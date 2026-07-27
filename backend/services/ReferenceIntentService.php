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
        self::appendLocationIntentsForQualifiers(
            $prompt,
            $matchText,
            '/\b(locations?|collections?)\b/iu',
            $intents,
            $ranges
        );
        self::appendLocationIntentsForQualifiers(
            $prompt,
            $matchText,
            '/\b(stacks|rooms?|shelving)\b/iu',
            $intents,
            $ranges
        );

        return $intents;
    }

    private static function appendLocationIntentsForQualifiers(
        string $prompt,
        string $matchText,
        string $pattern,
        array &$intents,
        array &$ranges
    ): void {
        if (!preg_match_all(
            $pattern,
            $matchText,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return;
        }

        $prefixMatches = [];
        $structuralEnds = [];
        foreach ($matches as $index => $match) {
            $qualifierOffset = $match[1][1];
            $qualifierEnd = $qualifierOffset + strlen($match[1][0]);
            if (self::overlaps($qualifierOffset, $qualifierEnd, $ranges)) {
                continue;
            }

            $valueOffset = self::skipWhitespace($matchText, $qualifierEnd);
            $following = substr($matchText, $valueOffset);
            if (
                $following === ''
                || preg_match('/^[.,;:!?\n]/u', $following)
                || preg_match(
                    '/^(?:and|or|at|in|from|for|with|within|inside|near|on|by|held)\b/iu',
                    $following
                )
                || preg_match(
                    '/^(?:locations?|collections?|stacks|rooms?|shelving)\b/iu',
                    $following
                )
            ) {
                continue;
            }

            $valueEnd = self::nextClauseOffset($matchText, $valueOffset);
            if (isset($matches[$index + 1])) {
                $nextOffset = $matches[$index + 1][1][1];
                $nextEnd = $nextOffset + strlen($matches[$index + 1][1][0]);
                $nextFollowing = substr(
                    $matchText,
                    self::skipWhitespace($matchText, $nextEnd)
                );
                $sameQualifier = strtolower($match[1][0])
                    === strtolower($matches[$index + 1][1][0]);
                if (
                    $sameQualifier
                    || (
                        $nextFollowing !== ''
                        && !preg_match('/^[.,;:!?\n]/u', $nextFollowing)
                    )
                ) {
                    $valueEnd = min($valueEnd, $nextOffset);
                }
            }

            $capture = substr($matchText, $valueOffset, $valueEnd - $valueOffset);
            $capture = self::trimLocationValue($capture);
            $plural = self::isPluralQualifier($match[1][0]);
            $values = self::qualifiedValues($capture, $valueOffset, $plural);
            foreach ($values as $value) {
                list($offset, $length) = $value;
                if (
                    $length === 0
                    || self::overlaps($offset, $offset + $length, $ranges)
                ) {
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
            if ($values !== []) {
                $prefixMatches[$index] = true;
                $structuralEnds[] = $qualifierEnd;
            }
        }

        foreach ($matches as $index => $match) {
            if (isset($prefixMatches[$index])) {
                continue;
            }

            $qualifierOffset = $match[1][1];
            $qualifierEnd = $qualifierOffset + strlen($match[1][0]);
            if (self::overlaps($qualifierOffset, $qualifierEnd, $ranges)) {
                continue;
            }

            $following = substr(
                $matchText,
                self::skipWhitespace($matchText, $qualifierEnd)
            );
            if (
                $following !== ''
                && !preg_match(
                    '/^(?:[.,;:!?\n]|and\b|or\b|at\b|in\b|from\b|for\b|'
                        . 'with\b|within\b|inside\b|near\b|on\b|by\b|held\b)/iu',
                    $following
                )
            ) {
                continue;
            }

            $plural = self::isPluralQualifier($match[1][0]);
            $valueStart = $plural
                ? self::previousClauseOffset($matchText, $qualifierOffset)
                : self::previousPunctuationOffset($matchText, $qualifierOffset);
            if ($structuralEnds !== []) {
                $valueStart = max($valueStart, max($structuralEnds));
            }

            $values = self::qualifiedValues(
                substr($matchText, $valueStart, $qualifierOffset - $valueStart),
                $valueStart,
                $plural
            );
            foreach ($values as $value) {
                list($offset, $length) = $value;
                if (
                    $length === 0
                    || self::overlaps($offset, $offset + $length, $ranges)
                ) {
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
            if ($values !== []) {
                $structuralEnds[] = $qualifierEnd;
            }
        }
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
            foreach ($consumed as $range) {
                if ($range[1] <= $qualifierOffset) {
                    $nameStart = max($nameStart, $range[1]);
                }
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
                $plural = self::isPluralQualifier($match[1][0]);
                $candidateStart = $plural
                    ? self::previousClauseOffset($matchText, $qualifierOffset)
                    : self::previousPunctuationOffset(
                        $matchText,
                        $qualifierOffset
                    );
                if ($index > 0) {
                    $previousEnd = $matches[$index - 1][1][1]
                        + strlen($matches[$index - 1][1][0]);
                    $candidateStart = max($candidateStart, $previousEnd);
                }

                foreach ($consumed as $range) {
                    if ($range[1] <= $qualifierOffset) {
                        $candidateStart = max($candidateStart, $range[1]);
                    }
                }

                $values = self::qualifiedValues(
                    substr(
                        $matchText,
                        $candidateStart,
                        $qualifierOffset - $candidateStart
                    ),
                    $candidateStart,
                    $plural,
                    true
                );
                foreach ($values as $value) {
                    list($offset, $length) = $value;
                    $end = $offset + $length;
                    if (
                        $length === 0
                        || self::overlaps($offset, $end, $consumed)
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

        if (preg_match('/^(?:,\s*)?(?:and|or)\b\s*/iu', $capture, $leading)) {
            $offset += strlen($leading[0]);
            $capture = substr($capture, strlen($leading[0]));
        } elseif (preg_match('/^,\s*/u', $capture, $leading)) {
            $offset += strlen($leading[0]);
            $capture = substr($capture, strlen($leading[0]));
        }

        if (preg_match_all(
            '/\b(?:held\s+by|at|in|from|for|with|within|inside|near|on|by)\s+/iu',
            $capture,
            $boundaries,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            $last = end($boundaries);
            $boundaryEnd = $last[0][1] + strlen($last[0][0]);
            $offset += $boundaryEnd;
            $capture = substr($capture, $boundaryEnd);
        }

        if (preg_match(
            '/^(?:(?:what|which|who|where)\b\s*)'
                . '(?:(?:is|are|was|were|does|do|did|uses?|has|have|had|'
                . 'holds?|contains?|serves?|manages?|owns?)\b\s*)*'
                . '(?:the\b\s*)?/iu',
            $capture,
            $interrogative
        )) {
            $offset += strlen($interrogative[0]);
            $capture = substr($capture, strlen($interrogative[0]));
        } elseif (preg_match(
            '/^(?:(?:is|are|was|were|does|do|did|has|have|had)\b\s*)'
                . '(?:the\b\s*)?/iu',
            $capture,
            $interrogative
        )) {
            $offset += strlen($interrogative[0]);
            $capture = substr($capture, strlen($interrogative[0]));
        }

        $prefixWords = $formatIntro
            ? 'show|find|list|display|get|give|report|please|'
                . 'all|the|of|items?|this|can|be'
            : 'show|find|list|display|get|give|report|please|'
                . 'all|the|of|items?';
        $prefixPattern = '/^(?:(?:' . $prefixWords . ')\b\s*)+/iu';
        if (preg_match($prefixPattern, $capture, $prefix, PREG_OFFSET_CAPTURE)) {
            $offset += strlen($prefix[0][0]);
            $capture = substr($capture, strlen($prefix[0][0]));
        }

        $length = strlen(rtrim($capture));

        return [$offset, $length];
    }

    private static function trimLocationValue(string $capture): string
    {
        $boundaries = [];
        if (preg_match(
            '/\s+\b(?:and|or)\s+(?=[^.,;:!?\n]*\b'
                . '(?:service\s+points?|librar(?:y|ies)|campus(?:es)?|institutions?)\b)/iu',
            $capture,
            $dimensionBoundary,
            PREG_OFFSET_CAPTURE
        )) {
            $boundaries[] = $dimensionBoundary[0][1];
        }

        if (preg_match(
            '/\s+\b(?:and|or)\s+(?='
                . '(?:vhs(?:\s+tapes?)?|'
                . '(?:dvds?(?:\s*(?:\/|-|and)\s*blu[\s-]*rays?)?|blu[\s-]*rays?)|'
                . 'films?|videos?(?:\s+(?:materials?|formats?))?)\b'
                . '|[^.,;:!?\n]*\b(?:formats?|material\s+types?)\b)/iu',
            $capture,
            $materialBoundary,
            PREG_OFFSET_CAPTURE
        )) {
            $boundaries[] = $materialBoundary[0][1];
        }

        if (preg_match(
            '/\s+\b(?:held\s+by|at|in|from|for|with|within|inside|near|on)\s+/iu',
            $capture,
            $boundary,
            PREG_OFFSET_CAPTURE
        )) {
            $boundaries[] = $boundary[0][1];
        }

        if ($boundaries !== []) {
            $capture = substr($capture, 0, min($boundaries));
        }

        $capture = preg_replace('/(?:,\s*)?(?:and|or)?\s*$/iu', '', $capture);
        if (!is_string($capture)) {
            return '';
        }

        return rtrim($capture);
    }

    private static function qualifiedValues(
        string $capture,
        int $offset,
        bool $plural,
        bool $formatIntro = false
    ): array {
        list($valueOffset, $valueLength) = self::trimLeadingQualifiedValue(
            $capture,
            $offset,
            $formatIntro
        );
        if ($valueLength === 0) {
            return [];
        }

        $capture = substr($capture, $valueOffset - $offset, $valueLength);
        if (!$plural) {
            return [[$valueOffset, $valueLength]];
        }

        $parts = preg_split(
            '/\s*,\s*(?:(?:and|or)\s+)?|\s+(?:and|or)\s+/iu',
            $capture,
            -1,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE
        );
        if (!is_array($parts)) {
            return [];
        }

        $values = [];
        foreach ($parts as $part) {
            $leadingLength = strlen($part[0]) - strlen(ltrim($part[0]));
            $value = trim($part[0]);
            if ($value === '') {
                continue;
            }

            $values[] = [
                $valueOffset + $part[1] + $leadingLength,
                strlen($value),
            ];
        }

        return $values;
    }

    private static function isPluralQualifier(string $qualifier): bool
    {
        $normalized = strtolower(preg_replace('/\s+/u', ' ', trim($qualifier)));

        return in_array(
            $normalized,
            ['locations', 'collections', 'rooms', 'formats', 'material types'],
            true
        );
    }

    private static function skipWhitespace(string $text, int $offset): int
    {
        $remaining = substr($text, $offset);
        if (preg_match('/^\s+/u', $remaining, $match)) {
            return $offset + strlen($match[0]);
        }

        return $offset;
    }

    private static function nextClauseOffset(string $text, int $offset): int
    {
        if (preg_match(
            '/[.;:!?\n]/u',
            $text,
            $match,
            PREG_OFFSET_CAPTURE,
            $offset
        )) {
            return $match[0][1];
        }

        return strlen($text);
    }

    private static function previousClauseOffset(string $text, int $offset): int
    {
        $prefix = substr($text, 0, $offset);
        $positions = [];
        foreach (['.', ';', ':', '!', '?', "\n"] as $punctuation) {
            $position = strrpos($prefix, $punctuation);
            if ($position !== false) {
                $positions[] = $position + 1;
            }
        }

        return $positions === [] ? 0 : max($positions);
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
