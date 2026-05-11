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
use app\services\QueryFamilySlotService;

function failTest(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function assertThrowsInvalidArgumentException(ReflectionMethod $validator, array $payload, array $queryDef, string $sql, string $expectedPrefix, string $message): void
{
    try {
        $validator->invoke(null, $payload, $queryDef, $sql);
    } catch (InvalidArgumentException $e) {
        if (strpos($e->getMessage(), $expectedPrefix) !== 0) {
            failTest($message . "\nExpected prefix: {$expectedPrefix}\nActual: " . $e->getMessage());
        }
        return;
    }

    failTest($message . "\nExpected InvalidArgumentException with prefix {$expectedPrefix}, but no exception was thrown.");
}

$validator = new ReflectionMethod(GeminiService::class, 'validateCompiledQueryFamilyShape');

$listingPayload = [
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'location_code' => 'SJTR',
        'requested_outputs' => ['title', 'author', 'barcode', 'instance_number'],
        'match_policy' => QueryFamilySlotService::DEFAULT_MATCH_POLICY,
    ],
];

$listingQueryDef = [
    'tables' => [
        'inventory_instances',
        'inventory_holdings',
        'inventory_items',
        'inventory_locations',
        'inventory_libraries',
        'inventory_campuses',
    ],
    'joins' => [
        ['from_table' => 'inventory_instances', 'to_table' => 'inventory_holdings'],
        ['from_table' => 'inventory_holdings', 'to_table' => 'inventory_items'],
        ['from_table' => 'inventory_items', 'to_table' => 'inventory_locations'],
        ['from_table' => 'inventory_locations', 'to_table' => 'inventory_libraries'],
        ['from_table' => 'inventory_libraries', 'to_table' => 'inventory_campuses'],
    ],
    'filters' => [
        ['table' => 'inventory_campuses', 'column' => 'name', 'value' => 'Smith College'],
        ['table' => 'inventory_libraries', 'column' => 'name', 'value' => 'Neilson Library'],
        ['table' => 'inventory_locations', 'column' => 'code', 'value' => 'SJTR'],
    ],
];

$listingSql = implode("\n", [
    'SELECT ii.title, it.barcode',
    'FROM inventory.instance__t ii',
    'JOIN inventory.holdings_record__t ihr ON ihr.instance_id = ii.id',
    'JOIN inventory.item__t it ON it.holdings_record_id = ihr.id',
    'JOIN inventory.location__t ilo ON ilo.id = it.effective_location_id',
    'JOIN inventory.loclibrary__t il ON il.id = ilo.library_id',
    'JOIN inventory.loccampus__t ic ON ic.id = il.campus_id',
    "WHERE ic.name ILIKE '%Smith College%' AND il.name ILIKE '%Neilson Library%' AND ilo.code = 'SJTR'",
]);

assertThrowsInvalidArgumentException(
    $validator,
    $listingPayload,
    $listingQueryDef,
    $listingSql,
    'missing_listing_contributor_anchor:',
    'Listing-family author outputs should require the contributor join anchor during compiled-shape validation.'
);

$topItemsPayload = [
    'familyKey' => 'circulation_top_items',
    'slots' => [
        'campus' => 'Smith College',
        'limit' => '10',
        'requested_outputs' => ['ranked_circulation_items'],
        'match_policy' => QueryFamilySlotService::DEFAULT_MATCH_POLICY,
    ],
];

$topItemsQueryDef = [
    'tables' => [
        'inventory_instances',
        'inventory_holdings',
        'inventory_items',
        'inventory_locations',
        'inventory_libraries',
        'inventory_campuses',
    ],
    'joins' => [
        ['from_table' => 'inventory_instances', 'to_table' => 'inventory_holdings'],
        ['from_table' => 'inventory_holdings', 'to_table' => 'inventory_items'],
        ['from_table' => 'inventory_items', 'to_table' => 'inventory_locations'],
        ['from_table' => 'inventory_locations', 'to_table' => 'inventory_libraries'],
        ['from_table' => 'inventory_libraries', 'to_table' => 'inventory_campuses'],
    ],
    'filters' => [
        ['table' => 'inventory_campuses', 'column' => 'name', 'value' => 'Smith College'],
    ],
];

$topItemsSql = implode("\n", [
    'SELECT ii.title, COUNT(*) AS total_circulation',
    'FROM inventory.item__t it',
    'JOIN inventory.holdings_record__t ihr ON ihr.id = it.holdings_record_id',
    'JOIN inventory.instance__t ii ON ii.id = ihr.instance_id',
    'JOIN inventory.location__t ilo ON ilo.id = it.effective_location_id',
    'JOIN inventory.loclibrary__t il ON il.id = ilo.library_id',
    'JOIN inventory.loccampus__t ic ON ic.id = il.campus_id',
    "WHERE ic.name ILIKE '%Smith College%'",
    'GROUP BY ii.title',
    'ORDER BY total_circulation DESC',
    'LIMIT 10',
]);

assertThrowsInvalidArgumentException(
    $validator,
    $topItemsPayload,
    $topItemsQueryDef,
    $topItemsSql,
    'missing_top_items_circulation_anchor:',
    'Top-items compiled-shape validation should reject results that omit the audit-loan and former-circulation ranking anchors.'
);

fwrite(STDOUT, "GeminiService family shape validation test passed\n");