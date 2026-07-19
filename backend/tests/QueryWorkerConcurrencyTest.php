<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\commands\QueryWorkerController;
use app\models\QueryJob;
use yii\console\Application;

$config = [
    'id' => 'query-worker-concurrency-test',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\\commands',
    'components' => [
        'db' => [
            'class' => yii\db\Connection::class,
            'dsn' => 'sqlite::memory:',
        ],
        'folioDb' => [
            'class' => yii\db\Connection::class,
            'dsn' => 'sqlite::memory:',
        ],
    ],
];

new Application($config);

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

function insertQueryWorkerConcurrencyJob(string $id, string $status, string $createdAt): void
{
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => 'SELECT 1',
        'params' => '{}',
        'source' => 'nl',
        'data_source' => 'folio',
        'status' => $status,
        'progress_message' => $status === 'running' ? 'Executing query...' : 'Queued',
        'created_at' => $createdAt,
    ])->execute();
}

function claimNextQueryWorkerJob(): ?QueryJob
{
    $controller = new QueryWorkerController('query-worker', Yii::$app);
    $method = new ReflectionMethod($controller, 'claimNextJob');
    $job = $method->invoke($controller);

    return $job instanceof QueryJob ? $job : null;
}

function assertQueryWorkerConcurrency(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

putenv('QUERY_WORKER_MAX_FOLIO_JOBS=2');

insertQueryWorkerConcurrencyJob('running-job', 'running', '2026-05-27 10:00:00');
insertQueryWorkerConcurrencyJob('pending-job', 'pending', '2026-05-27 10:01:00');

$claimed = claimNextQueryWorkerJob();
assertQueryWorkerConcurrency($claimed !== null, 'Expected a second FOLIO job to be claimable while concurrency capacity remains.');
assertQueryWorkerConcurrency($claimed->id === 'pending-job', 'Expected the pending job to be claimed.');
assertQueryWorkerConcurrency($claimed->status === 'running', 'Expected the claimed job to be marked running.');

insertQueryWorkerConcurrencyJob('pending-over-limit', 'pending', '2026-05-27 10:02:00');
Yii::$app->db->createCommand()->update('query_jobs', [
    'status' => 'cancelling',
    'progress_message' => 'Cancelling...',
], ['id' => 'pending-job'])->execute();
$overLimitClaim = claimNextQueryWorkerJob();
assertQueryWorkerConcurrency($overLimitClaim === null, 'Expected a cancelling FOLIO job to continue consuming its concurrency slot.');

fwrite(STDOUT, "Query worker concurrency test passed\n");
