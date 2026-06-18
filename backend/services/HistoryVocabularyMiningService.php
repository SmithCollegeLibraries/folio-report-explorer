<?php

namespace app\services;

class HistoryVocabularyMiningService
{
    const DEFAULT_MIN_SUPPORT = 2;
    const MAX_EVIDENCE_PROMPTS = 3;
    const MAX_NGRAM_WORDS = 3;

    public static function buildReport(array $historyJobs, array $existingVocabulary, array $options = []): array
    {
        $minSupport = isset($options['minSupport']) ? max(1, (int)$options['minSupport']) : self::DEFAULT_MIN_SUPPORT;
        $vocabulary = self::normalizeVocabulary($existingVocabulary);

        $jobCount = count($historyJobs);
        $eligibleJobs = [];
        $candidates = [];

        foreach ($historyJobs as $job) {
            if (!self::isEligibleJob($job)) {
                continue;
            }

            $prompt = self::extractPromptText($job);
            $sql = trim((string)($job['sql_text'] ?? $job['sql'] ?? ''));
            if ($prompt === '' || $sql === '') {
                continue;
            }

            $eligibleJobs[] = $job;
            $tableRefs = self::extractSqlTableRefs($sql);
            $phrases = self::extractPromptPhrases($prompt);

            foreach ($vocabulary as $termKey => $entry) {
                if (!self::vocabularyTouchesSql($entry, $sql, $tableRefs)) {
                    continue;
                }

                $bestPhrase = self::selectBestAliasPhrase($phrases, $entry);
                if ($bestPhrase === null) {
                    continue;
                }

                $candidateKey = $termKey . '|' . $bestPhrase;
                if (!isset($candidates[$candidateKey])) {
                    $candidates[$candidateKey] = [
                        'alias' => $bestPhrase,
                        'canonicalTerm' => $entry['term'],
                        'mapping' => $entry['mapping'],
                        'tableRefs' => $entry['tableRefs'],
                        'columnRefs' => $entry['columnRefs'],
                        'supportCount' => 0,
                        'prompts' => [],
                        'jobIds' => [],
                    ];
                }

                $candidates[$candidateKey]['supportCount']++;
                $candidates[$candidateKey]['jobIds'][] = (string)($job['id'] ?? '');
                if (count($candidates[$candidateKey]['prompts']) < self::MAX_EVIDENCE_PROMPTS) {
                    $candidates[$candidateKey]['prompts'][] = $prompt;
                }
            }
        }

        $filteredCandidates = [];
        foreach ($candidates as $candidate) {
            $candidate['prompts'] = self::sortedUniqueStrings($candidate['prompts']);
            $candidate['jobIds'] = self::sortedUniqueStrings($candidate['jobIds']);
            if (($candidate['supportCount'] ?? 0) < $minSupport) {
                continue;
            }
            $filteredCandidates[] = $candidate;
        }

        usort($filteredCandidates, function (array $left, array $right): int {
            $supportCompare = ($right['supportCount'] ?? 0) <=> ($left['supportCount'] ?? 0);
            if ($supportCompare !== 0) {
                return $supportCompare;
            }

            return strcmp((string)($left['alias'] ?? ''), (string)($right['alias'] ?? ''));
        });

        return [
            'summary' => [
                'jobCount' => $jobCount,
                'eligibleJobCount' => count($eligibleJobs),
                'candidateCount' => count($filteredCandidates),
            ],
            'candidates' => $filteredCandidates,
        ];
    }

    private static function isEligibleJob($job): bool
    {
        if (!is_array($job)) {
            return false;
        }

        if (strtolower((string)($job['status'] ?? '')) !== 'completed') {
            return false;
        }

        if (strtolower((string)($job['source'] ?? '')) !== 'nl') {
            return false;
        }

        $dataSource = strtolower((string)($job['dataSource'] ?? $job['data_source'] ?? 'folio'));
        return $dataSource === 'folio';
    }

