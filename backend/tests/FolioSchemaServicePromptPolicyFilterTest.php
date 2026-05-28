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

assertFalseValue(
    strpos($schemaContext, 'marctab.mt300') !== false,
    'Schema context should not include marctab-specific prompt guidance when the marctab schema is blocked by table policy.'
);
assertFalseValue(
    strpos($schemaContext, 'Use marctab.mtNNN') !== false,
    'Schema context should not instruct the model to use marctab tables when those queries will be blocked.'
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
assertContainsText(
    'parsed_record__content::text NOT ILIKE',
    $schemaContext,
    'Schema context should cast parsed_record__content to text before NOT ILIKE when the live schema type is jsonb.'
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
    'I am looking for a report of all holdings with the location "SC Rare Book Collection Reference". For each book, I also want to see which other institutions in the 5 Colleges also hold the same title.'
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
    'jsonb_array_elements(sr.parsed_record__content',
    $marc035Context,
    'MARC field prompts should show how to extract field/subfield values from SRS parsed_record__content.'
);
assertContainsText(
    "field_data->>'ind2' = '<requested second indicator>'",
    $marc035Context,
    'MARC field prompts should explain how to preserve requested indicator constraints without hard-coding a tag.'
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
    "tag.tag = '245'",
    $marc245Context,
    'MARC field prompts should preserve the requested tag rather than relying on 035-specific handling.'
);

$marc6xxContext = FolioSchemaService::buildSchemaContext(
    'For SC Internet records, count records that have MARC 6xx fields.'
);

assertContainsText(
    "tag.tag ~ '^6[0-9][0-9]$'",
    $marc6xxContext,
    'MARC field-family prompts should preserve 6xx-style families without relying on a specific tag.'
);

$mrbcDeweyContext = FolioSchemaService::buildSchemaContext(
    'Show me every bibliographic record in MRBC with a Dewey classification number.'
);

assertContainsText(
    'MRBC means SC Rare Book Collection',
    $mrbcDeweyContext,
    'MRBC prompts should include the local alias that resolves MRBC to the SC Rare Book Collection location.'
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

fwrite(STDOUT, "FolioSchemaService prompt policy filter test passed\n");