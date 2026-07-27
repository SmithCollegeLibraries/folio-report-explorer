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

        if (preg_match_all(
            '/\b' . $qualifier . '\b\s+([^.,;:!?\n]+)/iu',
            $matchText,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches as $match) {
                list($offset, $length) = self::trimCapture(
                    $match[1][0],
                    $match[1][1],
                    true
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
        }

        if (preg_match_all(
            '/([^.,;:!?\n]+?)\s+\b' . $qualifier . '\b/iu',
            $matchText,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches as $match) {
                list($offset, $length) = self::trimCapture(
                    $match[1][0],
                    $match[1][1],
                    false
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
        }

        return $intents;
    }

    private static function extractNamedDimensionIntents(
        string $prompt,
        string $matchText,
        array $consumed
    ): array {
        $intents = [];
        $qualifiers = [
            'service_point' => 'service\s+points?',
            'library' => 'librar(?:y|ies)',
            'campus' => 'campus(?:es)?',
            'institution' => 'institutions?',
        ];

        foreach ($qualifiers as $dimension => $qualifier) {
            if (!preg_match_all(
                '/([^.,;:!?\n]+?)\s+\b(' . $qualifier . ')\b/iu',
                $matchText,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            foreach ($matches as $match) {
                list($nameOffset, $nameLength) = self::trimCapture(
                    $match[1][0],
                    $match[1][1],
                    false
                );
                $qualifierOffset = $match[2][1];
                $end = $qualifierOffset + strlen($match[2][0]);

                if (
                    $nameLength === 0
                    || self::overlaps($nameOffset, $end, $consumed)
                ) {
                    continue;
                }

                $intents[] = self::namedIntent(
                    $dimension,
                    substr($prompt, $nameOffset, $end - $nameOffset),
                    $nameOffset,
                    $end
                );
            }
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
            '/\b([\pL\pN][\pL\pN\'&-]*)\s+(?:formats?|material\s+types?)\b/iu',
            $matchText,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            foreach ($matches as $match) {
                $offset = $match[1][1];
                $end = $offset + strlen($match[1][0]);
                if (
                    self::overlaps($offset, $end, $consumed)
                    || self::overlaps($offset, $end, $knownRanges)
                ) {
                    continue;
                }

                $term = strtolower($match[1][0]);
                if (
                    in_array($term, ['video', 'videos', 'all', 'vhs', 'dvd', 'dvds', 'film', 'films'], true)
                    || isset($unknownTerms[$term])
                ) {
                    continue;
                }

                $unknownTerms[$term] = $offset;
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

            asort($unknownTerms);
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

    private static function trimCapture(
        string $capture,
        int $offset,
        bool $trimTail
    ): array {
        $leadingLength = strlen($capture) - strlen(ltrim($capture));
        $capture = ltrim($capture);
        $offset += $leadingLength;

        $boundaryPattern = '/\b(?:at|in|from|for|within|inside|near)\s+/iu';
        if ($trimTail && preg_match('/^(?:at|in|from|for|with)\b/iu', $capture)) {
            return [$offset, 0];
        }

        if (!$trimTail && preg_match_all(
            $boundaryPattern,
            $capture,
            $boundaries,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            $last = end($boundaries);
            $boundaryEnd = $last[0][1] + strlen($last[0][0]);
            $offset += $boundaryEnd;
            $capture = substr($capture, $boundaryEnd);
        }

        if ($trimTail && preg_match(
            '/\s+\b(?:at|in|from|for|with)\s+/iu',
            $capture,
            $boundary,
            PREG_OFFSET_CAPTURE
        )) {
            $capture = substr($capture, 0, $boundary[0][1]);
        }

        if (!$trimTail) {
            $prefixPattern = '/^(?:(?:show|find|list|display|get|give|report)\b\s*|(?:all|the|of|items?)\b\s*)+/iu';
            if (preg_match($prefixPattern, $capture, $prefix, PREG_OFFSET_CAPTURE)) {
                $offset += strlen($prefix[0][0]);
                $capture = substr($capture, strlen($prefix[0][0]));
            }

            if (preg_match('/^(?:at|in|from|for|within|inside|near)$/iu', trim($capture))) {
                $capture = '';
            }
        }

        $length = strlen(rtrim($capture));

        return [$offset, $length];
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
