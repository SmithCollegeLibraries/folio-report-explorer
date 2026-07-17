<?php

$builderSqlCacheDir = sys_get_temp_dir() . '/builder-sql-cache-' . bin2hex(random_bytes(6));
mkdir($builderSqlCacheDir, 0700, true);
foreach (['table_mapping_cache.json', 'subtable_cache.json', 'column_cache.json'] as $cacheFile) {
    $cache = json_decode((string)file_get_contents(__DIR__ . '/../data/' . $cacheFile), true);
    $cache['_discovered_at'] = date('c');
    if ($cacheFile === 'table_mapping_cache.json') {
        $cache['mapping']['inventory_items'] = 'inventory.item__t';
        $cache['mapping']['inventory_locations'] = 'inventory.location__t';
    }
    file_put_contents(
        $builderSqlCacheDir . '/' . $cacheFile,
        json_encode($cache, JSON_PRETTY_PRINT)
    );
}
register_shutdown_function(function () use ($builderSqlCacheDir): void {
    foreach (glob($builderSqlCacheDir . '/*') ?: [] as $cacheFile) {
        unlink($cacheFile);
    }
    rmdir($builderSqlCacheDir);
});

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            $paths = [
                '@app/data/table_mapping_cache.json' => $GLOBALS['builderSqlCacheDir'] . '/table_mapping_cache.json',
                '@app/data/subtable_cache.json' => $GLOBALS['builderSqlCacheDir'] . '/subtable_cache.json',
                '@app/data/column_cache.json' => $GLOBALS['builderSqlCacheDir'] . '/column_cache.json',
            ];
            return $paths[$alias] ?? $alias;
        }

        public static function warning($message, $category = 'application'): void
        {
        }
    }
}

Yii::$app = (object) [
    'cache' => null,
    'folioDb' => new class {
        public function quoteValue($value): string
        {
            return "'" . str_replace("'", "''", (string)$value) . "'";
        }

        public function createCommand($sql = '')
        {
            return new class {
                public function queryAll(): array
                {
                    return [];
                }
            };
        }
    },
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
        'defaultQueryLimit' => 100,
        'maxQueryRows' => 1000,
    ],
];

require_once __DIR__ . '/../services/FolioSchemaService.php';
require_once __DIR__ . '/../services/SqlBuilderService.php';
require_once __DIR__ . '/../services/BuilderQueryDefinitionNormalizerService.php';

use app\services\BuilderQueryDefinitionNormalizerService;
use app\services\SqlBuilderService;

function expectSqlContains(string $sql, string $fragment): void
{
    if (strpos($sql, $fragment) === false) {
        fwrite(STDERR, "Expected SQL fragment:\n{$fragment}\nActual SQL:\n{$sql}\n");
        exit(1);
    }
}

$mapping = [
    'inventory.item__t' => 'inventory_items',
    'inventory.location__t' => 'inventory_locations',
];

$relationships = [];
foreach (['effective', 'permanent', 'temporary'] as $kind) {
    $column = $kind . '_location_id';
    $id = "inventory.item__t.{$column}->inventory.location__t.id";
    $relationships[$id] = [
        'relationship_id' => $id,
        'pair_id' => 'inventory.item__t<->inventory.location__t',
        'from_table' => 'inventory.item__t',
        'from_column' => $column,
        'to_table' => 'inventory.location__t',
        'to_column' => 'id',
        'is_default' => $kind === 'effective',
    ];
}
$catalog = [
    'relationships_by_id' => $relationships,
    'defaults_by_pair' => [
        'inventory.item__t<->inventory.location__t'
            => 'inventory.item__t.effective_location_id->inventory.location__t.id',
    ],
];

$baseDefinition = [
    'schemaIdentity' => 'ldlite',
    'tables' => ['inventory.item__t', 'inventory.location__t'],
    'columns' => [['table' => 'inventory.item__t', 'column' => 'barcode']],
    'filters' => [],
    'orderBy' => [],
    'groupBy' => [],
    'having' => [],
    'limit' => 100,
];

foreach (['effective', 'permanent', 'temporary'] as $kind) {
    $column = $kind . '_location_id';
    $definition = $baseDefinition;
    $definition['joins'] = [[
        'relationship_id' => "inventory.item__t.{$column}->inventory.location__t.id",
    ]];

    $normalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
        $definition,
        $mapping,
        $catalog
    );
    $result = SqlBuilderService::build($normalized);

    expectSqlContains($result['sql'], 'JOIN inventory.location__t il');
    expectSqlContains($result['sql'], "ON il.id = ii.{$column}");
}

$reverseDefinition = $baseDefinition;
$reverseDefinition['tables'] = ['inventory.location__t', 'inventory.item__t'];
$reverseDefinition['columns'] = [['table' => 'inventory.location__t', 'column' => 'name']];
$reverseDefinition['joins'] = [[
    'relationship_id' => 'inventory.item__t.permanent_location_id->inventory.location__t.id',
]];
$reverseNormalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
    $reverseDefinition,
    $mapping,
    $catalog
);
$reverseResult = SqlBuilderService::build($reverseNormalized);
expectSqlContains($reverseResult['sql'], 'FROM inventory.location__t il');
expectSqlContains($reverseResult['sql'], 'JOIN inventory.item__t ii');
expectSqlContains($reverseResult['sql'], 'ON ii.permanent_location_id = il.id');

$defaultDefinition = $baseDefinition;
$defaultDefinition['joins'] = 'auto';
$defaultNormalized = BuilderQueryDefinitionNormalizerService::normalizeWithCatalog(
    $defaultDefinition,
    $mapping,
    $catalog
);
$defaultResult = SqlBuilderService::build($defaultNormalized);
expectSqlContains($defaultResult['sql'], 'ON il.id = ii.effective_location_id');

fwrite(STDOUT, "SqlBuilderService LDLite relationship test passed\n");
