<?php

$migrationPath = getenv('BUDGET_REPORT_MIGRATION_037')
    ?: __DIR__ . '/../../mysql/migrations/037_budget_year_fund_report_payment_distributions.sql';

function failPostgresPaymentDistributionTest(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function skipPostgresPaymentDistributionTest(string $message): void
{
    fwrite(STDOUT, "SKIP: {$message}\n");
    exit(0);
}

if (!file_exists($migrationPath)) {
    failPostgresPaymentDistributionTest('Migration 037 is missing.');
}

$migration = (string)file_get_contents($migrationPath);
$matched = preg_match(
    "/SUM\\((CASE LOWER\\(COALESCE\\(fd\\.fund_distributions__distribution_type, ''percentage''\\)\\).*?END)\\) AS payment/s",
    $migration,
    $matches
);
if ($matched !== 1) {
    failPostgresPaymentDistributionTest('Could not extract the stored payment CASE expression from migration 037.');
}

// Migration SQL stores the PostgreSQL query inside a MySQL single-quoted
// literal, so doubled single quotes must be unescaped before execution.
$caseExpression = str_replace("''", "'", $matches[1]);

if (!extension_loaded('pdo_pgsql')) {
    skipPostgresPaymentDistributionTest('PDO PostgreSQL driver is unavailable.');
}

$appRoot = getenv('FOLIO_APP_ROOT') ?: dirname(__DIR__);
$settingsServicePath = $appRoot . '/services/SettingsService.php';
if (file_exists($settingsServicePath)) {
    require_once $settingsServicePath;
}

$settingsClass = '\\app\\services\\SettingsService';
$useSettings = class_exists($settingsClass) && getenv('BUDGET_REPORT_PG_USE_ENV') !== '1';
$host = $useSettings
    ? $settingsClass::get('pg_host', 'FOLIO_PG_HOST', '')
    : (getenv('FOLIO_PG_HOST') ?: '');
$port = $useSettings
    ? $settingsClass::get('pg_port', 'FOLIO_PG_PORT', '5432')
    : (getenv('FOLIO_PG_PORT') ?: '5432');
$database = $useSettings
    ? $settingsClass::get('pg_db', 'FOLIO_PG_DB', '')
    : (getenv('FOLIO_PG_DB') ?: '');
$username = $useSettings
    ? $settingsClass::get('pg_user', 'FOLIO_PG_USER', '')
    : (getenv('FOLIO_PG_USER') ?: '');
$password = $useSettings
    ? $settingsClass::get('pg_pass', 'FOLIO_PG_PASS', '')
    : (getenv('FOLIO_PG_PASS') ?: '');
$sslMode = $useSettings
    ? $settingsClass::get('pg_sslmode', 'FOLIO_PG_SSLMODE', 'require')
    : (getenv('FOLIO_PG_SSLMODE') ?: 'require');
$connectTimeout = max(1, (int)(getenv('BUDGET_REPORT_PG_TIMEOUT') ?: 10));
if ($host === '' || $database === '' || $username === '') {
    skipPostgresPaymentDistributionTest('FOLIO PostgreSQL connection environment is unavailable.');
}

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslMode};connect_timeout={$connectTimeout}",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => $connectTimeout]
    );
} catch (Throwable $exception) {
    skipPostgresPaymentDistributionTest('FOLIO PostgreSQL connection is unavailable: ' . $exception->getMessage());
}

$sql = <<<'SQL'
WITH fixture (
    scenario,
    total,
    fund_distributions__value,
    fund_distributions__distribution_type
) AS (
    VALUES
        ('percentage', 200.00::numeric, 25.00::numeric, 'percentage'::text),
        ('amount', 200.00::numeric, 25.00::numeric, 'amount'::text),
        ('mixed', 200.00::numeric, 25.00::numeric, 'PERCENTAGE'::text),
        ('mixed', 999.00::numeric, 12.50::numeric, 'Amount'::text),
        ('null-type', 80.00::numeric, 12.50::numeric, NULL::text)
)
SELECT scenario,
       ROUND(SUM(%s), 2)::text AS payment
FROM fixture fd
GROUP BY scenario
ORDER BY scenario
SQL;

try {
    $rows = $pdo->query(sprintf($sql, $caseExpression))->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $exception) {
    failPostgresPaymentDistributionTest(
        "Stored payment CASE expression failed against PostgreSQL fixtures: {$exception->getMessage()}\n"
        . $caseExpression
    );
}

$expected = [
    'amount' => '25.00',
    'mixed' => '62.50',
    'null-type' => '10.00',
    'percentage' => '50.00',
];
if ($rows !== $expected) {
    failPostgresPaymentDistributionTest(
        "Stored payment CASE expression produced incorrect totals.\n"
        . 'Expected: ' . json_encode($expected) . "\n"
        . 'Actual: ' . json_encode($rows)
    );
}

fwrite(STDOUT, "BudgetYearFundReportPaymentDistributionsPostgres test passed\n");
