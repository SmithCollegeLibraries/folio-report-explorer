<?php

require_once __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;

$finalRecognitionFailures = [];

function assertFinalRecognitionSame($expected, $actual, string $message): void
{
    global $finalRecognitionFailures;
    if ($expected !== $actual) {
        $finalRecognitionFailures[] = $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true);
    }
}

function finalRevisionParameters(): array
{
    return [
        [
            'name' => 'fiscalYearEndYear',
            'type' => 'select',
            'label' => 'Fiscal Year',
            'default' => '',
            'required' => true,
            'description' => 'Campus-neutral fiscal year; the selected acquisition unit determines the matching FOLIO fiscal-year series.',
            'options_sql' => "SELECT DISTINCT EXTRACT(YEAR FROM period_end::date)::int AS value, 'FY' || EXTRACT(YEAR FROM period_end::date)::int::text AS label FROM finance.fiscal_year__t WHERE period_end IS NOT NULL ORDER BY value DESC",
            'options_db' => 'folio',
            'placeholder' => 'Select fiscal year',
        ],
        [
            'name' => 'acqUnitId',
            'type' => 'select',
            'label' => 'Acquisition Unit',
            'default' => '',
            'required' => true,
            'description' => 'Determines both fund membership and the campus fiscal-year series.',
            'options_sql' => 'SELECT id AS value, TRIM(name) AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY TRIM(name)',
            'options_db' => 'folio',
            'placeholder' => 'Select acquisition unit',
        ],
    ];
}

function finalRevisionSql(): string
{
    return <<<'SQL'
WITH selected_acquisition_unit AS (
  SELECT id, TRIM(name) AS code FROM orders.acquisitions_unit__t WHERE id = :acqUnitId
), fiscal_years AS (
  SELECT fy.id, fy.period_start::date AS period_start, fy.period_end::date AS period_end
  FROM finance.fiscal_year__t fy CROSS JOIN selected_acquisition_unit au
  WHERE fy.series = au.code || 'FY'
), encumbrances AS (
  SELECT SUM(COALESCE(t.encumbrance__initial_amount_encumbered, 0)
    - COALESCE(t.encumbrance__amount_expended, 0)
    - COALESCE(t.encumbrance__amount_awaiting_payment, 0)) AS current_encumbrance
  FROM finance.transaction__t t JOIN fiscal_years fy ON fy.id = t.fiscal_year_id
  WHERE t.transaction_type = 'Encumbrance' AND t.encumbrance__status IN ('Unreleased', 'Active')
), payments AS (
  SELECT SUM(CASE LOWER(COALESCE(fd.fund_distributions__distribution_type, 'percentage'))
    WHEN 'amount' THEN COALESCE(fd.fund_distributions__value, 0)
    ELSE COALESCE(fd.total, 0) * (COALESCE(fd.fund_distributions__value, 0) * 0.01)
  END) AS payment
  FROM invoice.invoice_lines__t__fund_distributions fd
)
SELECT sf.code AS "Fund Code", sf.name AS "Fund Name", fy.id AS "Fiscal Year",
  ROUND(b.allocated, 2) AS "Allocated", ROUND(p.payment, 2) AS "Payments",
  ROUND(e.current_encumbrance, 2) AS "Calculated Current Encumbrances",
  ROUND(p.payment + e.current_encumbrance, 2) AS "Total Committed",
  ROUND(COALESCE(b.allocated, 0) - COALESCE(p.payment, 0) - COALESCE(e.current_encumbrance, 0), 2) AS "Calculated Remaining",
  ROUND(b.expenditures, 2) AS "FOLIO Expenditures",
  ROUND(b.encumbered, 2) AS "FOLIO Encumbered", ROUND(b.available, 2) AS "FOLIO Available"
SQL;
}

function finalRevisionHelp(): string
{
    return 'Allocated: the allocation total stored on the FOLIO budget. '
        . 'Payments: paid invoice-line fund distributions whose invoice payment date falls inside the resolved FOLIO fiscal year. '
        . 'Percentage distributions contribute the invoice-line total multiplied by the distribution value divided by 100; amount distributions contribute the distribution value directly; distribution types are matched case-insensitively, and a missing type is treated as percentage. '
        . 'Calculated Current Encumbrances: active or unreleased encumbrance transactions, calculated as initial amount minus expended amount minus awaiting-payment amount. '
        . 'Total Committed: Payments plus Calculated Current Encumbrances. '
        . 'Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances. '
        . 'FOLIO Expenditures: the expenditure total currently stored on the FOLIO budget. '
        . 'FOLIO Encumbered: the encumbrance total currently stored on the FOLIO budget. '
        . 'FOLIO Available: the operational available balance stored on the FOLIO budget.';
}

