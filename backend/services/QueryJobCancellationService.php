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

    public function __construct(Connection $localDb, Connection $folioDb, callable $backendCanceller = null)
    {
        $this->localDb = $localDb;
        $this->folioDb = $folioDb;
        $this->backendCanceller = $backendCanceller ?: function ($pid) {
            return $this->folioDb
                ->createCommand('SELECT pg_cancel_backend(:pid)', [':pid' => (int) $pid])
                ->queryScalar();
        };
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
                call_user_func($this->backendCanceller, $pid);
            }
            return $job;
        }

        if ($job->status === 'cancelled') {
            return $job;
        }

        throw new DomainException("Cannot cancel job with status '{$job->status}'");
    }
}
