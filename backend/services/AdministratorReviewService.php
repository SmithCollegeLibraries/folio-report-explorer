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

    /** @var callable */
    private $directReuseFingerprint;

    /** @var callable */
    private $schemaVersionFingerprint;

    /** @var array<string,string> */
    private $directFingerprintCache = [];

    /** @var string|null */
    private $currentSchemaFingerprintCache;

    public function __construct(
        ?Connection $db = null,
        ?callable $directReuseFingerprint = null,
        ?callable $schemaVersionFingerprint = null
    ) {
        $this->db = $db ?: Yii::$app->db;
        $this->directReuseFingerprint = $directReuseFingerprint ?: static function (string $question): string {
            return QueryMemoryService::currentDirectReuseSchemaFingerprint($question);
        };
        $this->schemaVersionFingerprint = $schemaVersionFingerprint ?: static function (): string {
            return QueryMemoryService::currentSchemaVersionFingerprint();
        };
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
     * Verify that an execution generation and its source lineage belong to the
     * submitting user without creating derivative persistence.
     */
    public function assertExecutionGenerationOwned(string $generationId, int $userId): void
    {
        $generation = $this->ownedExecutionGeneration($generationId, $userId);
        $this->executionSourceGeneration($generation, $userId);
    }

    /**
     * Resolve an owned Ask generation for execution, creating a reviewed child
     * when the submitted SQL no longer matches the server-stored SQL hash.
     *
     * @return array{generation:AiReportGeneration,provenanceGeneration:AiReportGeneration,edited:bool}
     */
    public function resolveExecutionGeneration(string $generationId, int $userId, string $normalizedSql): array
    {
        $generation = $this->ownedExecutionGeneration($generationId, $userId);
        $sourceGeneration = $this->executionSourceGeneration($generation, $userId);

        $storedHash = (string)$sourceGeneration->sql_hash;
        $submittedHash = hash('sha256', $normalizedSql);
        if ($storedHash !== '' && hash_equals($storedHash, $submittedHash)) {
            return [
                'generation' => $this->createExecutionChild($sourceGeneration, $normalizedSql),
                'provenanceGeneration' => $sourceGeneration,
                'edited' => false,
            ];
        }

        $editedGeneration = $this->createEditedChild($sourceGeneration, $normalizedSql);
        return [
            'generation' => $editedGeneration,
            'provenanceGeneration' => $editedGeneration,
            'edited' => true,
        ];
    }

    /**
     * Create execution lineage from a server-revalidated reusable generation.
     * The source may belong to another user; the child always belongs to the
     * current executor and retains immutable provenance unless SQL was edited.
     */
    public function createTrustedReuseChild(
        string $sourceGenerationId,
        int $userId,
        string $question,
        string $normalizedSql,
        bool $edited,
        string $reuseTrust
    ): array {
        if (!in_array($reuseTrust, ['verified_global', 'same_user_accurate', 'administrator_approved'], true)) {
            throw new InvalidArgumentException('invalid_reuse_trust');
        }

        return $this->db->transaction(function () use (
            $sourceGenerationId,
            $userId,
            $question,
            $normalizedSql,
            $edited,
            $reuseTrust
        ): array {
            $source = AiReportGeneration::findOne(['id' => $sourceGenerationId]);
            if ($source === null) {
                throw new DomainException('reuse_source_generation_not_found');
            }

            $generationId = AiReportGeneration::generateUuid();
            $now = gmdate('Y-m-d H:i:s');
            $provenance = $this->decodeJsonObject($source->provenance_json);
            if ($edited) {
                $provenance['generationProvenance'] = 'ai_built';
            }
            $provenance['queryMemory'] = [
                'reused' => true,
                'sourceGenerationId' => (string)$source->id,
                'reuseTrust' => $reuseTrust,
                'edited' => $edited,
            ];

            $confidenceEvidence = $this->decodeJsonObject($source->confidence_evidence_json);
            $confidenceEvidence['queryMemoryReuse'] = [
                'sourceGenerationId' => (string)$source->id,
                'reuseTrust' => $reuseTrust,
                'edited' => $edited,
            ];
            $reviewReasons = $edited ? ['user_modified_sql'] : [];

            $this->db->createCommand()->insert('ai_report_generations', [
                'id' => $generationId,
                'conversation_id' => (string)$source->conversation_id,
                'parent_generation_id' => (string)$source->id,
                'query_job_id' => null,
                'user_id' => $userId,
                'prompt_fingerprint' => substr(hash('sha256', $question), 0, 16),
                'original_question' => $question,
                'follow_up_context' => null,
                'response_mode' => $source->response_mode,
                'execution_mode' => $edited ? 'exploratory' : $source->execution_mode,
                'route' => $source->route,
                'route_reason' => $edited ? 'user_edited_sql' : 'query_reuse',
                'validation_status' => 'validated',
                'generated_sql' => $normalizedSql,
                'sql_hash' => hash('sha256', $normalizedSql),
                'assumptions_json' => $source->assumptions_json,
                'user_notice_json' => $source->user_notice_json,
                'confidence_evidence_json' => $this->encodeJson($confidenceEvidence),
                'initial_structure_json' => $source->initial_structure_json,
                'final_structure_json' => $source->final_structure_json,
                'provenance_json' => $this->encodeJson($provenance),
                'review_required' => $edited ? 1 : 0,
                'review_reasons_json' => $this->encodeJson($reviewReasons),
                'created_at' => $now,
                'linked_at' => null,
                'updated_at' => $now,
            ])->execute();

            $reviewId = $edited ? $this->insertReview($generationId) : null;
            $generation = AiReportGeneration::findOne(['id' => $generationId]);
            if ($generation === null) {
                throw new RuntimeException('reuse_generation_not_found');
            }
            return [
                'generationId' => $generationId,
                'conversationId' => (string)$source->conversation_id,
                'reviewId' => $reviewId,
                'generation' => $generation,
                'provenanceGeneration' => $edited ? $generation : $source,
            ];
        });
    }

    /**
     * Link a saved query job and copy only server-trusted Ask provenance into
     * its metadata.
     */
    public function linkExecutionGeneration(
        AiReportGeneration $generation,
        string $queryJobId,
        ?AiReportGeneration $provenanceGeneration = null
    ): void
    {
        $provenanceGeneration = $provenanceGeneration ?: $generation;
        $this->db->transaction(function () use (
            $generation,
            $queryJobId,
            $provenanceGeneration
        ): void {
            $metadataJson = $this->db->createCommand(
                'SELECT metadata FROM query_jobs WHERE id = :id',
                [':id' => $queryJobId]
            )->queryScalar();
            if ($metadataJson === false) {
                throw new RuntimeException('query_job_not_found');
            }
            $metadata = is_string($metadataJson) ? json_decode($metadataJson, true) : [];
            if (!is_array($metadata)) {
                $metadata = [];
            }

            $metadata['askAiProvenance'] = [
                'generationId' => (string)$generation->id,
                'sourceGenerationId' => (string)$provenanceGeneration->id,
                'conversationId' => (string)$generation->conversation_id,
                'parentGenerationId' => $generation->parent_generation_id === null
                    ? null
                    : (string)$generation->parent_generation_id,
                'route' => $provenanceGeneration->route,
                'routeReason' => $provenanceGeneration->route_reason,
                'executionMode' => $provenanceGeneration->execution_mode,
                'validationStatus' => $provenanceGeneration->validation_status,
                'reviewRequired' => (bool)$provenanceGeneration->review_required,
                'reviewReasons' => $this->decodeJsonList(
                    $provenanceGeneration->review_reasons_json
                ),
                'provenance' => $this->decodeJsonObject(
                    $provenanceGeneration->provenance_json
                ),
            ];

            $now = gmdate('Y-m-d H:i:s');
            $updatedJobs = $this->db->createCommand()->update('query_jobs', [
                'metadata' => $this->encodeJson($metadata),
            ], ['id' => $queryJobId])->execute();
            if ($updatedJobs !== 1) {
                throw new RuntimeException('query_job_link_update_failed');
            }
            $updatedGenerations = $this->db->createCommand()->update('ai_report_generations', [
                'query_job_id' => $queryJobId,
                'linked_at' => $now,
                'updated_at' => $now,
            ], [
                'id' => (string)$generation->id,
                'query_job_id' => null,
            ])->execute();
            if ($updatedGenerations !== 1) {
                throw new RuntimeException('generation_link_update_failed');
            }
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
        return $this->complete(
            $reviewId,
            $administratorId,
            'resolved',
            $disposition,
            $notes,
            $advisoryState,
            $supersededByJobId,
            $takeover
        );
    }

    public function dismiss(
        string $reviewId,
        int $administratorId,
        string $disposition,
        string $notes,
        bool $takeover = false
    ): array {
        return $this->complete(
            $reviewId,
            $administratorId,
            'dismissed',
            $disposition,
            $notes,
            'none',
            null,
            $takeover
        );
    }

    /**
     * List AI-built feedback that has an explicit trust decision or suppression.
     * Raw SQL and feedback notes intentionally remain outside this summary.
     */
    public function listQueryMemory(array $filters = []): array
    {
        $status = strtolower(trim((string)($filters['status'] ?? 'all')));
        if (!in_array($status, ['all', 'accurate', 'suppressed', 'approved'], true)) {
            $status = 'all';
        }
        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $query = (new \yii\db\Query())
            ->from(['f' => 'ai_query_feedback'])
            ->leftJoin(['g' => 'ai_report_generations'], 'g.id = f.generation_id')
            ->where(['f.generation_provenance' => 'ai_built']);
        if ($status === 'accurate') {
            $query->andWhere(['f.result_accuracy' => 'accurate']);
        } elseif ($status === 'suppressed') {
            $query->andWhere(['f.reuse_suppressed' => 1]);
        } elseif ($status === 'approved') {
            $query->andWhere(['not', ['f.admin_reuse_approved_at' => null]]);
        } else {
            $query->andWhere(['or',
                ['f.result_accuracy' => 'accurate'],
                ['f.reuse_suppressed' => 1],
                ['not', ['f.admin_reuse_approved_at' => null]],
            ]);
        }

        $total = (int)(clone $query)->count('*', $this->db);
        $rows = $query
            ->select([
                'f.*',
                'generation_provenance_json' => 'g.provenance_json',
            ])
            ->orderBy(['f.created_at' => SORT_DESC, 'f.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all($this->db);

        return [
            'items' => array_map([$this, 'mapQueryMemoryFeedback'], $rows),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => $total,
            ],
        ];
    }

    /** Atomically approve or revoke cross-user reuse without changing provenance. */
    public function setQueryFeedbackReuseApproval(
        int $feedbackId,
        bool $approved,
        int $administratorId
    ): array {
        return $this->db->transaction(function () use ($feedbackId, $approved, $administratorId): array {
            $row = $this->lockedQueryMemoryFeedback($feedbackId);
            if (!$approved) {
                $this->db->createCommand()->update('ai_query_feedback', [
                    'admin_reuse_approved_at' => null,
                    'admin_reuse_approved_by' => null,
                ], ['id' => $feedbackId])->execute();
                $updated = $this->queryMemoryFeedback($feedbackId);
                $this->logQueryMemoryAdministration('reuse_approval_changed', $updated, $administratorId, [
                    'approved' => false,
                ]);
                return $this->mapQueryMemoryFeedback($updated);
            }

            if (!$this->queryMemoryApprovalEligible($row)) {
                throw new DomainException('query_feedback_not_approvable');
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->db->createCommand()->update('ai_query_feedback', [
                'admin_reuse_approved_at' => $now,
                'admin_reuse_approved_by' => $administratorId,
            ], ['id' => $feedbackId])->execute();
            $updated = $this->queryMemoryFeedback($feedbackId);
            $this->logQueryMemoryAdministration('reuse_approval_changed', $updated, $administratorId, [
                'approved' => true,
            ]);
            return $this->mapQueryMemoryFeedback($updated);
        });
    }

    /** Clear one exact SQL/global-schema/scope suppression cluster after review. */
    public function clearQueryFeedbackSuppression(int $feedbackId, int $administratorId): array
    {
        return $this->db->transaction(function () use ($feedbackId, $administratorId): array {
            $row = $this->lockedQueryMemoryFeedback($feedbackId);
            if (empty($row['reuse_suppressed'])) {
                throw new DomainException('query_feedback_not_suppressed');
            }
            $match = [
                'sql_hash' => trim((string)($row['sql_hash'] ?? '')),
                'schema_version_fingerprint' => trim((string)($row['schema_version_fingerprint'] ?? '')),
                'scope_fingerprint' => trim((string)($row['scope_fingerprint'] ?? '')),
            ];
            if (in_array('', $match, true)) {
                throw new DomainException('query_feedback_suppression_not_clearable');
            }

            $clearedCount = $this->db->createCommand()->update('ai_query_feedback', [
                'reuse_suppressed' => 0,
                'admin_reuse_approved_at' => null,
                'admin_reuse_approved_by' => null,
            ], $match)->execute();
            $updated = $this->queryMemoryFeedback($feedbackId);
            $this->logQueryMemoryAdministration('reuse_suppression_cleared', $updated, $administratorId, [
                'clearedCount' => $clearedCount,
            ]);

            return [
                'feedback' => $this->mapQueryMemoryFeedback($updated),
                'clearedCount' => $clearedCount,
            ];
        });
    }

    private function complete(
        string $reviewId,
        int $administratorId,
        string $terminalStatus,
        string $disposition,
        string $notes,
        string $advisoryState,
        ?string $supersededByJobId,
        bool $takeover
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

        return $this->db->transaction(function () use (
            $reviewId,
            $administratorId,
            $terminalStatus,
            $disposition,
            $notes,
            $advisoryState,
            $supersededByJobId,
            $takeover
        ): array {
            if ($advisoryState === 'superseded') {
                $this->lockReplacementQueryJob((string)$supersededByJobId);
                $generationId = $this->reviewGenerationIdForUpdate($reviewId);
                $this->lockGenerationRow($generationId);
                if (!$this->isCompletedReplacementJob($reviewId, (string)$supersededByJobId)) {
                    throw new InvalidArgumentException('superseded_review_requires_completed_owned_job');
                }
            }

            $now = gmdate('Y-m-d H:i:s');
            $values = [
                'status' => $terminalStatus,
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
        });
    }

    private function lockReplacementQueryJob(string $queryJobId): void
    {
        // UPDATE-to-self acquires a transaction-held InnoDB row lock and an
        // early SQLite write reservation without changing execution status.
        $this->db->createCommand(
            'UPDATE query_jobs SET id=id WHERE id = :queryJobId',
            [':queryJobId' => $queryJobId]
        )->execute();
    }

    private function reviewGenerationIdForUpdate(string $reviewId): string
    {
        $generationId = $this->db->createCommand(
            'SELECT g.id
             FROM ai_report_reviews r
             INNER JOIN ai_report_generations g ON g.id = r.generation_id
             WHERE r.id = :reviewId' . $this->lockingReadSuffix(),
            [':reviewId' => $reviewId]
        )->queryScalar();
        if ($generationId === false) {
            throw new DomainException('review_not_resolvable');
        }
        return (string)$generationId;
    }

    private function lockGenerationRow(string $generationId): void
    {
        $this->db->createCommand(
            'UPDATE ai_report_generations SET id=id WHERE id = :generationId',
            [':generationId' => $generationId]
        )->execute();
    }

    private function isCompletedReplacementJob(string $reviewId, string $queryJobId): bool
    {
        return $this->db->createCommand(
            "SELECT q.id
             FROM query_jobs q
             INNER JOIN ai_report_reviews r ON r.id = :reviewId
             INNER JOIN ai_report_generations g ON g.id = r.generation_id
             WHERE q.id = :queryJobId
               AND q.status = 'completed'
               AND g.user_id IS NOT NULL
               AND q.user_id = g.user_id
               AND (g.query_job_id IS NULL OR q.id <> g.query_job_id)" . $this->lockingReadSuffix(),
            [':reviewId' => $reviewId, ':queryJobId' => $queryJobId]
        )->queryScalar() !== false;
    }

    private function lockingReadSuffix(): string
    {
        return in_array($this->db->getDriverName(), ['mysql', 'mysqli'], true)
            ? ' FOR UPDATE'
            : '';
    }

    public function purgeExpired(int $days, DateTimeImmutable $now): int
    {
        $days = max(1, $days);
        $cutoff = $now->modify('-' . $days . ' days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return $this->db->transaction(function () use ($cutoff): int {
            $terminalGenerationIds = $this->db->createCommand(
                "SELECT r.generation_id
                 FROM ai_report_reviews r
                 INNER JOIN ai_report_generations g ON g.id = r.generation_id
                 WHERE r.status IN ('resolved', 'dismissed')
                   AND COALESCE(r.resolved_at, r.updated_at) < :cutoff
                   AND NOT EXISTS (
                       SELECT 1
                       FROM ai_report_generations linked
                       WHERE linked.parent_generation_id = g.id
                         AND linked.query_job_id IS NOT NULL
                   )",
                [':cutoff' => $cutoff]
            )->queryColumn();

            $deleted = $this->deleteGenerationsByIds($terminalGenerationIds);
            $unlinkedGenerationIds = $this->db->createCommand(
                'SELECT g.id
                 FROM ai_report_generations g
                 WHERE g.query_job_id IS NULL
                   AND g.created_at < :cutoff
                   AND NOT EXISTS (
                       SELECT 1
                       FROM ai_report_generations linked
                       WHERE linked.parent_generation_id = g.id
                         AND linked.query_job_id IS NOT NULL
                   )',
                [':cutoff' => $cutoff]
            )->queryColumn();
            $deleted += $this->deleteGenerationsByIds($unlinkedGenerationIds);

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

    private function ownedExecutionGeneration(
        string $generationId,
        int $userId
    ): AiReportGeneration {
        $generation = AiReportGeneration::findOne(['id' => $generationId]);
        if (
            $generation === null
            || $generation->user_id === null
            || (int)$generation->user_id !== $userId
        ) {
            throw new DomainException('generation_not_owned');
        }

        return $generation;
    }

    private function executionSourceGeneration(
        AiReportGeneration $generation,
        int $userId
    ): AiReportGeneration {
        $seen = [];
        while (
            (string)$generation->route_reason === 'query_execution'
            && $generation->parent_generation_id !== null
        ) {
            $generationId = (string)$generation->id;
            if (isset($seen[$generationId])) {
                throw new DomainException('generation_lineage_cycle');
            }
            $seen[$generationId] = true;

            $parent = AiReportGeneration::findOne([
                'id' => (string)$generation->parent_generation_id,
            ]);
            if (
                $parent === null
                || $parent->user_id === null
                || (int)$parent->user_id !== $userId
            ) {
                throw new DomainException('generation_not_owned');
            }
            $generation = $parent;
        }

        return $generation;
    }

    private function createExecutionChild(
        AiReportGeneration $parent,
        string $normalizedSql
    ): AiReportGeneration {
        $record = $this->recordGeneration([
            'userId' => (int)$parent->user_id,
            'parentGenerationId' => (string)$parent->id,
            'promptFingerprint' => (string)$parent->prompt_fingerprint,
            'originalQuestion' => (string)$parent->original_question,
            'followUpContext' => $this->decodeNullableJson($parent->follow_up_context),
            'responseMode' => $parent->response_mode,
            'executionMode' => $parent->execution_mode,
            'route' => $parent->route,
            'routeReason' => 'query_execution',
            'validationStatus' => $parent->validation_status,
            'generatedSql' => $normalizedSql,
            'sqlHash' => hash('sha256', $normalizedSql),
            'assumptions' => $this->decodeNullableJson($parent->assumptions_json),
            'userNotice' => $this->decodeNullableJson($parent->user_notice_json),
            'confidenceEvidence' => $this->decodeJsonObject($parent->confidence_evidence_json),
            'initialStructure' => $this->decodeNullableJson($parent->initial_structure_json),
            'finalStructure' => $this->decodeNullableJson($parent->final_structure_json),
            'provenance' => $this->decodeJsonObject($parent->provenance_json),
            'reviewRequired' => false,
            'reviewReasons' => [],
        ]);

        $child = AiReportGeneration::findOne(['id' => $record['generationId']]);
        if ($child === null) {
            throw new RuntimeException('execution_generation_not_found');
        }
        return $child;
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

    private function lockedQueryMemoryFeedback(int $feedbackId): array
    {
        // Reserve the row before reading eligibility so the decision and update
        // cannot race with feedback suppression or another administrator.
        $this->db->createCommand(
            'UPDATE ai_query_feedback SET id=id WHERE id = :id',
            [':id' => $feedbackId]
        )->execute();
        $row = $this->queryMemoryFeedback($feedbackId);
        $generationId = trim((string)($row['generation_id'] ?? ''));
        if ($generationId !== '') {
            $this->lockGenerationRow($generationId);
            $row = $this->queryMemoryFeedback($feedbackId);
        }
        return $row;
    }

    private function queryMemoryFeedback(int $feedbackId): array
    {
        $row = (new \yii\db\Query())
            ->from(['f' => 'ai_query_feedback'])
            ->leftJoin(['g' => 'ai_report_generations'], 'g.id = f.generation_id')
            ->select([
                'f.*',
                'generation_provenance_json' => 'g.provenance_json',
            ])
            ->where(['f.id' => $feedbackId])
            ->one($this->db);
        if ($row === false) {
            throw new DomainException('query_feedback_not_found');
        }
        return $row;
    }

    private function queryMemoryApprovalEligible(array $row): bool
    {
        if (
            strtolower(trim((string)($row['generation_provenance'] ?? ''))) !== 'ai_built'
            || strtolower(trim((string)($row['result_accuracy'] ?? ''))) !== 'accurate'
            || !empty($row['reuse_suppressed'])
            || trim((string)($row['scope_fingerprint'] ?? '')) === ''
        ) {
            return false;
        }

        $generationProvenance = $this->decodeJsonObject($row['generation_provenance_json'] ?? null);
        $immutableProvenance = strtolower(trim((string)($generationProvenance['generationProvenance'] ?? '')));
        if ($immutableProvenance !== '' && $immutableProvenance !== 'ai_built') {
            return false;
        }

        $question = (string)($row['original_question'] ?? '');
        $currentDirect = $this->currentDirectFingerprint($question);
        $currentVersion = $this->currentSchemaFingerprint();
        return $this->fingerprintsEqual($currentDirect, $row['direct_reuse_schema_fingerprint'] ?? null)
            && $this->fingerprintsEqual($currentVersion, $row['schema_version_fingerprint'] ?? null);
    }

    private function mapQueryMemoryFeedback(array $row): array
    {
        $currentDirect = $this->currentDirectFingerprint((string)($row['original_question'] ?? ''));
        $currentVersion = $this->currentSchemaFingerprint();
        $strictCompatible = $this->fingerprintsEqual(
            $currentDirect,
            $row['direct_reuse_schema_fingerprint'] ?? null
        );
        $versionCompatible = $this->fingerprintsEqual(
            $currentVersion,
            $row['schema_version_fingerprint'] ?? null
        );
        $scopeCompatible = trim((string)($row['scope_fingerprint'] ?? '')) !== '';

        return [
            'id' => (int)$row['id'],
            'generationId' => $row['generation_id'] === null ? null : (string)$row['generation_id'],
            'queryJobId' => $row['query_job_id'] === null ? null : (string)$row['query_job_id'],
            'question' => (string)$row['original_question'],
            'generationProvenance' => (string)($row['generation_provenance'] ?? ''),
            'resultAccuracy' => (string)$row['result_accuracy'],
            'reuseSuppressed' => !empty($row['reuse_suppressed']),
            'sqlHash' => (string)$row['sql_hash'],
            'dataSource' => (string)$row['data_source'],
            'strictSchemaCompatible' => $strictCompatible,
            'globalSchemaCompatible' => $versionCompatible,
            'schemaCompatible' => $strictCompatible && $versionCompatible,
            'scopeCompatible' => $scopeCompatible,
            'adminReuseApprovedAt' => $row['admin_reuse_approved_at'] === null
                ? null
                : (string)$row['admin_reuse_approved_at'],
            'adminReuseApprovedBy' => $row['admin_reuse_approved_by'] === null
                ? null
                : (int)$row['admin_reuse_approved_by'],
            'approvalEligible' => $this->queryMemoryApprovalEligible($row),
            'createdAt' => (string)$row['created_at'],
        ];
    }

    private function fingerprintsEqual(string $current, $stored): bool
    {
        $stored = trim((string)$stored);
        return $current !== '' && $stored !== '' && hash_equals($current, $stored);
    }

    private function currentDirectFingerprint(string $question): string
    {
        if (!array_key_exists($question, $this->directFingerprintCache)) {
            $this->directFingerprintCache[$question] = (string)call_user_func(
                $this->directReuseFingerprint,
                $question
            );
        }
        return $this->directFingerprintCache[$question];
    }

    private function currentSchemaFingerprint(): string
    {
        if ($this->currentSchemaFingerprintCache === null) {
            $this->currentSchemaFingerprintCache = (string)call_user_func($this->schemaVersionFingerprint);
        }
        return $this->currentSchemaFingerprintCache;
    }

    private function logQueryMemoryAdministration(
        string $event,
        array $row,
        int $administratorId,
        array $details
    ): void {
        Yii::info(array_merge([
            'event' => $event,
            'feedbackId' => (int)$row['id'],
            'generationId' => $row['generation_id'] ?? null,
            'queryJobId' => $row['query_job_id'] ?? null,
            'sqlHash' => $row['sql_hash'] ?? null,
            'administratorId' => $administratorId,
        ], $details), 'query_memory');
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
