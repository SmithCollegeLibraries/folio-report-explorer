<?php

namespace app\services;

if (!class_exists(FolioSchemaService::class, false)) {
    require_once __DIR__ . '/FolioSchemaService.php';
}
if (!class_exists(SqlBuilderService::class, false)) {
    require_once __DIR__ . '/SqlBuilderService.php';
}

/** Centralizes query-memory compatibility, explicit trust, and ranking. */
class QueryMemoryService
{
    private const TIER_VERIFIED = 5;
    private const TIER_ADMIN_APPROVED = 4;
    private const TIER_SAME_USER_ACCURATE = 3;
    private const TIER_OTHER_USER_ACCURATE = 2;
    private const TIER_NEUTRAL = 1;

    /** @var callable|null Optional observer used by focused telemetry tests. */
    private static $telemetrySink;

    private const TELEMETRY_FIELDS = [
        'candidateId', 'generationId', 'jobId', 'feedbackId', 'sqlHash',
        'reuseTrust', 'tier', 'reason', 'stage', 'count', 'signal', 'approved',
        'administratorId', 'directReuseSchemaFingerprint', 'schemaVersionFingerprint',
        'scopeFingerprint', 'resultAccuracy', 'clearedCount',
    ];

    public static function setTelemetrySink(?callable $sink): void
    {
        self::$telemetrySink = $sink;
    }

    public static function recordCandidateRejected(array $candidate, string $reason, string $stage): void
    {
        self::emitTelemetry('reuse_candidate_rejected', array_merge(
            self::candidateTelemetry($candidate),
            [
                'reason' => $reason,
                'stage' => $stage,
                'directReuseSchemaFingerprint' => $candidate['directReuseSchemaFingerprint'] ?? null,
                'schemaVersionFingerprint' => $candidate['schemaVersionFingerprint'] ?? null,
                'scopeFingerprint' => $candidate['scopeFingerprint'] ?? null,
            ]
        ));
    }

    public static function recordFeedback(array $feedback): void
    {
        $payload = [
            'feedbackId' => $feedback['feedbackId'] ?? null,
            'generationId' => $feedback['generationId'] ?? null,
            'jobId' => $feedback['queryJobId'] ?? $feedback['jobId'] ?? null,
            'sqlHash' => $feedback['sqlHash'] ?? null,
            'resultAccuracy' => $feedback['resultAccuracy'] ?? null,
            'schemaVersionFingerprint' => $feedback['schemaVersionFingerprint'] ?? null,
            'scopeFingerprint' => $feedback['scopeFingerprint'] ?? null,
        ];
        self::emitTelemetry('feedback_recorded', $payload);
        if (strtolower(trim((string)($payload['resultAccuracy'] ?? ''))) === 'inaccurate') {
            $payload['reason'] = 'inaccurate_feedback';
            self::emitTelemetry('reuse_suppressed', $payload);
        }
    }

    public static function recordWeakSignal(
        string $generationId,
        string $jobId,
        string $signal,
        int $count
    ): void {
        self::emitTelemetry('weak_signal_recorded', [
            'generationId' => $generationId,
            'jobId' => $jobId,
            'signal' => $signal,
            'count' => $count,
        ]);
    }

    public static function recordApprovalChanged(
        int $feedbackId,
        ?string $generationId,
        ?string $jobId,
        string $sqlHash,
        bool $approved,
        int $administratorId
    ): void {
        self::emitTelemetry('reuse_approval_changed', [
            'feedbackId' => $feedbackId,
            'generationId' => $generationId,
            'jobId' => $jobId,
            'sqlHash' => $sqlHash,
            'approved' => $approved,
            'administratorId' => $administratorId,
        ]);
    }

    public static function recordSuppressionCleared(
        int $feedbackId,
        ?string $generationId,
        ?string $jobId,
        string $sqlHash,
        int $clearedCount,
        int $administratorId
    ): void {
        self::emitTelemetry('reuse_suppression_cleared', [
            'feedbackId' => $feedbackId,
            'generationId' => $generationId,
            'jobId' => $jobId,
            'sqlHash' => $sqlHash,
            'clearedCount' => $clearedCount,
            'administratorId' => $administratorId,
        ]);
    }

