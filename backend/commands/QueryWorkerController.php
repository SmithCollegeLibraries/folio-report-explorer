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

            $db = Yii::$app->folioDb;
            $params = $job->getDecodedParams();

            $transaction = $db->beginTransaction();
            try {
                $db->createCommand("SET TRANSACTION READ ONLY")->execute();
                $command = $db->createCommand($job->sql_text);

                foreach ($params as $key => $value) {
                    $command->bindValue($key, $value);
                }

                $rows = $command->queryAll();
                $transaction->commit();

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
     * Log the job result to the query_log table.
     *
     * @param QueryJob $job
     */
    private function logQuery(QueryJob $job)
    {
        try {
            $log = new \app\models\QueryLog();
            $log->sql_text = $job->sql_text;
            $log->params = $job->params;
            $log->source = $job->source;
            $log->row_count = $job->row_count;
            $log->execution_time_ms = $job->execution_time_ms;
            $log->error_message = $job->error_message;
            $log->save(false);
        } catch (\Exception $e) {
            $this->stderr("Failed to log query: {$e->getMessage()}\n");
        }
    }
}
