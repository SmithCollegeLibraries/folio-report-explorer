<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$graphBuilderPath = __DIR__ . '/../services/CanonicalQueryGraphArtifactBuilder.php';
$graphServicePath = __DIR__ . '/../services/CanonicalQueryGraphService.php';
$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$manifestServicePath = __DIR__ . '/../services/QueryFamilySchemaManifestService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$compilerServicePath = __DIR__ . '/../services/QueryFamilyCompilerService.php';

foreach ([
    'FolioSchemaService' => $schemaServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
    'CanonicalQueryGraphArtifactBuilder' => $graphBuilderPath,
    'CanonicalQueryGraphService' => $graphServicePath,
    'QueryFamilyContractService' => $contractServicePath,
    'QueryFamilySchemaManifestService' => $manifestServicePath,
    'QueryFamilySlotService' => $slotServicePath,
    'QueryFamilyCompilerService' => $compilerServicePath,
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

use app\services\FolioSchemaService;
use app\services\QueryFamilyCompilerService;

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
        fwrite(STDERR, $message . "\nMissing text: {$needle}\nSQL: {$haystack}\n");
        exit(1);
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected text: {$needle}\nSQL: {$haystack}\n");
        exit(1);
    }
}

$mapProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredMap');
$columnProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredColumns');
$subtableProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredSubtables');

$mapProperty->setValue(null, [
    'inventory_instances' => 'inventory.instance__t',
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
]);

$compiled = QueryFamilyCompilerService::compileToQueryDefinition([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'contributor_name' => 'Smith College. Department of Biological Sciences.',
        'contributor_name_type' => 'Corporate name',
        'material_type' => 'Theses',
        'requested_outputs' => ['barcode', 'instance_hrid', 'publication_date', 'title'],
        'match_policy' => 'exact_phrase',
    ],
]);

assertSameValue(
    [
        'inventory_instances',
        'inventory_instance__t__contributors',
        'inventory_contributor_name_types',
        'inventory_holdings',
        'inventory_items',
        'inventory_locations',
        'inventory_libraries',
        'inventory_campuses',
        'inventory_material_types',
    ],
    $compiled['tables'] ?? null,
    'The family compiler should preserve the canonical path order so explicit joins start from instances before the holdings and item branch.'
);

assertSameValue(
    [
        [
            'from_table' => 'inventory_instances',
            'from_column' => 'id',
            'to_table' => 'inventory_instance__t__contributors',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_instance__t__contributors',
            'from_column' => 'contributors__contributor_name_type_id',
            'to_table' => 'inventory_contributor_name_types',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_instances',
            'from_column' => 'id',
            'to_table' => 'inventory_holdings',
            'to_column' => 'instance_id',
        ],
        [
            'from_table' => 'inventory_holdings',
            'from_column' => 'id',
            'to_table' => 'inventory_items',
            'to_column' => 'holdings_record_id',
        ],
        [
            'from_table' => 'inventory_items',
            'from_column' => 'effective_location_id',
            'to_table' => 'inventory_locations',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_locations',
            'from_column' => 'library_id',
            'to_table' => 'inventory_libraries',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_libraries',
            'from_column' => 'campus_id',
            'to_table' => 'inventory_campuses',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_items',
            'from_column' => 'material_type_id',
            'to_table' => 'inventory_material_types',
            'to_column' => 'id',
        ],
    ],
    $compiled['joins'] ?? null,
    'The family compiler should emit one deterministic explicit join chain anchored on holdings before item outputs.'
);

assertSameValue(
    'ILIKE',
    $compiled['filters'][0]['op'] ?? null,
    'Exact-phrase family slots should compile to case-insensitive exact campus matching in the deterministic query definition.'
);

assertSameValue(
    'Smith College',
    $compiled['filters'][0]['value'] ?? null,
    'Canonical campus filters should avoid wildcard broadening.'
);

assertSameValue(
    'Smith College. Department of Biological Sciences.',
    $compiled['filters'][1]['value'] ?? null,
    'Exact contributor filters should preserve the raw contributor name without wildcard broadening.'
);

