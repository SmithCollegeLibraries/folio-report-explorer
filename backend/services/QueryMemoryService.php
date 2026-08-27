<?php

namespace app\services;

require_once __DIR__ . '/FolioSchemaService.php';
require_once __DIR__ . '/SqlBuilderService.php';

/** Centralizes query-memory compatibility, explicit trust, and ranking. */
class QueryMemoryService
{
    private const TIER_VERIFIED = 5;
    private const TIER_ADMIN_APPROVED = 4;
    private const TIER_SAME_USER_ACCURATE = 3;
    private const TIER_OTHER_USER_ACCURATE = 2;
    private const TIER_NEUTRAL = 1;

    public static function directReuseSchemaFingerprint(array $schemaMetadata): string
    {
        return hash('sha256', self::canonicalJson([
            'version' => self::schemaVersion($schemaMetadata),
            'contextHash' => $schemaMetadata['contextHash'] ?? null,
        ]));
    }

    public static function currentDirectReuseSchemaFingerprint(string $prompt): string
    {
        $context = FolioSchemaService::buildSchemaContext($prompt);
        $metadata = FolioSchemaService::getMetadata();
        $metadata['contextHash'] = substr(hash('sha256', (string)$context), 0, 16);
        return self::directReuseSchemaFingerprint($metadata);
    }

    public static function schemaVersionFingerprint(array $schemaMetadata): string
    {
        return hash('sha256', self::canonicalJson([
            'version' => self::schemaVersion($schemaMetadata),
        ]));
    }

    public static function scopeFingerprint(string $dataSource, array $authorizedScope): string
    {
        return hash('sha256', self::canonicalJson([
            'dataSource' => strtolower(trim($dataSource)),
            'scope' => self::canonicalizeArray($authorizedScope, true),
        ]));
    }

