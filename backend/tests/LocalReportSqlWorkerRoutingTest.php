<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\commands\ExportWorkerController;
use app\commands\QueryWorkerController;
use app\models\QueryJob;
use app\models\ReportTemplate;
use yii\console\Application;
use yii\db\Connection;

class LocalReportCompositeFolioConnection extends Connection
{
    public function createCommand($sql = null, $params = [])
    {
        if (is_string($sql) && strpos($sql, 'SELECT set_config(') === 0) {
            $sql = 'SELECT :application_name';
        } elseif (is_string($sql) && trim($sql) === 'SET TRANSACTION READ ONLY') {
            $sql = 'SELECT 1';
        }
        return parent::createCommand($sql, $params);
    }
}

function localReportWorkerAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$runtimeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'local-report-worker-' . bin2hex(random_bytes(8));
mkdir($runtimeRoot, 0700, true);
register_shutdown_function(static function () use ($runtimeRoot): void {
    if (!is_dir($runtimeRoot)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($runtimeRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($runtimeRoot);
});

$testUser = bin2hex(random_bytes(8));
$testPassword = bin2hex(random_bytes(16));
putenv('LOCAL_REPORT_DB_USER=' . $testUser);
putenv('LOCAL_REPORT_DB_PASS=' . $testPassword);

new Application([
    'id' => 'local-report-sql-worker-routing-test',
    'basePath' => dirname(__DIR__),
    'runtimePath' => $runtimeRoot,
    'controllerNamespace' => 'app\\commands',
    'components' => [
        'db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:'],
        'localReportDb' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => LocalReportCompositeFolioConnection::class, 'dsn' => 'sqlite::memory:'],
    ],
    'params' => [
        'queryTimeoutMs' => 30000,
        'exportRowLimit' => 99,
        'exportPreviewRows' => 2,
    ],
]);

foreach ([Yii::$app->db, Yii::$app->localReportDb] as $connection) {
    $connection->createCommand('CREATE TABLE route_marker (value TEXT NOT NULL)')->execute();
}
Yii::$app->db->createCommand("INSERT INTO route_marker (value) VALUES ('main')")->execute();
Yii::$app->localReportDb->createCommand("INSERT INTO route_marker (value) VALUES ('local')")->execute();

Yii::$app->db->createCommand('CREATE TABLE route_options (value TEXT, label TEXT)')->execute();
Yii::$app->localReportDb->createCommand('CREATE TABLE route_options (value TEXT, label TEXT)')->execute();
Yii::$app->db->createCommand("INSERT INTO route_options VALUES ('main', 'Main option')")->execute();
Yii::$app->localReportDb->createCommand("INSERT INTO route_options VALUES ('local', 'Local option')")->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    sql_text TEXT NOT NULL,
    sql_hash TEXT,
    params TEXT,
    source TEXT,
    data_source TEXT,
    name TEXT,
    user_id INTEGER,
    status TEXT,
    result_columns TEXT,
    result_rows TEXT,
    row_count INTEGER,
    execution_time_ms INTEGER,
    error_message TEXT,
    progress_message TEXT,
    output_mode TEXT,
    export_file_path TEXT,
    estimated_rows INTEGER,
    estimated_cost NUMERIC,
    metadata TEXT,
    created_at TEXT,
    started_at TEXT,
    completed_at TEXT
)
SQL
)->execute();
Yii::$app->db->createCommand('CREATE TABLE query_log (id INTEGER PRIMARY KEY AUTOINCREMENT, sql_text TEXT, params TEXT, source TEXT, data_source TEXT, user_id INTEGER, row_count INTEGER, execution_time_ms INTEGER, error_message TEXT)')->execute();
Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE report_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT,
    name TEXT,
    description TEXT,
    help_text TEXT,
    category TEXT,
    sql_template TEXT,
    parameters TEXT,
    data_source TEXT,
    composite_config TEXT,
    execution_config TEXT,
    default_limit INTEGER,
    is_active INTEGER,
    created_by TEXT,
    created_at TEXT,
    updated_at TEXT
)
SQL
)->execute();

Yii::$app->localReportDb->createCommand('CREATE TABLE report_expense_allocations (expense_class_code TEXT, allocation_amount NUMERIC, fiscal_year INTEGER)')->execute();
Yii::$app->localReportDb->createCommand("INSERT INTO report_expense_allocations VALUES ('EC-1', 25, 2027)")->execute();
Yii::$app->folioDb->createCommand('CREATE TABLE primary_rows (expense_code TEXT)')->execute();
Yii::$app->folioDb->createCommand("INSERT INTO primary_rows VALUES ('EC-1')")->execute();