function finalRevisionReport(array $overrides = []): array
{
    return array_merge([
        'id' => 37,
        'slug' => 'budget-year-fund-report',
        'name' => 'Budget Year Fund Report',
        'description' => 'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
        'category' => 'finance',
        'data_source' => 'folio',
        'default_limit' => 1000,
        'is_active' => 1,
        'created_by' => 'manual',
        'sql_template' => finalRevisionSql(),
        'help_text' => finalRevisionHelp(),
        'parameters' => finalRevisionParameters(),
    ], $overrides);
}

class FinalRevisionColumnSchema
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

class FinalRevisionSchema
{
    public function getTableSchema(string $table, bool $refresh = false)
    {
        $tables = [
            'users',
            'query_jobs',
            'report_templates',
            'ai_clarification_events',
            'ai_query_feedback',
            'folio_reference_tables',
            'ai_report_generations',
            'ai_report_reviews',
        ];
        if (!in_array($table, $tables, true)) {
            return null;
        }

        return new FinalRevisionColumnSchema($table === 'report_templates' ? ['help_text'] : []);
    }
}

class FinalRevisionDatabase
{
    public $schema;
    private $report;

    public function __construct(array $report)
    {
        $this->schema = new FinalRevisionSchema();
        $this->report = $report;
    }

    public function createCommand(string $sql = '', array $params = []): FinalRevisionCommand
    {
        return new FinalRevisionCommand($this->report, $sql, $params);
    }
}

class FinalRevisionCommand
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
        if (strpos($this->sql, ':payment_type_marker') === false) {
            return 0;
        }

        $requiredSqlFragments = [
            'id = 37 AND slug = :slug',
            'JSON_LENGTH(parameters) = 2',
            'sql_template LIKE :payment_type_marker',
            'sql_template LIKE :payment_amount_marker',
            'sql_template LIKE :payment_percentage_marker',
            'sql_template LIKE :encumbrance_scope_marker',
            'sql_template LIKE :encumbrance_arithmetic_marker',
            'sql_template LIKE :encumbrance_expended_marker',
            'sql_template LIKE :encumbrance_awaiting_marker',
            'sql_template LIKE :calculated_remaining_marker',
        ];
        foreach ([0, 1] as $index) {
            foreach (['name', 'type', 'label', 'default', 'required', 'description', 'options_sql', 'options_db', 'placeholder'] as $field) {
                $requiredSqlFragments[] = "JSON_EXTRACT(parameters, '$[{$index}].{$field}')";
            }
        }
        foreach (range(0, 10) as $index) {
            $requiredSqlFragments[] = "sql_template LIKE :output_alias_{$index}";
        }
        foreach (['allocated', 'payments', 'payment_semantics', 'encumbrances', 'total_committed', 'calculated_remaining', 'folio_expenditures', 'folio_encumbered', 'folio_available'] as $help) {
            $requiredSqlFragments[] = "help_text LIKE :help_{$help}";
        }
        foreach ($requiredSqlFragments as $fragment) {
            if (strpos($this->sql, $fragment) === false) {
                return 0;
            }
        }

        $stable = [
            'id' => 37,
            'slug' => 'budget-year-fund-report',
            'name' => 'Budget Year Fund Report',
            'description' => 'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
            'category' => 'finance',
            'data_source' => 'folio',
            'default_limit' => 1000,
            'is_active' => 1,
            'created_by' => 'manual',
        ];
        foreach ($stable as $field => $value) {
            if (($this->report[$field] ?? null) !== $value) {
                return 0;
            }
        }
        if (($this->report['parameters'] ?? null) !== finalRevisionParameters()) {
            return 0;
        }

        foreach ($this->params as $name => $pattern) {
            if (strpos($name, ':output_alias_') === 0
                || strpos($name, ':payment_') === 0
                || strpos($name, ':encumbrance_') === 0
                || $name === ':calculated_remaining_marker'
                || $name === ':series_marker') {
                if (stripos($this->report['sql_template'] ?? '', trim($pattern, '%')) === false) {
                    return 0;
                }
            }
            if (strpos($name, ':help_') === 0
                && stripos($this->report['help_text'] ?? '', trim($pattern, '%')) === false) {
                return 0;
            }
        }

        return 1;
    }
}

function finalMigrationAppearsApplied(array $report, string $filename): bool
{
    static $method;
    if ($method === null) {
        $method = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
    }

    return $method->invoke(null, new FinalRevisionDatabase($report), $filename);
}