    public static function directReuseSchemaFingerprint(array $schemaMetadata): string
    {
        return hash('sha256', self::canonicalJson([
            'version' => self::schemaVersion($schemaMetadata),
            'contextHash' => $schemaMetadata['contextHash'] ?? null,
        ]));
    }

    public static function sqlFingerprint(string $sql): string
    {
        return hash('sha256', $sql);
    }

    public static function currentDirectReuseSchemaFingerprint(string $prompt): string
    {
        $context = FolioSchemaService::buildSchemaContext($prompt);
        $metadata = FolioSchemaService::getMetadata();
        $metadata['contextHash'] = substr(hash('sha256', (string)$context), 0, 16);
        return self::directReuseSchemaFingerprint($metadata);
    }

    public static function schemaVersionFingerprint(array $schemaMetadata): string
    {
        return hash('sha256', self::canonicalJson([
            'version' => self::schemaVersion($schemaMetadata),
        ]));
    }

    public static function currentSchemaVersionFingerprint(): string
    {
        return self::storedSchemaVersionFingerprint(FolioSchemaService::getMetadata());
    }

    public static function scopeFingerprint(string $dataSource, array $authorizedScope): string
    {
        return hash('sha256', self::canonicalJson([
            'dataSource' => strtolower(trim($dataSource)),
            'scope' => self::canonicalizeArray($authorizedScope, true),
        ]));
    }

    /**
     * Select trusted examples from completed server-owned query records.
     * Storage failures are intentionally allowed to bubble to the caller so
     * generation can log the optional lookup failure and continue without examples.
     */
    public static function selectAiExamplesFromStorage(
        array $request,
        int $limit = 3,
        int $byteLimit = 12000
    ): array {
        $dataSource = strtolower(trim((string)($request['dataSource'] ?? 'folio')));
        $jobs = (new \yii\db\Query())
            ->from('query_jobs')
            ->where([
                'status' => 'completed',
                'source' => 'nl',
                'data_source' => $dataSource,
            ])
            ->orderBy(['completed_at' => SORT_DESC, 'created_at' => SORT_DESC])
            ->limit(250)
            ->all(\Yii::$app->db);

        $shapedCandidates = self::shapeCompletedJobs($jobs, $dataSource);
        if ($shapedCandidates === []) {
            return [];
        }

        $schemaMetadata = FolioSchemaService::getMetadata();
        $schemaVersion = self::storedSchemaVersionFingerprint($schemaMetadata);
        if ($schemaVersion === '') {
            return [];
        }

        $request['dataSource'] = $dataSource;
        $request['schemaVersionFingerprint'] = $schemaVersion;
        $request['scopeFingerprint'] = self::scopeFingerprint(
            $dataSource,
            self::normalizeScope(is_array($request['authorizedScope'] ?? null) ? $request['authorizedScope'] : [])
        );

        return self::selectAiExamples(
            $request,
            self::hydrateCandidates($shapedCandidates, $jobs),
            $limit,
            $byteLimit
        );
    }

