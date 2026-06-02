<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$clarificationServicePath = __DIR__ . '/../services/ClarificationService.php';

if (!file_exists($schemaServicePath) || !file_exists($clarificationServicePath)) {
    fwrite(STDERR, "Required service is missing for FolioSchemaService prompt policy test\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            if ($alias === '@app/data/data_patterns.json') {
                return __DIR__ . '/../data/data_patterns.json';
            }
            if ($alias === '@app/data/location_reference_cache.json') {
                return __DIR__ . '/../data/location_reference_cache.json';
            }
            if ($alias === '@app/data/table_mapping_cache.json') {
                return sys_get_temp_dir() . '/folio_report_explorer_prompt_policy_table_mapping_cache.json';
            }
            if ($alias === '@app/data/column_cache.json') {
                return sys_get_temp_dir() . '/folio_report_explorer_prompt_policy_column_cache.json';
            }
            if ($alias === '@app/data/subtable_cache.json') {
                return sys_get_temp_dir() . '/folio_report_explorer_prompt_policy_subtable_cache.json';
            }

            return $alias;
        }

        public static function warning($message)
        {
        }
    }
}

$unavailableFolioDb = new class {
    public function quoteValue($value)
    {
        return "'" . str_replace("'", "''", (string)$value) . "'";
    }

    public function createCommand($sql = '')
    {
        return new class((string)$sql) {
            private $sql;

            public function __construct(string $sql)
            {
                $this->sql = $sql;
            }

            public function queryAll()
            {
                if (strpos($this->sql, 'information_schema.tables') !== false) {
                    return [
                        ['table_schema' => 'folio_source_record', 'table_name' => 'records__t'],
                    ];
                }

                if (strpos($this->sql, 'information_schema.columns') !== false) {
                    return [
                        [
                            'table_schema' => 'folio_source_record',
                            'table_name' => 'records__t',
                            'column_name' => 'parsed_record__content',
                            'data_type' => 'jsonb',
                            'character_maximum_length' => null,
                            'is_nullable' => 'YES',
                            'column_default' => null,
                            'ordinal_position' => 1,
                        ],
                        [
                            'table_schema' => 'folio_source_record',
                            'table_name' => 'records__t',
                            'column_name' => 'external_ids_holder__instance_id',
                            'data_type' => 'text',
                            'character_maximum_length' => null,
                            'is_nullable' => 'YES',
                            'column_default' => null,
                            'ordinal_position' => 2,
                        ],
                        [
                            'table_schema' => 'folio_source_record',
                            'table_name' => 'records__t',
                            'column_name' => 'deleted',
                            'data_type' => 'boolean',
                            'character_maximum_length' => null,
                            'is_nullable' => 'YES',
                            'column_default' => null,
                            'ordinal_position' => 3,
                        ],
                    ];
                }

                throw new Exception('FOLIO DB not available in prompt policy filter test harness.');
            }
        };
    }
};

Yii::$app = (object) [
    'cache' => null,
    'db' => new class {
        public function createCommand()
        {
            throw new Exception('DB not available in prompt policy filter test harness.');
        }
    },
    'folioDb' => $unavailableFolioDb,
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
    ],
];

require_once $clarificationServicePath;
require_once $schemaServicePath;

use app\services\FolioSchemaService;

