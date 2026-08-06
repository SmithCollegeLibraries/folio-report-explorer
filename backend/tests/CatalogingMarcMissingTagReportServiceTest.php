<?php

namespace yii\db {
    class ActiveRecord
    {
        private array $attributes = [];

        public function __get($name) { return $this->attributes[$name] ?? null; }
        public function __set($name, $value): void { $this->attributes[$name] = $value; }
        public function hasAttribute($name): bool { return true; }
    }
}

namespace {
    require_once __DIR__ . '/../models/ReportTemplate.php';
    require_once __DIR__ . '/../services/CatalogingMarcMissingTagReportService.php';

    use app\models\ReportTemplate;
    use app\services\CatalogingMarcMissingTagReportService;

    function marcCompilerAssertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function marcCompilerAssertContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) === false) {
            fwrite(STDERR, "FAIL: {$message}\nMissing: {$needle}\n");
            exit(1);
        }
    }

    function marcCompilerAssertNotContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) !== false) {
            fwrite(STDERR, "FAIL: {$message}\nUnexpected: {$needle}\n");
            exit(1);
        }
    }

    function marcCompilerAssertThrows(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException $exception) {
            return;
        }
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    final class MarcCompilerFakeCommand
    {
        public function __construct(private string $sql, private array $params, private MarcCompilerFakeDb $db) {}

        public function queryOne()
        {
            if (strpos($this->sql, 'FROM inventory.location__t') !== false) {
                if (($this->params[':location_id'] ?? null) !== $this->db->expectedLocationId) {
                    throw new \RuntimeException('Location lookup must use the validated location UUID parameter.');
                }
                return $this->db->location;
            }
            if (strpos($this->sql, 'to_regclass') !== false) {
                return ['to_regclass' => $this->db->tables[$this->params[':table_name']] ?? null];
            }
            throw new \RuntimeException('Unexpected compiler lookup: ' . $this->sql);
        }
    }

    final class MarcCompilerFakeDb
    {
        public string $expectedLocationId = '11111111-1111-4111-8111-111111111111';
        public ?array $location = ['name' => 'Main Library', 'code' => 'MAIN'];
        public array $tables = ['marctab.mt856' => 'marctab.mt856'];

        public function createCommand(string $sql, array $params = []): MarcCompilerFakeCommand
        {
            return new MarcCompilerFakeCommand($sql, $params, $this);
        }
    }

    function marcCompilerReport(array $overrides = []): ReportTemplate
    {
        $migration = (string) file_get_contents(__DIR__ . '/../../mysql/migrations/040_cataloging_marc_missing_tag_report.sql');
        if (preg_match("/\\n  '(WITH target_instances AS MATERIALIZED \\(.*?LIMIT 100001)',\\n  '(\\[.*?\\])',\\n  'folio'/s", $migration, $matches) !== 1) {
            throw new \RuntimeException('Could not load the Task 1 report template fixture.');
        }

        $report = new ReportTemplate();
        $report->slug = CatalogingMarcMissingTagReportService::REPORT_SLUG;
        $report->sql_template = str_replace("''", "'", $matches[1]);
        $report->parameters = str_replace("''", "'", $matches[2]);
        $report->default_limit = 100000;
        foreach ($overrides as $attribute => $value) {
            $report->$attribute = $value;
        }
        return $report;
    }

    function marcCompilerInputs(array $overrides = []): array
    {
        return array_merge([
            'locationId' => '11111111-1111-4111-8111-111111111111',
            'locationBasis' => 'effective_item',
            'marcTag' => '856',
        ], $overrides);
    }

    $db = new MarcCompilerFakeDb();
    $report = marcCompilerReport();
    marcCompilerAssertSame(true, CatalogingMarcMissingTagReportService::supports($report), 'The fixed report slug must be supported.');

    $effective = CatalogingMarcMissingTagReportService::build($report, marcCompilerInputs(), $db);
    marcCompilerAssertContains('FROM inventory.item__t item', $effective['sql'], 'Effective-item scope must begin with items.');
    marcCompilerAssertContains('location.id = item.effective_location_id', $effective['sql'], 'Effective-item scope must use effective item location.');
    marcCompilerAssertContains('FROM marctab.mt856 AS marc_tag', $effective['sql'], 'The selected MARC table must be resolved by tag.');
    marcCompilerAssertContains('marc_tag.instance_id = target_instances.instance_uuid', $effective['sql'], 'MARC presence must compare instance UUID values.');
    marcCompilerAssertSame(1, preg_match_all('/\\bLIMIT\\s+100001\\b/i', $effective['sql']), 'The query must retain exactly one sentinel limit.');
    marcCompilerAssertNotContains('{{', $effective['sql'], 'The compiled SQL must not retain structural tokens.');
    marcCompilerAssertSame('856', $effective['params'][':marcTag'], 'The selected tag must remain a bound parameter.');
    marcCompilerAssertSame(['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Main Library', 'code' => 'MAIN'], $effective['location'], 'Location metadata must come from the FOLIO lookup.');
    marcCompilerAssertSame('856', $effective['marcTag'], 'The returned tag must be normalized.');

    $distinctLocationId = '22222222-2222-4222-8222-222222222222';
    $db->expectedLocationId = $distinctLocationId;
    $db->location = ['name' => 'Science Library', 'code' => 'SCI'];
    $distinctLocation = CatalogingMarcMissingTagReportService::build(
        $report,
        marcCompilerInputs(['locationId' => $distinctLocationId]),
        $db
    );
    marcCompilerAssertSame(
        ['id' => $distinctLocationId, 'name' => 'Science Library', 'code' => 'SCI'],
        $distinctLocation['location'],
        'The location lookup must bind the distinct valid location UUID supplied by the caller.'
    );
    $db->expectedLocationId = '11111111-1111-4111-8111-111111111111';
    $db->location = ['name' => 'Main Library', 'code' => 'MAIN'];

    $permanentItem = CatalogingMarcMissingTagReportService::build($report, marcCompilerInputs(['locationBasis' => 'permanent_item']), $db);
    marcCompilerAssertContains('location.id = item.permanent_location_id', $permanentItem['sql'], 'Permanent-item scope must use permanent item location.');

    $permanentHoldings = CatalogingMarcMissingTagReportService::build($report, marcCompilerInputs(['locationBasis' => 'permanent_holdings']), $db);
    marcCompilerAssertContains('FROM inventory.holdings_record__t holdings', $permanentHoldings['sql'], 'Permanent-holdings scope must begin with holdings.');
    marcCompilerAssertContains('location.id = holdings.permanent_location_id', $permanentHoldings['sql'], 'Permanent-holdings scope must use permanent holdings location.');

    foreach (['000', '1', '12', '1000', ' 856', '856 ', '+856', "٨٥٦", 'marctab.mt856', "856'", '856 --', '856;'] as $invalidTag) {
        marcCompilerAssertThrows(
            fn () => CatalogingMarcMissingTagReportService::build($report, marcCompilerInputs(['marcTag' => $invalidTag]), $db),
            "Invalid tag {$invalidTag} must be rejected."
        );
    }

    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build($report, marcCompilerInputs(['locationId' => 'not-a-uuid']), $db), 'Invalid UUIDs must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build($report, marcCompilerInputs(['locationBasis' => 'all_items']), $db), 'Unknown location bases must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['sql_template' => str_replace('{{location_from}}', '', $report->sql_template)]), marcCompilerInputs(), $db), 'Missing structural tokens must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['sql_template' => str_replace('{{marc_table}}', '{{marc_table}} {{marc_table}}', $report->sql_template)]), marcCompilerInputs(), $db), 'Repeated structural tokens must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['sql_template' => $report->sql_template . ' {{unknown_token}}']), marcCompilerInputs(), $db), 'Unknown structural tokens must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['sql_template' => $report->sql_template . ' {{location_from:unsafe}}']), marcCompilerInputs(), $db), 'Colon-bearing structural tokens must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['parameters' => '[]']), marcCompilerInputs(), $db), 'Missing parameter definitions must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['parameters' => '[{"name":"locationId"},{"name":"locationBasis"},{"name":"marcTag"},{"name":"marcTag"}]']), marcCompilerInputs(), $db), 'Duplicated parameter definitions must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['parameters' => '[{"name":"locationId"},{"name":"locationBasis"},{"name":"marcTag"},{"name":"extra"}]']), marcCompilerInputs(), $db), 'Extra parameter definitions must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['parameters' => '[{"name":"locationId"},{"name":"locationBasis"},{"name":"marcTagExtra"}]']), marcCompilerInputs(), $db), 'Prefix-colliding parameter names must be rejected.');

    $nestedOrderAndLimit = str_replace(
        ")\nSELECT\n",
        "    ORDER BY instance.title\n    LIMIT 100001\n)\nSELECT\n",
        $report->sql_template
    );
    $nestedOrderAndLimit = str_replace(
        "\nORDER BY target_instances.title NULLS LAST,\n         target_instances.instance_hrid NULLS LAST,\n         target_instances.instance_uuid\nLIMIT 100001",
        '',
        $nestedOrderAndLimit
    );
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['sql_template' => $nestedOrderAndLimit]), marcCompilerInputs(), $db), 'A tampered template with nested-only ORDER BY and LIMIT must fail the canonical contract.');
    $nestedCompiledSql = str_replace(
        ")\nSELECT\n",
        "    ORDER BY instance.title\n    LIMIT 100001\n)\nSELECT\n",
        $effective['sql']
    );
    $nestedCompiledSql = str_replace(
        "\nORDER BY target_instances.title NULLS LAST,\n         target_instances.instance_hrid NULLS LAST,\n         target_instances.instance_uuid\nLIMIT 100001",
        '',
        $nestedCompiledSql
    );
    $compiledSqlValidator = new \ReflectionMethod(CatalogingMarcMissingTagReportService::class, 'assertCompiledSql');
    marcCompilerAssertThrows(fn () => $compiledSqlValidator->invoke(null, $nestedCompiledSql), 'ORDER BY and LIMIT inside a CTE must not satisfy the top-level compiled SQL contract.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['sql_template' => str_replace("AND instance.source = 'MARC'", '', $report->sql_template)]), marcCompilerInputs(), $db), 'Removing the MARC source guard must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['sql_template' => str_replace('marc_tag.instance_id = target_instances.instance_uuid', 'marc_tag.instance_id = target_instances.instance_hrid', $report->sql_template)]), marcCompilerInputs(), $db), 'Changing the UUID anti-join must be rejected.');
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build(marcCompilerReport(['sql_template' => str_replace('FROM {{marc_table}} AS marc_tag', 'FROM folio_source_record.marctab AS marc_tag /* {{marc_table}} */', $report->sql_template)]), marcCompilerInputs(), $db), 'Substituting another MARC source while retaining the token must be rejected.');

    $missingTableDb = new MarcCompilerFakeDb();
    $missingTableDb->tables = [];
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build($report, marcCompilerInputs(), $missingTableDb), 'A missing marctab table must be rejected.');
    $missingLocationDb = new MarcCompilerFakeDb();
    $missingLocationDb->location = null;
    marcCompilerAssertThrows(fn () => CatalogingMarcMissingTagReportService::build($report, marcCompilerInputs(), $missingLocationDb), 'A missing location must be rejected.');

    $limitReport = marcCompilerReport(['sql_template' => 'SELECT :marcTag']);
    $ordinary = $limitReport->bindParams(marcCompilerInputs());
    marcCompilerAssertSame(1, preg_match_all('/\\bLIMIT\\s+100000\\b/i', $ordinary['sql']), 'Ordinary binding must append the report default limit.');
    $override = $report->bindParams(marcCompilerInputs(), 'SELECT :marcTag LIMIT 100001');
    marcCompilerAssertSame(1, preg_match_all('/\\bLIMIT\\s+100001\\b/i', $override['sql']), 'Override binding must not append a second limit.');

    fwrite(STDOUT, "Cataloging MARC missing-tag compiler tests passed\n");
}
