<?php

$migration035 = __DIR__ . '/../../mysql/migrations/035_budget_year_fund_report.sql';
$migration036 = __DIR__ . '/../../mysql/migrations/036_budget_year_fund_report_fiscal_year_options.sql';

function failRevisionTest(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function assertRevisionTrue(bool $condition, string $message): void
{
    if (!$condition) {
        failRevisionTest($message);
    }
}

function assertRevisionContains(string $needle, string $haystack, string $message): void
{
    assertRevisionTrue(strpos($haystack, $needle) !== false, $message);
}

assertRevisionTrue(
    hash_file('sha256', $migration035) === 'ad4aadbda3259f2bfd68f6e995c86da3f95c8d4fa86a9bd7a726ff1ab0c823ab',
    'Applied migration 035 must not change.'
);
assertRevisionTrue(file_exists($migration036), 'Migration 036 must exist.');

$sql = file_exists($migration036) ? (string)file_get_contents($migration036) : '';
assertRevisionContains("'budget-year-fund-report'", $sql, 'Migration 036 must update the fixed report slug.');
assertRevisionContains('ON DUPLICATE KEY UPDATE', $sql, 'Migration 036 must be idempotent.');
assertRevisionContains('SELECT DISTINCT EXTRACT(YEAR FROM period_end::date)::int AS value', $sql, 'Fiscal-year options must group campus rows by end year.');
assertRevisionContains("''FY'' || EXTRACT(YEAR FROM period_end::date)::int::text AS label", $sql, 'Fiscal-year labels must be generated as FY####.');
assertRevisionContains('TRIM(name) AS label', $sql, 'Acquisition-unit labels must be trimmed.');
assertRevisionContains("fy.series = au.code || ''FY''", $sql, 'The FOLIO fiscal-year series must be inferred from the acquisition unit.');
assertRevisionContains('CAST(:fiscalYearEndYear AS integer)', $sql, 'The selected year must remain a bound parameter.');
assertRevisionContains(':acqUnitId', $sql, 'The selected acquisition unit must remain a bound parameter.');
assertRevisionContains('COALESCE(b.allocated, 0) <> 0', $sql, 'Only allocated funds must be returned.');
assertRevisionContains('inv.payment_date::date BETWEEN fy.period_start AND fy.period_end', $sql, 'Payment dates must use FOLIO fiscal-year dates.');
assertRevisionContains("t.encumbrance__status IN (''Unreleased'', ''Active'')", $sql, 'Current encumbrances must use active and unreleased transactions.');
assertRevisionTrue(preg_match("/''[A-Z]{2}FY''/i", $sql) !== 1, 'Migration 036 must not hardcode a two-letter campus series.');
assertRevisionTrue(preg_match('/FY[0-9]{4}/', $sql) !== 1, 'Migration 036 must not hardcode a fiscal-year label.');
assertRevisionTrue(preg_match("/''[0-9]{4}-[0-9]{2}-[0-9]{2}''/", $sql) !== 1, 'Migration 036 must not hardcode an ISO date literal.');
assertRevisionTrue(preg_match('/\b(?:19|20)[0-9]{2}\b/', $sql) !== 1, 'Migration 036 must not hardcode a four-digit fiscal year or date component.');

$parameterNames = [];
if (preg_match_all('/"name":"([^"]+)"/', $sql, $matches)) {
    $parameterNames = $matches[1];
}
assertRevisionTrue($parameterNames === ['fiscalYearEndYear', 'acqUnitId'], 'Migration 036 must expose exactly the two approved parameters.');
assertRevisionTrue(substr_count($sql, '"required":true') === 2, 'Both report parameters must be required.');
assertRevisionTrue(substr_count($sql, '"type":"select"') === 2, 'Both report parameters must be dropdowns.');

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
$finalSelectMatched = preg_match(
    '/SELECT sf\.code AS "Fund Code".*?\nFROM budgets b/s',
    $sql,
    $finalSelectMatches
);
assertRevisionTrue($finalSelectMatched === 1, 'Migration 036 must contain the expected final SELECT block.');
$finalSelectBlock = $finalSelectMatches[0] ?? '';
$finalAliases = [];
if (preg_match_all('/\bAS "([^"]+)"/', $finalSelectBlock, $aliasMatches)) {
    $finalAliases = $aliasMatches[1];
}
assertRevisionTrue($finalAliases === $columns, 'The final SELECT must expose exactly the 11 approved columns in order.');
assertRevisionTrue(substr_count($finalSelectBlock, 'ROUND(') === 8, 'Exactly eight final monetary outputs must use ROUND.');
assertRevisionTrue(stripos($finalSelectBlock, 'TO_CHAR') === false, 'Final monetary outputs must remain numeric.');
assertRevisionTrue(strpos($sql, 'Remaining Difference') === false, 'The concise report must omit difference columns.');
$helpDefinitions = [
    'Allocated: the allocation total stored on the FOLIO budget.',
    'Payments: paid invoice-line fund distributions whose invoice payment date falls inside the resolved FOLIO fiscal year.',
    'Calculated Current Encumbrances: active or unreleased encumbrance transactions, calculated as initial amount minus expended amount minus awaiting-payment amount.',
    'Total Committed: Payments plus Calculated Current Encumbrances.',
    'Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.',
    'FOLIO Expenditures: the expenditure total currently stored on the FOLIO budget.',
    'FOLIO Encumbered: the encumbrance total currently stored on the FOLIO budget.',
    'FOLIO Available: the operational available balance stored on the FOLIO budget.',
];
foreach ($helpDefinitions as $definition) {
    assertRevisionContains($definition, $sql, "Missing help definition: {$definition}");
}

fwrite(STDOUT, "BudgetYearFundReportFiscalYearOptionsMigration test passed\n");
