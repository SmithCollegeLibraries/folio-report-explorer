<?php

namespace app\services;

/**
 * Builds a deterministic retrieval plan from the canonical semantic artifact.
 * The plan re-ranks prompt-relevant tables first and suppresses unrelated
 * vocabulary, examples, and pattern snippets when the prompt has strong matches.
 */
class SemanticContextRetrievalService
{
    const MAX_PROMPT_TERMS = 12;
    const DEFAULT_PATTERN_TABLE_LIMIT = 10;
    const DEFAULT_PATTERN_CARD_LIMIT = 4;
    const MIN_RELEVANT_TABLE_SCORE = 8;

    /**
     * @param array $semanticContext
     * @param string $prompt
     * @param array $options
     * @return array
     */
    public static function buildPlan(array $semanticContext, string $prompt, array $options = []): array
    {
        $tables = $semanticContext['tables'] ?? [];
        $vocabulary = $semanticContext['vocabulary'] ?? [];
        $examples = $semanticContext['examples'] ?? [];
        $patternCards = $semanticContext['patternCards'] ?? [];
        $terms = self::extractPromptTerms($prompt);
        $hasPrompt = trim($prompt) !== '';

        $tableScores = [];
        foreach ($tables as $tableName => $tableInfo) {
            $tableScores[$tableName] = self::scoreSemanticTable($tableName, $tableInfo, $terms, $prompt);
        }

        $prioritizedTableNames = self::sortTableNamesByScore($tableScores);
        $relevantTableNames = self::selectRelevantTableNames($prioritizedTableNames, $tableScores, $hasPrompt);

        $tableDescriptionLimit = (int)($options['tableDescriptionLimit'] ?? PHP_INT_MAX);
        $vocabularyLimit = (int)($options['vocabularyLimit'] ?? PHP_INT_MAX);
        $exampleLimit = (int)($options['exampleLimit'] ?? PHP_INT_MAX);
        $patternTableLimit = (int)($options['patternTableLimit'] ?? self::DEFAULT_PATTERN_TABLE_LIMIT);
        $patternCardLimit = (int)($options['patternCardLimit'] ?? self::DEFAULT_PATTERN_CARD_LIMIT);

        $selectedTableDescriptions = [];
        foreach ($relevantTableNames as $tableName) {
            $description = trim((string)($tables[$tableName]['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $selectedTableDescriptions[$tableName] = $description;
            if (count($selectedTableDescriptions) >= $tableDescriptionLimit) {
                break;
            }
        }

        $selectedPatternTables = [];
        foreach ($relevantTableNames as $tableName) {
            $tableInfo = $tables[$tableName] ?? [];
            $patternInfo = self::extractPatternInfo($tableInfo);
            if (empty($patternInfo['columnWarnings']) && empty($patternInfo['sampleValues']) && empty($patternInfo['preferredApproach'])) {
                continue;
            }
            $selectedPatternTables[$tableName] = $patternInfo;
            if (count($selectedPatternTables) >= $patternTableLimit) {
                break;
            }
        }
        ksort($selectedPatternTables, SORT_STRING);

        $selectedDerivedComments = [];
        foreach ($relevantTableNames as $tableName) {
            foreach (($tables[$tableName]['columnSemantics'] ?? []) as $columnName => $columnInfo) {
                $derivedComments = array_values($columnInfo['derivedComments'] ?? []);
                if (!empty($derivedComments)) {
                    $selectedDerivedComments[$tableName][$columnName] = $derivedComments;
                }
            }
        }

        $selectedVocabulary = self::selectRelevantVocabulary(
            $vocabulary,
            $terms,
            $prompt,
            $relevantTableNames,
            $vocabularyLimit,
            $hasPrompt
        );

        $selectedExamples = self::selectRelevantExamples(
            $examples,
            $terms,
            $prompt,
            $relevantTableNames,
            $exampleLimit,
            $hasPrompt
        );

        $selectedPatternCards = self::selectRelevantPatternCards(
            $patternCards,
            $terms,
            $prompt,
            $relevantTableNames,
            $patternCardLimit,
            $hasPrompt
        );

        return [
            'promptTerms' => $terms,
            'tableScores' => $tableScores,
            'prioritizedTableNames' => $prioritizedTableNames,
            'relevantTableNames' => $relevantTableNames,
            'tableDescriptions' => $selectedTableDescriptions,
            'dataPatterns' => $selectedPatternTables,
            'derivedCommentsByTable' => $selectedDerivedComments,
            'vocabulary' => $selectedVocabulary,
            'examples' => $selectedExamples,
            'patternCards' => $selectedPatternCards,
        ];
    }

    private static function extractPromptTerms(string $prompt): array
    {
        $normalized = strtolower(trim($prompt));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[^a-z0-9_]+/', $normalized);
        $stopwords = [
            'the', 'and', 'for', 'with', 'from', 'that', 'this', 'show', 'list',
            'count', 'what', 'which', 'where', 'when', 'have', 'has', 'into',
            'also', 'only', 'your', 'our', 'are', 'was', 'were', 'how', 'many',
            'get', 'give', 'use', 'using', 'about', 'over', 'under', 'than',
            'from', 'did', 'does', 'them', 'they', 'their', 'there', 'been',
        ];
        $stop = array_flip($stopwords);

        $terms = [];
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) < 3) {
                continue;
            }
            if (isset($stop[$part])) {
                continue;
            }
            $terms[$part] = true;
        }

        $expansions = [
            'vendors' => ['vendor'],
            'vendor' => ['vendors', 'supplier', 'suppliers'],
            'suppliers' => ['supplier', 'vendor', 'vendors'],
            'supplier' => ['suppliers', 'vendor', 'vendors'],
            'orders' => ['order', 'ordered', 'purchased'],
            'order' => ['orders', 'ordered', 'purchased'],
            'ordered' => ['order', 'orders', 'purchased'],
            'purchased' => ['purchase', 'order', 'orders', 'ordered'],
            'purchase' => ['purchased', 'order', 'orders', 'ordered'],
            'books' => ['book'],
            'journals' => ['journal'],
            'theses' => ['thesis', 'dissertation'],
            'thesis' => ['theses', 'dissertation'],
            'dissertations' => ['dissertation', 'thesis', 'theses'],
            'dissertation' => ['thesis', 'theses'],
        ];

        foreach (array_keys($terms) as $term) {
            foreach ($expansions[$term] ?? [] as $expanded) {
                $terms[$expanded] = true;
            }
        }

        $result = array_keys($terms);
        sort($result, SORT_STRING);
        return array_slice($result, 0, self::MAX_PROMPT_TERMS);
    }

