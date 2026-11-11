<?php

namespace app\services;

use app\models\QueryJob;
use app\models\ReportTemplate;
use app\models\SavedQuery;

final class ReportReplayService
{
    public static function historyEligibility(array $job): array
    {
        $metadata = self::decodeObject($job['metadata'] ?? null);
        $reportId = $metadata && isset($metadata->reportTemplateId)
            ? self::reportId($metadata->reportTemplateId)
            : null;
        if (!$metadata || !property_exists($metadata, 'reportReplay')) {
            return self::eligibility($reportId, false, 'older_run');
        }
        if (($job['source'] ?? null) !== 'report' || $reportId === null || !self::isValidSnapshot($metadata->reportReplay)) {
            return self::eligibility($reportId, false, 'invalid_snapshot');
        }

        $report = ReportTemplate::findOne($reportId);
        if (!$report) {
            return self::eligibility($reportId, false, 'report_deleted');
        }
        if ((int) $report->is_active !== 1) {
            return self::eligibility($reportId, false, 'report_retired');
        }
        return self::eligibility($reportId, true, null);
    }

    public static function isValidSnapshot($snapshot): bool
    {
        if (!is_object($snapshot) || !property_exists($snapshot, 'inputs') || !is_object($snapshot->inputs)) {
            return false;
        }
        foreach (get_object_vars($snapshot->inputs) as $value) {
            if (!is_scalar($value)) {
                return false;
            }
        }
        return isset($snapshot->outputMode)
            && in_array($snapshot->outputMode, ['table', 'file'], true)
            && isset($snapshot->exportKind)
            && in_array($snapshot->exportKind, ['worklist', 'identifier'], true);
    }

    public static function savedRefresh($queryDefinition): array
    {
        $definition = self::decodeObject($queryDefinition);
        if (!$definition || !property_exists($definition, 'report_replay')) {
            return ['hasReplay' => false];
        }
        $reportId = isset($definition->report_template_id)
            ? self::reportId($definition->report_template_id)
            : null;
        if ($reportId === null || !self::isValidSnapshot($definition->report_replay)) {
            return ['hasReplay' => true, 'valid' => false];
        }
        return [
            'hasReplay' => true,
            'valid' => true,
            'reportTemplateId' => $reportId,
            'snapshot' => $definition->report_replay,
        ];
    }

    public static function saveFromJob(array $body, $userId): array
    {
        $job = QueryJob::findOne($body['fromJobId'] ?? null);
        if (!$job) {
            return ['statusCode' => 404, 'error' => 'Job not found'];
        }
        $eligibility = self::historyEligibility([
            'source' => $job->source,
            'metadata' => $job->metadata,
        ]);
        if (!$eligibility['canSave']) {
            $reason = $eligibility['reason'];
            $messages = [
                'older_run' => "Run this report again to save it - older runs didn't keep their settings.",
                'invalid_snapshot' => "This run's saved settings can't be read - run the report again to save it.",
                'report_retired' => 'This report has been retired.',
                'report_deleted' => 'This report no longer exists.',
            ];
            return [
                'statusCode' => 422,
                'error' => $messages[$reason] ?? $reason,
                'reason' => $reason,
            ];
        }

        $metadata = self::decodeObject($job->metadata);
        $query = new SavedQuery();
        $query->name = $body['name'] ?? 'Untitled Query';
        $query->user_id = $userId;
        $query->description = $body['description'] ?? null;
        $query->query_definition = json_encode([
            'report_template_id' => $eligibility['reportTemplateId'],
            'report_replay' => [
                'inputs' => $metadata->reportReplay->inputs,
                'outputMode' => $metadata->reportReplay->outputMode,
                'exportKind' => $metadata->reportReplay->exportKind,
            ],
            'saved_from_job' => $job->id,
        ]);
        $query->generated_sql = $job->sql_text;
        $query->source = 'report';
        $query->is_pinned = !empty($body['isPinned']) ? 1 : 0;
        if (!$query->save()) {
            return ['statusCode' => 422, 'error' => 'Failed to save query', 'errors' => $query->errors];
        }
        return ['statusCode' => 201, 'query' => $query];
    }

    private static function eligibility($reportId, bool $canSave, $reason): array
    {
        return [
            'reportTemplateId' => $reportId,
            'canSave' => $canSave,
            'reason' => $reason,
        ];
    }

    private static function reportId($value)
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }
        return null;
    }

    private static function decodeObject($json)
    {
        if (is_object($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded);
        }
        return is_object($decoded) ? $decoded : null;
    }
}
