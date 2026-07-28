<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\models\QueryJob;
use app\services\QueryHistoryDeletionService;
use yii\console\Application;

new Application([
    'id' => 'query-history-deletion-service-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
]);

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    sql_text TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    export_file_path VARCHAR(500) NULL
)
SQL)->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE ai_report_generations (
    id VARCHAR(36) PRIMARY KEY,
    parent_generation_id VARCHAR(36) NULL,
    query_job_id VARCHAR(36) NULL,
    original_question TEXT NOT NULL,
    generated_sql TEXT NULL
)
SQL)->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE ai_report_reviews (
    id VARCHAR(36) PRIMARY KEY,
    generation_id VARCHAR(36) NOT NULL,
    administrator_notes TEXT NULL
)
SQL)->execute();

function deletionAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function insertDeletionJob($id, $status, $exportPath = null)
{
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => 'SELECT 1',
        'status' => $status,
        'export_file_path' => $exportPath,
    ])->execute();

    return QueryJob::findOne($id);
}

function deletionRowExists($id)
{
    return QueryJob::find()->where(['id' => $id])->exists();
}

function insertLinkedDeletionReview($jobId, $suffix)
{
    $generationId = 'generation-' . $suffix;
    Yii::$app->db->createCommand()->insert('ai_report_generations', [
        'id' => $generationId,
        'query_job_id' => $jobId,
        'original_question' => 'Sensitive question ' . $suffix,
        'generated_sql' => 'SELECT sensitive_' . $suffix,
    ])->execute();
    Yii::$app->db->createCommand()->insert('ai_report_reviews', [
        'id' => 'review-' . $suffix,
        'generation_id' => $generationId,
        'administrator_notes' => 'Sensitive notes ' . $suffix,
    ])->execute();

    return $generationId;
}

function insertSharedDeletionExecution($sourceGenerationId, $jobId, $suffix)
{
    $generationId = 'execution-' . $suffix;
    Yii::$app->db->createCommand()->insert('ai_report_generations', [
        'id' => $generationId,
        'parent_generation_id' => $sourceGenerationId,
        'query_job_id' => $jobId,
        'original_question' => 'Execution question ' . $suffix,
        'generated_sql' => 'SELECT 1',
    ])->execute();
    return $generationId;
}

function linkedDeletionRowsExist($generationId)
{
    $generationExists = (int) Yii::$app->db->createCommand(
        'SELECT COUNT(*) FROM ai_report_generations WHERE id = :id',
        [':id' => $generationId]
    )->queryScalar() > 0;
    $reviewExists = (int) Yii::$app->db->createCommand(
        'SELECT COUNT(*) FROM ai_report_reviews WHERE generation_id = :id',
        [':id' => $generationId]
    )->queryScalar() > 0;
    return $generationExists || $reviewExists;
}

function expectDeletionException($class, callable $callback, $message)
{
    try {
        $callback();
    } catch (Throwable $exception) {
        deletionAssert(
            $exception instanceof $class,
            $message . ' Expected ' . $class . ', got ' . get_class($exception) . '.'
        );
        return $exception;
    }

    deletionAssert(false, $message . ' Expected ' . $class . '.');
}

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'query-history-deletion-' . bin2hex(random_bytes(8));
$exportDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'exports';
$externalDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'external';

deletionAssert(mkdir($exportDirectory, 0700, true), 'The temporary export directory should be created.');
deletionAssert(mkdir($externalDirectory, 0700, true), 'The temporary external directory should be created.');

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

$warnings = [];
$service = new QueryHistoryDeletionService(
    $exportDirectory,
    null,
    static function ($message) use (&$warnings): void {
        $warnings[] = $message;
    }
);

foreach (['completed', 'failed', 'cancelled'] as $terminalStatus) {
    $jobId = $terminalStatus . '-job';
    $service->delete(insertDeletionJob($jobId, $terminalStatus));
    deletionAssert(!deletionRowExists($jobId), "A {$terminalStatus} job should be deleted.");
}

$linkedJob = insertDeletionJob('linked-job', 'completed');
$linkedGeneration = insertLinkedDeletionReview('linked-job', 'single');
$service->delete($linkedJob);
deletionAssert(!linkedDeletionRowsExist($linkedGeneration), 'Single deletion must remove linked review and generation rows.');

