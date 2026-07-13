<?php

require_once __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;

function assertRecognitionSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
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
    public $revised;

    public function __construct(bool $revised)
    {
        $this->schema = new FiscalYearOptionsSchema();
        $this->revised = $revised;
    }

    public function createCommand(string $sql = '', array $params = [])
    {
        return new FiscalYearOptionsCommand($this->revised, $sql, $params);
    }
}

class FiscalYearOptionsCommand
{
    private $revised;
    private $sql;
    private $params;

    public function __construct(bool $revised, string $sql, array $params)
    {
        $this->revised = $revised;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryScalar(): int
    {
        $isRevisionPredicate = strpos($this->sql, 'FROM report_templates') !== false
            && strpos($this->sql, 'sql_template LIKE :series_marker') !== false
            && strpos($this->sql, 'CAST(parameters AS CHAR) LIKE :fiscal_year_parameter') !== false
            && ($this->params[':fiscal_year_parameter'] ?? null) === '%"fiscalYearEndYear"%';
        return $isRevisionPredicate && $this->revised ? 1 : 0;
    }
}

$migrationApplied = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
$databaseCurrent = new ReflectionMethod(MigrationService::class, 'databaseAppearsCurrent');

$oldDefinition = new FiscalYearOptionsDatabase(false);
assertRecognitionSame(false, $migrationApplied->invoke(null, $oldDefinition, '036_budget_year_fund_report_fiscal_year_options.sql'), 'Migration 036 must not appear applied for the old report definition.');
assertRecognitionSame(false, $databaseCurrent->invoke(null, $oldDefinition), 'The old report definition must not make a ledger-less database appear current.');

$revisedDefinition = new FiscalYearOptionsDatabase(true);
assertRecognitionSame(true, $migrationApplied->invoke(null, $revisedDefinition, '036_budget_year_fund_report_fiscal_year_options.sql'), 'Migration 036 must appear applied for the revised report definition.');
assertRecognitionSame(true, $databaseCurrent->invoke(null, $revisedDefinition), 'The revised report definition should make an otherwise-current database appear current.');

fwrite(STDOUT, "BudgetYearFundReportFiscalYearOptionsRecognition test passed\n");
