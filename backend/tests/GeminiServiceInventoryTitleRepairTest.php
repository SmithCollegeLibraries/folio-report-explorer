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
$repairAliasLeak = new ReflectionMethod(GeminiService::class, 'repairOnlyHoldingLocationAliasLeaks');

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

$aliasLeakSql = implode("\n", [
    'WITH target_locations AS (',
    '    SELECT id, name',
    '    FROM inventory.location__t',
    "    WHERE name = 'SC Rare Book Collection Reference'",
    '),',
    'target_holdings AS (',
    '    SELECT DISTINCT ih.instance_id, ih.call_number, ih.effective_location_id',
    '    FROM inventory.holdings_record__t ih',
    '    JOIN target_locations tl ON tl.id = ih.effective_location_id',
    ')',
    'SELECT DISTINCT',
    '    inst.title',
    'FROM target_holdings th',
    'JOIN inventory.instance__t inst ON inst.id = th.instance_id',
    'WHERE NOT EXISTS (',
    '    SELECT 1',
    '    FROM inventory.holdings_record__t other_hr',
    '    JOIN inventory.location__t other_loc ON other_loc.id = other_hr.effective_location_id',
    '    WHERE other_hr.instance_id = th.instance_id',
    '      AND other_loc.name <> tl.name',
    ')',
    'LIMIT 100',
]);

$repairedAliasLeakSql = $repairAliasLeak->invoke(null, $aliasLeakSql);
assertSameValue(
    strpos($repairedAliasLeakSql, 'other_loc.name <> tl.name') === false,
    true,
    'Generated SQL repair should replace only-holding anti-join alias references that leak outer scope.'
);
assertSameValue(
    strpos($repairedAliasLeakSql, 'other_hr.effective_location_id NOT IN (SELECT id FROM target_locations)') !== false,
    true,
    'Generated SQL repair should preserve only-holding semantics through target location ID exclusion.'
);

$reverseAliasLeakSql = str_replace(
    'other_loc.name <> tl.name',
    'tl.name NOT ILIKE other_loc.name',
    $aliasLeakSql
);
$repairedReverseAliasLeakSql = $repairAliasLeak->invoke(null, $reverseAliasLeakSql);
assertSameValue(
    strpos($repairedReverseAliasLeakSql, 'tl.name NOT ILIKE other_loc.name') === false,
    true,
    'Generated SQL repair should replace reverse only-holding anti-join alias references that leak outer scope.'
);
assertSameValue(
    strpos($repairedReverseAliasLeakSql, 'other_hr.effective_location_id NOT IN (SELECT id FROM target_locations)') !== false,
    true,
    'Generated SQL repair should preserve only-holding semantics for reverse alias-leak predicates.'
);

$singularCteAliasLeakSql = str_replace(
    ['target_locations', 'other_loc.name <> tl.name'],
    ['target_location', 'other_loc.name <> tl.name'],
    $aliasLeakSql
);
$repairedSingularCteAliasLeakSql = $repairAliasLeak->invoke(null, $singularCteAliasLeakSql);
assertSameValue(
    strpos($repairedSingularCteAliasLeakSql, 'other_loc.name <> tl.name') === false,
    true,
    'Generated SQL repair should replace only-holding alias leaks when the model uses singular target_location CTE naming.'
);
assertSameValue(
    strpos($repairedSingularCteAliasLeakSql, 'other_hr.effective_location_id NOT IN (SELECT id FROM target_location)') !== false,
    true,
    'Generated SQL repair should preserve only-holding semantics for singular target_location CTE naming.'
);

fwrite(STDOUT, "GeminiService inventory title repair test passed\n");