Yii::$app->db->createCommand()->insert('report_templates', [
    'slug' => 'local-options',
    'name' => 'Local options',
    'sql_template' => 'SELECT 1',
    'parameters' => json_encode([[
        'name' => 'choice',
        'type' => 'select',
        'options_sql' => 'SELECT value, label FROM route_options',
        'options_db' => 'local',
    ]]),
    'data_source' => 'local',
    'is_active' => 1,
])->execute();
$report = ReportTemplate::findOne(['slug' => 'local-options']);
$options = $report->fetchSelectOptions();
localReportWorkerAssertSame('local', $options['choice'][0]['value'] ?? null, 'Local report options_sql uses localReportDb.');
putenv('LOCAL_REPORT_DB_USER=');
putenv('LOCAL_REPORT_DB_PASS=');
localReportWorkerAssertSame([], $report->fetchSelectOptions()['choice'] ?? null, 'Unconfigured local options return an empty list.');
putenv('LOCAL_REPORT_DB_USER=' . $testUser);
putenv('LOCAL_REPORT_DB_PASS=' . $testPassword);

$worker = new QueryWorkerController('query-worker', Yii::$app);
$localJob = QueryJob::createJob('SELECT value FROM route_marker', [], 'manual', 'local');
$localJob->id = 'worker-local-route';
$localJob->status = 'running';
$localJob->save(false);
$executeJob = new ReflectionMethod($worker, 'executeJob');
if (PHP_VERSION_ID < 80100) {
    $executeJob->setAccessible(true);
}
$executeJob->invoke($worker, $localJob);
$localJob->refresh();
localReportWorkerAssertSame('completed', $localJob->status, 'Local query jobs complete through the worker.');
localReportWorkerAssertSame('local', $localJob->getDecodedRows()[0]['value'] ?? null, 'QueryWorkerController uses localReportDb for local jobs.');

$compositeJob = QueryJob::createJob(
    'SELECT expense_code AS "Expense Class Code" FROM primary_rows',
    [':fiscalYear' => 2027],
    'report',
    'composite',
    [
        'composite_config' => [
            'secondary_sql' => 'SELECT expense_class_code, allocation_amount FROM report_expense_allocations WHERE fiscal_year = :fiscalYear',
            'merge_key' => ['primary' => 'Expense Class Code', 'secondary' => 'expense_class_code'],
            'append_columns' => ['allocation_amount AS Allocation'],
        ],
        'bound_params' => [':fiscalYear' => 2027],
    ]
);
$compositeJob->id = 'worker-composite-local-route';
$compositeJob->status = 'running';
$compositeJob->save(false);
$executeComposite = new ReflectionMethod($worker, 'executeCompositeJob');
if (PHP_VERSION_ID < 80100) {
    $executeComposite->setAccessible(true);
}
$executeComposite->invoke($worker, $compositeJob, microtime(true));
$compositeJob->refresh();
localReportWorkerAssertSame('completed', $compositeJob->status, 'Composite jobs complete.');
localReportWorkerAssertSame(25.0, (float) ($compositeJob->getDecodedRows()[0]['Allocation'] ?? 0), 'Composite secondary SQL uses localReportDb and merges its result.');

$exportJob = QueryJob::createJob('SELECT value FROM route_marker', [], 'report', 'local');
$exportJob->id = 'worker-local-export-route';
$exportJob->status = 'running';
$exportJob->output_mode = 'file';
$exportJob->save(false);
$exportWorker = new ExportWorkerController('export-worker', Yii::$app);
$executeExport = new ReflectionMethod($exportWorker, 'executeExportJob');
if (PHP_VERSION_ID < 80100) {
    $executeExport->setAccessible(true);
}
$executeExport->invoke($exportWorker, $exportJob);
$exportJob->refresh();
localReportWorkerAssertSame('completed', $exportJob->status, 'Local file exports complete.');
localReportWorkerAssertSame(true, strpos((string) file_get_contents($exportJob->export_file_path), 'local') !== false, 'ExportWorkerController streams localReportDb results.');

putenv('LOCAL_REPORT_DB_USER=');
putenv('LOCAL_REPORT_DB_PASS=');
$unconfiguredJob = QueryJob::createJob('SELECT value FROM route_marker', [], 'manual', 'local');
$unconfiguredJob->id = 'worker-local-unconfigured';
$unconfiguredJob->status = 'running';
$unconfiguredJob->save(false);
$executeJob->invoke($worker, $unconfiguredJob);
$unconfiguredJob->refresh();
localReportWorkerAssertSame('failed', $unconfiguredJob->status, 'Unconfigured local jobs fail in the worker.');
localReportWorkerAssertSame(
    "Local reports aren't configured on this server.",
    $unconfiguredJob->error_message,
    'Unconfigured local jobs do not fall back to the app DB.'
);

$unconfiguredExportJob = QueryJob::createJob('SELECT value FROM route_marker', [], 'report', 'local');
$unconfiguredExportJob->id = 'worker-local-export-unconfigured';
$unconfiguredExportJob->status = 'running';
$unconfiguredExportJob->output_mode = 'file';
$unconfiguredExportJob->save(false);
$executeExport->invoke($exportWorker, $unconfiguredExportJob);
$unconfiguredExportJob->refresh();
localReportWorkerAssertSame('failed', $unconfiguredExportJob->status, 'Unconfigured local exports fail in the worker.');
localReportWorkerAssertSame(
    "Local reports aren't configured on this server.",
    $unconfiguredExportJob->error_message,
    'Unconfigured local exports do not fall back to the app DB.'
);

echo "OK: options, local jobs, composite jobs and exports use localReportDb.\n";
