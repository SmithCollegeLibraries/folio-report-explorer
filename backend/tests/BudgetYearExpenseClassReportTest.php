<?php

namespace {
    require_once __DIR__ . '/support/BudgetYearExpenseClassReportFixture.php';
    require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';
    require_once __DIR__ . '/../services/ReportExecutionContractService.php';
    require_once __DIR__ . '/../services/FolioSchemaService.php';
    require_once __DIR__ . '/../services/SqlBuilderService.php';
    require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';

    function budgetYearExpenseClassAssert($condition, $message)
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function budgetYearExpenseClassFieldError($compile, array $overrides, $field, $message)
    {
        try {
            $compile($overrides);
        } catch (\app\exceptions\ReportParameterValidationException $error) {
            budgetYearExpenseClassAssert(isset($error->getFieldErrors()[$field]), $message . ' (field error)');
            return;
        }
        budgetYearExpenseClassAssert(false, $message);
    }

    final class BudgetYearExpenseClassLookupCommand
    {
        private $db;
        private $sql;
        private $params;

        public function __construct($db, $sql, array $params)
        {
            $this->db = $db;
            $this->sql = $sql;
            $this->params = $params;
        }

        public function queryOne()
        {
            $this->db->queries[] = ['sql' => $this->sql, 'params' => $this->params];
            if (strpos($this->sql, 'to_regclass(') !== false) {
                $row = [];
                foreach ($this->params as $key => $table) {
                    if (preg_match('/\A:requiredTable(\d+)\z/', $key, $matches) === 1) {
                        $alias = 'required_table_' . $matches[1];
                        $row[$alias] = $table === $this->db->missingTable ? null : $table;
                    }
                }
                return $row;
            }
            if (strpos($this->sql, 'FROM finance.fiscal_year__t') !== false) {
                $code = $this->params[':fiscalYearCode'] ?? '';
                return $this->db->fiscalYears[$code] ?? false;
            }
            throw new \RuntimeException('Unexpected budget-year expense class lookup query.');
        }

        public function queryAll()
        {
            $this->db->queries[] = ['sql' => $this->sql, 'params' => $this->params];
            if (strpos($this->sql, 'FROM finance.fund__t__acq_unit_ids') !== false) {
                $unit = $this->params[':acqUnit'] ?? '';
                $selected = [];
                foreach ($this->params as $key => $value) {
                    if (preg_match('/\A:fund_\d+\z/', $key) === 1
                        && in_array($value, $this->db->fundsByUnit[$unit] ?? [], true)) {
                        $selected[] = ['fund_id' => $value];
                    }
                }
                return $selected;
            }
            throw new \RuntimeException('Unexpected budget-year expense class row query.');
        }
    }

    final class BudgetYearExpenseClassLookupDb
    {
        public $missingTable = null;
        public $queries = [];
        public $fiscalYears = [
            'SCFY2026' => ['id' => 'fy-sc-2026', 'period_start' => '2025-07-02', 'period_end' => '2026-06-30'],
            'MHFY2026' => ['id' => 'fy-mh-2026', 'period_start' => '2025-07-01', 'period_end' => '2026-06-30'],
        ];
        public $fundsByUnit = [
            'SC' => ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'],
            'MH' => ['33333333-3333-4333-8333-333333333333', '44444444-4444-4444-8444-444444444444'],
        ];

        public function createCommand($sql, array $params = [])
        {
            return new BudgetYearExpenseClassLookupCommand($this, $sql, $params);
        }
    }

    function budgetYearExpenseClassDefinition($report)
    {
        $definition = [];
        foreach ([
            'slug', 'name', 'description', 'help_text', 'category', 'sql_template', 'parameters',
            'data_source', 'execution_config', 'composite_config', 'default_limit', 'is_active', 'created_by',
        ] as $key) {
            $definition[$key] = $report->$key;
        }
        return $definition;
    }

    $probe = new \app\models\ReportTemplate();
    $probe->slug = 'budget-year-expense-class';
    budgetYearExpenseClassAssert(
        \app\services\CatalogingReportCompilerService::supports($probe),
        'The governed compiler must support the budget-year expense class report.'
    );

    $report = budgetYearExpenseClassReportFixture();
    $db = new BudgetYearExpenseClassLookupDb();
    $scFunds = '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222';
    $base = ['acqUnit' => 'SC', 'fiscalYear' => '2026', 'startDate' => '', 'endDate' => '', 'funds' => $scFunds];
    $compile = function ($overrides = []) use ($report, $db, $base) {
        return \app\services\CatalogingReportCompilerService::build($report, array_replace($base, $overrides), $db);
    };