assertSameValue(
    'Corporate name',
    $compiled['filters'][2]['value'] ?? null,
    'Contributor name type filters should normalize to exact case-insensitive lookup matching.'
);

$locationListingCompiled = QueryFamilyCompilerService::compileToQueryDefinition([
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Josten Library',
        'location' => 'Treasure Case',
        'requested_outputs' => ['title', 'author', 'pub_date', 'barcode', 'instance_number'],
        'match_policy' => 'exact_phrase',
    ],
]);

assertSameValue(
    [
        'inventory_instances',
        'inventory_instance__t__contributors',
        'inventory_holdings',
        'inventory_items',
        'inventory_locations',
        'inventory_libraries',
        'inventory_campuses',
    ],
    $locationListingCompiled['tables'] ?? null,
    'Library/location inventory listings should preserve the deterministic instance-through-item scope path and add contributor joins only when author output is requested.'
);

assertSameValue(
    [
        [
            'from_table' => 'inventory_instances',
            'from_column' => 'id',
            'to_table' => 'inventory_instance__t__contributors',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_instances',
            'from_column' => 'id',
            'to_table' => 'inventory_holdings',
            'to_column' => 'instance_id',
        ],
        [
            'from_table' => 'inventory_holdings',
            'from_column' => 'id',
            'to_table' => 'inventory_items',
            'to_column' => 'holdings_record_id',
        ],
        [
            'from_table' => 'inventory_items',
            'from_column' => 'effective_location_id',
            'to_table' => 'inventory_locations',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_locations',
            'from_column' => 'library_id',
            'to_table' => 'inventory_libraries',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_libraries',
            'from_column' => 'campus_id',
            'to_table' => 'inventory_campuses',
            'to_column' => 'id',
        ],
    ],
    $locationListingCompiled['joins'] ?? null,
    'Library/location inventory listings should compile a deterministic contributor-plus-holdings join chain for author, title, publication date, barcode, and instance number outputs.'
);

assertSameValue(
    'Smith College',
    $locationListingCompiled['filters'][0]['value'] ?? null,
    'Library/location inventory listings should preserve exact campus matching without wildcard broadening.'
);

assertSameValue(
    '%Josten Library%',
    $locationListingCompiled['filters'][1]['value'] ?? null,
    'Library/location inventory listings should keep library matching on contains semantics because stored names carry campus prefixes.'
);

assertSameValue(
    '%Treasure Case%',
    $locationListingCompiled['filters'][2]['value'] ?? null,
    'Library/location inventory listings should normalize Treasure Case prompts onto a contains-based location filter.'
);

assertSameValue(
    '%thesis%',
    $compiled['filters'][3]['value'] ?? null,
    'Thesis-like material-type filters should preserve contains matching semantics for stored variants.'
);

$built = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'contributor_name' => 'Smith College. Department of Biological Sciences.',
        'contributor_name_type' => 'Corporate name',
        'requested_outputs' => ['barcode', 'title'],
        'match_policy' => 'exact_phrase',
    ],
]);

$sql = $built['sql'] ?? '';
assertContainsText(
    'FROM inventory.instance__t',
    $sql,
    'The compiled SQL should anchor the explicit join chain on inventory instances, not a sorted unrelated table.'
);
assertContainsText(
    'JOIN inventory.holdings_record__t',
    $sql,
    'The compiled SQL should join holdings explicitly before item outputs are selected.'
);
assertContainsText(
    'JOIN inventory.item__t',
    $sql,
    'The compiled SQL should include the explicit holdings-to-items join for barcode outputs.'
);
assertContainsText(
    'ic.name ILIKE :p0',
    $sql,
    'The compiled SQL should use case-insensitive exact campus matching for canonical campus names.'
);
assertContainsText(
    'itc.contributors__name ILIKE :p1',
    $sql,
    'The compiled SQL should use case-insensitive exact contributor matching for named-entity prompts.'
);

