<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\QueryJob;

/**
 * QueryWorkerController — background worker that picks up pending query jobs
 * and executes them against the FOLIO Postgres database.
 *
 * Usage: php yii query-worker/run
 */
class QueryWorkerController extends Controller
{
    /**
     * @var int Polling interval in seconds when no jobs are pending.
     */
    public $sleep = 2;

    /**
     * Run the query worker loop.
     * Continuously polls for pending jobs and executes them.
     * Used in Docker (persistent container).
     */
    public function actionRun()
    {
        $this->stdout("Query worker started. Polling every {$this->sleep}s...\n");

        while (true) {
            try {
                $job = $this->claimNextJob();
                if ($job) {
                    $this->executeJob($job);
                } else {
                    sleep($this->sleep);
                }
            } catch (\Exception $e) {
                $this->stderr("Worker error: {$e->getMessage()}\n");
                sleep($this->sleep);
            }
        }
    }

    /**
     * Process all pending jobs then exit.
     * Designed for cron: * * * * * php yii query-worker/process-once
     */
    public function actionProcessOnce()
    {
        $processed = 0;
        while (true) {
            try {
                $job = $this->claimNextJob();
                if (!$job) {
                    break;
                }
                $this->executeJob($job);
                $processed++;
            } catch (\Exception $e) {
                $this->stderr("Worker error: {$e->getMessage()}\n");
                break;
            }
        }
        if ($processed > 0) {
            $this->stdout("Processed {$processed} job(s).\n");
        }
        return 0;
    }

    /**
     * Atomically claim the next pending job.
     * Uses UPDATE ... WHERE to prevent double-claiming.
     *
     * @return QueryJob|null
     */
    private function claimNextJob()
    {
        // Find oldest pending job
        $job = QueryJob::find()
            ->where(['status' => 'pending'])
            ->orderBy(['created_at' => SORT_ASC])
            ->one();

        if (!$job) {
            return null;
        }

        // Atomically claim it
        $affected = Yii::$app->db->createCommand()
            ->update('query_jobs', [
                'status' => 'running',
                'started_at' => date('Y-m-d H:i:s'),
                'progress_message' => 'Executing query…',
            ], [
                'id' => $job->id,
                'status' => 'pending',  // Only if still pending
            ])
            ->execute();

        if ($affected === 0) {
            return null; // Another worker got it
        }

        // Refresh from DB
        $job->refresh();
        return $job;
    }

    /**
     * Execute a query job against the FOLIO Postgres database.
     *
     * @param QueryJob $job
     */
    private function executeJob(QueryJob $job)
    {
        $this->stdout("Executing job {$job->id}: " . substr($job->sql_text, 0, 80) . "...\n");
        $startTime = microtime(true);

        try {
            // Check if job was cancelled while we were claiming it
            $job->refresh();
            if ($job->status === 'cancelled') {
                $this->stdout("Job {$job->id} was cancelled, skipping.\n");
                return;
            }

            $dataSource = $job->hasAttribute('data_source') ? strtolower((string) ($job->data_source ?: 'folio')) : 'folio';
            if (!in_array($dataSource, ['folio', 'local', 'composite'])) {
                $dataSource = 'folio';
            }

            // --- Composite report: run primary against FOLIO, secondary against MySQL, merge ---
            if ($dataSource === 'composite') {
                $this->executeCompositeJob($job, $startTime);
                return;
            }

            $db = $dataSource === 'local' ? Yii::$app->db : Yii::$app->folioDb;
            $params = $job->getDecodedParams();

            $transaction = $db->beginTransaction();
            try {
                if ($dataSource === 'folio') {
                    $db->createCommand("SET TRANSACTION READ ONLY")->execute();
                    // Store Postgres backend PID so cancel endpoint can issue pg_cancel_backend()
                    if ($job->hasAttribute('pg_backend_pid')) {
                        $pidRow = $db->createCommand('SELECT pg_backend_pid()')->queryOne();
                        if ($pidRow !== false) {
                            Yii::$app->db->createCommand()->update(
                                'query_jobs',
                                ['pg_backend_pid' => (int)$pidRow['pg_backend_pid']],
                                ['id' => $job->id]
                            )->execute();
                        }
                    }
                }
                $command = $db->createCommand($job->sql_text);

                foreach ($params as $key => $value) {
                    $command->bindValue($key, $value);
                }

                $rows = $command->queryAll();
                $transaction->commit();

                // Check if the job was cancelled while the query was running
                $job->refresh();
                if ($job->status === 'cancelled') {
                    $this->stdout("Job {$job->id} was cancelled during execution, discarding results.\n");
                    return;
                }

                $executionTime = round((microtime(true) - $startTime) * 1000);
                $columns = !empty($rows) ? array_keys($rows[0]) : [];

                $job->markCompleted($columns, $rows, $executionTime);
                $this->stdout("Job {$job->id} completed: {$job->row_count} rows in {$executionTime}ms\n");

                // Also log to query_log for history
                $this->logQuery($job);

            } catch (\Exception $e) {
                $transaction->rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000);
            $job->markFailed($e->getMessage(), $executionTime);
            $this->stderr("Job {$job->id} failed: {$e->getMessage()}\n");

            // Log the failure too
            $this->logQuery($job);
        }
    }

