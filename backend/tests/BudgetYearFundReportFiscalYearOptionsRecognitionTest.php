<?php

require_once __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;

$recognitionFailures = [];

function assertRecognitionSame($expected, $actual, string $message): void
{
    global $recognitionFailures;
    if ($expected !== $actual) {
        $recognitionFailures[] = $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true);
    }
}

function revisedFiscalYearOptionsReport(array $overrides = []): array
{
    return array_merge(
        [
            'id' => 37,
            'slug' => 'budget-year-fund-report',
            'name' => 'Budget Year Fund Report',
            'description' => 'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
            'category' => 'finance',
            'data_source' => 'folio',
            'default_limit' => 1000,
            'is_active' => 1,
            'created_by' => 'manual',
            'sql_template' => "SELECT fy.series = au.code || 'FY', amount AS \"Calculated Remaining\"",
            'help_text' => 'Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.',
            'parameters' => [
                ['name' => 'fiscalYearEndYear'],
                ['name' => 'acqUnitId'],
            ],
        ],
        $overrides
    );
}

function oldBudgetYearFundReport(): array
{
    return revisedFiscalYearOptionsReport(
        [
            'description' => 'Compares transaction-derived payments, current encumbrances, and remaining balances with FOLIO budget totals for every allocated fund in a selected fiscal year and acquisition unit.',
            'sql_template' => 'WITH selected_fiscal_year AS (...) SELECT amount AS "Remaining Difference"',
            'help_text' => 'Remaining Difference: Calculated Remaining minus FOLIO Available.',
            'parameters' => [
                ['name' => 'fiscalYearId'],
                ['name' => 'acqUnitId'],
            ],
        ]
    );
}

class FiscalYearOptionsColumnSchema
{
    public $columns;

    public function __construct(array $columns)
    {
        $this->columns = [];
        foreach ($columns as $column) {
            $this->columns[$column] = new stdClass();
        }
    }
}

class FiscalYearOptionsSchema
{
    public function getTableSchema(string $table, bool $refresh = false)
    {
        $tables = ['users', 'query_jobs', 'report_templates', 'ai_clarification_events', 'ai_query_feedback', 'folio_reference_tables'];
        if (!in_array($table, $tables, true)) {
            return null;
        }

        return new FiscalYearOptionsColumnSchema($table === 'report_templates' ? ['help_text'] : []);
    }
}

class FiscalYearOptionsDatabase
{
    public $schema;
    private $report;

    public function __construct(array $report)
    {
        $this->schema = new FiscalYearOptionsSchema();
        $this->report = $report;
    }

    public function createCommand(string $sql = '', array $params = [])
    {
        return new FiscalYearOptionsCommand($this->report, $sql, $params);
    }
}

class FiscalYearOptionsCommand
{
    private $report;
    private $sql;
    private $params;

