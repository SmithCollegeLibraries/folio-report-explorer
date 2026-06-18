<?php

namespace app\services;

class Nl2SqlFailureReviewService
{
    const MAX_EVIDENCE_PER_FAMILY = 3;

    public static function buildReview(array $telemetryEvents, array $replayArtifacts, array $historyJobs): array
    {
        $families = [];
        $clarificationOutcomes = [];
        $telemetryEventCount = 0;
        $clarificationOutcomeCount = 0;
        $replayFailureCount = 0;
        $historyFailureCount = 0;

        foreach ($telemetryEvents as $event) {
            if (!is_array($event)) {
                continue;
            }

            $telemetryEventCount++;

            $clarificationOutcome = self::classifyClarificationOutcome($event);
            if ($clarificationOutcome !== null) {
                $clarificationOutcomeCount++;
                self::recordClarificationOutcome(
                    $clarificationOutcomes,
                    $clarificationOutcome['outcome'],
                    $clarificationOutcome['evidence']
                );
                continue;
            }

            $classification = self::classifyTelemetryEvent($event);
            if ($classification === null) {
                continue;
            }

            self::recordFamily($families, $classification['family'], $classification['evidence']);
        }

        foreach ($replayArtifacts as $artifact) {
            if (!is_array($artifact)) {
                continue;
            }

            foreach (($artifact['results'] ?? []) as $result) {
                if (!self::isReplayFailure($result)) {
                    continue;
                }

                $replayFailureCount++;
                $classification = self::classifyReplayResult($result, $artifact);
                if ($classification === null) {
                    continue;
                }

                self::recordFamily($families, $classification['family'], $classification['evidence']);
            }
        }

        foreach ($historyJobs as $job) {
            if (!self::isHistoryFailure($job)) {
                continue;
            }

            $historyFailureCount++;
            $classification = self::classifyHistoryJob($job);
            if ($classification === null) {
                continue;
            }

            self::recordFamily($families, $classification['family'], $classification['evidence']);
        }

        return [
            'summary' => [
                'telemetryEventCount' => $telemetryEventCount,
                'clarificationOutcomeCount' => $clarificationOutcomeCount,
                'clarificationTypeCount' => count($clarificationOutcomes),
                'replayArtifactCount' => count($replayArtifacts),
                'replayFailureCount' => $replayFailureCount,
                'historyFailureCount' => $historyFailureCount,
                'familyCount' => count($families),
            ],
            'clarificationOutcomes' => self::sortClarificationOutcomes($clarificationOutcomes),
            'families' => self::sortFamilies($families),
        ];
    }

    private static function classifyClarificationOutcome(array $event): ?array
    {
        $eventName = strtolower(trim((string)($event['event'] ?? '')));
        if ($eventName !== 'nl2sql.generated') {
            return null;
        }

        $route = strtolower(trim((string)($event['route'] ?? '')));
        if ($route !== 'clarification') {
            return null;
        }

        $clarificationType = trim((string)($event['clarificationType'] ?? ''));
        if ($clarificationType === '') {
            $clarificationType = 'unspecified';
        }

        return [
            'outcome' => [
                'key' => $clarificationType,
                'title' => self::clarificationTitle($clarificationType),
                'routeReasons' => self::sortedUniqueStrings([(string)($event['routeReason'] ?? '')]),
                'finishReasons' => self::sortedUniqueStrings([(string)($event['finishReason'] ?? '')]),
                'promptVersions' => self::sortedUniqueStrings([(string)($event['promptVersion'] ?? '')]),
            ],
            'evidence' => [
                'sourceType' => 'telemetry',
                'event' => $eventName,
                'route' => $route,
                'routeReason' => (string)($event['routeReason'] ?? ''),
                'clarificationType' => $clarificationType,
                'finishReason' => (string)($event['finishReason'] ?? ''),
                'promptVersion' => (string)($event['promptVersion'] ?? ''),
                'timestamp' => (string)($event['timestamp'] ?? ''),
                'message' => sprintf('Deterministic clarification returned for type %s.', $clarificationType),
            ],
        ];
    }

