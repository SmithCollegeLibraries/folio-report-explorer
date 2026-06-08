<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$graphBuilderPath = __DIR__ . '/../services/CanonicalQueryGraphArtifactBuilder.php';
$graphServicePath = __DIR__ . '/../services/CanonicalQueryGraphService.php';
$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$manifestServicePath = __DIR__ . '/../services/QueryFamilySchemaManifestService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$intentServicePath = __DIR__ . '/../services/QueryIntentService.php';
$compilerServicePath = __DIR__ . '/../services/QueryFamilyCompilerService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
    'FolioSchemaService' => $schemaServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
    'CanonicalQueryGraphArtifactBuilder' => $graphBuilderPath,
    'CanonicalQueryGraphService' => $graphServicePath,
    'QueryFamilyContractService' => $contractServicePath,
    'QueryFamilySchemaManifestService' => $manifestServicePath,
    'QueryFamilySlotService' => $slotServicePath,
    'QueryIntentService' => $intentServicePath,
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
            if ($alias === '@app/data/query_family_schema_manifests.json') {
                return __DIR__ . '/../data/query_family_schema_manifests.json';
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
        'nl2sqlForceLegacy' => false,
    ],
];

require_once $schemaServicePath;
require_once $sqlBuilderPath;
require_once $graphBuilderPath;
require_once $graphServicePath;
require_once $contractServicePath;
require_once $manifestServicePath;
require_once $slotServicePath;
require_once $intentServicePath;
require_once $compilerServicePath;
require_once $geminiServicePath;

use app\services\GeminiService;
use app\services\QueryFamilySlotService;

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\nSQL: {$haystack}\n");
        exit(1);
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$builder = new ReflectionMethod(GeminiService::class, 'buildQueryFamilyIntentResponse');
$result = $builder->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'MRBC',
            'location' => 'HC Reference',
            'requested_outputs' => ['title'],
            'match_policy' => QueryFamilySlotService::DEFAULT_MATCH_POLICY,
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
        'promptFingerprint' => 'inventory-listing-compiler-regression',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ]
);

assertSameValue('builder_intent', $result['route'] ?? null, 'MRBC only-holding listing prompts should compile deterministically instead of surfacing the covered-family fallback guard.');
assertContainsText("tl.name ILIKE '%SC Rare Book Collection Reference%'", $result['sql'] ?? '', 'Compiled MRBC only-holding SQL should scope target_locations to the resolved MRBC reference location.');
assertContainsText('NOT EXISTS', $result['sql'] ?? '', 'Compiled MRBC only-holding SQL should exclude instances with holdings at non-target locations.');

fwrite(STDOUT, "GeminiService inventory listing compiler regression test passed\n");
