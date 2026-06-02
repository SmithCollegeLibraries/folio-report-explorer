<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$queryIntentServicePath = __DIR__ . '/../services/QueryIntentService.php';

foreach ([
    'FolioSchemaService' => $schemaServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
    'QueryIntentService' => $queryIntentServicePath,
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
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
    ],
];

require_once $schemaServicePath;
require_once $sqlBuilderPath;
require_once $queryIntentServicePath;

use app\services\QueryIntentService;

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

$intent = [
    'intentVersion' => 1,
    'query' => [
        'tables' => [
            'inventory.instance__t',
            'folio_source_record.records__t',
        ],
        'select' => [
            [
                'table' => 'inventory.instance__t',
                'column' => 'title',
            ],
        ],
        'where' => [
            [
                'table' => 'folio_source_record.records__t',
                'column' => 'parsed_record__content',
                'op' => 'NOT LIKE',
                'value' => '%"300"%',
            ],
        ],
        'joins' => 'auto',
        'groupBy' => [],
        'having' => [],
        'sort' => [
            [
                'table' => 'inventory.instance__t',
                'column' => 'title',
                'direction' => 'ASC',
            ],
        ],
        'distinct' => false,
        'limit' => 100,
    ],
];

$validation = QueryIntentService::validateIntent($intent);

assertTrueValue(
    $validation['valid'] ?? false,
    'Structured intent validation should accept known schema-qualified physical table names and normalize them to logical contract identifiers.'
);

$normalizedQuery = $validation['normalizedIntent']['query'] ?? [];

assertSameValue(
    $normalizedQuery['tables'][0] ?? null,
    $normalizedQuery['select'][0]['table'] ?? null,
    'Select clauses should normalize physical table names consistently.'
);
assertSameValue(
    $normalizedQuery['tables'][1] ?? null,
    $normalizedQuery['where'][0]['table'] ?? null,
    'Where clauses should normalize physical table names consistently.'
);
assertSameValue(
    $normalizedQuery['tables'][0] ?? null,
    $normalizedQuery['sort'][0]['table'] ?? null,
    'Sort clauses should normalize physical table names consistently.'
);

$nestedArrayFilterIntent = $intent;
$nestedArrayFilterIntent['query']['where'][0] = [
    'table' => 'inventory.instance__t',
    'column' => 'hrid',
    'op' => 'IN',
    'value' => [['in00002452774', 'in00004512775']],
];
$nestedArrayValidation = QueryIntentService::validateIntent($nestedArrayFilterIntent);

assertSameValue(
    false,
    $nestedArrayValidation['valid'] ?? null,
    'Structured intent validation should reject nested array filter values before SqlBuilder sees them.'
);
assertSameValue(
    'query.where[0].value[0]',
    $nestedArrayValidation['errors'][0]['path'] ?? null,
    'Nested array filter validation should point to the non-scalar IN value.'
);

$scalarOperatorArrayIntent = $intent;
$scalarOperatorArrayIntent['query']['where'][0] = [
    'table' => 'inventory.instance__t',
    'column' => 'hrid',
    'op' => '=',
    'value' => ['in00002452774', 'in00004512775'],
];
$scalarOperatorArrayValidation = QueryIntentService::validateIntent($scalarOperatorArrayIntent);

assertSameValue(
    false,
    $scalarOperatorArrayValidation['valid'] ?? null,
    'Structured intent validation should reject array values for scalar comparison operators.'
);
assertSameValue(
    'query.where[0].value',
    $scalarOperatorArrayValidation['errors'][0]['path'] ?? null,
    'Scalar operator array validation should point to the invalid where value.'
);

fwrite(STDOUT, "QueryIntentService table normalization test passed\n");
