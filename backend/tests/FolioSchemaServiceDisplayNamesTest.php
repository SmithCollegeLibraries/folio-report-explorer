<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';

if (!file_exists($schemaServicePath)) {
    fwrite(STDERR, "FolioSchemaService is missing at {$schemaServicePath}\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            if ($alias === '@app/data/table_mapping_cache.json') {
                return __DIR__ . '/../data/table_mapping_cache.json';
            }
            if ($alias === '@app/data/column_cache.json') {
                return __DIR__ . '/../data/column_cache.json';
            }
            if ($alias === '@app/data/subtable_cache.json') {
                return __DIR__ . '/../data/subtable_cache.json';
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
    ],
];

require_once $schemaServicePath;

use app\services\FolioSchemaService;

$mapProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredMap');
$mapProperty->setValue(null, [
    'inventory_items' => 'inventory.item__t',
]);
$columnProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredColumns');
$columnProperty->setValue(null, []);

function assertSameDisplayValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$tables = FolioSchemaService::getTables(['inventory_items']);
$summary = $tables['inventory_items'] ?? null;

if (!is_array($summary)) {
    fwrite(STDERR, "inventory_items summary should be present in schema table list\n");
    exit(1);
}

assertSameDisplayValue(
    'inventory.item__t',
    $summary['sql_name'] ?? null,
    'Schema table list should expose the real Postgres table name for legacy aliases.'
);
assertSameDisplayValue(
    'inventory_items',
    $summary['alias_name'] ?? null,
    'Schema table list should retain the legacy alias as secondary metadata.'
);

$detail = FolioSchemaService::getTable('inventory_items');

assertSameDisplayValue(
    'inventory.item__t',
    $detail['sql_name'] ?? null,
    'Schema table detail should expose the real Postgres table name for legacy aliases.'
);
assertSameDisplayValue(
    'inventory_items',
    $detail['alias_name'] ?? null,
    'Schema table detail should retain the legacy alias as secondary metadata.'
);

fwrite(STDOUT, "FolioSchemaService display names test passed\n");
