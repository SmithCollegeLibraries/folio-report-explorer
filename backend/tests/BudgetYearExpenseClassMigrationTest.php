<?php

require_once __DIR__ . '/support/BudgetYearExpenseClassReportFixture.php';

function budgetYearExpenseMigrationAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$path = __DIR__ . '/../../mysql/migrations/051_budget_year_expense_class_report.sql';
budgetYearExpenseMigrationAssert(file_exists($path), 'Migration 051 must exist.');
$sql = file_get_contents($path);
budgetYearExpenseMigrationAssert(is_string($sql), 'Read migration 051.');
budgetYearExpenseMigrationAssert(strpos($sql, 'ALTER TABLE `report_expense_allocations`') !== false, 'Widen the local allocation key.');
budgetYearExpenseMigrationAssert(
    strpos($sql, 'MODIFY COLUMN') !== false
        && strpos($sql, 'expense_class_code') !== false
        && strpos($sql, 'VARCHAR(32)') !== false,
    'Use VARCHAR(32) for allocation codes.'
);
budgetYearExpenseMigrationAssert(strpos($sql, 'ADD COLUMN IF NOT EXISTS') === false, 'Keep DDL compatible with MySQL 8 and MariaDB 10.3.');
budgetYearExpenseMigrationAssert(strpos($sql, 'ON DUPLICATE KEY UPDATE') !== false, 'Make the report seed safe to apply twice.');
budgetYearExpenseMigrationAssert(
    strpos($sql, 'UPPER(BTRIM(ect.code)) AS "Expense Code"') !== false,
    'Normalize report 34 expense-class codes for the allocation merge.'
);
budgetYearExpenseMigrationAssert(
    strpos($sql, 'budget-year-expense-class') !== false,
    'Update report 36 through migration 051.'
);
budgetYearExpenseMigrationAssert(
    strpos($sql, 'inv.fiscal_year_id = :invoiceFiscalYearId') !== false
        && strpos($sql, 'Optional start and end dates narrow') !== false
        && strpos($sql, 'Payment-date filters are not checked against the FOLIO fiscal-year period.') !== false,
    'Seed payments by invoice fiscal year and explain optional payment-date narrowing.'
);
budgetYearExpenseMigrationAssert(
    strpos($sql, 'WHERE fiscal_year = :fiscalYear') !== false
        && strpos($sql, 'Expense Class Code') !== false
        && strpos($sql, 'Remaining') !== false,
    'Preserve the local allocation merge and Remaining calculation.'
);
budgetYearExpenseMigrationAssert(
    strpos(file_get_contents(__DIR__ . '/../services/MigrationService.php'), "case '051_budget_year_expense_class_report.sql'") !== false,
    'Register migration 051 recognition through MigrationService.'
);

fwrite(STDOUT, "Budget-year expense class migration structure test passed\n");
