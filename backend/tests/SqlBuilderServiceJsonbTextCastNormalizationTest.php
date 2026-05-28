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

fwrite(STDOUT, "SqlBuilderService jsonb text cast normalization test passed\n");