    private static function extractPromptText(array $job): string
    {
        $metadata = $job['metadata'] ?? null;
        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded) && trim((string)($decoded['nlPrompt'] ?? '')) !== '') {
                return trim((string)$decoded['nlPrompt']);
            }
        }

        if (is_array($metadata) && trim((string)($metadata['nlPrompt'] ?? '')) !== '') {
            return trim((string)$metadata['nlPrompt']);
        }

        return trim((string)($job['name'] ?? ''));
    }

    private static function normalizeVocabulary(array $existingVocabulary): array
    {
        $normalized = [];

        foreach ($existingVocabulary as $term => $entry) {
            $term = trim((string)$term);
            if ($term === '') {
                continue;
            }

            if (is_string($entry)) {
                $mapping = trim($entry);
                $refs = self::extractReferences($mapping);
                $entry = [
                    'mapping' => $mapping,
                    'tableRefs' => $refs['tableRefs'],
                    'columnRefs' => $refs['columnRefs'],
                ];
            }

            if (!is_array($entry)) {
                continue;
            }

            $mapping = trim((string)($entry['mapping'] ?? ''));
            if ($mapping === '') {
                continue;
            }

            $tableRefs = self::sortedUniqueStrings($entry['tableRefs'] ?? []);
            $columnRefs = self::sortedUniqueStrings($entry['columnRefs'] ?? []);
            if (empty($tableRefs) && empty($columnRefs)) {
                $refs = self::extractReferences($mapping);
                $tableRefs = $refs['tableRefs'];
                $columnRefs = $refs['columnRefs'];
            }

            $normalized[strtolower($term)] = [
                'term' => $term,
                'mapping' => $mapping,
                'tableRefs' => $tableRefs,
                'columnRefs' => $columnRefs,
                'termTokens' => self::tokenize($term),
                'mappingTokens' => self::tokenize($mapping),
            ];
        }

        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function vocabularyTouchesSql(array $entry, string $sql, array $tableRefs): bool
    {
        $sqlLower = strtolower($sql);

        foreach (($entry['tableRefs'] ?? []) as $tableRef) {
            if (in_array($tableRef, $tableRefs, true)) {
                return true;
            }
        }

        foreach (($entry['columnRefs'] ?? []) as $columnRef) {
            if (strpos($sqlLower, strtolower($columnRef)) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function selectBestAliasPhrase(array $phrases, array $entry): ?string
    {
        $bestPhrase = null;
        $bestScore = 0;
        $termLower = strtolower((string)($entry['term'] ?? ''));
        $termTokens = $entry['termTokens'] ?? [];
        $mappingTokens = $entry['mappingTokens'] ?? [];

        foreach ($phrases as $phrase) {
            $phraseLower = strtolower($phrase);
            if ($phraseLower === $termLower) {
                continue;
            }

            $phraseTokens = self::tokenize($phrase);
            if (empty($phraseTokens) || self::isNoisePhrase($phraseTokens)) {
                continue;
            }

            $score = 0;
            $sharedTermTokens = array_intersect($phraseTokens, $termTokens);
            $sharedMappingTokens = array_intersect($phraseTokens, $mappingTokens);
            $score += count($sharedTermTokens) * 4;
            $score += count($sharedMappingTokens) * 2;

            if (count($phraseTokens) > 1 && (!empty($sharedTermTokens) || !empty($sharedMappingTokens))) {
                $score += 2;
            }

            $phraseTail = self::canonicalToken(end($phraseTokens));
            $termTail = self::canonicalToken(end($termTokens));
            if ($phraseTail !== '' && $phraseTail === $termTail) {
                $score += 4;
            }

            if (count($phraseTokens) === 1 && !empty($sharedMappingTokens)) {
                $score += 4;
            }

            if ($score < 5) {
                continue;
            }

            if ($score > $bestScore || ($score === $bestScore && strcmp($phraseLower, (string)$bestPhrase) < 0)) {
                $bestPhrase = $phraseLower;
                $bestScore = $score;
            }
        }

        return $bestPhrase;
    }

    private static function extractPromptPhrases(string $prompt): array
    {
        $prompt = strtolower(trim($prompt));
        if ($prompt === '') {
            return [];
        }

        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', $prompt), function ($token): bool {
            return trim((string)$token) !== '';
        }));
        $phrases = [];
        $count = count($tokens);

        for ($size = 1; $size <= self::MAX_NGRAM_WORDS; $size++) {
            for ($start = 0; $start <= ($count - $size); $start++) {
                $slice = array_slice($tokens, $start, $size);
                if (empty($slice)) {
                    continue;
                }

                $phrase = self::trimPromptPhrase($slice);
                if (strlen($phrase) < 4) {
                    continue;
                }
                $phrases[] = $phrase;
            }
        }

        return self::sortedUniqueStrings($phrases);
    }

    private static function isNoisePhrase(array $tokens): bool
    {
        $noise = [
            'show', 'list', 'count', 'find', 'create', 'give', 'what', 'which', 'who',
            'had', 'have', 'with', 'from', 'this', 'that', 'these', 'those', 'items',
            'materials', 'records', 'highest', 'open', 'neilson', 'library', 'for',
            'and', 'the', 'all', 'top', 'had', 'year', 'fiscal',
        ];
        $noiseLookup = array_flip($noise);

        $usefulCount = 0;
        foreach ($tokens as $token) {
            if (!isset($noiseLookup[$token])) {
                $usefulCount++;
            }
        }

        return $usefulCount === 0;
    }

    private static function tokenize(string $text): array
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/[^a-z0-9]+/', $text);
        $tokens = [];
        foreach ($parts as $part) {
            $part = self::canonicalToken($part);
            if ($part === '' || strlen($part) < 2) {
                continue;
            }
            $tokens[] = $part;
        }

        return $tokens;
    }

    private static function canonicalToken($token): string
    {
        $token = trim(strtolower((string)$token));
        if ($token === '') {
            return '';
        }

        $specials = [
            'suppliers' => 'supplier',
            'vendors' => 'vendor',
            'types' => 'type',
            'materials' => 'material',
            'records' => 'record',
        ];
        if (isset($specials[$token])) {
            return $specials[$token];
        }

        if (strlen($token) > 4 && substr($token, -1) === 's') {
            return substr($token, 0, -1);
        }

        return $token;
    }

    private static function trimPromptPhrase(array $tokens): string
    {
        $trimTokens = [
            'a', 'an', 'and', 'by', 'create', 'find', 'for', 'from', 'how', 'in',
            'list', 'of', 'on', 'show', 'the', 'this', 'to', 'what', 'which', 'who', 'with'
        ];
        $trimLookup = array_flip($trimTokens);

        while (!empty($tokens) && isset($trimLookup[(string)$tokens[0]])) {
            array_shift($tokens);
        }

        while (!empty($tokens) && isset($trimLookup[(string)$tokens[count($tokens) - 1]])) {
            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }

    private static function extractSqlTableRefs(string $sql): array
    {
        preg_match_all('/(?:[a-z_]+\.[a-z0-9_]+__t(?:__[a-z0-9_]+)?|marctab\.mt[0-9]{3})/i', $sql, $matches);
        return self::sortedUniqueStrings(array_map('strtolower', $matches[0] ?? []));
    }

    private static function extractReferences(string $mapping): array
    {
        preg_match_all('/[a-z_]+\.[a-z0-9_]+__t(?:__[a-z0-9_]+)?(?:\.[a-z0-9_]+)?/i', $mapping, $matches);

        $tableRefs = [];
        $columnRefs = [];
        foreach ($matches[0] ?? [] as $ref) {
            $parts = explode('.', strtolower($ref));
            if (count($parts) >= 3) {
                $tableName = $parts[0] . '.' . $parts[1];
                $columnName = $parts[2];
                $columnRefs[] = $tableName . '.' . $columnName;
                $tableRefs[] = $tableName;
                continue;
            }

            if (count($parts) === 2) {
                $tableRefs[] = $parts[0] . '.' . $parts[1];
            }
        }

        return [
            'tableRefs' => self::sortedUniqueStrings($tableRefs),
            'columnRefs' => self::sortedUniqueStrings($columnRefs),
        ];
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