    private static function scoreSemanticTable(string $tableName, array $tableInfo, array $terms, string $prompt): int
    {
        $promptText = strtolower($prompt);
        $score = 0;

        $score += self::scoreText($tableName, $terms, $promptText, 18, 10, 6, 4);
        $score += self::scoreText((string)($tableInfo['description'] ?? ''), $terms, $promptText, 20, 10, 5, 3);

        foreach (($tableInfo['terms'] ?? []) as $term) {
            $term = strtolower((string)$term);
            if ($term === '') {
                continue;
            }
            if ($promptText !== '' && strpos($promptText, $term) !== false) {
                $score += 28;
            }
            foreach ($terms as $promptTerm) {
                if ($term === $promptTerm) {
                    $score += 18;
                } elseif (strpos($term, $promptTerm) !== false || strpos($promptTerm, $term) !== false) {
                    $score += 8;
                }
            }
        }

        foreach (($tableInfo['columnSemantics'] ?? []) as $columnName => $columnInfo) {
            $score += self::scoreText((string)$columnName, $terms, $promptText, 8, 6, 3, 2);
            foreach (['terms', 'warnings', 'sampleValues', 'derivedComments'] as $fieldName) {
                foreach (($columnInfo[$fieldName] ?? []) as $value) {
                    $score += self::scoreText((string)$value, $terms, $promptText, 10, 6, 3, 2);
                }
            }
        }

        foreach (($tableInfo['preferredApproach'] ?? []) as $approach) {
            $score += self::scoreText((string)$approach, $terms, $promptText, 10, 6, 3, 2);
        }

        return $score;
    }

