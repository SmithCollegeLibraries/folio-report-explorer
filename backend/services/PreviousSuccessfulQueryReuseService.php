<?php

namespace app\services;

class PreviousSuccessfulQueryReuseService
{
    private const STRONG_MATCH_THRESHOLD = 90;

    /**
     * Find the best prior successful NL query candidate for reviewable SQL reuse.
     *
     * @param string $prompt Current user prompt.
     * @param string $dataSource Current data source, e.g. folio.
     * @param array $resolvedContext Deterministic context such as campus/domain.
     * @param array $jobs Query job rows or row-like arrays, newest first preferred.
     * @return array|null
     */
    public static function findStrongMatch(string $prompt, string $dataSource, array $resolvedContext, array $jobs): ?array
    {
        $matches = self::findStrongMatches($prompt, $dataSource, $resolvedContext, $jobs);
        return $matches[0] ?? null;
    }

    /** Shape all strong candidates so QueryMemoryService can decide trust. */
    public static function findStrongMatches(string $prompt, string $dataSource, array $resolvedContext, array $jobs): array
    {
        $matches = [];
        foreach ($jobs as $job) {
            $candidate = self::buildCandidate($prompt, $dataSource, $resolvedContext, $job);
            if ($candidate === null) {
                continue;
            }
            if ($candidate['score'] >= self::STRONG_MATCH_THRESHOLD) {
                $matches[] = $candidate;
            }
        }

        usort($matches, static function (array $left, array $right): int {
            return -self::compareCandidates($left, $right);
        });

        return array_map([self::class, 'stripInternalRanking'], $matches);
    }

    private static function buildCandidate(string $prompt, string $dataSource, array $resolvedContext, array $job): ?array
    {
        if (!self::isReusableJob($job, $dataSource)) {
            return null;
        }

        $metadata = self::decodeMetadata($job['metadata'] ?? null);
        $previousPrompt = self::extractPrompt($job, $metadata);
        if ($previousPrompt === '') {
            return null;
        }

        $score = self::scorePromptMatch($prompt, $previousPrompt);
        if ($score < self::STRONG_MATCH_THRESHOLD) {
            return null;
        }

        $matchReasons = ['completed_successfully', 'same_data_source'];
        $sql = (string)($job['sql_text'] ?? '');
        if (!self::contextMatches($resolvedContext, $metadata, 'campus', $previousPrompt, $sql)) {
            return null;
        }
        if (isset($resolvedContext['campus'])) {
            $matchReasons[] = 'same_campus';
        }

        if (!self::contextMatches($resolvedContext, $metadata, 'domain', $previousPrompt, $sql)) {
            return null;
        }
        if (isset($resolvedContext['domain'])) {
            $matchReasons[] = 'same_domain';
        }

        $candidate = [
            'jobId' => (string)($job['id'] ?? ''),
            'question' => $previousPrompt,
            'previousPrompt' => $previousPrompt,
            'sql' => $sql,
            'dataSource' => (string)($job['data_source'] ?? $job['dataSource'] ?? 'folio'),
            'score' => $score,
            'matchReasons' => $matchReasons,
            'rowCount' => isset($job['row_count']) ? (int)$job['row_count'] : null,
            'executionTimeMs' => isset($job['execution_time_ms']) ? (int)$job['execution_time_ms'] : null,
            'completedAt' => $job['completed_at'] ?? $job['completedAt'] ?? null,
            '_rankExactPrompt' => self::normalizePrompt($prompt) === self::normalizePrompt($previousPrompt) ? 1 : 0,
            '_rankCompletedAt' => self::timestampRank($job['completed_at'] ?? $job['completedAt'] ?? $job['created_at'] ?? null),
        ];

        $generationProvenance = self::extractGenerationProvenance($metadata);
        if ($generationProvenance !== null) {
            $candidate['generationProvenance'] = $generationProvenance;
            $candidate['provenanceLabel'] = $generationProvenance === 'verified_pattern'
                ? 'Verified pattern'
                : 'AI-built';
        }

        return $candidate;
    }

