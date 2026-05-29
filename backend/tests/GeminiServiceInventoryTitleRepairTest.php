<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([$schemaServicePath, $sqlBuilderPath, $geminiServicePath] as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Required service is missing at {$path}\n");
        exit(1);
    }
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

        public static function warning($message, $category = null)
        {
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
require_once $sqlBuilderPath;
require_once $geminiServicePath;

use app\services\GeminiService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: {$expected}\nActual: {$actual}\n");
        exit(1);
    }
}

$repair = new ReflectionMethod(GeminiService::class, 'repairInvalidInventoryTitleReferences');

$badSql = implode("\n", [
    'SELECT ii.title, COUNT(DISTINCT inst.id) AS record_count',
    'FROM inventory.item__t AS ii',
    'JOIN inventory.holdings_record__t AS hr ON hr.id = ii.holdings_record_id',
    'JOIN inventory.instance__t AS inst ON inst.id = hr.instance_id',
    'GROUP BY hr.permanent_location_id',
    'ORDER BY record_count DESC',
]);

$expectedSql = implode("\n", [
    'SELECT inst.title, COUNT(DISTINCT inst.id) AS record_count',
    'FROM inventory.item__t AS ii',
    'JOIN inventory.holdings_record__t AS hr ON hr.id = ii.holdings_record_id',
    'JOIN inventory.instance__t AS inst ON inst.id = hr.instance_id',
    'GROUP BY hr.permanent_location_id, inst.title',
    'ORDER BY record_count DESC',
]);

assertSameValue(
    $expectedSql,
    $repair->invoke(null, $badSql),
    'Generated SQL repair should move item title references to the joined instance alias and extend GROUP BY.'
);

$instanceAliasSql = implode("\n", [
    'SELECT ii.title, COUNT(*) AS record_count',
    'FROM inventory.instance__t ii',
    'GROUP BY ii.title',
]);

assertSameValue(
    $instanceAliasSql,
    $repair->invoke(null, $instanceAliasSql),
    'Generated SQL repair should not rewrite aliases that already point to inventory.instance__t.'
);

fwrite(STDOUT, "GeminiService inventory title repair test passed\n");
