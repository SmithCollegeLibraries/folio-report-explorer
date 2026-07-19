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

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'controller-history-deletion-' . bin2hex(random_bytes(8));
$runtimePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'runtime';
$exportPath = $runtimePath . DIRECTORY_SEPARATOR . 'exports';
mkdir($exportPath, 0700, true);

register_shutdown_function(static function () use ($temporaryRoot): void {
    if (!is_dir($temporaryRoot)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entry->getPathname());
        } else {
            rmdir($entry->getPathname());
        }
    }
    rmdir($temporaryRoot);
});

new Application([
    'id' => 'folio-query-controller-history-deletion-test',
    'basePath' => dirname(__DIR__),
    'runtimePath' => $runtimePath,
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
    email VARCHAR(255),
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
    export_file_path VARCHAR(500) NULL,
    created_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL
)
SQL)->execute();

Yii::$app->db->createCommand()->batchInsert(
    'users',
    ['id', 'smith_id', 'username', 'email', 'role', 'is_approved'],
    [
        [1, 'smith-1', 'admin', 'admin@example.test', 'admin', 1],
        [7, 'smith-7', 'owner', 'owner@example.test', 'user', 1],
        [8, 'smith-8', 'other', 'other@example.test', 'user', 1],
    ]
)->execute();

function historyDeletionAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function historyDeletionIdentity($id, $role)
{
    $identity = new User();
    $identity->setAttributes([
        'id' => $id,
        'smith_id' => 'smith-' . $id,
        'username' => 'user-' . $id,
        'email' => 'user-' . $id . '@example.test',
        'role' => $role,
        'is_approved' => 1,
    ], false);
    Yii::$app->user->setIdentity($identity);
}

function historyDeletionJob($id, $ownerId, $status, $completedAt = null)
{
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => 'SELECT 1',
        'params' => '{}',
        'source' => 'manual',
        'data_source' => 'folio',
        'user_id' => $ownerId,
        'status' => $status,
        'progress_message' => ucfirst($status),
        'created_at' => '2026-07-19 10:00:00',
        'completed_at' => $completedAt,
    ])->execute();
}

function invokeHistoryDeletion($id)
{
    Yii::$app->response->statusCode = 200;
    $controller = new FolioQueryController('folio-query', Yii::$app);
    return $controller->actionDeleteHistoryJob($id);
}

function queryHistoryItems(array $queryParams = [])
{
    Yii::$app->request->setQueryParams($queryParams);
    $controller = new FolioQueryController('folio-query', Yii::$app);
    $response = $controller->actionQueryHistory();
    $items = [];
    foreach ($response['items'] as $item) {
        $items[$item['jobId']] = $item;
    }
    return $items;
}

$ownedId = '8f4a4aa0-1111-4222-8333-123456789abc';
historyDeletionIdentity(7, 'user');
historyDeletionJob($ownedId, 7, 'completed', '2026-07-19 10:01:00');
$ownedResponse = invokeHistoryDeletion($ownedId);
historyDeletionAssert(Yii::$app->response->statusCode === 200, 'An owner should delete a completed UUID-keyed job.');
historyDeletionAssert(($ownedResponse['jobId'] ?? null) === $ownedId, 'Deletion should return the unchanged UUID.');
historyDeletionAssert(!QueryJob::find()->where(['id' => $ownedId])->exists(), 'Successful deletion should remove the owned row.');

$foreignId = '8f4a4aa0-2222-4222-8333-123456789abc';
historyDeletionJob($foreignId, 8, 'completed', '2026-07-19 10:02:00');
$foreignResponse = invokeHistoryDeletion($foreignId);
historyDeletionAssert(Yii::$app->response->statusCode === 403, 'A non-owner should receive HTTP 403.');
historyDeletionAssert(($foreignResponse['error'] ?? null) === 'Forbidden', 'A non-owner should receive stable forbidden copy.');
historyDeletionAssert(QueryJob::find()->where(['id' => $foreignId])->exists(), 'A forbidden deletion should retain the row.');

historyDeletionIdentity(1, 'admin');
$adminResponse = invokeHistoryDeletion($foreignId);
historyDeletionAssert(Yii::$app->response->statusCode === 200, 'An administrator should delete another user’s job.');
historyDeletionAssert(($adminResponse['jobId'] ?? null) === $foreignId, 'Administrator deletion should return the unchanged UUID.');
historyDeletionAssert(!QueryJob::find()->where(['id' => $foreignId])->exists(), 'Administrator deletion should remove the row.');

