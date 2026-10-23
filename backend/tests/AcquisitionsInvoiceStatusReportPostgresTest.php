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
    function acquisitionsInvoicePostgresFail($message)
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function acquisitionsInvoicePostgresSkip($message)
    {
        fwrite(STDOUT, "SKIP: {$message}\n");
        exit(0);
    }

    if (getenv('RUN_FOLIO_DB_TESTS') !== '1') {
        acquisitionsInvoicePostgresSkip('Set RUN_FOLIO_DB_TESTS=1 to run live FOLIO PostgreSQL contract checks.');
    }
    if (!extension_loaded('pdo_pgsql')) {
        acquisitionsInvoicePostgresFail('RUN_FOLIO_DB_TESTS=1 requires the PDO PostgreSQL driver.');
    }

    require_once __DIR__ . '/support/AcquisitionsInvoiceStatusReportFixture.php';
    require_once __DIR__ . '/../services/AcquisitionsInvoiceStatusReportService.php';

    final class AcquisitionsInvoicePostgresCommand
    {
        private $pdo;
        private $sql;
        private $params;
        private $cachedQueryOne;
        private $hasCachedQueryOne;

        public function __construct(\PDO $pdo, $sql, array $params = [], $cachedQueryOne = null, $hasCachedQueryOne = false)
        {
            $this->pdo = $pdo;
            $this->sql = $sql;
            $this->params = $params;
            $this->cachedQueryOne = $cachedQueryOne;
            $this->hasCachedQueryOne = $hasCachedQueryOne;
        }

        public function queryOne()
        {
            if ($this->hasCachedQueryOne) {
                return $this->cachedQueryOne;
            }
            $statement = $this->pdo->prepare($this->sql);
            $statement->execute($this->params);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        }

        public function queryAll()
        {
            $statement = $this->pdo->prepare($this->sql);
            $statement->execute($this->params);
            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        }
    }

    final class AcquisitionsInvoicePostgresDb
    {
        private $pdo;
        private $requiredTables = null;
        private $hasRequiredTables = false;

        public function __construct(\PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        public function createCommand($sql, array $params = [])
        {
            if (strpos($sql, 'to_regclass(') !== false) {
                if (!$this->hasRequiredTables) {
                    $this->requiredTables = (new AcquisitionsInvoicePostgresCommand($this->pdo, $sql, $params))->queryOne();
                    $this->hasRequiredTables = true;
                }
                return new AcquisitionsInvoicePostgresCommand($this->pdo, $sql, $params, $this->requiredTables, true);
            }
            return new AcquisitionsInvoicePostgresCommand($this->pdo, $sql, $params);
        }
    }

    function acquisitionsInvoicePostgresQuery(\PDO $pdo, $sql, array $params)
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    function acquisitionsInvoicePostgresUnion(array $reports)
    {
        $queries = [];
        $params = [];
        foreach ($reports as $name => $report) {
            $sql = $report['sql'];
            foreach ($report['params'] as $marker => $value) {
                $renamedMarker = ':report_' . $name . '_' . substr($marker, 1);
                $sql = preg_replace('/' . preg_quote($marker, '/') . '(?![A-Za-z0-9_])/', $renamedMarker, $sql);
                $params[$renamedMarker] = $value;
            }
            $queries[] = "SELECT '" . $name . "' AS __report_set, report_rows.*, "
                . 'source.vendor_invoice_no AS __source_invoice_number, '
                . 'vendor.name AS __source_vendor_name, vendor.code AS __source_vendor_code, '
                . 'source.status AS __source_invoice_status, source.payment_method AS __source_payment_method, '
                . 'source.invoice_date AS __source_invoice_date, source.approval_date AS __source_approval_date, '
                . 'source.payment_date AS __source_payment_date, fiscal_year.code AS __source_fiscal_year, '
                . 'source.total AS __source_total, source.currency AS __source_currency, '
                . 'source.lock_total AS __source_lock_total '
                . 'FROM (' . $sql . ') AS report_rows '
                . 'JOIN invoice.invoices__t AS source ON source.id::text = report_rows."Invoice UUID" '
                . 'LEFT JOIN organizations.organizations__t AS vendor ON vendor.id = source.vendor_id '
                . 'LEFT JOIN finance.fiscal_year__t AS fiscal_year ON fiscal_year.id = source.fiscal_year_id';
        }
        return ['sql' => implode(' UNION ALL ', $queries), 'params' => $params];
    }

    foreach (['FOLIO_PG_HOST', 'FOLIO_PG_PORT', 'FOLIO_PG_DB', 'FOLIO_PG_USER', 'FOLIO_PG_PASS'] as $key) {
        if (getenv($key) === false || getenv($key) === '') {
            acquisitionsInvoicePostgresFail('The live FOLIO connection environment is incomplete.');
        }
    }

    $host = getenv('FOLIO_PG_HOST');
    $port = getenv('FOLIO_PG_PORT');
    $database = getenv('FOLIO_PG_DB');
    $username = getenv('FOLIO_PG_USER');
    $password = getenv('FOLIO_PG_PASS');
    $sslMode = getenv('FOLIO_PG_SSLMODE') ?: 'require';
    try {
        $pdo = new \PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';sslmode=' . $sslMode,
            $username,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    } catch (\Throwable $error) {
        acquisitionsInvoicePostgresFail('Could not connect to the live FOLIO PostgreSQL database.');
    }
    unset($password, $username);
    try {
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION READ ONLY');
    } catch (\Throwable $error) {
        acquisitionsInvoicePostgresFail('Could not start a read-only live FOLIO transaction.');
    }

    $report = acquisitionsInvoiceStatusReportFixture();
    $folioDb = new AcquisitionsInvoicePostgresDb($pdo);
    $startDefault = \app\models\ReportTemplate::resolveDefaultMacro('$fiscal_year_start');
    $endDefault = \app\models\ReportTemplate::resolveDefaultMacro('$today');
    $base = ['acqUnit' => 'SC', 'startDate' => $startDefault, 'endDate' => $endDefault, 'vendorIds' => ''];

    try {
        $reports = [];
        $reports['default'] = \app\services\AcquisitionsInvoiceStatusReportService::build(
            $report,
            $base + ['statuses' => 'Open,Reviewed,Approved,Paid'],
            $folioDb
        );
        $reports['fiscal_year'] = \app\services\AcquisitionsInvoiceStatusReportService::build(
            $report,
            ['acqUnit' => 'SC', 'startDate' => '2025-07-01', 'endDate' => '2026-06-30', 'statuses' => 'Approved,Open,Paid', 'vendorIds' => ''],
            $folioDb
        );
        $reports['approved'] = \app\services\AcquisitionsInvoiceStatusReportService::build(
            $report,
            $base + ['statuses' => 'Approved'],
            $folioDb
        );
        $reports['open_history'] = \app\services\AcquisitionsInvoiceStatusReportService::build(
            $report,
            ['acqUnit' => 'SC', 'startDate' => '1900-01-01', 'endDate' => '9999-12-30', 'statuses' => 'Open', 'vendorIds' => ''],
            $folioDb
        );
        $combined = acquisitionsInvoicePostgresUnion($reports);
        $combinedRows = acquisitionsInvoicePostgresQuery($pdo, $combined['sql'], $combined['params']);
    } catch (\Throwable $error) {
        acquisitionsInvoicePostgresFail('The live invoice report query failed; connection details were withheld.');
    }

    $rowsByReport = ['default' => [], 'fiscal_year' => [], 'approved' => [], 'open_history' => []];
    foreach ($combinedRows as $row) {
        $reportSet = $row['__report_set'];
        unset($row['__report_set']);
        $rowsByReport[$reportSet][] = $row;
    }
    $defaultRows = $rowsByReport['default'];
    $fiscalYearRows = $rowsByReport['fiscal_year'];
    $approvedRows = $rowsByReport['approved'];
    $openHistoryRows = $rowsByReport['open_history'];

    if (count($fiscalYearRows) !== 971) {
        acquisitionsInvoicePostgresFail('The SC FY2026 Approved/Open/Paid count did not match the reviewed 971-row evidence.');
    }
    if (count($approvedRows) !== 2) {
        acquisitionsInvoicePostgresFail('The Approved-only default-range query did not return the two reviewed invoices.');
    }

    $spotChecks = [];
    foreach ($defaultRows as $row) {
        if (!isset($spotChecks['approved']) && $row['Invoice Status'] === 'Approved') {
            $spotChecks['approved'] = $row;
        }
        if (!isset($spotChecks['open'])
            && $row['Invoice Status'] === 'Open'
            && $row['Lock Total Mismatch'] !== 'Mismatch') {
            $spotChecks['open'] = $row;
        }
    }
    foreach ($openHistoryRows as $row) {
        if (!isset($spotChecks['mismatch']) && $row['Lock Total Mismatch'] === 'Mismatch') {
            $spotChecks['mismatch'] = $row;
        }
    }
    foreach (['approved', 'open', 'mismatch'] as $kind) {
        if (!isset($spotChecks[$kind]['Invoice UUID'])) {
            acquisitionsInvoicePostgresFail('The default range did not provide an invoice for each required live spot check.');
        }
    }

    foreach ($spotChecks as $kind => $row) {
        if ($row['__source_invoice_number'] !== $row['Invoice Number']
            || $row['__source_vendor_name'] !== $row['Vendor Name']
            || $row['__source_vendor_code'] !== $row['Vendor Code']
            || $row['__source_invoice_status'] !== $row['Invoice Status']
            || $row['__source_payment_method'] !== $row['Payment Method']
            || $row['__source_invoice_date'] !== $row['Invoice Date']
            || $row['__source_approval_date'] !== $row['Approval Date']
            || $row['__source_payment_date'] !== $row['Payment Date']
            || $row['__source_fiscal_year'] !== $row['Fiscal Year']
            || abs((float) $row['__source_total'] - (float) $row['Total Amount']) > 0.0001
            || $row['__source_currency'] !== $row['Currency']
            || ($row['__source_lock_total'] === null ? null : (float) $row['__source_lock_total'])
                !== ($row['Lock Total'] === null ? null : (float) $row['Lock Total'])) {
            acquisitionsInvoicePostgresFail('A selected report row did not match its live FOLIO invoice fields.');
        }
        if ($kind === 'mismatch' && $row['__source_lock_total'] === null) {
            acquisitionsInvoicePostgresFail('The lock-total mismatch sample has no stored lock total.');
        }
    }

    $pdo->commit();
    fwrite(STDOUT, 'Live SC default-range rows: ' . count($defaultRows) . "\n");
    fwrite(STDOUT, 'Live SC FY2026 Approved/Open/Paid rows: ' . count($fiscalYearRows) . "\n");
    fwrite(STDOUT, 'Live SC default-range Approved-only rows: ' . count($approvedRows) . "\n");
    foreach ($spotChecks as $kind => $row) {
        fwrite(STDOUT, 'Spot check ' . $kind . ' invoice UUID: ' . $row['Invoice UUID'] . "\n");
    }
    fwrite(STDOUT, "Live acquisitions invoice status report checks passed\n");
}
