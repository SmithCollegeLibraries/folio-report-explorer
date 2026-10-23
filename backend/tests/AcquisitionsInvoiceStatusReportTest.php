<?php

namespace yii\db {
    if (!class_exists(ActiveRecord::class, false)) {
        class ActiveRecord
        {
            private $attributes = [];
            public function __get($name) { return $this->attributes[$name] ?? null; }
            public function __set($name, $value) { $this->attributes[$name] = $value; }
            public function hasAttribute($name) { return true; }
        }
    }
}

namespace {
    require_once __DIR__ . '/support/AcquisitionsInvoiceStatusReportFixture.php';
    require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';
    require_once __DIR__ . '/../services/ReportExecutionContractService.php';
    require_once __DIR__ . '/../services/SqlBuilderService.php';
    require_once __DIR__ . '/../services/FolioSchemaService.php';
    require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';

    function acqInvoiceCheck($condition, $message)
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function acqInvoiceExpectFieldError($compile, array $overrides, $field, $message)
    {
        try {
            $compile($overrides);
            acqInvoiceCheck(false, $message);
        } catch (\app\exceptions\ReportParameterValidationException $error) {
            acqInvoiceCheck(isset($error->getFieldErrors()[$field]), $message . ' (field error)');
        }
    }

    final class AcquisitionsInvoiceLookupDb
    {
        public $missingTable = null;
        public $probeCount = 0;

        public function createCommand($sql, array $params = [])
        {
            $this->probeCount++;
            return new class($this->missingTable) {
                private $missingTable;
                public function __construct($missingTable) { $this->missingTable = $missingTable; }
                public function queryOne()
                {
                    $tables = [
                        'invoices' => 'invoice.invoices__t',
                        'unit_links' => 'invoice.invoices__t__acq_unit_ids',
                        'acquisition_units' => 'orders.acquisitions_unit__t',
                        'vendors' => 'organizations.organizations__t',
                        'fiscal_years' => 'finance.fiscal_year__t',
                    ];
                    $row = [];
                    foreach ($tables as $key => $table) {
                        $row[$key] = $table === $this->missingTable ? null : $table;
                    }
                    return $row;
                }
            };
        }
    }

    $report = acquisitionsInvoiceStatusReportFixture();
    acqInvoiceCheck(
        \app\services\CatalogingReportCompilerService::supports($report),
        'The governed compiler must support the acquisitions invoice status report.'
    );
    $db = new AcquisitionsInvoiceLookupDb();
    $base = [
        'acqUnit' => 'SC',
        'startDate' => '2025-07-01',
        'endDate' => '2026-06-30',
        'statuses' => 'Open,Reviewed,Approved,Paid',
        'vendorIds' => '',
    ];
    $compile = function ($overrides = []) use ($report, $db, $base) {
        return \app\services\CatalogingReportCompilerService::build(
            $report,
            array_replace($base, $overrides),
            $db
        );
    };

    try {
        $compiled = $compile();
    } catch (\Throwable $error) {
        acqInvoiceCheck(false, 'The governed compiler must compile invoice report parameters before execution.');
    }
    acqInvoiceCheck($db->probeCount === 1, 'Probe all report tables in one read-only schema lookup.');
    acqInvoiceCheck(strpos($compiled['sql'], ':statuses') === false, 'Expand the status marker into bound placeholders.');
    acqInvoiceCheck(strpos($compiled['sql'], ':vendorFilter') === false, 'Expand the vendor marker without interpolating values.');
    acqInvoiceCheck(strpos($compiled['sql'], 'AND TRUE') !== false, 'An empty vendor filter must keep every vendor in scope.');
    acqInvoiceCheck($compiled['params'][':startDate'] === '2025-07-01', 'Bind the inclusive start date.');
    acqInvoiceCheck($compiled['params'][':endDate'] === '2026-06-30', 'Bind the inclusive end date.');

    $vendorOne = '00000000-0000-4000-8000-000000000001';
    $selected = $compile(['statuses' => 'Paid,Approved', 'vendorIds' => strtoupper($vendorOne)]);
    acqInvoiceCheck(
        $selected['params'][':status_0'] === 'Paid' && $selected['params'][':status_1'] === 'Approved',
        'Bind every selected status separately.'
    );
    acqInvoiceCheck($selected['params'][':vendor_0'] === $vendorOne, 'Normalize and bind every selected vendor UUID.');
    acqInvoiceCheck(strpos($selected['sql'], $vendorOne) === false, 'Vendor UUIDs must never be interpolated into SQL.');
    acqInvoiceCheck(strpos($selected['sql'], 'Approved') === false, 'Status values must never be interpolated into SQL.');
    acqInvoiceCheck(strpos($selected['sql'], 'i.vendor_id IN (:vendor_0)') !== false, 'Apply selected vendors as bound predicates.');