function finalDatabaseAppearsCurrent(array $report): bool
{
    static $method;
    if ($method === null) {
        $method = new ReflectionMethod(MigrationService::class, 'databaseAppearsCurrent');
    }

    return $method->invoke(null, new FinalRevisionDatabase($report));
}

$complete = finalRevisionReport();
assertFinalRecognitionSame(true, finalMigrationAppearsApplied($complete, '037_budget_year_fund_report_payment_distributions.sql'), 'Migration 037 must recognize the complete final report contract.');
assertFinalRecognitionSame(false, finalDatabaseAppearsCurrent($complete), 'A migration-037-only fixture must not baseline without the later MARC missing-tag report contract.');

$parameters = finalRevisionParameters();
$negativeCases = [
    'incomplete first parameter object' => ['parameters' => [array_diff_key($parameters[0], ['options_sql' => true]), $parameters[1]]],
    'wrong parameter order' => ['parameters' => [$parameters[1], $parameters[0]]],
    'wrong required flag' => ['parameters' => [array_merge($parameters[0], ['required' => false]), $parameters[1]]],
    'stale dynamic fiscal-year options SQL' => ['parameters' => [array_merge($parameters[0], ['options_sql' => 'SELECT 2027 AS value']), $parameters[1]]],
    'wrong acquisition-unit parameter type' => ['parameters' => [$parameters[0], array_merge($parameters[1], ['type' => 'text'])]],
    'stale dynamic acquisition-unit options SQL' => ['parameters' => [$parameters[0], array_merge($parameters[1], ['options_sql' => 'SELECT id AS value FROM orders.acquisitions_unit__t'])]],
    'missing Fund Code alias' => ['sql_template' => str_replace(' AS "Fund Code"', '', finalRevisionSql())],
    'missing FOLIO Available alias' => ['sql_template' => str_replace(' AS "FOLIO Available"', '', finalRevisionSql())],
    'missing payment type normalization' => ['sql_template' => str_replace("LOWER(COALESCE(fd.fund_distributions__distribution_type, 'percentage'))", "fd.fund_distributions__distribution_type", finalRevisionSql())],
    'missing amount distribution arithmetic' => ['sql_template' => str_replace("WHEN 'amount' THEN COALESCE(fd.fund_distributions__value, 0)", "WHEN 'amount' THEN 0", finalRevisionSql())],
    'missing percentage distribution arithmetic' => ['sql_template' => str_replace("ELSE COALESCE(fd.total, 0) * (COALESCE(fd.fund_distributions__value, 0) * 0.01)", 'ELSE 0', finalRevisionSql())],
    'missing encumbrance status scope' => ['sql_template' => str_replace("t.encumbrance__status IN ('Unreleased', 'Active')", 'TRUE', finalRevisionSql())],
    'missing encumbrance arithmetic' => ['sql_template' => str_replace('- COALESCE(t.encumbrance__amount_expended, 0)', '', finalRevisionSql())],
    'missing calculated remaining' => ['sql_template' => str_replace('COALESCE(b.allocated, 0) - COALESCE(p.payment, 0) - COALESCE(e.current_encumbrance, 0)', 'COALESCE(b.allocated, 0)', finalRevisionSql())],
    'missing Payments help' => ['help_text' => str_replace('Payments: paid invoice-line fund distributions', 'Spending: paid invoice-line fund distributions', finalRevisionHelp())],
    'missing payment distribution semantics help' => ['help_text' => str_replace('Percentage distributions contribute the invoice-line total multiplied by the distribution value divided by 100; amount distributions contribute the distribution value directly; distribution types are matched case-insensitively, and a missing type is treated as percentage.', '', finalRevisionHelp())],
    'missing FOLIO Available help' => ['help_text' => str_replace('FOLIO Available: the operational available balance stored on the FOLIO budget.', '', finalRevisionHelp())],
];
foreach ($negativeCases as $name => $overrides) {
    $report = finalRevisionReport($overrides);
    assertFinalRecognitionSame(false, finalMigrationAppearsApplied($report, '037_budget_year_fund_report_payment_distributions.sql'), "Migration 037 must reject {$name}.");
    assertFinalRecognitionSame(false, finalDatabaseAppearsCurrent($report), "Current-database recognition must reject {$name}.");
}

if ($finalRecognitionFailures !== []) {
    fwrite(STDERR, implode("\n\n", $finalRecognitionFailures) . "\n");
    exit(1);
}

fwrite(STDOUT, "BudgetYearFundReportFinalRevisionRecognition test passed\n");
