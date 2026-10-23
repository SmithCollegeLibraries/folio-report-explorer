<?php

require_once __DIR__ . '/support/BudgetYearExpenseClassReportFixture.php';
require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';
require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';

function budgetYearExpenseSqlAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class BudgetYearExpenseSqlCommand
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
        if (strpos($this->sql, 'to_regclass(') !== false) {
            $row = [];
            foreach ($this->params as $index => $table) {
                preg_match('/requiredTable(\d+)/', $index, $matches);
                $row['required_table_' . ($matches[1] ?? '')] = $table;
            }
            return $row;
        }
        if (strpos($this->sql, 'FROM finance.fiscal_year__t') !== false) {
            return ['id' => 'fy-sc-2026', 'period_start' => '2025-07-02', 'period_end' => '2026-06-30'];
        }
        throw new RuntimeException('Unexpected report fixture lookup.');
    }

    public function queryAll()
    {
        $result = [];
        foreach ($this->params as $key => $value) {
            if (strpos($key, ':fund_') === 0) {
                $result[] = ['fund_id' => $value];
            }
        }
        return $result;
    }
}

final class BudgetYearExpenseSqlDb
{
    public function createCommand($sql, array $params = [])
    {
        return new BudgetYearExpenseSqlCommand($this, $sql, $params);
    }
}

