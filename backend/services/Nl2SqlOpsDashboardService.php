<?php

namespace app\services;

use DateTimeImmutable;

class Nl2SqlOpsDashboardService
{
    const DEFAULT_SHADOW_WINDOW_DAYS = 7;
    const DEFAULT_TOP_ERRORS = 5;
    const DEFAULT_TOP_FAMILIES = 5;
    const DEFAULT_RECENT_MISMATCHES = 5;
    const DEFAULT_RECENT_HISTORY_FAILURES = 5;

    public static function buildDashboard(array $settings, array $options = []): array
    {
        $shadowWindowDays = max(1, (int)($options['shadowWindowDays'] ?? self::DEFAULT_SHADOW_WINDOW_DAYS));
        $now = self::resolveNow($options['now'] ?? null);
        $logFiles = self::resolveLogFiles($options['logFiles'] ?? null);
        $outputsDir = self::resolveOutputsDir($options['outputsDir'] ?? null);
        $historyFailures = is_array($options['historyFailures'] ?? null) ? $options['historyFailures'] : [];

        $shadowEvents = self::loadShadowEvents($logFiles, $shadowWindowDays, $now);
        $failureReviewArtifact = self::loadLatestArtifact($outputsDir, '*_nl2sql-205-failure-review.json');
        $replayArtifact = self::loadLatestArtifact($outputsDir, '*_nl2sql-*-replay-results.json');

        return [
            'generatedAt' => $now->format(DATE_ATOM),
            'cohort' => self::buildCohortSummary($settings),
            'shadow' => self::buildShadowSummary($shadowEvents, $shadowWindowDays, [
                'maxTopErrors' => (int)($options['maxTopErrors'] ?? self::DEFAULT_TOP_ERRORS),
                'maxRecentMismatches' => (int)($options['maxRecentMismatches'] ?? self::DEFAULT_RECENT_MISMATCHES),
            ]),
            'failureReview' => self::buildFailureReviewSummary(
                $failureReviewArtifact,
                (int)($options['maxTopFamilies'] ?? self::DEFAULT_TOP_FAMILIES)
            ),
            'replay' => self::buildReplaySummary($replayArtifact),
            'history' => self::buildHistorySummary(
                $historyFailures,
                (int)($options['maxRecentHistoryFailures'] ?? self::DEFAULT_RECENT_HISTORY_FAILURES)
            ),
        ];
    }

    private static function buildCohortSummary(array $settings): array
    {
        return [
            'primaryMode' => strtolower(trim((string)($settings['nl2sql_primary_mode'] ?? 'legacy'))) ?: 'legacy',
            'intentMode' => !empty($settings['nl2sql_intent_mode']),
            'shadowMode' => !empty($settings['nl2sql_shadow_mode']),
            'shadowUsers' => trim((string)($settings['nl2sql_shadow_users'] ?? '')),
            'shadowSamplePercent' => (int)($settings['nl2sql_shadow_sample_percent'] ?? 0),
            'forceLegacy' => !empty($settings['nl2sql_force_legacy']),
        ];
    }

