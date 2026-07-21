<?php

namespace app\services;

use app\models\AiReportGeneration;
use app\models\AiReportReview;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Yii;
use yii\db\Connection;

/**
 * Persists trusted Ask AI evidence and manages its administrator review lifecycle.
 */
class AdministratorReviewService
{
    /** @var Connection */
    private $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?: Yii::$app->db;
    }

    /**
     * @return array{generationId:string,conversationId:string,reviewId:?string}
     */
    public function recordGeneration(array $context): array
    {
        return $this->db->transaction(function () use ($context): array {
            $parent = $this->ownedParent($context);
            $generationId = AiReportGeneration::generateUuid();
            $conversationId = $parent === null
                ? AiReportGeneration::generateUuid()
                : (string)$parent['conversation_id'];
            $now = gmdate('Y-m-d H:i:s');
            $question = (string)($context['originalQuestion'] ?? $context['prompt'] ?? '');
            $generatedSql = $this->nullableString($context, 'generatedSql');

            $this->db->createCommand()->insert('ai_report_generations', [
                'id' => $generationId,
                'conversation_id' => $conversationId,
                'parent_generation_id' => $parent === null ? null : (string)$parent['id'],
                'query_job_id' => $this->nullableString($context, 'queryJobId'),
                'user_id' => array_key_exists('userId', $context) && $context['userId'] !== null
                    ? (int)$context['userId']
                    : null,
                'prompt_fingerprint' => (string)($context['promptFingerprint'] ?? substr(hash('sha256', $question), 0, 16)),
                'original_question' => $question,
                'follow_up_context' => $this->nullableJson($context, 'followUpContext'),
                'response_mode' => $this->nullableStringFromKeys($context, ['responseMode', 'mode']),
                'execution_mode' => $this->nullableString($context, 'executionMode'),
                'route' => $this->nullableString($context, 'route'),
                'route_reason' => $this->nullableString($context, 'routeReason'),
                'validation_status' => $this->nullableString($context, 'validationStatus'),
                'generated_sql' => $generatedSql,
                'sql_hash' => $this->nullableString($context, 'sqlHash')
                    ?? ($generatedSql === null ? null : hash('sha256', $generatedSql)),
                'assumptions_json' => $this->nullableJson($context, 'assumptions'),
                'user_notice_json' => $this->nullableJson($context, 'userNotice'),
                'confidence_evidence_json' => $this->requiredJson($context, 'confidenceEvidence', []),
                'initial_structure_json' => $this->nullableJson($context, 'initialStructure'),
                'final_structure_json' => $this->nullableJson($context, 'finalStructure'),
                'provenance_json' => $this->requiredJson($context, 'provenance', []),
                'review_required' => !empty($context['reviewRequired']) ? 1 : 0,
                'review_reasons_json' => $this->requiredJson($context, 'reviewReasons', []),
                'created_at' => $now,
                'linked_at' => null,
                'updated_at' => $now,
            ])->execute();

            $reviewId = null;
            if (!empty($context['reviewRequired'])) {
                $reviewId = $this->insertReview($generationId);
            }

            return [
                'generationId' => $generationId,
                'conversationId' => $conversationId,
                'reviewId' => $reviewId,
            ];
        });
    }

    /**
     * Resolve an owned Ask generation for execution, creating a reviewed child
     * when the submitted SQL no longer matches the server-stored SQL hash.
     *
     * @return array{generation:AiReportGeneration,edited:bool}
     */
    public function resolveExecutionGeneration(string $generationId, int $userId, string $normalizedSql): array
    {
        $generation = AiReportGeneration::findOne(['id' => $generationId]);
        if ($generation === null || $generation->user_id === null || (int)$generation->user_id !== $userId) {
            throw new DomainException('generation_not_owned');
        }

        $storedHash = (string)$generation->sql_hash;
        $submittedHash = hash('sha256', $normalizedSql);
        if ($storedHash !== '' && hash_equals($storedHash, $submittedHash)) {
            return ['generation' => $generation, 'edited' => false];
        }

        return [
            'generation' => $this->createEditedChild($generation, $normalizedSql),
            'edited' => true,
        ];
    }

    /**
     * Link a saved query job and copy only server-trusted Ask provenance into
     * its metadata.
     */
    public function linkExecutionGeneration(AiReportGeneration $generation, string $queryJobId): void
    {
        $this->db->transaction(function () use ($generation, $queryJobId): void {
            $metadataJson = $this->db->createCommand(
                'SELECT metadata FROM query_jobs WHERE id = :id',
                [':id' => $queryJobId]
            )->queryScalar();
            $metadata = is_string($metadataJson) ? json_decode($metadataJson, true) : [];
            if (!is_array($metadata)) {
                $metadata = [];
            }

            $metadata['askAiProvenance'] = [
                'generationId' => (string)$generation->id,
                'conversationId' => (string)$generation->conversation_id,
                'parentGenerationId' => $generation->parent_generation_id === null
                    ? null
                    : (string)$generation->parent_generation_id,
                'route' => $generation->route,
                'routeReason' => $generation->route_reason,
                'executionMode' => $generation->execution_mode,
                'validationStatus' => $generation->validation_status,
                'reviewRequired' => (bool)$generation->review_required,
                'reviewReasons' => $this->decodeJsonList($generation->review_reasons_json),
                'provenance' => $this->decodeJsonObject($generation->provenance_json),
            ];

            $now = gmdate('Y-m-d H:i:s');
            $this->db->createCommand()->update('query_jobs', [
                'metadata' => $this->encodeJson($metadata),
            ], ['id' => $queryJobId])->execute();
            $this->db->createCommand()->update('ai_report_generations', [
                'query_job_id' => $queryJobId,
                'linked_at' => $now,
                'updated_at' => $now,
            ], ['id' => (string)$generation->id])->execute();
        });
    }

    public function claim(string $reviewId, int $administratorId): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $affected = $this->db->createCommand()->update('ai_report_reviews', [
            'status' => 'in_review',
            'reviewed_by' => $administratorId,
            'claimed_at' => $now,
            'updated_at' => $now,
        ], ['id' => $reviewId, 'status' => 'pending'])->execute();

        if ($affected !== 1) {
            throw new DomainException('review_not_claimable');
        }

        return $this->reviewRow($reviewId);
    }

    public function resolve(
        string $reviewId,
        int $administratorId,
        string $disposition,
        string $notes,
        string $advisoryState = 'none',
        ?string $supersededByJobId = null,
        bool $takeover = false
    ): array {
        $dispositions = [
            'acceptable',
            'assumption_change',
            'deterministic_candidate',
            'generation_defect',
            'data_unavailable',
            'specialist_interpretation',
        ];
        if (!in_array($disposition, $dispositions, true)) {
            throw new InvalidArgumentException('invalid_review_disposition');
        }
        if (!in_array($advisoryState, ['none', 'cautioned', 'superseded'], true)) {
            throw new InvalidArgumentException('invalid_advisory_state');
        }
        if ($advisoryState === 'superseded' && ($supersededByJobId === null || $supersededByJobId === '')) {
            throw new InvalidArgumentException('superseded_review_requires_job');
        }
        if ($advisoryState !== 'superseded' && $supersededByJobId !== null) {
            throw new InvalidArgumentException('superseding_job_requires_superseded_state');
        }

        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'status' => 'resolved',
            'disposition' => $disposition,
            'advisory_state' => $advisoryState,
            'superseded_by_job_id' => $supersededByJobId,
            'administrator_notes' => $notes,
            'resolved_at' => $now,
            'updated_at' => $now,
        ];
        if ($takeover) {
            $values['reviewed_by'] = $administratorId;
        }
        $condition = [
            'id' => $reviewId,
            'status' => 'in_review',
        ];
        if (!$takeover) {
            $condition['reviewed_by'] = $administratorId;
        }
        $affected = $this->db->createCommand()->update('ai_report_reviews', $values, $condition)->execute();

        if ($affected !== 1) {
            throw new DomainException('review_not_resolvable');
        }

        return $this->reviewRow($reviewId);
    }

    public function purgeExpired(int $days, DateTimeImmutable $now): int
    {
        $days = max(1, $days);
        $cutoff = $now->modify('-' . $days . ' days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return $this->db->transaction(function () use ($cutoff): int {
            $terminalGenerationIds = $this->db->createCommand(
                "SELECT generation_id FROM ai_report_reviews
                 WHERE status IN ('resolved', 'dismissed')
                   AND COALESCE(resolved_at, updated_at) < :cutoff",
                [':cutoff' => $cutoff]
            )->queryColumn();

            $deleted = $this->deleteGenerationsByIds($terminalGenerationIds);
            $deleted += $this->db->createCommand(
                'DELETE FROM ai_report_generations WHERE query_job_id IS NULL AND created_at < :cutoff',
                [':cutoff' => $cutoff]
            )->execute();

            return $deleted;
        });
    }

    public function purgeUserContent(int $userId): int
    {
        return $this->db->transaction(function () use ($userId): int {
            $generationIds = $this->db->createCommand(
                'SELECT id FROM ai_report_generations WHERE user_id = :userId',
                [':userId' => $userId]
            )->queryColumn();
            if ($generationIds === []) {
                return 0;
            }

            $this->db->createCommand()->delete('ai_report_reviews', ['generation_id' => $generationIds])->execute();
            return $this->deleteGenerationsByIds($generationIds);
        });
    }

    /**
     * Validate ownership before accepting a parent-provided conversation id.
     */
    private function ownedParent(array $context): ?array
    {
        $parentId = $context['parentGenerationId'] ?? null;
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = $this->db->createCommand(
            'SELECT id, conversation_id, user_id FROM ai_report_generations WHERE id = :id',
            [':id' => $parentId]
        )->queryOne();
        $requestedUserId = $context['userId'] ?? null;
        $owned = $parent !== false
            && $parent['user_id'] !== null
            && $requestedUserId !== null
            && (int)$parent['user_id'] === (int)$requestedUserId;
        if (!$owned) {
            throw new DomainException('parent_generation_not_owned');
        }

        return $parent;
    }

    private function createEditedChild(AiReportGeneration $parent, string $normalizedSql): AiReportGeneration
    {
        $record = $this->recordGeneration([
            'userId' => (int)$parent->user_id,
            'parentGenerationId' => (string)$parent->id,
            'promptFingerprint' => (string)$parent->prompt_fingerprint,
            'originalQuestion' => (string)$parent->original_question,
            'followUpContext' => $this->decodeNullableJson($parent->follow_up_context),
            'responseMode' => $parent->response_mode,
            'executionMode' => $parent->execution_mode,
            'route' => $parent->route,
            'routeReason' => 'user_edited_sql',
            'validationStatus' => 'validated',
            'generatedSql' => $normalizedSql,
            'sqlHash' => hash('sha256', $normalizedSql),
            'assumptions' => $this->decodeNullableJson($parent->assumptions_json),
            'userNotice' => $this->decodeNullableJson($parent->user_notice_json),
            'confidenceEvidence' => $this->decodeJsonObject($parent->confidence_evidence_json),
            'initialStructure' => $this->decodeNullableJson($parent->initial_structure_json),
            'finalStructure' => $this->decodeNullableJson($parent->final_structure_json),
            'provenance' => $this->decodeJsonObject($parent->provenance_json),
            'reviewRequired' => true,
            'reviewReasons' => ['user_modified_sql'],
        ]);

        $child = AiReportGeneration::findOne(['id' => $record['generationId']]);
        if ($child === null) {
            throw new RuntimeException('edited_generation_not_found');
        }
        return $child;
    }

    /**
     * Return an existing row when a retry races with another review insert.
     */
    private function insertReview(string $generationId): string
    {
        $existing = $this->db->createCommand(
            'SELECT id FROM ai_report_reviews WHERE generation_id = :generationId',
            [':generationId' => $generationId]
        )->queryScalar();
        if ($existing !== false) {
            return (string)$existing;
        }

        $reviewId = AiReportReview::generateUuid();
        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->db->createCommand()->insert('ai_report_reviews', [
                'id' => $reviewId,
                'generation_id' => $generationId,
                'status' => 'pending',
                'disposition' => null,
                'advisory_state' => 'none',
                'superseded_by_job_id' => null,
                'administrator_notes' => null,
                'reviewed_by' => null,
                'claimed_at' => null,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
        } catch (Throwable $exception) {
            $existing = $this->db->createCommand(
                'SELECT id FROM ai_report_reviews WHERE generation_id = :generationId',
                [':generationId' => $generationId]
            )->queryScalar();
            if ($existing !== false) {
                return (string)$existing;
            }
            throw $exception;
        }

        return $reviewId;
    }

    private function reviewRow(string $reviewId): array
    {
        $row = $this->db->createCommand(
            'SELECT * FROM ai_report_reviews WHERE id = :id',
            [':id' => $reviewId]
        )->queryOne();
        if ($row === false) {
            throw new RuntimeException('review_not_found');
        }
        return $row;
    }

    private function deleteGenerationsByIds(array $generationIds): int
    {
        $generationIds = array_values(array_unique(array_map('strval', $generationIds)));
        if ($generationIds === []) {
            return 0;
        }
        return $this->db->createCommand()->delete('ai_report_generations', ['id' => $generationIds])->execute();
    }

    private function nullableString(array $context, string $key): ?string
    {
        if (!array_key_exists($key, $context) || $context[$key] === null) {
            return null;
        }
        return (string)$context[$key];
    }

    private function nullableStringFromKeys(array $context, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $context)) {
                return $context[$key] === null ? null : (string)$context[$key];
            }
        }
        return null;
    }

    private function nullableJson(array $context, string $key): ?string
    {
        if (!array_key_exists($key, $context) || $context[$key] === null) {
            return null;
        }
        return $this->encodeJson($context[$key]);
    }

    private function requiredJson(array $context, string $key, $default): string
    {
        return $this->encodeJson(array_key_exists($key, $context) ? $context[$key] : $default);
    }

    private function encodeJson($value): string
    {
        $encoded = json_encode(
            $this->stableJsonValue($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            throw new InvalidArgumentException('invalid_generation_json: ' . json_last_error_msg());
        }
        return $encoded;
    }

    private function decodeNullableJson($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function decodeJsonObject($value): array
    {
        $decoded = $this->decodeNullableJson($value);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeJsonList($value): array
    {
        $decoded = $this->decodeNullableJson($value);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function stableJsonValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            return array_map(function ($item) {
                return $this->stableJsonValue($item);
            }, $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->stableJsonValue($item);
        }
        return $value;
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
