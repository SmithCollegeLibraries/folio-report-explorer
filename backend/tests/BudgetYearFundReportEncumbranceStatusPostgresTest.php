<?php

function budgetEncumbrancePostgresFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function budgetEncumbrancePostgresAssert(bool $condition, string $message): void
{
    if (!$condition) {
        budgetEncumbrancePostgresFail($message);
    }
}

function budgetEncumbrancePostgresSkip(string $message): void
{
    fwrite(STDOUT, "SKIP: not run here; {$message}\n");
    exit(0);
}

function budgetEncumbrancePostgresCte(string $migration, string $number): string
{
    if (preg_match('/(encumbrances\s+AS\s*\(.*?\n\s*\))\s*,\s*payments\s+AS/is', $migration, $matches) !== 1) {
        budgetEncumbrancePostgresFail("Could not extract the encumbrances CTE from migration {$number}.");
    }
    return str_replace("''", "'", $matches[1]);
}

function budgetEncumbrancePostgresRunCte(PDO $pdo, string $cte, bool $newColumns): array
{
    $cte = preg_replace('/FROM\s+finance\.transaction__t\s+t/i', 'FROM transaction_fixture t', $cte, 1);
    if (strpos($cte, 'FROM transaction_fixture t') === false) {
        budgetEncumbrancePostgresFail('The stored encumbrances CTE must read finance.transaction__t through alias t.');
    }
    $sql = <<<'SQL'
WITH fiscal_years(id) AS (VALUES (2026), (2027)),
transaction_fixture (
    from_fund_id, fiscal_year_id, transaction_type, encumbrance__status,
    encumbrance__initial_amount_encumbered, encumbrance__amount_expended,
    encumbrance__amount_awaiting_payment
) AS (
    VALUES
      ('fund-a', 2026, 'Encumbrance', 'Unreleased', 100.00::numeric, 10.00::numeric, 20.00::numeric),
      ('fund-a', 2026, 'Encumbrance', 'Active', 20.00::numeric, 4.00::numeric, 6.00::numeric),
      ('fund-a', 2026, 'Encumbrance', 'Released', 300.00::numeric, 0.00::numeric, 0.00::numeric),
      ('fund-a', 2026, 'Encumbrance', 'Pending', 999.00::numeric, 0.00::numeric, 0.00::numeric),
      ('fund-b', 2026, 'Encumbrance', 'Released', NULL::numeric, NULL::numeric, NULL::numeric),
      ('fund-a', 2027, 'Encumbrance', 'Released', 700.00::numeric, 0.00::numeric, 0.00::numeric),
      ('fund-b', 2027, 'Encumbrance', 'Unreleased', 500.00::numeric, 0.00::numeric, 0.00::numeric),
      ('fund-c', 2026, 'Encumbrance', 'Pending', 400.00::numeric, 0.00::numeric, 0.00::numeric),
      ('fund-d', 2026, 'Encumbrance', 'Unreleased', NULL::numeric, NULL::numeric, NULL::numeric),
      ('fund-a', 2026, 'Allocation', 'Released', 800.00::numeric, 0.00::numeric, 0.00::numeric)
),
%s
SELECT fund_id::text, fiscal_year_id::text, current_encumbrance::text
SQL;
    if ($newColumns) {
        $sql .= ', unreleased_count::text, released_encumbrances::text, released_count::text';
    }
    $sql .= "\nFROM encumbrances ORDER BY fund_id, fiscal_year_id";
    $sql = sprintf($sql, $cte);
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        budgetEncumbrancePostgresFail('Extracted encumbrances CTE failed against synthetic PostgreSQL fixtures: ' . $exception->getMessage());
    }
    return [];
}

// Dedicated disposable PostgreSQL only. This test never reads the live LDP.
$dsn = getenv('BUDGET_REPORT_TEST_PG_DSN') ?: '';
if ($dsn === '') {
    budgetEncumbrancePostgresSkip('a dedicated BUDGET_REPORT_TEST_PG_DSN is not configured.');
}

$migration062Path = __DIR__ . '/../../mysql/migrations/062_budget_year_fund_report_encumbrance_status.sql';
$migration037Path = __DIR__ . '/../../mysql/migrations/037_budget_year_fund_report_payment_distributions.sql';
if (!file_exists($migration062Path)) {
    budgetEncumbrancePostgresFail('Migration 062 is missing.');
}
if (!extension_loaded('pdo_pgsql')) {
    budgetEncumbrancePostgresSkip('PDO PostgreSQL is unavailable.');
}

