<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$graphBuilderPath = __DIR__ . '/../services/CanonicalQueryGraphArtifactBuilder.php';
$graphServicePath = __DIR__ . '/../services/CanonicalQueryGraphService.php';
$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$compilerServicePath = __DIR__ . '/../services/QueryFamilyCompilerService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
    'FolioSchemaService' => $schemaServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
    'CanonicalQueryGraphArtifactBuilder' => $graphBuilderPath,
    'CanonicalQueryGraphService' => $graphServicePath,
    'QueryFamilyContractService' => $contractServicePath,
    'QueryFamilySlotService' => $slotServicePath,
    'QueryFamilyCompilerService' => $compilerServicePath,
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
assertSameValue('Reference collection', $collectionAgePayloadSeen['normalizedPayload']['slots']['location'] ?? null, 'Collection-age prompts should recover the reference-collection location scope from the prompt before validation.');
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

fwrite(STDOUT, "GeminiService family intent branch test passed\n");