<?php

namespace app\services;

final class HistoryItemSerializer
{
    public static function serialize(array $job, $userId = null, bool $isAdmin = false, array $advisory = []): array
    {
        $id = $job['id'] ?? $job['jobId'] ?? null;
        $ownerId = $job['user_id'] ?? null;
        $authorized = $isAdmin
            || ($userId !== null && (int) $ownerId === (int) $userId);
        $terminal = in_array($job['status'] ?? null, ['completed', 'failed', 'cancelled'], true);
        $item = [
            'jobId' => $id,
            'name' => $job['name'] ?? null,
            'status' => $job['status'] ?? null,
            'sql' => $job['sql_text'] ?? ($job['sql'] ?? null),
            'source' => $job['source'] ?? null,
            'dataSource' => $job['data_source'] ?? ($job['dataSource'] ?? 'folio'),
            'progressMessage' => $job['progress_message'] ?? ($job['progressMessage'] ?? null),
            'rowCount' => (int) ($job['row_count'] ?? ($job['rowCount'] ?? 0)),
            'executionTimeMs' => (int) ($job['execution_time_ms'] ?? ($job['executionTimeMs'] ?? 0)),
            'errorMessage' => $job['error_message'] ?? ($job['errorMessage'] ?? null),
            'createdAt' => $job['created_at'] ?? ($job['createdAt'] ?? null),
            'startedAt' => $job['started_at'] ?? ($job['startedAt'] ?? null),
            'completedAt' => $job['completed_at'] ?? ($job['completedAt'] ?? null),
            'runBy' => $job['runBy'] ?? null,
            'canDelete' => $authorized && $terminal,
        ];

        if (($job['source'] ?? null) === 'report') {
            $item['reportRun'] = ReportReplayService::historyEligibility($job);
        }

        $reviewState = $advisory['reviewAdvisoryState'] ?? ($job['reviewAdvisoryState'] ?? null);
        $supersededByJobId = $advisory['reviewSupersededByJobId'] ?? ($job['reviewSupersededByJobId'] ?? null);
        if ($reviewState === 'cautioned') {
            $item['reviewAdvisory'] = [
                'state' => 'cautioned',
                'message' => 'A reporting specialist identified an important limitation in this result.',
            ];
        } elseif ($reviewState === 'superseded') {
            if (!empty($supersededByJobId)) {
                $item['reviewAdvisory'] = [
                    'state' => 'superseded',
                    'message' => 'A corrected version of this report is available.',
                    'supersededByJobId' => $supersededByJobId,
                ];
            } else {
                $item['reviewAdvisory'] = [
                    'state' => 'superseded',
                    'message' => 'A corrected version of this report was created, but it is no longer available in your history.',
                ];
            }
        }
        return $item;
    }
}
