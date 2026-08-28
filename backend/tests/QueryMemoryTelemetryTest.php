<?php

require_once __DIR__ . '/../services/SqlBuilderService.php';
require_once __DIR__ . '/../services/QueryMemoryService.php';

use app\services\QueryMemoryService;

function telemetryAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$secretQuestion = 'RAW PROMPT MUST NEVER APPEAR';
$secretSql = 'SELECT secret_raw_sql FROM circulation.audit_loan__t';
$secretNote = 'RAW FEEDBACK NOTE MUST NEVER APPEAR';
$baseRequest = [
    'question' => $secretQuestion,
    'dataSource' => 'folio',
    'userId' => 17,
    'directReuseSchemaFingerprint' => 'strict-current',
    'schemaVersionFingerprint' => 'global-current',
    'scopeFingerprint' => 'scope-current',
];
$baseCandidate = [
    'id' => 'candidate-1',
    'generationId' => 'generation-1',
    'jobId' => 'job-1',
    'question' => $secretQuestion,
    'sql' => $secretSql,
    'sqlHash' => hash('sha256', $secretSql),
    'dataSource' => 'folio',
    'userId' => 17,
    'generationProvenance' => 'ai_built',
    'resultAccuracy' => 'accurate',
    'accurateFeedbackUserIds' => [17],
    'directReuseSchemaFingerprint' => 'strict-current',
    'schemaVersionFingerprint' => 'global-current',
    'scopeFingerprint' => 'scope-current',
    'status' => 'completed',
];

$events = [];
QueryMemoryService::setTelemetrySink(static function (array $event) use (&$events): void {
    $events[] = $event;
});
try {
    QueryMemoryService::findDirectReuse($baseRequest, [$baseCandidate]);
    QueryMemoryService::findDirectReuse($baseRequest, [array_merge($baseCandidate, ['reuseSuppressed' => true])]);
    QueryMemoryService::findDirectReuse($baseRequest, [array_merge($baseCandidate, ['directReuseSchemaFingerprint' => 'strict-stale'])]);
    QueryMemoryService::findDirectReuse($baseRequest, [array_merge($baseCandidate, ['sql' => 'DELETE FROM circulation.audit_loan__t'])]);
    QueryMemoryService::selectAiExamples($baseRequest, [
        $baseCandidate,
        array_merge($baseCandidate, ['id' => 'example-stale', 'schemaVersionFingerprint' => 'global-stale']),
        array_merge($baseCandidate, ['id' => 'example-suppressed', 'reuseSuppressed' => true]),
    ]);
    QueryMemoryService::recordCandidateRejected($baseCandidate, 'preflight_failed', 'preflight');
    QueryMemoryService::recordFeedback([
        'feedbackId' => 9,
        'generationId' => 'generation-1',
        'queryJobId' => 'job-1',
        'sqlHash' => hash('sha256', $secretSql),
        'resultAccuracy' => 'inaccurate',
        'schemaVersionFingerprint' => 'global-current',
        'scopeFingerprint' => 'scope-current',
        'feedbackNote' => $secretNote,
    ]);
    QueryMemoryService::recordWeakSignal('generation-1', 'job-1', 'saved', 2);
    QueryMemoryService::recordApprovalChanged(9, 'generation-1', 'job-1', hash('sha256', $secretSql), true, 1);
} finally {
    QueryMemoryService::setTelemetrySink(null);
}

$eventNames = array_column($events, 'event');
foreach ([
    'reuse_selected',
    'reuse_suppressed',
    'reuse_stale',
    'reuse_candidate_rejected',
    'example_selected',
    'feedback_recorded',
    'weak_signal_recorded',
    'reuse_approval_changed',
] as $requiredEvent) {
    telemetryAssert(in_array($requiredEvent, $eventNames, true), "Missing structured telemetry event {$requiredEvent}.");
}

$encoded = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
telemetryAssert(strpos((string)$encoded, $secretQuestion) === false, 'Telemetry must not contain raw prompts.');
telemetryAssert(strpos((string)$encoded, $secretSql) === false, 'Telemetry must not contain raw SQL.');
telemetryAssert(strpos((string)$encoded, $secretNote) === false, 'Telemetry must not contain feedback notes.');

$allowedKeys = [
    'event', 'candidateId', 'generationId', 'jobId', 'feedbackId', 'sqlHash',
    'reuseTrust', 'tier', 'reason', 'stage', 'count', 'signal', 'approved',
    'administratorId', 'directReuseSchemaFingerprint', 'schemaVersionFingerprint',
    'scopeFingerprint', 'resultAccuracy', 'clearedCount',
];
foreach ($events as $event) {
    telemetryAssert(array_diff(array_keys($event), $allowedKeys) === [], 'Telemetry emitted a non-allowlisted field.');
}

$reuseSelected = $events[array_search('reuse_selected', $eventNames, true)];
telemetryAssert(($reuseSelected['directReuseSchemaFingerprint'] ?? null) === 'strict-current', 'Direct-reuse telemetry must identify the strict context fingerprint.');
$exampleSelected = $events[array_search('example_selected', $eventNames, true)];
telemetryAssert(($exampleSelected['schemaVersionFingerprint'] ?? null) === 'global-current', 'Example telemetry must identify the global schema-version fingerprint.');

fwrite(STDOUT, "QueryMemoryTelemetryTest passed\n");
