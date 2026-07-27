<?php

namespace app\services;

final class ReferenceIntentService
{
    const DIMENSION_TABLES = [
        'library' => 'inventory.loclibrary__t',
        'location' => 'inventory.location__t',
        'campus' => 'inventory.loccampus__t',
        'institution' => 'inventory.locinstitution__t',
        'service_point' => 'inventory.service_point__t',
        'material_type' => 'inventory.material_type__t',
    ];

    const MATERIAL_SELECTORS = [
        'vhs' => ['Videocassette'],
        'dvd' => ['DVD/Blu-ray'],
        'film' => ['Film'],
        'physical_video' => ['Videocassette', 'DVD/Blu-ray', 'Film'],
    ];

    private const DIMENSION_ORDER = [
        'institution',
        'campus',
        'library',
        'location',
        'service_point',
        'material_type',
    ];

    public static function extract(string $prompt): array
    {
        if (trim($prompt) === '') {
            return [];
        }

        $matchText = self::normalizePunctuationForMatching($prompt);
        $intents = [];
        $consumed = [];

        foreach (self::extractLocationIntents($prompt, $matchText) as $intent) {
            $intents[] = $intent;
            $consumed[] = [$intent['_offset'], $intent['_end']];
        }

        foreach (self::extractNamedDimensionIntents($prompt, $matchText, $consumed) as $intent) {
            $intents[] = $intent;
            $consumed[] = [$intent['_offset'], $intent['_end']];
        }

        $materialIntent = self::extractMaterialIntent($prompt, $matchText, $consumed);
        if ($materialIntent !== null) {
            $intents[] = $materialIntent;
        }

        usort($intents, function (array $left, array $right): int {
            $leftOrder = array_search($left['dimension'], self::DIMENSION_ORDER, true);
            $rightOrder = array_search($right['dimension'], self::DIMENSION_ORDER, true);

            if ($leftOrder === $rightOrder) {
                return $left['_offset'] <=> $right['_offset'];
            }

            return $leftOrder <=> $rightOrder;
        });

        return array_map(function (array $intent): array {
            unset($intent['_offset'], $intent['_end']);

            return $intent;
        }, $intents);
    }

    public static function tableForDimension(string $dimension): ?string
    {
        return self::DIMENSION_TABLES[$dimension] ?? null;
    }