    public static function findDirectReuse(array $request, array $candidates): ?array
    {
        $eligible = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || !self::directlyCompatible($request, $candidate)) {
                continue;
            }
            $trust = self::directReuseTrust($request, $candidate);
            if ($trust === null) {
                continue;
            }
            $candidate['reuseTrust'] = $trust;
            $candidate['_memoryTier'] = self::directTrustRank($trust);
            $candidate['_memoryCompletedAt'] = self::timestampRank($candidate['completedAt'] ?? $candidate['completed_at'] ?? null);
            $eligible[] = $candidate;
        }

        if ($eligible === []) {
            return null;
        }
        usort($eligible, [self::class, 'compareDirectCandidates']);
        $match = $eligible[0];
        unset($match['_memoryTier'], $match['_memoryCompletedAt']);
        return $match;
    }

    public static function selectAiExamples(
        array $request,
        array $candidates,
        int $limit = 3,
        int $byteLimit = 12000
    ): array {
        if ($limit <= 0 || $byteLimit <= 2) {
            return [];
        }

        $ranked = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || !self::exampleCompatible($request, $candidate)) {
                continue;
            }
            $tier = self::exampleTier($request, $candidate);
            if ($tier === null) {
                continue;
            }
            $candidate['_memoryTier'] = $tier['rank'];
            $candidate['_memoryTierName'] = $tier['name'];
            $candidate['_memorySimilarity'] = self::promptSimilarity(
                (string)($request['question'] ?? $request['normalizedQuestion'] ?? ''),
                (string)($candidate['question'] ?? $candidate['normalizedQuestion'] ?? '')
            );
            $candidate['_memoryWeakSignals'] = self::weakSignalCount($candidate);
            $candidate['_memoryCompletedAt'] = self::timestampRank($candidate['completedAt'] ?? $candidate['completed_at'] ?? null);
            $candidate['_memoryStableId'] = self::stableId($candidate);
            $ranked[] = $candidate;
        }
        usort($ranked, [self::class, 'compareExampleCandidates']);

        $selected = [];
        foreach ($ranked as $candidate) {
            if (count($selected) >= $limit) {
                break;
            }
            $trial = $selected;
            $trial[] = self::shapeExample($candidate);
            $encoded = json_encode($trial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false || strlen($encoded) > $byteLimit) {
                continue;
            }
            $selected = $trial;
        }
        return $selected;
    }

    private static function directlyCompatible(array $request, array $candidate): bool
    {
        if (!self::baseCompatible($request, $candidate)) {
            return false;
        }
        $requestQuestion = self::normalizeQuestion((string)($request['question'] ?? $request['normalizedQuestion'] ?? ''));
        $candidateQuestion = self::normalizeQuestion((string)($candidate['question'] ?? $candidate['normalizedQuestion'] ?? ''));
        if ($requestQuestion === '' || $requestQuestion !== $candidateQuestion) {
            return false;
        }
        return self::fingerprintsMatch(
            $request['directReuseSchemaFingerprint'] ?? null,
            $candidate['directReuseSchemaFingerprint'] ?? null
        );
    }

    private static function exampleCompatible(array $request, array $candidate): bool
    {
        return self::baseCompatible($request, $candidate)
            && self::fingerprintsMatch(
                $request['schemaVersionFingerprint'] ?? null,
                $candidate['schemaVersionFingerprint'] ?? null
            );
    }

    private static function baseCompatible(array $request, array $candidate): bool
    {
        if (!empty($candidate['reuseSuppressed'])) {
            return false;
        }
        if (strtolower(trim((string)($candidate['resultAccuracy'] ?? ''))) === 'inaccurate') {
            return false;
        }
        if (isset($candidate['status']) && strtolower(trim((string)$candidate['status'])) !== 'completed') {
            return false;
        }
        if (strcasecmp(
            trim((string)($request['dataSource'] ?? '')),
            trim((string)($candidate['dataSource'] ?? ''))
        ) !== 0) {
            return false;
        }
        if (!self::fingerprintsMatch($request['scopeFingerprint'] ?? null, $candidate['scopeFingerprint'] ?? null)) {
            return false;
        }
        return self::candidateSqlIsAllowed((string)($candidate['sql'] ?? $candidate['generatedSql'] ?? ''));
    }

    private static function directReuseTrust(array $request, array $candidate): ?string
    {
        $provenance = strtolower(trim((string)($candidate['generationProvenance'] ?? '')));
        if ($provenance === 'verified_pattern') {
            return 'verified_global';
        }
        if ($provenance !== 'ai_built') {
            return null;
        }
        if (strtolower(trim((string)($candidate['resultAccuracy'] ?? ''))) !== 'accurate') {
            return null;
        }
        if (self::hasAdministratorApproval($candidate)) {
            return 'administrator_approved';
        }
        $requestUserId = self::normalizedUserId($request['userId'] ?? null);
        $candidateUserId = self::normalizedUserId($candidate['userId'] ?? null);
        return $requestUserId !== null && $requestUserId === $candidateUserId
            ? 'same_user_accurate'
            : null;
    }

    private static function exampleTier(array $request, array $candidate): ?array
    {
        $provenance = strtolower(trim((string)($candidate['generationProvenance'] ?? '')));
        if ($provenance === 'verified_pattern') {
            return ['rank' => self::TIER_VERIFIED, 'name' => 'verified_pattern'];
        }
        if ($provenance !== 'ai_built') {
            return null;
        }

        $accuracy = strtolower(trim((string)($candidate['resultAccuracy'] ?? '')));
        if ($accuracy === 'accurate' && self::hasAdministratorApproval($candidate)) {
            return ['rank' => self::TIER_ADMIN_APPROVED, 'name' => 'administrator_approved'];
        }
        if ($accuracy === 'accurate') {
            $requestUserId = self::normalizedUserId($request['userId'] ?? null);
            $candidateUserId = self::normalizedUserId($candidate['userId'] ?? null);
            if ($requestUserId !== null && $requestUserId === $candidateUserId) {
                return ['rank' => self::TIER_SAME_USER_ACCURATE, 'name' => 'same_user_accurate'];
            }
            return ['rank' => self::TIER_OTHER_USER_ACCURATE, 'name' => 'other_user_accurate'];
        }
        if ($accuracy === '' || $accuracy === 'unsure') {
            return ['rank' => self::TIER_NEUTRAL, 'name' => 'neutral_success'];
        }
        return null;
    }

    private static function candidateSqlIsAllowed(string $sql): bool
    {
        if (trim($sql) === '') {
            return false;
        }
        try {
            SqlBuilderService::validateSafety($sql);
            SqlBuilderService::validateTablePolicy($sql);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private static function compareDirectCandidates(array $left, array $right): int
    {
        foreach (['_memoryTier', '_memoryCompletedAt'] as $field) {
            $comparison = ((int)($right[$field] ?? 0)) <=> ((int)($left[$field] ?? 0));
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return strcmp(self::stableId($left), self::stableId($right));
    }

    private static function compareExampleCandidates(array $left, array $right): int
    {
        foreach (['_memoryTier', '_memorySimilarity', '_memoryWeakSignals', '_memoryCompletedAt'] as $field) {
            $comparison = ((int)($right[$field] ?? 0)) <=> ((int)($left[$field] ?? 0));
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return strcmp((string)$left['_memoryStableId'], (string)$right['_memoryStableId']);
    }

    private static function shapeExample(array $candidate): array
    {
        return [
            'id' => self::stableId($candidate),
            'generationId' => $candidate['generationId'] ?? null,
            'question' => (string)($candidate['question'] ?? $candidate['normalizedQuestion'] ?? ''),
            'sql' => (string)($candidate['sql'] ?? $candidate['generatedSql'] ?? ''),
            'sqlHash' => $candidate['sqlHash'] ?? null,
            'generationProvenance' => (string)($candidate['generationProvenance'] ?? ''),
            'resultAccuracy' => $candidate['resultAccuracy'] ?? null,
            'rankTier' => (string)$candidate['_memoryTierName'],
            'schemaVersionFingerprint' => (string)($candidate['schemaVersionFingerprint'] ?? ''),
            'scopeFingerprint' => (string)($candidate['scopeFingerprint'] ?? ''),
        ];
    }

    private static function directTrustRank(string $trust): int
    {
        if ($trust === 'verified_global') {
            return self::TIER_VERIFIED;
        }
        if ($trust === 'administrator_approved') {
            return self::TIER_ADMIN_APPROVED;
        }
        return self::TIER_SAME_USER_ACCURATE;
    }

    private static function hasAdministratorApproval(array $candidate): bool
    {
        return trim((string)($candidate['adminReuseApprovedAt'] ?? '')) !== '';
    }

    private static function weakSignalCount(array $candidate): int
    {
        return max(0, (int)($candidate['savedCount'] ?? 0))
            + max(0, (int)($candidate['downloadedCount'] ?? 0))
            + max(0, (int)($candidate['rerunCount'] ?? 0))
            + max(0, (int)($candidate['followUpCount'] ?? 0));
    }

    private static function stableId(array $candidate): string
    {
        foreach (['id', 'generationId', 'jobId'] as $key) {
            $value = trim((string)($candidate[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return hash('sha256', (string)($candidate['sql'] ?? $candidate['generatedSql'] ?? ''));
    }

    private static function promptSimilarity(string $left, string $right): int
    {
        $left = self::normalizeQuestion($left);
        $right = self::normalizeQuestion($right);
        if ($left === '' || $right === '') {
            return 0;
        }
        if ($left === $right) {
            return 100;
        }
        similar_text($left, $right, $percentage);
        return (int)round($percentage);
    }

    private static function normalizeQuestion(string $question): string
    {
        $normalized = strtolower($question);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        $normalized = preg_replace('/\b(the|a|an|please|show|me|tell)\b/u', ' ', (string)$normalized);
        return (string)preg_replace('/\s+/u', ' ', trim((string)$normalized));
    }

    private static function canonicalJson(array $value): string
    {
        $encoded = json_encode(
            self::canonicalizeArray($value, false),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($encoded === false) {
            throw new \InvalidArgumentException('Unable to canonicalize query-memory fingerprint input.');
        }
        return $encoded;
    }

    private static function canonicalizeArray(array $value, bool $sortLists): array
    {
        $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalizeArray($item, $sortLists);
            } elseif ($sortLists && is_string($item)) {
                $value[$key] = preg_replace('/\s+/u', ' ', trim($item));
            }
        }
        if ($isList && $sortLists) {
            usort($value, static function ($left, $right): int {
                return strcmp(
                    (string)json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    (string)json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            });
        }
        return $value;
    }

    private static function fingerprintsMatch($left, $right): bool
    {
        $left = trim((string)$left);
        $right = trim((string)$right);
        return $left !== '' && $right !== '' && hash_equals($left, $right);
    }

    private static function schemaVersion(array $metadata)
    {
        return $metadata['version'] ?? $metadata['scraped_at'] ?? null;
    }

    private static function normalizedUserId($value): ?string
    {
        return $value === null || $value === '' ? null : (string)$value;
    }

    private static function timestampRank($value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : (int)$timestamp;
    }
}
