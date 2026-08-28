<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$graphBuilderPath = __DIR__ . '/../services/CanonicalQueryGraphArtifactBuilder.php';
$graphServicePath = __DIR__ . '/../services/CanonicalQueryGraphService.php';
$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$manifestServicePath = __DIR__ . '/../services/QueryFamilySchemaManifestService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
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
    ],
];

require_once $schemaServicePath;
require_once $sqlBuilderPath;
require_once $graphBuilderPath;
require_once $graphServicePath;
require_once $contractServicePath;
require_once $manifestServicePath;
require_once $slotServicePath;
require_once $compilerServicePath;
require_once $geminiServicePath;

use app\services\FolioSchemaService;
use app\services\GeminiService;
use app\services\QueryFamilySlotService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\nSQL/Text: {$haystack}\n");
        exit(1);
    }
}

$mapProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredMap');
$columnProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredColumns');
$subtableProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredSubtables');

$mapProperty->setValue(null, [
    'inventory_instances' => 'inventory.instance__t',
    'inventory_instance__t__publication' => 'inventory.instance__t__publication',
    'inventory_holdings' => 'inventory.holdings_record__t',
    'inventory_items' => 'inventory.item__t',
    'inventory_locations' => 'inventory.location__t',
    'inventory_libraries' => 'inventory.loclibrary__t',
    'inventory_campuses' => 'inventory.loccampus__t',
    'inventory_contributor_name_types' => 'inventory.contributor_name_type__t',
    'inventory_material_types' => 'inventory.material_type__t',
]);
$columnCache = json_decode((string)file_get_contents(__DIR__ . '/../data/column_cache.json'), true);
$columnProperty->setValue(null, is_array($columnCache['columns'] ?? null) ? $columnCache['columns'] : []);
$subtableProperty->setValue(null, [
    'inventory.instance__t__contributors' => [
        'parent' => 'inventory.instance__t',
        'columns' => [
            ['name' => 'id'],
            ['name' => 'contributors__name'],
            ['name' => 'contributors__contributor_name_type_id'],
        ],
    ],
    'inventory.instance__t__publication' => [
        'parent' => 'inventory.instance__t',
        'columns' => [
            ['name' => 'id'],
            ['name' => 'publication__date_of_publication'],
            ['name' => 'cataloged_date'],
        ],
    ],
]);

$validation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'contributor_name' => 'Smith College. Department of Biological Sciences.',
        'contributor_name_type' => 'Corporate name',
        'requested_outputs' => ['barcode', 'title'],
        'match_policy' => 'exact_phrase',
    ],
]);

if (empty($validation['valid'])) {
    fwrite(STDERR, "Test setup produced an invalid family payload.\n");
    exit(1);
}

$builder = new ReflectionMethod(GeminiService::class, 'buildCompiledQueryFamilyResult');
$result = $builder->invoke(
    null,
    $validation['normalizedPayload'],
    'family_contract_supported:inventory_contributor_campus_item_barcode'
);

assertSameValue(
    'builder_intent',
    $result['route'] ?? null,
    'Compiled covered-family responses should stay on the builder_intent route.'
);
assertSameValue(
    'family_contract_supported:inventory_contributor_campus_item_barcode',
    $result['routeReason'] ?? null,
    'Compiled covered-family responses should preserve the checked-in family route reason.'
);
assertSameValue(
    'folio',
    $result['dataSource'] ?? null,
    'Covered-family compiler results should remain on the FOLIO data source.'
);
assertContainsText(
    'Generated from structured family compiler mode.',
    $result['explanation'] ?? '',
    'Compiled covered-family responses should report the family-compiler explanation path.'
);
assertContainsText(
    'inventory_holdings',
    $result['explanation'] ?? '',
    'Compiled covered-family explanations should surface the canonical covered tables.'
);

$sql = $result['sql'] ?? '';
assertContainsText(
    'JOIN inventory.holdings_record__t',
    $sql,
    'Compiled covered-family SQL should join holdings explicitly before item outputs.'
);
assertContainsText(
    'JOIN inventory.item__t',
    $sql,
    'Compiled covered-family SQL should include the explicit holdings-to-items join.'
);

$ageValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'location' => 'Reference',
        'requested_outputs' => ['average_age_years'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

if (empty($ageValidation['valid'])) {
    fwrite(STDERR, "Collection-age test setup produced an invalid family payload.\n");
    exit(1);
}

$ageResult = $builder->invoke(
    null,
    $ageValidation['normalizedPayload'],
    'family_contract_supported:inventory_collection_age'
);

assertSameValue(
    'builder_intent',
    $ageResult['route'] ?? null,
    'Compiled collection-age family responses should stay on the builder_intent route.'
);
assertSameValue(
    'family_contract_supported:inventory_collection_age',
    $ageResult['routeReason'] ?? null,
    'Compiled collection-age family responses should preserve the checked-in family route reason.'
);
assertContainsText(
    'inventory_instance__t__publication',
    $ageResult['explanation'] ?? '',
    'Compiled collection-age explanations should surface the publication subtable anchor.'
);

$ageSql = $ageResult['sql'] ?? '';
assertContainsText(
    'SUM(scoped_instances.item_count * (EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(iip.publication__date_of_publication FROM 1 FOR 4) AS INTEGER))) / NULLIF(SUM(scoped_instances.item_count), 0) AS average_age_years',
    $ageSql,
    'Compiled collection-age SQL should compute weighted average age from the instance publication year after collapsing scoped items by instance.'
);
assertContainsText(
    "il.name ILIKE '%Neilson Library%'",
    $ageSql,
    'Compiled collection-age SQL should inline the requested library scope.'
);
assertContainsText(
    "ilo.name ILIKE '%Reference%'",
    $ageSql,
    'Compiled collection-age SQL should inline the requested location scope.'
);
assertContainsText(
    "iip.publication__date_of_publication ~ '^\\d{4}'",
    $ageSql,
    'Compiled collection-age SQL should preserve the four-digit publication-year validation guard.'
);

$lcClassAgeValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'material_type' => 'Book',
        'grouping_dimension' => 'primary_call_number_class',
        'requested_outputs' => [
            'title_count',
            'average_publication_year',
            'oldest_publication_year',
            'newest_publication_year',
        ],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

if (empty($lcClassAgeValidation['valid'])) {
    fwrite(STDERR, "LC-class collection-age test setup produced an invalid family payload.\n");
    exit(1);
}

$lcClassAgeResult = $builder->invoke(
    null,
    $lcClassAgeValidation['normalizedPayload'],
    'family_contract_supported:inventory_collection_age'
);

assertSameValue(
    'builder_intent',
    $lcClassAgeResult['route'] ?? null,
    'The Neilson Book collection-age report should remain on the verified compiler route.'
);
assertContainsText(
    'JOIN inventory.instance__t__publication iip ON iip.id = iin.id',
    $lcClassAgeResult['sql'] ?? '',
    'Publication-year summaries should accept the compiler\'s inner publication join because rows without a valid year are intentionally excluded.'
);

$trendValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'circulation_trends_matrix',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'grouping_dimension' => 'primary_call_number_class',
        'year_buckets' => ['2026', '2025', '2024', '2023'],
        'circulation_source_policy' => 'current_loans_only',
        'requested_outputs' => ['yearly_circulation_matrix'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

if (empty($trendValidation['valid'])) {
    fwrite(STDERR, "Trend-matrix test setup produced an invalid family payload.\n");
    exit(1);
}

$trendResult = $builder->invoke(
    null,
    $trendValidation['normalizedPayload'],
    'family_contract_supported:circulation_trends_matrix'
);

assertSameValue(
    'builder_intent',
    $trendResult['route'] ?? null,
    'Compiled trend-matrix family responses should stay on the builder_intent route.'
);
assertSameValue(
    'family_contract_supported:circulation_trends_matrix',
    $trendResult['routeReason'] ?? null,
    'Compiled trend-matrix family responses should preserve the checked-in family route reason.'
);
assertContainsText(
    'circulation_loans',
    $trendResult['explanation'] ?? '',
    'Compiled trend-matrix explanations should surface the circulation loan anchor table.'
);

$trendSql = $trendResult['sql'] ?? '';
assertContainsText(
    'FROM circulation.loan__t cl',
    $trendSql,
    'Compiled trend-matrix SQL should anchor on circulation.loan__t.'
);
assertContainsText(
    'SUM(CASE WHEN EXTRACT(YEAR FROM cl.loan_date) = 2026 THEN 1 ELSE 0 END) AS circulation_2026',
    $trendSql,
    'Compiled trend-matrix SQL should include the first requested year bucket as a conditional aggregate column.'
);
assertContainsText(
    'AS call_number_class',
    $trendSql,
    'Compiled trend-matrix SQL should emit a derived call_number_class column for the yearly matrix rows.'
);
assertContainsText(
    "cl.action IN ('checkedout', 'checkedOutThroughOverride')",
    $trendSql,
    'Compiled trend-matrix SQL should preserve the first supported circulation source policy.'
);

fwrite(STDOUT, "GeminiService family compiler result test passed\n");
