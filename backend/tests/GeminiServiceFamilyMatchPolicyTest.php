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

$capturedExactPayload = null;
$familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_contributor_campus_item_barcode',
        'slots' => [
            'campus' => 'Smith College',
            'contributor_name' => 'Smith College. Department of Biological Sciences.',
            'contributor_name_type' => 'Corporate name',
            'requested_outputs' => ['barcode', 'title'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_contributor_campus_item_barcode',
    ],
    'Show barcodes for Smith College items with the corporate-body contributor named Smith College. Department of Biological Sciences.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'match-policy-exact-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload) use (&$capturedExactPayload): array {
        $capturedExactPayload = $normalizedPayload;

        return [
            'sql' => 'SELECT legacy_fallback_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue(
    'exact_phrase',
    $capturedExactPayload['slots']['match_policy'] ?? null,
    'Named contributor prompts should normalize the family match policy to exact_phrase before compilation.'
);

$capturedFuzzyPayload = null;
$familyBranch->invoke(
    null,
    [
        'familyKey' => 'inventory_contributor_campus_item_barcode',
        'slots' => [
            'campus' => 'Smith College',
            'contributor_name' => 'Biological Sciences',
            'contributor_name_type' => 'Corporate name',
            'requested_outputs' => ['barcode', 'title'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_contributor_campus_item_barcode',
    ],
    'Show barcodes for Smith College items with contributors related to Biological Sciences.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'match-policy-fuzzy-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $normalizedPayload) use (&$capturedFuzzyPayload): array {
        $capturedFuzzyPayload = $normalizedPayload;

        return [
            'sql' => 'SELECT legacy_fallback_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue(
    'case_insensitive_contains',
    $capturedFuzzyPayload['slots']['match_policy'] ?? null,
    'Fuzzy contributor prompts should preserve contains matching semantics before compilation.'
);

fwrite(STDOUT, "GeminiService family match policy test passed\n");