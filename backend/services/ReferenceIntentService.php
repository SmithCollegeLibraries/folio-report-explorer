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

    private const HARD_PUNCTUATION = ['.', ';', ':', '!', '?'];

    public static function extract(string $prompt): array
    {
        if (trim($prompt) === '') {
            return [];
        }

        $tokens = self::lex($prompt);
        $qualifiers = self::qualifiers($tokens);
        $claims = [];
        $intents = [];
        $materialAtoms = [];
        $genericVideo = [];

        for ($index = count($qualifiers) - 1; $index >= 0; $index--) {
            $qualifier = $qualifiers[$index];
            if (self::overlaps($qualifier['start'], $qualifier['end'], $claims)) {
                continue;
            }

            $prefix = self::isPrefixQualifier($qualifier, $tokens);
            $valueSpan = $prefix
                ? self::prefixValueSpan($qualifier, $tokens, $claims)
                : self::suffixValueSpan($qualifier, $qualifiers, $index, $tokens);
            if ($valueSpan === null) {
                continue;
            }

            list($valueStart, $valueEnd) = $valueSpan;
            $parts = self::splitQualifiedValues(
                $tokens,
                $valueStart,
                $valueEnd,
                $qualifier['plural'],
                $qualifier['dimension']
            );
            if ($parts === []) {
                continue;
            }

            if ($qualifier['dimension'] === 'material_type') {
                $accepted = [];
                $acceptedGeneric = [];
                foreach ($parts as $part) {
                    $atom = self::materialAtom($prompt, $part[0], $part[1]);
                    if ($atom === null) {
                        continue;
                    }
                    if ($atom['generic']) {
                        $acceptedGeneric[] = [$atom['offset'], $atom['end']];
                    } else {
                        $accepted[] = $atom;
                    }
                }

                if ($accepted === [] && $acceptedGeneric === []) {
                    continue;
                }

                foreach ($accepted as $atom) {
                    $materialAtoms[] = $atom;
                }
                foreach ($acceptedGeneric as $range) {
                    $genericVideo[] = $range;
                }
            } else {
                foreach ($parts as $part) {
                    $spanStart = $part[0];
                    $spanEnd = $part[1];
                    if (
                        !$prefix
                        && !$qualifier['plural']
                        && $qualifier['dimension'] !== 'location'
                    ) {
                        $spanEnd = $qualifier['end'];
                    }

                    $intents[] = self::namedIntent(
                        $qualifier['dimension'],
                        substr($prompt, $spanStart, $spanEnd - $spanStart),
                        $spanStart,
                        $spanEnd
                    );
                }
            }

            $claimStart = $prefix ? $qualifier['start'] : $valueStart;
            $claimEnd = $prefix ? $valueEnd : $qualifier['end'];
            $claims[] = [$claimStart, $claimEnd];
        }

        foreach (self::unqualifiedKnownMaterials($prompt, $tokens, $claims) as $atom) {
            $materialAtoms[] = $atom;
        }

        $materialIntent = self::materialIntent(
            $prompt,
            $tokens,
            $claims,
            $materialAtoms,
            $genericVideo
        );
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

    private static function lex(string $prompt): array
    {
        if (!preg_match_all(
            '/[\pL\pN]+(?:[\'’][\pL\pN]+)*(?:(?:[-\/])[\pL\pN]+)*|[&]|[,.;:!?]/u',
            $prompt,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        $tokens = [];
        foreach ($matches[0] as $match) {
            $raw = $match[0];
            $tokens[] = [
                'raw' => $raw,
                'norm' => self::lower($raw),
                'start' => $match[1],
                'end' => $match[1] + strlen($raw),
                'punctuation' => strlen($raw) === 1
                    && strpos(',.;:!?', $raw) !== false,
            ];
        }

        return $tokens;
    }

    private static function qualifiers(array $tokens): array
    {
        $qualifiers = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $word = $tokens[$index]['norm'];
            $dimension = null;
            $plural = false;
            $endIndex = $index + 1;

            if (
                $word === 'service'
                && isset($tokens[$index + 1])
                && in_array($tokens[$index + 1]['norm'], ['point', 'points'], true)
            ) {
                $dimension = 'service_point';
                $plural = $tokens[$index + 1]['norm'] === 'points';
                $endIndex = $index + 2;
            } elseif (
                $word === 'material'
                && isset($tokens[$index + 1])
                && in_array($tokens[$index + 1]['norm'], ['type', 'types'], true)
            ) {
                $dimension = 'material_type';
                $plural = $tokens[$index + 1]['norm'] === 'types';
                $endIndex = $index + 2;
            } elseif (in_array($word, ['location', 'locations'], true)) {
                $dimension = 'location';
                $plural = $word === 'locations';
            } elseif (in_array($word, ['collection', 'collections'], true)) {
                $dimension = 'location';
                $plural = $word === 'collections';
            } elseif (in_array($word, ['stack', 'stacks'], true)) {
                $dimension = 'location';
                $plural = $word === 'stacks';
            } elseif (in_array($word, ['room', 'rooms'], true)) {
                $dimension = 'location';
                $plural = $word === 'rooms';
            } elseif ($word === 'shelving') {
                $dimension = 'location';
            } elseif (in_array($word, ['library', 'libraries'], true)) {
                $dimension = 'library';
                $plural = $word === 'libraries';
            } elseif (in_array($word, ['campus', 'campuses'], true)) {
                $dimension = 'campus';
                $plural = $word === 'campuses';
            } elseif (in_array($word, ['institution', 'institutions'], true)) {
                $dimension = 'institution';
                $plural = $word === 'institutions';
            } elseif (in_array($word, ['format', 'formats'], true)) {
                $dimension = 'material_type';
                $plural = $word === 'formats';
            }

            if ($dimension === null) {
                continue;
            }

            $qualifiers[] = [
                'dimension' => $dimension,
                'plural' => $plural,
                'keyword' => $word,
                'token_start' => $index,
                'token_end' => $endIndex,
                'start' => $tokens[$index]['start'],
                'end' => $tokens[$endIndex - 1]['end'],
            ];
            $index = $endIndex - 1;
        }

        return $qualifiers;
    }

    private static function isPrefixQualifier(array $qualifier, array $tokens): bool
    {
        $next = $qualifier['token_end'];
        if (!isset($tokens[$next]) || $tokens[$next]['punctuation']) {
            return false;
        }
        if (
            self::isConnector($tokens[$next]['norm'])
            || self::isBoundaryPreposition($tokens[$next]['norm'])
        ) {
            return false;
        }

        if ($qualifier['dimension'] === 'location') {
            return true;
        }

        return $qualifier['dimension'] !== 'material_type' && $qualifier['plural'];
    }

    private static function prefixValueSpan(
        array $qualifier,
        array $tokens,
        array $claims
    ): ?array {
        $startIndex = $qualifier['token_end'];
        if (!isset($tokens[$startIndex])) {
            return null;
        }

        $start = $tokens[$startIndex]['start'];
        $end = self::nextHardBoundary($tokens, $startIndex);
        foreach ($claims as $claim) {
            if ($claim[0] >= $start) {
                $end = min($end, $claim[0]);
            }
        }

        if (!$qualifier['plural'] && $qualifier['dimension'] === 'location') {
            foreach ($tokens as $token) {
                if ($token['start'] < $start || $token['start'] >= $end) {
                    continue;
                }
                if ($token['norm'] === ',') {
                    $end = $token['start'];
                    break;
                }
            }
        }

        if ($qualifier['dimension'] === 'location') {
            $end = self::locationMaterialBoundary($tokens, $start, $end);
        }

        list($start, $end) = self::trimSpan($tokens, $start, $end, false);

        return $start < $end ? [$start, $end] : null;
    }

    private static function suffixValueSpan(
        array $qualifier,
        array $qualifiers,
        int $qualifierIndex,
        array $tokens
    ): ?array {
        if (
            self::isEmbeddedInPrefixLocation(
                $qualifier,
                $qualifiers,
                $qualifierIndex,
                $tokens
            )
        ) {
            return null;
        }

        $start = self::previousHardBoundary($tokens, $qualifier['token_start']);
        $end = $qualifier['start'];

        if (
            !$qualifier['plural']
            && $qualifier['dimension'] !== 'material_type'
        ) {
            $start = max(
                $start,
                self::previousCommaBoundary($tokens, $qualifier['token_start'])
            );
        }

        for ($index = $qualifierIndex - 1; $index >= 0; $index--) {
            $previous = $qualifiers[$index];
            if ($previous['end'] <= $start) {
                break;
            }
            if ($previous['end'] >= $end) {
                continue;
            }
            if (
                $qualifier['dimension'] === 'location'
                && $previous['dimension'] !== 'location'
            ) {
                continue;
            }

            $between = self::tokensInSpan($tokens, $previous['end'], $end);
            $separatorEnd = self::lastStructuralSeparatorEnd($between);
            if ($separatorEnd !== null) {
                $start = $separatorEnd;
                break;
            }
        }

        list($start, $end) = self::trimSpan($tokens, $start, $end, true);

        return $start < $end ? [$start, $end] : null;
    }

    private static function isEmbeddedInPrefixLocation(
        array $qualifier,
        array $qualifiers,
        int $qualifierIndex,
        array $tokens
    ): bool {
        for ($index = $qualifierIndex - 1; $index >= 0; $index--) {
            $previous = $qualifiers[$index];
            if (
                $previous['dimension'] !== 'location'
                || $previous['end'] >= $qualifier['start']
            ) {
                continue;
            }
            if (
                self::previousHardBoundary($tokens, $qualifier['token_start'])
                > $previous['end']
            ) {
                return false;
            }

            if (!self::isPrefixQualifier($previous, $tokens)) {
                return false;
            }

            $between = self::tokensInSpan(
                $tokens,
                $previous['end'],
                $qualifier['start']
            );
            if (
                $qualifier['dimension'] !== 'location'
                && self::lastStructuralSeparatorEnd($between) === null
            ) {
                return true;
            }

            return $qualifier['dimension'] === 'location'
                && !in_array($qualifier['keyword'], ['location', 'locations'], true);
        }

        return false;
    }

    private static function lastStructuralSeparatorEnd(array $tokens): ?int
    {
        $end = null;
        foreach ($tokens as $token) {
            if (
                $token['norm'] === ','
                || self::isConnector($token['norm'])
                || self::isBoundaryPreposition($token['norm'])
            ) {
                $end = $token['end'];
            }
        }

        return $end;
    }

    private static function splitQualifiedValues(
        array $tokens,
        int $start,
        int $end,
        bool $plural,
        string $dimension
    ): array {
        $valueTokens = self::tokensInSpan($tokens, $start, $end);
        if ($valueTokens === []) {
            return [];
        }

        $hasComma = false;
        foreach ($valueTokens as $token) {
            if ($token['norm'] === ',') {
                $hasComma = true;
                break;
            }
        }

        if ($hasComma) {
            return self::commaChunks($valueTokens);
        }

        $splitConnectors = $plural;
        if (!$splitConnectors && $dimension === 'material_type') {
            $splitConnectors = self::singularMaterialListIsExplicit($valueTokens);
        }

        if (!$splitConnectors) {
            return [[$valueTokens[0]['start'], $valueTokens[count($valueTokens) - 1]['end']]];
        }

        $chunks = [];
        $chunkStart = 0;
        foreach ($valueTokens as $index => $token) {
            if (!self::isConnector($token['norm'])) {
                continue;
            }
            self::appendTokenChunk($chunks, $valueTokens, $chunkStart, $index);
            $chunkStart = $index + 1;
        }
        self::appendTokenChunk($chunks, $valueTokens, $chunkStart, count($valueTokens));

        return $chunks;
    }

    private static function commaChunks(array $tokens): array
    {
        $chunks = [];
        $chunkStart = 0;
        foreach ($tokens as $index => $token) {
            if ($token['norm'] !== ',') {
                continue;
            }
            self::appendTokenChunk($chunks, $tokens, $chunkStart, $index);
            $chunkStart = $index + 1;
        }
        self::appendTokenChunk($chunks, $tokens, $chunkStart, count($tokens));

        return $chunks;
    }

    private static function appendTokenChunk(
        array &$chunks,
        array $tokens,
        int $start,
        int $end
    ): void {
        while ($start < $end && self::isConnector($tokens[$start]['norm'])) {
            $start++;
        }
        while ($end > $start && self::isConnector($tokens[$end - 1]['norm'])) {
            $end--;
        }
        if ($start >= $end) {
            return;
        }

        $chunks[] = [$tokens[$start]['start'], $tokens[$end - 1]['end']];
    }

    private static function singularMaterialListIsExplicit(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token['norm'] === 'and/or') {
                return true;
            }
        }

        foreach ($tokens as $index => $token) {
            if (!self::isConnector($token['norm'])) {
                continue;
            }
            $left = array_slice($tokens, 0, $index);
            $right = array_slice($tokens, $index + 1);
            if (
                self::exactKnownMaterialTokens($left) !== null
                || self::exactKnownMaterialTokens($right) !== null
            ) {
                return true;
            }
        }

        return false;
    }

    private static function materialAtom(
        string $prompt,
        int $start,
        int $end
    ): ?array {
        $raw = trim(substr($prompt, $start, $end - $start));
        if ($raw === '') {
            return null;
        }
        $normalized = self::normalizeTerm($raw);
        if (self::isGenericVideoTerm($normalized)) {
            return [
                'term' => null,
                'offset' => $start,
                'end' => $end,
                'generic' => true,
            ];
        }
        if (in_array($normalized, ['all', 'material', 'materials'], true)) {
            return null;
        }

        $known = self::knownMaterialTerm($normalized);

        return [
            'term' => $known === null ? $normalized : $known,
            'offset' => $start,
            'end' => $end,
            'generic' => false,
        ];
    }

    private static function unqualifiedKnownMaterials(
        string $prompt,
        array $tokens,
        array $claims
    ): array {
        $atoms = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (self::overlaps($token['start'], $token['end'], $claims)) {
                continue;
            }

            $endIndex = $index + 1;
            $term = self::knownMaterialTerm($token['norm']);
            if (
                $token['norm'] === 'vhs'
                && isset($tokens[$index + 1])
                && in_array($tokens[$index + 1]['norm'], ['tape', 'tapes'], true)
                && !self::overlaps(
                    $tokens[$index + 1]['start'],
                    $tokens[$index + 1]['end'],
                    $claims
                )
            ) {
                $term = 'vhs';
                $endIndex = $index + 2;
            } elseif (
                in_array($token['norm'], ['dvd', 'dvds'], true)
                && isset($tokens[$index + 2])
                && in_array($tokens[$index + 1]['norm'], ['and', '&'], true)
                && in_array($tokens[$index + 2]['norm'], ['blu-ray', 'bluray'], true)
                && !self::overlaps(
                    $tokens[$index + 2]['start'],
                    $tokens[$index + 2]['end'],
                    $claims
                )
            ) {
                $term = 'dvd';
                $endIndex = $index + 3;
            }

            if ($term === null) {
                continue;
            }

            $atoms[] = [
                'term' => $term,
                'offset' => $token['start'],
                'end' => $tokens[$endIndex - 1]['end'],
                'generic' => false,
            ];
            $index = $endIndex - 1;
        }

        return $atoms;
    }

    private static function materialIntent(
        string $prompt,
        array $tokens,
        array $claims,
        array $atoms,
        array $genericVideo
    ): ?array {
        if ($atoms !== []) {
            usort($atoms, function (array $left, array $right): int {
                return $left['offset'] <=> $right['offset'];
            });

            $known = [];
            $unknown = [];
            foreach ($atoms as $atom) {
                $term = $atom['term'];
                if (in_array($term, ['vhs', 'dvd', 'film'], true)) {
                    $known[$term] = true;
                } elseif (!isset($unknown[$term])) {
                    $unknown[$term] = $atom['offset'];
                }
            }

            $terms = [];
            foreach (['vhs', 'dvd', 'film'] as $term) {
                if (isset($known[$term])) {
                    $terms[] = $term;
                }
            }
            asort($unknown);
            foreach (array_keys($unknown) as $term) {
                $terms[] = $term;
            }

            $first = $atoms[0];

            return [
                'dimension' => 'material_type',
                'span' => substr($prompt, $first['offset'], $first['end'] - $first['offset']),
                'terms' => $terms,
                'selector' => null,
                'provenance' => 'explicit_prompt',
                'explicit' => true,
                '_offset' => $first['offset'],
                '_end' => $first['end'],
            ];
        }

        if (self::containsAllMaterials($tokens, $claims)) {
            return null;
        }

        foreach ($tokens as $index => $token) {
            if (
                !in_array($token['norm'], ['video', 'videos'], true)
                || self::overlaps($token['start'], $token['end'], $claims)
            ) {
                continue;
            }
            $genericVideo[] = [$token['start'], $token['end']];
            break;
        }

        if ($genericVideo === []) {
            return null;
        }
        usort($genericVideo, function (array $left, array $right): int {
            return $left[0] <=> $right[0];
        });
        $first = $genericVideo[0];

        return [
            'dimension' => 'material_type',
            'span' => substr($prompt, $first[0], $first[1] - $first[0]),
            'terms' => [],
            'selector' => 'physical_video',
            'provenance' => 'documented_default',
            'explicit' => false,
            '_offset' => $first[0],
            '_end' => $first[1],
        ];
    }

    private static function containsAllMaterials(array $tokens, array $claims): bool
    {
        foreach ($tokens as $index => $token) {
            if (
                $token['norm'] !== 'all'
                || !isset($tokens[$index + 1])
                || !in_array($tokens[$index + 1]['norm'], ['material', 'materials'], true)
            ) {
                continue;
            }
            if (!self::overlaps($token['start'], $tokens[$index + 1]['end'], $claims)) {
                return true;
            }
        }

        return false;
    }

    private static function trimSpan(
        array $tokens,
        int $start,
        int $end,
        bool $stripScaffolding
    ): array {
        $spanTokens = self::tokensInSpan($tokens, $start, $end);
        while (
            $spanTokens !== []
            && (
                $spanTokens[0]['norm'] === ','
                || self::isConnector($spanTokens[0]['norm'])
            )
        ) {
            array_shift($spanTokens);
        }
        while (
            $spanTokens !== []
            && (
                $spanTokens[count($spanTokens) - 1]['norm'] === ','
                || self::isConnector($spanTokens[count($spanTokens) - 1]['norm'])
                || self::isBoundaryPreposition(
                    $spanTokens[count($spanTokens) - 1]['norm']
                )
            )
        ) {
            array_pop($spanTokens);
        }
        if ($spanTokens === []) {
            return [$end, $end];
        }

        if ($stripScaffolding) {
            $spanTokens = self::afterLastPreposition($spanTokens);
            $spanTokens = self::afterScaffolding($spanTokens);
        }
        if ($spanTokens === []) {
            return [$end, $end];
        }

        return [
            $spanTokens[0]['start'],
            $spanTokens[count($spanTokens) - 1]['end'],
        ];
    }

    private static function afterLastPreposition(array $tokens): array
    {
        $boundary = null;
        foreach ($tokens as $index => $token) {
            if (self::isBoundaryPreposition($token['norm'])) {
                $boundary = $index;
            }
            if (
                $token['norm'] === 'held'
                && isset($tokens[$index + 1])
                && $tokens[$index + 1]['norm'] === 'by'
            ) {
                $boundary = $index + 1;
            }
        }

        return $boundary === null ? $tokens : array_slice($tokens, $boundary + 1);
    }

    private static function afterScaffolding(array $tokens): array
    {
        $words = array_column($tokens, 'norm');
        $count = count($words);
        if ($count === 0) {
            return [];
        }

        $verbs = [
            'is', 'are', 'was', 'were', 'does', 'do', 'did', 'uses', 'use',
            'has', 'have', 'had', 'holds', 'hold', 'contains', 'contain',
            'serves', 'serve', 'manages', 'manage', 'owns', 'own',
        ];
        $commands = ['show', 'find', 'list', 'display', 'get', 'give', 'report'];
        $index = 0;

        if (in_array($words[0], ['what', 'which', 'who', 'where'], true)) {
            $index = 1;
            while (
                $index < $count
                && (
                    in_array($words[$index], $verbs, true)
                    || $words[$index] === 'the'
                )
            ) {
                $index++;
            }

            return array_slice($tokens, $index);
        }

        if (
            in_array($words[0], ['can', 'could', 'would', 'will', 'may'], true)
            && ($words[1] ?? null) === 'you'
            && in_array($words[2] ?? null, $commands, true)
        ) {
            $index = 3;
            if (($words[$index] ?? null) === 'me') {
                $index++;
            }

            return array_slice($tokens, $index);
        }

        if ($words[0] === 'how' && ($words[1] ?? null) === 'many') {
            $index = 2;
            while ($index < $count && !in_array($words[$index], $verbs, true)) {
                $index++;
            }
            if ($index < $count) {
                $index++;
            }

            return array_slice($tokens, $index);
        }

        if (in_array($words[0], ['does', 'do', 'did'], true)) {
            return array_slice($tokens, 1);
        }

        if ($words[0] === 'tell' && ($words[1] ?? null) === 'me') {
            return array_slice($tokens, 2);
        }

        if (in_array($words[0], $commands, true)) {
            $index = 1;
            if (($words[$index] ?? null) === 'me') {
                $index++;
            }
            while (
                $index < $count
                && in_array(
                    $words[$index],
                    ['all', 'the', 'of', 'item', 'items', 'this', 'can', 'be'],
                    true
                )
            ) {
                $index++;
            }

            return array_slice($tokens, $index);
        }

        return $tokens;
    }

    private static function locationMaterialBoundary(
        array $tokens,
        int $start,
        int $end
    ): int {
        $spanTokens = self::tokensInSpan($tokens, $start, $end);
        foreach ($spanTokens as $index => $token) {
            if (!$token['punctuation'] && !self::isConnector($token['norm'])) {
                continue;
            }
            $following = array_slice($spanTokens, $index + 1);
            while (
                $following !== []
                && (
                    $following[0]['norm'] === ','
                    || self::isConnector($following[0]['norm'])
                )
            ) {
                array_shift($following);
            }
            if (
                $following !== []
                && (
                    self::exactKnownMaterialTokens($following) !== null
                    || in_array($following[0]['norm'], ['video', 'videos'], true)
                )
            ) {
                return $token['start'];
            }
        }

        return $end;
    }

    private static function exactKnownMaterialTokens(array $tokens): ?string
    {
        if ($tokens === []) {
            return null;
        }
        $words = array_column($tokens, 'norm');
        $normalized = implode(' ', $words);

        return self::knownMaterialTerm($normalized);
    }

    private static function knownMaterialTerm(string $term): ?string
    {
        $term = preg_replace('/\s+/u', ' ', trim($term));
        if (!is_string($term)) {
            return null;
        }
        if (in_array($term, ['vhs', 'vhs tape', 'vhs tapes'], true)) {
            return 'vhs';
        }
        if (
            in_array(
                $term,
                [
                    'dvd', 'dvds', 'blu-ray', 'blu-rays', 'bluray', 'blurays',
                    'dvd/blu-ray', 'dvd/blu-rays', 'dvd and blu-ray',
                    'dvd and blu-rays', 'dvd & blu-ray', 'dvd & blu-rays',
                ],
                true
            )
        ) {
            return 'dvd';
        }
        if (in_array($term, ['film', 'films'], true)) {
            return 'film';
        }

        return null;
    }

    private static function isGenericVideoTerm(string $term): bool
    {
        return in_array(
            $term,
            [
                'video', 'videos', 'video material', 'video materials',
                'video format', 'video formats',
            ],
            true
        );
    }

    private static function normalizeTerm(string $term): string
    {
        $term = self::lower(trim($term));
        $term = preg_replace('/\s+/u', ' ', $term);

        return is_string($term) ? $term : '';
    }

    private static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtr(strtolower($value), [
            'À' => 'à', 'Á' => 'á', 'Â' => 'â', 'Ã' => 'ã', 'Ä' => 'ä',
            'Å' => 'å', 'Æ' => 'æ', 'Ç' => 'ç', 'È' => 'è', 'É' => 'é',
            'Ê' => 'ê', 'Ë' => 'ë', 'Ì' => 'ì', 'Í' => 'í', 'Î' => 'î',
            'Ï' => 'ï', 'Ð' => 'ð', 'Ñ' => 'ñ', 'Ò' => 'ò', 'Ó' => 'ó',
            'Ô' => 'ô', 'Õ' => 'õ', 'Ö' => 'ö', 'Ø' => 'ø', 'Ù' => 'ù',
            'Ú' => 'ú', 'Û' => 'û', 'Ü' => 'ü', 'Ý' => 'ý', 'Þ' => 'þ',
            'Ā' => 'ā', 'Ă' => 'ă', 'Ą' => 'ą', 'Ć' => 'ć', 'Ĉ' => 'ĉ',
            'Ċ' => 'ċ', 'Č' => 'č', 'Ď' => 'ď', 'Đ' => 'đ', 'Ē' => 'ē',
            'Ĕ' => 'ĕ', 'Ė' => 'ė', 'Ę' => 'ę', 'Ě' => 'ě', 'Ĝ' => 'ĝ',
            'Ğ' => 'ğ', 'Ġ' => 'ġ', 'Ģ' => 'ģ', 'Ĥ' => 'ĥ', 'Ħ' => 'ħ',
            'Ĩ' => 'ĩ', 'Ī' => 'ī', 'Ĭ' => 'ĭ', 'Į' => 'į', 'İ' => 'i',
            'Ĵ' => 'ĵ', 'Ķ' => 'ķ', 'Ĺ' => 'ĺ', 'Ļ' => 'ļ', 'Ľ' => 'ľ',
            'Ŀ' => 'ŀ', 'Ł' => 'ł', 'Ń' => 'ń', 'Ņ' => 'ņ', 'Ň' => 'ň',
            'Ŋ' => 'ŋ', 'Ō' => 'ō', 'Ŏ' => 'ŏ', 'Ő' => 'ő', 'Œ' => 'œ',
            'Ŕ' => 'ŕ', 'Ŗ' => 'ŗ', 'Ř' => 'ř', 'Ś' => 'ś', 'Ŝ' => 'ŝ',
            'Ş' => 'ş', 'Š' => 'š', 'Ţ' => 'ţ', 'Ť' => 'ť', 'Ŧ' => 'ŧ',
            'Ũ' => 'ũ', 'Ū' => 'ū', 'Ŭ' => 'ŭ', 'Ů' => 'ů', 'Ű' => 'ű',
            'Ų' => 'ų', 'Ŵ' => 'ŵ', 'Ŷ' => 'ŷ', 'Ÿ' => 'ÿ', 'Ź' => 'ź',
            'Ż' => 'ż', 'Ž' => 'ž',
        ]);
    }

    private static function nextHardBoundary(array $tokens, int $startIndex): int
    {
        foreach ($tokens as $index => $token) {
            if ($index < $startIndex) {
                continue;
            }
            if (in_array($token['norm'], self::HARD_PUNCTUATION, true)) {
                return $token['start'];
            }
        }

        if ($tokens === []) {
            return 0;
        }

        return $tokens[count($tokens) - 1]['end'];
    }

    private static function previousHardBoundary(array $tokens, int $endIndex): int
    {
        $start = 0;
        for ($index = 0; $index < $endIndex; $index++) {
            if (in_array($tokens[$index]['norm'], self::HARD_PUNCTUATION, true)) {
                $start = $tokens[$index]['end'];
            }
        }

        return $start;
    }

    private static function previousCommaBoundary(array $tokens, int $endIndex): int
    {
        $start = 0;
        for ($index = 0; $index < $endIndex; $index++) {
            if ($tokens[$index]['norm'] === ',') {
                $start = $tokens[$index]['end'];
            }
        }

        return $start;
    }

    private static function tokensInSpan(array $tokens, int $start, int $end): array
    {
        return array_values(array_filter(
            $tokens,
            function (array $token) use ($start, $end): bool {
                return $token['start'] >= $start && $token['end'] <= $end;
            }
        ));
    }

    private static function isConnector(string $word): bool
    {
        return in_array($word, ['and', 'or', 'and/or'], true);
    }

    private static function isBoundaryPreposition(string $word): bool
    {
        return in_array(
            $word,
            [
                'at', 'in', 'from', 'for', 'with', 'within', 'inside',
                'near', 'on', 'by', 'about',
            ],
            true
        );
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
