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
require_once __DIR__ . '/../services/BuilderQueryDefinitionNormalizerService.php';
require_once __DIR__ . '/../services/SqlBuilderService.php';

use app\services\BuilderSchemaService;
use app\services\BuilderQueryDefinitionNormalizerService;
use app\services\FolioSchemaService;
use app\services\SqlBuilderService;

function expectSubtableRelationship($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$overlayPath = tempnam(sys_get_temp_dir(), 'builder-subtable-overlay-');
file_put_contents($overlayPath, json_encode([
    'version' => 1,
    'relationships' => [[
        'fromTable' => 'inventory.instance__t__identifiers',
        'fromColumn' => 'id',
        'toTable' => 'inventory.instance__t',
        'toColumn' => 'id',
        'label' => 'Reviewed instance parent',
        'default' => true,
    ]],
]));
register_shutdown_function(function () use ($overlayPath): void {
    if (is_file($overlayPath)) {
        unlink($overlayPath);
    }
});

Yii::$app = (object) [
    'cache' => null,
    'params' => [
        'builderRelationshipOverlayPath' => $overlayPath,
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
        'defaultQueryLimit' => 100,
        'maxQueryRows' => 1000,
    ],
];

$mapping = [
    'inventory_instances' => 'inventory.instance__t',
    'inventory_identifier_types' => 'inventory.identifier_type__t',
    'inventory_instance_types' => 'inventory.instance_type__t',
    'inventory_holdings' => 'inventory.holdings_record__t',
    'inventory_holdings_note_types' => 'inventory.holdings_note_type__t',
    'inventory.instance__t' => 'inventory.instance__t',
    'inventory.identifier_type__t' => 'inventory.identifier_type__t',
    'inventory.instance_type__t' => 'inventory.instance_type__t',
    'inventory.holdings_record__t' => 'inventory.holdings_record__t',
    'inventory.holdings_note_type__t' => 'inventory.holdings_note_type__t',
];
$columns = [
    'inventory.instance__t' => [['name' => 'id'], ['name' => 'title']],
    'inventory.identifier_type__t' => [['name' => 'id'], ['name' => 'name']],
    'inventory.instance_type__t' => [['name' => 'id'], ['name' => 'name']],
    'inventory.holdings_record__t' => [['name' => 'id'], ['name' => 'hrid']],
    'inventory.holdings_note_type__t' => [['name' => 'id'], ['name' => 'name']],
];
$subtables = [
    'inventory.instance__t__identifiers' => [
        'parent' => 'inventory.instance__t',
        'columns' => [
            ['name' => 'id'],
            ['name' => 'identifiers__identifier_type_id'],
            ['name' => 'instance_type_id'],
        ],
    ],
    'inventory.holdings_record__t__notes' => [
        'parent' => 'inventory.holdings_record__t',
        'columns' => [['name' => 'id'], ['name' => 'holdings_note_type_id']],
    ],
    'inventory.instance__t__unresolved' => [
        'parent' => 'inventory.instance__t',
        'columns' => [['name' => 'id'], ['name' => 'unresolved__missing_type_id']],
    ],
];
$schemaProperties = [
    'schema' => [
        'tables' => [
            'inventory_instances' => ['columns' => []],
            'inventory_identifier_types' => ['columns' => []],
            'inventory_instance_types' => ['columns' => []],
            'inventory_holdings' => ['columns' => []],
            'inventory_holdings_note_types' => ['columns' => []],
        ],
        'relationships' => [],
    ],
    'discoveredMap' => $mapping,
    'discoveredColumns' => $columns,
    'discoveredSubtables' => $subtables,
];
foreach ($schemaProperties as $propertyName => $value) {
    $property = new ReflectionProperty(FolioSchemaService::class, $propertyName);
    if (PHP_VERSION_ID < 80100) {
        $property->setAccessible(true);
    }
    $property->setValue(null, $value);
}

$catalog = BuilderSchemaService::catalog();
$parentId = 'inventory.instance__t__identifiers.id->inventory.instance__t.id';
$lookupId = 'inventory.instance__t__identifiers.identifiers__identifier_type_id->inventory.identifier_type__t.id';
$copiedLookupId = 'inventory.instance__t__identifiers.instance_type_id->inventory.instance_type__t.id';
$notesParentId = 'inventory.holdings_record__t__notes.id->inventory.holdings_record__t.id';
$unresolvedLookupId = 'inventory.instance__t__unresolved.unresolved__missing_type_id->inventory.missing_type__t.id';

expectSubtableRelationship(
    ($catalog['relationships_by_id'][$parentId]['source'] ?? null) === 'overlay'
        && $catalog['relationships_by_id'][$parentId]['label'] === 'Reviewed instance parent',
    'An overlay must replace a generated subtable-parent relationship.'
);
expectSubtableRelationship(
    ($catalog['relationships_by_id'][$lookupId]['to_column'] ?? null) === 'id',
    'An array-prefixed subtable foreign-key column must link to its existing type table.'
);
expectSubtableRelationship(
    ($catalog['relationships_by_id'][$notesParentId]['label'] ?? null) === 'Parent record',
    'A second subtable family must link to its parent with the Parent record label.'
);
expectSubtableRelationship(
    !isset($catalog['relationships_by_id'][$unresolvedLookupId])
        && $catalog['warnings'] === [],
    'An unresolved lookup target must be omitted without a catalog warning.'
);
expectSubtableRelationship(
    !isset($catalog['relationships_by_id'][$copiedLookupId])
        && BuilderSchemaService::findShortestPath(
            'inventory.instance__t',
            'inventory.instance_type__t'
        ) === null
        && FolioSchemaService::findShortestPath(
            'inventory_instances',
            'inventory_instance_types'
        ) === null,
    'A copied parent foreign key must not create a subtable lookup edge or route a path through the subtable.'
);

$builderPath = BuilderSchemaService::findShortestPath(
    'inventory.instance__t',
    'inventory.identifier_type__t'
);
expectSubtableRelationship(
    count($builderPath['joins'] ?? []) === 2
        && $builderPath['joins'][0]['relationship_id'] === $parentId
        && $builderPath['joins'][1]['relationship_id'] === $lookupId,
    'Builder join discovery must find the parent and type-table path.'
);

$query = [
    'tables' => [
        'inventory.instance__t',
        'inventory.instance__t__identifiers',
        'inventory.identifier_type__t',
    ],
    'columns' => [
        ['table' => 'inventory.instance__t', 'column' => 'title'],
        ['table' => 'inventory.identifier_type__t', 'column' => 'name'],
    ],
    'limit' => 25,
];
$canonical = BuilderQueryDefinitionNormalizerService::normalize(array_merge(
    $query,
    ['schemaIdentity' => 'ldlite', 'joins' => 'auto']
));
$canonicalSql = SqlBuilderService::build($canonical)['sql'];

$serverDefinition = $canonical;
$serverDefinition['joins'] = 'auto';
$serverSql = SqlBuilderService::build($serverDefinition)['sql'];
expectSubtableRelationship(
    $canonicalSql === $serverSql,
    'Builder catalog joins and server-side path search must compile the same selected tables.'
);

preg_match('/FROM inventory\.instance__t ([A-Za-z0-9_]+)/', $serverSql, $instanceAlias);
preg_match('/JOIN inventory\.instance__t__identifiers ([A-Za-z0-9_]+)/', $serverSql, $identifierAlias);
preg_match('/JOIN inventory\.identifier_type__t ([A-Za-z0-9_]+)/', $serverSql, $typeAlias);
expectSubtableRelationship(
    isset($instanceAlias[1], $identifierAlias[1], $typeAlias[1])
        && strpos($serverSql, 'ON ' . $identifierAlias[1] . '.id = ' . $instanceAlias[1] . '.id') !== false
        && strpos($serverSql, 'ON ' . $typeAlias[1] . '.id = ' . $identifierAlias[1] . '.identifiers__identifier_type_id') !== false,
    'Compiled SQL must join the subtable to its parent and its type table on the generated keys.'
);
SqlBuilderService::validateSafety($serverSql);
SqlBuilderService::validateTablePolicy($serverSql);

$serverParentPath = FolioSchemaService::findShortestPath(
    'inventory_holdings',
    'inventory.holdings_record__t__notes'
);
expectSubtableRelationship(
    count($serverParentPath ?? []) === 1
        && $serverParentPath[0][0] === 'inventory_holdings'
        && $serverParentPath[0][1] === 'id'
        && $serverParentPath[0][2] === 'inventory.holdings_record__t__notes'
        && $serverParentPath[0][3] === 'id',
    'Server-side path search must include the holdings notes subtable family.'
);

fwrite(STDOUT, "Builder subtable relationship test passed\n");