    private static function classifyTelemetryEvent(array $event): ?array
    {
        $eventName = strtolower(trim((string)($event['event'] ?? '')));
        if ($eventName === '') {
            return null;
        }

        if ($eventName === 'nl2sql.shadow_compare') {
            return self::classifyShadowCompare($event);
        }

        $message = trim((string)(
            ($event['firstErrorMessage'] ?? '') !== ''
                ? $event['firstErrorMessage']
                : ($event['error'] ?? '')
        ));

        $context = [
            'event' => $eventName,
            'stage' => (string)($event['stage'] ?? ''),
            'sourceType' => $eventName === 'nl2sql.shadow_error' ? 'shadow' : 'telemetry',
            'route' => (string)($event['route'] ?? ''),
            'timestamp' => (string)($event['timestamp'] ?? ''),
        ];

        $classification = self::classifyErrorMessage($message, $context);
        if ($classification === null) {
            return null;
        }

        $evidence = [
            'sourceType' => $context['sourceType'],
            'event' => $eventName,
            'stage' => $context['stage'],
            'route' => $context['route'],
            'timestamp' => $context['timestamp'],
            'message' => $message,
        ];

        if (!empty($event['firstErrorPath'])) {
            $evidence['errorPath'] = (string)$event['firstErrorPath'];
        }

        return [
            'family' => $classification,
            'evidence' => $evidence,
        ];
    }

    private static function classifyShadowCompare(array $event): ?array
    {
        if (($event['sqlHashMatch'] ?? null) === false) {
            return [
                'family' => self::makeFamily(
                    'shadow_sql_mismatch',
                    'Shadow SQL Mismatch',
                    'medium',
                    'shadow',
                    'Review prompts where primary and shadow routes generate different SQL for the same request.'
                ),
                'evidence' => [
                    'sourceType' => 'shadow',
                    'event' => 'nl2sql.shadow_compare',
                    'timestamp' => (string)($event['timestamp'] ?? ''),
                    'message' => 'Primary and shadow SQL hashes did not match.',
                    'route' => trim((string)(($event['primaryRoute'] ?? '') . ' vs ' . ($event['shadowRoute'] ?? ''))),
                ],
            ];
        }

        if (
            isset($event['primaryDataSource'], $event['shadowDataSource'])
            && $event['primaryDataSource'] !== ''
            && $event['shadowDataSource'] !== ''
            && $event['primaryDataSource'] !== $event['shadowDataSource']
        ) {
            return [
                'family' => self::makeFamily(
                    'shadow_data_source_mismatch',
                    'Shadow Data Source Mismatch',
                    'medium',
                    'shadow',
                    'Inspect prompts where primary and shadow routes target different data sources.'
                ),
                'evidence' => [
                    'sourceType' => 'shadow',
                    'event' => 'nl2sql.shadow_compare',
                    'timestamp' => (string)($event['timestamp'] ?? ''),
                    'message' => 'Primary and shadow routes selected different data sources.',
                ],
            ];
        }

        return null;
    }

    private static function classifyReplayResult(array $result, array $artifact): ?array
    {
        $current = is_array($result['current'] ?? null) ? $result['current'] : [];
        $message = trim((string)($current['error'] ?? ''));
        $classification = self::classifyErrorMessage($message, [
            'sourceType' => 'replay',
            'event' => 'replay',
            'note' => (string)($result['note'] ?? ''),
        ]);

        if ($classification === null) {
            $classification = self::makeFamily(
                'replay_unclassified_failure',
                'Replay Unclassified Failure',
                'medium',
                'replay',
                'Inspect replay failures that did not match an existing failure family and extend the taxonomy if they repeat.'
            );
        }

        return [
            'family' => $classification,
            'evidence' => [
                'sourceType' => 'replay',
                'event' => 'replay',
                'artifact' => (string)($artifact['capturedAt'] ?? ''),
                'promptId' => (string)($result['id'] ?? ''),
                'prompt' => (string)($result['prompt'] ?? ''),
                'note' => (string)($result['note'] ?? ''),
                'message' => $message,
            ],
        ];
    }

