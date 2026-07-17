<?php

require_once __DIR__ . '/../services/BuilderRelationshipCatalogService.php';

use app\services\BuilderRelationshipCatalogService;

function expectCatalog($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$legacy = [
    'inventory_items' => [
        'parents' => [[
            'parent_table' => 'inventory_locations',
            'parent_column' => 'id',
            'local_column' => 'effective_location_id',
            'foreign_key' => 'inventory_items_effective_location_id_fkey',
        ]],
        'children' => [],
    ],
];
$mapping = [
    'inventory_items' => 'inventory.item__t',
    'inventory_locations' => 'inventory.location__t',
];
$columns = [
    'inventory.item__t' => [
        ['name' => 'effective_location_id'],
        ['name' => 'permanent_location_id'],
        ['name' => 'temporary_location_id'],
    ],
    'inventory.location__t' => [['name' => 'id']],
];
$overlay = [
    'version' => 1,
    'relationships' => [
        [
            'fromTable' => 'inventory.item__t',
            'fromColumn' => 'effective_location_id',
            'toTable' => 'inventory.location__t',
            'toColumn' => 'id',
            'label' => 'Effective location',
            'default' => true,
        ],
        [
            'fromTable' => 'inventory.item__t',
            'fromColumn' => 'permanent_location_id',
            'toTable' => 'inventory.location__t',
            'toColumn' => 'id',
            'label' => 'Permanent location',
            'default' => false,
        ],
        [
            'fromTable' => 'inventory.item__t',
            'fromColumn' => 'missing_location_id',
            'toTable' => 'inventory.location__t',
            'toColumn' => 'id',
            'label' => 'Invalid location',
            'default' => false,
        ],
    ],
];

$catalog = BuilderRelationshipCatalogService::build($legacy, $mapping, $columns, $overlay);
$pairId = BuilderRelationshipCatalogService::pairId('inventory.item__t', 'inventory.location__t');
$defaultId = 'inventory.item__t.effective_location_id->inventory.location__t.id';
$permanentId = 'inventory.item__t.permanent_location_id->inventory.location__t.id';

expectCatalog(count($catalog['relationships_by_pair'][$pairId]) === 2, 'Expected two valid direct item-location relationships.');
expectCatalog($catalog['defaults_by_pair'][$pairId] === $defaultId, 'Effective location must be the curated default.');
expectCatalog(isset($catalog['relationships_by_id'][$permanentId]), 'Permanent location must be present.');
expectCatalog($catalog['relationships_by_id'][$defaultId]['source'] === 'overlay', 'Overlay must enrich the generated relationship.');
expectCatalog(count($catalog['warnings']) === 1, 'Invalid overlay columns must produce one isolated warning.');

fwrite(STDOUT, "Builder relationship catalog test passed\n");
