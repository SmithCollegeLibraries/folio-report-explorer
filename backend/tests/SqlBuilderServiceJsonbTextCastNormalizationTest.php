<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderServicePath = __DIR__ . '/../services/SqlBuilderService.php';

if (!file_exists($schemaServicePath) || !file_exists($sqlBuilderServicePath)) {
    fwrite(STDERR, "Required service files are missing.\n");
    exit(1);
}

define('SQLBUILDER_JSONB_TABLE_MAPPING_CACHE', sys_get_temp_dir() . '/folio_report_explorer_sqlbuilder_table_mapping_cache.json');
define('SQLBUILDER_JSONB_COLUMN_CACHE', sys_get_temp_dir() . '/folio_report_explorer_sqlbuilder_column_cache.json');

function refreshSchemaCacheFixture(string $sourcePath, string $targetPath): void
{
    $cache = json_decode((string)file_get_contents($sourcePath), true);
    if (!is_array($cache)) {
        fwrite(STDERR, "Invalid schema cache fixture: {$sourcePath}\n");
        exit(1);
    }
    $cache['_discovered_at'] = date('c');
    file_put_contents($targetPath, json_encode($cache, JSON_PRETTY_PRINT));
}

refreshSchemaCacheFixture(__DIR__ . '/../data/table_mapping_cache.json', SQLBUILDER_JSONB_TABLE_MAPPING_CACHE);
refreshSchemaCacheFixture(__DIR__ . '/../data/column_cache.json', SQLBUILDER_JSONB_COLUMN_CACHE);

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            if ($alias === '@app/data/table_mapping_cache.json') {
                return SQLBUILDER_JSONB_TABLE_MAPPING_CACHE;
            }
            if ($alias === '@app/data/column_cache.json') {
                return SQLBUILDER_JSONB_COLUMN_CACHE;
            }

            return $alias;
        }
    }
}

Yii::$app = (object) [
    'cache' => null,
    'params' => [],
];

require_once $schemaServicePath;
require_once $sqlBuilderServicePath;

use app\services\SqlBuilderService;

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