    /**
     * Execute a composite report job:
     * 1. Run primary SQL against FOLIO (Postgres)
     * 2. Run secondary SQL against local MySQL
     * 3. Merge the two result sets by the configured key column
     *
     * @param QueryJob $job
     * @param float $startTime microtime(true) when execution began
     */
    private function executeCompositeJob(QueryJob $job, $startTime)
    {
        $this->stdout("Executing COMPOSITE job {$job->id}\n");
        try {
            $job->refresh();
            if ($job->status === 'cancelled') {
                $this->stdout("Job {$job->id} was cancelled, skipping.\n");
                return;
            }

            // Decode metadata carrying composite_config + bound params
            $rawMeta = $job->hasAttribute('metadata') ? $job->metadata : null;
            $meta = $rawMeta ? json_decode($rawMeta, true) : [];
            $config = $meta['composite_config'] ?? [];
            $secondarySql = $config['secondary_sql'] ?? null;
            $mergeKeyPrimary = $config['merge_key']['primary'] ?? null;   // column alias in FOLIO result
            $mergeKeySecondary = $config['merge_key']['secondary'] ?? null; // column name in MySQL result
            $appendColumns = $config['append_columns'] ?? [];

            if (!$secondarySql || !$mergeKeyPrimary || !$mergeKeySecondary) {
                throw new \InvalidArgumentException('composite_config missing secondary_sql or merge_key');
            }

            $primaryParams = $job->getDecodedParams();

            // --- Run primary (FOLIO Postgres) ---
            $folio = Yii::$app->folioDb;
            $transaction = $folio->beginTransaction();
            try {
                $folio->createCommand("SET TRANSACTION READ ONLY")->execute();
                $cmd = $folio->createCommand($job->sql_text);
                foreach ($primaryParams as $key => $value) {
                    $cmd->bindValue($key, $value);
                }
                $primaryRows = $cmd->queryAll();
                $transaction->commit();
            } catch (\Exception $e) {
                $transaction->rollBack();
                throw $e;
            }

            // --- Run secondary (local MySQL) ---
            // Secondary SQL may use the same :params as the primary (e.g. :fiscalYear)
            $mysql = Yii::$app->db;
            $cmd2 = $mysql->createCommand($secondarySql);
            foreach ($primaryParams as $key => $value) {
                // Bind only params that appear in the secondary SQL
                if (strpos($secondarySql, $key) !== false) {
                    $cmd2->bindValue($key, $value);
                }
            }
            $secondaryRows = $cmd2->queryAll();

            // Index secondary rows by merge key for O(1) lookup
            $secondaryIndex = [];
            foreach ($secondaryRows as $row) {
                $keyVal = $row[$mergeKeySecondary] ?? null;
                if ($keyVal !== null) {
                    $secondaryIndex[$keyVal] = $row;
                }
            }

            // --- Merge: append secondary columns to primary rows ---
            $mergedRows = [];
            foreach ($primaryRows as $primary) {
                $keyVal = $primary[$mergeKeyPrimary] ?? null;
                $secondary = ($keyVal !== null && isset($secondaryIndex[$keyVal]))
                    ? $secondaryIndex[$keyVal]
                    : [];

                $merged = $primary;
                foreach ($appendColumns as $colExpr) {
                    // colExpr is like "allocation_amount AS Allocation"
                    if (preg_match('/^(\w+)\s+AS\s+(.+)$/i', trim($colExpr), $m)) {
                        $srcCol = $m[1];
                        $destCol = trim($m[2]);
                    } else {
                        $srcCol = $colExpr;
                        $destCol = $colExpr;
                    }
                    $merged[$destCol] = $secondary[$srcCol] ?? null;
                }
                $mergedRows[] = $merged;
            }

            $executionTime = round((microtime(true) - $startTime) * 1000);
            $columns = !empty($mergedRows) ? array_keys($mergedRows[0]) : [];

            $job->markCompleted($columns, $mergedRows, $executionTime);
            $this->stdout("Composite job {$job->id} completed: " . count($mergedRows) . " rows in {$executionTime}ms\n");
            $this->logQuery($job);

        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000);
            $job->markFailed($e->getMessage(), $executionTime);
            $this->stderr("Composite job {$job->id} failed: {$e->getMessage()}\n");
            $this->logQuery($job);
        }
    }

    /**
     * Log the job result to the query_log table.
     *
     * @param QueryJob $job
     */
    private function logQuery(QueryJob $job)
    {
        try {
            $log = new \app\models\QueryLog();
            if ($log->hasAttribute('user_id')) {
                $log->user_id = $job->user_id;
            }
            $log->sql_text = $job->sql_text;
            $log->params = $job->params;
            $log->source = $job->source;
            if ($log->hasAttribute('data_source')) {
                $log->data_source = $job->hasAttribute('data_source') ? ($job->data_source ?: 'folio') : 'folio';
            }
            $log->row_count = $job->row_count;
            $log->execution_time_ms = $job->execution_time_ms;
            $log->error_message = $job->error_message;
            $log->save(false);
        } catch (\Exception $e) {
            $this->stderr("Failed to log query: {$e->getMessage()}\n");
        }
    }
}
