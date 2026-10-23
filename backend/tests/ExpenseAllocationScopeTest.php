<?php

require_once __DIR__ . '/support/BudgetYearExpenseClassReportFixture.php';
require_once __DIR__ . '/../models/ExpenseAllocation.php';
require_once __DIR__ . '/../services/ExpenseAllocationService.php';

function expenseAllocationScopeAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE report_expense_allocations (id INTEGER PRIMARY KEY AUTOINCREMENT, fiscal_year INTEGER NOT NULL, expense_class_code VARCHAR(32) NOT NULL, allocation_amount NUMERIC NOT NULL, UNIQUE(fiscal_year, expense_class_code))');
$insert = $pdo->prepare('INSERT INTO report_expense_allocations (fiscal_year, expense_class_code, allocation_amount) VALUES (?, ?, ?)');
foreach ([
    [2025, 'SCBPS', 1000],
    [2025, 'SCBIS', 500],
    [2025, 'MHBPS', 800],
    [2025, 'UMADMOTHRDISCR', 250],
    [2026, 'SCBPS', 1200],
] as $row) {
    $insert->execute($row);
}

$copied = \app\services\ExpenseAllocationService::copyFromPreviousYear($pdo, 2026, ' sc ');
expenseAllocationScopeAssert($copied === [
    'copied' => 1,
    'skipped' => 1,
    'sourceYear' => 2025,
    'foundSource' => true,
], 'Copy only this college’s previous-year allocation rows and leave existing values untouched.');
$targetRows = $pdo->query('SELECT expense_class_code, allocation_amount FROM report_expense_allocations WHERE fiscal_year = 2026 ORDER BY expense_class_code')->fetchAll(PDO::FETCH_KEY_PAIR);
$targetRows = array_map('floatval', $targetRows);
expenseAllocationScopeAssert($targetRows === ['SCBIS' => 500.0, 'SCBPS' => 1200.0], 'Do not copy another college’s allocation codes.');
expenseAllocationScopeAssert(
    \app\services\ExpenseAllocationService::availableYears($pdo, 'SC') === [2026, 2025],
    'Return only years with allocations for the selected college.'
);

$mhCopy = \app\services\ExpenseAllocationService::copyFromPreviousYear($pdo, 2026, 'MH');
expenseAllocationScopeAssert($mhCopy['copied'] === 1 && $mhCopy['skipped'] === 0, 'Copy the selected college’s own codes.');
$allTargetCodes = $pdo->query('SELECT expense_class_code FROM report_expense_allocations WHERE fiscal_year = 2026 ORDER BY expense_class_code')->fetchAll(PDO::FETCH_COLUMN);
expenseAllocationScopeAssert($allTargetCodes === ['MHBPS', 'SCBIS', 'SCBPS'], 'Keep other colleges out of both copy operations.');

$rules = (new \app\models\ExpenseAllocation())->rules();
$lengthRules = array_values(array_filter($rules, function ($rule) {
    return isset($rule[0]) && is_array($rule[0])
        && in_array('expense_class_code', $rule[0], true)
        && isset($rule['max']);
}));
expenseAllocationScopeAssert(count($lengthRules) === 1 && $lengthRules[0]['max'] === 32, 'Accept FOLIO expense codes up to 32 characters.');

fwrite(STDOUT, "Expense allocation college-scope test passed\n");
