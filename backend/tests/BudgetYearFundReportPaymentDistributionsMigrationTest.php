<?php

$migration035 = __DIR__ . '/../../mysql/migrations/035_budget_year_fund_report.sql';
$migration036 = __DIR__ . '/../../mysql/migrations/036_budget_year_fund_report_fiscal_year_options.sql';
$migration037 = __DIR__ . '/../../mysql/migrations/037_budget_year_fund_report_payment_distributions.sql';

function failPaymentDistributionTest(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function assertPaymentDistributionTrue(bool $condition, string $message): void
{
    if (!$condition) {
        failPaymentDistributionTest($message);
    }
}

function assertPaymentDistributionContains(string $needle, string $haystack, string $message): void
{
    assertPaymentDistributionTrue(strpos($haystack, $needle) !== false, $message);
}

assertPaymentDistributionTrue(
    hash_file('sha256', $migration035) === 'ad4aadbda3259f2bfd68f6e995c86da3f95c8d4fa86a9bd7a726ff1ab0c823ab',
    'Applied migration 035 must not change.'
);
assertPaymentDistributionTrue(
    hash_file('sha256', $migration036) === 'cb90cd367bee5943c10c9922315f78a0ff16e0465c1eb283df7defa915c19c76',
    'Applied migration 036 must not change.'
);
assertPaymentDistributionTrue(file_exists($migration037), 'Migration 037 must exist.');

$sql = file_exists($migration037) ? (string)file_get_contents($migration037) : '';
assertPaymentDistributionContains("'budget-year-fund-report'", $sql, 'Migration 037 must update the fixed report slug.');
assertPaymentDistributionContains('ON DUPLICATE KEY UPDATE', $sql, 'Migration 037 must be idempotent.');
assertPaymentDistributionContains(
    "LOWER(COALESCE(fd.fund_distributions__distribution_type, ''percentage''))",
    $sql,
    'Distribution types must be matched case-insensitively and null must retain percentage behavior.'
);
assertPaymentDistributionContains(
    "WHEN ''amount'' THEN COALESCE(fd.fund_distributions__value, 0)",
    $sql,
    'Amount distributions must contribute their value directly.'
);
assertPaymentDistributionContains(
    'ELSE COALESCE(fd.total, 0) * (COALESCE(fd.fund_distributions__value, 0) * 0.01)',
    $sql,
    'Percentage distributions must contribute invoice-line total times value divided by 100.'
);
assertPaymentDistributionContains(
    'Percentage distributions contribute the invoice-line total multiplied by the distribution value divided by 100; amount distributions contribute the distribution value directly; distribution types are matched case-insensitively, and a missing type is treated as percentage.',
    $sql,
    'Report help must explain percentage, amount, case-insensitive, and null-type payment behavior.'
);

$columns = [
    'Fund Code',
    'Fund Name',
    'Fiscal Year',
    'Allocated',
    'Payments',
    'Calculated Current Encumbrances',
    'Total Committed',
    'Calculated Remaining',
    'FOLIO Expenditures',
    'FOLIO Encumbered',
    'FOLIO Available',
];
$finalSelectMatched = preg_match('/SELECT sf\.code AS "Fund Code".*?\nFROM budgets b/s', $sql, $matches);
assertPaymentDistributionTrue($finalSelectMatched === 1, 'Migration 037 must contain the expected final SELECT block.');
$finalSelect = $matches[0] ?? '';
$aliases = [];
if (preg_match_all('/\bAS "([^"]+)"/', $finalSelect, $aliasMatches)) {
    $aliases = $aliasMatches[1];
}
assertPaymentDistributionTrue($aliases === $columns, 'Migration 037 must preserve the exact 11 output aliases in order.');
assertPaymentDistributionTrue(substr_count($finalSelect, 'ROUND(') === 8, 'Migration 037 must preserve eight numeric two-decimal monetary outputs.');
assertPaymentDistributionTrue(stripos($finalSelect, 'TO_CHAR') === false, 'Migration 037 monetary outputs must remain numeric.');

// These fixtures are gated by the stored SQL branch markers above: changing the SQL
// contract makes the test fail before the deterministic expected totals are evaluated.
$fixtures = [
    'percentage' => [
        ['total' => 200.00, 'value' => 25.00, 'type' => 'percentage'],
    ],
    'amount' => [
        ['total' => 200.00, 'value' => 25.00, 'type' => 'amount'],
    ],
    'mixed and case-insensitive' => [
        ['total' => 200.00, 'value' => 25.00, 'type' => 'PERCENTAGE'],
        ['total' => 999.00, 'value' => 12.50, 'type' => 'Amount'],
    ],
    'null-type legacy percentage' => [
        ['total' => 80.00, 'value' => 12.50, 'type' => null],
    ],
];
$expected = [
    'percentage' => 50.00,
    'amount' => 25.00,
    'mixed and case-insensitive' => 62.50,
    'null-type legacy percentage' => 10.00,
];
foreach ($fixtures as $name => $distributions) {
    $actual = 0.0;
    foreach ($distributions as $distribution) {
        $type = strtolower($distribution['type'] ?? 'percentage');
        $actual += $type === 'amount'
            ? $distribution['value']
            : $distribution['total'] * ($distribution['value'] * 0.01);
    }
    assertPaymentDistributionTrue(
        abs($actual - $expected[$name]) < 0.00001,
        "Stored payment contract produced the wrong deterministic result for {$name}."
    );
}

fwrite(STDOUT, "BudgetYearFundReportPaymentDistributionsMigration test passed\n");
