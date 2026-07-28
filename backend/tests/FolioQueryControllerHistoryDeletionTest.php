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
Yii::$app->db->createCommand('PRAGMA foreign_keys = ON')->execute();

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
CREATE TABLE ai_report_generations (
    id VARCHAR(36) PRIMARY KEY,
    parent_generation_id VARCHAR(36) NULL,
    query_job_id VARCHAR(36) NULL,
    user_id INTEGER NULL,
    original_question TEXT NOT NULL,
    follow_up_context TEXT NULL,
    generated_sql TEXT NULL,
    confidence_evidence_json TEXT NOT NULL
)
SQL)->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE ai_report_reviews (
    id VARCHAR(36) PRIMARY KEY,
    generation_id VARCHAR(36) NOT NULL,
    advisory_state VARCHAR(20) NOT NULL DEFAULT 'none',
    superseded_by_job_id VARCHAR(36) NULL,
    administrator_notes TEXT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (superseded_by_job_id) REFERENCES query_jobs(id) ON DELETE SET NULL
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

function historyDeletionReview(
    $jobId,
    $userId,
    $state,
    $supersededByJobId = null,
    $suffix = null,
    $updatedAt = '2026-07-19 10:00:00',
    $reviewId = null
)
{
    $suffix = $suffix ?: $state;
    $generationId = 'generation-' . $suffix;
    Yii::$app->db->createCommand()->insert('ai_report_generations', [
        'id' => $generationId,
        'query_job_id' => $jobId,
        'user_id' => $userId,
        'original_question' => 'PRIVATE QUESTION ' . $suffix,
        'follow_up_context' => '{"private":"context"}',
        'generated_sql' => 'SELECT PRIVATE_' . $suffix,
        'confidence_evidence_json' => '{"private":"evidence"}',
    ])->execute();
    Yii::$app->db->createCommand()->insert('ai_report_reviews', [
        'id' => $reviewId ?: 'review-' . $suffix,
        'generation_id' => $generationId,
        'advisory_state' => $state,
        'superseded_by_job_id' => $supersededByJobId,
        'administrator_notes' => 'PRIVATE NOTES ' . $suffix,
        'updated_at' => $updatedAt,
    ])->execute();
    return $generationId;
}

function historyDeletionExecutionChild($parentGenerationId, $jobId, $userId, $suffix)
{
    $generationId = 'execution-' . $suffix;
    Yii::$app->db->createCommand()->insert('ai_report_generations', [
        'id' => $generationId,
        'parent_generation_id' => $parentGenerationId,
        'query_job_id' => $jobId,
        'user_id' => $userId,
        'original_question' => 'PRIVATE EXECUTION QUESTION ' . $suffix,
        'follow_up_context' => null,
        'generated_sql' => 'SELECT 1',
        'confidence_evidence_json' => '{}',
    ])->execute();
    return $generationId;
}

function invokeHistoryDeletion($id)
{
    Yii::$app->response->statusCode = 200;
    $controller = new FolioQueryController('folio-query', Yii::$app);
    return $controller->actionDeleteHistoryJob($id);
}

function queryHistoryResponse(array $queryParams = [])
{
    Yii::$app->request->setQueryParams($queryParams);
    $controller = new FolioQueryController('folio-query', Yii::$app);
    return $controller->actionQueryHistory();
}

function queryHistoryItems(array $queryParams = [])
{
    $response = queryHistoryResponse($queryParams);
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

$cautionedId = '8f4a4aa0-5101-4222-8333-123456789abc';
$supersededId = '8f4a4aa0-5102-4222-8333-123456789abc';
$replacementId = '8f4a4aa0-5103-4222-8333-123456789abc';
historyDeletionJob($cautionedId, 7, 'completed', '2026-07-19 10:05:00');
historyDeletionJob($supersededId, 7, 'completed', '2026-07-19 10:06:00');
historyDeletionJob($replacementId, 7, 'completed', '2026-07-19 10:07:00');
historyDeletionReview($cautionedId, 7, 'cautioned');
historyDeletionReview($supersededId, 7, 'superseded', $replacementId);
$advisoryItems = queryHistoryItems();
historyDeletionAssert($advisoryItems[$cautionedId]['status'] === 'completed', 'A cautioned result must keep completed execution status.');
historyDeletionAssert($advisoryItems[$cautionedId]['reviewAdvisory'] === [
    'state' => 'cautioned',
    'message' => 'A reporting specialist identified an important limitation in this result.',
], 'Cautioned history must expose only stable user-safe advisory copy.');
historyDeletionAssert($advisoryItems[$supersededId]['status'] === 'completed', 'A superseded result must keep completed execution status.');
historyDeletionAssert($advisoryItems[$supersededId]['reviewAdvisory'] === [
    'state' => 'superseded',
    'message' => 'A corrected version of this report is available.',
    'supersededByJobId' => $replacementId,
], 'Superseded history must expose only stable user-safe advisory copy and replacement id.');
$ordinaryHistoryPayload = json_encode([$advisoryItems[$cautionedId], $advisoryItems[$supersededId]]);
foreach (['PRIVATE QUESTION', 'PRIVATE_', 'private', 'PRIVATE NOTES', 'administrator_notes', 'confidence_evidence'] as $secret) {
    historyDeletionAssert(strpos($ordinaryHistoryPayload, $secret) === false, 'Ordinary history must not expose review notes or generation evidence.');
}

$exactFirstId = '8f4a4aa0-5111-4222-8333-123456789abc';
$exactSecondId = '8f4a4aa0-5112-4222-8333-123456789abc';
historyDeletionJob($exactFirstId, 7, 'completed', '2026-07-19 10:08:00');
historyDeletionJob($exactSecondId, 7, 'completed', '2026-07-19 10:09:00');
$exactSourceGeneration = historyDeletionReview(null, 7, 'cautioned', null, 'exact-source');
$exactFirstGeneration = historyDeletionExecutionChild($exactSourceGeneration, $exactFirstId, 7, 'exact-first');
$exactSecondGeneration = historyDeletionExecutionChild($exactSourceGeneration, $exactSecondId, 7, 'exact-second');
$exactAdvisoryItems = queryHistoryItems();
foreach ([$exactFirstId, $exactSecondId] as $exactJobId) {
    historyDeletionAssert(($exactAdvisoryItems[$exactJobId]['reviewAdvisory'] ?? null) === [
        'state' => 'cautioned',
        'message' => 'A reporting specialist identified an important limitation in this result.',
    ], 'Every exact rerun must inherit the reviewed source advisory.');
}
$deleteNewestExact = invokeHistoryDeletion($exactSecondId);
historyDeletionAssert(($deleteNewestExact['success'] ?? false) === true, 'The newest exact rerun should be deletable.');
historyDeletionAssert(
    (int)Yii::$app->db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => $exactSecondGeneration])->queryScalar() === 0,
    'Deleting one exact rerun must remove only its execution child.'
);
historyDeletionAssert(
    (int)Yii::$app->db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => $exactSourceGeneration])->queryScalar() === 1,
    'Deleting the newest exact rerun must retain the shared reviewed source while an older rerun remains.'
);
historyDeletionAssert(
    queryHistoryItems()[$exactFirstId]['reviewAdvisory']['state'] === 'cautioned',
    'The older exact rerun must retain its advisory after the newest rerun is deleted.'
);
$deleteLastExact = invokeHistoryDeletion($exactFirstId);
historyDeletionAssert(($deleteLastExact['success'] ?? false) === true, 'The last exact rerun should be deletable.');
historyDeletionAssert(
    (int)Yii::$app->db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id IN (:source, :child)', [
        ':source' => $exactSourceGeneration,
        ':child' => $exactFirstGeneration,
    ])->queryScalar() === 0,
    'Deleting the last exact rerun must clean up its child and now-unreferenced source generation.'
);
historyDeletionAssert(
    (int)Yii::$app->db->createCommand('SELECT COUNT(*) FROM ai_report_reviews WHERE generation_id = :id', [':id' => $exactSourceGeneration])->queryScalar() === 0,
    'Deleting the last exact rerun must clean up the shared review.'
);

