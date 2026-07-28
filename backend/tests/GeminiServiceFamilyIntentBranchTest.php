<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$graphBuilderPath = __DIR__ . '/../services/CanonicalQueryGraphArtifactBuilder.php';
$graphServicePath = __DIR__ . '/../services/CanonicalQueryGraphService.php';
$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$compilerServicePath = __DIR__ . '/../services/QueryFamilyCompilerService.php';
$queryIntentServicePath = __DIR__ . '/../services/QueryIntentService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
    'FolioSchemaService' => $schemaServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
    'CanonicalQueryGraphArtifactBuilder' => $graphBuilderPath,
    'CanonicalQueryGraphService' => $graphServicePath,
    'QueryFamilyContractService' => $contractServicePath,
    'QueryFamilySlotService' => $slotServicePath,
    'QueryFamilyCompilerService' => $compilerServicePath,
    'QueryIntentService' => $queryIntentServicePath,
    'GeminiService' => $geminiServicePath,
] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "{$label} is missing at {$path}\n");
        exit(1);
    }
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            if ($alias === '@app/data/canonical_query_graph.json') {
                return __DIR__ . '/../data/canonical_query_graph.json';
            }
            if ($alias === '@app/data/query_family_contracts.json') {
                return __DIR__ . '/../data/query_family_contracts.json';
            }
            if ($alias === '@app/data/table_mapping_cache.json') {
                return __DIR__ . '/../data/table_mapping_cache.json';
            }
            if ($alias === '@app/data/column_cache.json') {
                return __DIR__ . '/../data/column_cache.json';
            }
            if ($alias === '@app/data/subtable_cache.json') {
                return __DIR__ . '/../data/subtable_cache.json';
            }
            if ($alias === '@app/data/semantic_context.json') {
                return __DIR__ . '/../data/semantic_context.json';
            }

            return $alias;
        }

        public static function warning($message, $category = null)
        {
        }

        public static function info($message, $category = null)
        {
        }
    }
}

Yii::$app = (object) [
    'cache' => null,
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
        'defaultQueryLimit' => 100,
        'maxQueryRows' => 1000,
    ],
];

require_once $schemaServicePath;
require_once $sqlBuilderPath;
require_once $graphBuilderPath;
require_once $graphServicePath;
require_once $contractServicePath;
require_once $slotServicePath;
require_once $compilerServicePath;
require_once $queryIntentServicePath;
require_once $geminiServicePath;

use app\services\GeminiService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$familyBranch = new ReflectionMethod(GeminiService::class, 'buildQueryFamilyIntentResponse');

$canonicalEvidence = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'location' => 'Neilson Reference',
            'requested_outputs' => ['average_age_years'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    ['familyKey' => 'inventory_collection_age'],
    'What is the average age of the Neilson Reference collection in Neilson Library?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'canonical-evidence-fingerprint',
        'schemaVersion' => '2026-07-21T00:00:00Z',
        'schemaContextHash' => 'schema-context-hash',
        'schemaContextBytes' => 123,
    ],
    function (): array {
        return [
            'sql' => 'SELECT 1 AS average_age_years',
            'dataSource' => 'folio',
            'route' => 'builder_intent',
            'routeReason' => 'family_contract_supported:inventory_collection_age',
            'queryDefinition' => ['tables' => [], 'columns' => [], 'filters' => [], 'joins' => []],
        ];
    }
);
assertSameValue('inventory_collection_age', $canonicalEvidence['_askEvidence']['queryFamily'] ?? null, 'Canonical family results must retain their trusted family key.');
assertSameValue('test-model', $canonicalEvidence['_askEvidence']['modelName'] ?? null, 'Canonical family results must retain the configured model provenance.');
assertSameValue('family_slot_prompt.v1', $canonicalEvidence['_askEvidence']['promptVersion'] ?? null, 'Canonical family results must retain prompt provenance.');
assertSameValue('2026-07-21T00:00:00Z', $canonicalEvidence['_askEvidence']['schemaMetadata']['version'] ?? null, 'Canonical family results must retain schema provenance.');