$missingId = '8f4a4aa0-3333-4222-8333-123456789abc';
$missingResponse = invokeHistoryDeletion($missingId);
historyDeletionAssert(Yii::$app->response->statusCode === 404, 'A missing job should receive HTTP 404.');
historyDeletionAssert(($missingResponse['error'] ?? null) === 'Job not found', 'A missing job should receive stable not-found copy.');

historyDeletionIdentity(7, 'user');
$activeStatuses = ['pending', 'pending_export', 'running', 'cancelling'];
foreach ($activeStatuses as $index => $status) {
    $id = sprintf('8f4a4aa0-4%03d-4222-8333-123456789abc', $index);
    historyDeletionJob($id, 7, $status);
    $response = invokeHistoryDeletion($id);
    historyDeletionAssert(Yii::$app->response->statusCode === 409, "Deleting a {$status} job should receive HTTP 409.");
    historyDeletionAssert(
        ($response['error'] ?? null) === 'Stop this query before deleting it from history.',
        "Deleting a {$status} job should return stable guidance."
    );
    historyDeletionAssert(QueryJob::find()->where(['id' => $id])->exists(), "A rejected {$status} job should remain.");
}

$ownerTerminalId = '8f4a4aa0-5001-4222-8333-123456789abc';
$foreignTerminalId = '8f4a4aa0-5002-4222-8333-123456789abc';
historyDeletionJob($ownerTerminalId, 7, 'failed', '2026-07-19 10:03:00');
historyDeletionJob($foreignTerminalId, 8, 'cancelled', '2026-07-19 10:04:00');

$ownerItems = queryHistoryItems();
historyDeletionAssert($ownerItems[$ownerTerminalId]['canDelete'] === true, 'An owner may delete their terminal history row.');
historyDeletionAssert($ownerItems[$foreignTerminalId]['canDelete'] === false, 'A non-owner may not delete another user’s terminal row.');
foreach ($activeStatuses as $index => $status) {
    $id = sprintf('8f4a4aa0-4%03d-4222-8333-123456789abc', $index);
    historyDeletionAssert($ownerItems[$id]['canDelete'] === false, "An owned {$status} row should not be deletable.");
}

$activeItems = queryHistoryItems(['status' => 'active']);
historyDeletionAssert(isset($activeItems['8f4a4aa0-4003-4222-8333-123456789abc']), 'The active history filter should include cancelling jobs.');

historyDeletionIdentity(1, 'admin');
$adminItems = queryHistoryItems();
historyDeletionAssert($adminItems[$ownerTerminalId]['canDelete'] === true, 'An administrator may delete an owner’s terminal row.');
foreach ($activeStatuses as $index => $status) {
    $id = sprintf('8f4a4aa0-4%03d-4222-8333-123456789abc', $index);
    historyDeletionAssert($adminItems[$id]['canDelete'] === false, "An administrator may not delete an active {$status} row.");
}

$failureId = '8f4a4aa0-6001-4222-8333-123456789abc';
historyDeletionJob($failureId, 1, 'completed', '2026-07-19 10:05:00');
Yii::$app->db->open();
Yii::$app->db->pdo->exec(<<<'SQL'
CREATE TRIGGER prevent_history_deletion
BEFORE DELETE ON query_jobs
WHEN OLD.id = '8f4a4aa0-6001-4222-8333-123456789abc'
BEGIN
    SELECT RAISE(FAIL, 'forced deletion failure');
END
SQL);
$failureResponse = invokeHistoryDeletion($failureId);
historyDeletionAssert(Yii::$app->response->statusCode === 500, 'An unexpected deletion failure should receive HTTP 500.');
historyDeletionAssert(
    ($failureResponse['error'] ?? null) === 'Unable to delete this history item right now. Please try again.',
    'An unexpected deletion failure should return stable retry guidance.'
);
historyDeletionAssert(QueryJob::find()->where(['id' => $failureId])->exists(), 'A failed deletion should retain the row.');

fwrite(STDOUT, "Folio query controller history deletion test passed\n");
