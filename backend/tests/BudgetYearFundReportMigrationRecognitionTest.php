<?php

require_once __DIR__ . '/../services/BudgetYearFundReportMigrationRecognitionService.php';
require_once __DIR__ . '/../services/MigrationService.php';

use app\services\BudgetYearFundReportMigrationRecognitionService;

$failures = [];

function budgetRecognitionAssert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function budgetRecognitionDefinition(array $overrides = []): array
{
    $aliases = [
        'Fund Code', 'Fund Name', 'Fiscal Year', 'Allocated', 'Payments',
        'Calculated Current Encumbrances', 'Unreleased Count', 'Released Encumbrances',
        'Released Count', 'Total Committed', 'Calculated Remaining', 'FOLIO Expenditures',
        'FOLIO Encumbered', 'FOLIO Available',
    ];
    $select = [];
    foreach ($aliases as $alias) {
        $select[] = '0 AS "' . $alias . '"';
    }
    $sql = "WITH encumbrances AS (SELECT SUM(CASE WHEN t.encumbrance__status IN ('Unreleased', 'Active') THEN COALESCE(t.encumbrance__initial_amount_encumbered, 0) - COALESCE(t.encumbrance__amount_expended, 0) - COALESCE(t.encumbrance__amount_awaiting_payment, 0) ELSE 0 END) AS current_encumbrance, SUM(CASE WHEN t.encumbrance__status = 'Unreleased' THEN 1 ELSE 0 END) AS unreleased_count, SUM(CASE WHEN t.encumbrance__status = 'Released' THEN COALESCE(t.encumbrance__initial_amount_encumbered, 0) ELSE 0 END) AS released_encumbrances, SUM(CASE WHEN t.encumbrance__status = 'Released' THEN 1 ELSE 0 END) AS released_count FROM finance.transaction__t t WHERE t.encumbrance__status IN ('Unreleased', 'Active', 'Released') GROUP BY t.from_fund_id, t.fiscal_year_id)\nSELECT "
        . implode(",\n       ", $select) . "\nFROM budgets b LEFT JOIN encumbrances e ON e.fund_id = b.fund_id AND e.fiscal_year_id = b.fiscal_year_id";
    $help = 'Calculated Current Encumbrances: the unreleased amount calculated as initial less expended and awaiting payment. '
        . 'Unreleased Count: number of Unreleased transactions. Released Encumbrances: sum of initial amounts for Released transactions. '
        . 'Released Count: number of Released transactions. Pending transactions are excluded from the new amounts and counts.';
    return array_merge([
        'id' => 37,
        'slug' => 'budget-year-fund-report',
        'name' => 'Budget Year Fund Report',
        'description' => 'Shows released and unreleased encumbrances for allocated funds.',
        'help_text' => $help,
        'category' => 'finance',
        'sql_template' => $sql,
        'parameters' => [
            ['name' => 'fiscalYearEndYear', 'type' => 'select', 'label' => 'Fiscal Year', 'default' => '', 'required' => true, 'description' => 'Campus-neutral fiscal year.', 'options_sql' => 'SELECT 2027', 'options_db' => 'folio', 'placeholder' => 'Select fiscal year'],
            ['name' => 'acqUnitId', 'type' => 'select', 'label' => 'Acquisition Unit', 'default' => '', 'required' => true, 'description' => 'Determines fund membership.', 'options_sql' => 'SELECT id', 'options_db' => 'folio', 'placeholder' => 'Select acquisition unit'],
        ],
        'data_source' => 'folio',
        'default_limit' => 1000,
        'is_active' => 1,
        'created_by' => 'manual',
    ], $overrides);
}

final class BudgetRecognitionTestSchema
{
    public function getTableSchema(string $table, bool $refresh = false)
    {
        return $table === 'report_templates' ? (object)['columns' => ['help_text' => new stdClass()]] : null;
    }
}

final class BudgetRecognitionTestDatabase
{
    public $schema;
    public $definition;