$familyRepairCalls = 0;
$explicitFamilyCandidate = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Josten Library',
            'requested_outputs' => ['title'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    ['familyKey' => 'inventory_library_location_listing'],
    'For instance numbers in0001 and in0002, show title in Josten Library.',
    'Smith College',
    ['model' => 'test-model', 'promptVersion' => 'family_slot_prompt.v1'],
    function (): array {
        return [
            'sql' => "SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid IN ('in0001','in0002','in9999')",
            'dataSource' => 'folio',
            'route' => 'builder_intent',
            'routeReason' => 'family_contract_supported:inventory_library_location_listing',
            'queryDefinition' => ['tables' => [], 'columns' => [], 'filters' => [], 'joins' => []],
        ];
    },
    null,
    function (string $prompt, $campus, array $candidate) use (&$familyRepairCalls): array {
        $familyRepairCalls++;
        return [
            'sql' => "SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid IN ('in0001','in0002')",
            'dataSource' => 'folio',
            'mode' => 'exploratory',
            'route' => 'exploratory_legacy_freeform',
            'routeReason' => 'family_contract_supported:inventory_library_location_listing',
            'repairAttempts' => 1,
        ];
    }
);
assertSameValue(1, $familyRepairCalls, 'A routed family candidate that broadens the explicit identifier set must enter the existing repair seam.');
assertSameValue(1, $explicitFamilyCandidate['repairAttempts'] ?? null, 'Routed-family explicit repair must report the shared repair count.');
assertSameValue("SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid IN ('in0001','in0002')", $explicitFamilyCandidate['sql'] ?? null, 'Routed-family explicit repair must return the exact-set repaired candidate.');

$routerFallback = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'requested_outputs' => ['title'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    ['familyKey' => 'inventory_library_location_listing'],
    'Show available Smith College items',
    'Smith College',
    ['model' => 'test-model', 'promptVersion' => 'family_slot_prompt.v1'],
    null,
    function (): array {
        return [
            'mode' => 'exploratory',
            'route' => 'exploratory_legacy_freeform',
            'sql' => 'SELECT id FROM inventory.item__t',
        ];
    }
);
assertSameValue('exploratory', $routerFallback['mode'] ?? null, 'A known-family router fallback must remain exploratory.');
assertSameValue('inventory_library_location_listing', $routerFallback['_askEvidence']['queryFamily'] ?? null, 'A known-family router fallback must retain its validated family key.');

$receivedPayload = null;
$result = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_contributor_campus_item_barcode',
        'slots' => [
            'campus' => 'Smith College',
            'contributor_name' => 'Smith College. Department of Biological Sciences.',
            'contributor_name_type' => 'Corporate name',
            'material_type' => 'Theses',
            'requested_outputs' => ['barcode', 'title'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_contributor_campus_item_barcode',
    ],
    'Show barcodes for Smith College theses with the corporate-body contributor named Smith College. Department of Biological Sciences.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'intent-branch-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason, $prompt, $campus, array $telemetryContext) use (&$receivedPayload): array {
        $receivedPayload = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
            'prompt' => $prompt,
            'campus' => $campus,
            'telemetry' => $telemetryContext,
        ];

        return [
            'sql' => 'SELECT legacy_fallback_stub',
            'explanation' => 'Legacy fallback stub.',
            'dataSource' => 'folio',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $result['route'] ?? null, 'The family intent branch should return the family compiler helper result unchanged when compilation is stubbed.');
assertSameValue('family_contract_supported:inventory_contributor_campus_item_barcode', $receivedPayload['routeReason'] ?? null, 'The family intent branch should derive the checked-in contributor-family route reason.');
assertSameValue('exact_phrase', $receivedPayload['normalizedPayload']['slots']['match_policy'] ?? null, 'Named contributor prompts should normalize contributor matching to exact_phrase before compilation.');

$clarificationBuilderCalled = false;
$clarificationResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_contributor_campus_item_barcode',
        'slots' => [
            'campus' => 'Smith College',
            'requested_outputs' => ['barcode'],
        ],
    ],
    [
        'familyKey' => 'inventory_contributor_campus_item_barcode',
    ],
    'Show me Smith College items with barcodes',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'intent-clarification-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function () use (&$clarificationBuilderCalled): array {
        $clarificationBuilderCalled = true;
        return [
            'sql' => 'SELECT should_not_run',
        ];
    }
);