    /** Hydrate job candidates with immutable generation and feedback evidence. */
    public static function hydrateCandidates(array $shapedCandidates, array $jobs): array
    {
        $jobsById = [];
        foreach ($jobs as $job) {
            $jobId = trim((string)($job['id'] ?? ''));
            if ($jobId !== '') {
                $jobsById[$jobId] = $job;
            }
        }
        $jobIds = array_values(array_unique(array_filter(array_map(
            static function (array $candidate): string {
                return trim((string)($candidate['jobId'] ?? ''));
            },
            $shapedCandidates
        ))));
        if ($jobIds === []) {
            return [];
        }

        $generationRows = (new \yii\db\Query())
            ->from('ai_report_generations')
            ->where(['query_job_id' => $jobIds])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_ASC])
            ->all(\Yii::$app->db);
        $generationByJob = [];
        foreach ($generationRows as $generation) {
            $jobId = trim((string)($generation['query_job_id'] ?? ''));
            if ($jobId !== '' && !isset($generationByJob[$jobId])) {
                $generationByJob[$jobId] = $generation;
            }
        }

        $candidateSqlHashes = array_values(array_unique(array_map(
            static function (array $candidate): string {
                return self::sqlFingerprint((string)($candidate['sql'] ?? ''));
            },
            $shapedCandidates
        )));
        [$feedbackByGeneration, $feedbackByJob, $feedbackBySqlHash] = self::feedbackIndexes(
            $generationRows,
            $jobIds,
            $candidateSqlHashes
        );
        $hydrated = [];
        foreach ($shapedCandidates as $candidate) {
            $jobId = trim((string)($candidate['jobId'] ?? ''));
            $generation = $generationByJob[$jobId] ?? null;
            if (!is_array($generation)) {
                continue;
            }
            $generationId = trim((string)($generation['id'] ?? ''));
            $provenance = self::decodeObject($generation['provenance_json'] ?? null);
            $schemaMetadata = is_array($provenance['schemaMetadata'] ?? null)
                ? $provenance['schemaMetadata']
                : [];
            $generationProvenance = (string)($provenance['generationProvenance'] ?? '');
            $job = $jobsById[$jobId] ?? [];
            $jobMetadata = self::decodeObject($job['metadata'] ?? null);
            $storedScope = is_array($jobMetadata['resolvedContext'] ?? null)
                ? self::normalizeScope($jobMetadata['resolvedContext'])
                : [];
            $directFingerprint = trim((string)($provenance['directReuseSchemaFingerprint'] ?? ''));
            if ($directFingerprint === '') {
                $directFingerprint = self::storedDirectReuseSchemaFingerprint($schemaMetadata);
            }
            $versionFingerprint = self::storedSchemaVersionFingerprint($schemaMetadata);
            $scopeFingerprint = self::scopeFingerprint(
                (string)($candidate['dataSource'] ?? 'folio'),
                $storedScope
            );
            $sqlHash = self::sqlFingerprint((string)($candidate['sql'] ?? ''));
            $feedbackRows = self::feedbackRowsForCandidate(
                $generationId,
                $jobId,
                $sqlHash,
                $versionFingerprint,
                $scopeFingerprint,
                $feedbackByGeneration,
                $feedbackByJob,
                $feedbackBySqlHash
            );
            $feedback = self::preferredFeedback($feedbackRows);
            $accurateFeedbackUserIds = self::accurateFeedbackUserIds($feedbackRows);
            $hasExplicitFeedback = $feedback !== null;
            $isVerified = $generationProvenance === 'verified_pattern';

            $hydrated[] = array_merge($candidate, [
                'id' => $generationId,
                'sourceGenerationId' => $generationId,
                'question' => (string)($generation['original_question'] ?? $candidate['question'] ?? ''),
                'userId' => $generation['user_id'] ?? null,
                'generationProvenance' => $generationProvenance,
                'sqlHash' => $sqlHash,
                'resultAccuracy' => $feedback['result_accuracy'] ?? null,
                'accurateFeedbackUserIds' => $accurateFeedbackUserIds,
                'adminReuseApprovedAt' => $feedback['admin_reuse_approved_at'] ?? null,
                'reuseSuppressed' => $feedback === null ? false : !empty($feedback['reuse_suppressed']),
                'directReuseSchemaFingerprint' => $isVerified || !$hasExplicitFeedback
                    ? $directFingerprint
                    : trim((string)($feedback['direct_reuse_schema_fingerprint'] ?? '')),
                'schemaVersionFingerprint' => $isVerified || !$hasExplicitFeedback
                    ? $versionFingerprint
                    : trim((string)($feedback['schema_version_fingerprint'] ?? '')),
                'scopeFingerprint' => $isVerified || !$hasExplicitFeedback
                    ? $scopeFingerprint
                    : trim((string)($feedback['scope_fingerprint'] ?? '')),
                'savedCount' => (int)($generation['saved_count'] ?? 0),
                'downloadedCount' => (int)($generation['downloaded_count'] ?? 0),
                'rerunCount' => (int)($generation['rerun_count'] ?? 0),
                'followUpCount' => (int)($generation['follow_up_count'] ?? 0),
                'status' => 'completed',
            ]);
        }
        return $hydrated;
    }

    public static function findDirectReuse(array $request, array $candidates): ?array
    {
        $eligible = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $baseRejection = self::baseRejectionReason($request, $candidate);
            if ($baseRejection !== null) {
                self::recordBaseRejection($request, $candidate, $baseRejection, 'direct');
                continue;
            }
            $requestQuestion = self::normalizeQuestion((string)($request['question'] ?? $request['normalizedQuestion'] ?? ''));
            $candidateQuestion = self::normalizeQuestion((string)($candidate['question'] ?? $candidate['normalizedQuestion'] ?? ''));
            if ($requestQuestion === '' || $requestQuestion !== $candidateQuestion) {
                continue;
            }
            if (!self::fingerprintsMatch(
                $request['directReuseSchemaFingerprint'] ?? null,
                $candidate['directReuseSchemaFingerprint'] ?? null
            )) {
                self::emitTelemetry('reuse_stale', array_merge(self::candidateTelemetry($candidate), [
                    'reason' => 'strict_schema_mismatch',
                    'directReuseSchemaFingerprint' => $request['directReuseSchemaFingerprint'] ?? null,
                    'scopeFingerprint' => $request['scopeFingerprint'] ?? null,
                ]));
                continue;
            }
            $trust = self::directReuseTrust($request, $candidate);
            if ($trust === null) {
                continue;
            }
            $candidate['reuseTrust'] = $trust;
            $candidate['_memoryTier'] = self::directTrustRank($trust);
            $candidate['_memoryCompletedAt'] = self::timestampRank($candidate['completedAt'] ?? $candidate['completed_at'] ?? null);
            $eligible[] = $candidate;
        }

        if ($eligible === []) {
            return null;
        }
        usort($eligible, [self::class, 'compareDirectCandidates']);
        $match = $eligible[0];
        unset($match['_memoryTier'], $match['_memoryCompletedAt']);
        self::emitTelemetry('reuse_selected', array_merge(self::candidateTelemetry($match), [
            'reuseTrust' => $match['reuseTrust'] ?? null,
            'directReuseSchemaFingerprint' => $request['directReuseSchemaFingerprint'] ?? null,
            'scopeFingerprint' => $request['scopeFingerprint'] ?? null,
        ]));
        return $match;
    }

    public static function selectAiExamples(
        array $request,
        array $candidates,
        int $limit = 3,
        int $byteLimit = 12000
    ): array {
        if ($limit <= 0 || $byteLimit <= 2) {
            return [];
        }

        $ranked = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $baseRejection = self::baseRejectionReason($request, $candidate);
            if ($baseRejection !== null) {
                self::recordBaseRejection($request, $candidate, $baseRejection, 'example');
                continue;
            }
            if (!self::fingerprintsMatch(
                $request['schemaVersionFingerprint'] ?? null,
                $candidate['schemaVersionFingerprint'] ?? null
            )) {
                self::emitTelemetry('reuse_stale', array_merge(self::candidateTelemetry($candidate), [
                    'reason' => 'global_schema_mismatch',
                    'schemaVersionFingerprint' => $request['schemaVersionFingerprint'] ?? null,
                    'scopeFingerprint' => $request['scopeFingerprint'] ?? null,
                ]));
                continue;
            }
            $tier = self::exampleTier($request, $candidate);
            if ($tier === null) {
                continue;
            }
            $candidate['_memoryTier'] = $tier['rank'];
            $candidate['_memoryTierName'] = $tier['name'];
            $candidate['_memorySimilarity'] = self::promptSimilarity(
                (string)($request['question'] ?? $request['normalizedQuestion'] ?? ''),
                (string)($candidate['question'] ?? $candidate['normalizedQuestion'] ?? '')
            );
            $candidate['_memoryWeakSignals'] = self::weakSignalCount($candidate);
            $candidate['_memoryCompletedAt'] = self::timestampRank($candidate['completedAt'] ?? $candidate['completed_at'] ?? null);
            $candidate['_memoryStableId'] = self::stableId($candidate);
            $ranked[] = $candidate;
        }
        usort($ranked, [self::class, 'compareExampleCandidates']);

        $selected = [];
        foreach ($ranked as $candidate) {
            if (count($selected) >= $limit) {
                break;
            }
            $trial = $selected;
            $trial[] = self::shapeExample($candidate);
            $encoded = json_encode($trial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false || strlen($encoded) > $byteLimit) {
                continue;
            }
            $selected = $trial;
            self::emitTelemetry('example_selected', array_merge(self::candidateTelemetry($candidate), [
                'tier' => $candidate['_memoryTierName'] ?? null,
                'schemaVersionFingerprint' => $request['schemaVersionFingerprint'] ?? null,
                'scopeFingerprint' => $request['scopeFingerprint'] ?? null,
            ]));
        }
        return $selected;
    }

    private static function shapeCompletedJobs(array $jobs, string $dataSource): array
    {
        $candidates = [];
        foreach ($jobs as $job) {
            if (!self::jobHasNoBoundParameters($job['params'] ?? null)) {
                continue;
            }
            $sql = trim((string)($job['sql_text'] ?? ''));
            if ($sql === '') {
                continue;
            }
            $metadata = self::decodeObject($job['metadata'] ?? null);
            $question = '';
            foreach (['originalPrompt', 'nlPrompt', 'originalName'] as $key) {
                $question = trim((string)($metadata[$key] ?? ''));
                if ($question !== '') {
                    break;
                }
            }
            if ($question === '') {
                $question = trim((string)($job['name'] ?? ''));
            }
            if ($question === '') {
                continue;
            }
            $candidates[] = [
                'jobId' => (string)($job['id'] ?? ''),
                'question' => $question,
                'sql' => $sql,
                'dataSource' => (string)($job['data_source'] ?? $dataSource),
                'completedAt' => $job['completed_at'] ?? $job['created_at'] ?? null,
            ];
        }
        return $candidates;
    }

    private static function feedbackIndexes(
        array $generationRows,
        array $jobIds,
        array $candidateSqlHashes = []
    ): array
    {
        $generationIds = array_values(array_filter(array_map(
            static function (array $generation): string {
                return trim((string)($generation['id'] ?? ''));
            },
            $generationRows
        )));
        $sqlHashes = array_values(array_unique(array_merge($candidateSqlHashes, array_filter(array_map(
            static function (array $generation): string {
                return trim((string)($generation['sql_hash'] ?? ''));
            },
            $generationRows
        )))));
        if ($generationIds === [] && $jobIds === [] && $sqlHashes === []) {
            return [[], [], []];
        }

        $feedbackRows = (new \yii\db\Query())
            ->from('ai_query_feedback')
            ->where(['or',
                ['generation_id' => $generationIds],
                ['query_job_id' => $jobIds],
                ['sql_hash' => $sqlHashes],
            ])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->all(\Yii::$app->db);
        $byGeneration = [];
        $byJob = [];
        $bySqlHash = [];
        foreach ($feedbackRows as $feedback) {
            $generationId = trim((string)($feedback['generation_id'] ?? ''));
            $jobId = trim((string)($feedback['query_job_id'] ?? ''));
            $sqlHash = trim((string)($feedback['sql_hash'] ?? ''));
            if ($generationId !== '') {
                $byGeneration[$generationId][] = $feedback;
            }
            if ($jobId !== '') {
                $byJob[$jobId][] = $feedback;
            }
            if ($sqlHash !== '') {
                $bySqlHash[$sqlHash][] = $feedback;
            }
        }
        return [$byGeneration, $byJob, $bySqlHash];
    }

    private static function feedbackRowsForCandidate(
        string $generationId,
        string $jobId,
        string $sqlHash,
        string $schemaVersionFingerprint,
        string $scopeFingerprint,
        array $feedbackByGeneration,
        array $feedbackByJob,
        array $feedbackBySqlHash
    ): array {
        $rows = array_merge(
            $feedbackByGeneration[$generationId] ?? [],
            $feedbackByJob[$jobId] ?? []
        );
        foreach ($feedbackBySqlHash[$sqlHash] ?? [] as $row) {
            if (
                trim((string)($row['schema_version_fingerprint'] ?? '')) === $schemaVersionFingerprint
                && trim((string)($row['scope_fingerprint'] ?? '')) === $scopeFingerprint
            ) {
                $rows[] = $row;
            }
        }
        $unique = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $key = isset($row['id']) ? 'id:' . (string)$row['id'] : hash('sha256', serialize($row));
                $unique[$key] = $row;
            }
        }
        return array_values($unique);
    }

    private static function preferredFeedback(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (!empty($row['reuse_suppressed'])) {
                $row['reuse_suppressed'] = 1;
                return $row;
            }
        }
        $accurate = null;
        $neutral = null;
        foreach ($rows as $row) {
            $accuracy = strtolower(trim((string)($row['result_accuracy'] ?? '')));
            if ($accuracy === 'accurate' && !empty($row['admin_reuse_approved_at'])) {
                return $row;
            }
            if ($accuracy === 'accurate' && $accurate === null) {
                $accurate = $row;
            } elseif ($neutral === null) {
                $neutral = $row;
            }
        }
        return $accurate ?? $neutral;
    }

    private static function accurateFeedbackUserIds(array $rows): array
    {
        $userIds = [];
        foreach ($rows as $row) {
            if (strtolower(trim((string)($row['result_accuracy'] ?? ''))) !== 'accurate') {
                continue;
            }
            $userId = self::normalizedUserId($row['user_id'] ?? null);
            if ($userId !== null) {
                $userIds[$userId] = $userId;
            }
        }
        ksort($userIds, SORT_NUMERIC);
        return array_values($userIds);
    }

    private static function storedDirectReuseSchemaFingerprint(array $metadata): string
    {
        $version = self::schemaVersion($metadata);
        return $version === null || trim((string)($metadata['contextHash'] ?? '')) === ''
            ? ''
            : self::directReuseSchemaFingerprint($metadata);
    }

    private static function storedSchemaVersionFingerprint(array $metadata): string
    {
        return self::schemaVersion($metadata) === null ? '' : self::schemaVersionFingerprint($metadata);
    }

    private static function normalizeScope(array $scope): array
    {
        $normalized = [];
        foreach ($scope as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $normalizedKey = trim((string)$key);
            $normalizedValue = preg_replace('/\s+/', ' ', trim((string)$value));
            if ($normalizedKey !== '' && $normalizedValue !== '') {
                $normalized[$normalizedKey] = $normalizedValue;
            }
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function decodeObject($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) && trim($value) !== '' ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function jobHasNoBoundParameters($params): bool
    {
        if ($params === null || $params === '') {
            return true;
        }
        if (is_array($params)) {
            return $params === [];
        }
        $decoded = is_string($params) ? json_decode($params, true) : null;
        return is_array($decoded) && $decoded === [];
    }

    private static function baseRejectionReason(array $request, array $candidate): ?string
    {
        if (
            !empty($candidate['reuseSuppressed'])
            || strtolower(trim((string)($candidate['resultAccuracy'] ?? ''))) === 'inaccurate'
        ) {
            return 'suppressed';
        }
        if (isset($candidate['status']) && strtolower(trim((string)$candidate['status'])) !== 'completed') {
            return 'incomplete';
        }
        if (strcasecmp(
            trim((string)($request['dataSource'] ?? '')),
            trim((string)($candidate['dataSource'] ?? ''))
        ) !== 0) {
            return 'data_source_mismatch';
        }
        if (!self::fingerprintsMatch($request['scopeFingerprint'] ?? null, $candidate['scopeFingerprint'] ?? null)) {
            return 'scope_mismatch';
        }
        if (!self::candidateSqlIsAllowed((string)($candidate['sql'] ?? $candidate['generatedSql'] ?? ''))) {
            return 'candidate_policy_failed';
        }
        return null;
    }

    private static function recordBaseRejection(
        array $request,
        array $candidate,
        string $reason,
        string $stage
    ): void {
        if ($reason === 'suppressed') {
            $fingerprints = $stage === 'direct'
                ? ['directReuseSchemaFingerprint' => $request['directReuseSchemaFingerprint'] ?? null]
                : ['schemaVersionFingerprint' => $request['schemaVersionFingerprint'] ?? null];
            self::emitTelemetry('reuse_suppressed', array_merge(self::candidateTelemetry($candidate), $fingerprints, [
                'reason' => 'explicit_suppression',
                'scopeFingerprint' => $request['scopeFingerprint'] ?? null,
            ]));
        } elseif ($reason === 'candidate_policy_failed') {
            self::recordCandidateRejected($candidate, $reason, $stage);
        }
    }

    private static function directReuseTrust(array $request, array $candidate): ?string
    {
        $provenance = strtolower(trim((string)($candidate['generationProvenance'] ?? '')));
        if ($provenance === 'verified_pattern') {
            return 'verified_global';
        }
        if ($provenance !== 'ai_built') {
            return null;
        }
        if (strtolower(trim((string)($candidate['resultAccuracy'] ?? ''))) !== 'accurate') {
            return null;
        }
        if (self::hasAdministratorApproval($candidate)) {
            return 'administrator_approved';
        }
        $requestUserId = self::normalizedUserId($request['userId'] ?? null);
        return $requestUserId !== null
            && in_array($requestUserId, self::candidateAccurateFeedbackUserIds($candidate), true)
            ? 'same_user_accurate'
            : null;
    }

    private static function exampleTier(array $request, array $candidate): ?array
    {
        $provenance = strtolower(trim((string)($candidate['generationProvenance'] ?? '')));
        if ($provenance === 'verified_pattern') {
            return ['rank' => self::TIER_VERIFIED, 'name' => 'verified_pattern'];
        }
        if ($provenance !== 'ai_built') {
            return null;
        }

        $accuracy = strtolower(trim((string)($candidate['resultAccuracy'] ?? '')));
        if ($accuracy === 'accurate' && self::hasAdministratorApproval($candidate)) {
            return ['rank' => self::TIER_ADMIN_APPROVED, 'name' => 'administrator_approved'];
        }
        if ($accuracy === 'accurate') {
            $requestUserId = self::normalizedUserId($request['userId'] ?? null);
            if (
                $requestUserId !== null
                && in_array($requestUserId, self::candidateAccurateFeedbackUserIds($candidate), true)
            ) {
                return ['rank' => self::TIER_SAME_USER_ACCURATE, 'name' => 'same_user_accurate'];
            }
            return ['rank' => self::TIER_OTHER_USER_ACCURATE, 'name' => 'other_user_accurate'];
        }
        if ($accuracy === '' || $accuracy === 'unsure') {
            return ['rank' => self::TIER_NEUTRAL, 'name' => 'neutral_success'];
        }
        return null;
    }

    private static function candidateAccurateFeedbackUserIds(array $candidate): array
    {
        $userIds = is_array($candidate['accurateFeedbackUserIds'] ?? null)
            ? $candidate['accurateFeedbackUserIds']
            : [];
        return array_values(array_filter(array_map(
            [self::class, 'normalizedUserId'],
            $userIds
        ), static function ($userId): bool {
            return $userId !== null;
        }));
    }

    private static function candidateSqlIsAllowed(string $sql): bool
    {
        if (trim($sql) === '') {
            return false;
        }
        try {
            SqlBuilderService::validateSafety($sql);
            SqlBuilderService::validateTablePolicy($sql);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private static function compareDirectCandidates(array $left, array $right): int
    {
        foreach (['_memoryTier', '_memoryCompletedAt'] as $field) {
            $comparison = ((int)($right[$field] ?? 0)) <=> ((int)($left[$field] ?? 0));
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return strcmp(self::stableId($left), self::stableId($right));
    }

    private static function compareExampleCandidates(array $left, array $right): int
    {
        foreach (['_memoryTier', '_memorySimilarity', '_memoryWeakSignals', '_memoryCompletedAt'] as $field) {
            $comparison = ((int)($right[$field] ?? 0)) <=> ((int)($left[$field] ?? 0));
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return strcmp((string)$left['_memoryStableId'], (string)$right['_memoryStableId']);
    }

    private static function shapeExample(array $candidate): array
    {
        return [
            'id' => self::stableId($candidate),
            'generationId' => $candidate['generationId'] ?? null,
            'question' => (string)($candidate['question'] ?? $candidate['normalizedQuestion'] ?? ''),
            'sql' => (string)($candidate['sql'] ?? $candidate['generatedSql'] ?? ''),
            'sqlHash' => $candidate['sqlHash'] ?? null,
            'generationProvenance' => (string)($candidate['generationProvenance'] ?? ''),
            'resultAccuracy' => $candidate['resultAccuracy'] ?? null,
            'rankTier' => (string)$candidate['_memoryTierName'],
            'schemaVersionFingerprint' => (string)($candidate['schemaVersionFingerprint'] ?? ''),
            'scopeFingerprint' => (string)($candidate['scopeFingerprint'] ?? ''),
        ];
    }

    private static function directTrustRank(string $trust): int
    {
        if ($trust === 'verified_global') {
            return self::TIER_VERIFIED;
        }
        if ($trust === 'administrator_approved') {
            return self::TIER_ADMIN_APPROVED;
        }
        return self::TIER_SAME_USER_ACCURATE;
    }

    private static function hasAdministratorApproval(array $candidate): bool
    {
        return trim((string)($candidate['adminReuseApprovedAt'] ?? '')) !== '';
    }

    private static function weakSignalCount(array $candidate): int
    {
        return max(0, (int)($candidate['savedCount'] ?? 0))
            + max(0, (int)($candidate['downloadedCount'] ?? 0))
            + max(0, (int)($candidate['rerunCount'] ?? 0))
            + max(0, (int)($candidate['followUpCount'] ?? 0));
    }

    private static function stableId(array $candidate): string
    {
        foreach (['id', 'generationId', 'jobId'] as $key) {
            $value = trim((string)($candidate[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return hash('sha256', (string)($candidate['sql'] ?? $candidate['generatedSql'] ?? ''));
    }

    private static function promptSimilarity(string $left, string $right): int
    {
        $left = self::normalizeQuestion($left);
        $right = self::normalizeQuestion($right);
        if ($left === '' || $right === '') {
            return 0;
        }
        if ($left === $right) {
            return 100;
        }
        similar_text($left, $right, $percentage);
        return (int)round($percentage);
    }

    private static function normalizeQuestion(string $question): string
    {
        $normalized = strtolower($question);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        $normalized = preg_replace('/\b(the|a|an|please|show|me|tell)\b/u', ' ', (string)$normalized);
        return (string)preg_replace('/\s+/u', ' ', trim((string)$normalized));
    }

    private static function candidateTelemetry(array $candidate): array
    {
        return [
            'candidateId' => self::stableId($candidate),
            'generationId' => $candidate['generationId'] ?? $candidate['sourceGenerationId'] ?? null,
            'jobId' => $candidate['jobId'] ?? null,
            'sqlHash' => $candidate['sqlHash'] ?? null,
        ];
    }

    private static function emitTelemetry(string $event, array $payload): void
    {
        $record = ['event' => self::normalizeTelemetryLabel($event, 'query_memory_event')];
        foreach (self::TELEMETRY_FIELDS as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                continue;
            }
            $value = $payload[$field];
            if (in_array($field, ['reason', 'stage', 'signal', 'reuseTrust', 'tier', 'resultAccuracy'], true)) {
                $value = self::normalizeTelemetryLabel((string)$value, 'unknown');
            } elseif ($field === 'approved') {
                $value = (bool)$value;
            } elseif (in_array($field, ['count', 'feedbackId', 'administratorId', 'clearedCount'], true)) {
                $value = (int)$value;
            } else {
                $value = (string)$value;
            }
            $record[$field] = $value;
        }

        if (is_callable(self::$telemetrySink)) {
            call_user_func(self::$telemetrySink, $record);
        }
        if (class_exists('\\Yii', false) && \Yii::$app !== null) {
            \Yii::info(
                'Query-memory telemetry: ' . json_encode($record, JSON_UNESCAPED_SLASHES),
                'query.memory'
            );
        }
    }

    private static function normalizeTelemetryLabel(string $value, string $fallback): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized);
        $normalized = trim((string)$normalized, '_');
        return $normalized === '' ? $fallback : substr($normalized, 0, 80);
    }

    private static function canonicalJson(array $value): string
    {
        $encoded = json_encode(
            self::canonicalizeArray($value, false),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($encoded === false) {
            throw new \InvalidArgumentException('Unable to canonicalize query-memory fingerprint input.');
        }
        return $encoded;
    }

    private static function canonicalizeArray(array $value, bool $sortLists): array
    {
        $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalizeArray($item, $sortLists);
            } elseif ($sortLists && is_string($item)) {
                $value[$key] = preg_replace('/\s+/u', ' ', trim($item));
            }
        }
        if ($isList && $sortLists) {
            usort($value, static function ($left, $right): int {
                return strcmp(
                    (string)json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    (string)json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            });
        }
        return $value;
    }

    private static function fingerprintsMatch($left, $right): bool
    {
        $left = trim((string)$left);
        $right = trim((string)$right);
        return $left !== '' && $right !== '' && hash_equals($left, $right);
    }

    private static function schemaVersion(array $metadata)
    {
        return $metadata['version'] ?? $metadata['scraped_at'] ?? null;
    }

    private static function normalizedUserId($value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }

    private static function timestampRank($value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : (int)$timestamp;
    }
}
