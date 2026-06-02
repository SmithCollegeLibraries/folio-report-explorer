<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';

foreach ([
    'FolioSchemaService' => $schemaServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
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
            if ($alias === '@app/data/table_mapping_cache.json') {
                return __DIR__ . '/../data/table_mapping_cache.json';
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
    'folioDb' => new class {
        public function quoteValue($value)
        {
            return "'" . str_replace("'", "''", (string)$value) . "'";
        }

        public function createCommand($sql = '')
        {
            return new class {
                public function queryAll()
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

require_once $schemaServicePath;
require_once $sqlBuilderPath;

use app\services\SqlBuilderService;

function assertThrowsInvalidArgument(callable $callback, string $expectedText, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        if (strpos($e->getMessage(), $expectedText) !== false) {
            return;
        }
        fwrite(STDERR, $message . "\nExpected exception text: {$expectedText}\nActual: {$e->getMessage()}\n");
        exit(1);
    }

    fwrite(STDERR, $message . "\nExpected InvalidArgumentException was not thrown.\n");
    exit(1);
}

$baseQuery = [
    'tables' => ['inventory_instances'],
    'columns' => [
        ['table' => 'inventory_instances', 'column' => 'title'],
    ],
    'filters' => [],
    'joins' => 'auto',
    'orderBy' => [],
    'groupBy' => [],
    'having' => [],
    'distinct' => false,
    'limit' => 100,
];

$nestedInQuery = $baseQuery;
$nestedInQuery['filters'][] = [
    'table' => 'inventory_instances',
    'column' => 'hrid',
    'op' => 'IN',
    'value' => [['in00002452774', 'in00004512775']],
];

assertThrowsInvalidArgument(
    fn() => SqlBuilderService::build($nestedInQuery),
    'Operator IN requires scalar list values',
    'SqlBuilderService should reject nested IN values with a controlled exception instead of calling trim() on an array.'
);

$scalarArrayQuery = $baseQuery;
$scalarArrayQuery['filters'][] = [
    'table' => 'inventory_instances',
    'column' => 'hrid',
    'op' => '=',
    'value' => ['in00002452774', 'in00004512775'],
];

assertThrowsInvalidArgument(
    fn() => SqlBuilderService::build($scalarArrayQuery),
    'Operator = requires a scalar value',
    'SqlBuilderService should reject array values for scalar operators.'
);

fwrite(STDOUT, "SqlBuilderService filter value shape test passed\n");