    acqInvoiceExpectFieldError($compile, ['acqUnit' => 'SC; DROP TABLE'], 'acqUnit', 'Reject an acquisitions unit outside the option whitelist.');
    acqInvoiceExpectFieldError($compile, ['startDate' => '2025-02-30'], 'startDate', 'Reject impossible invoice start dates.');
    acqInvoiceExpectFieldError($compile, ['endDate' => '2025-06-30', 'startDate' => '2025-07-01'], 'endDate', 'Reject a range whose end precedes its start.');
    acqInvoiceExpectFieldError($compile, ['statuses' => 'Approved,Approved'], 'statuses', 'Reject duplicate status selections.');
    acqInvoiceExpectFieldError($compile, ['statuses' => 'Approved) OR 1=1 --'], 'statuses', 'Reject a status outside the FOLIO option whitelist.');
    acqInvoiceExpectFieldError($compile, ['statuses' => ''], 'statuses', 'Require at least one selected invoice status.');
    acqInvoiceExpectFieldError($compile, ['vendorIds' => 'not-a-uuid'], 'vendorIds', 'Reject vendor values that are not UUIDs.');
    acqInvoiceExpectFieldError($compile, ['vendorIds' => $vendorOne . ',' . $vendorOne], 'vendorIds', 'Reject duplicate vendor selections.');

    $originalSql = $report->sql_template;
    $report->sql_template = 'SELECT 1';
    try {
        $compile();
        acqInvoiceCheck(false, 'Reject a report whose stored SQL has drifted from migration 049.');
    } catch (\InvalidArgumentException $error) {
        acqInvoiceCheck(strpos($error->getMessage(), 'seed contract') !== false, 'Report drift must identify the seed contract.');
    }
    $report->sql_template = $originalSql;

    $db->missingTable = 'organizations.organizations__t';
    try {
        $compile();
        acqInvoiceCheck(false, 'Reject missing tables instead of returning an incomplete report.');
    } catch (\InvalidArgumentException $error) {
        acqInvoiceCheck(strpos($error->getMessage(), 'organizations.organizations__t') !== false, 'Report the missing table name.');
    }
    $db->missingTable = null;

    $contract = \app\services\ReportExecutionContractService::fromReport($report, [
        'exportKind' => 'worklist',
        'marcTag' => $compiled['marcTag'],
        'locationName' => $compiled['location']['name'],
        'locationCode' => $compiled['location']['code'],
    ]);
    acqInvoiceCheck($contract['identifierExport']['sourceColumn'] === 'Invoice UUID', 'Expose the invoice UUID for CSV export.');
    \app\services\ReportExecutionContractService::assertStaticExportSql($report->sql_template, $contract);
    \app\services\SqlBuilderService::validateSafety($compiled['sql']);
    \app\services\SqlBuilderService::validateTablePolicy($compiled['sql']);

    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ATTACH DATABASE ':memory:' AS invoice");
    $pdo->exec("ATTACH DATABASE ':memory:' AS orders");
    $pdo->exec("ATTACH DATABASE ':memory:' AS organizations");
    $pdo->exec("ATTACH DATABASE ':memory:' AS finance");
    $pdo->exec('CREATE TABLE invoice.invoices__t (id TEXT, vendor_invoice_no TEXT, vendor_id TEXT, status TEXT, payment_method TEXT, invoice_date TEXT, approval_date TEXT, payment_date TEXT, fiscal_year_id TEXT, total NUMERIC, currency TEXT, lock_total NUMERIC)');
    $pdo->exec('CREATE TABLE invoice.invoices__t__acq_unit_ids (id TEXT, acq_unit_ids TEXT)');
    $pdo->exec('CREATE TABLE orders.acquisitions_unit__t (id TEXT, name TEXT)');
    $pdo->exec('CREATE TABLE organizations.organizations__t (id TEXT, name TEXT, code TEXT, is_vendor BOOLEAN)');
    $pdo->exec('CREATE TABLE finance.fiscal_year__t (id TEXT, code TEXT)');

    $scUnit = '00000000-0000-4000-8000-000000000101';
    $acUnit = '00000000-0000-4000-8000-000000000102';
    $vendorTwo = '00000000-0000-4000-8000-000000000002';
    $pdo->exec("INSERT INTO orders.acquisitions_unit__t VALUES ('$scUnit', 'SC'), ('$acUnit', 'AC')");
    $pdo->exec("INSERT INTO organizations.organizations__t VALUES ('$vendorOne', 'Vendor One', 'V1', 1), ('$vendorTwo', 'Vendor Two', 'V2', 1)");
    $pdo->exec("INSERT INTO finance.fiscal_year__t VALUES ('fy26', 'SCFY2026')");

