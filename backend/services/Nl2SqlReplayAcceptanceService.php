<?php

namespace app\services;

class Nl2SqlReplayAcceptanceService
{
    const DEFAULT_MAX_PROMPT_QUALITY_FAILURES = 0;
    const DEFAULT_MAX_NEW_SEMANTIC_FAILURE_FAMILIES = 0;
    const DEFAULT_MAX_PROMPT_SIZE_INCREASE_BYTES = 10000;
    const DEFAULT_MAX_PROMPT_BUDGET_BREACHES = 0;

    public static function buildAcceptanceReview(
        array $promptCatalog,
        array $currentArtifact,
        array $currentTelemetryEvents,
        ?array $previousArtifact = null,
        array $previousTelemetryEvents = [],
        array $thresholds = []
    ): array {
        $currentQuality = self::evaluatePromptQuality($promptCatalog, $currentArtifact);
        $currentReplayReview = self::buildReplayFailureReview($currentArtifact);

        $previousReplayReview = [
            'summary' => [
                'telemetryEventCount' => 0,
                'replayArtifactCount' => 0,
                'replayFailureCount' => 0,
                'historyFailureCount' => 0,
                'familyCount' => 0,
            ],
            'families' => [],
        ];
        if (is_array($previousArtifact)) {
            $previousReplayReview = self::buildReplayFailureReview($previousArtifact);
        }

        $currentPromptSizes = self::buildPromptSizeSummary($promptCatalog, $currentArtifact, $currentTelemetryEvents);
        $previousPromptSizes = self::resolvePreviousPromptSizeSummary($promptCatalog, $previousArtifact, $previousTelemetryEvents);
        $currentPromptBudget = self::buildPromptBudgetSummary($promptCatalog, $currentArtifact, $currentTelemetryEvents);

        $familyDelta = self::buildFailureFamilyDelta($currentReplayReview, $previousReplayReview);
        $promptSizeDelta = self::buildPromptSizeDelta($currentPromptSizes['byPromptId'], $previousPromptSizes['byPromptId']);

        $resolvedThresholds = self::resolveThresholds($thresholds);
        $gates = self::evaluateQualityGates(
            $currentQuality['summary'],
            $familyDelta,
            $promptSizeDelta,
            $currentPromptBudget,
            $resolvedThresholds
        );

        return [
            'quality' => $currentQuality,
            'shapeExpectations' => self::buildShapeExpectationReview($promptCatalog, $currentArtifact),
            'failureFamilies' => [
                'current' => $currentReplayReview,
                'previous' => $previousReplayReview,
                'delta' => $familyDelta,
            ],
            'promptSize' => [
                'current' => $currentPromptSizes,
                'previous' => $previousPromptSizes,
                'delta' => $promptSizeDelta,
            ],
            'promptBudget' => [
                'current' => $currentPromptBudget,
            ],
            'gates' => $gates,
        ];
    }

    private static function evaluatePromptQuality(array $promptCatalog, array $artifact): array
    {
        $families = [];
        $byPromptId = [];
        $evaluatedPromptCount = 0;
        $promptQualityFailureCount = 0;
        $results = $artifact['results'] ?? [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $promptId = (string)($result['id'] ?? '');
            $prompt = $promptCatalog[$promptId] ?? null;
            if (!is_array($prompt)) {
                continue;
            }

            $acceptance = self::promptAcceptanceConfig($prompt, $promptCatalog);
            if (empty($acceptance)) {
                continue;
            }

            $evaluatedPromptCount++;
            $checks = self::buildPromptChecks($promptId, $prompt, $result, $acceptance);
            $failedChecks = array_values(array_filter($checks, function (array $check): bool {
                return empty($check['passed']);
            }));

            $byPromptId[$promptId] = [
                'prompt' => (string)($prompt['prompt'] ?? ''),
                'shapeExpectationIds' => array_values($acceptance['shapeExpectationIds'] ?? []),
                'checks' => $checks,
                'failedCheckCount' => count($failedChecks),
            ];

            if ($failedChecks) {
                $promptQualityFailureCount += count($failedChecks);
                foreach ($failedChecks as $check) {
                    self::recordQualityFamily($families, $check, $promptId, (string)($prompt['prompt'] ?? ''), $result);
                }
            }
        }

        return [
            'summary' => [
                'evaluatedPromptCount' => $evaluatedPromptCount,
                'promptQualityFailureCount' => $promptQualityFailureCount,
                'familyCount' => count($families),
            ],
            'byPromptId' => $byPromptId,
            'families' => self::sortQualityFamilies($families),
        ];
    }