    private static function scoreText(string $text, array $terms, string $promptText, int $phraseWeight, int $exactWeight, int $wordWeight, int $substringWeight): int
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return 0;
        }

        $score = 0;
        if ($promptText !== '' && strpos($promptText, $text) !== false) {
            $score += $phraseWeight;
        }

        foreach ($terms as $term) {
            if ($text === $term) {
                $score += $exactWeight;
                continue;
            }

            if (preg_match('/\b' . preg_quote($term, '/') . '\b/', $text)) {
                $score += $wordWeight;
                continue;
            }

            if (strpos($text, $term) !== false) {
                $score += $substringWeight;
            }
        }

        return $score;
    }

    private static function sortTableNamesByScore(array $tableScores): array
    {
        $tableNames = array_keys($tableScores);
        usort($tableNames, function ($left, $right) use ($tableScores) {
            if ($tableScores[$left] !== $tableScores[$right]) {
                return $tableScores[$right] <=> $tableScores[$left];
            }
            return strcmp($left, $right);
        });
        return $tableNames;
    }

    private static function selectRelevantTableNames(array $prioritizedTableNames, array $tableScores, bool $hasPrompt): array
    {
        if (!$hasPrompt) {
            return $prioritizedTableNames;
        }

        $selected = [];
        foreach ($prioritizedTableNames as $tableName) {
            if (($tableScores[$tableName] ?? 0) < self::MIN_RELEVANT_TABLE_SCORE) {
                continue;
            }
            $selected[] = $tableName;
        }

        if (!empty($selected)) {
            return $selected;
        }

        foreach ($prioritizedTableNames as $tableName) {
            if (($tableScores[$tableName] ?? 0) <= 0) {
                continue;
            }
            $selected[] = $tableName;
        }

        return !empty($selected) ? $selected : $prioritizedTableNames;
    }

    private static function extractPatternInfo(array $tableInfo): array
    {
        $patternInfo = [
            'columnWarnings' => [],
            'sampleValues' => [],
            'valueSemantics' => [],
            'preferredApproach' => array_values($tableInfo['preferredApproach'] ?? []),
        ];

        foreach (($tableInfo['columnSemantics'] ?? []) as $columnName => $columnInfo) {
            $warnings = array_values($columnInfo['warnings'] ?? []);
            if (!empty($warnings)) {
                $patternInfo['columnWarnings'][$columnName] = implode(' ', $warnings);
            }

            $sampleValues = array_values($columnInfo['sampleValues'] ?? []);
            if (!empty($sampleValues)) {
                $patternInfo['sampleValues'][$columnName] = $sampleValues;
            }

            $valueSemantics = $columnInfo['valueSemantics'] ?? [];
            if (!empty($valueSemantics) && is_array($valueSemantics)) {
                ksort($valueSemantics, SORT_STRING);
                $patternInfo['valueSemantics'][$columnName] = $valueSemantics;
            }
        }

        return $patternInfo;
    }

    private static function selectRelevantVocabulary(array $vocabulary, array $terms, string $prompt, array $relevantTableNames, int $limit, bool $hasPrompt): array
    {
        $relevantLookup = array_fill_keys($relevantTableNames, true);
        $items = [];
        foreach ($vocabulary as $term => $info) {
            $mapping = trim((string)($info['mapping'] ?? ''));
            if ($mapping === '') {
                continue;
            }

            $score = self::scoreText((string)$term, $terms, strtolower($prompt), 18, 14, 8, 4);
            $score += self::scoreText($mapping, $terms, strtolower($prompt), 10, 6, 3, 2);
            foreach (($info['tableRefs'] ?? []) as $tableRef) {
                if (isset($relevantLookup[$tableRef])) {
                    $score += 8;
                }
            }
            foreach (($info['columnRefs'] ?? []) as $columnRef) {
                foreach ($relevantTableNames as $tableName) {
                    if (strpos($columnRef, $tableName . '.') === 0) {
                        $score += 6;
                        break;
                    }
                }
            }

            $items[] = [
                'term' => (string)$term,
                'mapping' => $mapping,
                'score' => $score,
            ];
        }

        usort($items, function ($left, $right) {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }
            return strcmp($left['term'], $right['term']);
        });

        $result = [];
        foreach ($items as $item) {
            if ($hasPrompt && $item['score'] <= 0) {
                continue;
            }
            $result[$item['term']] = $item['mapping'];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    private static function selectRelevantExamples(array $examples, array $terms, string $prompt, array $relevantTableNames, int $limit, bool $hasPrompt): array
    {
        $scored = [];
        $promptText = strtolower($prompt);
        foreach ($examples as $example) {
            $question = trim((string)($example['question'] ?? ''));
            $sql = trim((string)($example['sql'] ?? ''));
            if ($question === '' || $sql === '') {
                continue;
            }

            $score = self::scoreText($question, $terms, $promptText, 20, 10, 6, 3);
            $score += self::scoreText($sql, $terms, $promptText, 12, 6, 3, 2);
            foreach ($relevantTableNames as $tableName) {
                if (strpos(strtolower($sql), strtolower($tableName)) !== false) {
                    $score += 6;
                }
            }

            $scored[] = [
                'question' => $question,
                'sql' => $sql,
                'score' => $score,
            ];
        }

        usort($scored, function ($left, $right) {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }
            return strcmp($left['question'], $right['question']);
        });

        $result = [];
        foreach ($scored as $item) {
            if ($hasPrompt && $item['score'] <= 0) {
                continue;
            }
            $result[] = [
                'question' => $item['question'],
                'sql' => $item['sql'],
            ];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    private static function selectRelevantPatternCards(array $patternCards, array $terms, string $prompt, array $relevantTableNames, int $limit, bool $hasPrompt): array
    {
        if (empty($patternCards) || $limit <= 0) {
            return [];
        }

        $promptText = strtolower($prompt);
        $relevantLookup = array_fill_keys($relevantTableNames, true);
        $items = [];

        foreach ($patternCards as $cardKey => $cardInfo) {
            $score = self::scoreText((string)$cardKey, $terms, $promptText, 12, 8, 5, 3);
            $score += self::scoreText((string)($cardInfo['title'] ?? ''), $terms, $promptText, 20, 12, 6, 3);
            $score += self::scoreText((string)($cardInfo['summary'] ?? ''), $terms, $promptText, 12, 8, 4, 2);

            foreach (($cardInfo['promptSignals'] ?? []) as $signal) {
                $signal = strtolower((string)$signal);
                if ($signal === '') {
                    continue;
                }
                if ($promptText !== '' && strpos($promptText, $signal) !== false) {
                    $score += 30;
                }
                $score += self::scoreText($signal, $terms, $promptText, 8, 8, 5, 3);
            }

            foreach (($cardInfo['tableRefs'] ?? []) as $tableRef) {
                if (isset($relevantLookup[$tableRef])) {
                    $score += 10;
                }
            }

            foreach (($cardInfo['guidance'] ?? []) as $guidance) {
                $score += self::scoreText((string)$guidance, $terms, $promptText, 6, 4, 2, 1);
            }

            foreach (($cardInfo['exampleQuestions'] ?? []) as $exampleQuestion) {
                $score += self::scoreText((string)$exampleQuestion, $terms, $promptText, 10, 6, 3, 2);
            }

            $items[] = [
                'key' => (string)$cardKey,
                'score' => $score,
                'card' => $cardInfo,
            ];
        }

        usort($items, function ($left, $right) {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }
            return strcmp($left['key'], $right['key']);
        });

        $result = [];
        foreach ($items as $item) {
            if ($hasPrompt && $item['score'] <= 0) {
                continue;
            }
            $result[$item['key']] = $item['card'];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }
}