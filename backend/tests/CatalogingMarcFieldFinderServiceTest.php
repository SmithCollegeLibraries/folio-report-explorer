<?php

namespace yii\db {
    class ActiveRecord
    {
        private $attributes = [];

        public function __get($name) { return $this->attributes[$name] ?? null; }
        public function __set($name, $value): void { $this->attributes[$name] = $value; }
        public function hasAttribute($name): bool { return true; }
    }
}

namespace {
    require_once __DIR__ . '/../models/ReportTemplate.php';
    require_once __DIR__ . '/../services/CatalogingMarcFieldFinderService.php';

    use app\exceptions\ReportParameterValidationException;
    use app\models\ReportTemplate;
    use app\services\CatalogingMarcFieldFinderService;

    function finderCompilerFail(string $message): void
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function finderCompilerSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            finderCompilerFail($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    function finderCompilerContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) === false) {
            finderCompilerFail($message . "\nMissing: {$needle}");
        }
    }

    function finderCompilerNotContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) !== false) {
            finderCompilerFail($message . "\nUnexpected: {$needle}");
        }
    }

    function finderCompilerFieldError(ReportTemplate $report, array $inputs, $db, string $field): void
    {
        try {
            CatalogingMarcFieldFinderService::build($report, $inputs, $db);
        } catch (ReportParameterValidationException $exception) {
            finderCompilerSame([$field], array_keys($exception->getFieldErrors()), "Only {$field} should receive a field error.");
            return;
        } catch (\Throwable $exception) {
            finderCompilerFail("{$field} should raise ReportParameterValidationException, got " . get_class($exception) . ': ' . $exception->getMessage());
        }
        finderCompilerFail("{$field} should be rejected.");
    }

    function finderCompilerInvalid(callable $callback, string $message, ?string $expectedMessage = null): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException $exception) {
            if ($exception instanceof ReportParameterValidationException) {
                finderCompilerFail($message . '\nTemplate drift must not be reported as a user field error.');
            }
            if ($expectedMessage !== null) {
                finderCompilerSame($expectedMessage, $exception->getMessage(), $message);
            }
            return;
        }
        finderCompilerFail($message);
    }

    final class FinderCompilerFakeCommand
    {
        private $sql;
        private $params;
        private $db;

        public function __construct(string $sql, array $params, FinderCompilerFakeDb $db)
        {
            $this->sql = $sql;
            $this->params = $params;
            $this->db = $db;
        }

        public function queryAll(): array
        {
            if (strpos($this->sql, 'FROM inventory.location__t') === false) {
                throw new \RuntimeException('Unexpected queryAll lookup: ' . $this->sql);
            }
            if (array_values($this->params) !== $this->db->expectedLocationIds) {
                throw new \RuntimeException('Location lookup must bind every normalized location UUID.');
            }
            return $this->db->locations;
        }

        public function queryOne(): array
        {
            if (strpos($this->sql, 'to_regclass') === false) {
                throw new \RuntimeException('Unexpected queryOne lookup: ' . $this->sql);
            }
            $name = $this->params[':table_name'] ?? '';
            return ['to_regclass' => $this->db->tables[$name] ?? null];
        }
    }

    final class FinderCompilerFakeDb
    {
        public $expectedLocationIds = [
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
        ];
        public $locations = [
            ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Main Library', 'code' => 'MAIN'],
            ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Internet', 'code' => 'ONLINE'],
        ];
        public $tables = ['marctab.mt035' => 'marctab.mt035'];

        public function createCommand(string $sql, array $params = []): FinderCompilerFakeCommand
        {
            return new FinderCompilerFakeCommand($sql, $params, $this);
        }
    }

    function finderCompilerSeed(): array
    {
        static $seed;
        if ($seed !== null) {
            return $seed;
        }
        $migration = (string) file_get_contents(__DIR__ . '/../../mysql/migrations/043_cataloging_marc_field_finder.sql');
        if (preg_match(
            "/VALUES \(\n  'marc-field-indicator-content-finder',\n  '([^']+)',.*?\n  '(WITH target_instances AS MATERIALIZED \(.*?LIMIT 100001)',\n  '(\\[.*?\\])',\n  'folio',\n  '(\\{.*?\\})',\n  100000,\n  1,\n  'manual'\n\)/s",
            $migration,
            $matches
        ) !== 1) {
            throw new \RuntimeException('Could not load the canonical MARC finder fixture.');
        }
        $seed = [
            'slug' => CatalogingMarcFieldFinderService::REPORT_SLUG,
            'name' => $matches[1],
            'category' => 'cataloging',
            'sql_template' => str_replace("''", "'", $matches[2]),
            'parameters' => str_replace("''", "'", $matches[3]),
            'data_source' => 'folio',
            'execution_config' => str_replace("''", "'", $matches[4]),
            'default_limit' => 100000,
            'is_active' => 1,
            'created_by' => 'manual',
        ];
        return $seed;
    }

    function finderCompilerReport(array $overrides = []): ReportTemplate
    {
        $report = new ReportTemplate();
        foreach (array_merge(finderCompilerSeed(), $overrides) as $attribute => $value) {
            $report->$attribute = $value;
        }
        return $report;
    }

    function finderCompilerInputs(array $overrides = []): array
    {
        return array_merge([
            'locationIds' => '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222',
            'locationBasis' => 'effective_item',
            'marcTag' => '035',
            'occurrenceCondition' => 'has',
            'firstIndicator' => 'blank',
            'secondIndicator' => 'char:9',
            'subfieldCode' => 'a',
            'contentRule' => 'not_begins',
            'searchValue' => '(SCTFEBA)',
            'caseExact' => 'true',
        ], $overrides);
    }

    $db = new FinderCompilerFakeDb();
    $report = finderCompilerReport();
    $compiled = CatalogingMarcFieldFinderService::build($report, finderCompilerInputs(), $db);

    finderCompilerSame(2, substr_count($compiled['sql'], 'marctab.mt035'), 'Both MARC table tokens must resolve to the same selected relation.');
    finderCompilerSame(1, substr_count($compiled['sql'], 'FROM inventory.item__t item'), 'Exactly one location fragment must be resolved.');
    finderCompilerContains('FROM marctab.mt035 missing_row', $compiled['sql'], 'Missing mode must probe the physical table directly.');
    finderCompilerContains('NOT EXISTS', $compiled['sql'], 'Missing mode must use a correlated NOT EXISTS probe.');
    finderCompilerNotContains('{{', $compiled['sql'], 'Compiled SQL must not retain structural tokens.');
    finderCompilerNotContains(' LIKE ', $compiled['sql'], 'Search text must not use wildcard LIKE semantics.');
    finderCompilerSame('(SCTFEBA)', $compiled['params'][':searchValue'], 'Search text must remain a literal bound value.');
    finderCompilerSame('11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222', $compiled['params'][':locationIds'], 'Location IDs must remain one bound value.');
    finderCompilerSame('035', $compiled['marcTag'], 'The normalized MARC tag must be returned.');
    finderCompilerSame(2, count($compiled['locations']), 'All selected locations must be returned.');
    finderCompilerSame('2 Locations', $compiled['location']['name'], 'Multi-location summary metadata must be returned.');

    $backslash = CatalogingMarcFieldFinderService::build(
        $report,
        finderCompilerInputs(['firstIndicator' => 'char:\\']),
        $db
    );
    finderCompilerSame('blank', $backslash['params'][':firstIndicator'], 'A backslash indicator must normalize to blank.');
    $whitespace = CatalogingMarcFieldFinderService::build(
        $report,
        finderCompilerInputs(['firstIndicator' => 'char: ']),
        $db
    );
    finderCompilerSame('blank', $whitespace['params'][':firstIndicator'], 'A whitespace indicator must normalize to blank.');

    $literalSearch = "%_'\\quoted";
    $literal = CatalogingMarcFieldFinderService::build(
        $report,
        finderCompilerInputs(['searchValue' => $literalSearch]),
        $db
    );
    finderCompilerSame($literalSearch, $literal['params'][':searchValue'], 'Wildcard punctuation and quotes must remain literal bound search text.');

    foreach ([
        'marcTag' => '000',
        'locationBasis' => 'permanent_holdings',
        'occurrenceCondition' => 'either',
        'firstIndicator' => 'char:12',
        'secondIndicator' => 'char:xy',
        'subfieldCode' => '$',
        'contentRule' => 'regex',
        'caseExact' => 'True',
    ] as $field => $value) {
        finderCompilerFieldError($report, finderCompilerInputs([$field => $value]), $db, $field);
    }
    finderCompilerFieldError($report, finderCompilerInputs(['searchValue' => '']), $db, 'searchValue');
    finderCompilerFieldError(
        $report,
        finderCompilerInputs(['contentRule' => 'blank', 'searchValue' => 'unexpected']),
        $db,
        'searchValue'
    );
    finderCompilerFieldError($report, finderCompilerInputs(['locationIds' => 'not-a-uuid']), $db, 'locationIds');

    $nonText = CatalogingMarcFieldFinderService::build(
        $report,
        finderCompilerInputs(['contentRule' => 'blank', 'searchValue' => '', 'caseExact' => 'true']),
        $db
    );
    finderCompilerSame('false', $nonText['params'][':caseExact'], 'Non-text content rules must normalize caseExact to false.');

    foreach ([
        'slug' => 'other-report',
        'sql_template' => str_replace('LIMIT 100001', 'LIMIT 100000', $report->sql_template),
        'parameters' => '[]',
        'execution_config' => '{}',
    ] as $attribute => $value) {
        finderCompilerInvalid(
            function () use ($attribute, $value, $db) {
                CatalogingMarcFieldFinderService::build(finderCompilerReport([$attribute => $value]), finderCompilerInputs(), $db);
            },
            "Drifted {$attribute} must fail closed."
        );
    }

    $definitions = json_decode($report->parameters, true);
    $definitions[] = ['name' => 'marcTagExtra', 'type' => 'text'];
    finderCompilerInvalid(
        function () use ($definitions, $db) {
            CatalogingMarcFieldFinderService::build(
                finderCompilerReport(['parameters' => json_encode($definitions)]),
                finderCompilerInputs(),
                $db
            );
        },
        'Pairwise prefix collisions must fail with the dedicated integrity error.',
        'MARC finder parameter names must not prefix-collide.'
    );

    $compiledValidator = new \ReflectionMethod(CatalogingMarcFieldFinderService::class, 'assertCompiledSql');
    @$compiledValidator->setAccessible(true);
    foreach ([
        'unresolved token' => $compiled['sql'] . ' {{unknown}}',
        'different MARC relation' => preg_replace('/marctab\.mt035/', 'marctab.mt245', $compiled['sql'], 1),
        'forbidden union view' => $compiled['sql'] . ' /* folio_source_record.marctab */',
        'wrong sentinel limit' => str_replace('LIMIT 100001', 'LIMIT 100000', $compiled['sql']),
    ] as $label => $invalidSql) {
        finderCompilerInvalid(
            function () use ($compiledValidator, $invalidSql) {
                $compiledValidator->invoke(null, $invalidSql, 'marctab.mt035');
            },
            "Compiled SQL with {$label} must fail closed."
        );
    }

    $missingTableDb = new FinderCompilerFakeDb();
    $missingTableDb->tables = [];
    finderCompilerInvalid(
        function () use ($report, $missingTableDb) {
            CatalogingMarcFieldFinderService::build($report, finderCompilerInputs(), $missingTableDb);
        },
        'A missing selected MARC table must be reported as schema integrity failure.'
    );

    fwrite(STDOUT, "Cataloging MARC field finder compiler tests passed\n");
}