function assertFalseValue(bool $condition, string $message): void
{
    if ($condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertThrowsMessage(callable $callback, string $expectedText, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        if (strpos($e->getMessage(), $expectedText) !== false) {
            return;
        }

        fwrite(STDERR, $message . "\nExpected exception containing: {$expectedText}\nActual: {$e->getMessage()}\n");
        exit(1);
    }

    fwrite(STDERR, $message . "\nExpected InvalidArgumentException was not thrown.\n");
    exit(1);
}

$sql = <<<'SQL'
SELECT fsr.parsed_record__content
FROM folio_source_record.records__t AS fsr
LEFT JOIN inventory.instance__t AS inst ON inst.id = fsr.external_ids_holder__instance_id
WHERE fsr.state = 'ACTUAL'
  AND fsr.parsed_record__content NOT ILIKE '%"300"%'
  AND fsr.parsed_record__content::text ILIKE '%"650"%'
SQL;

$normalized = SqlBuilderService::normalizeForExecution($sql);

assertContainsText(
    'fsr.parsed_record__content::text NOT ILIKE',
    $normalized,
    'normalizeForExecution should cast parsed_record__content to text before NOT ILIKE when the live schema type is jsonb.'
);
assertContainsText(
    'COALESCE(fsr.deleted, false) = false',
    $normalized,
    'normalizeForExecution should replace stale source-record state filters with the live deleted-flag filter when records__t has no state column.'
);
assertFalseValue(
    preg_match('/parsed_record__content::text::text/i', $normalized) === 1,
    'normalizeForExecution should not duplicate an existing ::text cast.'
);
assertFalseValue(
    strpos($normalized, "fsr.state = 'ACTUAL'") !== false,
    'normalizeForExecution should remove stale source-record state filters when records__t has no state column.'
);

$aliasSql = <<<'SQL'
WITH items AS MATERIALIZED (
    SELECT
        rec.parsed_record__content AS marc_content
    FROM folio_source_record.records__t AS rec
    WHERE COALESCE(rec.deleted, false) = false
)
SELECT marc_content
FROM items
WHERE marc_content NOT ILIKE '%"6"%'
SQL;

$aliasNormalized = SqlBuilderService::normalizeForExecution($aliasSql);

assertContainsText(
        'rec.parsed_record__content::text AS marc_content',
        $aliasNormalized,
        'normalizeForExecution should cast parsed_record__content to text before aliasing it when the alias is later used in NOT ILIKE.'
);
assertContainsText(
        'WHERE marc_content NOT ILIKE',
        $aliasNormalized,
        'normalizeForExecution should preserve downstream alias predicates after casting the source projection.'
);

$jsonbExistsSql = <<<'SQL'
SELECT subfield.subfield_obj->>'a' AS marc_value
FROM folio_source_record.records__t AS sr
CROSS JOIN LATERAL jsonb_array_elements(sr.parsed_record__content->'fields') AS marc_field(field_obj)
CROSS JOIN LATERAL jsonb_each(marc_field.field_obj) AS tag(tag, field_data)
CROSS JOIN LATERAL jsonb_array_elements(tag.field_data->'subfields') AS subfield(subfield_obj)
WHERE subfield.subfield_obj ? 'a'
SQL;

$jsonbExistsNormalized = SqlBuilderService::normalizeForExecution($jsonbExistsSql);

assertContainsText(
    "jsonb_exists(subfield.subfield_obj, 'a')",
    $jsonbExistsNormalized,
    'normalizeForExecution should replace JSONB key-exists ? operators with jsonb_exists before PDO/preflight sees them.'
);
assertFalseValue(
    strpos($jsonbExistsNormalized, "subfield.subfield_obj ? 'a'") !== false,
    'normalizeForExecution should remove JSONB ? operators that PDO treats as positional placeholders.'
);

$onlyHoldingAliasLeakSql = <<<'SQL'
WITH target_location AS MATERIALIZED (
    SELECT id, name
    FROM inventory.location__t
    WHERE name ILIKE 'SC Rare Book Collection Reference'
),
target_holdings AS MATERIALIZED (
    SELECT DISTINCT hr.instance_id, hr.call_number, hr.effective_location_id
    FROM inventory.holdings_record__t hr
    JOIN target_location tl ON tl.id = hr.effective_location_id
)
SELECT inst.title
FROM target_holdings
JOIN inventory.instance__t inst ON inst.id = target_holdings.instance_id
WHERE NOT EXISTS (
    SELECT 1
    FROM inventory.holdings_record__t other_hr
    JOIN inventory.location__t other_loc ON other_loc.id = other_hr.effective_location_id
    WHERE other_hr.instance_id = target_holdings.instance_id
      AND other_loc.name <> tl.name
)
SQL;

$onlyHoldingAliasLeakNormalized = SqlBuilderService::normalizeForExecution($onlyHoldingAliasLeakSql);

assertFalseValue(
    strpos($onlyHoldingAliasLeakNormalized, 'other_loc.name <> tl.name') !== false,
    'normalizeForExecution should remove stale only-holding location alias comparisons before preflight validation.'
);
assertContainsText(
    'other_hr.effective_location_id NOT IN (SELECT id FROM target_location)',
    $onlyHoldingAliasLeakNormalized,
    'normalizeForExecution should replace stale only-holding alias comparisons with target location ID exclusion.'
);

$marctabSql = <<<'SQL'
WITH target_instances AS MATERIALIZED (
    SELECT inst.id, inst.hrid
    FROM inventory.instance__t AS inst
)
SELECT m.content AS marc_value, COUNT(DISTINCT ti.id) AS record_count
FROM target_instances AS ti
JOIN marctab.mt035 AS m ON m.instance_hrid = ti.hrid
WHERE m.ind2 = '9'
  AND m.sf = 'a'
GROUP BY m.content
SQL;

SqlBuilderService::validateTablePolicy($marctabSql);

$marctabSchemaSql = <<<'SQL'
SELECT m.content
FROM marctab.some_other_table AS m
SQL;

assertThrowsMessage(
    static function () use ($marctabSchemaSql): void {
        SqlBuilderService::validateTablePolicy($marctabSchemaSql);
    },
    'Query references blocked schema: marctab',
    'SQL policy should only allow the known per-field marctab.mtNNN access path.'
);

$expensiveMarcJsonSql = <<<'SQL'
SELECT subfield.subfield_obj->>'a' AS marc_value
FROM folio_source_record.records__t AS sr
CROSS JOIN LATERAL jsonb_array_elements(sr.parsed_record__content->'fields') AS marc_field(field_obj)
CROSS JOIN LATERAL jsonb_each(marc_field.field_obj) AS tag(tag, field_data)
CROSS JOIN LATERAL jsonb_array_elements(tag.field_data->'subfields') AS subfield(subfield_obj)
WHERE tag.tag = '035'
SQL;

assertThrowsMessage(
    static function () use ($expensiveMarcJsonSql): void {
        SqlBuilderService::validateTablePolicy($expensiveMarcJsonSql);
    },
    'Use marctab.mtNNN per-field tables for MARC field/subfield extraction',
    'Generated MARC field extraction should not be allowed to scan parsed_record__content JSON.'
);

fwrite(STDOUT, "SqlBuilderService jsonb text cast normalization test passed\n");
