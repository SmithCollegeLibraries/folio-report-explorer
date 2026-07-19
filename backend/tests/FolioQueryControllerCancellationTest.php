<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use app\models\QueryJob;
use app\models\User;
use yii\web\Application;

new Application([
    'id' => 'folio-query-controller-cancellation-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'request' => ['cookieValidationKey' => 'test-key', 'enableCsrfValidation' => false],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['identityClass' => User::class, 'enableSession' => false],
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
]);

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    smith_id VARCHAR(50),
    username VARCHAR(100),
    role VARCHAR(20),
    is_approved INTEGER
)
SQL)->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    sql_text TEXT NOT NULL,
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
    created_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL
)
SQL)->execute();

function controllerCancellationAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function cancellationIdentity($id, $role)
{
    $identity = new User();
    $identity->setAttributes([
        'id' => $id,
        'smith_id' => 'smith-' . $id,
        'username' => 'user-' . $id,
        'role' => $role,
        'is_approved' => 1,
    ], false);
    Yii::$app->user->setIdentity($identity);
}

function controllerCancellationJob($id, $ownerId, $status = 'pending')
{
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => 'SELECT 1',
        'params' => '{}',
        'source' => 'manual',
        'data_source' => 'folio',
        'user_id' => $ownerId,
        'status' => $status,
        'progress_message' => 'Queued',
        'created_at' => '2026-07-19 10:00:00',
    ])->execute();
}

function invokeCancellation($id)
{
    Yii::$app->response->statusCode = 200;
    $controller = new FolioQueryController('folio-query', Yii::$app);
    return $controller->actionQueryCancel($id);
}

cancellationIdentity(7, 'user');
controllerCancellationJob('owned-job', 7);
$ownedResponse = invokeCancellation('owned-job');
controllerCancellationAssert(Yii::$app->response->statusCode === 200, 'An owner should be allowed to cancel their job.');
controllerCancellationAssert(($ownedResponse['status'] ?? null) === 'cancelled', 'A pending owned job should return cancelled.');

controllerCancellationJob('foreign-job', 8);
$foreignResponse = invokeCancellation('foreign-job');
controllerCancellationAssert(Yii::$app->response->statusCode === 403, 'A non-owner should receive HTTP 403.');
controllerCancellationAssert(($foreignResponse['error'] ?? null) === 'Forbidden', 'A non-owner should receive stable forbidden copy.');
controllerCancellationAssert(QueryJob::findOne('foreign-job')->status === 'pending', 'A forbidden cancellation must not change job state.');

cancellationIdentity(1, 'admin');
$adminResponse = invokeCancellation('foreign-job');
controllerCancellationAssert(Yii::$app->response->statusCode === 200, 'An administrator should cancel another user’s job.');
controllerCancellationAssert(($adminResponse['status'] ?? null) === 'cancelled', 'Admin cancellation should return the updated status.');

$repeatResponse = invokeCancellation('foreign-job');
controllerCancellationAssert(Yii::$app->response->statusCode === 200, 'Repeated cancellation should be idempotent.');
controllerCancellationAssert(($repeatResponse['status'] ?? null) === 'cancelled', 'Repeated cancellation should retain cancelled.');

controllerCancellationJob('completed-job', 8, 'completed');
$completedResponse = invokeCancellation('completed-job');
controllerCancellationAssert(Yii::$app->response->statusCode === 409, 'A completed job should return HTTP 409.');
controllerCancellationAssert(isset($completedResponse['error']), 'A completed job should return an explanatory error.');

$missingResponse = invokeCancellation('missing-job');
controllerCancellationAssert(Yii::$app->response->statusCode === 404, 'A missing job should return HTTP 404.');
controllerCancellationAssert(($missingResponse['error'] ?? null) === 'Job not found', 'A missing job should return stable not-found copy.');

fwrite(STDOUT, "Folio query controller cancellation test passed\n");