$report = budgetYearExpenseClassReportFixture();
$fundId = '11111111-1111-4111-8111-111111111111';
$compiled = \app\services\CatalogingReportCompilerService::build(
    $report,
    [
        'acqUnit' => 'SC',
        'fiscalYear' => '2026',
        'startDate' => '',
        'endDate' => '',
        'funds' => $fundId,
    ],
    new BudgetYearExpenseSqlDb()
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
@$pdo->sqliteCreateFunction('BTRIM', 'trim', 1);
foreach (['finance', 'invoice', 'orders', 'inventory'] as $schema) {
    $pdo->exec("ATTACH DATABASE ':memory:' AS {$schema}");
}
$pdo->exec('CREATE TABLE finance.expense_class__t (id TEXT PRIMARY KEY, name TEXT, code TEXT)');
$pdo->exec('CREATE TABLE finance.transaction__t (id TEXT, transaction_type TEXT, encumbrance__status TEXT, fiscal_year_id TEXT, from_fund_id TEXT, expense_class_id TEXT, encumbrance__source_po_line_id TEXT, encumbrance__initial_amount_encumbered NUMERIC, encumbrance__amount_expended NUMERIC, encumbrance__amount_awaiting_payment NUMERIC)');
$pdo->exec('CREATE TABLE invoice.invoices__t (id TEXT PRIMARY KEY, fiscal_year_id TEXT, payment_date TEXT)');
$pdo->exec('CREATE TABLE invoice.invoice_lines__t__fund_distributions (invoice_id TEXT, invoice_line_status TEXT, total NUMERIC, fund_distributions__value NUMERIC, fund_distributions__fund_id TEXT, fund_distributions__expense_class_id TEXT, po_line_id TEXT)');
$pdo->exec('CREATE TABLE orders.po_line__t (id TEXT PRIMARY KEY, eresource__material_type TEXT, physical__material_type TEXT)');
$pdo->exec('CREATE TABLE inventory.material_type__t (id TEXT PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO finance.expense_class__t VALUES
    ('sc-book', 'SC Books', ' SCAP '),
    ('sc-serial', 'SC Serials', 'scserial'),
    ('sc-empty', 'SC Other', 'SCOTH'),
    ('mh-book', 'MH Books', 'MHBOOK')");
$pdo->exec("INSERT INTO inventory.material_type__t VALUES ('book', 'Book'), ('ebook', 'E-Book'), ('video', 'Video')");
$pdo->exec("INSERT INTO invoice.invoices__t VALUES
    ('invoice-book', 'fy-sc-2026', '2025-07-02'),
    ('invoice-ebook', 'fy-sc-2026', '2026-06-30'),
    ('invoice-unitless', 'fy-sc-2026', '2025-07-03'),
    ('invoice-other', 'fy-sc-2026', '2025-07-04'),
    ('invoice-boundary', 'fy-sc-2025', '2025-07-01'),
    ('invoice-after-period', 'fy-sc-2026', '2026-07-01')");
$pdo->exec("INSERT INTO orders.po_line__t VALUES
    ('po-book', NULL, 'book'),
    ('po-ebook', 'ebook', NULL),
    ('po-unitless', NULL, 'book'),
    ('po-other', NULL, 'video'),
    ('po-boundary', NULL, 'book'),
    ('po-after-period', NULL, 'book')");
$statement = $pdo->prepare('INSERT INTO invoice.invoice_lines__t__fund_distributions VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ([
    ['invoice-book', 'Paid', 100, 100, $fundId, 'sc-book', 'po-book'],
    ['invoice-ebook', 'Paid', 50, 100, $fundId, 'sc-book', 'po-ebook'],
    ['invoice-unitless', 'Paid', 25, 100, $fundId, 'sc-serial', 'po-unitless'],
    ['invoice-other', 'Paid', 5, 100, $fundId, 'sc-serial', 'po-other'],
    ['invoice-boundary', 'Paid', 90, 100, $fundId, 'sc-serial', 'po-boundary'],
    ['invoice-after-period', 'Paid', 20, 100, $fundId, 'sc-book', 'po-after-period'],
] as $row) {
    $statement->execute($row);
}
$pdo->exec("INSERT INTO finance.transaction__t VALUES
    ('current-book', 'Encumbrance', 'Active', 'fy-sc-2026', '$fundId', 'sc-book', 'po-book', 10, 2, 1),
    ('previous-year-book', 'Encumbrance', 'Active', 'fy-sc-2025', '$fundId', 'sc-book', 'po-book', 551.64, 0, 0),
    ('current-ebook', 'Encumbrance', 'Unreleased', 'fy-sc-2026', '$fundId', 'sc-book', 'po-ebook', 5, 1, 1)");

$sql = str_replace(
    ['CAST(inv.payment_date AS date)', 'CAST(:startDate AS date)', 'CAST(:endDate AS date)'],
    ['date(inv.payment_date)', 'date(:startDate)', 'date(:endDate)'],
    $compiled['sql']
);
$query = $pdo->prepare($sql);
$query->execute($compiled['params']);
$rows = $query->fetchAll(PDO::FETCH_ASSOC);
$byCode = array_column($rows, null, 'Expense Class Code');

budgetYearExpenseSqlAssert(count($rows) === 3, 'Return every SC expense class, including classes without activity, and exclude MH.');
budgetYearExpenseSqlAssert(isset($byCode['SCAP'], $byCode['SCSERIAL'], $byCode['SCOTH']), 'Trim and uppercase the expense-class key.');
budgetYearExpenseSqlAssert(!isset($byCode['MHBOOK']), 'Scope expense classes by the selected college prefix.');
budgetYearExpenseSqlAssert((float) $byCode['SCAP']['Book Payments'] === 120.0, 'Include selected-year invoices paid after the FOLIO fiscal period and classify Book payments by material type.');
budgetYearExpenseSqlAssert((float) $byCode['SCAP']['E-Book Payments'] === 50.0, 'Classify E-Book payments by material type, independent of fund.');
budgetYearExpenseSqlAssert((float) $byCode['SCAP']['Total Payments'] === 170.0, 'Total payments include the Book and E-Book amounts once.');
budgetYearExpenseSqlAssert((float) $byCode['SCAP']['Book Encumbrances'] === 7.0, 'Use the exact fiscal-year encumbrance and existing arithmetic.');
budgetYearExpenseSqlAssert((float) $byCode['SCAP']['E-Book Encumbrances'] === 3.0, 'Classify E-Book encumbrances by material type.');
budgetYearExpenseSqlAssert((float) $byCode['SCAP']['Total Encumbrances'] === 10.0, 'Exclude the prior-year boundary encumbrance.');
budgetYearExpenseSqlAssert((float) $byCode['SCAP']['Total Spent'] === 180.0, 'Total spent adds all payments and exact-year encumbrances.');
budgetYearExpenseSqlAssert((float) $byCode['SCSERIAL']['Book Payments'] === 25.0, 'Count the paid line even though its purchase order has no unit association.');
budgetYearExpenseSqlAssert((float) $byCode['SCSERIAL']['Total Payments'] === 30.0, 'Keep other material types in Total Payments.');
budgetYearExpenseSqlAssert((float) $byCode['SCOTH']['Total Spent'] === 0.0, 'Return zero totals for an in-scope class without activity.');

$narrowedParams = $compiled['params'];
$narrowedParams[':startDateEnabled'] = 1;
$narrowedParams[':startDate'] = '2025-07-03';
$query->execute($narrowedParams);
$narrowedRows = $query->fetchAll(PDO::FETCH_ASSOC);
$narrowedByCode = array_column($narrowedRows, null, 'Expense Class Code');
budgetYearExpenseSqlAssert(
    (float) $narrowedByCode['SCAP']['Total Payments'] === 70.0,
    'Apply an inclusive start-date filter while leaving the end date unbounded.'
);
budgetYearExpenseSqlAssert(
    (float) $narrowedByCode['SCSERIAL']['Total Payments'] === 30.0,
    'Keep all selected-year payments on or after the inclusive start date.'
);

fwrite(STDOUT, "Budget-year expense class SQLite SQL semantics test passed\n");