    public function __construct(array $definition)
    {
        $this->schema = new BudgetRecognitionTestSchema();
        $this->definition = $definition;
    }

    public function createCommand(string $sql = '', array $params = [])
    {
        return new class($this, $sql, $params) {
            private $db;
            private $sql;
            private $params;

            public function __construct($db, string $sql, array $params)
            {
                $this->db = $db;
                $this->sql = $sql;
                $this->params = $params;
            }

            public function queryOne()
            {
                return $this->db->definition;
            }

            public function queryScalar(): int
            {
                return $this->db->isComplete062() ? 1 : 0;
            }
        };
    }

    public function isComplete062(): bool
    {
        $definition = $this->definition;
        $requiredAliases = [
            'Fund Code', 'Fund Name', 'Fiscal Year', 'Allocated', 'Payments',
            'Calculated Current Encumbrances', 'Unreleased Count', 'Released Encumbrances',
            'Released Count', 'Total Committed', 'Calculated Remaining', 'FOLIO Expenditures',
            'FOLIO Encumbered', 'FOLIO Available',
        ];
        $aliases = [];
        if (preg_match_all('/AS "([^"]+)"/', (string)($definition['sql_template'] ?? ''), $matches)) {
            $aliases = $matches[1];
        }
        $requiredFragments = [
            "encumbrance__status IN ('Unreleased', 'Active', 'Released')",
            "encumbrance__status IN ('Unreleased', 'Active')",
            "encumbrance__status = 'Unreleased'",
            "encumbrance__status = 'Released'",
            'GROUP BY t.from_fund_id, t.fiscal_year_id',
        ];
        foreach ($requiredFragments as $fragment) {
            if (strpos((string)($definition['sql_template'] ?? ''), $fragment) === false) {
                return false;
            }
        }
        foreach (['Unreleased Count:', 'Released Encumbrances:', 'Released Count:', 'Pending', 'unreleased amount'] as $fragment) {
            if (stripos((string)($definition['help_text'] ?? ''), $fragment) === false) {
                return false;
            }
        }
        return ($definition['id'] ?? null) === 37
            && ($definition['slug'] ?? null) === 'budget-year-fund-report'
            && strpos((string)($definition['description'] ?? ''), 'released and unreleased encumbrances') !== false
            && $aliases === $requiredAliases;
    }
}

function budgetRecognitionCall(string $method, array $definition, string $case): ?bool
{
    global $failures;
    try {
        return BudgetYearFundReportMigrationRecognitionService::$method(new BudgetRecognitionTestDatabase($definition));
    } catch (Throwable $exception) {
        $failures[] = "{$case}: recognition threw '{$exception->getMessage()}'.";
        return null;
    }
}

$servicePath = __DIR__ . '/../services/BudgetYearFundReportMigrationRecognitionService.php';
$migrationServicePath = __DIR__ . '/../services/MigrationService.php';
$serviceSource = (string)file_get_contents($servicePath);
$migrationServiceSource = (string)file_get_contents($migrationServicePath);
$methodCases = [
    '035_budget_year_fund_report.sql' => 'budgetYearFundReportAppearsComplete',
    '036_budget_year_fund_report_fiscal_year_options.sql' => 'budgetYearFundReportFiscalYearOptionsAppearComplete',
    '037_budget_year_fund_report_payment_distributions.sql' => 'budgetYearFundReportPaymentDistributionsAppearComplete',
    '062_budget_year_fund_report_encumbrance_status.sql' => 'budgetYearFundReportEncumbranceStatusAppearComplete',
];
foreach ($methodCases as $filename => $method) {
    budgetRecognitionAssert(
        strpos($migrationServiceSource, "case '{$filename}': return BudgetYearFundReportMigrationRecognitionService::{$method}(") !== false
            && strpos($migrationServiceSource, "case '{$filename}': return BudgetYearFundReportMigrationRecognitionService::{$method}(" . '$db);') !== false,
        "MigrationService must delegate {$filename} recognition to the extracted service."
    );
    budgetRecognitionAssert(
        strpos($serviceSource, "function {$method}(" . '$db): bool') !== false,
        "The recognition service must expose {$method} with a bool result."
    );
}

