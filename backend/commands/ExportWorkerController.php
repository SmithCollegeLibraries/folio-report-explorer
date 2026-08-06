<?php

namespace app\commands;

use Yii;
use yii\console\Controller;
use app\models\QueryJob;
use app\services\DatabaseRetryService;
use app\services\FolioIdentifierCsvService;
use app\services\QueryJobCancellationService;
use app\services\ReportExecutionContractService;

/**
 * ExportWorkerController — background worker for large file exports.
 *
 * Usage: php yii export-worker/run
 */
class ExportWorkerController extends Controller
{
    /** @var int Polling interval in seconds when no jobs are pending. */
    public $sleep = 2;

    /**
     * Run the export worker loop.
     */
    public function actionRun()
    {
        $this->stdout("Export worker started. Polling every {$this->sleep}s. FOLIO concurrency limit: " . $this->maxConcurrentFolioJobs() . "...\n");

        while (true) {
            try {
                $job = $this->claimNextExportJob();
                if ($job) {
                    $this->executeExportJob($job);
                } else {
                    sleep($this->sleep);
                }
            } catch (\Exception $e) {
                $this->stderr("Export worker error: {$e->getMessage()}\n");
                sleep($this->sleep);
            }
        }
    }

    /**
     * Process all pending export jobs then exit.
     */
    public function actionProcessOnce()
    {
        $processed = 0;
        while (true) {
            try {
                $job = $this->claimNextExportJob();
                if (!$job) {
                    break;
                }
                $this->executeExportJob($job);
                $processed++;
            } catch (\Exception $e) {
                $this->stderr("Export worker error: {$e->getMessage()}\n");
                break;
            }
        }

        if ($processed > 0) {
            $this->stdout("Processed {$processed} export job(s).\n");
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
            ->where(['status' => ['running', 'cancelling']])
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
     * Atomically claim the next pending export job.
    * Skips FOLIO jobs while the configured FOLIO concurrency limit is reached.
     *
     * @return QueryJob|null
     */
    private function claimNextExportJob()
    {
        $job = QueryJob::find()
            ->where(['status' => 'pending_export'])
            ->orderBy(['created_at' => SORT_ASC])
            ->one();

        if (!$job) {
            return null;
        }

        $jobDataSource = $job->hasAttribute('data_source') ? strtolower((string) ($job->data_source ?: 'folio')) : 'folio';
        if ($jobDataSource === 'folio' && $this->runningFolioJobCount() >= $this->maxConcurrentFolioJobs()) {
            return null;
        }

        $affected = Yii::$app->db->createCommand()
            ->update('query_jobs', [
                'status' => 'running',
                'started_at' => date('Y-m-d H:i:s'),
                'progress_message' => 'Generating CSV export…',
            ], [
                'id' => $job->id,
                'status' => 'pending_export',
            ])
            ->execute();

        if ($affected === 0) {
            return null;
        }

        $job->refresh();
        return $job;
    }

    /**
     * Execute a file export job by streaming rows to CSV.
     *
     * @param QueryJob $job
     */
    private function executeExportJob(QueryJob $job)
    {
        $this->stdout("Exporting job {$job->id}...\n");
        $startTime = microtime(true);
        $filePath = null;
        $fileHandle = null;

        try {
            if ($this->isCancellationRequested($job)) {
                $this->finishCancellation($job, $fileHandle, $filePath, 'before export');
                return;
            }

            $dataSource = $job->hasAttribute('data_source') ? strtolower((string) ($job->data_source ?: 'folio')) : 'folio';
            if ($dataSource === 'composite') {
                throw new \RuntimeException('Composite exports are not yet supported for file mode.');
            }
            if (!in_array($dataSource, ['folio', 'local'], true)) {
                $dataSource = 'folio';
            }

            $db = $dataSource === 'local' ? Yii::$app->db : Yii::$app->folioDb;
            $params = $job->getDecodedParams();

            $exportDir = Yii::getAlias('@runtime/exports');
            if (!is_dir($exportDir) && !mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
                throw new \RuntimeException('Failed to create exports directory.');
            }

            $filePath = $exportDir . DIRECTORY_SEPARATOR . $job->id . '.csv';
            $fileHandle = fopen($filePath, 'w');
            if ($fileHandle === false) {
                throw new \RuntimeException('Failed to open export file for writing.');
            }

            $genericMaxRows = (int) (Yii::$app->params['exportRowLimit'] ?? 500000);
            $contract = ReportExecutionContractService::fromJob($job);
            $sql = $this->prepareExportSql($job, $genericMaxRows);

            $prepareAndExecute = function () use ($db, $dataSource, $job, $sql, $params) {
                if ($dataSource === 'folio') {
                    $db->createCommand("SET statement_timeout = " . (int) Yii::$app->params['queryTimeoutMs'])->execute();
                    $db->createCommand(
                        "SELECT set_config('application_name', :application_name, false)",
                        [':application_name' => QueryJobCancellationService::applicationName($job->id)]
                    )->queryScalar();
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

                    if ($this->isCancellationRequested($job)) {
                        throw new \RuntimeException('Query cancellation requested.');
                    }
                }

                $pdo = $db->getMasterPdo();
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->execute();

                return $stmt;
            };

            $stmt = $dataSource === 'folio'
                ? DatabaseRetryService::runWithReconnectRetry($db, $prepareAndExecute, 'export-worker.execute_export.folio')
                : $prepareAndExecute();

            $columnCount = $stmt->columnCount();
            $headers = [];
            for ($i = 0; $i < $columnCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                $headers[] = $meta['name'] ?? ('column_' . ($i + 1));
            }
            $identifierExport = $contract !== null && $contract['exportKind'] === 'identifier';
            if ($identifierExport) {
                $headers = [$contract['identifierExport']['header']];
                fwrite($fileHandle, FolioIdentifierCsvService::encodeRow($headers));
            } else {
                fputcsv($fileHandle, $headers);
            }

            $rowCount = 0;
            $sourceRowCount = 0;
            $identifierSkippedCount = 0;
            $truncated = false;
            $previewLimit = max(0, (int) (Yii::$app->params['exportPreviewRows'] ?? 200));
            $previewRows = [];
            $seenIdentifiers = [];
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                $sourceRowCount++;
                if ($contract !== null && $sourceRowCount > $contract['publicRowCap']) {
                    $truncated = true;
                    break;
                }

                if ($identifierExport) {
                    $identifier = FolioIdentifierCsvService::project($row, $contract['identifierExport']);
                    if ($identifier === null) {
                        $identifierSkippedCount++;
                        continue;
                    }
                    if (isset($seenIdentifiers[$identifier])) {
                        continue;
                    }
                    $seenIdentifiers[$identifier] = true;
                    fwrite($fileHandle, FolioIdentifierCsvService::encodeRow([$identifier]));
                    $previewRow = [$headers[0] => $identifier];
                } else {
                    fputcsv($fileHandle, $row);
                    $previewRow = $row;
                }
                $rowCount++;

                if ($previewLimit > 0 && count($previewRows) < $previewLimit) {
                    $previewRows[] = $previewRow;
                }

                if ($sourceRowCount % 1000 === 0 && $this->isCancellationRequested($job)) {
                    $this->finishCancellation($job, $fileHandle, $filePath, 'during export');
                    return;
                }

                if ($rowCount % 10000 === 0) {
                    Yii::$app->db->createCommand()->update(
                        'query_jobs',
                        ['progress_message' => "Exporting CSV… {$rowCount} rows written"],
                        ['id' => $job->id, 'status' => 'running']
                    )->execute();

                }
            }

            fclose($fileHandle);
            $fileHandle = null;

            if ($this->isCancellationRequested($job)) {
                $this->finishCancellation($job, $fileHandle, $filePath, 'before export completion');
                return;
            }

            $executionTime = (int) round((microtime(true) - $startTime) * 1000);
            $job->markExportCompleted($filePath, $rowCount, $executionTime, $headers, $previewRows, $truncated, $identifierSkippedCount);
            $this->stdout("Export job {$job->id} completed: {$rowCount} rows in {$executionTime}ms\n");

            $this->logQuery($job);
        } catch (\Throwable $e) {
            if ($this->isCancellationRequested($job)) {
                $this->finishCancellation($job, $fileHandle, $filePath, 'during export');
                return;
            }
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
            if ($filePath && is_file($filePath)) {
                @unlink($filePath);
            }
            $executionTime = (int) round((microtime(true) - $startTime) * 1000);
            $job->markFailed($e->getMessage(), $executionTime);
            $this->stderr("Export job {$job->id} failed: {$e->getMessage()}\n");
            $this->logQuery($job);
        }
    }

    /**
     * Refresh a job and determine whether a stop has been requested.
     *
     * @param QueryJob $job
     * @return bool
     */
    private function isCancellationRequested(QueryJob $job)
    {
        $job->refresh();
        return in_array($job->status, ['cancelling', 'cancelled'], true);
    }

    /**
     * Close and remove partial output, then settle cancellation as terminal.
     *
     * @param QueryJob $job
     * @param resource|null $fileHandle
     * @param string|null $filePath
     * @param string $phase
     */
    private function finishCancellation(QueryJob $job, &$fileHandle, $filePath, $phase)
    {
        if (is_resource($fileHandle)) {
            fclose($fileHandle);
            $fileHandle = null;
        }
        if ($filePath && is_file($filePath)) {
            @unlink($filePath);
        }
        if ($job->status !== 'cancelled') {
            $job->markCancelled();
        }
        $this->stdout("Job {$job->id} cancelled {$phase}.\n");
    }

    /**
     * Retain the static ordering and sentinel for reports with a server-owned
     * execution contract. Ordinary exports keep the legacy generic policy.
     *
     * @param QueryJob $job
     * @param int $genericMaxRows
     * @return string
     */
    private function prepareExportSql(QueryJob $job, int $genericMaxRows): string
    {
        $contract = ReportExecutionContractService::fromJob($job);
        if ($contract !== null) {
            return ReportExecutionContractService::assertStaticExportSql($job->sql_text, $contract);
        }

        return $this->applyExportLimit($job->sql_text, $genericMaxRows);
    }

    /**
     * Replace/append top-level LIMIT for export mode, and strip any top-level
     * ORDER BY clause (sorting all rows before streaming is extremely expensive
     * and unnecessary for a CSV download).
     *
     * @param string $sql
     * @param int $maxRows
     * @return string
     */
    private function applyExportLimit($sql, $maxRows)
    {
        $trimmed = rtrim($sql, "; \n\t");
        $trimmed = $this->stripTopLevelOrderBy($trimmed);
        if (preg_match('/\bLIMIT\s+\d+\s*$/i', $trimmed)) {
            return preg_replace('/\bLIMIT\s+\d+\s*$/i', 'LIMIT ' . (int)$maxRows, $trimmed);
        }
        return $trimmed . "\nLIMIT " . (int)$maxRows;
    }

    /**
     * Remove the outermost ORDER BY clause from a SQL string without touching
     * ORDER BY clauses inside subqueries or CTEs.
     *
     * @param string $sql
     * @return string
     */
    private function stripTopLevelOrderBy(string $sql): string
    {
        $depth = 0;
        $len = strlen($sql);
        $lastOrderByPos = -1;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($depth === 0 && strtoupper(substr($sql, $i, 5)) === 'ORDER') {
                if (preg_match('/^ORDER\s+BY\b/i', substr($sql, $i))) {
                    $lastOrderByPos = $i;
                }
            }
        }

        if ($lastOrderByPos === -1) {
            return $sql;
        }

        $before = rtrim(substr($sql, 0, $lastOrderByPos));
        $after = substr($sql, $lastOrderByPos);

        // Strip "ORDER BY ..." up to the LIMIT clause or end of string
        $after = preg_replace('/^ORDER\s+BY\b.+?(?=\s*\bLIMIT\b|\s*$)/is', '', $after);

        return $before . $after;
    }

    /**
     * Log query result to query_log.
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
