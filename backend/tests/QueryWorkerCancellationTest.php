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

new Application([
    'id' => 'query-worker-cancellation-test',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\\commands',
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
    'params' => [
        'queryTimeoutMs' => 30000,
        'exportRowLimit' => 100,
        'exportPreviewRows' => 10,
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

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sql_text TEXT,
    params TEXT,
    source VARCHAR(20),
    data_source VARCHAR(20),
    user_id INTEGER,
    row_count INTEGER,
    execution_time_ms INTEGER,
    error_message TEXT
)
SQL)->execute();

function workerCancellationAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function insertWorkerCancellationJob($id, $dataSource, $outputMode)
{
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => 'SELECT 1 AS value',
        'params' => '{}',
        'source' => 'manual',
        'data_source' => $dataSource,
        'user_id' => 7,
        'status' => 'cancelling',
        'progress_message' => 'Cancelling…',
        'output_mode' => $outputMode,
        'created_at' => '2026-07-19 10:00:00',
        'started_at' => '2026-07-19 10:00:01',
    ])->execute();

    return QueryJob::findOne($id);
}

$queryJob = insertWorkerCancellationJob('query-cancelling', 'local', 'table');
$queryWorker = new QueryWorkerController('query-worker', Yii::$app);
$executeQuery = new ReflectionMethod($queryWorker, 'executeJob');
$executeQuery->invoke($queryWorker, $queryJob);
$queryJob->refresh();
workerCancellationAssert($queryJob->status === 'cancelled', 'A query worker should terminalize a pre-execution cancellation.');
workerCancellationAssert($queryJob->row_count === 0, 'A cancelled query should not publish rows.');
workerCancellationAssert($queryJob->error_message === null, 'A cancelled query should not expose an execution failure.');

$exportJob = insertWorkerCancellationJob('export-cancelling', 'local', 'file');
$exportWorker = new ExportWorkerController('export-worker', Yii::$app);
$executeExport = new ReflectionMethod($exportWorker, 'executeExportJob');
$executeExport->invoke($exportWorker, $exportJob);
$exportJob->refresh();
workerCancellationAssert($exportJob->status === 'cancelled', 'An export worker should terminalize a pre-execution cancellation.');
workerCancellationAssert(empty($exportJob->export_file_path), 'A cancelled export should not publish a download path.');

fwrite(STDOUT, "Query worker cancellation test passed\n");
