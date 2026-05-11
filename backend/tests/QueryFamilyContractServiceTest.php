<?php

$servicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$contractPath = __DIR__ . '/../data/query_family_contracts.json';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "QueryFamilyContractService is missing at {$servicePath}\n");
    exit(1);
}

if (!file_exists($contractPath)) {
    fwrite(STDERR, "query_family_contracts.json is missing at {$contractPath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\QueryFamilyContractService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$contracts = QueryFamilyContractService::loadContracts();

assertTrueValue(
    isset($contracts['inventory_contributor_campus_item_barcode']),
    'The first checked-in query family contract should be available under the inventory contributor/campus/item/barcode key.'
);
assertTrueValue(
    isset($contracts['circulation_trends_matrix']),
    'The checked-in query family contracts should include the first circulation trend-matrix contract.'
);
assertTrueValue(
    isset($contracts['circulation_top_items']),
    'The checked-in query family contracts should include the top-circulating-items contract once that deterministic family is introduced.'
);

$selection = QueryFamilyContractService::selectContract([
    'availableEntityKeys' => [
        'inventory_instances',
        'inventory_instance__t__contributors',
        'inventory_contributor_name_types',
        'inventory_holdings',
        'inventory_items',
        'inventory_locations',
        'inventory_libraries',
        'inventory_campuses',
    ],
    'slotNames' => [
        'campus',
        'contributor_name',
        'contributor_name_type',
        'material_type',
    ],
    'outputFields' => [
        'barcode',
        'instance_hrid',
        'title',
    ],
]);

assertSameValue(true, $selection['matched'] ?? null, 'The covered contributor/campus/item family should match deterministically.');
assertSameValue(
    'inventory_contributor_campus_item_barcode',
    $selection['contractKey'] ?? null,
    'Contract selection should identify the checked-in contributor/campus/item family.'
);
assertSameValue(
    'outputs_via_qualifying_holdings',
    $selection['contract']['scopeRule'] ?? null,
    'The selected contract should preserve the required holdings-scoped output rule.'
);
assertTrueValue(
    in_array('contributor_name', $selection['contract']['outputs']['allowed'] ?? [], true),
    'The covered contributor/campus/item contract should allow contributor_name as a supported output.'
);

$trendSelection = QueryFamilyContractService::selectContract([
    'availableEntityKeys' => [
        'circulation_loans',
        'inventory_items',
        'inventory_locations',
        'inventory_libraries',
        'inventory_campuses',
    ],
    'slotNames' => [
        'campus',
        'library',
        'grouping_dimension',
        'year_buckets',
        'circulation_source_policy',
    ],
    'outputFields' => [
        'yearly_circulation_matrix',
    ],
]);

assertSameValue(true, $trendSelection['matched'] ?? null, 'The circulation trend-matrix family should match deterministically once the required entities, slots, and output fields are present.');
assertSameValue(
    'circulation_trends_matrix',
    $trendSelection['contractKey'] ?? null,
    'Contract selection should identify the checked-in circulation trend-matrix family.'
);
assertSameValue(
    'circulation_trends_by_call_number_class',
    $trendSelection['contract']['scopeRule'] ?? null,
    'The selected trend contract should preserve the deterministic call-number trend scope rule.'
);

$unsupportedSelection = QueryFamilyContractService::selectContract([
    'availableEntityKeys' => [
        'inventory_instances',
        'inventory_holdings',
        'inventory_items',
    ],
    'slotNames' => [
        'call_number',
        'campus',
    ],
    'outputFields' => [
        'barcode',
    ],
]);

assertSameValue(false, $unsupportedSelection['matched'] ?? null, 'Unsupported families should fail selection deterministically.');
assertSameValue('unsupported_family', $unsupportedSelection['reason'] ?? null, 'Unsupported family failures should return a stable reason.');

fwrite(STDOUT, "QueryFamilyContractService test passed\n");