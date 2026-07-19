<?php

namespace app\services;

use app\models\QueryJob;
use DomainException;
use yii\db\Connection;

/**
 * Coordinates atomic query-job state changes with database interruption.
 */
class QueryJobCancellationService
{
    /** @var Connection */
    private $localDb;

    /** @var Connection */
    private $folioDb;

    /** @var callable */
    private $backendCanceller;

    public function __construct(Connection $localDb, Connection $folioDb, ?callable $backendCanceller = null)
    {
        $this->localDb = $localDb;
        $this->folioDb = $folioDb;
        $this->backendCanceller = $backendCanceller ?: function ($pid, $jobId) {
            return $this->folioDb
                ->createCommand(
                    'SELECT COALESCE((
                        SELECT pg_cancel_backend(pid)
                        FROM pg_stat_activity
                        WHERE pid = :pid
                          AND datname = current_database()
                          AND usename = current_user
                          AND application_name = :application_name
                    ), FALSE)',
                    [
                        ':pid' => (int) $pid,
                        ':application_name' => self::applicationName($jobId),
                    ]
                )
                ->queryScalar();
        };
    }

    /**
     * Return the PostgreSQL session tag shared by workers and cancellation.
     *
     * @param string $jobId
     * @return string
     */
    public static function applicationName($jobId)
    {
        return 'folio-report-explorer:' . substr((string) $jobId, 0, 42);
    }

    /**
     * Request cancellation and return the refreshed job state.
     *
     * @param QueryJob $job
     * @return QueryJob
     * @throws DomainException when the job is already terminal and not cancelled
     */
    public function cancel(QueryJob $job)
    {
        $job->refresh();

        if (in_array($job->status, ['pending', 'pending_export'], true)) {
            $this->localDb->createCommand()->update('query_jobs', [
                'status' => 'cancelled',
                'completed_at' => date('Y-m-d H:i:s'),
                'progress_message' => 'Cancelled by user',
                'error_message' => null,
                'pg_backend_pid' => null,
            ], [
                'id' => $job->id,
                'status' => ['pending', 'pending_export'],
            ])->execute();

            $job->refresh();
            if ($job->status === 'cancelled') {
                return $job;
            }
        }

        if ($job->status === 'running') {
            $this->localDb->createCommand()->update('query_jobs', [
                'status' => 'cancelling',
                'progress_message' => 'Cancelling…',
            ], [
                'id' => $job->id,
                'status' => 'running',
            ])->execute();
            $job->refresh();
        }

        if ($job->status === 'cancelling') {
            $dataSource = $job->hasAttribute('data_source')
                ? strtolower((string) ($job->data_source ?: 'folio'))
                : 'folio';
            $pid = $job->hasAttribute('pg_backend_pid') ? (int) $job->pg_backend_pid : 0;
            if ($pid > 0 && in_array($dataSource, ['folio', 'composite'], true)) {
                call_user_func($this->backendCanceller, $pid, $job->id);
            }
            return $job;
        }

        if ($job->status === 'cancelled') {
            return $job;
        }

        throw new DomainException("Cannot cancel job with status '{$job->status}'");
    }
}