    private static function buildPromptChecks(string $promptId, array $prompt, array $result, array $acceptance): array
    {
        $checks = [];
        $current = is_array($result['current'] ?? null) ? $result['current'] : [];
        $execution = is_array($result['execution'] ?? null) ? $result['execution'] : [];
        $currentStatus = strtolower((string)($current['status'] ?? ''));
        $sql = strtolower((string)($current['sql'] ?? ''));
        $dataSource = strtolower((string)($current['dataSource'] ?? ''));
        $route = strtolower((string)($current['route'] ?? ''));

        if ($currentStatus !== '' && $currentStatus !== 'success') {
            $checks[] = [
                'key' => 'current_status_error',
                'passed' => false,
                'message' => 'Current replay response returned status ' . $currentStatus . ': ' . (string)($current['error'] ?? 'Unknown replay error') . '.',
            ];

            return $checks;
        }

        if (!empty($acceptance['expectedRoute'])) {
            $expectedRoute = strtolower((string)$acceptance['expectedRoute']);
            $checks[] = [
                'key' => 'expected_route',
                'passed' => $expectedRoute !== '' && $expectedRoute === $route,
                'message' => 'Expected route ' . $expectedRoute . ' but got ' . ($route === '' ? 'none' : $route) . '.',
            ];
        }

        if (!empty($acceptance['expectedClarificationType'])) {
            $expectedClarificationType = strtolower((string)$acceptance['expectedClarificationType']);
            $actualClarificationType = strtolower((string)($current['clarificationType'] ?? ''));
            $checks[] = [
                'key' => 'expected_clarification_type',
                'passed' => $expectedClarificationType !== '' && $expectedClarificationType === $actualClarificationType,
                'message' => 'Expected clarification type ' . $expectedClarificationType . ' but got ' . ($actualClarificationType === '' ? 'none' : $actualClarificationType) . '.',
            ];
        }

        if (!empty($acceptance['expectedClarificationQuestion'])) {
            $expectedClarificationQuestion = trim((string)$acceptance['expectedClarificationQuestion']);
            $actualClarificationQuestion = trim((string)($current['question'] ?? ''));
            $checks[] = [
                'key' => 'expected_clarification_question',
                'passed' => $expectedClarificationQuestion !== '' && $expectedClarificationQuestion === $actualClarificationQuestion,
                'message' => 'Expected clarification question ' . ($expectedClarificationQuestion === '' ? 'none' : $expectedClarificationQuestion) . ' but got ' . ($actualClarificationQuestion === '' ? 'none' : $actualClarificationQuestion) . '.',
            ];
        }

        $expectedClarificationOptions = self::normalizeClarificationOptions($acceptance['expectedClarificationOptions'] ?? []);
        if ($expectedClarificationOptions !== []) {
            $actualClarificationOptions = self::resolveClarificationOptions($current['options'] ?? null);
            $checks[] = [
                'key' => 'expected_clarification_options',
                'passed' => $actualClarificationOptions === $expectedClarificationOptions,
                'message' => 'Expected clarification options [' . implode(', ', $expectedClarificationOptions) . '] but got [' . implode(', ', $actualClarificationOptions) . '].',
                'expectedOptions' => $expectedClarificationOptions,
                'actualOptions' => $actualClarificationOptions,
            ];
        }

        $expectedMissingSlots = self::normalizeStringList($acceptance['expectedMissingSlots'] ?? []);
        if ($expectedMissingSlots !== []) {
            $actualMissingSlots = self::normalizeStringList($current['missingSlots'] ?? []);
            $checks[] = [
                'key' => 'expected_missing_slots',
                'passed' => $actualMissingSlots === $expectedMissingSlots,
                'message' => 'Expected missing slots [' . implode(', ', $expectedMissingSlots) . '] but got [' . implode(', ', $actualMissingSlots) . '].',
                'expectedMissingSlots' => $expectedMissingSlots,
                'actualMissingSlots' => $actualMissingSlots,
            ];
        }

        if (!empty($acceptance['expectedDataSource'])) {
            $expected = strtolower((string)$acceptance['expectedDataSource']);
            $checks[] = [
                'key' => 'expected_data_source',
                'passed' => $expected !== '' && $expected === $dataSource,
                'message' => 'Expected data source ' . $expected . ' but got ' . ($dataSource === '' ? 'none' : $dataSource) . '.',
            ];
        }

        foreach (($acceptance['disallowedSqlPatterns'] ?? []) as $pattern) {
            $pattern = strtolower(trim((string)$pattern));
            if ($pattern === '') {
                continue;
            }

            $checks[] = [
                'key' => 'disallowed_sql_pattern_matched',
                'passed' => $sql === '' || strpos($sql, $pattern) === false,
                'message' => 'Generated SQL matched disallowed pattern: ' . $pattern . '.',
                'token' => $pattern,
            ];
        }

        foreach (($acceptance['requiredSqlPatterns'] ?? []) as $pattern) {
            $pattern = strtolower(trim((string)$pattern));
            if ($pattern === '') {
                continue;
            }

            $checks[] = [
                'key' => 'required_sql_pattern_missing',
                'passed' => $sql !== '' && strpos($sql, $pattern) !== false,
                'message' => 'Generated SQL did not include required pattern: ' . $pattern . '.',
                'token' => $pattern,
            ];
        }

        $missingShapePatterns = [];
        foreach (($acceptance['requiredSqlShapePatterns'] ?? []) as $pattern) {
            $pattern = strtolower(trim((string)$pattern));
            if ($pattern === '') {
                continue;
            }

            if ($sql === '' || strpos($sql, $pattern) === false) {
                $missingShapePatterns[] = $pattern;
            }
        }
        if ($missingShapePatterns) {
            $checks[] = [
                'key' => 'required_sql_shape_pattern_missing',
                'passed' => false,
                'message' => 'Generated SQL did not include required shape patterns: ' . implode(', ', $missingShapePatterns) . '.',
                'tokens' => $missingShapePatterns,
                'shapeExpectationIds' => array_values($acceptance['shapeExpectationIds'] ?? []),
            ];
        }

        $matchedDisallowedShapePatterns = [];
        foreach (($acceptance['disallowedSqlShapePatterns'] ?? []) as $pattern) {
            $pattern = strtolower(trim((string)$pattern));
            if ($pattern === '') {
                continue;
            }

            if ($sql !== '' && strpos($sql, $pattern) !== false) {
                $matchedDisallowedShapePatterns[] = $pattern;
            }
        }
        if ($matchedDisallowedShapePatterns) {
            $checks[] = [
                'key' => 'disallowed_sql_shape_pattern_matched',
                'passed' => false,
                'message' => 'Generated SQL matched disallowed shape patterns: ' . implode(', ', $matchedDisallowedShapePatterns) . '.',
                'tokens' => $matchedDisallowedShapePatterns,
                'shapeExpectationIds' => array_values($acceptance['shapeExpectationIds'] ?? []),
            ];
        }

        if (isset($acceptance['expectedMinRows'])) {
            $minimum = (int)$acceptance['expectedMinRows'];
            $rowCount = isset($execution['rowCount']) ? (int)$execution['rowCount'] : null;
            $status = strtolower((string)($execution['status'] ?? ''));

            $checks[] = [
                'key' => 'expected_min_rows_not_met',
                'passed' => $status === 'success' && $rowCount !== null && $rowCount >= $minimum,
                'message' => 'Expected at least ' . $minimum . ' rows but execution returned ' . ($rowCount === null ? 'none' : $rowCount) . '.',
                'expectedMinRows' => $minimum,
                'rowCount' => $rowCount,
            ];
        }
        return $checks;
    }