$sharedSource = insertLinkedDeletionReview(null, 'shared-source');
$sharedFirstJob = insertDeletionJob('shared-first-job', 'completed');
$sharedSecondJob = insertDeletionJob('shared-second-job', 'completed');
$sharedFirstExecution = insertSharedDeletionExecution($sharedSource, 'shared-first-job', 'shared-first');
$sharedSecondExecution = insertSharedDeletionExecution($sharedSource, 'shared-second-job', 'shared-second');
$service->delete($sharedSecondJob);
deletionAssert(
    !linkedDeletionRowsExist($sharedSecondExecution),
    'Deleting one shared execution must remove its execution child.'
);
deletionAssert(
    linkedDeletionRowsExist($sharedSource) && linkedDeletionRowsExist($sharedFirstExecution),
    'Deleting one shared execution must retain the reviewed source and sibling execution.'
);
$service->delete($sharedFirstJob);
deletionAssert(
    !linkedDeletionRowsExist($sharedFirstExecution) && !linkedDeletionRowsExist($sharedSource),
    'Deleting the last shared execution must remove its child, source generation, and review.'
);

$batchGenerations = [];
foreach (['batch-a', 'batch-b'] as $batchJobId) {
    $batchGenerations[] = insertLinkedDeletionReview($batchJobId, $batchJobId);
    $service->delete(insertDeletionJob($batchJobId, 'completed'));
}
foreach ($batchGenerations as $batchGeneration) {
    deletionAssert(!linkedDeletionRowsExist($batchGeneration), 'Batch deletion calls must remove every linked review and generation row.');
}

foreach (['pending', 'running', 'cancelling', 'pending_export'] as $activeStatus) {
    $jobId = $activeStatus . '-job';
    $job = insertDeletionJob($jobId, $activeStatus);
    $exception = expectDeletionException(
        DomainException::class,
        static function () use ($service, $job): void {
            $service->delete($job);
        },
        "An active {$activeStatus} job should be rejected."
    );
    deletionAssert(
        $exception->getMessage() === 'Stop this query before deleting it from history.',
        'Active deletion should use the stable user-facing message.'
    );
    deletionAssert(deletionRowExists($jobId), "A rejected {$activeStatus} job should remain.");
}

$validExportId = 'valid-export';
$validExportPath = $exportDirectory . DIRECTORY_SEPARATOR . $validExportId . '.csv';
deletionAssert(file_put_contents($validExportPath, "id\n1\n") !== false, 'The valid export fixture should be created.');
$service->delete(insertDeletionJob($validExportId, 'completed', $validExportPath));
deletionAssert(!file_exists($validExportPath), 'A valid in-scope export should be removed.');
deletionAssert(!deletionRowExists($validExportId), 'A job with a removed valid export should be deleted.');

$unsafeCases = [];

$externalId = 'external-export';
$externalPath = $externalDirectory . DIRECTORY_SEPARATOR . $externalId . '.csv';
file_put_contents($externalPath, 'external');
$unsafeCases[] = [$externalId, $externalPath, 'external file'];

$traversalId = 'traversal-export';
$traversalPath = $exportDirectory . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'external' . DIRECTORY_SEPARATOR . $traversalId . '.csv';
file_put_contents($externalDirectory . DIRECTORY_SEPARATOR . $traversalId . '.csv', 'traversal');
$unsafeCases[] = [$traversalId, $traversalPath, 'traversal path'];

$directoryId = 'directory-export';
$directoryPath = $exportDirectory . DIRECTORY_SEPARATOR . $directoryId . '.csv';
mkdir($directoryPath);
$unsafeCases[] = [$directoryId, $directoryPath, 'directory'];

$symlinkId = 'symlink-export';
$symlinkTarget = $externalDirectory . DIRECTORY_SEPARATOR . 'symlink-target.csv';
$symlinkPath = $exportDirectory . DIRECTORY_SEPARATOR . $symlinkId . '.csv';
file_put_contents($symlinkTarget, 'symlink target');
deletionAssert(symlink($symlinkTarget, $symlinkPath), 'The symlink fixture should be created.');
$unsafeCases[] = [$symlinkId, $symlinkPath, 'symlink'];

