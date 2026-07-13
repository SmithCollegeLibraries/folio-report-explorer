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
    private $reportRows;

    public function __construct(bool $hasHelpText, array $reportRows)
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
        $this->reportRows = $reportRows;
    }

    public function createCommand(string $sql, array $params = []): BudgetYearFundReportTestCommand
    {
        $isReportLookup = strpos($sql, 'FROM report_templates') !== false
            && ($params[':slug'] ?? null) === 'budget-year-fund-report';
        if (!$isReportLookup) {
            return new BudgetYearFundReportTestCommand(0);
        }

        $requiresExactIdentity = strpos($sql, 'id = 37 AND slug = :slug') !== false;
        $count = 0;
        foreach ($this->reportRows as $row) {
            $hasId = ($row['id'] ?? null) === 37;
            $hasSlug = ($row['slug'] ?? null) === 'budget-year-fund-report';
            if (($requiresExactIdentity && $hasId && $hasSlug)
                || (!$requiresExactIdentity && ($hasId || $hasSlug))) {
                $count++;
            }
        }

        return new BudgetYearFundReportTestCommand($count);
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

$reservePosition = strpos($sql, 'SET @budget_year_fund_report_displaced_id = (');
$captureDisplacedPosition = strpos($sql, "SET @budget_year_fund_report_has_displaced_row = EXISTS (\n  SELECT 1\n  FROM report_templates\n  WHERE id = 37\n    AND slug <> 'budget-year-fund-report'\n)");
$captureExistingPosition = strpos($sql, "SET @budget_year_fund_report_existing_id = (\n  SELECT id\n  FROM report_templates\n  WHERE slug = 'budget-year-fund-report'\n  LIMIT 1\n)");
$displacePosition = strpos($sql, "UPDATE report_templates\nSET id = @budget_year_fund_report_displaced_id\nWHERE id = 37\n  AND slug <> 'budget-year-fund-report'");
$repointDisplacedPosition = strpos($sql, "UPDATE dashboard_widget_templates\nSET report_template_id = @budget_year_fund_report_displaced_id\nWHERE report_template_id = 37\n  AND @budget_year_fund_report_has_displaced_row = 1");
$claimPosition = strpos($sql, "UPDATE report_templates\nSET id = 37\nWHERE slug = 'budget-year-fund-report'\n  AND id <> 37");
$repointExistingPosition = strpos($sql, "UPDATE dashboard_widget_templates\nSET report_template_id = 37\nWHERE report_template_id = @budget_year_fund_report_existing_id\n  AND @budget_year_fund_report_existing_id <> 37");
$seedPosition = strpos($sql, 'INSERT INTO `report_templates`');
assertTrueValue($reservePosition !== false, 'Migration must reserve a new ID before displacing an unrelated report at ID 37.');
assertTrueValue($captureDisplacedPosition !== false, 'Migration must remember whether ID 37 belongs to an unrelated report.');
assertTrueValue($captureExistingPosition !== false, 'Migration must capture the fixed slug old ID before changing report identities.');
assertTrueValue($displacePosition !== false, 'Migration must preserve an unrelated ID 37 report at the reserved ID.');
assertTrueValue($repointDisplacedPosition !== false, 'Migration must keep widgets attached to an unrelated report displaced from ID 37.');
assertTrueValue($claimPosition !== false, 'Migration must move an existing fixed slug to ID 37 before seeding.');
assertTrueValue($repointExistingPosition !== false, 'Migration must keep widgets attached to the fixed slug when it moves to ID 37.');
assertTrueValue(
    $reservePosition < $captureDisplacedPosition
        && $captureDisplacedPosition < $captureExistingPosition
        && $captureExistingPosition < $displacePosition
        && $displacePosition < $repointDisplacedPosition
        && $repointDisplacedPosition < $claimPosition
        && $claimPosition < $repointExistingPosition
        && $repointExistingPosition < $seedPosition,
    'Migration must preserve each logical report-widget association while reconciling both unique keys.'
);

$initSql = (string)file_get_contents($initPath);
assertContainsText('help_text LONGTEXT NULL', $initSql, 'Fresh-install schema must include reusable report help metadata.');

$migrationApplied = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
$databaseCurrent = new ReflectionMethod(MigrationService::class, 'databaseAppearsCurrent');

$columnOnlyDatabase = new BudgetYearFundReportTestDatabase(true, []);
assertTrueValue(
    $migrationApplied->invoke(null, $columnOnlyDatabase, '035_budget_year_fund_report.sql') === false,
    'Migration 035 must not appear applied when only help_text exists.'
);
assertTrueValue(
    $databaseCurrent->invoke(null, $columnOnlyDatabase) === false,
    'Fresh-init schema must not be baselined before the fixed report row exists.'
);

$rowOnlyDatabase = new BudgetYearFundReportTestDatabase(false, [
    ['id' => 37, 'slug' => 'budget-year-fund-report'],
]);
assertTrueValue(
    $migrationApplied->invoke(null, $rowOnlyDatabase, '035_budget_year_fund_report.sql') === false,
    'Migration 035 must not appear applied when only the fixed report row exists.'
);
assertTrueValue(
    $databaseCurrent->invoke(null, $rowOnlyDatabase) === false,
    'Database must not appear current before help_text exists.'
);

$idOnlyDatabase = new BudgetYearFundReportTestDatabase(true, [
    ['id' => 37, 'slug' => 'unrelated-report'],
]);
assertTrueValue(
    $migrationApplied->invoke(null, $idOnlyDatabase, '035_budget_year_fund_report.sql') === false,
    'Migration 035 must not appear applied when ID 37 belongs to an unrelated report.'
);
assertTrueValue(
    $databaseCurrent->invoke(null, $idOnlyDatabase) === false,
    'Database must not appear current when ID 37 belongs to an unrelated report.'
);

$slugOnlyDatabase = new BudgetYearFundReportTestDatabase(true, [
    ['id' => 42, 'slug' => 'budget-year-fund-report'],
]);
assertTrueValue(
    $migrationApplied->invoke(null, $slugOnlyDatabase, '035_budget_year_fund_report.sql') === false,
    'Migration 035 must not appear applied when the fixed slug has the wrong ID.'
);
assertTrueValue(
    $databaseCurrent->invoke(null, $slugOnlyDatabase) === false,
    'Database must not appear current when the fixed slug has the wrong ID.'
);

$mismatchedDatabase = new BudgetYearFundReportTestDatabase(true, [
    ['id' => 37, 'slug' => 'unrelated-report'],
    ['id' => 42, 'slug' => 'budget-year-fund-report'],
]);
assertTrueValue(
    $migrationApplied->invoke(null, $mismatchedDatabase, '035_budget_year_fund_report.sql') === false,
    'Migration 035 must not appear applied when ID and slug belong to different rows.'
);
assertTrueValue(
    $databaseCurrent->invoke(null, $mismatchedDatabase) === false,
    'Database must not appear current when ID and slug belong to different rows.'
);

$completeDatabase = new BudgetYearFundReportTestDatabase(true, [
    ['id' => 37, 'slug' => 'budget-year-fund-report'],
]);
assertTrueValue(
    $migrationApplied->invoke(null, $completeDatabase, '035_budget_year_fund_report.sql') === true,
    'Migration 035 must appear applied when help_text and the fixed report row both exist.'
);
assertTrueValue(
    $databaseCurrent->invoke(null, $completeDatabase) === true,
    'Database should appear current once help_text and the fixed report row both exist.'
);

fwrite(STDOUT, "BudgetYearFundReportMigration test passed\n");