    $invoices = [
        ['invoice-1', 'APPROVED-START', $vendorOne, 'Approved', '2025-07-01', 100, 100],
        ['invoice-2', 'OPEN-END', $vendorTwo, 'Open', '2026-06-30', 100, 125],
        ['invoice-3', 'PAID-IN-RANGE', $vendorOne, 'Paid', '2025-07-10', 40, null],
        ['invoice-4', 'CANCELLED-IN-RANGE', $vendorOne, 'Cancelled', '2025-08-01', 10, 10],
        ['invoice-5', 'VENDORLESS-OPEN', null, 'Open', '2025-08-15', 30, null],
        ['invoice-6', 'REVIEWED-IN-RANGE', $vendorTwo, 'Reviewed', '2026-01-01', 50, 50],
        ['invoice-7', 'BEFORE-RANGE', $vendorOne, 'Paid', '2025-06-30', 20, 20],
        ['invoice-8', 'AC-ONLY', $vendorOne, 'Open', '2025-09-01', 60, 60],
    ];
    $invoiceInsert = $pdo->prepare('INSERT INTO invoice.invoices__t VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?)');
    $unitInsert = $pdo->prepare('INSERT INTO invoice.invoices__t__acq_unit_ids VALUES (?, ?)');
    foreach ($invoices as $index => $invoice) {
        list($id, $number, $vendor, $status, $date, $total, $lockTotal) = $invoice;
        $invoiceInsert->execute([$id, $number, $vendor, $status, 'Electronic', $date, 'fy26', $total, 'USD', $lockTotal]);
        if ($id !== 'invoice-8') {
            $unitInsert->execute([$id, $scUnit]);
        }
        if ($id === 'invoice-2') {
            $unitInsert->execute([$id, $scUnit]);
            $unitInsert->execute([$id, $acUnit]);
        }
        if ($id === 'invoice-8') {
            $unitInsert->execute([$id, $acUnit]);
        }
    }
    $run = function ($overrides = []) use ($compile, $pdo) {
        $result = $compile($overrides);
        $sql = str_replace(
            [
                "CAST(:startDate AS date)",
                "CAST(:endDate AS date) + INTERVAL '1 day'",
                'i.lock_total IS DISTINCT FROM i.total',
                'i.invoice_date DESC NULLS LAST',
                'i.vendor_invoice_no ASC NULLS LAST',
            ],
            [
                ':startDate',
                "date(:endDate, '+1 day')",
                'i.lock_total IS NOT i.total',
                'i.invoice_date IS NULL ASC, i.invoice_date DESC',
                'i.vendor_invoice_no IS NULL ASC, i.vendor_invoice_no ASC',
            ],
            $result['sql']
        );
        $statement = $pdo->prepare($sql);
        $statement->execute($result['params']);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    };
    $defaultRows = $run();
    $byId = array_column($defaultRows, null, 'Invoice UUID');
    acqInvoiceCheck(count($defaultRows) === 5, 'Default statuses include the date-edge invoices and exclude Cancelled.');
    acqInvoiceCheck(isset($byId['invoice-1'], $byId['invoice-2']), 'Include invoices on both inclusive date edges.');
    acqInvoiceCheck(isset($byId['invoice-5']), 'An empty vendor selection includes an invoice without a vendor record.');
    acqInvoiceCheck(count(array_filter($defaultRows, function ($row) { return $row['Invoice UUID'] === 'invoice-2'; })) === 1, 'An invoice linked to more than one unit still appears once.');
    acqInvoiceCheck(!isset($byId['invoice-4'], $byId['invoice-7'], $byId['invoice-8']), 'Exclude cancelled, before-range, and other-unit invoices by default.');
    acqInvoiceCheck($byId['invoice-1']['Lock Total Mismatch'] === '', 'Equal lock total and invoice total are not a mismatch.');
    acqInvoiceCheck($byId['invoice-2']['Lock Total Mismatch'] === 'Mismatch', 'A differing set lock total is flagged.');
    acqInvoiceCheck($byId['invoice-5']['Lock Total Mismatch'] === '', 'A null lock total is not a mismatch.');

    $vendorRows = $run(['vendorIds' => $vendorOne]);
    acqInvoiceCheck(array_column($vendorRows, 'Invoice UUID') === ['invoice-3', 'invoice-1'], 'A vendor selection restricts rows to that vendor.');
    $cancelledRows = $run(['statuses' => 'Cancelled']);
    acqInvoiceCheck(array_column($cancelledRows, 'Invoice UUID') === ['invoice-4'], 'Selecting Cancelled includes cancelled invoices.');
    $acRows = $run(['acqUnit' => 'AC']);
    acqInvoiceCheck(array_column($acRows, 'Invoice UUID') === ['invoice-2', 'invoice-8'], 'The selected acquisitions unit scopes invoice links.');

    fwrite(STDOUT, "Acquisitions invoice status report compiler, SQL semantics, and export contract checks passed\n");
}