if (preg_match('/private static function databaseAppearsCurrent\(\$db\): bool(.*?)\n    }\n\n    private static function migrationAppearsApplied/s', $migrationServiceSource, $baselineMatch) === 1) {
    budgetRecognitionAssert(
        strpos($baselineMatch[1], 'BudgetYearFundReportMigrationRecognitionService::budgetYearFundReportEncumbranceStatusAppearComplete($db)') !== false,
        'databaseAppearsCurrent must require the complete 062 report definition before baselining.'
    );
} else {
    budgetRecognitionAssert(false, 'Could not inspect databaseAppearsCurrent for 062 baseline recognition.');
}

$serviceLineCount = count(file($servicePath));
$migrationServiceLines = count(file($migrationServicePath));
budgetRecognitionAssert($serviceLineCount < 300, 'The extracted Budget Year Fund recognition service must stay below 300 lines.');
budgetRecognitionAssert($migrationServiceLines <= 771, 'MigrationService.php must remain at or below its 771-line limit.');

$canonical = budgetRecognitionDefinition();
$legacy037 = budgetRecognitionDefinition([
    'description' => 'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
    'sql_template' => str_replace(
        ["'Unreleased', 'Active', 'Released'", "'Released'", 'Unreleased Count', 'Released Encumbrances', 'Released Count'],
        ["'Unreleased', 'Active'", "'Unreleased'", 'Calculated Current Encumbrances', 'Total Committed', 'Calculated Remaining'],
        budgetRecognitionDefinition()['sql_template']
    ),
    'help_text' => 'Calculated Current Encumbrances: active or unreleased encumbrance transactions.',
]);
$missingAlias = budgetRecognitionDefinition([
    'sql_template' => str_replace('AS "Released Count"', 'AS "Removed Count"', $canonical['sql_template']),
]);
$wrongDescription = budgetRecognitionDefinition(['description' => 'Wrong report description.']);

$recognitionMethod = 'budgetYearFundReportEncumbranceStatusAppearComplete';
$canonicalResult = budgetRecognitionCall($recognitionMethod, $canonical, 'A complete 062 definition must be recognized');
if ($canonicalResult !== null) {
    budgetRecognitionAssert($canonicalResult === true, 'A complete 062 definition must be recognized.');
}
$missingAliasResult = budgetRecognitionCall($recognitionMethod, $missingAlias, 'A 062 definition with a missing alias must be rejected');
if ($missingAliasResult !== null) {
    budgetRecognitionAssert($missingAliasResult === false, 'A 062 definition with a missing alias must be rejected.');
}
$wrongDescriptionResult = budgetRecognitionCall($recognitionMethod, $wrongDescription, 'A 062 definition with the wrong description must be rejected');
if ($wrongDescriptionResult !== null) {
    budgetRecognitionAssert($wrongDescriptionResult === false, 'A 062 definition with the wrong description must be rejected.');
}
$legacyResult = budgetRecognitionCall($recognitionMethod, $legacy037, 'A 037-only definition must not satisfy 062 recognition');
if ($legacyResult !== null) {
    budgetRecognitionAssert($legacyResult === false, 'A database with the 037 definition must not be current until 062 runs.');
}

foreach ([
    'budgetYearFundReportAppearsComplete',
    'budgetYearFundReportFiscalYearOptionsAppearComplete',
    'budgetYearFundReportPaymentDistributionsAppearComplete',
] as $method) {
    $successorResult = budgetRecognitionCall($method, $canonical, "{$method} must accept the complete 062 successor");
    if ($successorResult !== null) {
        budgetRecognitionAssert($successorResult === true, "Migration recognition {$method} must accept the complete 062 successor.");
    }
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: Budget Year Fund Report migration recognition\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: Budget Year Fund Report migration recognition\n");