    private static function compareCandidates(array $left, array $right): int
    {
        foreach (['_rankExactPrompt', 'score', '_rankCompletedAt'] as $field) {
            $leftValue = (int)($left[$field] ?? 0);
            $rightValue = (int)($right[$field] ?? 0);
            if ($leftValue === $rightValue) {
                continue;
            }
            return $leftValue > $rightValue ? 1 : -1;
        }

        return 0;
    }

    private static function stripInternalRanking(array $candidate): array
    {
        unset($candidate['_rankExactPrompt'], $candidate['_rankCompletedAt']);
        return $candidate;
    }

    private static function timestampRank($value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : (int)$timestamp;
    }

    private static function isReusableJob(array $job, string $dataSource): bool
    {
        if (($job['status'] ?? null) !== 'completed') {
            return false;
        }
        if (($job['source'] ?? null) !== 'nl') {
            return false;
        }

        $jobDataSource = (string)($job['data_source'] ?? $job['dataSource'] ?? 'folio');
        if (strcasecmp($jobDataSource, $dataSource) !== 0) {
            return false;
        }
        if (!self::hasNoBoundParameters($job['params'] ?? null)) {
            return false;
        }

        return trim((string)($job['sql_text'] ?? '')) !== '';
    }

    private static function hasNoBoundParameters($params): bool
    {
        if ($params === null || $params === '') {
            return true;
        }
        if (is_array($params)) {
            return $params === [];
        }
        if (!is_string($params)) {
            return false;
        }

        $decoded = json_decode($params, true);
        return is_array($decoded) && $decoded === [];
    }

    private static function decodeMetadata($metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (!is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function extractPrompt(array $job, array $metadata): string
    {
        foreach (['originalPrompt', 'nlPrompt', 'originalName'] as $key) {
            $value = trim((string)($metadata[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string)($job['name'] ?? ''));
    }

    private static function extractGenerationProvenance(array $metadata): ?string
    {
        $askAiProvenance = $metadata['askAiProvenance'] ?? null;
        if (!is_array($askAiProvenance)) {
            return null;
        }

        $provenance = $askAiProvenance['provenance'] ?? null;
        if (!is_array($provenance)) {
            return null;
        }

        $generationProvenance = (string)($provenance['generationProvenance'] ?? '');
        return in_array($generationProvenance, ['verified_pattern', 'ai_built'], true)
            ? $generationProvenance
            : null;
    }

    private static function scorePromptMatch(string $currentPrompt, string $previousPrompt): int
    {
        $current = self::normalizePrompt($currentPrompt);
        $previous = self::normalizePrompt($previousPrompt);
        if ($current === '' || $previous === '') {
            return 0;
        }
        if ($current === $previous) {
            return 100;
        }

        similar_text($current, $previous, $percent);
        return (int)round($percent);
    }

    private static function normalizePrompt(string $prompt): string
    {
        $normalized = strtolower($prompt);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        $normalized = preg_replace('/\b(the|a|an|please|show|me|tell)\b/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', trim((string)$normalized));
        return $normalized;
    }

    private static function contextMatches(array $resolvedContext, array $metadata, string $key, string $previousPrompt, string $sql): bool
    {
        if (!isset($resolvedContext[$key]) || trim((string)$resolvedContext[$key]) === '') {
            return true;
        }

        $expected = self::normalizeContextValue((string)$resolvedContext[$key]);
        if ($expected === '') {
            return false;
        }

        $metadataContext = $metadata['resolvedContext'] ?? [];
        if (is_array($metadataContext)) {
            $actual = self::normalizeContextValue((string)($metadataContext[$key] ?? ''));
            if ($actual !== '') {
                return $expected === $actual;
            }
        }

        return self::legacyContextAppearsInPromptOrSql($expected, $previousPrompt, $sql);
    }

    private static function legacyContextAppearsInPromptOrSql(string $expected, string $previousPrompt, string $sql): bool
    {
        if (strpos(self::normalizeContextValue($previousPrompt), $expected) !== false) {
            return true;
        }

        $normalizedSql = self::normalizeContextValue($sql);
        if (strpos($normalizedSql, $expected) !== false) {
            return true;
        }

        if ($expected === 'smith college' && preg_match('/\bsc\b/u', $normalizedSql) === 1) {
            return true;
        }

        return false;
    }

    private static function normalizeContextValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        return (string)$normalized;
    }
}