$instanceJoinPos = strpos($sql, 'JOIN inventory.instance__t__contributors');
$holdingsJoinPos = strpos($sql, 'JOIN inventory.holdings_record__t');
$itemsJoinPos = strpos($sql, 'JOIN inventory.item__t');
assertSameValue(
    true,
    $instanceJoinPos !== false && $holdingsJoinPos !== false && $itemsJoinPos !== false && $instanceJoinPos < $holdingsJoinPos && $holdingsJoinPos < $itemsJoinPos,
    'The compiled SQL should keep the contributor branch and the holdings-to-item branch in deterministic order.'
);

$itemIdBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'contributor_name' => 'Smith College. Department of Biological Sciences.',
        'contributor_name_type' => 'Corporate name',
        'material_type' => 'Theses',
        'requested_outputs' => ['item_id', 'title'],
        'match_policy' => 'exact_phrase',
    ],
]);

$itemIdSql = $itemIdBuilt['sql'] ?? '';
assertContainsText(
    'ii1.id AS item_id',
    $itemIdSql,
    'The compiled SQL should source item_id from the holdings-scoped item table alias.'
);
assertContainsText(
    'JOIN inventory.holdings_record__t',
    $itemIdSql,
    'The item_id variant should preserve the holdings join branch.'
);

$contributorOutputBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'contributor_name' => 'Smith College. Department of Biological Sciences.',
        'contributor_name_type' => 'Corporate name',
        'requested_outputs' => ['contributor_name', 'title'],
        'match_policy' => 'exact_phrase',
    ],
]);

$contributorOutputSql = $contributorOutputBuilt['sql'] ?? '';
assertContainsText(
    'itc.contributors__name AS contributor_name',
    $contributorOutputSql,
    'Contributor-name outputs should compile from the contributor subtable with a stable contributor_name alias.'
);