    private static function classifyHistoryJob(array $job): ?array
    {
        $message = trim((string)($job['errorMessage'] ?? $job['error_message'] ?? ''));
        $classification = self::classifyErrorMessage($message, [
            'sourceType' => 'history',
            'event' => 'query_history',
            'source' => (string)($job['source'] ?? ''),
        ]);

        if ($classification === null) {
            $classification = self::makeFamily(
                'query_execution_error',
                'Query Execution Error',
                'medium',
                'history',
                'Review failed query-history executions and capture reusable fixes or guards for the repeated database errors.'
            );
        }

        return [
            'family' => $classification,
            'evidence' => [
                'sourceType' => 'history',
                'event' => 'query_history',
                'jobId' => (string)($job['id'] ?? ''),
                'jobName' => (string)($job['name'] ?? ''),
                'route' => (string)($job['source'] ?? ''),
                'timestamp' => (string)($job['completedAt'] ?? $job['completed_at'] ?? ''),
                'message' => $message,
            ],
        ];
    }

    private static function classifyErrorMessage(string $message, array $context): ?array
    {
        $normalized = strtolower($message);
        $stage = strtolower((string)($context['stage'] ?? ''));

        if ($normalized === '') {
            return null;
        }

        if (strpos($normalized, 'quota exceeded') !== false || strpos($normalized, 'exceeded your current quota') !== false) {
            return self::makeFamily(
                'ai_quota_exhaustion',
                'AI Quota Exhaustion',
                'high',
                'capacity',
                'Rerun or reconfigure replay and shadow checks with sufficient model quota before treating these failures as semantic regressions.'
            );
        }

        if (
            strpos($normalized, 'value is required') !== false
            && (
                $stage === 'intent_contract'
                || strpos($normalized, 'invalid intent json') !== false
                || strpos($normalized, 'intent') !== false
            )
        ) {
            return self::makeFamily(
                'intent_contract_missing_required_fields',
                'Intent Contract Missing Required Fields',
                'high',
                'contract',
                'Tighten structured-intent prompting and inspect missing required paths in generated intent payloads before fallback routing.'
            );
        }

        if (
            strpos($normalized, 'could not extract sql from gemini response') !== false
            && (
                strpos($normalized, 'please provide') !== false
                || strpos($normalized, 'timeframe') !== false
                || strpos($normalized, 'start date') !== false
            )
        ) {
            return self::makeFamily(
                'clarification_response_instead_of_sql',
                'Clarification Response Instead Of SQL',
                'medium',
                'generation',
                'Add deterministic clarification handling or stronger prompt constraints when the model answers with a question instead of SQL.'
            );
        }

        if (preg_match('/query references blocked schema:\s*([a-z0-9_]+)/i', $message, $matches)) {
            return self::makeFamily(
                'safety_blocked_schema_reference',
                'Safety Blocked Schema Reference',
                'medium',
                'safety',
                'Capture blocked-schema prompts as review candidates and reinforce allowed alternatives in semantic context or routing.',
                [$matches[1]]
            );
        }

        if (preg_match('/query references blocked table:\s*([a-z0-9_.]+)/i', $message, $matches)) {
            return self::makeFamily(
                'safety_blocked_table_reference',
                'Safety Blocked Table Reference',
                'medium',
                'safety',
                'Capture blocked-table prompts as review candidates and reinforce allowed alternatives in semantic context or routing.',
                [$matches[1]]
            );
        }

        if (
            strpos($normalized, 'sqlstate[') !== false
            || strpos($normalized, 'syntax error') !== false
            || strpos($normalized, 'column ') !== false
            || strpos($normalized, 'relation ') !== false
        ) {
            return self::makeFamily(
                'query_execution_sql_error',
                'Query Execution SQL Error',
                'high',
                'history',
                'Review generated SQL against current schema semantics and add reusable guards or canonical examples for the repeated database errors.'
            );
        }

        return null;
    }