assertSameValue(false, $clarificationBuilderCalled, 'The family compiler helper should not run when required family slots are missing.');
assertSameValue(true, $clarificationResult['needsClarification'] ?? null, 'Missing required family slots should return a structured clarification response.');
assertSameValue('Which contributor should I use for this report?', $clarificationResult['question'] ?? null, 'Missing contributor-name slots should map to a deterministic clarification question.');
assertSameValue('family_slot:contributor_name', $clarificationResult['clarificationKey'] ?? null, 'Missing required family slot clarifications should expose a stable clarification key for free-text responses.');
assertSameValue(true, $clarificationResult['freeTextAllowed'] ?? null, 'Missing required family slot clarifications should allow users to type the missing value.');
assertSameValue(['contributor_name'], $clarificationResult['missingSlots'] ?? null, 'Clarification responses should expose the missing contributor_name slot.');

$listingBuilderCalled = false;
$listingClarificationResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'location_code' => 'SJTR',
            'requested_outputs' => ['title', 'author', 'barcode', 'instance_number'],
        ],
    ],
    [
        'familyKey' => 'inventory_library_location_listing',
    ],
    'List of materials in location code SJTR. Include title, author, pub date, barcode and instance number.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'listing-single-code-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload) use (&$listingBuilderCalled): array {
        $listingBuilderCalled = true;
        return [
            'sql' => 'SELECT should_not_run',
        ];
    }
);

assertSameValue(false, $listingBuilderCalled, 'Single-code listing prompts should not continue to compilation when library scope is still unresolved.');
assertSameValue(true, $listingClarificationResult['needsClarification'] ?? null, 'Single location-code listing prompts should return a clarification response.');
assertSameValue('Which library should I use for this report?', $listingClarificationResult['question'] ?? null, 'Single location-code listing prompts should ask for the concrete library.');
assertSameValue(['library'], $listingClarificationResult['missingSlots'] ?? null, 'Single location-code listing prompts should expose the missing library slot.');