    public function __construct(array $report, string $sql, array $params)
    {
        $this->report = $report;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryScalar(): int
    {
        $requiredFragments = [
            'FROM report_templates',
            'id = 37 AND slug = :slug',
            'name = :name',
            'description = :description',
            'category = :category',
            'data_source = :data_source',
            'default_limit = :default_limit',
            'is_active = 1',
            'created_by = :created_by',
            'sql_template LIKE :series_marker',
            'sql_template LIKE :remaining_marker',
            'sql_template NOT LIKE :difference_marker',
            'help_text LIKE :help_marker',
            'help_text NOT LIKE :difference_marker',
            'JSON_LENGTH(parameters) = 2',
            "BINARY JSON_UNQUOTE(JSON_EXTRACT(parameters, '$[0].name')) = BINARY :fiscal_year_parameter",
            "BINARY JSON_UNQUOTE(JSON_EXTRACT(parameters, '$[1].name')) = BINARY :acq_unit_parameter",
        ];
        foreach ($requiredFragments as $fragment) {
            if (strpos($this->sql, $fragment) === false) {
                return 0;
            }
        }

        $expectedParams = [
            ':slug' => 'budget-year-fund-report',
            ':name' => 'Budget Year Fund Report',
            ':description' => 'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
            ':category' => 'finance',
            ':data_source' => 'folio',
            ':default_limit' => 1000,
            ':created_by' => 'manual',
            ':series_marker' => "%fy.series = au.code || 'FY'%",
            ':remaining_marker' => '%AS "Calculated Remaining"%',
            ':difference_marker' => '%Remaining Difference%',
            ':help_marker' => '%Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.%',
            ':fiscal_year_parameter' => 'fiscalYearEndYear',
            ':acq_unit_parameter' => 'acqUnitId',
        ];
        if ($this->params !== $expectedParams) {
            return 0;
        }

        foreach (['slug', 'name', 'description', 'category', 'data_source', 'default_limit', 'created_by'] as $field) {
            if (($this->report[$field] ?? null) !== $expectedParams[':' . $field]) {
                return 0;
            }
        }
        if (($this->report['id'] ?? null) !== 37 || ($this->report['is_active'] ?? null) !== 1) {
            return 0;
        }

        if (!$this->matchesLike($this->report['sql_template'] ?? '', $expectedParams[':series_marker'])
            || !$this->matchesLike($this->report['sql_template'] ?? '', $expectedParams[':remaining_marker'])
            || $this->matchesLike($this->report['sql_template'] ?? '', $expectedParams[':difference_marker'])
            || !$this->matchesLike($this->report['help_text'] ?? '', $expectedParams[':help_marker'])) {
            return 0;
        }
        if ($this->matchesLike($this->report['help_text'] ?? '', $expectedParams[':difference_marker'])) {
            return 0;
        }

        $parameters = $this->report['parameters'] ?? [];
        return count($parameters) === 2
            && ($parameters[0]['name'] ?? null) === $expectedParams[':fiscal_year_parameter']
            && ($parameters[1]['name'] ?? null) === $expectedParams[':acq_unit_parameter']
            ? 1
            : 0;
    }

    private function matchesLike(string $value, string $pattern): bool
    {
        return stripos($value, trim($pattern, '%')) !== false;
    }
}

function migration036AppearsApplied(array $report): bool
{
    static $migrationApplied;
    if ($migrationApplied === null) {
        $migrationApplied = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
    }

    return $migrationApplied->invoke(null, new FiscalYearOptionsDatabase($report), '036_budget_year_fund_report_fiscal_year_options.sql');
}

function databaseAppearsCurrentWithReport(array $report): bool
{
    static $databaseCurrent;
    if ($databaseCurrent === null) {
        $databaseCurrent = new ReflectionMethod(MigrationService::class, 'databaseAppearsCurrent');
    }

    return $databaseCurrent->invoke(null, new FiscalYearOptionsDatabase($report));
}

$revisedDefinition = revisedFiscalYearOptionsReport();
assertRecognitionSame(true, migration036AppearsApplied($revisedDefinition), 'Migration 036 must recognize the fully revised definition.');
assertRecognitionSame(false, databaseAppearsCurrentWithReport($revisedDefinition), 'The migration-036 definition must not make a database appear current after migration 037 exists.');
assertRecognitionSame(false, migration036AppearsApplied(oldBudgetYearFundReport()), 'Migration 036 must not recognize the old report definition.');
assertRecognitionSame(false, databaseAppearsCurrentWithReport(oldBudgetYearFundReport()), 'The old report definition must not make a ledger-less database appear current.');

$negativeCases = [
    'case-variant fiscal-year parameter' => ['parameters' => [['name' => 'fiscalyearendyear'], ['name' => 'acqUnitId']]],
    'wrong acquisition-unit parameter' => ['parameters' => [['name' => 'fiscalYearEndYear'], ['name' => 'acquisitionUnitId']]],
    'missing acquisition-unit parameter' => ['parameters' => [['name' => 'fiscalYearEndYear']]],
    'wrong parameter order' => ['parameters' => [['name' => 'acqUnitId'], ['name' => 'fiscalYearEndYear']]],
    'extra parameter' => ['parameters' => [['name' => 'fiscalYearEndYear'], ['name' => 'acqUnitId'], ['name' => 'extra']]],
    'missing fiscal-year series marker' => ['sql_template' => 'SELECT amount AS "Calculated Remaining"'],
    'missing Calculated Remaining SQL marker' => ['sql_template' => "SELECT fy.series = au.code || 'FY', amount"],
    'SQL containing Remaining Difference' => ['sql_template' => "SELECT fy.series = au.code || 'FY', amount AS \"Calculated Remaining\", amount AS \"Remaining Difference\""],
    'missing required help text' => ['help_text' => 'Allocated: stored allocation.'],
    'help containing Remaining Difference' => ['help_text' => 'Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances. Remaining Difference: stale definition.'],
    'wrong stable metadata' => ['description' => 'Stale report description.'],
];
foreach ($negativeCases as $name => $overrides) {
    assertRecognitionSame(false, migration036AppearsApplied(revisedFiscalYearOptionsReport($overrides)), "Migration 036 must reject {$name}.");
}

if ($recognitionFailures !== []) {
    fwrite(STDERR, implode("\n\n", $recognitionFailures) . "\n");
    exit(1);
}

fwrite(STDOUT, "BudgetYearFundReportFiscalYearOptionsRecognition test passed\n");