    private static function buildShadowSummary(array $events, int $windowDays, array $options): array
    {
        $compareEvents = [];
        $errorMessages = [];
        $recentMismatches = [];
        $matchCount = 0;
        $mismatchCount = 0;
        $unknownCount = 0;
        $dataSourceMismatchCount = 0;

        foreach ($events as $event) {
            $eventName = (string)($event['event'] ?? '');
            if ($eventName === 'nl2sql.shadow_compare') {
                $compareEvents[] = $event;

                if (($event['sqlHashMatch'] ?? null) === true) {
                    $matchCount++;
                } elseif (($event['sqlHashMatch'] ?? null) === false) {
                    $mismatchCount++;
                } else {
                    $unknownCount++;
                }

                $hasDataSourceMismatch = isset($event['primaryDataSource'], $event['shadowDataSource'])
                    && $event['primaryDataSource'] !== ''
                    && $event['shadowDataSource'] !== ''
                    && $event['primaryDataSource'] !== $event['shadowDataSource'];

                if ($hasDataSourceMismatch) {
                    $dataSourceMismatchCount++;
                }

                if (($event['sqlHashMatch'] ?? null) === false || $hasDataSourceMismatch) {
                    $recentMismatches[] = [
                        'timestamp' => (string)($event['timestamp'] ?? ''),
                        'promptFingerprint' => (string)($event['promptFingerprint'] ?? ''),
                        'primaryRoute' => (string)($event['primaryRoute'] ?? ''),
                        'shadowRoute' => (string)($event['shadowRoute'] ?? ''),
                        'primaryDataSource' => (string)($event['primaryDataSource'] ?? ''),
                        'shadowDataSource' => (string)($event['shadowDataSource'] ?? ''),
                    ];
                }

                continue;
            }

            if ($eventName === 'nl2sql.shadow_error') {
                $message = trim((string)($event['error'] ?? 'unknown'));
                $errorMessages[$message] = ($errorMessages[$message] ?? 0) + 1;
            }
        }

        uasort($errorMessages, function ($left, $right) {
            if ($left === $right) {
                return 0;
            }

            return $left > $right ? -1 : 1;
        });

        usort($recentMismatches, function (array $left, array $right): int {
            return strcmp((string)($right['timestamp'] ?? ''), (string)($left['timestamp'] ?? ''));
        });

        $compareCount = count($compareEvents);

        return [
            'windowDays' => $windowDays,
            'eventCount' => count($events),
            'compareCount' => $compareCount,
            'errorCount' => array_sum($errorMessages),
            'matchCount' => $matchCount,
            'mismatchCount' => $mismatchCount,
            'unknownCount' => $unknownCount,
            'matchRate' => $compareCount > 0 ? round(($matchCount * 100) / $compareCount, 1) : 0.0,
            'mismatchRate' => $compareCount > 0 ? round(($mismatchCount * 100) / $compareCount, 1) : 0.0,
            'dataSourceMismatchCount' => $dataSourceMismatchCount,
            'topErrors' => array_slice(array_map(function (string $message) use ($errorMessages): array {
                return [
                    'message' => $message,
                    'count' => (int)$errorMessages[$message],
                ];
            }, array_keys($errorMessages)), 0, max(1, $options['maxTopErrors'])),
            'recentMismatches' => array_slice($recentMismatches, 0, max(1, $options['maxRecentMismatches'])),
        ];
    }

    private static function buildFailureReviewSummary(?array $artifact, int $maxTopFamilies): array
    {
        if ($artifact === null) {
            return [
                'available' => false,
                'generatedAt' => null,
                'windowDays' => null,
                'telemetryEventCount' => 0,
                'replayFailureCount' => 0,
                'historyFailureCount' => 0,
                'familyCount' => 0,
                'topFamilies' => [],
            ];
        }

        $summary = is_array($artifact['review']['summary'] ?? null) ? $artifact['review']['summary'] : [];
        $families = is_array($artifact['review']['families'] ?? null) ? $artifact['review']['families'] : [];
        $topFamilies = [];

        foreach ($families as $key => $family) {
            if (!is_array($family)) {
                continue;
            }

            $topFamilies[] = [
                'key' => (string)$key,
                'title' => (string)($family['title'] ?? $key),
                'severity' => (string)($family['severity'] ?? 'unknown'),
                'category' => (string)($family['category'] ?? 'unknown'),
                'action' => (string)($family['action'] ?? ''),
                'occurrenceCount' => (int)($family['occurrenceCount'] ?? 0),
                'sourceCounts' => is_array($family['sourceCounts'] ?? null) ? $family['sourceCounts'] : [],
            ];
        }

        usort($topFamilies, function (array $left, array $right): int {
            $countCompare = ($right['occurrenceCount'] ?? 0) <=> ($left['occurrenceCount'] ?? 0);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp((string)($left['key'] ?? ''), (string)($right['key'] ?? ''));
        });

        return [
            'available' => true,
            'generatedAt' => $artifact['generatedAt'] ?? null,
            'windowDays' => $artifact['window']['windowDays'] ?? null,
            'telemetryEventCount' => (int)($summary['telemetryEventCount'] ?? 0),
            'replayFailureCount' => (int)($summary['replayFailureCount'] ?? 0),
            'historyFailureCount' => (int)($summary['historyFailureCount'] ?? 0),
            'familyCount' => (int)($summary['familyCount'] ?? count($topFamilies)),
            'topFamilies' => array_slice($topFamilies, 0, max(1, $maxTopFamilies)),
        ];
    }