    private static function makeFamily(string $key, string $title, string $severity, string $category, string $action, array $tokens = []): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'severity' => $severity,
            'category' => $category,
            'action' => $action,
            'tokens' => self::sortedUniqueStrings($tokens),
        ];
    }

    private static function clarificationTitle(string $clarificationType): string
    {
        $normalized = trim($clarificationType);
        if ($normalized === '' || $normalized === 'unspecified') {
            return 'Unspecified Clarification';
        }

        return ucwords(str_replace('_', ' ', $normalized)) . ' Clarification';
    }

    private static function recordClarificationOutcome(array &$clarificationOutcomes, array $outcome, array $evidence): void
    {
        $key = $outcome['key'];
        if (!isset($clarificationOutcomes[$key])) {
            $clarificationOutcomes[$key] = [
                'title' => $outcome['title'],
                'occurrenceCount' => 0,
                'sourceCounts' => [],
                'routeReasons' => [],
                'finishReasons' => [],
                'promptVersions' => [],
                'evidence' => [],
            ];
        }

        $clarificationOutcomes[$key]['occurrenceCount']++;

        $sourceType = trim((string)($evidence['sourceType'] ?? 'unknown'));
        if ($sourceType === '') {
            $sourceType = 'unknown';
        }
        $clarificationOutcomes[$key]['sourceCounts'][$sourceType] = ($clarificationOutcomes[$key]['sourceCounts'][$sourceType] ?? 0) + 1;

        $clarificationOutcomes[$key]['routeReasons'] = self::sortedUniqueStrings(array_merge(
            $clarificationOutcomes[$key]['routeReasons'],
            $outcome['routeReasons'] ?? [],
            [(string)($evidence['routeReason'] ?? '')]
        ));
        $clarificationOutcomes[$key]['finishReasons'] = self::sortedUniqueStrings(array_merge(
            $clarificationOutcomes[$key]['finishReasons'],
            $outcome['finishReasons'] ?? [],
            [(string)($evidence['finishReason'] ?? '')]
        ));
        $clarificationOutcomes[$key]['promptVersions'] = self::sortedUniqueStrings(array_merge(
            $clarificationOutcomes[$key]['promptVersions'],
            $outcome['promptVersions'] ?? [],
            [(string)($evidence['promptVersion'] ?? '')]
        ));
        ksort($clarificationOutcomes[$key]['sourceCounts'], SORT_STRING);

        if (count($clarificationOutcomes[$key]['evidence']) < self::MAX_EVIDENCE_PER_FAMILY) {
            $clarificationOutcomes[$key]['evidence'][] = self::trimEvidence($evidence);
        }
    }

    private static function recordFamily(array &$families, array $family, array $evidence): void
    {
        $key = $family['key'];
        if (!isset($families[$key])) {
            $families[$key] = [
                'title' => $family['title'],
                'severity' => $family['severity'],
                'category' => $family['category'],
                'action' => $family['action'],
                'occurrenceCount' => 0,
                'sourceCounts' => [],
                'tokens' => $family['tokens'] ?? [],
                'jobNames' => [],
                'promptIds' => [],
                'evidence' => [],
            ];
        }

        $families[$key]['occurrenceCount']++;

        $sourceType = trim((string)($evidence['sourceType'] ?? 'unknown'));
        if ($sourceType === '') {
            $sourceType = 'unknown';
        }
        $families[$key]['sourceCounts'][$sourceType] = ($families[$key]['sourceCounts'][$sourceType] ?? 0) + 1;

        $families[$key]['tokens'] = self::sortedUniqueStrings(array_merge(
            $families[$key]['tokens'],
            $family['tokens'] ?? []
        ));

        $jobName = trim((string)($evidence['jobName'] ?? ''));
        if ($jobName !== '') {
            $families[$key]['jobNames'][] = $jobName;
        }

        $promptId = trim((string)($evidence['promptId'] ?? ''));
        if ($promptId !== '') {
            $families[$key]['promptIds'][] = $promptId;
        }

        $families[$key]['jobNames'] = self::sortedUniqueStrings($families[$key]['jobNames']);
        $families[$key]['promptIds'] = self::sortedUniqueStrings($families[$key]['promptIds']);
        ksort($families[$key]['sourceCounts'], SORT_STRING);

        if (count($families[$key]['evidence']) < self::MAX_EVIDENCE_PER_FAMILY) {
            $families[$key]['evidence'][] = self::trimEvidence($evidence);
        }
    }

    private static function trimEvidence(array $evidence): array
    {
        $trimmed = [];
        foreach ($evidence as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $trimmed[$key] = $value;
        }

        if (isset($trimmed['message']) && is_string($trimmed['message']) && strlen($trimmed['message']) > 220) {
            $trimmed['message'] = substr($trimmed['message'], 0, 217) . '...';
        }

        return $trimmed;
    }

    private static function sortFamilies(array $families): array
    {
        $keys = array_keys($families);
        usort($keys, function ($left, $right) use ($families) {
            $leftCount = (int)($families[$left]['occurrenceCount'] ?? 0);
            $rightCount = (int)($families[$right]['occurrenceCount'] ?? 0);
            if ($leftCount !== $rightCount) {
                return $rightCount <=> $leftCount;
            }

            $leftSeverity = self::severityRank((string)($families[$left]['severity'] ?? 'low'));
            $rightSeverity = self::severityRank((string)($families[$right]['severity'] ?? 'low'));
            if ($leftSeverity !== $rightSeverity) {
                return $rightSeverity <=> $leftSeverity;
            }

            return strcmp($left, $right);
        });

        $sorted = [];
        foreach ($keys as $key) {
            $family = $families[$key];
            $family['tokens'] = self::sortedUniqueStrings($family['tokens'] ?? []);
            $family['jobNames'] = self::sortedUniqueStrings($family['jobNames'] ?? []);
            $family['promptIds'] = self::sortedUniqueStrings($family['promptIds'] ?? []);
            ksort($family['sourceCounts'], SORT_STRING);
            $sorted[$key] = $family;
        }

        return $sorted;
    }

    private static function sortClarificationOutcomes(array $clarificationOutcomes): array
    {
        $keys = array_keys($clarificationOutcomes);
        usort($keys, function ($left, $right) use ($clarificationOutcomes) {
            $leftCount = (int)($clarificationOutcomes[$left]['occurrenceCount'] ?? 0);
            $rightCount = (int)($clarificationOutcomes[$right]['occurrenceCount'] ?? 0);
            if ($leftCount !== $rightCount) {
                return $rightCount <=> $leftCount;
            }

            return strcmp($left, $right);
        });

        $sorted = [];
        foreach ($keys as $key) {
            $outcome = $clarificationOutcomes[$key];
            $outcome['routeReasons'] = self::sortedUniqueStrings($outcome['routeReasons'] ?? []);
            $outcome['finishReasons'] = self::sortedUniqueStrings($outcome['finishReasons'] ?? []);
            $outcome['promptVersions'] = self::sortedUniqueStrings($outcome['promptVersions'] ?? []);
            ksort($outcome['sourceCounts'], SORT_STRING);
            $sorted[$key] = $outcome;
        }

        return $sorted;
    }

    private static function severityRank(string $severity): int
    {
        switch (strtolower($severity)) {
            case 'high':
                return 3;
            case 'medium':
                return 2;
            default:
                return 1;
        }
    }

    private static function isReplayFailure($result): bool
    {
        if (!is_array($result)) {
            return false;
        }

        $current = is_array($result['current'] ?? null) ? $result['current'] : [];
        if (trim((string)($current['error'] ?? '')) !== '') {
            return true;
        }

        return strtolower((string)($current['status'] ?? '')) === 'error';
    }

    private static function isHistoryFailure($job): bool
    {
        if (!is_array($job)) {
            return false;
        }

        if (strtolower((string)($job['status'] ?? '')) === 'failed') {
            return trim((string)($job['errorMessage'] ?? $job['error_message'] ?? '')) !== '';
        }

        return false;
    }

    private static function sortedUniqueStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);
        return $result;
    }
}