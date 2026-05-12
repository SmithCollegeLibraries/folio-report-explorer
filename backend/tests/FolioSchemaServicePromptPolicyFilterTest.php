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
    'db' => new class {
        public function createCommand()
        {
            throw new Exception('DB not available in prompt policy filter test harness.');
        }
    },
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

fwrite(STDOUT, "FolioSchemaService prompt policy filter test passed\n");