$compiledTopItems = QueryFamilyCompilerService::compileToQueryDefinition([
    'familyKey' => 'circulation_top_items',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Josten Library',
        'material_type' => 'Book',
        'limit' => '10',
        'requested_outputs' => ['ranked_circulation_items'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

assertSameValue(
    [
        'inventory_instances',
        'inventory_holdings',
        'inventory_items',
        'inventory_locations',
        'inventory_libraries',
        'inventory_campuses',
        'inventory_material_types',
    ],
    $compiledTopItems['tables'] ?? null,
    'The top-items compiler should preserve the deterministic inventory scope path plus material types before raw circulation SQL compilation.'
);

assertSameValue(
    [
        [
            'from_table' => 'inventory_instances',
            'from_column' => 'id',
            'to_table' => 'inventory_holdings',
            'to_column' => 'instance_id',
        ],
        [
            'from_table' => 'inventory_holdings',
            'from_column' => 'id',
            'to_table' => 'inventory_items',
            'to_column' => 'holdings_record_id',
        ],
        [
            'from_table' => 'inventory_items',
            'from_column' => 'effective_location_id',
            'to_table' => 'inventory_locations',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_locations',
            'from_column' => 'library_id',
            'to_table' => 'inventory_libraries',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_libraries',
            'from_column' => 'campus_id',
            'to_table' => 'inventory_campuses',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_items',
            'from_column' => 'material_type_id',
            'to_table' => 'inventory_material_types',
            'to_column' => 'id',
        ],
    ],
    $compiledTopItems['joins'] ?? null,
    'The top-items compiler should emit deterministic joins for inventory scope plus material-type filtering before ranking circulation results.'
);

assertSameValue(
    10,
    $compiledTopItems['limit'] ?? null,
    'Top-items query definitions should preserve the requested ranking limit.'
);

assertSameValue(
    '%Josten Library%',
    $compiledTopItems['filters'][1]['value'] ?? null,
    'Top-items query definitions should preserve contains matching for prefixed stored library names.'
);

assertSameValue(
    '%Book%',
    $compiledTopItems['filters'][2]['value'] ?? null,
    'Top-items query definitions should preserve material-type filtering on the requested book scope.'
);

$topItemsBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'circulation_top_items',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Josten Library',
        'material_type' => 'Book',
        'limit' => '10',
        'requested_outputs' => ['ranked_circulation_items'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

$topItemsSql = $topItemsBuilt['sql'] ?? '';
assertContainsText(
    'FROM circulation.audit_loan__t al',
    $topItemsSql,
    'The top-items compiler should count circulation from the audit loan log rather than the current-state loan table.'
);
assertContainsText(
    'FROM inventory.item__t__notes itn',
    $topItemsSql,
    'The top-items compiler should join migrated former-circulation notes for total-circulation ranking.'
);
assertContainsText(
    "itn.notes__item_note_type_id = 'f765f19f-9f1c-4688-8c79-ec366a730842'",
    $topItemsSql,
    'The top-items compiler should scope note extraction to the known former-circulation note type.'
);
assertContainsText(
    'COALESCE(cc.current_circulation, 0) + COALESCE(fc.former_circulation, 0) AS total_circulation',
    $topItemsSql,
    'The top-items compiler should rank items by combined current and former circulation totals.'
);
assertContainsText(
    'ORDER BY total_circulation DESC',
    $topItemsSql,
    'The top-items compiler should order results by total circulation descending.'
);
assertContainsText(
    'LIMIT 10',
    $topItemsSql,
    'The top-items compiler should preserve the explicit top-N limit in the compiled SQL.'
);

$compiledAge = QueryFamilyCompilerService::compileToQueryDefinition([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'location' => 'Neilson Reference',
        'requested_outputs' => ['average_age_years'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

assertSameValue(
    [
        'inventory_items',
        'inventory_holdings',
        'inventory_instances',
        'inventory_instance__t__publication',
        'inventory_locations',
        'inventory_libraries',
        'inventory_campuses',
    ],
    $compiledAge['tables'] ?? null,
    'The collection-age family compiler should preserve the canonical holdings-to-publication path order.'
);

assertSameValue(
    [
        [
            'from_table' => 'inventory_items',
            'from_column' => 'holdings_record_id',
            'to_table' => 'inventory_holdings',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_holdings',
            'from_column' => 'instance_id',
            'to_table' => 'inventory_instances',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_instances',
            'from_column' => 'id',
            'to_table' => 'inventory_instance__t__publication',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_items',
            'from_column' => 'effective_location_id',
            'to_table' => 'inventory_locations',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_locations',
            'from_column' => 'library_id',
            'to_table' => 'inventory_libraries',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_libraries',
            'from_column' => 'campus_id',
            'to_table' => 'inventory_campuses',
            'to_column' => 'id',
        ],
    ],
    $compiledAge['joins'] ?? null,
    'The collection-age family compiler should emit deterministic joins from items through holdings, instances, publication, and scope lookups.'
);

assertSameValue(
    '%Neilson Library%',
    $compiledAge['filters'][1]['value'] ?? null,
    'Collection-age library filters should keep contains matching because stored library names include campus prefixes.'
);
assertSameValue(
    '%Neilson Reference%',
    $compiledAge['filters'][2]['value'] ?? null,
    'Collection-age reference prompts should compile the concrete Neilson Reference location phrase instead of an abstract reference-collection label that does not exist in data.'
);

$ageBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'location' => 'Neilson Reference',
        'requested_outputs' => ['average_age_years'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

$ageSql = $ageBuilt['sql'] ?? '';
assertContainsText(
    'SUM(scoped_instances.item_count * (EXTRACT(YEAR FROM CURRENT_DATE) - CAST(SUBSTRING(iip.publication__date_of_publication FROM 1 FOR 4) AS INTEGER))) / NULLIF(SUM(scoped_instances.item_count), 0) AS average_age_years',
    $ageSql,
    'Collection-age SQL should compute the average age from the first four digits of the instance publication year while preserving per-item weighting through scoped instance counts.'
);
assertContainsText(
    'WITH scoped_instances AS (',
    $ageSql,
    'Collection-age SQL should scope and aggregate instance targets before joining publication rows so library-wide age requests do not scan publication data per item row.'
);
assertContainsText(
    'COUNT(*) AS item_count',
    $ageSql,
    'Collection-age SQL should count items per instance inside the scoped target CTE.'
);
assertContainsText(
    'GROUP BY ih.instance_id, iin.id',
    $ageSql,
    'Collection-age SQL should collapse scoped items by instance before computing the final weighted average age.'
);
assertContainsText(
    'JOIN inventory.holdings_record__t',
    $ageSql,
    'Collection-age SQL should join holdings before reaching instances and publication data.'
);
assertContainsText(
    'LEFT JOIN inventory.instance__t__publication',
    $ageSql,
    'Collection-age SQL should use the instance publication subtable for bibliographic age.'
);
assertContainsText(
    "iip.publication__date_of_publication ~ '^\\d{4}'",
    $ageSql,
    'Collection-age SQL should keep only publication dates that begin with a four-digit year.'
);
assertContainsText(
    'il.name ILIKE :p1',
    $ageSql,
    'Collection-age SQL should scope the report to the requested library.'
);
assertContainsText(
    'ilo.name ILIKE :p2',
    $ageSql,
    'Collection-age SQL should scope reference prompts through location semantics.'
);
assertNotContainsText(
    'LIMIT 100',
    $ageSql,
    'Collection-age aggregate SQL should not append a LIMIT clause because the aggregate already returns a single row and the limit does not reduce execution cost.'
);

$compiledAgeLibraryOnly = QueryFamilyCompilerService::compileToQueryDefinition([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'requested_outputs' => ['average_age_years'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

assertSameValue(
    2,
    count($compiledAgeLibraryOnly['filters'] ?? []),
    'Library-only collection-age query definitions should include only campus and library filters when no explicit location scope is present.'
);
assertSameValue(
    '%Neilson Library%',
    $compiledAgeLibraryOnly['filters'][1]['value'] ?? null,
    'Library-only collection-age query definitions should preserve the requested library scope.'
);

$ageLibraryOnlyBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'requested_outputs' => ['average_age_years'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

$ageLibraryOnlySql = $ageLibraryOnlyBuilt['sql'] ?? '';
assertContainsText(
    'il.name ILIKE :p1',
    $ageLibraryOnlySql,
    'Library-only collection-age SQL should still scope the report to the requested library.'
);
assertContainsText(
    'WITH scoped_instances AS (',
    $ageLibraryOnlySql,
    'Library-only collection-age SQL should also scope and aggregate instances before joining publication data.'
);
assertNotContainsText(
    'ilo.name ILIKE',
    $ageLibraryOnlySql,
    'Library-only collection-age SQL should not invent a location predicate when the prompt never requested a location.'
);
assertNotContainsText(
    'LIMIT 100',
    $ageLibraryOnlySql,
    'Library-only collection-age aggregate SQL should not append a LIMIT clause.'
);

$ageLocationOnlyBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'location' => 'Locked Stack',
        'requested_outputs' => ['average_age_years'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

$ageLocationOnlySql = $ageLocationOnlyBuilt['sql'] ?? '';
assertContainsText(
    'ilo.name ILIKE :p1',
    $ageLocationOnlySql,
    'Location-scoped collection-age SQL should compile an explicit location predicate even when no library slot is present.'
);
assertNotContainsText(
    'il.name ILIKE',
    $ageLocationOnlySql,
    'Location-scoped collection-age SQL should not invent a library predicate when the payload supplies only campus and location scope.'
);
assertSameValue(
    '%Locked Stack%',
    $ageLocationOnlyBuilt['params'][':p1'] ?? null,
    'Location-scoped collection-age SQL should preserve the explicit locked-stack location phrase as a contains match.'
);

$ageCountAndAverageBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'inventory_collection_age',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Hillyer',
        'location' => 'Zine Collection',
        'requested_outputs' => ['item_count', 'average_age_years'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

$ageCountAndAverageSql = $ageCountAndAverageBuilt['sql'] ?? '';
assertContainsText(
    'SUM(scoped_instances.item_count) AS item_count',
    $ageCountAndAverageSql,
    'Collection-age SQL should include the scoped item count when the prompt asks how many items are in the collection.'
);
assertContainsText(
    'AS average_age_years',
    $ageCountAndAverageSql,
    'Collection-age SQL should still include average age when count and age are requested together.'
);
assertContainsText(
    'ilo.name ILIKE :p2',
    $ageCountAndAverageSql,
    'Collection-age SQL should compile the explicit zine collection as a location predicate.'
);
assertSameValue(
    '%Hillyer%',
    $ageCountAndAverageBuilt['params'][':p1'] ?? null,
    'Collection-age SQL should use a broad Hillyer library token so it matches the real SC Hillyer Art Library label.'
);
assertSameValue(
    '%Zine Collection%',
    $ageCountAndAverageBuilt['params'][':p2'] ?? null,
    'Collection-age SQL should preserve the zine collection location phrase as a contains match.'
);

$trendCompiled = QueryFamilyCompilerService::compileToQueryDefinition([
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

assertSameValue(
    [
        'circulation_loans',
        'inventory_items',
        'inventory_locations',
        'inventory_libraries',
        'inventory_campuses',
    ],
    $trendCompiled['tables'] ?? null,
    'The trend-matrix family compiler should preserve the checked-in circulation canonical path order.'
);

assertSameValue(
    [
        [
            'from_table' => 'circulation_loans',
            'from_column' => 'item_id',
            'to_table' => 'inventory_items',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'circulation_loans',
            'from_column' => 'item_effective_location_id_at_check_out',
            'to_table' => 'inventory_locations',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_locations',
            'from_column' => 'library_id',
            'to_table' => 'inventory_libraries',
            'to_column' => 'id',
        ],
        [
            'from_table' => 'inventory_libraries',
            'from_column' => 'campus_id',
            'to_table' => 'inventory_campuses',
            'to_column' => 'id',
        ],
    ],
    $trendCompiled['joins'] ?? null,
    'The trend-matrix family compiler should emit one deterministic join chain from circulation loans to location scope.'
);

assertSameValue(
    '%Neilson Library%',
    $trendCompiled['filters'][1]['value'] ?? null,
    'Trend-matrix family query definitions should preserve library contains matching for prefixed stored library names.'
);

$trendBuilt = QueryFamilyCompilerService::compileToSql([
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

$trendSql = $trendBuilt['sql'] ?? '';
assertContainsText(
    'FROM circulation.loan__t cl',
    $trendSql,
    'The trend-matrix compiler should anchor the SQL on circulation.loan__t.'
);
assertContainsText(
    'JOIN inventory.item__t ii ON cl.item_id = ii.id',
    $trendSql,
    'The trend-matrix compiler should join loans to items for call-number classification.'
);
assertContainsText(
    'JOIN inventory.location__t ilo ON cl.item_effective_location_id_at_check_out = ilo.id',
    $trendSql,
    'The trend-matrix compiler should scope circulation by the effective checkout location recorded on the loan.'
);
assertContainsText(
    'SUM(CASE WHEN EXTRACT(YEAR FROM cl.loan_date) = 2026 THEN 1 ELSE 0 END) AS circulation_2026',
    $trendSql,
    'The trend-matrix compiler should emit one conditional aggregate per requested year bucket in the original order.'
);
assertContainsText(
    'SUM(CASE WHEN EXTRACT(YEAR FROM cl.loan_date) = 2023 THEN 1 ELSE 0 END) AS circulation_2023',
    $trendSql,
    'The trend-matrix compiler should keep the last requested year bucket in the matrix output.'
);
assertContainsText(
    "WHEN ii.effective_call_number_components__call_number ~ '^[A-Z]{1,3}[0-9]'",
    $trendSql,
    'The trend-matrix compiler should use the canonical LC call-number-class extraction instead of broad prefix heuristics.'
);
assertContainsText(
    'THEN LPAD(',
    $trendSql,
    'The trend-matrix compiler should include the Dewey hundred-class branch in the call-number-class extraction CASE expression.'
);
assertContainsText(
    "cl.action IN ('checkedout', 'checkedOutThroughOverride')",
    $trendSql,
    'The first trend-matrix compiler slice should scope current circulation to checkout loan actions.'
);
assertContainsText(
    'GROUP BY call_number_class',
    $trendSql,
    'The trend-matrix compiler should group rows by derived primary call number class.'
);
assertContainsText(
    'ORDER BY call_number_class ASC',
    $trendSql,
    'The trend-matrix compiler should keep matrix rows in deterministic call-number-class order.'
);
assertContainsText(
    'il.name ILIKE :p1',
    $trendSql,
    'The trend-matrix compiler should parameterize the requested library scope filter.'
);

$formerTrendBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'circulation_trends_matrix',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Josten Library',
        'location' => 'Treasure Case',
        'grouping_dimension' => 'primary_call_number_class',
        'year_buckets' => ['2026', '2025', '2024', '2023'],
        'circulation_source_policy' => 'former_aleph_comparison',
        'requested_outputs' => ['yearly_circulation_matrix'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

$formerTrendSql = $formerTrendBuilt['sql'] ?? '';
assertContainsText(
    'LEFT JOIN inventory.item__t__notes itn ON itn.hrid = ii.hrid',
    $formerTrendSql,
    'The former-ALEPH trend slice should join migrated circulation notes by item hrid.'
);
assertContainsText(
    "itn.notes__item_note_type_id = 'f765f19f-9f1c-4688-8c79-ec366a730842'",
    $formerTrendSql,
    'The former-ALEPH trend slice should scope note extraction to the known migrated former-circulation note type.'
);
assertContainsText(
    "SUM(CAST(COALESCE(NULLIF(REGEXP_REPLACE(itn.notes__note, '\\D', '', 'g'), ''), '0') AS BIGINT)) AS former_circulation",
    $formerTrendSql,
    'The former-ALEPH trend slice should aggregate the previous circulation column from migrated item notes as BIGINT.'
);
assertContainsText(
    'COALESCE(fc.former_circulation, 0) AS former_circulation',
    $formerTrendSql,
    'The former-ALEPH trend slice should expose one former_circulation comparison column per call number class in the outer matrix output.'
);
assertContainsText(
    'ilo.name ILIKE :p2',
    $formerTrendSql,
    'The former-ALEPH trend slice should preserve explicit location scope alongside the former circulation comparison column.'
);

$priorYearTrendBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'circulation_trends_matrix',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'grouping_dimension' => 'primary_call_number_class',
        'year_buckets' => ['2026', '2025', '2024', '2023'],
        'circulation_source_policy' => 'prior_year_comparison',
        'requested_outputs' => ['yearly_circulation_matrix'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

$priorYearTrendSql = $priorYearTrendBuilt['sql'] ?? '';
assertContainsText(
    'SUM(CASE WHEN EXTRACT(YEAR FROM cl.loan_date) = 2025 THEN 1 ELSE 0 END) AS previous_circulation',
    $priorYearTrendSql,
    'The prior-year trend slice should expose one previous_circulation column using the year immediately before the first requested year bucket.'
);
assertContainsText(
    'il.name ILIKE :p1',
    $priorYearTrendSql,
    'The prior-year trend slice should preserve library scope filters alongside the previous_circulation comparison column.'
);

$cumulativeTrendBuilt = QueryFamilyCompilerService::compileToSql([
    'familyKey' => 'circulation_trends_matrix',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Neilson Library',
        'grouping_dimension' => 'primary_call_number_class',
        'year_buckets' => ['2026', '2025', '2024', '2023'],
        'circulation_source_policy' => 'cumulative_before_selected_years_comparison',
        'requested_outputs' => ['yearly_circulation_matrix'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

$cumulativeTrendSql = $cumulativeTrendBuilt['sql'] ?? '';
assertContainsText(
    'SUM(CASE WHEN EXTRACT(YEAR FROM cl.loan_date) < 2023 THEN 1 ELSE 0 END) AS previous_circulation',
    $cumulativeTrendSql,
    'The cumulative-before trend slice should expose one previous_circulation column counting circulation before the earliest requested year bucket.'
);
assertContainsText(
    'il.name ILIKE :p1',
    $cumulativeTrendSql,
    'The cumulative-before trend slice should preserve library scope filters alongside the previous_circulation comparison column.'
);

fwrite(STDOUT, "QueryFamilyCompilerService test passed\n");