    private static function buildReplaySummary(?array $artifact): array
    {
        if ($artifact === null) {
            return [
                'available' => false,
                'ticket' => null,
                'capturedAt' => null,
                'gateMet' => null,
                'failedGateKeys' => [],
                'totalPrompts' => 0,
                'passCount' => 0,
                'failCount' => 0,
                'passRate' => null,
                'regressionsOnBaselineSuccess' => 0,
                'promptQualityFailureCount' => null,
                'newSemanticFamilyCount' => null,
                'maxPromptSizeIncreaseBytes' => null,
                'overBudgetPromptCount' => null,
            ];
        }

        $summary = is_array($artifact['summary'] ?? null) ? $artifact['summary'] : [];
        $acceptanceReview = is_array($artifact['acceptanceReview'] ?? null) ? $artifact['acceptanceReview'] : [];
        $gates = is_array($acceptanceReview['gates'] ?? null) ? $acceptanceReview['gates'] : [];
        $overBudgetPromptIds = is_array($acceptanceReview['promptBudget']['current']['overBudgetPromptIds'] ?? null)
            ? $acceptanceReview['promptBudget']['current']['overBudgetPromptIds']
            : [];

        return [
            'available' => true,
            'ticket' => $artifact['ticket'] ?? null,
            'capturedAt' => $artifact['capturedAt'] ?? null,
            'gateMet' => array_key_exists('met', $gates)
                ? (bool)$gates['met']
                : (array_key_exists('met', $artifact['gate'] ?? []) ? (bool)$artifact['gate']['met'] : null),
            'failedGateKeys' => array_values(is_array($gates['failedGateKeys'] ?? null) ? $gates['failedGateKeys'] : []),
            'totalPrompts' => (int)($summary['total'] ?? 0),
            'passCount' => (int)($summary['pass'] ?? 0),
            'failCount' => (int)($summary['fail'] ?? 0),
            'passRate' => isset($summary['passRate']) ? (float)$summary['passRate'] : null,
            'regressionsOnBaselineSuccess' => (int)($summary['regressionsOnBaselineSuccess'] ?? 0),
            'promptQualityFailureCount' => isset($acceptanceReview['quality']['summary']['promptQualityFailureCount'])
                ? (int)$acceptanceReview['quality']['summary']['promptQualityFailureCount']
                : null,
            'newSemanticFamilyCount' => isset($acceptanceReview['failureFamilies']['delta']['newNonCapacityFamilyCount'])
                ? (int)$acceptanceReview['failureFamilies']['delta']['newNonCapacityFamilyCount']
                : null,
            'maxPromptSizeIncreaseBytes' => isset($acceptanceReview['promptSize']['delta']['maxIncreaseBytes'])
                ? (int)$acceptanceReview['promptSize']['delta']['maxIncreaseBytes']
                : null,
            'overBudgetPromptCount' => count($overBudgetPromptIds),
        ];
    }

