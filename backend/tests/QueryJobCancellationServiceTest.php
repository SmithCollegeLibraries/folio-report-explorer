<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\models\QueryJob;
use app\services\QueryJobCancellationService;
use yii\console\Application;

new Application([
    'id' => 'query-job-cancellation-service-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
]);

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    sql_text TEXT NOT NULL,
    params TEXT NULL,
    source VARCHAR(20) DEFAULT 'manual',
    data_source VARCHAR(20) DEFAULT 'folio',
    user_id INTEGER NULL,
    status VARCHAR(20) DEFAULT 'pending',
    error_message TEXT NULL,
    progress_message VARCHAR(255) NULL,
    pg_backend_pid INTEGER NULL,
    created_at DATETIME NULL,
    completed_at DATETIME NULL
)
SQL)->execute();

function cancellationAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function insertCancellationJob($id, $status, $pid = null, $dataSource = 'folio')
{
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => 'SELECT 1',
        'params' => '{}',
        'source' => 'manual',
        'data_source' => $dataSource,
        'user_id' => 7,
        'status' => $status,
        'progress_message' => 'Before cancellation',
        'pg_backend_pid' => $pid,
        'created_at' => '2026-07-19 10:00:00',
    ])->execute();

    return QueryJob::findOne($id);
}

$cancelledBackends = [];
$service = new QueryJobCancellationService(
    Yii::$app->db,
    Yii::$app->folioDb,
    function ($pid, $jobId) use (&$cancelledBackends) {
        $cancelledBackends[] = [$pid, $jobId];
        return true;
    }
);

$pending = $service->cancel(insertCancellationJob('pending-job', 'pending'));
cancellationAssert($pending->status === 'cancelled', 'A pending job should cancel immediately.');
cancellationAssert($pending->completed_at !== null, 'Immediate cancellation should set completed_at.');
cancellationAssert($pending->progress_message === 'Cancelled by user', 'Immediate cancellation should use friendly progress copy.');

$pendingExport = $service->cancel(insertCancellationJob('pending-export-job', 'pending_export'));
cancellationAssert($pendingExport->status === 'cancelled', 'A pending export should cancel immediately.');

$running = $service->cancel(insertCancellationJob('running-job', 'running', 4321));
cancellationAssert($running->status === 'cancelling', 'A running job should remain in cancelling until the worker confirms termination.');
cancellationAssert($running->completed_at === null, 'A cancelling job must not look terminal.');
cancellationAssert($running->progress_message === 'Cancelling…', 'A running cancellation should expose progress copy.');
cancellationAssert(
    $cancelledBackends === [[4321, 'running-job']],
    'The service should bind PostgreSQL cancellation to the stored PID and exact job identity.'
);
cancellationAssert(
    QueryJobCancellationService::applicationName('running-job') === 'folio-report-explorer:running-job',
    'Workers and the cancellation endpoint should derive the same PostgreSQL application name.'
);

$service->cancel($running);
cancellationAssert(
    $cancelledBackends === [[4321, 'running-job'], [4321, 'running-job']],
    'Retrying a cancelling job should retry the same validated backend interruption idempotently.'
);

$cancelled = $service->cancel(insertCancellationJob('cancelled-job', 'cancelled'));
cancellationAssert($cancelled->status === 'cancelled', 'Cancelling an already cancelled job should be idempotent.');

$local = $service->cancel(insertCancellationJob('local-job', 'running', 9999, 'local'));
cancellationAssert($local->status === 'cancelling', 'A local running job should enter the cooperative cancelling state.');
cancellationAssert(count($cancelledBackends) === 2, 'A local query must not send its PID to PostgreSQL.');

$completedRejected = false;
try {
    $service->cancel(insertCancellationJob('completed-job', 'completed'));
} catch (DomainException $exception) {
    $completedRejected = true;
}
cancellationAssert($completedRejected, 'A completed job should be rejected as non-cancellable.');

$terminal = insertCancellationJob('terminal-job', 'cancelling', 7777);
$terminal->error_message = 'canceling statement due to user request';
$terminal->save(false);
$terminal->markCancelled();
$terminal->refresh();
cancellationAssert($terminal->status === 'cancelled', 'markCancelled should set the terminal cancelled state.');
cancellationAssert($terminal->completed_at !== null, 'markCancelled should set completed_at.');
cancellationAssert($terminal->pg_backend_pid === null, 'markCancelled should clear the PostgreSQL backend PID.');
cancellationAssert($terminal->error_message === null, 'markCancelled should clear an internal database cancellation error.');
cancellationAssert($terminal->progress_message === 'Cancelled by user', 'markCancelled should use friendly user-facing copy.');

fwrite(STDOUT, "Query job cancellation service test passed\n");
