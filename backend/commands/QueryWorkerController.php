<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\QueryJob;
use app\services\DatabaseRetryService;

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
        $this->stdout("Query worker started. Polling every {$this->sleep}s. FOLIO concurrency limit: " . $this->maxConcurrentFolioJobs() . "...\n");

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
     * Maximum number of concurrent jobs allowed to use the FOLIO Postgres connection.
     *
     * @return int
     */
    private function maxConcurrentFolioJobs()
    {
        $configured = getenv('QUERY_WORKER_MAX_FOLIO_JOBS');
        if ($configured === false || trim((string)$configured) === '') {
            return 2;
        }

        return max(1, (int)$configured);
    }

    /**
     * Count jobs currently using the FOLIO Postgres connection.
     *
     * @return int
     */
    private function runningFolioJobCount()
    {
        return (int) QueryJob::find()
            ->where(['status' => 'running'])
            ->andWhere([
                'or',
                ['data_source' => 'folio'],
                ['data_source' => 'composite'],
                ['data_source' => null],
                ['data_source' => ''],
            ])
            ->count();
    }

    /**
     * Whether a queued job will use the FOLIO Postgres connection.
     *
     * @param string $dataSource
     * @return bool
     */
    private function usesFolioConnection($dataSource)
    {
        return in_array(strtolower((string)$dataSource), ['folio', 'composite', ''], true);
    }

    /**
     * Atomically claim the next pending job.
     * Uses UPDATE ... WHERE to prevent double-claiming.
    * Skips FOLIO jobs while the configured FOLIO concurrency limit is reached.
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

        $jobDataSource = $job->hasAttribute('data_source') ? strtolower((string) ($job->data_source ?: 'folio')) : 'folio';
        if ($this->usesFolioConnection($jobDataSource) && $this->runningFolioJobCount() >= $this->maxConcurrentFolioJobs()) {
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

            $runQuery = function () use ($db, $dataSource, $job, $params) {
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
                    return $rows;
                } catch (\Throwable $e) {
                    if ($transaction->isActive) {
                        try {
                            $transaction->rollBack();
                        } catch (\Throwable $rollbackError) {
                            Yii::warning(
                                'Rollback failed in query-worker executeJob: ' . $rollbackError->getMessage(),
                                'db.retry'
                            );
                        }
                    }
                    throw $e;
                }
            };

            $rows = $dataSource === 'folio'
                ? DatabaseRetryService::runWithReconnectRetry($db, $runQuery, 'query-worker.execute_job.folio')
                : $runQuery();

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
            $runPrimary = function () use ($folio, $job, $primaryParams) {
                $transaction = $folio->beginTransaction();
                try {
                    $folio->createCommand("SET TRANSACTION READ ONLY")->execute();
                    $cmd = $folio->createCommand($job->sql_text);
                    foreach ($primaryParams as $key => $value) {
                        // Bind only params that appear in the primary SQL to avoid
                        // "Invalid parameter number" errors when shared params (like
                        // :fiscalYear) are present only in the secondary SQL.
                        if (strpos($job->sql_text, $key) !== false) {
                            $cmd->bindValue($key, $value);
                        }
                    }
                    $rows = $cmd->queryAll();
                    $transaction->commit();
                    return $rows;
                } catch (\Throwable $e) {
                    if ($transaction->isActive) {
                        try {
                            $transaction->rollBack();
                        } catch (\Throwable $rollbackError) {
                            Yii::warning(
                                'Rollback failed in query-worker executeCompositeJob: ' . $rollbackError->getMessage(),
                                'db.retry'
                            );
                        }
                    }
                    throw $e;
                }
            };

            $primaryRows = DatabaseRetryService::runWithReconnectRetry(
                $folio,
                $runPrimary,
                'query-worker.execute_composite.primary'
            );

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

            // Computed column definitions (simple arithmetic over merged columns).
            // Each entry: {"name": "Remaining", "formula": "Allocation - Total Payments - Total Encumbrances"}
            $computedColumns = $config['computed_columns'] ?? [];

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

                // Evaluate computed columns against the fully-merged row.
                // Formulas are restricted to column references + arithmetic (+, -, *, /).
                // Example: "Allocation - Total Payments - Total Encumbrances"
                foreach ($computedColumns as $computed) {
                    $destCol = $computed['name'] ?? null;
                    $formula = $computed['formula'] ?? null;
                    if ($destCol === null || $formula === null) {
                        continue;
                    }
                    $value = $this->evaluateFormula($formula, $merged);
                    $merged[$destCol] = is_float($value) ? round($value, 2) : $value;
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
     * Evaluate a simple arithmetic formula against a row of data.
     *
     * Formulas consist of column-name tokens separated by +, -, *, / operators.
     * Column names may contain spaces and are matched case-sensitively against
     * the keys in $row.  Tokens that don't match a column are treated as 0.
     *
     * Example: "Allocation - Total Payments - Total Encumbrances"
     *
     * For safety only numeric values (and null/missing → 0) are used; the
     * formula string itself must contain only alphanumeric characters, spaces,
     * and the four arithmetic operators — anything else causes a 0 result.
     *
     * @param string $formula
     * @param array  $row  Fully-merged row keyed by column name
     * @return float|null  null when the formula cannot be evaluated
     */
    private function evaluateFormula($formula, array $row)
    {
        // Guard: only allow the characters we expect
        if (!preg_match('/^[\w\s\+\-\*\/\.]+$/', $formula)) {
            return null;
        }

        // Tokenise on operators, preserving the operator itself
        // We walk the formula left to right accumulating a running value.
        $pattern = '/(\s*[\+\-\*\/]\s*)/';
        $parts = preg_split($pattern, $formula, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts) {
            return null;
        }

        $resolve = function ($token) use ($row) {
            $token = trim($token);
            if (isset($row[$token])) {
                return (float) $row[$token];
            }
            // Also try numeric literal
            if (is_numeric($token)) {
                return (float) $token;
            }
            return 0.0;
        };

        $result = $resolve(array_shift($parts));

        while (!empty($parts)) {
            $op    = trim(array_shift($parts));  // operator
            $right = $resolve(array_shift($parts)); // operand

            switch ($op) {
                case '+': $result += $right; break;
                case '-': $result -= $right; break;
                case '*': $result *= $right; break;
                case '/': $result  = ($right != 0) ? $result / $right : null; break;
                default:  return null;
            }

            if ($result === null) {
                return null;
            }
        }

        return $result;
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
