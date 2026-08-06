<?php

namespace yii\db {
    if (!class_exists(ActiveRecord::class, false)) {
        class ActiveRecord
        {
            private $attributes = [];
            public function __get($name) { return $this->attributes[$name] ?? null; }
            public function __set($name, $value): void { $this->attributes[$name] = $value; }
            public function hasAttribute($name): bool { return true; }
        }
    }
}

namespace {
    require_once __DIR__ . '/../models/ReportTemplate.php';
    require_once __DIR__ . '/../services/CatalogingMarcFieldFinderService.php';

    use app\models\ReportTemplate;
    use app\services\CatalogingMarcFieldFinderService;

    function finderSemanticsFail(string $message): void
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function finderSemanticsSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            finderSemanticsFail($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    final class FinderSemanticsLookupCommand
    {
        private $sql;
        private $params;

        public function __construct(string $sql, array $params)
        {
            $this->sql = $sql;
            $this->params = $params;
        }

        public function queryAll(): array
        {
            if (strpos($this->sql, 'FROM inventory.location__t') === false) {
                throw new \RuntimeException('Unexpected location lookup.');
            }
            $locations = [
                '11111111-1111-4111-8111-111111111111' => ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Main', 'code' => 'MAIN'],
                '22222222-2222-4222-8222-222222222222' => ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Internet', 'code' => 'ONLINE'],
            ];
            $rows = [];
            foreach ($this->params as $id) {
                if (isset($locations[$id])) {
                    $rows[] = $locations[$id];
                }
            }
            return $rows;
        }

        public function queryOne(): array
        {
            if (strpos($this->sql, 'to_regclass') === false) {
                throw new \RuntimeException('Unexpected table lookup.');
            }
            $table = $this->params[':table_name'] ?? null;
            return ['to_regclass' => in_array($table, ['marctab.mt035', 'marctab.mt245'], true) ? $table : null];
        }
    }

    final class FinderSemanticsLookupDb
    {
        public function createCommand(string $sql, array $params = []): FinderSemanticsLookupCommand
        {
            return new FinderSemanticsLookupCommand($sql, $params);
        }
    }

    function finderSemanticsReport(): ReportTemplate
    {
        $migration = (string) file_get_contents(__DIR__ . '/../../mysql/migrations/043_cataloging_marc_field_finder.sql');
        if (preg_match(
            "/VALUES \(\n  'marc-field-indicator-content-finder',\n  '([^']+)',.*?\n  '(WITH target_instances AS MATERIALIZED \(.*?LIMIT 100001)',\n  '(\\[.*?\\])',\n  'folio',\n  '(\\{.*?\\})',\n  100000,\n  1,\n  'manual'\n\)/s",
            $migration,
            $matches
        ) !== 1) {
            throw new \RuntimeException('Could not load the canonical MARC finder fixture.');
        }
        $report = new ReportTemplate();
        foreach ([
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
        ] as $attribute => $value) {
            $report->$attribute = $value;
        }
        return $report;
    }

    function finderSemanticsInputs(array $overrides = []): array
    {
        return array_merge([
            'locationIds' => '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222',
            'locationBasis' => 'effective_item',
            'marcTag' => '245',
            'occurrenceCondition' => 'has',
            'firstIndicator' => 'any',
            'secondIndicator' => 'any',
            'subfieldCode' => '',
            'contentRule' => 'any',
            'searchValue' => '',
            'caseExact' => 'false',
        ], $overrides);
    }

    function finderSemanticsSqliteSql(string $sql): string
    {
        $sql = str_replace('AS MATERIALIZED', 'AS', $sql);
        $sql = str_replace(
            "location.id = ANY(string_to_array(:locationIds, ',')::uuid[])",
            "instr(',' || :locationIds || ',', ',' || location.id || ',') > 0",
            $sql
        );
        $sql = preg_replace(
            "/STRING_AGG\(\s*DISTINCT location\.name \|\| COALESCE\(' \[' \|\| location\.code \|\| '\]', ''\),\s*'; ' ORDER BY location\.name \|\| COALESCE\(' \[' \|\| location\.code \|\| '\]', ''\)\s*\)/s",
            "GROUP_CONCAT(DISTINCT location.name || COALESCE(' [' || location.code || ']', ''))",
            $sql
        );
        $sql = str_replace(['CHR(92)', 'STRPOS(', 'LEFT(', 'CHAR_LENGTH('], ['CHAR(92)', 'INSTR(', 'LEFT_FN(', 'LENGTH('], $sql);
        $sql = preg_replace('/SUBSTRING\((:[A-Za-z][A-Za-z0-9]*) FROM 6\)/', 'SUBSTR($1, 6)', $sql);
        $sql = str_replace(" ~ '", " REGEXP '", $sql);
        $sql = str_replace(['NULL::text', 'NULL::integer'], ['CAST(NULL AS TEXT)', 'CAST(NULL AS INTEGER)'], $sql);
        return $sql;
    }

    function finderSemanticsRowsForUuid(array $rows, string $uuid): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($uuid): bool {
            return $row['Instance UUID'] === $uuid;
        }));
    }

    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
        exit(0);
    }

    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    @$pdo->sqliteCreateFunction('LEFT_FN', static function ($value, $length): string {
        return substr((string) $value, 0, (int) $length);
    }, 2);
    @$pdo->sqliteCreateFunction('regexp', static function ($pattern, $value): int {
        return preg_match('/' . str_replace('/', '\\/', (string) $pattern) . '/', (string) $value) === 1 ? 1 : 0;
    }, 2);
    $pdo->exec("ATTACH DATABASE ':memory:' AS inventory");
    $pdo->exec("ATTACH DATABASE ':memory:' AS marctab");
    $pdo->exec('CREATE TABLE inventory.location__t (id TEXT PRIMARY KEY, name TEXT, code TEXT)');
    $pdo->exec('CREATE TABLE inventory.instance__t (id TEXT PRIMARY KEY, hrid TEXT, title TEXT, source TEXT)');
    $pdo->exec('CREATE TABLE inventory.holdings_record__t (id TEXT PRIMARY KEY, instance_id TEXT, permanent_location_id TEXT)');
    $pdo->exec('CREATE TABLE inventory.item__t (id TEXT PRIMARY KEY, holdings_record_id TEXT, effective_location_id TEXT, permanent_location_id TEXT)');
    foreach (['245', '035'] as $tag) {
        $pdo->exec("CREATE TABLE marctab.mt{$tag} (instance_id TEXT, ind1 TEXT, ind2 TEXT, ord INTEGER, line INTEGER, sf TEXT, content TEXT)");
    }

    $pdo->exec("INSERT INTO inventory.location__t VALUES
        ('11111111-1111-4111-8111-111111111111', 'Main', 'MAIN'),
        ('22222222-2222-4222-8222-222222222222', 'Internet', 'ONLINE')");
    $instances = [
        ['rich-245', null, 'Rich 245', 'MARC'],
        ['other-245', 'in0002', 'Other 245', 'MARC'],
        ['missing-245', 'in0003', 'Missing 245', 'MARC'],
        ['folio-245', 'in0004', 'FOLIO 245', 'FOLIO'],
        ['two-035', 'in0035', 'Two 035s', 'MARC'],
    ];
    $insertInstance = $pdo->prepare('INSERT INTO inventory.instance__t VALUES (?, ?, ?, ?)');
    foreach ($instances as $instance) {
        $insertInstance->execute($instance);
    }
    $holdings = [
        ['hold-rich-main', 'rich-245', '11111111-1111-4111-8111-111111111111'],
        ['hold-rich-online', 'rich-245', '22222222-2222-4222-8222-222222222222'],
        ['hold-other', 'other-245', '11111111-1111-4111-8111-111111111111'],
        ['hold-missing', 'missing-245', '11111111-1111-4111-8111-111111111111'],
        ['hold-folio', 'folio-245', '11111111-1111-4111-8111-111111111111'],
        ['hold-035', 'two-035', '11111111-1111-4111-8111-111111111111'],
    ];
    $insertHolding = $pdo->prepare('INSERT INTO inventory.holdings_record__t VALUES (?, ?, ?)');
    $insertItem = $pdo->prepare('INSERT INTO inventory.item__t VALUES (?, ?, ?, ?)');
    foreach ($holdings as $index => $holding) {
        $insertHolding->execute($holding);
        $insertItem->execute(['item-' . $index, $holding[0], $holding[2], $holding[2]]);
    }

    $insert245 = $pdo->prepare('INSERT INTO marctab.mt245 VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ([
        ['rich-245', ' ', '\\', 1, 10, 'a', 'Alpha%'],
        ['rich-245', ' ', '\\', 1, 11, 'b', 'Mixed_Case'],
        ['rich-245', ' ', '\\', 1, 12, 'c', "Quote'\\"],
        ['rich-245', 'A', '!', 2, 20, '9', null],
        ['rich-245', '2', '0', 3, 30, 'a', 'repeat'],
        ['rich-245', '2', '0', 3, 31, 'a', 'repeat'],
        ['rich-245', '   ', ' ', 4, 40, 'x', '   '],
        ['rich-245', '\\', '', 5, 50, 'y', ''],
        ['other-245', '1', '0', 1, 10, 'a', 'beta'],
        ['folio-245', ' ', ' ', 1, 10, 'a', 'Alpha%'],
    ] as $row) {
        $insert245->execute($row);
    }
    $insert035 = $pdo->prepare('INSERT INTO marctab.mt035 VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert035->execute(['two-035', ' ', ' ', 1, 10, 'a', '(OCoLC)One']);
    $insert035->execute(['two-035', '\\', '\\', 2, 20, 'a', '(OCoLC)Two']);

    $report = finderSemanticsReport();
    $lookupDb = new FinderSemanticsLookupDb();
    $run = static function (array $overrides = []) use ($report, $lookupDb, $pdo): array {
        $compiled = CatalogingMarcFieldFinderService::build($report, finderSemanticsInputs($overrides), $lookupDb);
        $statement = $pdo->prepare(finderSemanticsSqliteSql($compiled['sql']));
        $statement->execute($compiled['params']);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    };

    $allRows = $run();
    finderSemanticsSame([
        'Instance UUID', 'Instance HRID', 'Title', 'Selected Location(s)', 'Location Basis', 'MARC Tag',
        'First Indicator', 'Second Indicator', 'Field Occurrence', 'Subfield', 'Content', 'Finding',
    ], array_keys($allRows[0]), 'The worklist must expose the exact 12-column header.');
    finderSemanticsSame(8, count(finderSemanticsRowsForUuid($allRows, 'rich-245')), 'Two selected locations must not duplicate matching field rows.');
    finderSemanticsSame(null, finderSemanticsRowsForUuid($allRows, 'rich-245')[0]['Instance HRID'], 'A null-HRID MARC instance must still join by UUID.');
    $locations = explode(',', finderSemanticsRowsForUuid($allRows, 'rich-245')[0]['Selected Location(s)']);
    sort($locations);
    finderSemanticsSame(['Internet [ONLINE]', 'Main [MAIN]'], $locations, 'All selected locations must be aggregated on each finding.');
    finderSemanticsSame([], finderSemanticsRowsForUuid($allRows, 'folio-245'), 'FOLIO-sourced instances must be excluded.');

    $blankIndicators = $run(['firstIndicator' => 'blank', 'secondIndicator' => 'blank']);
    finderSemanticsSame(
        ['Alpha%', 'Mixed_Case', "Quote'\\", '   ', ''],
        array_column(finderSemanticsRowsForUuid($blankIndicators, 'rich-245'), 'Content'),
        'Whitespace and backslash indicator encodings must all match blank.'
    );
    finderSemanticsSame(['#'], array_values(array_unique(array_column($blankIndicators, 'First Indicator'))), 'Blank first indicators must display as #.');
    finderSemanticsSame(['#'], array_values(array_unique(array_column($blankIndicators, 'Second Indicator'))), 'Blank second indicators must display as #.');

    $customIndicators = $run(['firstIndicator' => 'char:A', 'secondIndicator' => 'char:!', 'subfieldCode' => '9']);
    finderSemanticsSame([null], array_column($customIndicators, 'Content'), 'Alphabetic and punctuation indicators must combine with numeric local subfield 9 on one row.');
    finderSemanticsSame([], $run(['firstIndicator' => 'char:A', 'subfieldCode' => 'a', 'contentRule' => 'contains', 'searchValue' => 'Alpha']), 'Indicator, subfield, and content predicates must not match across different MARC rows.');

    $sharedOccurrence = $run(['firstIndicator' => 'blank', 'secondIndicator' => 'blank']);
    $firstOccurrence = array_values(array_filter(finderSemanticsRowsForUuid($sharedOccurrence, 'rich-245'), static function (array $row): bool {
        return (int) $row['Field Occurrence'] === 1;
    }));
    finderSemanticsSame(['a', 'b', 'c'], array_column($firstOccurrence, 'Subfield'), '245 subfields with different lines must remain in the same ord occurrence.');
    finderSemanticsSame([1, 1, 1], array_map('intval', array_column($firstOccurrence, 'Field Occurrence')), 'Field Occurrence must use ord, not line.');
    finderSemanticsSame(2, count($run(['contentRule' => 'equals', 'searchValue' => 'repeat', 'caseExact' => 'true'])), 'Repeated identical subfield rows must be preserved.');

    $rows035 = $run(['marcTag' => '035']);
    finderSemanticsSame([1, 2], array_map('intval', array_column($rows035, 'Field Occurrence')), 'Separate 035 occurrences must expose ord 1 and ord 2.');

    $textCases = [
        ['contains', 'alpha', 'false', ['Alpha%']],
        ['contains', 'alpha', 'true', []],
        ['not_contains', 'alpha', 'false', ["Quote'\\", 'Mixed_Case', null, 'repeat', 'repeat', '   ', '', 'beta']],
        ['not_contains', 'Alpha', 'true', ["Quote'\\", 'Mixed_Case', null, 'repeat', 'repeat', '   ', '', 'beta']],
        ['equals', 'mixed_case', 'false', ['Mixed_Case']],
        ['equals', 'Mixed_Case', 'true', ['Mixed_Case']],
        ['not_equals', 'repeat', 'false', ['Alpha%', "Quote'\\", 'Mixed_Case', null, '   ', '', 'beta']],
        ['not_equals', 'Repeat', 'true', ['Alpha%', "Quote'\\", 'Mixed_Case', null, 'repeat', 'repeat', '   ', '', 'beta']],
        ['begins', 'alpha', 'false', ['Alpha%']],
        ['begins', 'alpha', 'true', []],
        ['not_begins', 'alpha', 'false', ["Quote'\\", 'Mixed_Case', null, 'repeat', 'repeat', '   ', '', 'beta']],
        ['not_begins', 'alpha', 'true', ['Alpha%', "Quote'\\", 'Mixed_Case', null, 'repeat', 'repeat', '   ', '', 'beta']],
    ];
    foreach ($textCases as [$rule, $search, $caseExact, $expected]) {
        $actual = array_column($run(['contentRule' => $rule, 'searchValue' => $search, 'caseExact' => $caseExact]), 'Content');
        sort($expected);
        sort($actual);
        finderSemanticsSame($expected, $actual, "{$rule} must honor caseExact={$caseExact}.");
    }

    foreach ([
        'blank' => [null, '   ', ''],
        'not_blank' => ['Alpha%', "Quote'\\", 'Mixed_Case', 'repeat', 'repeat', 'beta'],
        'has_lowercase' => ['Alpha%', "Quote'\\", 'Mixed_Case', 'repeat', 'repeat', 'beta'],
        'has_non_alphanumeric' => ['Alpha%', "Quote'\\", 'Mixed_Case', '   '],
    ] as $rule => $expected) {
        $actual = array_column($run(['contentRule' => $rule]), 'Content');
        sort($expected);
        sort($actual);
        finderSemanticsSame($expected, $actual, "{$rule} must use its controlled literal semantics.");
    }

    foreach ([
        '%' => ['Alpha%'],
        '_' => ['Mixed_Case'],
        "'\\" => ["Quote'\\"],
    ] as $literal => $expected) {
        finderSemanticsSame($expected, array_column($run(['contentRule' => 'contains', 'searchValue' => $literal]), 'Content'), "Literal {$literal} search text must not become a wildcard or SQL fragment.");
    }

    $missingRows = $run(['occurrenceCondition' => 'missing']);
    finderSemanticsSame(['missing-245', 'two-035'], array_column($missingRows, 'Instance UUID'), 'Missing mode must return exactly one row per instance without a matching occurrence.');
    finderSemanticsSame([null, null], array_column($missingRows, 'Field Occurrence'), 'Missing findings must not invent an occurrence number.');
    $sameRowMissing = $run([
        'occurrenceCondition' => 'missing',
        'firstIndicator' => 'char:A',
        'subfieldCode' => 'a',
        'contentRule' => 'contains',
        'searchValue' => 'Alpha',
    ]);
    finderSemanticsSame(1, count(finderSemanticsRowsForUuid($sameRowMissing, 'rich-245')), 'The missing probe must duplicate every predicate on the same physical row.');

    $repeated = $run(['contentRule' => 'equals', 'searchValue' => 'repeat', 'caseExact' => 'true']);
    $compiled = CatalogingMarcFieldFinderService::build(
        $report,
        finderSemanticsInputs(['contentRule' => 'equals', 'searchValue' => 'repeat', 'caseExact' => 'true']),
        $lookupDb
    );
    $identifierSql = 'SELECT DISTINCT "Instance UUID" AS UUID FROM (' . finderSemanticsSqliteSql($compiled['sql']) . ') finder_worklist';
    $identifierStatement = $pdo->prepare($identifierSql);
    $identifierStatement->execute($compiled['params']);
    finderSemanticsSame(2, count($repeated), 'The worklist must retain repeated matching rows before identifier export.');
    finderSemanticsSame([['UUID' => 'rich-245']], $identifierStatement->fetchAll(\PDO::FETCH_ASSOC), 'Identifier projection must deduplicate repeated field rows.');

    fwrite(STDOUT, "Cataloging MARC field finder SQL semantics tests passed\n");
}