function assertFalseValue(bool $condition, string $message): void
{
    if ($condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

$schemaContext = FolioSchemaService::buildSchemaContext(
    'Show all FOLIO instances of Smith College books that are missing a MARC 300 field, including their call number and current location.'
);

assertContainsText(
    'marctab.mt300',
    $schemaContext,
    'Schema context should steer exact MARC field presence checks to the per-field marctab table.'
);
assertContainsText(
    'parsed_record__content:jsonb',
    $schemaContext,
    'Schema context should surface the live parsed_record__content type from the column cache.'
);
assertFalseValue(
    preg_match('/parsed_record__content\s+NOT\s+ILIKE/i', $schemaContext) === 1,
    'Schema context should not suggest direct NOT ILIKE on parsed_record__content when the live schema type is jsonb.'
);
assertFalseValue(
    strpos($schemaContext, 'parsed_record__content::text NOT ILIKE') !== false,
    'Schema context should not steer MARC field presence checks to parsed_record__content text scans.'
);
assertFalseValue(
    strpos($schemaContext, "state = 'ACTUAL'") !== false,
    'Schema context should not suggest records__t.state = ACTUAL when the live source-record schema has no state column.'
);
assertContainsText(
    'COALESCE(deleted, false) = false',
    $schemaContext,
    'Schema context should surface the live deleted-flag filter when records__t has no state column.'
);

$rareHoldingsContext = FolioSchemaService::buildSchemaContext(
    'I am looking for a report of all holdings with the location "SC Rare Book Collection Reference". For each book, I also want to see which other institutions in the 5 Collegse also hold the same title.'
);

assertContainsText(
    'same-title holdings overlap',
    $rareHoldingsContext,
    'Same-title holdings prompts should retrieve performance guidance for Five Colleges holdings overlap reports.'
);
assertContainsText(
    'target_rare_titles AS MATERIALIZED',
    $rareHoldingsContext,
    'Same-title holdings prompts should retrieve the target-first CTE example instead of broad non-Smith materialization.'
);
assertContainsText(
    'other_inst.title_key = target_rare_titles.title_key',
    $rareHoldingsContext,
    'Same-title holdings prompts should retrieve an equality title-key join, not an OR/correlated-subquery join.'
);
assertContainsText(
    'Treat "5 Collegse" as "Five Colleges"',
    $rareHoldingsContext,
    'Five Colleges typo variants should be normalized in prompt guidance.'
);

$specialCollectionsBrowsingContext = FolioSchemaService::buildSchemaContext(
    'List the records in the SC Special Collections Browsing collection, with their HRID, Call Number Prefix, Call Number, Author, and Title.'
);

assertContainsText(
    '--- Resolved Location References ---',
    $specialCollectionsBrowsingContext,
    'Collection-name prompts should include resolved location reference matches from the local location cache.'
);
assertContainsText(
    "inventory.location__t.name = 'SC Special Collections Browsing'",
    $specialCollectionsBrowsingContext,
    'SC Special Collections Browsing should resolve to inventory.location__t.name, not inventory.loclibrary__t.name.'
);
assertContainsText(
    'For resolved inventory.location__t matches, filter the location alias, for example loc.name ILIKE',
    $specialCollectionsBrowsingContext,
    'Resolved location matches should explicitly instruct the model to filter loc.name rather than lib.name.'
);

$marc035Context = FolioSchemaService::buildSchemaContext(
    'Find all of the records that have a location of SC Internet where the holdings are only Smith College. Summarize the marc field 035 9 subfield a and count how many records are tied to the value in 035 9.'
);

assertFalseValue(
    strpos($marc035Context, 'users.users__t') !== false,
    'MARC/source-record prompts should not include blocked user-table guidance.'
);
assertContainsText(
    '--- MARC Field Source Record Rule ---',
    $marc035Context,
    'MARC field prompts should include a generic source-record extraction rule.'
);
assertContainsText(
    'JOIN marctab.mt035 m ON m.instance_hrid = ti.hrid',
    $marc035Context,
    'MARC field prompts should use the per-field marctab table instead of the slow folio_source_record.marctab view.'
);
assertFalseValue(
    strpos($marc035Context, 'JOIN folio_source_record.marctab') !== false,
    'MARC field prompts should not steer exact-tag queries to the slow folio_source_record.marctab view.'
);
assertFalseValue(
    strpos($marc035Context, 'jsonb_array_elements(sr.parsed_record__content') !== false,
    'MARC field prompts should not steer generated SQL toward expensive parsed_record__content JSON expansion.'
);
assertFalseValue(
    strpos($marc035Context, 'subfield.subfield_obj ?') !== false,
    'MARC field prompt guidance should not use the PostgreSQL ? JSONB operator because PDO treats it as a bind placeholder.'
);
assertContainsText(
    "m.ind2 = '9'",
    $marc035Context,
    'MARC field prompts should interpret shorthand 035 9 as a second-indicator constraint.'
);
assertContainsText(
    "m.sf = 'a'",
    $marc035Context,
    'MARC field prompts should preserve the requested subfield in a marctab sf predicate.'
);
assertContainsText(
    'm.content AS marc_value',
    $marc035Context,
    'MARC field prompts should aggregate row-expanded MARC content values.'
);
assertContainsText(
    'the table already restricts rows to MARC field 035',
    $marc035Context,
    'MARC field prompts should explain that exact per-field tables do not need m.field predicates.'
);
assertContainsText(
    "For 'holdings are only Smith College'",
    $marc035Context,
    'MARC field prompts should keep holdings-only campus scoping guidance.'
);

$marc245Context = FolioSchemaService::buildSchemaContext(
    'For SC Internet records, summarize MARC field 245 1 subfield a and count records by that value.'
);

assertContainsText(
    '--- MARC Field Source Record Rule ---',
    $marc245Context,
    'Non-035 MARC field prompts should use the same generic source-record extraction rule.'
);
assertContainsText(
    'JOIN marctab.mt245 m ON m.instance_hrid = ti.hrid',
    $marc245Context,
    'MARC field prompts should choose the per-field table for the requested tag rather than relying on 035-specific handling.'
);

$marc6xxContext = FolioSchemaService::buildSchemaContext(
    'For SC Internet records, count records that have MARC 6xx fields.'
);

assertContainsText(
    "m.field ~ '^6[0-9][0-9]$'",
    $marc6xxContext,
    'MARC field-family prompts should preserve 6xx-style families without relying on a specific tag.'
);

$mrbcDeweyContext = FolioSchemaService::buildSchemaContext(
    'Show me every bibliographic record in MRBC Reference with a Dewey classification number.'
);

assertContainsText(
    "MRBC Reference means inventory.location__t.name = 'SC Rare Book Collection Reference'",
    $mrbcDeweyContext,
    'MRBC Reference prompts should resolve to the reference rare book location.'
);
assertContainsText(
    'filter inventory.location__t',
    $mrbcDeweyContext,
    'MRBC prompts should direct the model to filter location, not instance HRID.'
);
assertContainsText(
    'Do not filter inventory.instance__t.hrid for MRBC',
    $mrbcDeweyContext,
    'MRBC prompts should explicitly reject the mistaken instance HRID-prefix interpretation.'
);
assertFalseValue(
    strpos($mrbcDeweyContext, 'Local alias: MRBC means SC Rare Book Collection;') !== false,
    'MRBC Reference prompts should not include contradictory base MRBC alias guidance.'
);
assertContainsText(
    'Titles live on inventory.instance__t.title; inventory.item__t has no title column.',
    $mrbcDeweyContext,
    'Inventory title prompts should remind the model to select inst.title rather than inventing ii.title.'
);
assertContainsText(
    'If a query uses GROUP BY, every selected non-aggregate expression must also appear in GROUP BY.',
    $mrbcDeweyContext,
    'Inventory title prompts should include grouped-query correctness guidance.'
);
assertContainsText(
    "Use ct.name = 'Dewey'",
    $mrbcDeweyContext,
    'Dewey classification-number prompts should use the live inventory.classification_type__t label Dewey.'
);
assertContainsText(
    'inventory.classification_type__t.name values: UDC, LC, LC (local), NLM, SUDOC, National Agriculture Library, GDC, Canadian Classification, Additional Dewey, Dewey',
    $mrbcDeweyContext,
    'Classification prompts should include the known inventory.classification_type__t naming convention values.'
);

$mrbcOnlyLocationContext = FolioSchemaService::buildSchemaContext(
    'Please provide a list of titles and corresponding instance and call numbers with the location MRBC Reference Collection containing only records for which the MRBC Reference Collection is the only holding location in the 5 Colleges'
);

assertContainsText(
    "For 'only holding location in the Five Colleges'",
    $mrbcOnlyLocationContext,
    'Only-location MRBC prompts should include a concrete NOT EXISTS holdings-location pattern.'
);
assertContainsText(
    'Do not join inventory.item__t unless the user asks for item-level fields such as barcode',
    $mrbcOnlyLocationContext,
    'Title, instance number, and holdings call number prompts should avoid item joins that change row cardinality.'
);
assertContainsText(
    'inventory.instance__t.hrid AS instance_number',
    $mrbcOnlyLocationContext,
    'Instance-number prompts should steer to instance__t.hrid rather than the UUID id.'
);
assertContainsText(
    'WITH target_location AS MATERIALIZED',
    $mrbcOnlyLocationContext,
    'Only-location prompts should include a reusable canonical CTE shape for consistent SQL generation.'
);
assertContainsText(
    'NOT EXISTS (',
    $mrbcOnlyLocationContext,
    'Only-location prompts should steer exclusion through NOT EXISTS rather than a row-count HAVING clause.'
);

fwrite(STDOUT, "FolioSchemaService prompt policy filter test passed\n");