    private static function buildHistorySummary(array $historyFailures, int $maxRecentHistoryFailures): array
    {
        $recentFailedJobs = [];

        foreach ($historyFailures as $job) {
            if (!is_array($job)) {
                continue;
            }

            $recentFailedJobs[] = [
                'jobId' => (string)($job['jobId'] ?? $job['id'] ?? ''),
                'name' => (string)($job['name'] ?? ''),
                'source' => (string)($job['source'] ?? ''),
                'completedAt' => (string)($job['completedAt'] ?? $job['completed_at'] ?? ''),
                'errorMessage' => (string)($job['errorMessage'] ?? $job['error_message'] ?? ''),
            ];
        }

        usort($recentFailedJobs, function (array $left, array $right): int {
            return strcmp((string)($right['completedAt'] ?? ''), (string)($left['completedAt'] ?? ''));
        });

        return [
            'recentFailedCount' => count($recentFailedJobs),
            'recentFailedJobs' => array_slice($recentFailedJobs, 0, max(1, $maxRecentHistoryFailures)),
        ];
    }

    private static function loadShadowEvents(array $logFiles, int $windowDays, DateTimeImmutable $now): array
    {
        $events = [];
        $cutoff = $now->modify('-' . $windowDays . ' days');

        foreach ($logFiles as $logFile) {
            if (!is_string($logFile) || $logFile === '' || !is_file($logFile)) {
                continue;
            }

            $handle = fopen($logFile, 'r');
            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                if (strpos($line, 'NL2SQL telemetry: ') === false) {
                    continue;
                }

                $json = substr($line, strpos($line, 'NL2SQL telemetry: ') + strlen('NL2SQL telemetry: '));
                $event = json_decode(trim($json), true);
                if (!is_array($event)) {
                    continue;
                }

                $eventName = (string)($event['event'] ?? '');
                if ($eventName !== 'nl2sql.shadow_compare' && $eventName !== 'nl2sql.shadow_error') {
                    continue;
                }

                $timestamp = self::parseTimestamp($event['timestamp'] ?? null);
                if ($timestamp !== null && $timestamp < $cutoff) {
                    continue;
                }

                $events[] = $event;
            }

            fclose($handle);
        }

        usort($events, function (array $left, array $right): int {
            return strcmp((string)($left['timestamp'] ?? ''), (string)($right['timestamp'] ?? ''));
        });

        return $events;
    }

    private static function loadLatestArtifact(string $outputsDir, string $pattern): ?array
    {
        if ($outputsDir === '' || !is_dir($outputsDir)) {
            return null;
        }

        $matches = glob(rtrim($outputsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pattern) ?: [];
        if (empty($matches)) {
            return null;
        }

        rsort($matches, SORT_STRING);

        foreach ($matches as $path) {
            $decoded = self::decodeJsonFile($path);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    private static function decodeJsonFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function resolveLogFiles($logFiles): array
    {
        if (is_array($logFiles)) {
            return array_values(array_filter($logFiles, function ($path): bool {
                return is_string($path) && $path !== '';
            }));
        }

        $repoRoot = dirname(__DIR__, 2);
        $logsDir = $repoRoot . '/backend/runtime/logs';
        $paths = [];

        foreach (glob($logsDir . '/app.log*') ?: [] as $path) {
            $paths[] = $path;
        }

        rsort($paths, SORT_STRING);
        return $paths;
    }

    private static function resolveOutputsDir($outputsDir): string
    {
        if (is_string($outputsDir) && $outputsDir !== '') {
            return $outputsDir;
        }

        return dirname(__DIR__, 2) . '/planning/baseline/outputs';
    }

    private static function resolveNow($now): DateTimeImmutable
    {
        if ($now instanceof DateTimeImmutable) {
            return $now;
        }

        if (is_string($now) && trim($now) !== '') {
            $parsed = self::parseTimestamp($now);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return new DateTimeImmutable('now');
    }

    private static function parseTimestamp($value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            return null;
        }
    }
}