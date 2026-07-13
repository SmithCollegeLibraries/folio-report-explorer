<?php

$servicePath = __DIR__ . '/../services/MigrationService.php';
$migrationPath = __DIR__ . '/../../mysql/migrations/035_budget_year_fund_report.sql';
$initPath = __DIR__ . '/../../mysql/init.sql';

require_once $servicePath;

use app\services\MigrationService;

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    assertTrueValue(strpos($haystack, $needle) !== false, $message);
}

class BudgetYearFundReportTestColumn
{
}

class BudgetYearFundReportTestTableSchema
{
    public $columns;

    public function __construct(array $columns = [])
    {
        $this->columns = [];
        foreach ($columns as $column) {
            $this->columns[$column] = new BudgetYearFundReportTestColumn();
        }
    }
}

class BudgetYearFundReportTestSchema
{
    private $tables;

    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function getTableSchema(string $table, bool $refresh = false)
    {
        return $this->tables[$table] ?? null;
    }
}

class BudgetYearFundReportTestCommand
{
    private $count;

    public function __construct(int $count)
    {
        $this->count = $count;
    }

    public function queryScalar(): int
    {
        return $this->count;
    }
}

class BudgetYearFundReportTestDatabase
{
    public $schema;
    private $hasReportRow;

    public function __construct(bool $hasHelpText, bool $hasReportRow)
    {
        $tableNames = [
            'users',
            'query_jobs',
            'report_templates',
            'ai_clarification_events',
            'ai_query_feedback',
            'folio_reference_tables',
        ];
        $tables = [];
        foreach ($tableNames as $tableName) {
            $columns = $tableName === 'report_templates' && $hasHelpText ? ['help_text'] : [];
            $tables[$tableName] = new BudgetYearFundReportTestTableSchema($columns);
        }
        $this->schema = new BudgetYearFundReportTestSchema($tables);
        $this->hasReportRow = $hasReportRow;
    }

    public function createCommand(string $sql, array $params = []): BudgetYearFundReportTestCommand
    {
        $isReportLookup = strpos($sql, 'FROM report_templates') !== false
            && ($params[':slug'] ?? null) === 'budget-year-fund-report';

        return new BudgetYearFundReportTestCommand($isReportLookup && $this->hasReportRow ? 1 : 0);
    }
}

$sql = file_exists($migrationPath) ? (string)file_get_contents($migrationPath) : '';
assertContainsText('ADD COLUMN IF NOT EXISTS `help_text` LONGTEXT NULL', $sql, 'Migration must add reusable report help metadata.');
assertContainsText("'budget-year-fund-report'", $sql, 'Migration must seed the fixed report slug.');
assertContainsText(':fiscalYearId', $sql, 'Report must bind one fiscal year.');
assertContainsText(':acqUnitId', $sql, 'Report must bind one acquisition unit.');
assertContainsText('WHERE EXISTS', $sql, 'Fund acquisition-unit filtering must avoid duplicate budgets.');
assertContainsText("tt.encumbrance__status IN (''Unreleased'', ''Active'')", $sql, 'Report must calculate active encumbrances.');
assertContainsText('inv.payment_date::date BETWEEN fy.period_start AND fy.period_end', $sql, 'Payment dates must come from the selected fiscal year.');
assertContainsText('"Remaining Difference"', $sql, 'Report must include reconciliation differences.');
assertTrueValue(substr_count($sql, 'ROUND(') >= 13, 'All monetary outputs must be rounded.');
assertTrueValue(stripos($sql, 'TO_CHAR') === false, 'Monetary outputs must remain numeric.');

$initSql = (string)file_get_contents($initPath);
assertContainsText('help_text LONGTEXT NULL', $initSql, 'Fresh-install schema must include reusable report help metadata.');

$migrationApplied = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
$databaseCurrent = new ReflectionMethod(MigrationService::class, 'databaseAppearsCurrent');

$columnOnlyDatabase = new BudgetYearFundReportTestDatabase(true, false);
assertTrueValue(
    $migrationApplied->invoke(null, $columnOnlyDatabase, '035_budget_year_fund_report.sql') === false,
    'Migration 035 must not appear applied when only help_text exists.'
);
assertTrueValue(
    $databaseCurrent->invoke(null, $columnOnlyDatabase) === false,
    'Fresh-init schema must not be baselined before the fixed report row exists.'
);

$rowOnlyDatabase = new BudgetYearFundReportTestDatabase(false, true);
assertTrueValue(
    $migrationApplied->invoke(null, $rowOnlyDatabase, '035_budget_year_fund_report.sql') === false,
    'Migration 035 must not appear applied when only the fixed report row exists.'
);
assertTrueValue(
    $databaseCurrent->invoke(null, $rowOnlyDatabase) === false,
    'Database must not appear current before help_text exists.'
);

$completeDatabase = new BudgetYearFundReportTestDatabase(true, true);
assertTrueValue(
    $migrationApplied->invoke(null, $completeDatabase, '035_budget_year_fund_report.sql') === true,
    'Migration 035 must appear applied when help_text and the fixed report row both exist.'
);
assertTrueValue(
    $databaseCurrent->invoke(null, $completeDatabase) === true,
    'Database should appear current once help_text and the fixed report row both exist.'
);

fwrite(STDOUT, "BudgetYearFundReportMigration test passed\n");
