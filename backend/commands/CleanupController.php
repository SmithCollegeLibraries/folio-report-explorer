<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Data cleanup and retention management.
 *
 * Usage:
 *   php yii cleanup/run               — run all cleanup tasks
 *   php yii cleanup/run --dry-run     — preview what would be deleted
 *   php yii cleanup/jobs              — clean up old query jobs
 *   php yii cleanup/logs              — clean up old query logs
 *   php yii cleanup/stats             — show data size statistics
 */
class CleanupController extends Controller
{
    /**
     * @var bool Preview mode — show what would be deleted without deleting.
     */
    public $dryRun = false;

    /**
     * @var int Days to retain completed job results (default: 90).
     */
    public $jobRetentionDays = 90;

    /**
     * @var int Days to retain query logs (default: 180).
     */
    public $logRetentionDays = 180;

    /**
     * @var int Max result_rows size in bytes to keep (default: 10MB).
     * Jobs with larger results will have result_rows truncated.
     */
    public $maxResultSize = 10485760;

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'dryRun',
            'jobRetentionDays',
            'logRetentionDays',
            'maxResultSize',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'n' => 'dryRun',
        ]);
    }

    /**
     * Run all cleanup tasks.
     */
    public function actionRun()
    {
        $this->stdout("=== Data Cleanup " . ($this->dryRun ? '(DRY RUN) ' : '') . "===\n\n");

        $this->actionJobs();
        $this->stdout("\n");
        $this->actionLogs();
        $this->stdout("\n");
        $this->actionTruncate();
        $this->stdout("\n");
        $this->actionStats();

        return ExitCode::OK;
    }

    /**
     * Delete old completed/failed/cancelled query jobs.
     */
    public function actionJobs()
    {
        $db = Yii::$app->db;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$this->jobRetentionDays} days"));

        $this->stdout("Cleaning query_jobs older than {$cutoff} ({$this->jobRetentionDays} days)...\n");

        // Count what would be deleted
        $count = $db->createCommand(
            'SELECT COUNT(*) FROM query_jobs WHERE status IN (:s1, :s2, :s3) AND completed_at < :cutoff',
            [':s1' => 'completed', ':s2' => 'failed', ':s3' => 'cancelled', ':cutoff' => $cutoff]
        )->queryScalar();

        $this->stdout("  Found {$count} jobs to delete\n");

        if ($count > 0 && !$this->dryRun) {
            $deleted = $db->createCommand(
                'DELETE FROM query_jobs WHERE status IN (:s1, :s2, :s3) AND completed_at < :cutoff',
                [':s1' => 'completed', ':s2' => 'failed', ':s3' => 'cancelled', ':cutoff' => $cutoff]
            )->execute();
            $this->stdout("  Deleted {$deleted} jobs\n");
        }

        return ExitCode::OK;
    }

    /**
     * Delete old query log entries.
     */
    public function actionLogs()
    {
        $db = Yii::$app->db;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$this->logRetentionDays} days"));

        $this->stdout("Cleaning query_log older than {$cutoff} ({$this->logRetentionDays} days)...\n");

        $count = $db->createCommand(
            'SELECT COUNT(*) FROM query_log WHERE created_at < :cutoff',
            [':cutoff' => $cutoff]
        )->queryScalar();

        $this->stdout("  Found {$count} log entries to delete\n");

        if ($count > 0 && !$this->dryRun) {
            $deleted = $db->createCommand(
                'DELETE FROM query_log WHERE created_at < :cutoff',
                [':cutoff' => $cutoff]
            )->execute();
            $this->stdout("  Deleted {$deleted} log entries\n");
        }

        return ExitCode::OK;
    }

    /**
     * Truncate oversized result_rows in completed jobs.
     */
    public function actionTruncate()
    {
        $db = Yii::$app->db;

        $this->stdout("Truncating result_rows larger than " . round($this->maxResultSize / 1048576, 1) . " MB...\n");

        $count = $db->createCommand(
            'SELECT COUNT(*) FROM query_jobs WHERE status = :status AND LENGTH(result_rows) > :maxSize',
            [':status' => 'completed', ':maxSize' => $this->maxResultSize]
        )->queryScalar();

        $this->stdout("  Found {$count} oversized results\n");

        if ($count > 0 && !$this->dryRun) {
            // Replace result_rows with a truncation notice
            $db->createCommand(
                'UPDATE query_jobs SET result_rows = :notice WHERE status = :status AND LENGTH(result_rows) > :maxSize',
                [
                    ':notice' => json_encode(['_truncated' => true, '_message' => 'Results truncated by cleanup process']),
                    ':status' => 'completed',
                    ':maxSize' => $this->maxResultSize,
                ]
            )->execute();
            $this->stdout("  Truncated {$count} result sets\n");
        }

        return ExitCode::OK;
    }

    /**
     * Show data size statistics.
     */
    public function actionStats()
    {
        $db = Yii::$app->db;

        $this->stdout("=== Data Statistics ===\n");

        // Table sizes
        $tables = ['query_jobs', 'query_log', 'saved_queries', 'report_templates', 'ai_training_hints', 'users'];
        foreach ($tables as $table) {
            $count = $db->createCommand("SELECT COUNT(*) FROM {$table}")->queryScalar();
            $this->stdout("  {$table}: {$count} rows\n");
        }

        // Result data size
        $totalResultSize = $db->createCommand(
            'SELECT COALESCE(SUM(LENGTH(result_rows)), 0) FROM query_jobs WHERE status = :status',
            [':status' => 'completed']
        )->queryScalar();

        $sizeMB = round($totalResultSize / 1048576, 2);
        $this->stdout("\n  Total result data: {$sizeMB} MB\n");

        // Oldest data
        $oldestJob = $db->createCommand('SELECT MIN(created_at) FROM query_jobs')->queryScalar();
        $oldestLog = $db->createCommand('SELECT MIN(created_at) FROM query_log')->queryScalar();
        $this->stdout("  Oldest job: " . ($oldestJob ?: 'none') . "\n");
        $this->stdout("  Oldest log: " . ($oldestLog ?: 'none') . "\n");

        return ExitCode::OK;
    }
}
