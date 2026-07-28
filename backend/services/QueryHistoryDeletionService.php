<?php

namespace app\services;

use app\models\QueryJob;
use DomainException;
use RuntimeException;
use Yii;

/**
 * Deletes terminal query history while restricting export cleanup to owned CSVs.
 */
class QueryHistoryDeletionService
{
    /** @var string */
    private $exportDirectory;

    /** @var callable */
    private $fileDeleter;

    /** @var callable */
    private $warningLogger;

    public function __construct(string $exportDirectory, ?callable $fileDeleter = null, ?callable $warningLogger = null)
    {
        $this->exportDirectory = $exportDirectory;
        $this->fileDeleter = $fileDeleter ?: static function ($path) {
            return @unlink($path);
        };
        $this->warningLogger = $warningLogger ?: static function ($message): void {
            Yii::warning($message, __CLASS__);
        };
    }

    /**
     * Delete a terminal query job and its valid in-scope export, if present.
     *
     * @throws DomainException when the job is active
     * @throws RuntimeException when a valid export or database row cannot be deleted
     */
    public function delete(QueryJob $job): void
    {
        $job->refresh();
        if (!in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
            throw new DomainException('Stop this query before deleting it from history.');
        }

        $db = QueryJob::getDb();
        $db->transaction(function () use ($db, $job): void {
            $generationRows = $db->createCommand(
                'SELECT id, parent_generation_id
                 FROM ai_report_generations
                 WHERE query_job_id = :jobId',
                [':jobId' => $job->id]
            )->queryAll();
            $generationIds = [];
            $parentGenerationIds = [];
            foreach ($generationRows as $generationRow) {
                $generationId = trim((string)($generationRow['id'] ?? ''));
                if ($generationId !== '') {
                    $generationIds[] = $generationId;
                }
                $parentGenerationId = trim((string)(
                    $generationRow['parent_generation_id'] ?? ''
                ));
                if ($parentGenerationId !== '') {
                    $parentGenerationIds[] = $parentGenerationId;
                }
            }
            $generationIds = array_values(array_unique($generationIds));
            $parentGenerationIds = array_values(array_unique($parentGenerationIds));

            if ($generationIds !== []) {
                $db->createCommand()->delete('ai_report_reviews', ['generation_id' => $generationIds])->execute();
                $db->createCommand()->delete('ai_report_generations', ['id' => $generationIds])->execute();
            }
            foreach ($parentGenerationIds as $parentGenerationId) {
                if (!in_array($parentGenerationId, $generationIds, true)) {
                    $this->deleteOrphanedSourceGeneration($db, $parentGenerationId);
                }
            }

            $this->removeExport($job);
            if (!$job->delete()) {
                throw new RuntimeException('History row could not be deleted.');
            }
        });
    }

    private function deleteOrphanedSourceGeneration($db, string $generationId): void
    {
        $source = $db->createCommand(
            'SELECT query_job_id
             FROM ai_report_generations
             WHERE id = :generationId',
            [':generationId' => $generationId]
        )->queryOne();
        if ($source === false || $source['query_job_id'] !== null) {
            return;
        }

        $childCount = (int)$db->createCommand(
            'SELECT COUNT(*)
             FROM ai_report_generations
             WHERE parent_generation_id = :generationId',
            [':generationId' => $generationId]
        )->queryScalar();
        if ($childCount !== 0) {
            return;
        }

        $db->createCommand()->delete(
            'ai_report_reviews',
            ['generation_id' => $generationId]
        )->execute();
        $db->createCommand()->delete(
            'ai_report_generations',
            ['id' => $generationId]
        )->execute();
    }

    private function removeExport(QueryJob $job): void
    {
        $path = (string) $job->export_file_path;
        if ($path === '') {
            return;
        }

        $exportDirectory = realpath($this->exportDirectory);
        if ($exportDirectory === false) {
            $this->warnUnsafeExport($job, $path);
            return;
        }

        if (!file_exists($path) && !is_link($path)) {
            $this->warnUnsafeExport($job, $path);
            return;
        }

        $expectedPath = $exportDirectory . DIRECTORY_SEPARATOR . $job->id . '.csv';
        $isSafe = !is_link($path)
            && is_file($path)
            && realpath(dirname($path)) === $exportDirectory
            && basename($path) === $job->id . '.csv'
            && realpath($path) === $expectedPath;

        if (!$isSafe) {
            $this->warnUnsafeExport($job, $path);
            return;
        }

        if (!call_user_func($this->fileDeleter, $path)) {
            throw new RuntimeException('Export file could not be deleted.');
        }
    }

    private function warnUnsafeExport(QueryJob $job, string $path): void
    {
        call_user_func(
            $this->warningLogger,
            "Skipped unsafe export cleanup for query job '{$job->id}': {$path}"
        );
    }
}
