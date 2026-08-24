<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\commands\ExportWorkerController;
use app\commands\QueryWorkerController;
use app\models\QueryJob;
use yii\console\Application;
use yii\db\Connection;

class FlakyWorkerDatabaseConnection extends Connection
{
    public $failNextQueueRead = false;
    public $reconnectCloseCalls = 0;
    public $reconnectOpenCalls = 0;

    public function createCommand($sql = null, $params = [])
    {
        if (
            $this->failNextQueueRead
            && is_string($sql)
            && stripos($sql, 'SELECT') !== false
            && stripos($sql, 'query_jobs') !== false
        ) {
            $this->failNextQueueRead = false;
            throw new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }

        return parent::createCommand($sql, $params);
    }

    public function close()
    {
        if ($this->getIsActive()) {
            $this->reconnectCloseCalls++;
        }
        parent::close();
    }

    public function open()
    {
        $wasActive = $this->getIsActive();
        parent::open();
        if (!$wasActive && $this->getIsActive()) {
            $this->reconnectOpenCalls++;
        }
    }
}

function workerReconnectAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$databasePath = tempnam(sys_get_temp_dir(), 'worker-reconnect-');
register_shutdown_function(static function () use ($databasePath): void {
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
});

new Application([
    'id' => 'worker-database-reconnect-test',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\\commands',
    'components' => [
        'db' => [
            'class' => FlakyWorkerDatabaseConnection::class,
            'dsn' => 'sqlite:' . $databasePath,
        ],
        'folioDb' => [
            'class' => Connection::class,
            'dsn' => 'sqlite::memory:',
        ],
    ],
]);

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    sql_text TEXT NOT NULL,
    sql_hash VARCHAR(64) NULL,
    params TEXT NULL,
    source VARCHAR(20) DEFAULT 'manual',
    data_source VARCHAR(20) DEFAULT 'folio',
    name VARCHAR(255) NULL,
    user_id INTEGER NULL,
    status VARCHAR(20) DEFAULT 'pending',
    result_columns TEXT NULL,
    result_rows TEXT NULL,
    row_count INTEGER DEFAULT 0,
    execution_time_ms INTEGER DEFAULT 0,
    error_message TEXT NULL,
    progress_message VARCHAR(255) NULL,
    pg_backend_pid INTEGER NULL,
    output_mode VARCHAR(20) DEFAULT 'table',
    export_file_path VARCHAR(500) NULL,
    estimated_rows INTEGER NULL,
    estimated_cost NUMERIC NULL,
    metadata TEXT NULL,
    created_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL
)
SQL)->execute();

Yii::$app->db->createCommand()->insert('query_jobs', [
    'id' => 'query-reconnect-job',
    'sql_text' => 'SELECT 1',
    'params' => '{}',
    'source' => 'manual',
    'data_source' => 'folio',
    'status' => 'pending',
    'progress_message' => 'Queued',
    'created_at' => '2026-08-24 15:00:00',
])->execute();

/** @var FlakyWorkerDatabaseConnection $db */
$db = Yii::$app->db;
$db->reconnectCloseCalls = 0;
$db->reconnectOpenCalls = 0;
$db->failNextQueueRead = true;

$queryWorker = new QueryWorkerController('query-worker', Yii::$app);
$claimNextQueryJob = new ReflectionMethod($queryWorker, 'claimNextJob');
$claimNextQueryJob->setAccessible(true);
try {
    $queryJob = $claimNextQueryJob->invoke($queryWorker);
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL: The query worker did not recover from MySQL error 2006: {$error->getMessage()}\n");
    exit(1);
}

workerReconnectAssertSame(true, $queryJob instanceof QueryJob, 'The query worker should recover and claim the queued job.');
workerReconnectAssertSame('running', $queryJob->status, 'The recovered query worker should transition the job to running.');
workerReconnectAssertSame(1, $db->reconnectCloseCalls, 'The query worker should close the stale queue connection once.');
workerReconnectAssertSame(1, $db->reconnectOpenCalls, 'The query worker should reopen the queue connection once.');

Yii::$app->db->createCommand()->insert('query_jobs', [
    'id' => 'export-reconnect-job',
    'sql_text' => 'SELECT 1',
    'params' => '{}',
    'source' => 'manual',
    'data_source' => 'folio',
    'status' => 'pending_export',
    'progress_message' => 'Queued for CSV export',
    'output_mode' => 'file',
    'created_at' => '2026-08-24 15:01:00',
])->execute();

$db->reconnectCloseCalls = 0;
$db->reconnectOpenCalls = 0;
$db->failNextQueueRead = true;

$exportWorker = new ExportWorkerController('export-worker', Yii::$app);
$claimNextExportJob = new ReflectionMethod($exportWorker, 'claimNextExportJob');
$claimNextExportJob->setAccessible(true);
try {
    $exportJob = $claimNextExportJob->invoke($exportWorker);
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL: The export worker did not recover from MySQL error 2006: {$error->getMessage()}\n");
    exit(1);
}

workerReconnectAssertSame(true, $exportJob instanceof QueryJob, 'The export worker should recover and claim the queued job.');
workerReconnectAssertSame('running', $exportJob->status, 'The recovered export worker should transition the job to running.');
workerReconnectAssertSame(1, $db->reconnectCloseCalls, 'The export worker should close the stale queue connection once.');
workerReconnectAssertSame(1, $db->reconnectOpenCalls, 'The export worker should reopen the queue connection once.');

fwrite(STDOUT, "Worker database reconnect test passed\n");
