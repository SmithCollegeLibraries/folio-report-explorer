<?php

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
        public static $warnings = [];

        public static function warning($message, $category = null): void
        {
            self::$warnings[] = [$message, $category];
        }
    }
}

require_once __DIR__ . '/../services/BuilderRelationshipCatalogService.php';
require_once __DIR__ . '/../services/BuilderSchemaService.php';

use app\services\BuilderRelationshipCatalogService;
use app\services\BuilderSchemaService;

function expectBuilderSchema($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$legacyTables = [
    'inventory_items' => [
        'name' => 'inventory_items',
        'sql_name' => 'inventory.item__t',
        'alias_name' => 'inventory_items',
        'domain' => 'inventory',
    ],
    'inventory_locations' => [
        'name' => 'inventory_locations',
        'sql_name' => 'inventory.location__t',
        'alias_name' => 'inventory_locations',
        'domain' => 'inventory',
    ],
    'query_jobs' => [
        'name' => 'query_jobs',
        'sql_name' => 'query_jobs',
        'alias_name' => null,
        'domain' => 'local',
    ],
];

$projected = BuilderSchemaService::projectTables($legacyTables, null);
expectBuilderSchema(isset($projected['inventory.item__t']), 'Canonical table map must be keyed by LDLite name.');
expectBuilderSchema($projected['inventory.item__t']['name'] === 'inventory.item__t', 'Canonical name must be physical.');
expectBuilderSchema($projected['inventory.item__t']['alias_name'] === 'inventory_items', 'Legacy alias must remain secondary metadata.');
expectBuilderSchema(isset($projected['query_jobs']), 'Local tables must retain their identity.');

$catalog = [
    'relationships_by_id' => [
        'inventory.item__t.effective_location_id->inventory.location__t.id' => [
            'relationship_id' => 'inventory.item__t.effective_location_id->inventory.location__t.id',
            'pair_id' => BuilderRelationshipCatalogService::pairId('inventory.item__t', 'inventory.location__t'),
            'from_table' => 'inventory.item__t',
            'from_column' => 'effective_location_id',
            'to_table' => 'inventory.location__t',
            'to_column' => 'id',
            'label' => 'Effective location',
            'is_default' => true,
            'source' => 'overlay',
        ],
    ],
    'defaults_by_pair' => [],
];
$detail = BuilderSchemaService::projectTable([
    'name' => 'inventory_items',
    'sql_name' => 'inventory.item__t',
    'alias_name' => 'inventory_items',
    'table' => ['columns' => [['name' => 'effective_location_id']]],
], $catalog);

expectBuilderSchema($detail['name'] === 'inventory.item__t', 'Canonical detail must use the physical name.');
expectBuilderSchema($detail['relationships']['parents'][0]['parent_table'] === 'inventory.location__t', 'Relationship endpoint must be physical.');
expectBuilderSchema(
    BuilderSchemaService::chooseDefaultRelationshipId($catalog, $detail['relationships']['parents'][0]['pair_id'])
        === 'inventory.item__t.effective_location_id->inventory.location__t.id',
    'Default relationship lookup must be deterministic.'
);

$childDetail = BuilderSchemaService::projectTable([
    'name' => 'inventory_locations',
    'sql_name' => 'inventory.location__t',
    'alias_name' => 'inventory_locations',
    'table' => ['columns' => [['name' => 'id']]],
], $catalog);
$childRelationship = $childDetail['relationships']['children'][0];
expectBuilderSchema(
    $childRelationship['local_column'] === 'id'
        && $childRelationship['child_column'] === 'effective_location_id',
    'Child projection must retain its table-relative endpoint fields.'
);
expectBuilderSchema(
    $childRelationship['from_column'] === 'effective_location_id'
        && $childRelationship['to_column'] === 'id',
    'Child projection must also retain canonical endpoints for order-independent display.'
);

$pairId = BuilderRelationshipCatalogService::pairId('inventory.item__t', 'inventory.location__t');
$relationshipIds = [
    'inventory.item__t.effective_location_id->inventory.location__t.id',
    'inventory.item__t.permanent_location_id->inventory.location__t.id',
    'inventory.item__t.temporary_location_id->inventory.location__t.id',
];
$parallelCatalog = [
    'relationships_by_id' => [],
    'defaults_by_pair' => [$pairId => $relationshipIds[0]],
];
foreach ($relationshipIds as $index => $relationshipId) {
    $column = ['effective_location_id', 'permanent_location_id', 'temporary_location_id'][$index];
    $parallelCatalog['relationships_by_id'][$relationshipId] = [
        'relationship_id' => $relationshipId,
        'pair_id' => $pairId,
        'from_table' => 'inventory.item__t',
        'from_column' => $column,
        'to_table' => 'inventory.location__t',
        'to_column' => 'id',
        'label' => ucfirst(str_replace('_', ' ', $column)),
        'is_default' => $index === 0,
        'source' => 'overlay',
    ];
}

$paths = BuilderSchemaService::findAllPathsInCatalog(
    $parallelCatalog,
    'inventory.item__t',
    'inventory.location__t'
);
expectBuilderSchema(count($paths) === 3, 'Every parallel relationship must produce a distinct path.');
expectBuilderSchema($paths[0]['joins'][0]['relationship_id'] === $relationshipIds[0], 'The default path must be first.');
expectBuilderSchema($paths[1]['joins'][0]['relationship_id'] === $relationshipIds[1], 'Non-default paths must be ordered by relationship ID.');
expectBuilderSchema($paths[2]['joins'][0]['relationship_id'] === $relationshipIds[2], 'All parallel relationship variants must be retained.');

$reversePaths = BuilderSchemaService::findAllPathsInCatalog(
    $parallelCatalog,
    'inventory.location__t',
    'inventory.item__t'
);
expectBuilderSchema(
    $reversePaths[0]['joins'][0]['from_table'] === 'inventory.item__t',
    'Reverse traversal must preserve the stored relationship direction.'
);

$schema = [
    'tables' => [
        'inventory_items' => [
            'type' => 'TABLE',
            'columns' => [['name' => 'effective_location_id']],
        ],
        'inventory_locations' => [
            'type' => 'TABLE',
            'columns' => [['name' => 'id']],
        ],
    ],
    'relationships' => [
        'inventory_items' => [
            'parents' => [[
                'parent_table' => 'inventory_locations',
                'parent_column' => 'id',
                'local_column' => 'effective_location_id',
                'foreign_key' => 'inventory_items_effective_location_id_fkey',
            ]],
            'children' => [],
        ],
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

Yii::$app = (object) [
    'cache' => null,
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
        'builderRelationshipOverlayPath' => __DIR__ . '/../data/builder_relationship_overrides.json',
    ],
];

foreach ([
    'schema' => $schema,
    'discoveredMap' => $mapping,
    'discoveredColumns' => $columns,
    'discoveredSubtables' => [],
] as $propertyName => $value) {
    $property = new ReflectionProperty(app\services\FolioSchemaService::class, $propertyName);
    $property->setValue(null, $value);
}

$snapshot = app\services\FolioSchemaService::getBuilderSchemaInputs();
expectBuilderSchema($snapshot['legacy_relationships'] === $schema['relationships'], 'Builder snapshot must expose legacy relationships.');
expectBuilderSchema($snapshot['mapping'] === $mapping, 'Builder snapshot must expose verified table mappings.');
expectBuilderSchema($snapshot['columns_by_physical_table'] === $columns, 'Builder snapshot must expose physical columns.');

$tables = BuilderSchemaService::getTables(['inventory.item__t']);
expectBuilderSchema(array_keys($tables) === ['inventory.item__t'], 'Facade table filters must use physical names.');
$aliasTables = BuilderSchemaService::getTables(['inventory_items']);
expectBuilderSchema(
    array_keys($aliasTables) === ['inventory.item__t'],
    'Facade table filters must resolve legacy aliases to physical names.'
);
$mixedTables = BuilderSchemaService::getTables([
    'inventory_items',
    'inventory.location__t',
    'query_jobs',
    'unknown_table',
]);
expectBuilderSchema(
    array_keys($mixedTables) === ['inventory.item__t', 'inventory.location__t'],
    'Facade table filters must mix aliases and physical names while omitting unknown names.'
);
expectBuilderSchema(BuilderSchemaService::physicalNameFor('inventory_items') === 'inventory.item__t', 'Legacy aliases must resolve to physical names.');
expectBuilderSchema(BuilderSchemaService::physicalNameFor('inventory.item__t') === 'inventory.item__t', 'Physical names must resolve to themselves.');
expectBuilderSchema(BuilderSchemaService::legacyNameFor('inventory.item__t') === 'inventory_items', 'Physical names must resolve to their legacy aliases.');
expectBuilderSchema(BuilderSchemaService::physicalToLegacyMap()['inventory.item__t'] === 'inventory_items', 'Reverse mapping must prefer a legacy alias.');

$facadeCatalog = BuilderSchemaService::catalog();
expectBuilderSchema(count($facadeCatalog['relationships_by_id']) === 3, 'Facade catalog must include every verified reviewed relationship.');
expectBuilderSchema(BuilderSchemaService::getRelationship($relationshipIds[1])['from_column'] === 'permanent_location_id', 'Relationship lookup must use stable IDs.');

$facadeDetail = BuilderSchemaService::getTable('inventory.item__t');
expectBuilderSchema($facadeDetail['name'] === 'inventory.item__t', 'Facade detail must remain canonical.');
expectBuilderSchema(count($facadeDetail['relationships']['parents']) === 3, 'Facade detail must expose all parallel parent relationships.');

$shortest = BuilderSchemaService::findShortestPath('inventory.item__t', 'inventory.location__t');
expectBuilderSchema($shortest['joins'][0]['relationship_id'] === $relationshipIds[0], 'Shortest path must select the pair default.');
expectBuilderSchema(count(BuilderSchemaService::findAllPaths('inventory.item__t', 'inventory.location__t')) === 3, 'Facade all-path discovery must retain parallel edges.');

fwrite(STDOUT, "Builder schema service test passed\n");
