<?php

// No application config is loaded: all databases and records below are synthetic and in memory.
defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../../services/SqlBuilderService.php';
require_once __DIR__ . '/../../services/VisualizationDataService.php';
require_once __DIR__ . '/../../services/VisualizationHistoryService.php';
require_once __DIR__ . '/../../services/VisualizationHistoryMigrationRecognitionService.php';

function visualizationAssert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

function visualizationSame($expected, $actual, $message)
{
    visualizationAssert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

function visualizationNear($expected, $actual, $message)
{
    visualizationAssert(is_numeric($actual) && abs($expected - $actual) < 0.00001, $message . ': expected ' . $expected . ', got ' . var_export($actual, true));
}

function visualizationCase($name, $test)
{
    global $visualizationFailures;
    try {
        $test();
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    } catch (Throwable $error) {
        $visualizationFailures[] = $name;
        fwrite(STDERR, 'FAIL: ' . $name . ' — ' . $error->getMessage() . "\n");
    }
}

function visualizationFinish()
{
    global $visualizationFailures;
    $failed = count($visualizationFailures ?? []);
    fwrite(STDOUT, 'Visualization backend cases: ' . $failed . " failed\n");
    exit($failed === 0 ? 0 : 1);
}

function visualizationByYear(array $rows, int $year): array
{
    foreach ($rows as $row) if ((int)$row['fiscalYearEnd'] === $year) return $row;
    throw new RuntimeException('Expected a point for fiscal year ' . $year);
}

final class VisualizationSqlValidatorSpy
{
    public $safety = [];
    public $policy = [];
    public $reject = null;
    public function validateSafety($sql)
    {
        $this->safety[] = $sql;
        if ($this->reject === 'safety') throw new InvalidArgumentException('synthetic safety rejection');
        return \app\services\SqlBuilderService::validateSafety($sql);
    }
    public function validateTablePolicy($sql)
    {
        $this->policy[] = $sql;
        if ($this->reject === 'policy') throw new InvalidArgumentException('synthetic policy rejection');
        return \app\services\SqlBuilderService::validateTablePolicy($sql);
    }
}

/** Executes the received SQL and parameters; it never selects expected rows in PHP. */
final class VisualizationSqliteFolioDb
{
    public $db;
    public $events = [];
    public $queries = [];
    public $active = false;
    public $readOnly = false;
    public $timeout = false;
    public $failAt = null;
    public $driverName = 'pgsql';
    public function __construct($db) { $this->db = $db; }
    public function beginTransaction($isolationLevel = null)
    {
        $this->events[] = 'begin';
        $this->active = true;
        $this->readOnly = false;
        $this->timeout = false;
        return new VisualizationSqliteTransaction($this, $this->db->beginTransaction());
    }
    public function createCommand($sql = '', array $params = [])
    {
        return new VisualizationSqliteCommand($this, $sql, $params);
    }
    public function getDriverName() { return 'pgsql'; }
    public function evaluate($sql, array $params, $method)
    {
        if (preg_match('/^\s*SET\s+TRANSACTION\s+READ\s+ONLY\s*;?\s*$/i', $sql)) {
            visualizationAssert($this->active, 'READ ONLY must be inside a transaction.');
            $this->events[] = 'read only';
            $this->readOnly = true;
            return 0;
        }
        if (preg_match('/^\s*SET\s+LOCAL\s+statement_timeout\s*=\s*\'30s\'\s*;?\s*$/i', $sql)) {
            visualizationAssert($this->active, 'The timeout must be transaction-local.');
            $this->events[] = 'timeout';
            $this->timeout = true;
            return 0;
        }
        visualizationAssert($this->active && $this->readOnly && $this->timeout, 'Every FOLIO SELECT must run inside READ ONLY with a 30s local timeout.');
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        $this->events[] = 'query';
        if ($this->failAt === count($this->queries)) throw new RuntimeException('synthetic query failure');
        // Translate expression spelling only; retain joins, filters, aggregation and bound values.
        $sqlite = preg_replace('/::(?:date|timestamp|integer|int|numeric|decimal|text)\b/i', '', $sql);
        $sqlite = preg_replace('/EXTRACT\s*\(\s*YEAR\s+FROM\s+([\w.]+)\s*\)/i', "CAST(strftime('%Y', $1) AS INTEGER)", $sqlite);
        $sqlite = preg_replace('/\bCURRENT_DATE\b/i', "date('now')", $sqlite);
        $command = $this->db->createCommand($sqlite, $params);
        return $command->$method();
    }
}

final class VisualizationSqliteCommand
{
    private $db;
    private $sql;
    private $params;
    public function __construct($db, $sql, array $params) { $this->db = $db; $this->sql = $sql; $this->params = $params; }
    public function bindValues(array $params) { $this->params = array_merge($this->params, $params); return $this; }
    public function execute() { return $this->db->evaluate($this->sql, $this->params, 'execute'); }
    public function queryAll() { return $this->db->evaluate($this->sql, $this->params, 'queryAll'); }
    public function queryOne() { return $this->db->evaluate($this->sql, $this->params, 'queryOne'); }
    public function queryScalar() { return $this->db->evaluate($this->sql, $this->params, 'queryScalar'); }
    public function queryColumn() { return $this->db->evaluate($this->sql, $this->params, 'queryColumn'); }
}

final class VisualizationSqliteTransaction
{
    private $db;
    private $transaction;
    public function __construct($db, $transaction) { $this->db = $db; $this->transaction = $transaction; }
    public function commit() { $this->db->events[] = 'commit'; $this->db->active = false; $this->transaction->commit(); }
    public function rollBack() { $this->db->events[] = 'rollback'; $this->db->active = false; $this->transaction->rollBack(); }
    public function getIsActive() { return $this->db->active; }
}

final class VisualizationRecordingCache extends \yii\caching\ArrayCache
{
    public $writes = [];
    public function set($key, $value, $duration = 0, $dependency = null)
    {
        $this->writes[] = ['key' => $key, 'duration' => $duration];
        return parent::set($key, $value, $duration, $dependency);
    }
}

final class VisualizationStaleVersionCache extends \yii\caching\ArrayCache
{
    public $staleValue;
    public $staleReads = 0;
    public $writes = [];
    public function set($key, $value, $duration = 0, $dependency = null)
    {
        $this->writes[] = ['key' => $key, 'duration' => $duration];
        return parent::set($key, $value, $duration, $dependency);
    }
    public function get($key)
    {
        if ($key === 'visualization_history_version_v1' && $this->staleReads > 0) {
            $this->staleReads--;
            return $this->staleValue;
        }
        return parent::get($key);
    }
}

final class VisualizationFixture
{
    public $db;
    public $folio;
    public $cache;
    public $validator;
    private $nextPayment = 0;
    public function __construct()
    {
        $this->db = new \yii\db\Connection(['dsn' => 'sqlite::memory:']);
        $this->db->open();
        foreach (['orders', 'finance', 'invoice', 'inventory'] as $schema) {
            $this->db->createCommand("ATTACH DATABASE ':memory:' AS " . $schema)->execute();
        }
        foreach ([
            'orders.acquisitions_unit__t (id TEXT PRIMARY KEY, name TEXT, is_deleted INTEGER DEFAULT 0)',
            'orders.po_line__t (id TEXT PRIMARY KEY, purchase_order_id TEXT, eresource__material_type TEXT, physical__material_type TEXT)',
            'orders.purchase_order__t__acq_unit_ids (id TEXT, acq_unit_ids TEXT)',
            'finance.fiscal_year__t (id TEXT PRIMARY KEY, series TEXT, name TEXT, period_start TEXT, period_end TEXT)',
            'finance.fund__t (id TEXT PRIMARY KEY, code TEXT, name TEXT)',
            'finance.fund__t__acq_unit_ids (id TEXT, acq_unit_ids TEXT)',
            'finance.budget__t (id TEXT PRIMARY KEY, fund_id TEXT, fiscal_year_id TEXT, allocated NUMERIC)',
            'invoice.invoices__t (id TEXT PRIMARY KEY, fiscal_year_id TEXT, payment_date TEXT)',
            'invoice.invoice_lines__t__fund_distributions (id TEXT, invoice_id TEXT, po_line_id TEXT, invoice_line_status TEXT, fund_distributions__fund_id TEXT, fund_distributions__distribution_type TEXT, fund_distributions__value NUMERIC, total NUMERIC)',
            'inventory.material_type__t (id TEXT PRIMARY KEY, name TEXT)',
            'visualization_history (id INTEGER PRIMARY KEY AUTOINCREMENT, acq_unit_code VARCHAR(50), kind TEXT, fiscal_year_end SMALLINT, total_amount DECIMAL(14,2), electronic_amount DECIMAL(14,2), physical_amount DECIMAL(14,2), note VARCHAR(255), updated_by INT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME, deleted_by INT, UNIQUE(acq_unit_code, kind, fiscal_year_end))',
            'visualization_history_version (id INTEGER PRIMARY KEY, version INTEGER NOT NULL DEFAULT 0)',
            'users (id INTEGER PRIMARY KEY, role TEXT, is_approved INTEGER, username TEXT, smith_id TEXT)',
        ] as $table) $this->db->createCommand('CREATE TABLE ' . $table)->execute();
        $this->insert('visualization_history_version', ['id' => 1, 'version' => 0]);
        foreach (['s' => ' SC ', 'a' => 'AC', 'h' => 'HX', 'n' => 'NF', 'e' => 'EP'] as $id => $name) {
            $this->insert('orders.acquisitions_unit__t', ['id' => $id, 'name' => $name]);
        }
        for ($year = 2021; $year <= 2028; $year++) $this->year('SC', $year);
        foreach (['AC', 'NF', 'EP'] as $code) $this->year($code, 2027);
        foreach (['s', 'a', 'e', 'h'] as $id) {
            $this->insert('finance.fund__t', ['id' => 'fund-' . $id, 'code' => 'fund-' . $id, 'name' => 'Synthetic fund']);
            $this->insert('finance.fund__t__acq_unit_ids', ['id' => 'fund-' . $id, 'acq_unit_ids' => $id]);
        }
        // Deliberate duplicate membership catches join multiplication.
        $this->insert('finance.fund__t__acq_unit_ids', ['id' => 'fund-s', 'acq_unit_ids' => 's']);
        $this->folio = new VisualizationSqliteFolioDb($this->db);
        $this->cache = new VisualizationRecordingCache();
        $this->validator = new VisualizationSqlValidatorSpy();
    }
    public function insert($table, array $row) { $this->db->createCommand()->insert($table, $row)->execute(); }
    public function year($code, $end)
    {
        $this->insert('finance.fiscal_year__t', ['id' => $code . $end, 'series' => $code . 'FY', 'name' => 'FY' . $end, 'period_start' => ($end - 1) . '-07-01', 'period_end' => $end . '-06-30']);
    }
    public function payment($value, $type = 'amount', $total = 100, $material = 'Book', $fund = 'fund-s', $year = 2027, $status = 'Paid', $withPo = true, $physical = null, $series = 'SC')
    {
        $id = 'payment-' . ++$this->nextPayment;
        $this->insert('invoice.invoices__t', ['id' => $id, 'fiscal_year_id' => $series . $year, 'payment_date' => '1999-01-01']);
        foreach ([$material, $physical] as $name) {
            if ($name !== null) $this->db->createCommand('INSERT OR IGNORE INTO inventory.material_type__t VALUES (:id, :name)', [':id' => $name, ':name' => $name])->execute();
        }
        if ($withPo) {
            $this->insert('orders.po_line__t', ['id' => $id, 'purchase_order_id' => $id, 'eresource__material_type' => $material, 'physical__material_type' => $physical]);
            $this->insert('orders.purchase_order__t__acq_unit_ids', ['id' => $id, 'acq_unit_ids' => 'a']);
        }
        $this->insert('invoice.invoice_lines__t__fund_distributions', ['id' => $id, 'invoice_id' => $id, 'po_line_id' => $withPo ? $id : null, 'invoice_line_status' => $status, 'fund_distributions__fund_id' => $fund, 'fund_distributions__distribution_type' => $type, 'fund_distributions__value' => $value, 'total' => $total]);
    }
    public function budget($year, $amount, $fund = 'fund-s', $series = 'SC')
    {
        $this->insert('finance.budget__t', ['id' => uniqid('budget-', true), 'fund_id' => $fund, 'fiscal_year_id' => $series . $year, 'allocated' => $amount]);
    }
    public function history($year, $kind, $total, $electronic = null, $physical = null, $code = 'SC')
    {
        $this->insert('visualization_history', ['acq_unit_code' => $code, 'fiscal_year_end' => $year, 'kind' => $kind, 'total_amount' => $total, 'electronic_amount' => $electronic, 'physical_amount' => $physical]);
    }
    public function service($today = '2026-11-02')
    {
        return new \app\services\VisualizationDataService($this->folio, $this->db, $this->cache, $this->validator, $today);
    }
    public function historyService()
    {
        return new \app\services\VisualizationHistoryService($this->db, $this->cache, ['SC', 'AC', 'HX', 'NF', 'EP']);
    }
}