try {
    $pdo = new PDO($dsn, getenv('BUDGET_REPORT_TEST_PG_USER') ?: '', getenv('BUDGET_REPORT_TEST_PG_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
} catch (Throwable $exception) {
    budgetEncumbrancePostgresSkip('the dedicated PostgreSQL test database is unavailable.');
}

$newMigration = (string)file_get_contents($migration062Path);
$oldMigration = (string)file_get_contents($migration037Path);
$newCte = budgetEncumbrancePostgresCte($newMigration, '062');
$oldCte = budgetEncumbrancePostgresCte($oldMigration, '037');
$newRows = budgetEncumbrancePostgresRunCte($pdo, $newCte, true);
$oldRows = budgetEncumbrancePostgresRunCte($pdo, $oldCte, false);

$newByKey = [];
foreach ($newRows as $row) {
    $key = $row['fund_id'] . ':' . $row['fiscal_year_id'];
    $newByKey[$key] = $row;
}
$oldByKey = [];
foreach ($oldRows as $row) {
    $key = $row['fund_id'] . ':' . $row['fiscal_year_id'];
    $oldByKey[$key] = $row;
}

$expected = [
    'fund-a:2026' => ['current_encumbrance' => '80', 'unreleased_count' => '1', 'released_encumbrances' => '300.00', 'released_count' => '1'],
    'fund-b:2026' => ['current_encumbrance' => '0', 'unreleased_count' => '0', 'released_encumbrances' => '0.00', 'released_count' => '1'],
    'fund-a:2027' => ['current_encumbrance' => '0', 'unreleased_count' => '0', 'released_encumbrances' => '700.00', 'released_count' => '1'],
    'fund-b:2027' => ['current_encumbrance' => '500', 'unreleased_count' => '1', 'released_encumbrances' => '0.00', 'released_count' => '0'],
    'fund-d:2026' => ['current_encumbrance' => '0', 'unreleased_count' => '1', 'released_encumbrances' => '0.00', 'released_count' => '0'],
];
foreach ($expected as $key => $values) {
    budgetEncumbrancePostgresAssert(isset($newByKey[$key]), "Expected grouped encumbrance row {$key}.");
    $row = $newByKey[$key] ?? [];
    budgetEncumbrancePostgresAssert(abs((float)($row['current_encumbrance'] ?? 0) - (float)$values['current_encumbrance']) < 0.00001, "Wrong current encumbrance for {$key}.");
    budgetEncumbrancePostgresAssert((string)($row['unreleased_count'] ?? '') === $values['unreleased_count'], "Wrong Unreleased Count for {$key}; null amounts must still count.");
    budgetEncumbrancePostgresAssert(number_format((float)($row['released_encumbrances'] ?? 0), 2, '.', '') === $values['released_encumbrances'], "Wrong Released Encumbrances for {$key}.");
    budgetEncumbrancePostgresAssert((string)($row['released_count'] ?? '') === $values['released_count'], "Wrong Released Count for {$key}; null amounts must still count.");
    $oldCurrent = (string)($oldByKey[$key]['current_encumbrance'] ?? '0');
    budgetEncumbrancePostgresAssert((float)$oldCurrent === (float)$values['current_encumbrance'], "The 11-column existing output Calculated Current Encumbrances must stay unchanged for {$key}.");
}
budgetEncumbrancePostgresAssert(!isset($newByKey['fund-c:2026']), 'Pending-only transactions must not create an encumbrance aggregate row.');

$zeroRows = $pdo->query(
    "WITH budgets_fixture(fund_id, fiscal_year_id) AS (VALUES ('fund-c', 2026)),\n"
        . "encumbrances(fund_id, fiscal_year_id, unreleased_count, released_encumbrances, released_count) AS (VALUES ('fund-b', 2026, 1, 1.00, 1))\n"
        . "SELECT COALESCE(e.released_encumbrances, 0)::numeric(12,2)::text AS released_amount,\n"
        . "       COALESCE(e.unreleased_count, 0)::text AS unreleased_count,\n"
        . "       COALESCE(e.released_count, 0)::text AS released_count\n"
        . "FROM budgets_fixture b LEFT JOIN encumbrances e ON e.fund_id = b.fund_id AND e.fiscal_year_id = b.fiscal_year_id"
)->fetch(PDO::FETCH_ASSOC);
budgetEncumbrancePostgresAssert($zeroRows === ['released_amount' => '0.00', 'unreleased_count' => '0', 'released_count' => '0'], 'Funds with no encumbrances must show 0.00 and zero counts.');

fwrite(STDOUT, "PASS: Budget Year Fund Report encumbrance PostgreSQL synthetic fixtures\n");