$multiCodePayload = null;
$multiCodeResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'SJTR',
            'location' => 'SJTRF',
            'requested_outputs' => ['title', 'author', 'barcode', 'instance_number'],
        ],
    ],
    [
        'familyKey' => 'inventory_library_location_listing',
    ],
    'List of materials in the SJTR and SJTRF location. Include title, author, pub date, barcode and instance number.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'listing-multi-code-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload) use (&$multiCodePayload): array {
        $multiCodePayload = $normalizedPayload;
        return [
            'sql' => 'SELECT listing_multi_location_code_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('SJTR,SJTRF', $multiCodePayload['slots']['location_code'] ?? null, 'Prompt-scoped recovery should collapse multiple location codes into the location_code slot.');
assertSameValue(false, array_key_exists('library', $multiCodePayload['slots'] ?? []), 'Prompt-scoped recovery should clear code-like library placeholders for multi-code listing prompts.');
assertSameValue('legacy_fallback', $multiCodeResult['route'] ?? null, 'Recovered multi-code listing prompts should continue through the family compiler helper.');

$mrbcOnlyHoldingPayload = null;
$mrbcOnlyHoldingResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'SC Rare Book Collection Reference',
            'requested_outputs' => ['title'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_library_location_listing',
    ],
    'Please provide a list of titles, instance ids and call numbers with the location MRBC Reference Collection containing only records for which the MRBC Reference Collection is the only holding location in the 5 Colleges.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'listing-mrbc-only-holding-recovery-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload) use (&$mrbcOnlyHoldingPayload): array {
        $mrbcOnlyHoldingPayload = $normalizedPayload;
        return [
            'sql' => 'SELECT listing_mrbc_only_holding_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('SC Rare Book Collection Reference', $mrbcOnlyHoldingPayload['slots']['location'] ?? null, 'Inventory listing recovery should extract an explicit MRBC location from natural-language-only holding prompts.');
assertSameValue(false, array_key_exists('library', $mrbcOnlyHoldingPayload['slots'] ?? []), 'Inventory listing recovery should not retain a model-invented library filter when the prompt only names an MRBC location.');
assertSameValue(false, array_key_exists('campus', $mrbcOnlyHoldingPayload['slots'] ?? []), 'Inventory listing recovery should not retain default home-campus scope when the prompt asks for only-holding location in the Five Colleges.');
assertSameValue(true, in_array('call_number', $mrbcOnlyHoldingPayload['slots']['requested_outputs'] ?? [], true), 'Inventory listing recovery should include holdings call number output when the prompt asks for call numbers.');
assertSameValue(true, $mrbcOnlyHoldingPayload['slots']['only_holding_location'] ?? false, 'Only-holding inventory prompts should be set from prompt-only signals even when the model omits that slot.');
assertSameValue('legacy_fallback', $mrbcOnlyHoldingResult['route'] ?? null, 'Recovered MRBC-only-holding listing prompts should continue through the family helper path.');

$mrbcBadLocationPayload = null;
$mrbcBadLocationResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'MRBC',
            'location' => 'HC Reference',
            'requested_outputs' => ['title'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_library_location_listing',
    ],
    'Please provide a list of titles with the location MRBC Reference Collection containing only records for which the MRBC Reference Collection is the only holding location in the 5 Colleges.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'listing-mrbc-bad-location-recovery-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload) use (&$mrbcBadLocationPayload): array {
        $mrbcBadLocationPayload = $normalizedPayload;
        return [
            'sql' => 'SELECT listing_mrbc_bad_location_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('SC Rare Book Collection Reference', $mrbcBadLocationPayload['slots']['location'] ?? null, 'Inventory listing recovery should override model-invented HC Reference when prompt says MRBC Reference Collection.');
assertSameValue(false, array_key_exists('library', $mrbcBadLocationPayload['slots'] ?? []), 'Inventory listing recovery should remove model-invented MRBC library filters for MRBC Reference Collection prompts.');
assertSameValue(false, array_key_exists('campus', $mrbcBadLocationPayload['slots'] ?? []), 'Inventory listing recovery should remove default home-campus scope for MRBC only-holding prompts across the Five Colleges.');
assertSameValue(true, $mrbcBadLocationPayload['slots']['only_holding_location'] ?? false, 'Inventory listing recovery should preserve only-holding intent for MRBC Reference Collection prompts.');
assertSameValue('legacy_fallback', $mrbcBadLocationResult['route'] ?? null, 'Recovered bad-location MRBC listing prompts should continue through the family helper path.');

$onlineStatusPayload = null;
$onlineStatusResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Smith College',
            'requested_outputs' => ['title', 'barcode', 'instance_number'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_library_location_listing',
    ],
    'List of items with material type "e-book" and item status of "in process". Include title, barcode and instance number at Smith College',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'listing-online-status-library-recovery-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload) use (&$onlineStatusPayload): array {
        $onlineStatusPayload = $normalizedPayload;
        return [
            'sql' => 'SELECT online_status_listing_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue(false, array_key_exists('library', $onlineStatusPayload['slots'] ?? []), 'Recovered online material/status listing prompts should remove fake campus-as-library slots.');
assertSameValue('e-book', $onlineStatusPayload['slots']['material_type'] ?? null, 'Recovered online material/status listing prompts should preserve the prompt material-type filter.');
assertSameValue('in process', $onlineStatusPayload['slots']['item_status'] ?? null, 'Recovered online material/status listing prompts should preserve the prompt item-status filter.');
assertSameValue('legacy_fallback', $onlineStatusResult['route'] ?? null, 'Recovered online material/status listing prompts should continue through the family compiler with item filters.');

$materialTypeClarificationResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'circulation_top_items',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Josten Library',
            'requested_outputs' => ['ranked_circulation_items'],
        ],
    ],
    [
        'familyKey' => 'circulation_top_items',
    ],
    'Show me the top 10 circulating items at Josten Library.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'top-items-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (): array {
        return [
            'sql' => 'SELECT should_not_run',
        ];
    }
);

assertSameValue(true, $materialTypeClarificationResult['needsClarification'] ?? null, 'Missing required top-items material types should return a clarification response.');
assertSameValue('Which material type should I use for this report?', $materialTypeClarificationResult['question'] ?? null, 'Missing material_type slots should map to a deterministic clarification question.');

$collectionAgePayloadSeen = null;
$collectionAgeResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'campus' => 'Smith College',
            'requested_outputs' => ['average_age_years'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'What is the average age of the reference collection in Neilson Library?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'collection-age-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason) use (&$collectionAgePayloadSeen): array {
        $collectionAgePayloadSeen = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
        ];

        return [
            'sql' => 'SELECT normalized_collection_age_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $collectionAgeResult['route'] ?? null, 'Collection-age family requests should preserve the family compiler helper result when compilation is stubbed.');
assertSameValue('Neilson Library', $collectionAgePayloadSeen['normalizedPayload']['slots']['library'] ?? null, 'Collection-age prompts should recover an explicit library scope from the prompt before validation.');
assertSameValue('Neilson Reference', $collectionAgePayloadSeen['normalizedPayload']['slots']['location'] ?? null, 'Collection-age prompts should recover the concrete Neilson Reference location scope from the prompt before validation.');
assertSameValue('family_contract_supported:inventory_collection_age', $collectionAgePayloadSeen['routeReason'] ?? null, 'Collection-age prompts should derive the checked-in collection-age route reason after recovery.');

$trendPayloadSeen = null;
$trendResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'circulation_trends_matrix',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'grouping_dimension' => 'primary call number class',
            'year_buckets' => ['2026', '2025', '2024', '2023'],
            'circulation_source_policy' => 'historical_checkouts',
            'requested_outputs' => ['yearly_circulation_matrix'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'circulation_trends_matrix',
    ],
    'Show circulation numbers for 2026, 2025, 2024, and 2023 by primary call number class in Neilson Library.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'trend-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason) use (&$trendPayloadSeen): array {
        $trendPayloadSeen = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
        ];

        return [
            'sql' => 'SELECT normalized_trend_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $trendResult['route'] ?? null, 'Trend family requests should preserve the family compiler helper result when compilation is stubbed.');
assertSameValue('primary_call_number_class', $trendPayloadSeen['normalizedPayload']['slots']['grouping_dimension'] ?? null, 'Trend family requests should normalize human-readable grouping_dimension labels before compilation.');
assertSameValue('current_loans_only', $trendPayloadSeen['normalizedPayload']['slots']['circulation_source_policy'] ?? null, 'Trend family requests without explicit historical semantics should normalize unsupported circulation_source_policy values back to current_loans_only.');
assertSameValue(['2026', '2025', '2024', '2023'], $trendPayloadSeen['normalizedPayload']['slots']['year_buckets'] ?? null, 'Trend family requests should preserve ordered year buckets through the family intent branch.');
assertSameValue('family_contract_supported:circulation_trends_matrix', $trendPayloadSeen['routeReason'] ?? null, 'Trend family requests should derive the checked-in trend-family route reason.');

$collectionAgeLibraryOnlyPayloadSeen = null;
$collectionAgeLibraryOnlyResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'campus' => 'Smith College',
            'requested_outputs' => ['average_age_years'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'What is the average age of items in Neilson Library?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'collection-age-library-only-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason) use (&$collectionAgeLibraryOnlyPayloadSeen): array {
        $collectionAgeLibraryOnlyPayloadSeen = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
        ];

        return [
            'sql' => 'SELECT normalized_collection_age_library_only_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $collectionAgeLibraryOnlyResult['route'] ?? null, 'Library-only collection-age requests should preserve the family compiler helper result when compilation is stubbed.');
assertSameValue('Neilson Library', $collectionAgeLibraryOnlyPayloadSeen['normalizedPayload']['slots']['library'] ?? null, 'Library-only collection-age prompts should recover the explicit library scope from the prompt before validation.');
assertSameValue(false, array_key_exists('location', $collectionAgeLibraryOnlyPayloadSeen['normalizedPayload']['slots'] ?? []), 'Library-only collection-age prompts should not invent a location slot when the prompt asks only for Neilson Library.');
assertSameValue('family_contract_supported:inventory_collection_age', $collectionAgeLibraryOnlyPayloadSeen['routeReason'] ?? null, 'Library-only collection-age prompts should remain on the supported collection-age route after recovery.');

$collectionAgeLibraryCollectionPayloadSeen = null;
$collectionAgeLibraryCollectionResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'campus' => 'Smith College',
            'requested_outputs' => ['average_age_years'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'What is the average age of the Neilson Library collection?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'collection-age-library-collection-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason) use (&$collectionAgeLibraryCollectionPayloadSeen): array {
        $collectionAgeLibraryCollectionPayloadSeen = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
        ];

        return [
            'sql' => 'SELECT normalized_collection_age_library_collection_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $collectionAgeLibraryCollectionResult['route'] ?? null, 'Library collection-age requests should preserve the family compiler helper result when compilation is stubbed.');
assertSameValue('Neilson Library', $collectionAgeLibraryCollectionPayloadSeen['normalizedPayload']['slots']['library'] ?? null, 'Library collection-age prompts should recover the broad library scope from collection wording.');
assertSameValue(false, array_key_exists('location', $collectionAgeLibraryCollectionPayloadSeen['normalizedPayload']['slots'] ?? []), 'Library collection-age prompts should not treat the library name itself as a location.');
assertSameValue('family_contract_supported:inventory_collection_age', $collectionAgeLibraryCollectionPayloadSeen['routeReason'] ?? null, 'Library collection-age prompts should remain on the supported collection-age route after recovery.');

$collectionAgeLibraryOnlyMalformedScopePayloadSeen = null;
$collectionAgeLibraryOnlyMalformedScopeResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'location' => 'Neilson Reference',
            'requested_outputs' => ['average_age_years'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'What is the average age of items in Neilson Library?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'collection-age-library-only-malformed-scope-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason) use (&$collectionAgeLibraryOnlyMalformedScopePayloadSeen): array {
        $collectionAgeLibraryOnlyMalformedScopePayloadSeen = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
        ];

        return [
            'sql' => 'SELECT normalized_collection_age_library_only_malformed_scope_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $collectionAgeLibraryOnlyMalformedScopeResult['route'] ?? null, 'Library-only collection-age requests should preserve the family compiler helper result when malformed scope slots are stubbed.');
assertSameValue('Neilson Library', $collectionAgeLibraryOnlyMalformedScopePayloadSeen['normalizedPayload']['slots']['library'] ?? null, 'Library-only malformed-scope prompts should preserve the explicit library scope from the prompt before validation.');
assertSameValue(false, array_key_exists('location', $collectionAgeLibraryOnlyMalformedScopePayloadSeen['normalizedPayload']['slots'] ?? []), 'Library-only collection-age prompts should clear prefilled location slots when the prompt never requested a location.');
assertSameValue('family_contract_supported:inventory_collection_age', $collectionAgeLibraryOnlyMalformedScopePayloadSeen['routeReason'] ?? null, 'Library-only malformed-scope prompts should remain on the supported collection-age route after repair.');

$collectionAgeNamedCollectionPayloadSeen = null;
$collectionAgeNamedCollectionResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'campus' => 'Smith College',
            'requested_outputs' => ['average_age_years'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'What is the average age of the Neilson Library Burack collection?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'collection-age-named-collection-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason) use (&$collectionAgeNamedCollectionPayloadSeen): array {
        $collectionAgeNamedCollectionPayloadSeen = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
        ];

        return [
            'sql' => 'SELECT normalized_collection_age_named_collection_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $collectionAgeNamedCollectionResult['route'] ?? null, 'Named collection-age requests should preserve the family compiler helper result when compilation is stubbed.');
assertSameValue('Neilson Library', $collectionAgeNamedCollectionPayloadSeen['normalizedPayload']['slots']['library'] ?? null, 'Named collection-age prompts should recover the explicit library scope from the prompt before validation.');
assertSameValue('Burack', $collectionAgeNamedCollectionPayloadSeen['normalizedPayload']['slots']['location'] ?? null, 'Named collection-age prompts should recover the explicit Burack collection scope instead of collapsing back to a library-only query.');
assertSameValue('family_contract_supported:inventory_collection_age', $collectionAgeNamedCollectionPayloadSeen['routeReason'] ?? null, 'Named collection-age prompts should remain on the supported collection-age route after prompt recovery.');

$collectionAgeHillyerLocationPayloadSeen = null;
$collectionAgeHillyerLocationResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Hillyer',
            'requested_outputs' => ['average_age_years'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'What is the average age of the Hillyer locked stacks collection?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'collection-age-hillyer-locked-stacks-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason) use (&$collectionAgeHillyerLocationPayloadSeen): array {
        $collectionAgeHillyerLocationPayloadSeen = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
        ];

        return [
            'sql' => 'SELECT normalized_collection_age_hillyer_locked_stack_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $collectionAgeHillyerLocationResult['route'] ?? null, 'Hillyer locked-stacks collection-age prompts should preserve the family compiler helper result when compilation is stubbed.');
assertSameValue('Hillyer', $collectionAgeHillyerLocationPayloadSeen['normalizedPayload']['slots']['library'] ?? null, 'Hillyer locked-stacks prompts should preserve the model-provided library scope.');
assertSameValue('Locked Stack', $collectionAgeHillyerLocationPayloadSeen['normalizedPayload']['slots']['location'] ?? null, 'Hillyer locked-stacks prompts should recover the explicit locked-stack location scope instead of compiling as Hillyer-wide totals.');
assertSameValue('family_contract_supported:inventory_collection_age', $collectionAgeHillyerLocationPayloadSeen['routeReason'] ?? null, 'Hillyer locked-stacks prompts should remain on the supported collection-age route after prompt recovery.');

$collectionAgeZineLocationPayloadSeen = null;
$collectionAgeZineLocationResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'zine collection at Hillyer library',
            'requested_outputs' => ['average_age_years', 'item_count'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'How many items are in the zine collection at Hillyer library and what is their average age?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'collection-age-hillyer-zine-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason) use (&$collectionAgeZineLocationPayloadSeen): array {
        $collectionAgeZineLocationPayloadSeen = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
        ];

        return [
            'sql' => 'SELECT normalized_collection_age_hillyer_zine_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $collectionAgeZineLocationResult['route'] ?? null, 'Hillyer zine collection-age prompts should preserve the family compiler helper result when compilation is stubbed.');
assertSameValue('Hillyer', $collectionAgeZineLocationPayloadSeen['normalizedPayload']['slots']['library'] ?? null, 'Hillyer zine prompts should repair a model-collapsed library phrase back to a broad Hillyer library token that matches SC Hillyer Art Library.');
assertSameValue('Zine Collection', $collectionAgeZineLocationPayloadSeen['normalizedPayload']['slots']['location'] ?? null, 'Hillyer zine prompts should recover the named zine collection as a location scope instead of a library predicate.');
assertSameValue(['average_age_years', 'item_count'], $collectionAgeZineLocationPayloadSeen['normalizedPayload']['slots']['requested_outputs'] ?? null, 'Hillyer zine prompts should preserve the requested count plus average-age outputs.');
assertSameValue('family_contract_supported:inventory_collection_age', $collectionAgeZineLocationPayloadSeen['routeReason'] ?? null, 'Hillyer zine prompts should remain on the supported collection-age route after prompt recovery.');

$collectionAgeMalformedSlotPayloadSeen = null;
$collectionAgeMalformedSlotResult = $familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Neilson Reference',
            'location' => 'Reference',
            'requested_outputs' => ['average_age_years'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'What is the average age of items in the Neilson Reference collection?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'collection-age-malformed-slot-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload, string $routeReason) use (&$collectionAgeMalformedSlotPayloadSeen): array {
        $collectionAgeMalformedSlotPayloadSeen = [
            'normalizedPayload' => $normalizedPayload,
            'routeReason' => $routeReason,
        ];

        return [
            'sql' => 'SELECT normalized_collection_age_malformed_slot_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue('legacy_fallback', $collectionAgeMalformedSlotResult['route'] ?? null, 'Collection-age malformed-slot requests should preserve the family compiler helper result when compilation is stubbed.');
assertSameValue('Neilson Library', $collectionAgeMalformedSlotPayloadSeen['normalizedPayload']['slots']['library'] ?? null, 'Collection-age malformed-slot prompts should canonicalize the combined Neilson Reference scope back to the library slot before validation.');
assertSameValue('Neilson Reference', $collectionAgeMalformedSlotPayloadSeen['normalizedPayload']['slots']['location'] ?? null, 'Collection-age malformed-slot prompts should canonicalize the concrete Neilson Reference location scope before validation.');
assertSameValue('family_contract_supported:inventory_collection_age', $collectionAgeMalformedSlotPayloadSeen['routeReason'] ?? null, 'Collection-age malformed-slot prompts should remain on the supported collection-age route after prompt-scoped repair.');

fwrite(STDOUT, "GeminiService family intent branch test passed\n");