    public static function canonicalNamesForMaterialIntent(array $intent): array
    {
        if (($intent['dimension'] ?? null) !== 'material_type') {
            return [];
        }

        $selector = $intent['selector'] ?? null;
        if (is_string($selector) && isset(self::MATERIAL_SELECTORS[$selector])) {
            return self::MATERIAL_SELECTORS[$selector];
        }

        $names = [];
        foreach (($intent['terms'] ?? []) as $term) {
            if (!is_string($term) || !isset(self::MATERIAL_SELECTORS[$term])) {
                continue;
            }

            foreach (self::MATERIAL_SELECTORS[$term] as $name) {
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    private static function normalizePunctuationForMatching(string $prompt): string
    {
        $normalized = preg_replace_callback(
            '/[^\pL\pN\s\/&\'\-\.,;:!?]/u',
            function (array $match): string {
                return str_repeat(' ', strlen($match[0]));
            },
            $prompt
        );

        return is_string($normalized) ? $normalized : $prompt;
    }

    private static function extractLocationIntents(string $prompt, string $matchText): array
    {
        $intents = [];
        $ranges = [];
        $qualifier = '(?:locations?|collections?|stacks|rooms?|shelving)';
        $qualifierMatches = [];

        if (preg_match_all(
            '/\b' . $qualifier . '\b/iu',
            $matchText,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            $qualifierMatches = $matches;
        }

        $prefixMatches = [];
        foreach ($qualifierMatches as $index => $match) {
            $qualifierEnd = $match[0][1] + strlen($match[0][0]);
            $valueOffset = self::skipWhitespace($matchText, $qualifierEnd);
            $following = substr($matchText, $valueOffset);

            if (
                $following === ''
                || preg_match('/^[.,;:!?\n]/u', $following)
                || preg_match(
                    '/^(?:and|or|at|in|from|for|with|within|inside|near|on|by|held)\b/iu',
                    $following
                )
            ) {
                continue;
            }

            $valueEnd = self::nextPunctuationOffset($matchText, $valueOffset);
            if (isset($qualifierMatches[$index + 1])) {
                $valueEnd = min($valueEnd, $qualifierMatches[$index + 1][0][1]);
            }

            list($offset, $length) = self::trimLocationValue(
                substr($matchText, $valueOffset, $valueEnd - $valueOffset),
                $valueOffset
            );
            if ($length === 0 || self::overlaps($offset, $offset + $length, $ranges)) {
                continue;
            }

            $intents[] = self::namedIntent(
                'location',
                substr($prompt, $offset, $length),
                $offset,
                $offset + $length
            );
            $ranges[] = [$offset, $offset + $length];
            $prefixMatches[$index] = true;
        }

        foreach ($qualifierMatches as $index => $match) {
            if (isset($prefixMatches[$index])) {
                continue;
            }

            $qualifierOffset = $match[0][1];
            $valueStart = self::previousPunctuationOffset($matchText, $qualifierOffset);
            if ($index > 0) {
                $previousEnd = $qualifierMatches[$index - 1][0][1]
                    + strlen($qualifierMatches[$index - 1][0][0]);
                $valueStart = max($valueStart, $previousEnd);
            }

            list($offset, $length) = self::trimLeadingQualifiedValue(
                substr($matchText, $valueStart, $qualifierOffset - $valueStart),
                $valueStart
            );
            if ($length === 0 || self::overlaps($offset, $offset + $length, $ranges)) {
                continue;
            }

            $intents[] = self::namedIntent(
                'location',
                substr($prompt, $offset, $length),
                $offset,
                $offset + $length
            );
            $ranges[] = [$offset, $offset + $length];
        }

        return $intents;
    }

    private static function extractNamedDimensionIntents(
        string $prompt,
        string $matchText,
        array $consumed
    ): array {
        $intents = [];
        $ranges = $consumed;
        $pattern = '/\b(service\s+points?|librar(?:y|ies)|campus(?:es)?|institutions?)\b/iu';
        if (!preg_match_all(
            $pattern,
            $matchText,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        foreach ($matches as $index => $match) {
            $qualifier = strtolower(preg_replace('/\s+/u', ' ', $match[1][0]));
            if (strpos($qualifier, 'service point') === 0) {
                $dimension = 'service_point';
            } elseif (strpos($qualifier, 'librar') === 0) {
                $dimension = 'library';
            } elseif (strpos($qualifier, 'campus') === 0) {
                $dimension = 'campus';
            } else {
                $dimension = 'institution';
            }

            $qualifierOffset = $match[1][1];
            $end = $qualifierOffset + strlen($match[1][0]);
            $nameStart = self::previousPunctuationOffset($matchText, $qualifierOffset);
            if ($index > 0) {
                $previousEnd = $matches[$index - 1][1][1]
                    + strlen($matches[$index - 1][1][0]);
                $nameStart = max($nameStart, $previousEnd);
            }

            list($nameOffset, $nameLength) = self::trimLeadingQualifiedValue(
                substr($matchText, $nameStart, $qualifierOffset - $nameStart),
                $nameStart
            );
            if (
                $nameLength === 0
                || self::overlaps($nameOffset, $end, $ranges)
            ) {
                continue;
            }

            $intents[] = self::namedIntent(
                $dimension,
                substr($prompt, $nameOffset, $end - $nameOffset),
                $nameOffset,
                $end
            );
            $ranges[] = [$nameOffset, $end];
        }

        return $intents;
    }

    private static function extractMaterialIntent(
        string $prompt,
        string $matchText,
        array $consumed
    ): ?array {
        $knownPatterns = [
            'vhs' => '/\bvhs(?:\s+tapes?)?\b/iu',
            'dvd' => '/\b(?:dvds?(?:\s*(?:\/|-|and)\s*blu[\s-]*rays?)?|blu[\s-]*rays?)\b/iu',
            'film' => '/\bfilms?\b/iu',
        ];
        $termOffsets = [];
        $knownRanges = [];
        $firstMatch = null;

        foreach ($knownPatterns as $term => $pattern) {
            if (!preg_match_all(
                $pattern,
                $matchText,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            foreach ($matches as $match) {
                $offset = $match[0][1];
                $end = $offset + strlen($match[0][0]);
                if (self::overlaps($offset, $end, $consumed)) {
                    continue;
                }

                if (!isset($termOffsets[$term])) {
                    $termOffsets[$term] = $offset;
                }
                $knownRanges[] = [$offset, $end];
                if ($firstMatch === null || $offset < $firstMatch[0]) {
                    $firstMatch = [$offset, $end];
                }
            }
        }

        $unknownTerms = [];
        if (preg_match_all(
            '/\b(formats?|material\s+types?)\b/iu',
            $matchText,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches as $index => $match) {
                $qualifierOffset = $match[1][1];
                $candidateStart = self::previousPunctuationOffset(
                    $matchText,
                    $qualifierOffset
                );
                if ($index > 0) {
                    $previousEnd = $matches[$index - 1][1][1]
                        + strlen($matches[$index - 1][1][0]);
                    $candidateStart = max($candidateStart, $previousEnd);
                }

                list($offset, $length) = self::trimLeadingQualifiedValue(
                    substr(
                        $matchText,
                        $candidateStart,
                        $qualifierOffset - $candidateStart
                    ),
                    $candidateStart,
                    true
                );
                $end = $offset + $length;
                if (
                    $length === 0
                    ||
                    self::overlaps($offset, $end, $consumed)
                    || self::overlaps($offset, $end, $knownRanges)
                ) {
                    continue;
                }

                $term = preg_replace(
                    '/\s+/u',
                    ' ',
                    strtolower(substr($matchText, $offset, $length))
                );
                if (!is_string($term)) {
                    continue;
                }
                $term = trim($term);
                if (
                    in_array(
                        $term,
                        [
                            'video',
                            'videos',
                            'all',
                            'material',
                            'materials',
                            'vhs',
                            'dvd',
                            'dvds',
                            'film',
                            'films',
                        ],
                        true
                    )
                    || isset($unknownTerms[$term])
                ) {
                    continue;
                }

                $unknownTerms[$term] = [$offset, $end];
                if ($firstMatch === null || $offset < $firstMatch[0]) {
                    $firstMatch = [$offset, $end];
                }
            }
        }

        if ($firstMatch !== null) {
            $terms = [];
            foreach (['vhs', 'dvd', 'film'] as $term) {
                if (isset($termOffsets[$term])) {
                    $terms[] = $term;
                }
            }

            uasort($unknownTerms, function (array $left, array $right): int {
                return $left[0] <=> $right[0];
            });
            foreach (array_keys($unknownTerms) as $term) {
                $terms[] = $term;
            }

            return [
                'dimension' => 'material_type',
                'span' => substr($prompt, $firstMatch[0], $firstMatch[1] - $firstMatch[0]),
                'terms' => $terms,
                'selector' => null,
                'provenance' => 'explicit_prompt',
                'explicit' => true,
                '_offset' => $firstMatch[0],
                '_end' => $firstMatch[1],
            ];
        }

        if (self::containsUnconsumedPattern(
            '/\ball\s+materials?\b/iu',
            $matchText,
            $consumed
        )) {
            return null;
        }

        $videoMatch = self::firstUnconsumedMatch(
            '/\bvideos?(?:\s+(?:materials?|formats?))?\b/iu',
            $matchText,
            $consumed
        );
        if ($videoMatch === null) {
            return null;
        }

        return [
            'dimension' => 'material_type',
            'span' => substr($prompt, $videoMatch[0], $videoMatch[1] - $videoMatch[0]),
            'terms' => [],
            'selector' => 'physical_video',
            'provenance' => 'documented_default',
            'explicit' => false,
            '_offset' => $videoMatch[0],
            '_end' => $videoMatch[1],
        ];
    }

    private static function trimLeadingQualifiedValue(
        string $capture,
        int $offset,
        bool $formatIntro = false
    ): array {
        $leadingLength = strlen($capture) - strlen(ltrim($capture));
        $capture = ltrim($capture);
        $offset += $leadingLength;

        if (preg_match_all(
            '/\b(?:held\s+by|at|in|from|for|with|within|inside|near|on|by|and|or)\s+/iu',
            $capture,
            $boundaries,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            $last = end($boundaries);
            $boundaryEnd = $last[0][1] + strlen($last[0][0]);
            $offset += $boundaryEnd;
            $capture = substr($capture, $boundaryEnd);
        }

        $prefixWords = $formatIntro
            ? 'show|find|list|display|get|give|report|please|what|which|who|where|'
                . 'all|the|of|items?|this|can|be'
            : 'show|find|list|display|get|give|report|please|what|which|who|where|'
                . 'all|the|of|items?';
        $prefixPattern = '/^(?:(?:' . $prefixWords . ')\b\s*)+/iu';
        if (preg_match($prefixPattern, $capture, $prefix, PREG_OFFSET_CAPTURE)) {
            $offset += strlen($prefix[0][0]);
            $capture = substr($capture, strlen($prefix[0][0]));
        }

        $length = strlen(rtrim($capture));

        return [$offset, $length];
    }

    private static function trimLocationValue(string $capture, int $offset): array
    {
        $leadingLength = strlen($capture) - strlen(ltrim($capture));
        $capture = ltrim($capture);
        $offset += $leadingLength;

        if (preg_match(
            '/\s+\b(?:and|or)\s+(?=[^.,;:!?\n]*\b'
                . '(?:service\s+points?|librar(?:y|ies)|campus(?:es)?|institutions?)\b)/iu',
            $capture,
            $dimensionBoundary,
            PREG_OFFSET_CAPTURE
        )) {
            $capture = substr($capture, 0, $dimensionBoundary[0][1]);
        }

        if (preg_match(
            '/\s+\b(?:held\s+by|at|in|from|for|with|within|inside|near|on)\s+/iu',
            $capture,
            $boundary,
            PREG_OFFSET_CAPTURE
        )) {
            $capture = substr($capture, 0, $boundary[0][1]);
        }

        $capture = preg_replace('/(?:,\s*)?(?:and|or)?\s*$/iu', '', $capture);
        if (!is_string($capture)) {
            return [$offset, 0];
        }

        return [$offset, strlen(rtrim($capture))];
    }

    private static function skipWhitespace(string $text, int $offset): int
    {
        $remaining = substr($text, $offset);
        if (preg_match('/^\s+/u', $remaining, $match)) {
            return $offset + strlen($match[0]);
        }

        return $offset;
    }

    private static function nextPunctuationOffset(string $text, int $offset): int
    {
        if (preg_match(
            '/[.,;:!?\n]/u',
            $text,
            $match,
            PREG_OFFSET_CAPTURE,
            $offset
        )) {
            return $match[0][1];
        }

        return strlen($text);
    }

    private static function previousPunctuationOffset(string $text, int $offset): int
    {
        $prefix = substr($text, 0, $offset);
        if (preg_match('/[.,;:!?\n]\s*$/u', $prefix, $match, PREG_OFFSET_CAPTURE)) {
            return $match[0][1] + strlen($match[0][0]);
        }

        $positions = [];
        foreach (['.', ',', ';', ':', '!', '?', "\n"] as $punctuation) {
            $position = strrpos($prefix, $punctuation);
            if ($position !== false) {
                $positions[] = $position + 1;
            }
        }

        return $positions === [] ? 0 : max($positions);
    }

    private static function namedIntent(
        string $dimension,
        string $span,
        int $offset,
        int $end
    ): array {
        return [
            'dimension' => $dimension,
            'span' => $span,
            'terms' => [],
            'selector' => null,
            'provenance' => 'explicit_prompt',
            'explicit' => true,
            '_offset' => $offset,
            '_end' => $end,
        ];
    }

    private static function containsUnconsumedPattern(
        string $pattern,
        string $text,
        array $consumed
    ): bool {
        return self::firstUnconsumedMatch($pattern, $text, $consumed) !== null;
    }

    private static function firstUnconsumedMatch(
        string $pattern,
        string $text,
        array $consumed
    ): ?array {
        if (!preg_match_all(
            $pattern,
            $text,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        foreach ($matches as $match) {
            $offset = $match[0][1];
            $end = $offset + strlen($match[0][0]);
            if (!self::overlaps($offset, $end, $consumed)) {
                return [$offset, $end];
            }
        }

        return null;
    }

    private static function overlaps(int $start, int $end, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($start < $range[1] && $end > $range[0]) {
                return true;
            }
        }

        return false;
    }
}
