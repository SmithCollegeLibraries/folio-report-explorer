<?php

$geminiServicePath = __DIR__ . '/../services/GeminiService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';

foreach ([
    'GeminiService' => $geminiServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
    'FolioSchemaService' => $schemaServicePath,
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
            return $alias;
        }

        public static function warning($message, $category = null)
        {
        }

        public static function info($message, $category = null)
        {
        }
    }
}

Yii::$app = (object) [
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
    ],
];

require_once $schemaServicePath;
require_once $sqlBuilderPath;
require_once $geminiServicePath;

use app\services\GeminiService;
use app\exceptions\ExploratorySqlValidationException;

function assertGuardTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertGuardNotContains(string $needle, string $haystack, string $message): void
{
    if (stripos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected text: {$needle}\nSQL:\n{$haystack}\n");
        exit(1);
    }
}

function assertGuardThrows(ReflectionMethod $validator, string $sql, string $expectedText, string $message): void
{
    try {
        $validator->invoke(null, $sql);
    } catch (RuntimeException $e) {
        assertGuardTrue(
            stripos($e->getMessage(), $expectedText) !== false,
            $message . "\nExpected exception text: {$expectedText}\nActual: " . $e->getMessage()
        );
        return;
    }

    fwrite(STDERR, $message . "\nExpected RuntimeException, but no exception was thrown.\n");
    exit(1);
}

$generateSqlParameters = array_map(function (ReflectionParameter $parameter): string {
    return $parameter->getName();
}, (new ReflectionMethod(GeminiService::class, 'generateSql'))->getParameters());
assertGuardTrue(
    $generateSqlParameters === ['prompt', 'campus', 'forceLegacy', 'forceIntent', 'originalQuestion', 'resolvedFilters'],
    'generateSql must preserve Task 12 originalQuestion as the fifth argument and append resolvedFilters as the sixth.'
);

$postPreflightParameters = array_map(function (ReflectionParameter $parameter): string {
    return $parameter->getName();
}, (new ReflectionMethod(GeminiService::class, 'repairExploratorySqlAfterPreflight'))->getParameters());
assertGuardTrue(
    $postPreflightParameters === ['originalQuestion', 'campus', 'currentResult', 'preflightError', 'generationPrompt', 'resolvedFilters'],
    'Post-preflight repair must preserve the raw/generation prompt ordering and append resolved filters.'
);

$resolvedFilterValidator = new ReflectionMethod(GeminiService::class, 'validateResolvedReferenceSql');
$resolvedFilterValidator->setAccessible(true);
$resolvedFilters = [[
    'dimension' => 'library',
    'source_table' => 'inventory.loclibrary__t',
    'column' => 'name',
    'values' => ['SC Hillyer Art Library'],
]];
try {
    $resolvedFilterValidator->invoke(
        null,
        "SELECT item.id\n"
            . "FROM inventory.item__t item\n"
            . "JOIN inventory.location__t location ON location.id = item.effective_location_id\n"
            . "JOIN inventory.loclibrary__t library ON library.id = location.library_id\n"
            . "WHERE library.name = 'HC DVD'",
        $resolvedFilters
    );
    fwrite(STDERR, "Resolved-reference mismatches must fail before candidate acceptance.\n");
    exit(1);
} catch (ExploratorySqlValidationException $exception) {
    assertGuardTrue($exception->isRepairable(), 'Resolved-reference mismatch must be a repairable exploratory semantic error.');
    assertGuardTrue(
        $exception->getSafeCategory() === 'resolved_reference_filter_mismatch',
        'Resolved-reference mismatch must expose only the stable safe category.'
    );
}

$badSql = <<<'SQL'
WITH target_locations AS (
    SELECT DISTINCT id, name
    FROM inventory.location__t tl
    WHERE tl.name ILIKE '%SC Rare Book Collection Reference%'
        AND tl.code ILIKE 'SRBCR'
),
target_holdings AS (
    SELECT DISTINCT ih.instance_id, ih.id AS holdings_record_id, ih.call_number, ih.effective_location_id
    FROM inventory.holdings_record__t ih
    JOIN target_locations tl ON tl.id = ih.effective_location_id
)
SELECT DISTINCT
    th.call_number AS call_number,
    ii.hrid AS instance_number,
    ii.title
FROM target_holdings th
JOIN inventory.instance__t ii ON ii.id = th.instance_id
JOIN inventory.location__t tl ON tl.id = th.effective_location_id
JOIN inventory.loclibrary__t il ON tl.library_id = il.id
JOIN inventory.loccampus__t ic ON il.campus_id = ic.id
WHERE NOT EXISTS (
    SELECT 1
    FROM inventory.holdings_record__t other_hr
    WHERE other_hr.instance_id = th.instance_id
      AND other_hr.effective_location_id NOT IN (SELECT id FROM target_locations)
)
  AND il.name ILIKE '%SC Rare Book Collection Reference%'
