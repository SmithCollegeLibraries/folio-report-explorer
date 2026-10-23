<?php
require_once __DIR__ . '/support/AcquisitionsMissingUnitReportFixture.php';

function acquisitionsMissingUnitSqlFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function acquisitionsMissingUnitSqlSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        acquisitionsMissingUnitSqlFail($message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true));
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: pdo_sqlite is unavailable\n");
    exit(0);
}

$seed = acquisitionsMissingUnitReportSeed();
$sql = str_replace('BTRIM(', 'TRIM(', $seed['sql_template']);
$sql = str_replace("STRING_AGG(fund_codes.fund_code, '; ' ORDER BY fund_codes.fund_code)", "GROUP_CONCAT(fund_codes.fund_code, '; ')", $sql);
$sql = str_replace("po.po_number ~ '^[0-9]+$'", "po.po_number REGEXP '^[0-9]+$'", $sql);
$sql = str_replace('report.created_date DESC NULLS LAST', '(report.created_date IS NULL), report.created_date DESC', $sql);

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$regexp = function ($pattern, $value): int {
    return preg_match('/' . str_replace('/', '\\/', (string) $pattern) . '/', (string) $value) === 1 ? 1 : 0;
};
if (method_exists($pdo, 'createFunction')) {
    $pdo->createFunction('regexp', $regexp, 2);
} else {
    @$pdo->sqliteCreateFunction('regexp', $regexp, 2);
}
$pdo->exec("ATTACH DATABASE ':memory:' AS orders");
$pdo->exec("ATTACH DATABASE ':memory:' AS organizations");
$pdo->exec('CREATE TABLE orders.purchase_order__t (id TEXT PRIMARY KEY, po_number TEXT, vendor TEXT, workflow_status TEXT, order_type TEXT, date_ordered TEXT, metadata__created_date TEXT)');
$pdo->exec('CREATE TABLE orders.purchase_order__t__acq_unit_ids (id TEXT, acq_unit_ids TEXT)');
$pdo->exec('CREATE TABLE orders.po_line__t (id TEXT, purchase_order_id TEXT)');
$pdo->exec('CREATE TABLE orders.po_line__t__fund_distribution (id TEXT, fund_distribution__code TEXT)');
$pdo->exec('CREATE TABLE organizations.organizations__t (id TEXT PRIMARY KEY, name TEXT, code TEXT)');

$vendors = $pdo->prepare('INSERT INTO organizations.organizations__t VALUES (?, ?, ?)');
foreach ([
    ['vendor-none', 'Ordinary Vendor', 'NO-1'],
    ['vendor-smith', 'Smith Coded Vendor', 'sc-vendor'],
    ['vendor-fund', 'Fund Coded Vendor', 'XY-1'],
    ['vendor-other', 'Other Vendor', 'XY-2'],
] as $vendor) {
    $vendors->execute($vendor);
}

$orders = [
    ['11111111-1111-4111-8111-111111111111', 'SC100', 'vendor-none', 'Closed', 'One-Time', '2025-01-10', '2025-01-11'],
    ['22222222-2222-4222-8222-222222222222', 'sc200', 'vendor-none', 'Open', 'One-Time', '2025-03-10', '2025-03-12'],
    ['33333333-3333-4333-8333-333333333333', '12345', 'vendor-smith', 'Pending', 'One-Time', '2025-04-10', '2025-04-12'],
    ['44444444-4444-4444-8444-444444444444', '67890', 'vendor-fund', 'Approved', 'Ongoing', '2025-02-10', '2025-02-12'],
    ['55555555-5555-4555-8555-555555555555', '54321', 'vendor-other', 'Open', 'One-Time', '2025-05-10', '2025-05-12'],
    ['66666666-6666-4666-8666-666666666666', '99999', null, 'Closed', 'One-Time', '2025-01-09', '2025-01-10'],
    ['77777777-7777-4777-8777-777777777777', 'AC777', 'vendor-none', 'Open', 'One-Time', '2025-05-01', '2025-05-02'],
    ['88888888-8888-4888-8888-888888888888', 'UM888', 'vendor-none', 'Open', 'One-Time', '2025-05-01', '2025-05-02'],
    ['99999999-9999-4999-8999-999999999999', 'MH999', 'vendor-none', 'Open', 'One-Time', '2025-05-01', '2025-05-02'],
    ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'SC300', 'vendor-none', 'Open', 'One-Time', '2025-05-01', '2025-05-02'],
    ['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '123A', 'vendor-none', 'Open', 'One-Time', '2025-05-01', '2025-05-02'],
];
$insertOrder = $pdo->prepare('INSERT INTO orders.purchase_order__t VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ($orders as $order) {
    $insertOrder->execute($order);
}