    private static function normalizeClarificationOptions($options): array
    {
        return self::normalizeStringList($options);
    }

    private static function resolveClarificationOptions($options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $resolved = [];
        foreach ($options as $option) {
            if (is_string($option) || is_numeric($option)) {
                $optionValue = strtolower(trim((string)$option));
                if ($optionValue !== '') {
                    $resolved[] = $optionValue;
                }
                continue;
            }

            if (!is_array($option)) {
                continue;
            }

            $optionValue = strtolower(trim((string)($option['label'] ?? $option['value'] ?? $option['id'] ?? '')));
            if ($optionValue === '') {
                continue;
            }

            $resolved[] = $optionValue;
        }

        return array_values(array_unique($resolved));
    }

    private static function normalizeStringList($values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $value = strtolower(trim((string)$value));
            if ($value === '') {
                continue;
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private static function promptAcceptanceConfig(array $prompt, array $promptCatalog): array
    {
        $acceptance = $prompt['acceptance'] ?? [];
        if (!is_array($acceptance) || $acceptance === []) {
            return [];
        }

        $shapeExpectationIds = self::resolvePromptShapeExpectationIds($acceptance);
        $shapeCatalog = self::shapeExpectationCatalog($promptCatalog);
        $requiredShapePatterns = [];
        $disallowedShapePatterns = [];

        foreach ($shapeExpectationIds as $expectationId) {
            $expectation = $shapeCatalog[$expectationId] ?? null;
            if (!is_array($expectation)) {
                continue;
            }

            foreach (($expectation['mustIncludeSqlPatterns'] ?? []) as $pattern) {
                $requiredShapePatterns[] = (string)$pattern;
            }
            foreach (($expectation['mustExcludeSqlPatterns'] ?? []) as $pattern) {
                $disallowedShapePatterns[] = (string)$pattern;
            }
        }

        $acceptance['shapeExpectationIds'] = $shapeExpectationIds;
        $acceptance['requiredSqlShapePatterns'] = self::sortedUniqueStrings($requiredShapePatterns);
        $acceptance['disallowedSqlShapePatterns'] = self::sortedUniqueStrings($disallowedShapePatterns);

        return $acceptance;
    }

    private static function recordQualityFamily(array &$families, array $check, string $promptId, string $promptText, array $result): void
    {
        $key = (string)($check['key'] ?? 'quality_check_failed');
        if (!isset($families[$key])) {
            $families[$key] = [
                'title' => self::qualityFamilyTitle($key),
                'action' => self::qualityFamilyAction($key),
                'occurrenceCount' => 0,
                'promptIds' => [],
                'tokens' => [],
                'evidence' => [],
            ];
        }

        $families[$key]['occurrenceCount']++;
        $families[$key]['promptIds'][] = $promptId;
        if (!empty($check['token'])) {
            $families[$key]['tokens'][] = (string)$check['token'];
        }
        foreach (($check['tokens'] ?? []) as $token) {
            $token = (string)$token;
            if ($token !== '') {
                $families[$key]['tokens'][] = $token;
            }
        }
        if (count($families[$key]['evidence']) < 3) {
            $families[$key]['evidence'][] = [
                'promptId' => $promptId,
                'prompt' => $promptText,
                'message' => (string)($check['message'] ?? ''),
                'status' => (string)(($result['current']['status'] ?? '') ?: ''),
                'shapeExpectationIds' => array_values($check['shapeExpectationIds'] ?? []),
            ];
        }

        $families[$key]['promptIds'] = self::sortedUniqueStrings($families[$key]['promptIds']);
        $families[$key]['tokens'] = self::sortedUniqueStrings($families[$key]['tokens']);
    }

    private static function qualityFamilyTitle(string $key): string
    {
        switch ($key) {
            case 'current_status_error':
                return 'Current Replay Status Error';
            case 'expected_route':
                return 'Expected Route Mismatch';
            case 'expected_data_source':
                return 'Expected Data Source Mismatch';
            case 'disallowed_sql_pattern_matched':
                return 'Disallowed SQL Pattern Matched';
            case 'required_sql_pattern_missing':
                return 'Required SQL Pattern Missing';
            case 'required_sql_shape_pattern_missing':
                return 'Required SQL Shape Pattern Missing';
            case 'disallowed_sql_shape_pattern_matched':
                return 'Disallowed SQL Shape Pattern Matched';
            case 'expected_min_rows_not_met':
                return 'Expected Minimum Rows Not Met';
            default:
                return 'Prompt Quality Check Failed';
        }
    }

    private static function qualityFamilyAction(string $key): string
    {
        switch ($key) {
            case 'current_status_error':
                return 'Resolve provider, model, or runtime availability errors before treating this replay prompt as a semantic routing or SQL-shape failure.';
            case 'expected_route':
                return 'Tighten family routing, clarification handling, or fallback policy so covered prompts resolve through the expected NL path.';
            case 'expected_data_source':
                return 'Review routing or prompt guidance when replay prompts resolve to the wrong data source.';
            case 'disallowed_sql_pattern_matched':
                return 'Add canonical patterns or safety guidance so these prompts stop generating blocked or banned SQL constructs.';
            case 'required_sql_pattern_missing':
                return 'Strengthen canonical patterns so required joins or filters appear consistently in generated SQL.';
            case 'required_sql_shape_pattern_missing':
                return 'Promote the missing joins or semantic paths into replay shape expectations or restore the deterministic compiler/intent path for this prompt family.';
            case 'disallowed_sql_shape_pattern_matched':
                return 'Remove the wrong semantic path from generated SQL and tighten replay shape expectations so the regression is caught immediately.';
            case 'expected_min_rows_not_met':
                return 'Execute the generated SQL and review overly restrictive predicates when prompts that should return data come back empty.';
            default:
                return 'Inspect failed prompt-quality checks and promote repeatable fixes into replay expectations or semantic context.';
        }
    }

    private static function buildShapeExpectationReview(array $promptCatalog, array $artifact): array
    {
        $byPromptId = [];
        foreach (($artifact['results'] ?? []) as $result) {
            if (!is_array($result) || empty($result['id'])) {
                continue;
            }

            $promptId = (string)$result['id'];
            $prompt = $promptCatalog[$promptId] ?? null;
            if (!is_array($prompt)) {
                continue;
            }

            $shapeExpectationIds = self::resolvePromptShapeExpectationIds($prompt['acceptance'] ?? []);
            if ($shapeExpectationIds) {
                $byPromptId[$promptId] = $shapeExpectationIds;
            }
        }

        ksort($byPromptId, SORT_STRING);

        return [
            'version' => self::shapeExpectationVersion($promptCatalog),
            'catalog' => self::shapeExpectationCatalog($promptCatalog),
            'byPromptId' => $byPromptId,
        ];
    }

    private static function shapeExpectationCatalog(array $promptCatalog): array
    {
        $catalog = $promptCatalog['__shapeExpectations'] ?? [];
        if (!is_array($catalog)) {
            return [];
        }

        ksort($catalog, SORT_STRING);
        return $catalog;
    }

    private static function shapeExpectationVersion(array $promptCatalog): int
    {
        return (int)($promptCatalog['__shapeExpectationsVersion'] ?? 0);
    }

    private static function resolvePromptShapeExpectationIds($acceptance): array
    {
        if (!is_array($acceptance)) {
            return [];
        }

        return self::sortedUniqueStrings($acceptance['shapeExpectationIds'] ?? []);
    }

    private static function sortQualityFamilies(array $families): array
    {
        uasort($families, function (array $left, array $right): int {
            $countCompare = ($right['occurrenceCount'] ?? 0) <=> ($left['occurrenceCount'] ?? 0);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp((string)($left['title'] ?? ''), (string)($right['title'] ?? ''));
        });

        return $families;
    }

    private static function buildReplayFailureReview(array $artifact): array
    {
        if (empty($artifact)) {
            return [
                'summary' => [
                    'telemetryEventCount' => 0,
                    'replayArtifactCount' => 0,
                    'replayFailureCount' => 0,
                    'historyFailureCount' => 0,
                    'familyCount' => 0,
                ],
                'families' => [],
            ];
        }

        return Nl2SqlFailureReviewService::buildReview([], [$artifact], []);
    }

    private static function buildFailureFamilyDelta(array $currentReview, array $previousReview): array
    {
        $currentFamilies = $currentReview['families'] ?? [];
        $previousFamilies = $previousReview['families'] ?? [];

        $currentKeys = array_keys($currentFamilies);
        $previousKeys = array_keys($previousFamilies);

        $newFamilyKeys = array_values(array_diff($currentKeys, $previousKeys));
        sort($newFamilyKeys, SORT_STRING);
        $resolvedFamilyKeys = array_values(array_diff($previousKeys, $currentKeys));
        sort($resolvedFamilyKeys, SORT_STRING);

        $newNonCapacityFamilyCount = 0;
        foreach ($newFamilyKeys as $familyKey) {
            $category = strtolower((string)($currentFamilies[$familyKey]['category'] ?? ''));
            if ($category !== 'capacity') {
                $newNonCapacityFamilyCount++;
            }
        }

        return [
            'newFamilyKeys' => $newFamilyKeys,
            'resolvedFamilyKeys' => $resolvedFamilyKeys,
            'newNonCapacityFamilyCount' => $newNonCapacityFamilyCount,
        ];
    }

    private static function buildPromptBudgetSummary(array $promptCatalog, array $artifact, array $telemetryEvents): array
    {
        $latestByPromptId = self::buildLatestTelemetryByPromptId($promptCatalog, $artifact, $telemetryEvents);

        $overBudgetPromptIds = [];
        $breachedSectionsByPromptId = [];
        $byPromptId = [];

        foreach ($latestByPromptId as $promptId => $entry) {
            $breachedSections = self::sortedUniqueStrings($entry['promptBudgetBreachedSections'] ?? []);
            $withinBudget = array_key_exists('promptBudgetWithinLimit', $entry)
                ? (bool)$entry['promptBudgetWithinLimit']
                : empty($breachedSections);

            $byPromptId[$promptId] = [
                'withinBudget' => $withinBudget,
                'totalBytes' => isset($entry['promptBudgetTotalBytes']) && is_numeric($entry['promptBudgetTotalBytes'])
                    ? (int)$entry['promptBudgetTotalBytes']
                    : null,
                'maxBytes' => isset($entry['promptBudgetMaxBytes']) && is_numeric($entry['promptBudgetMaxBytes'])
                    ? (int)$entry['promptBudgetMaxBytes']
                    : null,
                'breachedSections' => $breachedSections,
            ];

            if (!$withinBudget || !empty($breachedSections)) {
                $overBudgetPromptIds[] = $promptId;
                $breachedSectionsByPromptId[$promptId] = $breachedSections;
            }
        }

        $overBudgetPromptIds = self::sortedUniqueStrings($overBudgetPromptIds);
        ksort($byPromptId, SORT_STRING);
        ksort($breachedSectionsByPromptId, SORT_STRING);

        return [
            'measuredPromptCount' => count($byPromptId),
            'overBudgetPromptIds' => $overBudgetPromptIds,
            'breachedSectionsByPromptId' => $breachedSectionsByPromptId,
            'byPromptId' => $byPromptId,
        ];
    }

    private static function buildLatestTelemetryByPromptId(array $promptCatalog, array $artifact, array $telemetryEvents): array
    {
        $fingerprintToPromptId = [];
        foreach ($promptCatalog as $promptId => $prompt) {
            if (!is_array($prompt) || empty($prompt['prompt'])) {
                continue;
            }

            $fingerprintToPromptId[self::fingerprintPrompt((string)$prompt['prompt'])] = (string)$promptId;
        }

        $resultPromptIds = [];
        foreach (($artifact['results'] ?? []) as $result) {
            if (!is_array($result) || empty($result['id'])) {
                continue;
            }

            $resultPromptIds[(string)$result['id']] = true;
        }

        $latestByPromptId = [];
        foreach ($telemetryEvents as $event) {
            if (!is_array($event)) {
                continue;
            }

            $fingerprint = (string)($event['promptFingerprint'] ?? '');
            if ($fingerprint === '' || !isset($fingerprintToPromptId[$fingerprint])) {
                continue;
            }

            $promptId = $fingerprintToPromptId[$fingerprint];
            if (!isset($resultPromptIds[$promptId])) {
                continue;
            }

            $timestamp = (string)($event['timestamp'] ?? '');
            if (!isset($latestByPromptId[$promptId]) || strcmp($timestamp, (string)$latestByPromptId[$promptId]['timestamp']) >= 0) {
                $event['timestamp'] = $timestamp;
                $latestByPromptId[$promptId] = $event;
            }
        }

        ksort($latestByPromptId, SORT_STRING);
        return $latestByPromptId;
    }

    private static function buildPromptSizeSummary(array $promptCatalog, array $artifact, array $telemetryEvents): array
    {
        $latestByPromptId = self::buildLatestTelemetryByPromptId($promptCatalog, $artifact, $telemetryEvents);

        $byPromptId = [];
        foreach ($latestByPromptId as $promptId => $entry) {
            if (!isset($entry['schemaContextBytes']) || !is_numeric($entry['schemaContextBytes'])) {
                continue;
            }
            $byPromptId[$promptId] = (int)$entry['schemaContextBytes'];
        }
        ksort($byPromptId, SORT_STRING);

        $values = array_values($byPromptId);
        $measuredCount = count($values);

        return [
            'measuredPromptCount' => $measuredCount,
            'minBytes' => $measuredCount > 0 ? min($values) : null,
            'maxBytes' => $measuredCount > 0 ? max($values) : null,
            'avgBytes' => $measuredCount > 0 ? (int)floor(array_sum($values) / $measuredCount) : null,
            'byPromptId' => $byPromptId,
        ];
    }

    private static function resolvePreviousPromptSizeSummary(array $promptCatalog, ?array $previousArtifact, array $previousTelemetryEvents): array
    {
        if (!empty($previousTelemetryEvents)) {
            return self::buildPromptSizeSummary($promptCatalog, $previousArtifact ?? [], $previousTelemetryEvents);
        }

        $embedded = $previousArtifact['acceptanceReview']['promptSize']['current'] ?? null;
        if (!is_array($embedded)) {
            return self::buildPromptSizeSummary($promptCatalog, $previousArtifact ?? [], []);
        }

        return [
            'measuredPromptCount' => (int)($embedded['measuredPromptCount'] ?? count($embedded['byPromptId'] ?? [])),
            'minBytes' => isset($embedded['minBytes']) ? (int)$embedded['minBytes'] : null,
            'maxBytes' => isset($embedded['maxBytes']) ? (int)$embedded['maxBytes'] : null,
            'avgBytes' => isset($embedded['avgBytes']) ? (int)$embedded['avgBytes'] : null,
            'byPromptId' => is_array($embedded['byPromptId'] ?? null) ? $embedded['byPromptId'] : [],
        ];
    }

    private static function buildPromptSizeDelta(array $currentByPromptId, array $previousByPromptId): array
    {
        $maxIncreaseBytes = null;
        $increasedPromptIds = [];
        $byPromptId = [];

        foreach ($currentByPromptId as $promptId => $currentBytes) {
            if (!isset($previousByPromptId[$promptId])) {
                continue;
            }

            $delta = (int)$currentBytes - (int)$previousByPromptId[$promptId];
            $byPromptId[$promptId] = $delta;
            if ($delta > 0) {
                $increasedPromptIds[] = $promptId;
                if ($maxIncreaseBytes === null || $delta > $maxIncreaseBytes) {
                    $maxIncreaseBytes = $delta;
                }
            }
        }

        sort($increasedPromptIds, SORT_STRING);
        ksort($byPromptId, SORT_STRING);

        return [
            'maxIncreaseBytes' => $maxIncreaseBytes,
            'increasedPromptIds' => $increasedPromptIds,
            'byPromptId' => $byPromptId,
        ];
    }

    private static function resolveThresholds(array $thresholds): array
    {
        return [
            'maxPromptQualityFailures' => isset($thresholds['maxPromptQualityFailures'])
                ? (int)$thresholds['maxPromptQualityFailures']
                : self::DEFAULT_MAX_PROMPT_QUALITY_FAILURES,
            'maxNewSemanticFailureFamilies' => isset($thresholds['maxNewSemanticFailureFamilies'])
                ? (int)$thresholds['maxNewSemanticFailureFamilies']
                : self::DEFAULT_MAX_NEW_SEMANTIC_FAILURE_FAMILIES,
            'maxPromptSizeIncreaseBytes' => isset($thresholds['maxPromptSizeIncreaseBytes'])
                ? (int)$thresholds['maxPromptSizeIncreaseBytes']
                : self::DEFAULT_MAX_PROMPT_SIZE_INCREASE_BYTES,
            'maxPromptBudgetBreaches' => isset($thresholds['maxPromptBudgetBreaches'])
                ? (int)$thresholds['maxPromptBudgetBreaches']
                : self::DEFAULT_MAX_PROMPT_BUDGET_BREACHES,
        ];
    }

    private static function evaluateQualityGates(array $qualitySummary, array $familyDelta, array $promptSizeDelta, array $promptBudgetCurrent, array $thresholds): array
    {
        $failedGateKeys = [];

        if (($qualitySummary['promptQualityFailureCount'] ?? 0) > $thresholds['maxPromptQualityFailures']) {
            $failedGateKeys[] = 'prompt_quality';
        }

        if (($familyDelta['newNonCapacityFamilyCount'] ?? 0) > $thresholds['maxNewSemanticFailureFamilies']) {
            $failedGateKeys[] = 'semantic_family_delta';
        }

        $maxIncreaseBytes = $promptSizeDelta['maxIncreaseBytes'] ?? null;
        if ($maxIncreaseBytes !== null && $maxIncreaseBytes > $thresholds['maxPromptSizeIncreaseBytes']) {
            $failedGateKeys[] = 'prompt_size';
        }

        if (count($promptBudgetCurrent['overBudgetPromptIds'] ?? []) > $thresholds['maxPromptBudgetBreaches']) {
            $failedGateKeys[] = 'prompt_budget';
        }

        return [
            'thresholds' => $thresholds,
            'met' => empty($failedGateKeys),
            'failedGateKeys' => $failedGateKeys,
        ];
    }

    private static function fingerprintPrompt(string $prompt): string
    {
        return substr(hash('sha256', trim($prompt)), 0, 16);
    }

    private static function sortedUniqueStrings(array $values): array
    {
        $filtered = array_values(array_filter(array_map('strval', $values), function (string $value): bool {
            return trim($value) !== '';
        }));
        $unique = array_values(array_unique($filtered));
        sort($unique, SORT_STRING);
        return $unique;
    }
}