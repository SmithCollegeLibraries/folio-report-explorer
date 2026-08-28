<?php

require_once __DIR__ . '/../services/SqlBuilderService.php';
require_once __DIR__ . '/../services/QueryMemoryService.php';

use app\services\QueryMemoryService;

function acceptanceAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$question = 'Show annual checkout counts at Neilson Library';
$sql = 'SELECT COUNT(*) FROM circulation.audit_loan__t';
$request = [
    'question' => $question,
    'dataSource' => 'folio',
    'userId' => 17,
    'directReuseSchemaFingerprint' => 'strict-v1',
    'schemaVersionFingerprint' => 'global-v1',
    'scopeFingerprint' => 'scope-smith',
];
$candidate = [
    'id' => 'candidate-ai',
    'generationId' => 'generation-ai',
    'jobId' => 'job-ai',
    'question' => $question,
    'sql' => $sql,
    'sqlHash' => hash('sha256', $sql),
    'dataSource' => 'folio',
    'userId' => 17,
    'generationProvenance' => 'ai_built',
    'resultAccuracy' => null,
    'adminReuseApprovedAt' => null,
    'directReuseSchemaFingerprint' => 'strict-v1',
    'schemaVersionFingerprint' => 'global-v1',
    'scopeFingerprint' => 'scope-smith',
    'reuseSuppressed' => false,
    'status' => 'completed',
];

acceptanceAssert(QueryMemoryService::findDirectReuse($request, [$candidate]) === null, 'A neutral AI completion must not be directly reused.');

$candidate['resultAccuracy'] = 'accurate';
$sameUser = QueryMemoryService::findDirectReuse($request, [$candidate]);
acceptanceAssert(($sameUser['reuseTrust'] ?? null) === 'same_user_accurate', 'Accurate feedback should enable compatible same-user direct reuse.');
acceptanceAssert(($sameUser['generationProvenance'] ?? null) === 'ai_built', 'Same-user reuse must retain AI-built provenance.');

$otherUserRequest = array_merge($request, ['userId' => 29]);
acceptanceAssert(QueryMemoryService::findDirectReuse($otherUserRequest, [$candidate]) === null, 'Other users must not directly reuse unapproved AI-built SQL.');
$otherUserExamples = QueryMemoryService::selectAiExamples($otherUserRequest, [$candidate]);
acceptanceAssert(($otherUserExamples[0]['rankTier'] ?? null) === 'other_user_accurate', 'Other-user Accurate SQL may guide fresh AI generation as an example.');

$candidate['adminReuseApprovedAt'] = '2026-08-27 12:00:00';
$approved = QueryMemoryService::findDirectReuse($otherUserRequest, [$candidate]);
acceptanceAssert(($approved['reuseTrust'] ?? null) === 'administrator_approved', 'Administrator approval should enable compatible cross-user direct reuse.');
acceptanceAssert(($approved['generationProvenance'] ?? null) === 'ai_built', 'Administrator approval must not promote provenance.');

$candidate['resultAccuracy'] = 'inaccurate';
$candidate['reuseSuppressed'] = true;
acceptanceAssert(QueryMemoryService::findDirectReuse($request, [$candidate]) === null, 'Inaccurate exact SQL must be suppressed from direct reuse immediately.');
acceptanceAssert(QueryMemoryService::selectAiExamples($request, [$candidate]) === [], 'Inaccurate exact SQL must be suppressed from examples immediately.');

$candidate['resultAccuracy'] = 'accurate';
$candidate['reuseSuppressed'] = false;
$candidate['directReuseSchemaFingerprint'] = 'strict-old-context';
acceptanceAssert(QueryMemoryService::findDirectReuse($request, [$candidate]) === null, 'A changed prompt-context fingerprint must miss direct reuse.');
$strictStaleExamples = QueryMemoryService::selectAiExamples($request, [$candidate]);
acceptanceAssert(count($strictStaleExamples) === 1, 'A strict-context change must leave a globally compatible record eligible as an AI example.');

$candidate['schemaVersionFingerprint'] = 'global-old-version';
acceptanceAssert(QueryMemoryService::findDirectReuse($request, [$candidate]) === null, 'A stale record must not become directly reusable.');
acceptanceAssert(QueryMemoryService::selectAiExamples($request, [$candidate]) === [], 'A global schema-version change must exclude the record from examples so normal generation can continue.');
acceptanceAssert($candidate['generationProvenance'] === 'ai_built', 'AI-originated provenance must remain immutable throughout the trust ladder.');

fwrite(STDOUT, "QueryMemoryAcceptanceTest passed\n");