foreach ($unsafeCases as [$jobId, $path, $label]) {
    $warningCount = count($warnings);
    $service->delete(insertDeletionJob($jobId, 'completed', $path));
    deletionAssert(file_exists($path) || is_link($path), "The unsafe {$label} should remain untouched.");
    deletionAssert(!deletionRowExists($jobId), "The row referencing an unsafe {$label} should still be deleted.");
    deletionAssert(count($warnings) === $warningCount + 1, "The unsafe {$label} should record one warning.");
}

$missingExportId = 'missing-export';
$missingExportPath = $exportDirectory . DIRECTORY_SEPARATOR . $missingExportId . '.csv';
$warningCount = count($warnings);
$service->delete(insertDeletionJob($missingExportId, 'completed', $missingExportPath));
deletionAssert(!deletionRowExists($missingExportId), 'A missing export reference should not block row deletion.');
deletionAssert(count($warnings) === $warningCount + 1, 'An uncanonicalizable export path should record one warning.');

$missingDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'missing-exports';
$missingDirectoryService = new QueryHistoryDeletionService($missingDirectory);
$missingDirectoryService->delete(insertDeletionJob('no-export-missing-dir', 'completed'));
deletionAssert(
    !deletionRowExists('no-export-missing-dir'),
    'A missing export directory must not block deletion when the job has no export reference.'
);

$uncanonicalWarnings = [];
$uncanonicalService = new QueryHistoryDeletionService(
    $missingDirectory,
    null,
    static function ($message) use (&$uncanonicalWarnings): void {
        $uncanonicalWarnings[] = $message;
    }
);
$uncanonicalPath = $missingDirectory . DIRECTORY_SEPARATOR . 'uncanonical-export.csv';
$uncanonicalService->delete(insertDeletionJob('uncanonical-export', 'completed', $uncanonicalPath));
deletionAssert(!deletionRowExists('uncanonical-export'), 'An uncanonicalizable export reference should not block row deletion.');
deletionAssert(count($uncanonicalWarnings) === 1, 'An uncanonicalizable export reference should record one warning.');

$failedDeleteId = 'failed-file-delete';
$failedDeletePath = $exportDirectory . DIRECTORY_SEPARATOR . $failedDeleteId . '.csv';
file_put_contents($failedDeletePath, 'must remain');
$failedDeleteService = new QueryHistoryDeletionService(
    $exportDirectory,
    static function ($path): bool {
        return false;
    }
);
$failedDeleteJob = insertDeletionJob($failedDeleteId, 'completed', $failedDeletePath);
expectDeletionException(
    RuntimeException::class,
    static function () use ($failedDeleteService, $failedDeleteJob): void {
        $failedDeleteService->delete($failedDeleteJob);
    },
    'A valid export deletion failure should be reported.'
);
deletionAssert(file_exists($failedDeletePath), 'A failed export deletion should leave the file in place.');
deletionAssert(deletionRowExists($failedDeleteId), 'A failed export deletion should retain the history row.');

$rollbackJob = insertDeletionJob('rollback-job', 'completed');
$rollbackGeneration = insertLinkedDeletionReview('rollback-job', 'rollback');
Yii::$app->db->open();
Yii::$app->db->pdo->exec(<<<'SQL'
CREATE TRIGGER fail_linked_job_delete
BEFORE DELETE ON query_jobs
WHEN OLD.id = 'rollback-job'
BEGIN
    SELECT RAISE(FAIL, 'forced linked job deletion failure');
END
SQL);
expectDeletionException(
    Throwable::class,
    static function () use ($service, $rollbackJob): void {
        $service->delete($rollbackJob);
    },
    'A job deletion failure should escape the transaction.'
);
deletionAssert(deletionRowExists('rollback-job'), 'A failed transaction should retain the history row.');
deletionAssert(linkedDeletionRowsExist($rollbackGeneration), 'A failed transaction must restore linked generation and review rows.');

$missingRowJob = insertDeletionJob('missing-row', 'completed');
Yii::$app->db->createCommand()->delete('query_jobs', ['id' => 'missing-row'])->execute();
expectDeletionException(
    RuntimeException::class,
    static function () use ($service, $missingRowJob): void {
        $service->delete($missingRowJob);
    },
    'A database deletion failure should be reported.'
);

fwrite(STDOUT, "Query history deletion service test passed\n");
