<?php

$servicePath = __DIR__ . '/../services/QueryMemoryService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "QueryMemoryService is missing at {$servicePath}\n");
    exit(1);
}

require_once __DIR__ . '/../services/SqlBuilderService.php';
require_once $servicePath;

use app\services\QueryMemoryService;

function assertQueryMemory($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function memoryCandidate(array $overrides = []): array
{
    return array_merge([
        'id' => 'candidate-1',
        'generationId' => 'generation-1',
        'jobId' => 'job-1',
        'question' => 'Show annual circulation at Neilson Library',
        'sql' => 'SELECT COUNT(*) FROM circulation.audit_loan__t',
        'sqlHash' => hash('sha256', 'safe-sql'),
        'dataSource' => 'folio',
        'userId' => 17,
        'generationProvenance' => 'ai_built',
        'resultAccuracy' => 'accurate',
        'adminReuseApprovedAt' => null,
        'directReuseSchemaFingerprint' => 'strict-schema-v1',
        'schemaVersionFingerprint' => 'global-schema-v1',
        'scopeFingerprint' => 'scope-smith',
        'reuseSuppressed' => false,
        'savedCount' => 0,
        'downloadedCount' => 0,
        'rerunCount' => 0,
        'followUpCount' => 0,
        'completedAt' => '2026-08-20 10:00:00',
    ], $overrides);
}

$metadataA = ['contextHash' => 'prompt-a', 'version' => '2026-08-27', 'extra' => 'ignored'];
$metadataAReordered = ['version' => '2026-08-27', 'contextHash' => 'prompt-a'];
assertQueryMemory(
    QueryMemoryService::directReuseSchemaFingerprint($metadataA)
        === QueryMemoryService::directReuseSchemaFingerprint($metadataAReordered),
    'Strict schema fingerprints must be stable across metadata key order and ignore unrelated metadata.'
);
assertQueryMemory(
    QueryMemoryService::directReuseSchemaFingerprint($metadataA)
        !== QueryMemoryService::directReuseSchemaFingerprint(['version' => '2026-08-27', 'contextHash' => 'prompt-b']),
    'Strict schema fingerprints must include the prompt-scoped context hash.'
);
assertQueryMemory(
    QueryMemoryService::schemaVersionFingerprint($metadataA)
        === QueryMemoryService::schemaVersionFingerprint(['version' => '2026-08-27', 'contextHash' => 'another-prompt']),
    'Global schema fingerprints must not include the prompt-scoped context hash.'
);
assertQueryMemory(
    QueryMemoryService::scopeFingerprint('FOLIO', ['campuses' => ['Smith', 'Hampshire'], 'domain' => 'Inventory'])
        === QueryMemoryService::scopeFingerprint('folio', ['domain' => 'Inventory', 'campuses' => ['Hampshire', 'Smith']]),
    'Scope fingerprints must canonicalize source, associative keys, and set-like authorized values.'
);

$directRequest = [
    'question' => 'Please show annual circulation at Neilson Library.',
    'dataSource' => 'folio',
    'userId' => 17,
    'directReuseSchemaFingerprint' => 'strict-schema-v1',
    'scopeFingerprint' => 'scope-smith',
];

$trustCases = [
    ['verified_pattern', null, false, 17, 22, true, 'verified_global'],
    ['ai_built', 'accurate', false, 17, 17, true, 'same_user_accurate'],
    ['ai_built', 'accurate', false, 17, 22, false, null],
    ['ai_built', 'accurate', true, 17, 22, true, 'administrator_approved'],
    ['ai_built', 'inaccurate', true, 17, 17, false, null],
    ['ai_built', 'unsure', false, 17, 17, false, null],
    ['ai_built', null, false, 17, 17, false, null],
    [null, 'accurate', false, 17, 17, false, null],
];

foreach ($trustCases as $index => $case) {
    [$provenance, $accuracy, $approved, $candidateUser, $requestUser, $eligible, $trust] = $case;
    $request = array_merge($directRequest, ['userId' => $requestUser]);
    $candidate = memoryCandidate([
        'generationProvenance' => $provenance,
        'resultAccuracy' => $accuracy,
        'adminReuseApprovedAt' => $approved ? '2026-08-26 12:00:00' : null,
        'userId' => $candidateUser,
    ]);
    $match = QueryMemoryService::findDirectReuse($request, [$candidate]);
    assertQueryMemory(($match !== null) === $eligible, "Direct reuse trust case {$index} eligibility was incorrect.");
    assertQueryMemory(($match['reuseTrust'] ?? null) === $trust, "Direct reuse trust case {$index} returned the wrong trust source.");
    if ($match !== null) {
        assertQueryMemory(($match['generationProvenance'] ?? null) === $provenance, 'Direct reuse must preserve immutable provenance.');
    }
}

foreach ([
    ['directReuseSchemaFingerprint' => 'strict-schema-v2'],
    ['scopeFingerprint' => 'scope-hampshire'],
    ['dataSource' => 'local'],
    ['reuseSuppressed' => true],
    ['question' => 'Show acquisition spending by vendor'],
    ['sql' => 'DELETE FROM circulation.audit_loan__t'],
    ['sql' => 'SELECT * FROM users.users__t'],
] as $rejection) {
    assertQueryMemory(
        QueryMemoryService::findDirectReuse($directRequest, [memoryCandidate($rejection)]) === null,
        'Stale, incompatible, suppressed, unsafe, or nonmatching direct candidates must be rejected.'
    );
}

$exampleRequest = [
    'question' => 'Compare circulation trends at Neilson',
    'dataSource' => 'folio',
    'userId' => 17,
    'schemaVersionFingerprint' => 'global-schema-v1',
    'scopeFingerprint' => 'scope-smith',
];
$examples = QueryMemoryService::selectAiExamples($exampleRequest, [
    memoryCandidate([
        'id' => 'neutral',
        'question' => 'Compare yearly borrowing patterns',
        'generationProvenance' => 'ai_built',
        'resultAccuracy' => null,
        'userId' => 31,
        'savedCount' => 9,
        'directReuseSchemaFingerprint' => 'different-prompt-context-a',
    ]),
    memoryCandidate([
        'id' => 'other-accurate',
        'question' => 'Analyze annual loans',
        'generationProvenance' => 'ai_built',
        'resultAccuracy' => 'accurate',
        'userId' => 31,
        'directReuseSchemaFingerprint' => 'different-prompt-context-b',
    ]),
    memoryCandidate([
        'id' => 'same-user',
        'question' => 'Show circulation by year',
        'generationProvenance' => 'ai_built',
        'resultAccuracy' => 'accurate',
        'userId' => 17,
        'directReuseSchemaFingerprint' => 'different-prompt-context-c',
    ]),
    memoryCandidate([
        'id' => 'admin',
        'question' => 'Circulation summary by library',
        'generationProvenance' => 'ai_built',
        'resultAccuracy' => 'accurate',
        'userId' => 31,
        'adminReuseApprovedAt' => '2026-08-26 12:00:00',
        'directReuseSchemaFingerprint' => 'different-prompt-context-d',
    ]),
    memoryCandidate([
        'id' => 'verified',
        'question' => 'Inventory circulation totals',
        'generationProvenance' => 'verified_pattern',
        'resultAccuracy' => null,
        'userId' => 31,
        'directReuseSchemaFingerprint' => 'different-prompt-context-e',
    ]),
], 5);

assertQueryMemory(
    array_column($examples, 'id') === ['verified', 'admin', 'same-user', 'other-accurate', 'neutral'],
    'AI examples must rank by explicit trust tier before similarity and weak signals.'
);
assertQueryMemory(
    array_column($examples, 'rankTier') === [
        'verified_pattern',
        'administrator_approved',
        'same_user_accurate',
        'other_user_accurate',
        'neutral_success',
    ],
    'AI examples must expose stable ranking tiers.'
);

$tieBreakExamples = QueryMemoryService::selectAiExamples($exampleRequest, [
    memoryCandidate(['id' => 'near-high-signals', 'question' => 'Compare circulation', 'resultAccuracy' => null, 'userId' => 31, 'savedCount' => 99]),
    memoryCandidate(['id' => 'exact-low-signals', 'question' => $exampleRequest['question'], 'resultAccuracy' => null, 'userId' => 31, 'completedAt' => '2026-08-18 10:00:00']),
    memoryCandidate(['id' => 'exact-older-signals', 'question' => $exampleRequest['question'], 'resultAccuracy' => null, 'userId' => 31, 'savedCount' => 10, 'completedAt' => '2026-08-19 10:00:00']),
    memoryCandidate(['id' => 'zzz-stable', 'question' => $exampleRequest['question'], 'resultAccuracy' => null, 'userId' => 31, 'savedCount' => 10, 'completedAt' => '2026-08-21 10:00:00']),
    memoryCandidate(['id' => 'aaa-stable', 'question' => $exampleRequest['question'], 'resultAccuracy' => null, 'userId' => 31, 'savedCount' => 10, 'completedAt' => '2026-08-21 10:00:00']),
], 5);
assertQueryMemory(
    array_column($tieBreakExamples, 'id') === [
        'aaa-stable',
        'zzz-stable',
        'exact-older-signals',
        'exact-low-signals',
        'near-high-signals',
    ],
    'Within a tier, ranking must use similarity, weak signals, recency, then stable ID.'
);

$excludedExamples = QueryMemoryService::selectAiExamples($exampleRequest, [
    memoryCandidate(['id' => 'stale', 'schemaVersionFingerprint' => 'global-schema-v2']),
    memoryCandidate(['id' => 'suppressed', 'reuseSuppressed' => true]),
    memoryCandidate(['id' => 'inaccurate', 'resultAccuracy' => 'inaccurate']),
    memoryCandidate(['id' => 'wrong-scope', 'scopeFingerprint' => 'scope-hampshire']),
    memoryCandidate(['id' => 'wrong-source', 'dataSource' => 'local']),
    memoryCandidate(['id' => 'unsafe', 'sql' => 'UPDATE inventory.instance__t SET title = NULL']),
    memoryCandidate(['id' => 'blocked-table', 'sql' => 'SELECT * FROM users.users__t']),
]);
assertQueryMemory($excludedExamples === [], 'Stale, suppressed, inaccurate, unauthorized, and unsafe examples must be absent.');

$limitedExamples = QueryMemoryService::selectAiExamples($exampleRequest, [
    memoryCandidate(['id' => 'one', 'generationProvenance' => 'verified_pattern', 'question' => str_repeat('a', 80)]),
    memoryCandidate(['id' => 'two', 'generationProvenance' => 'verified_pattern', 'question' => str_repeat('b', 80)]),
    memoryCandidate(['id' => 'three', 'generationProvenance' => 'verified_pattern', 'question' => str_repeat('c', 80)]),
    memoryCandidate(['id' => 'four', 'generationProvenance' => 'verified_pattern', 'question' => str_repeat('d', 80)]),
], 3, 900);
assertQueryMemory(count($limitedExamples) <= 3, 'AI example count must respect the configured limit.');
assertQueryMemory(
    strlen((string)json_encode($limitedExamples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= 900,
    'AI example payload must respect the configured UTF-8 byte limit.'
);

echo "QueryMemoryServiceTest passed\n";
