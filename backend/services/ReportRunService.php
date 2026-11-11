<?php

namespace app\services;

use app\models\QueryJob;
use app\models\ReportTemplate;
use Yii;

final class ReportRunService
{
    public static function run(ReportTemplate $report, array $body, $userId, array $hooks)
    {
        $userParams = $body['params'] ?? [];
        $outputMode = strtolower((string)($body['outputMode'] ?? 'table')) === 'file' ? 'file' : 'table';
        $exportKind = $body['exportKind'] ?? 'worklist';

        if (!is_string($exportKind) || !in_array($exportKind, ['worklist', 'identifier'], true)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Unsupported report export kind.'];
        }
        if ($exportKind === 'identifier' && !$report->hasIdentifierExport()) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'This report does not support identifier export.'];
        }

        // Validate required params
        $paramDefs = $report->getDecodedParameters();
        $isGovernedReport = CatalogingReportCompilerService::supports($report);
        $missing = [];
        foreach ($paramDefs as $def) {
            if (!empty($def['required']) && empty($userParams[$def['name']])) {
                // Check if there's a default
                $resolved = ReportTemplate::resolveDefaultMacro($def['default'] ?? '');
                if (empty($resolved)) {
                    $missing[] = $def['label'] ?? $def['name'];
                }
            }
        }
        if (!empty($missing) && !$isGovernedReport) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Missing required parameters: ' . implode(', ', $missing)];
        }

        $compiled = null;
        if (CatalogingReportCompilerService::supports($report)) {
            try {
                $compiled = CatalogingReportCompilerService::build(
                    $report,
                    $userParams,
                    Yii::$app->folioDb
                );
            } catch (\app\exceptions\ReportParameterValidationException $e) {
                Yii::$app->response->statusCode = 400;
                return [
                    'error' => 'Report parameters are invalid.',
                    'fieldErrors' => $e->getFieldErrors(),
                ];
            } catch (\InvalidArgumentException $e) {
                if (call_user_func($hooks['isCatalogingReportIntegrityError'], $e)) {
                    Yii::$app->response->statusCode = 422;
                    return ['error' => call_user_func($hooks['catalogingReportIntegrityMessage'], $e)];
                }
                if ($isGovernedReport) {
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'The report definition could not be validated. Please contact an administrator.'];
                }
                Yii::$app->response->statusCode = 400;
                return ['error' => $e->getMessage()];
            } catch (\Throwable $e) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'The report could not be validated. Please try again or contact an administrator.'];
            }
            $bound = ['sql' => $compiled['sql'], 'params' => $compiled['params']];
        } else {
            $bound = $report->bindParams($userParams);
            $bound['sql'] = call_user_func($hooks['normalizeLegacyReportSql'], $report, $bound['sql']);
        }

        // Determine data source from report template
        $rawDataSource = $report->hasAttribute('data_source') ? $report->data_source : null;
        $dataSource = in_array($rawDataSource, ['folio', 'local', 'composite'])
            ? $rawDataSource
            : 'folio';

        // Safety validation
        try {
            if ($dataSource === 'local') {
                LocalReportSqlService::validateSql($bound['sql']);
            } else {
                SqlBuilderService::validateSafety($bound['sql']);
                SqlBuilderService::validateTablePolicy($bound['sql']);
            }
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'This query is blocked by reporting data policy.'];
        }

        // For composite reports, attach the composite_config as job metadata.
        $metadata = null;
        if ($dataSource === 'composite') {
            $compositeConfig = $report->getCompositeConfig();
            if (!$compositeConfig) {
                Yii::$app->response->statusCode = 400;
                return ['error' => 'Composite report is missing composite_config'];
            }
            if (isset($compositeConfig['secondary_sql'])) {
                try {
                    LocalReportSqlService::validateSql($compositeConfig['secondary_sql']);
                } catch (\InvalidArgumentException $exception) {
                    Yii::$app->response->statusCode = 403;
                    return ['error' => LocalReportSqlService::POLICY_MESSAGE];
                }
            }
            // Bind secondary SQL params too (secondary uses same param set)
            $metadata = [
                'composite_config' => $compositeConfig,
                'bound_params' => array_merge($bound['params'], is_array($compiled) ? ($compiled['compositeParams'] ?? []) : []),
            ];
        }

        $estimate = null;
        $reportExecution = null;
        if ($compiled !== null && $dataSource === 'folio' && $report->getExecutionConfig() !== null) {
            try {
                $reportExecution = ReportExecutionContractService::fromReport($report, [
                    'exportKind' => $exportKind,
                    'marcTag' => $compiled['marcTag'],
                    'locationName' => $compiled['location']['name'],
                    'locationCode' => $compiled['location']['code'],
                ]);
            } catch (\InvalidArgumentException $e) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'The report execution configuration is invalid. Please contact an administrator.'];
            }

            try {
                $estimate = call_user_func($hooks['estimateQueryComplexity'], $bound['sql'], 'folio', $bound['params']);
            } catch (\app\exceptions\DatabaseQueryCancelledException $exception) {
                return call_user_func($hooks['buildDatabaseCancelledResponse']);
            } catch (\Throwable $exception) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Query validation failed before execution.'];
            }
            if (isset($estimate['error'])) {
                call_user_func($hooks['logPreflightValidationFailure'],
                    'api.report_run',
                    (string) $estimate['error'],
                    $dataSource,
                    'report',
                    $bound['sql']
                );
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Query validation failed before execution.'];
            }
            if ($estimate !== null) {
                $thresholdRows = (int) (Yii::$app->params['exportRowThreshold'] ?? Yii::$app->params['maxQueryRows']);
                $thresholdCost = (float) (Yii::$app->params['exportCostThreshold'] ?? 500000);
                $estimatedRows = $estimate['rows'] ?? null;
                $estimatedCost = $estimate['cost'] ?? null;
                if (($estimatedRows !== null && $estimatedRows > $thresholdRows)
                    || ($estimatedCost !== null && $estimatedCost > $thresholdCost)) {
                    $outputMode = 'file';
                }
            }
        }
        // Governed reports stream to a file regardless of planner estimates.
        // Their sentinel/cap contract is enforced by the export worker, so a
        // table job must never materialize an unexpectedly large result set.
        if ($reportExecution !== null || $exportKind === 'identifier') {
            $outputMode = 'file';
        }
        if ($reportExecution !== null) {
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata[ReportExecutionContractService::METADATA_KEY] = $reportExecution;
        }

        $titleService = new ReportRunTitleService(
            new ReportRunTitleOptionSource(),
            function ($title) use ($hooks) {
                return call_user_func($hooks['normalizeQueryJobName'], $title);
            }
        );
        $metadata = $titleService->mergeReportReplay(
            $metadata,
            $report,
            $userParams,
            $outputMode,
            $exportKind
        );
        try {
            $titleParams = isset($compiled['identity']) ? $compiled['identity'] : $userParams;
            $jobName = $titleService->buildTitle($report, $titleParams, $exportKind === 'identifier');
        } catch (\Throwable $exception) {
            $jobName = call_user_func($hooks['normalizeQueryJobName'], $report->name);
        }

        // Create async job
        $jobParams = is_array($metadata)
            ? array_merge($bound['params'], $metadata['bound_params'] ?? [])
            : $bound['params'];
        $job = QueryJob::createJob($bound['sql'], $jobParams, 'report', $dataSource, $metadata);
        $job->name = $jobName;
        $job->user_id = $userId;
        if ($job->hasAttribute('output_mode')) {
            $job->output_mode = $outputMode;
        }
        if ($job->hasAttribute('history_group_key')) {
            $historyParams = isset($compiled['identity']) ? $compiled['identity'] : $jobParams;
            $job->history_group_key = \app\services\HistoryGroupKeyService::build(
                (int) $report->id,
                $historyParams,
                $exportKind,
                $outputMode
            );
        }
        if ($outputMode === 'file') {
            $job->status = 'pending_export';
            $job->progress_message = 'Queued for CSV export';
        }
        if ($estimate !== null) {
            if ($job->hasAttribute('estimated_rows')) {
                $job->estimated_rows = $estimate['rows'] ?? null;
            }
            if ($job->hasAttribute('estimated_cost')) {
                $cost = $estimate['cost'] ?? null;
                $job->estimated_cost = $cost !== null ? min((float) $cost, 1.0e15) : null;
            }
        }
        if (!$job->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to create job', 'details' => $job->errors];
        }

        Yii::$app->response->statusCode = 202;
        return [
            'jobId' => $job->id,
            'reportName' => $report->name,
            'status' => $job->status,
            'outputMode' => $job->hasAttribute('output_mode') ? ($job->output_mode ?: 'table') : 'table',
            'dataSource' => $dataSource,
            'progressMessage' => $job->progress_message,
            'createdAt' => $job->created_at,
            'startedAt' => $job->started_at,
            'completedAt' => $job->completed_at,
        ];
    }
}
