<?php

require_once __DIR__ . '/../services/ExpenseClassScopeService.php';

function expenseClassScopeServiceAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class ExpenseClassScopeServiceCommand
{
    private $sql;
    private $params;

    public function __construct($sql, array $params)
    {
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryOne()
    {
        if (strpos($this->sql, 'json_agg') !== false) {
            return [
                'funds' => '[{"id":"synthetic-sc-fund","code":"SCBPS","name":"Books"}]',
                'expense_classes' => '[{"code":"SCAP","name":"SC Books"}]',
                'fiscal_years' => '[{"year":2026,"code":"SCFY2026","startDate":"2025-07-02","endDate":"2026-06-30"}]',
            ];
        }
        throw new RuntimeException('Unexpected expense class scope query.');
    }

    public function queryAll()
    {
        $rows = [];
        if (strpos($this->sql, 'FROM finance.expense_class__t') !== false) {
            foreach ($this->params as $key => $code) {
                if (strpos($key, ':class_') === 0 && $this->params[':classPattern'] === 'SC%'
                    && $code === 'SCAP') {
                    $rows[] = ['expense_class_code' => 'SCAP'];
                }
            }
        }
        return $rows;
    }
}

final class ExpenseClassScopeServiceDb
{
    public function createCommand($sql, array $params = [])
    {
        return new ExpenseClassScopeServiceCommand($sql, $params);
    }
}

$db = new ExpenseClassScopeServiceDb();
$options = \app\services\ExpenseClassScopeService::getOptions($db, ' sc ');
expenseClassScopeServiceAssert($options['prefix'] === 'SC', 'Normalize the selected FOLIO acquisitions unit.');
expenseClassScopeServiceAssert($options['funds'][0]['defaultSelected'] === true, 'Mark only Smith’s approved Book fund as selected by default.');
expenseClassScopeServiceAssert($options['funds'][0]['label'] === 'SCBPS — Books', 'Label a fund by code and FOLIO name.');
expenseClassScopeServiceAssert($options['fiscalYears'][0]['code'] === 'SCFY2026', 'Return the exact college fiscal-year option.');

\app\services\ExpenseClassScopeService::assertExpenseClassesBelongToPrefix($db, 'sc', [' scap ']);
try {
    \app\services\ExpenseClassScopeService::assertExpenseClassesBelongToPrefix($db, 'SC', ['MHBPS']);
    expenseClassScopeServiceAssert(false, 'Reject a class outside the selected college.');
} catch (InvalidArgumentException $error) {
    expenseClassScopeServiceAssert(strpos($error->getMessage(), 'selected college') !== false, 'Return a college-scope validation error.');
}

fwrite(STDOUT, "Expense class scope service test passed\n");