$missingReplacementId = '8f4a4aa0-5301-4222-8333-123456789abc';
$deletedReplacementId = '8f4a4aa0-5302-4222-8333-123456789abc';
historyDeletionJob($missingReplacementId, 7, 'completed', '2026-07-19 08:00:00');
historyDeletionJob($deletedReplacementId, 7, 'completed', '2026-07-19 08:01:00');
historyDeletionReview($missingReplacementId, 7, 'superseded', $deletedReplacementId, 'missing-replacement');
$deletedReplacementResponse = invokeHistoryDeletion($deletedReplacementId);
historyDeletionAssert(($deletedReplacementResponse['success'] ?? false) === true, 'The superseding replacement fixture should be deleted.');
$missingReplacementAdvisory = queryHistoryItems()[$missingReplacementId]['reviewAdvisory'];
historyDeletionAssert($missingReplacementAdvisory === [
    'state' => 'superseded',
    'message' => 'A corrected version of this report was created, but it is no longer available in your history.',
], 'Superseded history must not claim that a deleted replacement remains available.');

$jobCountBeforeMultipleReviews = queryHistoryResponse()['total'];
$multiReviewId = '8f4a4aa0-5201-4222-8333-123456789abc';
$multiReplacementId = '8f4a4aa0-5202-4222-8333-123456789abc';
historyDeletionJob($multiReviewId, 7, 'completed', '2026-07-20 12:00:00');
historyDeletionJob($multiReplacementId, 7, 'completed', '2026-07-19 09:00:00');
historyDeletionReview($multiReviewId, 7, 'cautioned', null, 'multi-old', '2026-07-19 11:00:00', 'review-multi-old');
historyDeletionReview($multiReviewId, 7, 'cautioned', null, 'multi-tie-a', '2026-07-20 11:00:00', 'review-multi-a');
historyDeletionReview($multiReviewId, 7, 'superseded', $multiReplacementId, 'multi-tie-z', '2026-07-20 11:00:00', 'review-multi-z');
$multipleReviewResponse = queryHistoryResponse();
$multiReviewItems = array_values(array_filter($multipleReviewResponse['items'], static function ($item) use ($multiReviewId) {
    return $item['jobId'] === $multiReviewId;
}));
historyDeletionAssert($multipleReviewResponse['total'] === $jobCountBeforeMultipleReviews + 2, 'History total must count query jobs rather than linked reviews.');
historyDeletionAssert(count($multiReviewItems) === 1, 'History must return one item for a job with multiple linked reviews.');
historyDeletionAssert($multiReviewItems[0]['reviewAdvisory'] === [
    'state' => 'superseded',
    'message' => 'A corrected version of this report is available.',
    'supersededByJobId' => $multiReplacementId,
], 'History must deterministically choose the latest advisory and break updated-at ties by stable descending review id.');
$firstJobPage = queryHistoryResponse(['limit' => 1, 'offset' => 0]);
$secondJobPage = queryHistoryResponse(['limit' => 1, 'offset' => 1]);
historyDeletionAssert($firstJobPage['items'][0]['jobId'] === $multiReviewId, 'The newest job should occupy the first one-item history page.');
historyDeletionAssert($secondJobPage['items'][0]['jobId'] !== $multiReviewId, 'A duplicated review must not make the same job occupy the next history page.');

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

historyDeletionIdentity(1, 'admin');
$purgedGeneration = historyDeletionReview(null, 8, 'cautioned', null, 'user-purge');
$controller = new FolioQueryController('folio-query', Yii::$app);
$userDeleteResponse = $controller->actionUserDelete(8);
historyDeletionAssert(($userDeleteResponse['success'] ?? false) === true, 'An administrator should delete another user.');
historyDeletionAssert(User::findOne(8) === null, 'User deletion should remove the account.');
historyDeletionAssert(
    (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => $purgedGeneration])->queryScalar() === 0,
    'User deletion must purge raw questions, SQL, and follow-up context before removing the account.'
);
historyDeletionAssert(
    (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM ai_report_reviews WHERE generation_id = :id', [':id' => $purgedGeneration])->queryScalar() === 0,
    'User deletion must purge administrator notes before removing the account.'
);

fwrite(STDOUT, "Folio query controller history deletion test passed\n");