    budgetYearExpenseClassAssert(
        \app\services\BudgetYearExpenseClassReportService::supports($report),
        'Support the fixed budget-year expense class slug.'
    );
    budgetYearExpenseClassAssert(
        \app\services\BudgetYearExpenseClassReportService::isCanonicalSeedDefinition(budgetYearExpenseClassDefinition($report)),
        'Accept the canonical migration 051 seed.'
    );
    $compiled = $compile(['acqUnit' => ' sc ']);
    budgetYearExpenseClassAssert(count($db->queries) === 3, 'Probe schema, fiscal-year row, and fund membership once each.');
    budgetYearExpenseClassAssert($compiled['location']['code'] === 'SC', 'Normalize the selected acquisitions unit.');
    budgetYearExpenseClassAssert($compiled['params'][':expenseClassPattern'] === 'SC%', 'Bind the normalized college class-name prefix.');
    budgetYearExpenseClassAssert($compiled['params'][':fiscalYearId'] === 'fy-sc-2026', 'Bind the exact selected fiscal-year row.');
    budgetYearExpenseClassAssert(
        $compiled['params'][':invoiceFiscalYearId'] === 'fy-sc-2026',
        'Bind the same exact fiscal-year row to invoice payments.'
    );
    budgetYearExpenseClassAssert(
        $compiled['params'][':startDate'] === null && $compiled['params'][':endDate'] === null,
        'Leave payment-date filters empty by default.'
    );
    budgetYearExpenseClassAssert(
        $compiled['params'][':fund_0'] === '11111111-1111-4111-8111-111111111111'
            && $compiled['params'][':fund_1'] === '22222222-2222-4222-8222-222222222222',
        'Bind every selected fund UUID separately.'
    );
    budgetYearExpenseClassAssert(
        strpos($compiled['sql'], $scFunds) === false && strpos($compiled['sql'], ':fundIds') === false,
        'Never interpolate fund IDs or leave the structural list marker in SQL.'
    );
    budgetYearExpenseClassAssert(
        strpos($compiled['sql'], 'orders.purchase_order__t__acq_unit_ids') === false,
        'Do not require a PO unit link for payment rows.'
    );
    budgetYearExpenseClassAssert($compiled['compositeParams'][':fiscalYear'] === '2026', 'Bind the selected year for the local allocation merge.');
    \app\services\ReportExecutionContractService::assertStaticExportSql($compiled['sql'], ['fetchRowLimit' => 100001]);
    \app\services\SqlBuilderService::validateSafety($compiled['sql']);
    \app\services\SqlBuilderService::validateTablePolicy($compiled['sql']);

    $parameters = json_decode($report->parameters, true);
    budgetYearExpenseClassAssert(
        array_column($parameters, 'name') === ['acqUnit', 'fiscalYear', 'startDate', 'endDate', 'funds'],
        'Declare the five approved report parameters in order.'
    );
    budgetYearExpenseClassAssert($parameters[0]['default'] === '', 'Do not default the report to Smith.');
    budgetYearExpenseClassAssert(
        $parameters[4]['type'] === 'multiselect' && $parameters[4]['required'] === true,
        'Require the unit-specific fund multiselect.'
    );
    budgetYearExpenseClassAssert(
        $parameters[2]['required'] === false && $parameters[3]['required'] === false,
        'Make payment-date filters optional.'
    );
    budgetYearExpenseClassAssert(
        strpos($compiled['sql'], 'inv.fiscal_year_id = :invoiceFiscalYearId') !== false,
        'Assign payments by the invoice fiscal-year row.'
    );

    budgetYearExpenseClassFieldError($compile, ['acqUnit' => 'SC; DROP TABLE'], 'acqUnit', 'Reject a unit outside the fixed whitelist.');
    budgetYearExpenseClassFieldError($compile, ['fiscalYear' => '2026.5'], 'fiscalYear', 'Reject a non-integer fiscal year.');
    budgetYearExpenseClassFieldError($compile, ['fiscalYear' => '2025'], 'fiscalYear', 'Reject a missing exact unit fiscal-year row.');
    $outsidePeriod = $compile(['startDate' => '2025-07-01', 'endDate' => '2026-07-01']);
    budgetYearExpenseClassAssert(
        $outsidePeriod['params'][':startDate'] === '2025-07-01'
            && $outsidePeriod['params'][':endDate'] === '2026-07-01',
        'Allow payment-date filters outside the FOLIO period boundary.'
    );
    $oneSided = $compile(['startDate' => '2025-07-03']);
    budgetYearExpenseClassAssert(
        $oneSided['params'][':startDate'] === '2025-07-03' && $oneSided['params'][':endDate'] === null,
        'Allow a single optional payment-date bound.'
    );
    $oneSidedEnd = $compile(['endDate' => '2026-07-01']);
    budgetYearExpenseClassAssert(
        $oneSidedEnd['params'][':startDate'] === null && $oneSidedEnd['params'][':endDate'] === '2026-07-01',
        'Allow an end-date-only payment filter.'
    );
    budgetYearExpenseClassFieldError($compile, ['startDate' => '2025-07-04', 'endDate' => '2025-07-03'], 'endDate', 'Reject a reversed payment-date range.');
    budgetYearExpenseClassFieldError($compile, ['funds' => ''], 'funds', 'Require at least one selected fund.');
    budgetYearExpenseClassFieldError($compile, ['funds' => 'not-a-uuid'], 'funds', 'Reject fund values that are not UUIDs.');
    budgetYearExpenseClassFieldError(
        $compile,
        ['acqUnit' => 'MH', 'funds' => '11111111-1111-4111-8111-111111111111'],
        'funds',
        'Reject a fund associated with another college.'
    );

    $originalSql = $report->sql_template;
    $report->sql_template = 'SELECT 1';
    try {
        $compile();
        budgetYearExpenseClassAssert(false, 'Reject SQL drift from migration 051.');
    } catch (\InvalidArgumentException $error) {
        budgetYearExpenseClassAssert(strpos($error->getMessage(), 'seed contract') !== false, 'Identify report definition drift.');
    }
    $report->sql_template = $originalSql;

    $db->missingTable = 'finance.transaction__t';
    try {
        $compile();
        budgetYearExpenseClassAssert(false, 'Reject missing required LDP tables.');
    } catch (\InvalidArgumentException $error) {
        budgetYearExpenseClassAssert(strpos($error->getMessage(), 'finance.transaction__t') !== false, 'Name the missing LDP table.');
    }

    fwrite(STDOUT, "Budget-year expense class report compiler test passed\n");
}