$unit = $pdo->prepare('INSERT INTO orders.purchase_order__t__acq_unit_ids VALUES (?, ?)');
$unit->execute(['22222222-2222-4222-8222-222222222222', null]);
$unit->execute(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'smith-unit-id']);

$lines = $pdo->prepare('INSERT INTO orders.po_line__t VALUES (?, ?)');
foreach ([
    ['line-fund-one', '44444444-4444-4444-8444-444444444444'],
    ['line-fund-two', '44444444-4444-4444-8444-444444444444'],
    ['line-other', '55555555-5555-4555-8555-555555555555'],
] as $line) {
    $lines->execute($line);
}

$funds = $pdo->prepare('INSERT INTO orders.po_line__t__fund_distribution VALUES (?, ?)');
foreach ([
    ['line-fund-one', 'SCBPA'],
    ['line-fund-one', 'ACBP'],
    ['line-fund-two', 'SCBPA'],
    ['line-other', 'ACBIS'],
] as $fund) {
    $funds->execute($fund);
}

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$columns = ['PO Number', 'Number Pattern', 'Likely Smith', 'Workflow Status', 'Order Type', 'Vendor Name', 'Vendor Code', 'Date Ordered', 'Created Date', 'Number of PO Lines', 'Fund Codes', 'PO UUID'];
acquisitionsMissingUnitSqlSame($columns, array_keys($rows[0]), 'Report columns must follow the ticket order and append the export UUID.');

$byNumber = [];
foreach ($rows as $row) {
    $byNumber[$row['PO Number']] = $row;
}
foreach (['SC100', 'sc200', '12345', '67890', '54321', '99999'] as $expectedNumber) {
    if (!isset($byNumber[$expectedNumber])) {
        acquisitionsMissingUnitSqlFail("Missing expected order {$expectedNumber}.");
    }
}
foreach (['AC777', 'UM888', 'MH999', 'SC300', '123A'] as $excludedNumber) {
    if (isset($byNumber[$excludedNumber])) {
        acquisitionsMissingUnitSqlFail("Unexpectedly included order {$excludedNumber}.");
    }
}

acquisitionsMissingUnitSqlSame('SC prefix', $byNumber['SC100']['Number Pattern'], 'Uppercase SC prefix should be classified.');
acquisitionsMissingUnitSqlSame('SC prefix', $byNumber['sc200']['Number Pattern'], 'Lowercase sc prefix should be classified case-insensitively.');
acquisitionsMissingUnitSqlSame('Numbers only', $byNumber['12345']['Number Pattern'], 'Numeric order numbers should be classified.');
acquisitionsMissingUnitSqlSame('', $byNumber['SC100']['Likely Smith'], 'The order-number pattern alone must not imply likely Smith.');
acquisitionsMissingUnitSqlSame('Yes', $byNumber['12345']['Likely Smith'], 'An SC-prefixed vendor code should imply likely Smith.');
acquisitionsMissingUnitSqlSame('Yes', $byNumber['67890']['Likely Smith'], 'An SC-prefixed fund code should imply likely Smith.');
acquisitionsMissingUnitSqlSame('22222222-2222-4222-8222-222222222222', $byNumber['sc200']['PO UUID'], 'A null-unit bridge row should not exclude an order.');
acquisitionsMissingUnitSqlSame(2, (int) $byNumber['67890']['Number of PO Lines'], 'Count distinct purchase-order lines across multiple fund distributions.');
$fundCodes = explode('; ', (string) $byNumber['67890']['Fund Codes']);
sort($fundCodes, SORT_STRING);
acquisitionsMissingUnitSqlSame(['ACBP', 'SCBPA'], $fundCodes, 'Fund codes should be distinct for the order.');

$orderedNumbers = array_column($rows, 'PO Number');
acquisitionsMissingUnitSqlSame(['12345', '67890', '54321', 'sc200', 'SC100', '99999'], $orderedNumbers, 'Order likely-Smith records first, then newest created date.');

echo "PASS: Missing-acquisitions-unit SQLite SQL semantics tests\n";
