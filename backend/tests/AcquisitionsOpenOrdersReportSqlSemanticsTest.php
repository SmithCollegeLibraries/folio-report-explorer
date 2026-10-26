<?php
require_once __DIR__ . '/support/AcquisitionsOpenOrdersReportFixture.php';
require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';

use app\services\CatalogingReportCompilerService;

function acquisitionsSqlAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acquisitionsSqlRun(\PDO $pdo, string $sql, array $params): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
}

$report = acquisitionsOpenOrdersReportFixture();
$db = new AcquisitionsOpenOrdersLookupDb();
$compile = static function (array $overrides = []) use ($report, $db): array {
    return CatalogingReportCompilerService::build($report, $overrides, $db);
};

$pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
foreach (['orders', 'finance', 'organizations', 'inventory', 'invoice'] as $schema) {
    $pdo->exec("ATTACH DATABASE ':memory:' AS {$schema}");
}
@$pdo->sqliteCreateFunction('BTRIM', static function ($value) { return trim((string) $value); }, 1);
@$pdo->sqliteCreateFunction('SMITH_FY', static function ($date) {
    return (int) date('Y', strtotime((string) $date . ' -6 months'));
}, 1);
@$pdo->sqliteCreateFunction('SMITH_FQ', static function ($date) {
    $shifted = strtotime((string) $date . ' -6 months');
    return (int) ceil((int) date('n', $shifted) / 3);
}, 1);

$pdo->exec('CREATE TABLE orders.acquisitions_unit__t (id TEXT PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE orders.purchase_order__t (id TEXT PRIMARY KEY, po_number TEXT, workflow_status TEXT, order_type TEXT, re_encumber BOOLEAN, date_ordered TEXT, metadata__updated_date TEXT, vendor TEXT)');
$pdo->exec('CREATE TABLE orders.purchase_order__t__acq_unit_ids (id TEXT, acq_unit_ids TEXT)');
$pdo->exec('CREATE TABLE orders.po_line__t (id TEXT PRIMARY KEY, purchase_order_id TEXT, po_line_number TEXT, title_or_package TEXT, receipt_status TEXT, payment_status TEXT, order_format TEXT, physical__material_type TEXT, eresource__material_type TEXT, vendor_detail__vendor_account TEXT, metadata__updated_date TEXT)');
$pdo->exec('CREATE TABLE orders.po_line__t__fund_distribution (id TEXT, fund_distribution__fund_id TEXT, fund_distribution__expense_class_id TEXT)');
$pdo->exec('CREATE TABLE orders.po_line__t__vendor_detail__reference_numbers (id TEXT, vendor_detail__reference_numbers__ref_number TEXT, vendor_detail__reference_numbers__ref_number_type TEXT)');
$pdo->exec('CREATE TABLE finance.fund__t (id TEXT, code TEXT)');
$pdo->exec('CREATE TABLE finance.expense_class__t (id TEXT, code TEXT)');
$pdo->exec('CREATE TABLE organizations.organizations__t (id TEXT, name TEXT, code TEXT)');
$pdo->exec('CREATE TABLE organizations.organizations__t__contacts (id TEXT, contacts TEXT)');
$pdo->exec('CREATE TABLE organizations.contacts__t__emails (id TEXT, emails__value TEXT)');
$pdo->exec('CREATE TABLE inventory.material_type__t (id TEXT, name TEXT)');