LIMIT 100
SQL;

$repair = new ReflectionMethod(GeminiService::class, 'repairResolvedLocationPredicateMisuse');
$validator = new ReflectionMethod(GeminiService::class, 'validateNoResolvedLocationPredicateMisuse');
$repair->setAccessible(true);
$validator->setAccessible(true);

$repaired = $repair->invoke(null, $badSql);

assertGuardNotContains(
    "il.name ILIKE '%SC Rare Book Collection Reference%'",
    $repaired,
    'Resolved location values must not remain as loclibrary__t.name filters after repair.'
);
assertGuardNotContains(
    "tl.code ILIKE 'SRBCR'",
    $repaired,
    'Resolver metadata codes should not remain as extra target-location code filters when name already scopes the resolved location.'
);
assertGuardTrue(
    stripos($repaired, "tl.name ILIKE '%SC Rare Book Collection Reference%'") !== false,
    'Repair should preserve the resolved inventory.location__t.name predicate.'
);

$validator->invoke(null, $repaired);
assertGuardThrows(
    $validator,
    $badSql,
    'resolved location value was applied to inventory.loclibrary__t.name',
    'Validator should fail closed when a resolved location value is still applied to the library table.'
);

$wrongAliasSql = <<<'SQL'
WITH target_locations AS (
    SELECT DISTINCT id, name
    FROM inventory.location__t tl
    WHERE tl.name ILIKE '%HC Reference%'
),
target_holdings AS (
    SELECT DISTINCT ih.instance_id, ih.id AS holdings_record_id, ih.call_number, ih.effective_location_id
    FROM inventory.holdings_record__t ih
    JOIN target_locations tl ON tl.id = ih.effective_location_id
)
SELECT DISTINCT
    ii.title
FROM target_holdings th
JOIN inventory.instance__t ii ON ii.id = th.instance_id
JOIN inventory.location__t tl ON tl.id = th.effective_location_id
JOIN inventory.loclibrary__t il ON tl.library_id = il.id
JOIN inventory.loccampus__t ic ON il.campus_id = ic.id
WHERE NOT EXISTS (
    SELECT 1
    FROM inventory.holdings_record__t other_hr
    WHERE other_hr.instance_id = th.instance_id
      AND other_hr.effective_location_id NOT IN (SELECT id FROM target_locations)
)
  AND il.name ILIKE '%MRBC%'
LIMIT 100
SQL;

$wrongAliasRepaired = $repair->invoke(null, $wrongAliasSql);
assertGuardNotContains(
    "tl.name ILIKE '%HC Reference%'",
    $wrongAliasRepaired,
    'MRBC Reference generated SQL must not keep the model-invented HC Reference location.'
);
assertGuardNotContains(
    "il.name ILIKE '%MRBC%'",
    $wrongAliasRepaired,
    'MRBC must not remain as an inventory.loclibrary__t.name filter.'
);
assertGuardTrue(
    stripos($wrongAliasRepaired, "tl.name ILIKE '%SC Rare Book Collection Reference%'") !== false,
    'MRBC Reference generated SQL should repair the target location to SC Rare Book Collection Reference.'
);
$validator->invoke(null, $wrongAliasRepaired);

$partialLibraryLeakSql = <<<'SQL'
SELECT DISTINCT
    inst.title
FROM inventory.item__t ii
JOIN inventory.holdings_record__t hr ON ii.holdings_record_id = hr.id
JOIN inventory.instance__t inst ON hr.instance_id = inst.id
JOIN inventory.location__t loc ON ii.effective_location_id = loc.id
JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id
WHERE loc.name ILIKE '%SC Josten Treasure%'
  AND lib.name ILIKE '%Josten Treasure%'
LIMIT 100
SQL;

$partialLibraryLeakRepaired = $repair->invoke(null, $partialLibraryLeakSql);
assertGuardNotContains(
    "lib.name ILIKE '%Josten Treasure%'",
    $partialLibraryLeakRepaired,
    'Repair should remove partial resolved location values from loclibrary__t.name filters.'
);
$validator->invoke(null, $partialLibraryLeakRepaired);
assertGuardThrows(
    $validator,
    $partialLibraryLeakSql,
    'resolved location value was applied to inventory.loclibrary__t.name',
    'Validator should fail closed when a partial resolved location value is applied to the library table.'
);

fwrite(STDOUT, "GeminiService resolved location guard test passed\n");