$units = ['SC' => 'unit-sc', 'UM' => 'unit-um'];
$insertUnit = $pdo->prepare('INSERT INTO orders.acquisitions_unit__t VALUES (?, ?)');
foreach ($units as $name => $id) { $insertUnit->execute([$id, $name]); }
$pos = [
    ['11111111-1111-4111-8111-111111111111', 'SC100001', 'Open', 'One-Time', 1, '2025-07-01', '2026-01-01', 'vendor-1', 'SC'],
    ['22222222-2222-4222-8222-222222222222', 'SC100002', 'Pending', 'One-Time', 0, '2000-01-01', '2026-08-15', 'vendor-1', 'SC'],
    ['33333333-3333-4333-8333-333333333333', 'SC100003', 'Open', 'Ongoing', 1, '2025-08-01', '2026-01-01', 'vendor-1', 'SC'],
    ['44444444-4444-4444-8444-444444444444', 'SC100004', 'Closed', 'One-Time', 1, '2025-09-01', '2026-01-01', 'vendor-1', 'SC'],
    ['55555555-5555-4555-8555-555555555555', 'SC100005', 'Open', 'One-Time', null, '2025-10-10', '2026-01-01', 'vendor-1', 'SC'],
    ['66666666-6666-4666-8666-666666666666', 'SC100006', 'Open', 'One-Time', 0, '2026-06-30', '2026-07-01', 'vendor-1', 'SC'],
    ['77777777-7777-4777-8777-777777777777', 'UM100001', 'Open', 'One-Time', 1, '2025-11-01', '2026-01-01', 'vendor-1', 'UM'],
    ['88888888-8888-4888-8888-888888888888', 'SC100007', 'Pending', 'Ongoing', 1, '2000-01-01', '2026-08-20', 'vendor-1', 'SC'],
];
$insertPo = $pdo->prepare('INSERT INTO orders.purchase_order__t VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$insertPoUnit = $pdo->prepare('INSERT INTO orders.purchase_order__t__acq_unit_ids VALUES (?, ?)');
foreach ($pos as $po) {
    $insertPo->execute(array_slice($po, 0, 8));
    $insertPoUnit->execute([$po[0], $units[$po[8]]]);
}

$lines = [
    ['a1111111-1111-4111-8111-111111111111', $pos[0][0], '1', 'Awaiting_% title', 'Awaiting Receipt', 'Awaiting Payment', 'Physical Resource', 'mat-physical', null, 'ACCOUNT-1', '2026-01-02'],
    ['a2222222-2222-4222-8222-222222222222', $pos[0][0], '2', 'Received unpaid', 'Fully Received', 'Awaiting Payment', 'Physical Resource', 'mat-physical', null, 'ACCOUNT-1', '2026-01-03'],
    ['a3333333-3333-4333-8333-333333333333', $pos[0][0], '3', 'Partially paid', 'Fully Received', 'Partially Paid', 'Physical Resource', 'mat-physical', null, 'ACCOUNT-1', '2026-01-04'],
    ['a4444444-4444-4444-8444-444444444444', $pos[0][0], '4', 'Paid in full', 'Fully Received', 'Fully Paid', 'Physical Resource', 'mat-physical', null, 'ACCOUNT-1', '2026-01-05'],
    ['a5555555-5555-4555-8555-555555555555', $pos[0][0], '5', 'Cancelled line', 'Cancelled', 'Cancelled', 'Physical Resource', 'mat-physical', null, 'ACCOUNT-1', '2026-01-06'],
    ['a6666666-6666-4666-8666-666666666666', $pos[0][0], '6', 'No receipt required', 'Receipt Not Required', 'Awaiting Payment', 'Physical Resource', 'mat-physical', null, 'ACCOUNT-1', '2026-01-07'],
    ['a7777777-7777-4777-8777-777777777777', $pos[0][0], '7', 'Receipt ongoing', 'Ongoing', 'Ongoing', 'Physical Resource', 'mat-physical', null, 'ACCOUNT-1', '2026-01-08'],
    ['a8888888-8888-4888-8888-888888888888', $pos[1][0], '1', 'Pending fallback', 'Pending', 'Pending', 'Electronic Resource', null, 'mat-ebook', 'ACCOUNT-2', '2026-08-16'],
    ['a9999999-9999-4999-8999-999999999999', $pos[2][0], '1', 'Ongoing order', 'Pending', 'Pending', 'Electronic Resource', null, 'mat-ebook', 'ACCOUNT-3', '2026-01-09'],
    ['abbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $pos[3][0], '1', 'Closed order', 'Awaiting Receipt', 'Awaiting Payment', 'Physical Resource', 'mat-physical', null, 'ACCOUNT-4', '2026-01-10'],
    ['accccccc-cccc-4ccc-8ccc-cccccccccccc', $pos[4][0], '1', 'Unspecified re-encumber', 'Awaiting Receipt', 'Awaiting Payment', 'Other', null, null, 'ACCOUNT-5', '2026-01-11'],
    ['addddddd-dddd-4ddd-8ddd-dddddddddddd', $pos[5][0], '1', 'Partially received', 'Partially Received', 'Awaiting Payment', 'Electronic Resource', null, 'mat-ebook', 'ACCOUNT-6', '2026-07-02'],
    ['aeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', $pos[6][0], '1', 'Other unit', 'Awaiting Receipt', 'Awaiting Payment', 'Physical Resource', 'mat-physical', null, 'ACCOUNT-7', '2026-01-12'],
    ['afffffff-ffff-4fff-8fff-ffffffffffff', $pos[7][0], '1', 'Pending ongoing', 'Pending', 'Pending', 'Electronic Resource', null, 'mat-ebook', 'ACCOUNT-8', '2026-08-21'],
];
$insertLine = $pdo->prepare('INSERT INTO orders.po_line__t VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($lines as $line) { $insertLine->execute($line); }

$pdo->exec("INSERT INTO finance.fund__t VALUES ('fund-1', 'FUND-A'), ('fund-2', 'FUND-B')");
$pdo->exec("INSERT INTO finance.expense_class__t VALUES ('expense-1', 'CLASS-A'), ('expense-2', 'CLASS-B')");
$pdo->exec("INSERT INTO orders.po_line__t__fund_distribution VALUES ('{$lines[0][0]}', 'fund-1', 'expense-1'), ('{$lines[0][0]}', 'fund-2', 'expense-2')");
$pdo->exec("INSERT INTO orders.po_line__t__vendor_detail__reference_numbers VALUES ('{$lines[0][0]}', 'REF-1', 'Vendor order reference number')");
$pdo->exec("INSERT INTO organizations.organizations__t VALUES ('vendor-1', 'Example Vendor', 'EXV')");
$pdo->exec("INSERT INTO organizations.organizations__t__contacts VALUES ('vendor-1', 'contact-1')");
$pdo->exec("INSERT INTO organizations.contacts__t__emails VALUES ('contact-1', 'acquisitions@example.test')");
$pdo->exec("INSERT INTO inventory.material_type__t VALUES ('mat-physical', 'Book'), ('mat-ebook', 'E-Book')");
$pdo->exec('CREATE TABLE invoice.invoice_lines__t (id TEXT PRIMARY KEY, po_line_id TEXT, total REAL, invoice_id TEXT, invoice_line_status TEXT)');
$pdo->exec('CREATE TABLE invoice.invoices__t (id TEXT PRIMARY KEY, currency TEXT, invoice_date TEXT, payment_date TEXT)');
$pdo->exec("INSERT INTO invoice.invoices__t VALUES
    ('invoice-usd-1', 'USD', '2025-12-10', '2026-01-10'),
    ('invoice-usd-2', 'USD', '2026-02-03', '2026-03-01'),
    ('invoice-eur-1', 'EUR', '2026-04-05', '2026-04-10'),
    ('invoice-unpaid', 'USD', '2026-05-01', NULL)");
$pdo->exec("INSERT INTO invoice.invoice_lines__t VALUES
    ('paid-usd-1', '{$lines[0][0]}', 10, 'invoice-usd-1', 'Paid'),
    ('paid-usd-2', '{$lines[0][0]}', 20, 'invoice-usd-2', 'Paid'),
    ('paid-eur-1', '{$lines[0][0]}', 5, 'invoice-eur-1', 'Paid'),
    ('unpaid-line', '{$lines[0][0]}', 99, 'invoice-unpaid', 'Awaiting Payment')");

$compiled = $compile([
    'acqUnit' => 'SC', 'startDate' => '2025-07-01', 'endDate' => '2026-12-31',
    'orderFormat' => 'All', 'reEncumber' => 'Any',
]);
$sql = $compiled['sql'];
$adaptSql = static function (string $sql): string {
    $sql = str_replace('CAST(:startDate AS date)', ':startDate', $sql);
    $sql = str_replace('CAST(:endDate AS date)', ':endDate', $sql);
    $sql = str_replace('CAST(po.date_ordered AS date)', 'date(po.date_ordered)', $sql);
    $sql = str_replace('CAST(po.metadata__updated_date AS date)', 'date(po.metadata__updated_date)', $sql);
    $sql = str_replace('CAST(MAX(last_invoice_date) AS date)', 'date(MAX(last_invoice_date))', $sql);
    $sql = str_replace('CAST(MAX(last_payment_date) AS date)', 'date(MAX(last_payment_date))', $sql);
    $sql = str_replace('::text', '', $sql);
    $sql = str_replace(' ILIKE ', ' LIKE ', $sql);
    $sql = str_replace('CHR(92)', 'char(92)', $sql);
    $sql = str_replace('CURRENT_DATE - il.report_date', 'CAST(julianday(CURRENT_DATE) - julianday(il.report_date) AS INTEGER)', $sql);
    $sql = str_replace("EXTRACT(YEAR FROM (il.report_date - INTERVAL '6 months'))", 'SMITH_FY(il.report_date)', $sql);
    $sql = str_replace("EXTRACT(QUARTER FROM (il.report_date - INTERVAL '6 months'))", 'SMITH_FQ(il.report_date)', $sql);
    $sql = str_replace(
        "STRING_AGG(ROUND(total_paid, 2) || ' ' || COALESCE(currency, 'Unknown'), '; ' ORDER BY COALESCE(currency, 'Unknown'))",
        "GROUP_CONCAT(ROUND(total_paid, 2) || ' ' || COALESCE(currency, 'Unknown'), '; ')",
        $sql
    );
    return preg_replace('/STRING_AGG\(DISTINCT ([a-zA-Z_][a-zA-Z0-9_.]*), \'; \' ORDER BY [a-zA-Z_][a-zA-Z0-9_.]*\)/', "REPLACE(GROUP_CONCAT(DISTINCT $1), ',', '; ')", $sql);
};

$run = static function (array $overrides = []) use ($compile, $pdo, $adaptSql): array {
    $compiled = $compile(array_replace([
        'acqUnit' => 'SC', 'startDate' => '', 'endDate' => '',
        'orderFormat' => 'All', 'reEncumber' => 'Any',
    ], $overrides));
    $statement = $pdo->prepare($adaptSql($compiled['sql']));
    $statement->execute($compiled['params']);
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
};

$rows = $run();
$byLine = [];
foreach ($rows as $row) { $byLine[$row['PO Line UUID']] = $row; }
acquisitionsSqlAssert(count($rows) === 9, 'Default to every PO status and order type while keeping the prior eligible-line view.');
foreach ([$lines[0][0], $lines[1][0], $lines[2][0], $lines[7][0], $lines[8][0], $lines[9][0], $lines[10][0], $lines[11][0], $lines[13][0]] as $eligibleId) {
    acquisitionsSqlAssert(isset($byLine[$eligibleId]), "Include eligible PO line {$eligibleId}.");
}
foreach ([$lines[3][0], $lines[4][0], $lines[5][0], $lines[6][0], $lines[12][0]] as $excludedId) {
    acquisitionsSqlAssert(!isset($byLine[$excludedId]), "Exclude ineligible PO line {$excludedId}.");
}

$pending = $byLine[$lines[7][0]];
acquisitionsSqlAssert($pending['Report date'] === '2026-08-15' && $pending['Date used'] === 'Last updated (pending)', 'Pending POs use the PO last-updated date even when date_ordered is populated.');
acquisitionsSqlAssert($pending['Fiscal quarter'] === 'FY2027 Q1', 'Assign pending fallback dates to the Smith fiscal quarter.');
$closed = $byLine[$lines[9][0]];
acquisitionsSqlAssert($closed['PO status'] === 'Closed', 'Include closed POs when all PO statuses are selected.');
$awaiting = $byLine[$lines[0][0]];
acquisitionsSqlAssert($awaiting['Report date'] === '2025-07-01' && $awaiting['Date used'] === 'Opened', 'Opened POs use the inclusive order date.');
acquisitionsSqlAssert($awaiting['Fiscal quarter'] === 'FY2026 Q1', 'Assign July dates to Smith fiscal-year Q1.');
acquisitionsSqlAssert($awaiting['Fund codes'] === 'FUND-A; FUND-B' && $awaiting['Expense classes'] === 'CLASS-A; CLASS-B', 'Aggregate split fund and expense-class values without duplicating the line.');
acquisitionsSqlAssert($awaiting['Vendor name'] === 'Example Vendor' && $awaiting['Vendor code'] === 'EXV', 'Resolve vendor name and code.');
acquisitionsSqlAssert($awaiting['Vendor contact email(s)'] === 'acquisitions@example.test', 'Include vendor contact email when available.');
acquisitionsSqlAssert($awaiting['Vendor order reference number'] === 'REF-1', 'Include only the vendor order reference number.');
acquisitionsSqlAssert($awaiting['Material type'] === 'Book', 'Resolve physical material type.');
acquisitionsSqlAssert($awaiting['PO status'] === 'Open', 'Expose the parent purchase-order workflow status.');
acquisitionsSqlAssert(strpos($awaiting['Total paid'], '30') !== false && strpos($awaiting['Total paid'], 'USD') !== false
    && strpos($awaiting['Total paid'], '5') !== false && strpos($awaiting['Total paid'], 'EUR') !== false, 'Sum paid amounts separately by currency without duplicating the PO line.');
acquisitionsSqlAssert($awaiting['Last invoice date'] === '2026-04-05' && $awaiting['Last payment date'] === '2026-04-10', 'Report the latest invoice and payment dates from paid invoice lines.');
acquisitionsSqlAssert(array_slice(array_keys($awaiting), -2) === ['PO UUID', 'PO Line UUID'], 'Append both PO UUIDs to worklist CSV rows.');

$allLines = $run(['lineView' => 'All lines']);
acquisitionsSqlAssert(count($allLines) === 13, 'The all-lines view includes cancelled, fully paid, and receipt-not-required lines.');
acquisitionsSqlAssert(count(array_unique(array_column($allLines, 'PO Line UUID'))) === count($allLines), 'Keep a single output row per PO line after invoice aggregation.');
$pendingOngoing = $run(['workflowStatuses' => 'Pending', 'orderTypes' => 'Ongoing', 'lineView' => 'All lines']);
acquisitionsSqlAssert(count($pendingOngoing) === 1 && $pendingOngoing[0]['PO Line UUID'] === $lines[13][0], 'Narrow to Pending Ongoing orders.');
$allOngoing = $run(['orderTypes' => 'Ongoing', 'lineView' => 'All lines']);
acquisitionsSqlAssert(count($allOngoing) === 2, 'Narrow to Ongoing order types.');
$oldSelection = $run([
    'workflowStatuses' => 'Pending,Open',
    'orderTypes' => 'One-Time',
    'lineView' => 'Not yet received or not yet paid',
    'startDate' => '2025-07-01',
    'endDate' => '2026-12-31',
]);
acquisitionsSqlAssert(count($oldSelection) === 6, 'Reproduce the previous Open/Pending One-Time report selection.');
$startOnly = $run(['startDate' => '2026-08-15']);
acquisitionsSqlAssert(count($startOnly) === 2, 'Apply a start date without requiring an end date.');
$endOnly = $run(['endDate' => '2025-08-01']);
acquisitionsSqlAssert(count($endOnly) === 4, 'Apply an end date without requiring a start date.');
$cancelledAllLines = $run(['lineView' => 'All lines', 'receiptStatuses' => 'Cancelled']);
acquisitionsSqlAssert(count($cancelledAllLines) === 1 && $cancelledAllLines[0]['PO Line UUID'] === $lines[4][0], 'Receipt status selections narrow the all-lines view.');
$materialFiltered = $run(['lineView' => 'All lines', 'materialTypes' => 'E-Book']);
acquisitionsSqlAssert(count($materialFiltered) === 4, 'Filter by material type names used on the selected unit.');
$expenseFiltered = $run(['lineView' => 'All lines', 'expenseClasses' => 'expense-1']);
acquisitionsSqlAssert(count($expenseFiltered) === 1 && $expenseFiltered[0]['PO Line UUID'] === $lines[0][0], 'Match a line when any fund distribution has a selected expense class.');
$noExpenseClass = $run(['lineView' => 'All lines', 'expenseClasses' => '__none__']);
acquisitionsSqlAssert(count($noExpenseClass) === 12, 'The none expense-class option matches lines without a class on any fund distribution.');
$titleMatched = $run(['titleSearch' => 'awaiting_%']);
acquisitionsSqlAssert(count($titleMatched) === 1 && $titleMatched[0]['PO Line UUID'] === $lines[0][0], 'Treat percent and underscore in title searches as literal characters.');
$lineNumberMatched = $run(['poLineNumberSearch' => '2']);
acquisitionsSqlAssert(count($lineNumberMatched) === 1 && $lineNumberMatched[0]['PO Line UUID'] === $lines[1][0], 'Search PO line numbers by case-insensitive partial match.');
$paidOutsideReportDate = $run(['startDate' => '2025-07-01', 'endDate' => '2025-07-01']);
acquisitionsSqlAssert($paidOutsideReportDate[0]['Total paid'] === $awaiting['Total paid'], 'Keep total paid all-time when the report date is narrowed.');

$on = $run(['reEncumber' => 'On']);
$off = $run(['reEncumber' => 'Off']);
acquisitionsSqlAssert(count($on) === 6 && count($off) === 3, 'Apply re-encumber On and Off while Off includes null flags.');
$electronic = $run(['orderFormat' => 'Electronic Resource']);
acquisitionsSqlAssert(count($electronic) === 4 && isset(array_column($electronic, null, 'PO Line UUID')[$lines[7][0]]), 'Filter to the selected order format.');
$inclusive = $run(['startDate' => '2025-07-01', 'endDate' => '2025-07-01']);
acquisitionsSqlAssert(count($inclusive) === 3, 'Include both start and end dates on the range edges.');
$receiptFiltered = $run(['receiptStatuses' => 'Fully Received']);
acquisitionsSqlAssert(count($receiptFiltered) === 2, 'Filter eligible lines by receipt status.');
$paymentFiltered = $run(['paymentStatuses' => 'Partially Paid']);
acquisitionsSqlAssert(count($paymentFiltered) === 1 && $paymentFiltered[0]['PO Line UUID'] === $lines[2][0], 'Filter eligible lines by payment status.');
$combinedStatusFiltered = $run(['receiptStatuses' => 'Fully Received', 'paymentStatuses' => 'Awaiting Payment']);
acquisitionsSqlAssert(count($combinedStatusFiltered) === 1 && $combinedStatusFiltered[0]['PO Line UUID'] === $lines[1][0], 'Apply both status filters after the existing inclusion rule.');

echo "Acquisitions open-orders SQLite SQL-semantics checks passed\n";
