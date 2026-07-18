<?php

namespace app\services;

require_once __DIR__ . '/SqlSelectStructureService.php';

/**
 * Conservative, non-executing analysis of safety-approved exploratory SQL.
 *
 * This intentionally recognizes only the SELECT/CTE shapes needed by semantic
 * conformance. Unsupported shapes are reported as ambiguous instead of being
 * guessed at.
 */
class ExploratorySqlAnalysisService
{
    private const CLAUSE_STARTS = [
        'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET', 'FETCH',
        'UNION', 'INTERSECT', 'EXCEPT', 'WINDOW', 'FOR',
    ];

    private const SOURCE_STOPS = [
        'ON', 'USING', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS',
        'NATURAL', 'OUTER', 'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT',
        'OFFSET', 'FETCH', 'UNION', 'INTERSECT', 'EXCEPT', 'WINDOW', 'FOR',
    ];

    public static function analyze(string $sql): array
    {
        $empty = self::emptyAnalysis();
        try {
            $tokens = SqlSelectStructureService::tokenizeForAnalysis($sql);
        } catch (\InvalidArgumentException $exception) {
            $empty['ambiguous'] = true;
            return $empty;
        }

        if ($tokens === []) {
            $empty['ambiguous'] = true;
            return $empty;
        }
        if (($tokens[count($tokens) - 1]['value'] ?? '') === ';') {
            array_pop($tokens);
        }

        $parsed = self::parseCtes($tokens);
        $ctes = $parsed['ctes'];
        $knownCtes = array_keys($ctes);
        $finalScope = self::analyzeSelectScope($parsed['finalTokens'], $knownCtes);
        $ambiguous = !empty($parsed['ambiguous']) || !empty($finalScope['ambiguous']);
        foreach ($ctes as $cte) {
            if (!empty($cte['ambiguous'])) {
                $ambiguous = true;
                break;
            }
        }

        return [
            'ctes' => $ctes,
            'tables' => $finalScope['tables'],
            'selectItems' => $finalScope['selectItems'],
            'predicates' => $finalScope['predicates'],
            'groupBy' => $finalScope['groupBy'],
            'orderBy' => $finalScope['orderBy'],
            'limit' => $finalScope['limit'],
            'formattedAliases' => $finalScope['formattedAliases'],
            'ambiguous' => $ambiguous,
        ];
    }

    private static function parseCtes(array $tokens): array
    {
        $result = ['ctes' => [], 'finalTokens' => $tokens, 'ambiguous' => false];
        if (!self::isKeyword($tokens[0] ?? [], 'WITH')) {
            return $result;
        }

        $cursor = 1;
        if (self::isKeyword($tokens[$cursor] ?? [], 'RECURSIVE')) {
            $result['ambiguous'] = true;
            $cursor++;
        }

        $knownCtes = [];
        while (isset($tokens[$cursor])) {
            if (($tokens[$cursor]['depth'] ?? -1) !== 0 || ($tokens[$cursor]['kind'] ?? '') !== 'identifier') {
                $result['ambiguous'] = true;
                break;
            }
            $name = $tokens[$cursor]['value'];
            if (isset($result['ctes'][$name])) {
                $result['ambiguous'] = true;
            }
            $cursor++;
            if (($tokens[$cursor]['value'] ?? '') === '(') {
                // CTE output-column lists are valid SQL but outside this focused shape.
                $result['ambiguous'] = true;
                break;
            }
            if (!self::isKeyword($tokens[$cursor] ?? [], 'AS')) {
                $result['ambiguous'] = true;
                break;
            }
            $cursor++;
            if (($tokens[$cursor]['value'] ?? '') !== '(' || ($tokens[$cursor]['depth'] ?? -1) !== 0) {
                $result['ambiguous'] = true;
                break;
            }

            $open = $cursor;
            $cursor++;
            while (isset($tokens[$cursor]) && !(($tokens[$cursor]['value'] ?? '') === ')' && ($tokens[$cursor]['depth'] ?? -1) === 0)) {
                $cursor++;
            }
            if (!isset($tokens[$cursor])) {
                $result['ambiguous'] = true;
                break;
            }

            $scope = self::analyzeSelectScope(array_slice($tokens, $open + 1, $cursor - $open - 1), $knownCtes);
            $result['ctes'][$name] = [
                'tables' => $scope['tables'],
                'dependencies' => $scope['dependencies'],
                'selectItems' => $scope['selectItems'],
                'predicates' => $scope['predicates'],
                'groupBy' => $scope['groupBy'],
                'joins' => $scope['joins'],
                'ambiguous' => $scope['ambiguous'],
            ];
            $knownCtes[] = $name;
            $cursor++;
            if (($tokens[$cursor]['value'] ?? '') === ',' && ($tokens[$cursor]['depth'] ?? -1) === 0) {
                $cursor++;
                continue;
            }
            $result['finalTokens'] = array_slice($tokens, $cursor);
            return $result;
        }

        $result['finalTokens'] = array_slice($tokens, $cursor);
        return $result;
    }

    private static function analyzeSelectScope(array $tokens, array $knownCtes): array
    {
        $scope = self::emptyScope();
        if ($tokens === []) {
            $scope['ambiguous'] = true;
            return $scope;
        }
        $base = self::baseDepth($tokens);
        $selectIndexes = [];
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) !== $base) {
                continue;
            }
            if (self::isKeyword($token, 'SELECT')) {
                $selectIndexes[] = $index;
            }
            if (self::isKeyword($token, 'UNION') || self::isKeyword($token, 'INTERSECT') || self::isKeyword($token, 'EXCEPT')) {
                $scope['ambiguous'] = true;
            }
        }
        if (count($selectIndexes) !== 1) {
            $scope['ambiguous'] = true;
            return $scope;
        }

        $selectIndex = $selectIndexes[0];
        if (!self::hasReliableClauses($tokens, $base)) {
            $scope['ambiguous'] = true;
        }
        $fromIndex = self::findKeywordIndex($tokens, 'FROM', $selectIndex + 1, $base);
        $selectEnd = $fromIndex;
        if ($selectEnd === null) {
            $selectEnd = self::firstClauseIndex($tokens, $selectIndex + 1, $base);
        }
        if ($selectEnd === null) {
            $selectEnd = count($tokens);
        }
        $selectTokens = array_slice($tokens, $selectIndex + 1, $selectEnd - $selectIndex - 1);
        foreach (self::splitTopLevel($selectTokens, ',') as $itemTokens) {
            if ($itemTokens === []) {
                $scope['ambiguous'] = true;
                continue;
            }
            $alias = self::outputAlias($itemTokens);
            $expressionTokens = self::withoutOutputAlias($itemTokens);
            $functions = self::functionNames($expressionTokens);
            $scope['selectItems'][] = [
                'expression' => self::expressionText($expressionTokens),
                'alias' => $alias,
                'referencedAliases' => self::referencedAliases($expressionTokens),
                'functions' => $functions,
                'aggregate' => count(array_intersect($functions, ['avg', 'count', 'max', 'min', 'sum'])) > 0,
            ];
            if ($alias !== null) {
                if (isset($scope['outputAliases'][$alias])) {
                    $scope['ambiguous'] = true;
                }
                $scope['outputAliases'][$alias] = true;
                if (in_array('to_char', $functions, true) || self::hasTextFormatting($expressionTokens)) {
                    $scope['formattedAliases'][] = $alias;
                }
            }
        }

        if ($fromIndex !== null) {
            self::analyzeSources($tokens, $fromIndex, $base, $knownCtes, $scope);
        }

        $whereTokens = self::clauseSlice($tokens, 'WHERE', ['GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT', 'OFFSET', 'FETCH', 'WINDOW', 'FOR']);
        $predicateGroups = $whereTokens === [] ? [] : [$whereTokens];
        foreach ($scope['joins'] as $join) {
            if ($join['predicate'] !== '') {
                $predicateGroups[] = $join['predicateTokens'];
            }
        }
        $allPredicateTokens = [];
        foreach ($predicateGroups as $group) {
            $allPredicateTokens = array_merge($allPredicateTokens, $group);
        }
        $scope['predicates'] = [
            'where' => $whereTokens === [] ? null : self::expressionText($whereTokens),
            'joins' => array_values(array_filter(array_column($scope['joins'], 'predicate'))),
            'dateColumns' => self::datePredicateColumns($allPredicateTokens),
            'governedFilters' => self::governedFilters($allPredicateTokens),
        ];
        foreach ($scope['joins'] as &$join) {
            unset($join['predicateTokens']);
        }
        unset($join);

        $groupTokens = self::clauseSlice($tokens, 'GROUP BY', ['HAVING', 'ORDER BY', 'LIMIT', 'OFFSET', 'FETCH', 'WINDOW', 'FOR']);
        foreach (self::splitTopLevel($groupTokens, ',') as $groupItem) {
            if ($groupItem !== []) {
                $scope['groupBy'][] = self::expressionText($groupItem);
            }
        }

        $orderTokens = self::clauseSlice($tokens, 'ORDER BY', ['LIMIT', 'OFFSET', 'FETCH', 'WINDOW', 'FOR']);
        foreach (self::splitTopLevel($orderTokens, ',') as $orderItem) {
            if ($orderItem === []) {
                continue;
            }
            $direction = 'ASC';
            $last = count($orderItem) - 1;
            if (self::isKeyword($orderItem[$last], 'ASC') || self::isKeyword($orderItem[$last], 'DESC')) {
                $direction = strtoupper($orderItem[$last]['value']);
                array_pop($orderItem);
            }
            if ($orderItem === []) {
                $scope['ambiguous'] = true;
                continue;
            }
            $scope['orderBy'][] = ['expression' => self::expressionText($orderItem), 'direction' => $direction];
        }

        $limitTokens = self::clauseSlice($tokens, 'LIMIT', ['OFFSET', 'FETCH', 'WINDOW', 'FOR']);
        $scope['limit'] = $limitTokens === [] ? null : self::expressionText($limitTokens);
        unset($scope['outputAliases']);
        return $scope;
    }

    private static function splitTopLevel(array $tokens, string $separator): array
    {
        if ($tokens === []) {
            return [];
        }
        $base = self::baseDepth($tokens);
        $parts = [];
        $start = 0;
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) === $base && ($token['value'] ?? '') === $separator) {
                $parts[] = array_slice($tokens, $start, $index - $start);
                $start = $index + 1;
            }
        }
        $parts[] = array_slice($tokens, $start);
        return $parts;
    }

    private static function clauseSlice(array $tokens, string $start, array $ends): array
    {
        if ($tokens === []) {
            return [];
        }
        $base = self::baseDepth($tokens);
        $startWords = explode(' ', $start);
        $startIndex = self::findKeywordSequence($tokens, $startWords, 0, $base);
        if ($startIndex === null) {
            return [];
        }
        $contentStart = $startIndex + count($startWords);
        $endIndex = count($tokens);
        foreach ($ends as $end) {
            $candidate = self::findKeywordSequence($tokens, explode(' ', $end), $contentStart, $base);
            if ($candidate !== null && $candidate < $endIndex) {
                $endIndex = $candidate;
            }
        }
        return array_slice($tokens, $contentStart, $endIndex - $contentStart);
    }

    private static function expressionText(array $tokens): string
    {
        $text = '';
        $previous = null;
        foreach ($tokens as $token) {
            $value = (string)($token['value'] ?? '');
            $noSpaceBefore = in_array($value, [')', ',', '.', '::', ';'], true);
            $noSpaceAfterPrevious = in_array($previous, ['(', '.', '::'], true);
            if ($text !== '' && !$noSpaceBefore && !$noSpaceAfterPrevious) {
                $text .= ' ';
            }
            $text .= $value;
            $previous = $value;
        }
        return trim($text);
    }

    private static function outputAlias(array $tokens): ?string
    {
        if ($tokens === []) {
            return null;
        }
        $base = self::baseDepth($tokens);
        for ($index = count($tokens) - 2; $index >= 0; $index--) {
            if (($tokens[$index]['depth'] ?? -1) === $base && self::isKeyword($tokens[$index], 'AS')) {
                $alias = $tokens[$index + 1] ?? [];
                return ($alias['kind'] ?? '') === 'identifier' ? $alias['value'] : null;
            }
        }
        $last = count($tokens) - 1;
        if (($tokens[$last]['kind'] ?? '') !== 'identifier' || ($tokens[$last]['depth'] ?? -1) !== $base) {
            return null;
        }
        if ($last === 0 || ($tokens[$last - 1]['value'] ?? '') === '.') {
            return null;
        }
        if (in_array(strtoupper($tokens[$last]['value']), self::SOURCE_STOPS, true)) {
            return null;
        }
        return $tokens[$last]['value'];
    }

    private static function referencedAliases(array $tokens): array
    {
        $aliases = [];
        for ($index = 0; $index + 2 < count($tokens); $index++) {
            if (($tokens[$index]['kind'] ?? '') === 'identifier'
                && ($tokens[$index + 1]['value'] ?? '') === '.'
                && ($tokens[$index + 2]['kind'] ?? '') === 'identifier') {
                $aliases[] = $tokens[$index]['value'];
            }
        }
        return array_values(array_unique($aliases));
    }

    private static function datePredicateColumns(array $tokens): array
    {
        $columns = [];
        for ($index = 0; $index + 2 < count($tokens); $index++) {
            if (($tokens[$index]['kind'] ?? '') !== 'identifier'
                || ($tokens[$index + 1]['value'] ?? '') !== '.'
                || ($tokens[$index + 2]['kind'] ?? '') !== 'identifier') {
                continue;
            }
            $column = $tokens[$index + 2]['value'];
            if (strpos($column, 'date') !== false || substr($column, -3) === '_at') {
                $columns[] = $tokens[$index]['value'] . '.' . $column;
            }
        }
        return array_values(array_unique($columns));
    }

    private static function governedFilters(array $tokens): array
    {
        $columns = [];
        for ($index = 0; $index + 2 < count($tokens); $index++) {
            if (($tokens[$index]['kind'] ?? '') !== 'identifier'
                || ($tokens[$index + 1]['value'] ?? '') !== '.'
                || ($tokens[$index + 2]['kind'] ?? '') !== 'identifier') {
                continue;
            }
            if (self::hasLiteralFilterAfter($tokens, $index + 3)) {
                $columns[] = $tokens[$index]['value'] . '.' . $tokens[$index + 2]['value'];
            }
        }
        return array_values(array_unique($columns));
    }

    private static function hasLiteralFilterAfter(array $tokens, int $start): bool
    {
        $operators = ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'ILIKE'];
        $limit = min(count($tokens), $start + 6);
        for ($index = $start; $index < $limit; $index++) {
            $operator = strtoupper((string)($tokens[$index]['value'] ?? ''));
            if (in_array($operator, $operators, true)) {
                return in_array($tokens[$index + 1]['kind'] ?? '', ['string', 'number'], true);
            }
            if ($operator === 'IN') {
                return ($tokens[$index + 1]['value'] ?? '') === '(';
            }
            if (in_array($operator, ['AND', 'OR', ','], true)) {
                return false;
            }
        }
        return false;
    }

    private static function analyzeSources(array $tokens, int $fromIndex, int $base, array $knownCtes, array &$scope): void
    {
        $count = count($tokens);
        $cursor = $fromIndex;
        while ($cursor < $count) {
            if (($tokens[$cursor]['depth'] ?? -1) !== $base) {
                $cursor++;
                continue;
            }
            if (in_array(strtoupper($tokens[$cursor]['value'] ?? ''), self::CLAUSE_STARTS, true)) {
                break;
            }

            $joinType = null;
            $sourceStart = null;
            if (self::isKeyword($tokens[$cursor], 'FROM') || ($tokens[$cursor]['value'] ?? '') === ',') {
                $sourceStart = $cursor + 1;
            } elseif (self::isKeyword($tokens[$cursor], 'JOIN')) {
                $joinType = self::joinTypeBefore($tokens, $cursor, $base);
                $sourceStart = $cursor + 1;
            }
            if ($sourceStart === null) {
                $cursor++;
                continue;
            }

            while (self::isKeyword($tokens[$sourceStart] ?? [], 'ONLY')) {
                $sourceStart++;
            }
            if (self::isKeyword($tokens[$sourceStart] ?? [], 'LATERAL') || ($tokens[$sourceStart]['value'] ?? '') === '(') {
                $scope['ambiguous'] = true;
                $cursor = $sourceStart + 1;
                continue;
            }
            if (($tokens[$sourceStart]['kind'] ?? '') !== 'identifier') {
                $scope['ambiguous'] = true;
                $cursor = $sourceStart + 1;
                continue;
            }

            $parts = [$tokens[$sourceStart]['value']];
            $afterSource = $sourceStart + 1;
            while (isset($tokens[$afterSource + 1])
                && ($tokens[$afterSource]['value'] ?? '') === '.'
                && ($tokens[$afterSource + 1]['kind'] ?? '') === 'identifier') {
                $parts[] = $tokens[$afterSource + 1]['value'];
                $afterSource += 2;
            }
            if (($tokens[$afterSource]['value'] ?? '') === '(') {
                $scope['ambiguous'] = true;
                $cursor = $afterSource + 1;
                continue;
            }
            $source = implode('.', $parts);
            $alias = end($parts);
            if (self::isKeyword($tokens[$afterSource] ?? [], 'AS')) {
                $afterSource++;
            }
            if (($tokens[$afterSource]['kind'] ?? '') === 'identifier'
                && ($tokens[$afterSource]['depth'] ?? -1) === $base
                && !in_array(strtoupper($tokens[$afterSource]['value']), self::SOURCE_STOPS, true)) {
                $alias = $tokens[$afterSource]['value'];
                $afterSource++;
            }

            if (count($parts) === 1 && in_array($source, $knownCtes, true)) {
                $scope['dependencies'][] = $source;
            } else {
                $scope['tables'][] = $source;
            }

            if ($joinType !== null) {
                $predicateTokens = [];
                $onIndex = self::findKeywordIndex($tokens, 'ON', $afterSource, $base);
                $nextBoundary = self::nextSourceOrClauseIndex($tokens, $afterSource, $base);
                if ($onIndex !== null && ($nextBoundary === null || $onIndex < $nextBoundary)) {
                    $predicateEnd = self::nextSourceOrClauseIndex($tokens, $onIndex + 1, $base);
                    if ($predicateEnd === null) {
                        $predicateEnd = count($tokens);
                    }
                    $predicateTokens = array_slice($tokens, $onIndex + 1, $predicateEnd - $onIndex - 1);
                } else {
                    $scope['ambiguous'] = true;
                }
                $scope['joins'][] = [
                    'type' => $joinType,
                    'source' => $source,
                    'alias' => $alias,
                    'predicate' => self::expressionText($predicateTokens),
                    'referencedAliases' => self::referencedAliases($predicateTokens),
                    'predicateTokens' => $predicateTokens,
                ];
            }
            $cursor = $afterSource;
        }
        $scope['tables'] = array_values(array_unique($scope['tables']));
        sort($scope['tables'], SORT_STRING);
        $scope['dependencies'] = array_values(array_unique($scope['dependencies']));
    }

    private static function withoutOutputAlias(array $tokens): array
    {
        $alias = self::outputAlias($tokens);
        if ($alias === null) {
            return $tokens;
        }
        $last = count($tokens) - 1;
        if ($last > 0 && self::isKeyword($tokens[$last - 1], 'AS')) {
            return array_slice($tokens, 0, $last - 1);
        }
        return array_slice($tokens, 0, $last);
    }

    private static function functionNames(array $tokens): array
    {
        $functions = [];
        for ($index = 0; $index + 1 < count($tokens); $index++) {
            if (($tokens[$index]['kind'] ?? '') === 'identifier' && ($tokens[$index + 1]['value'] ?? '') === '(') {
                $functions[] = $tokens[$index]['value'];
            }
        }
        return array_values(array_unique($functions));
    }

    private static function hasTextFormatting(array $tokens): bool
    {
        foreach ($tokens as $index => $token) {
            if (($token['value'] ?? '') === '|' && ($tokens[$index + 1]['value'] ?? '') === '|') {
                return true;
            }
            if (($token['kind'] ?? '') === 'string' && strpos((string)$token['value'], '$') !== false) {
                return true;
            }
        }
        return false;
    }

    private static function findKeywordIndex(array $tokens, string $keyword, int $start, int $depth): ?int
    {
        return self::findKeywordSequence($tokens, [$keyword], $start, $depth);
    }

    private static function findKeywordSequence(array $tokens, array $words, int $start, int $depth): ?int
    {
        $limit = count($tokens) - count($words);
        for ($index = $start; $index <= $limit; $index++) {
            $matches = true;
            foreach ($words as $offset => $word) {
                $token = $tokens[$index + $offset] ?? [];
                if (($token['depth'] ?? -1) !== $depth || !self::isKeyword($token, $word)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $index;
            }
        }
        return null;
    }

    private static function firstClauseIndex(array $tokens, int $start, int $depth): ?int
    {
        $found = null;
        foreach (self::CLAUSE_STARTS as $keyword) {
            $index = self::findKeywordIndex($tokens, $keyword, $start, $depth);
            if ($index !== null && ($found === null || $index < $found)) {
                $found = $index;
            }
        }
        return $found;
    }

    private static function nextSourceOrClauseIndex(array $tokens, int $start, int $depth): ?int
    {
        for ($index = $start; $index < count($tokens); $index++) {
            if (($tokens[$index]['depth'] ?? -1) !== $depth) {
                continue;
            }
            if (($tokens[$index]['value'] ?? '') === ',' || self::isKeyword($tokens[$index], 'JOIN')
                || in_array(strtoupper($tokens[$index]['value'] ?? ''), ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'NATURAL'], true)
                || in_array(strtoupper($tokens[$index]['value'] ?? ''), self::CLAUSE_STARTS, true)) {
                return $index;
            }
        }
        return null;
    }

    private static function hasReliableClauses(array $tokens, int $depth): bool
    {
        foreach (['FROM', 'WHERE', 'HAVING', 'LIMIT', 'OFFSET', 'FETCH', 'WINDOW', 'FOR'] as $keyword) {
            $count = 0;
            foreach ($tokens as $token) {
                if (($token['depth'] ?? -1) === $depth && self::isKeyword($token, $keyword)) {
                    $count++;
                }
            }
            if ($count > 1) {
                return false;
            }
        }
        foreach (['GROUP', 'ORDER'] as $keyword) {
            $found = [];
            foreach ($tokens as $index => $token) {
                if (($token['depth'] ?? -1) === $depth && self::isKeyword($token, $keyword)) {
                    $found[] = $index;
                }
            }
            if (count($found) > 1) {
                return false;
            }
            if ($found !== [] && !self::isKeyword($tokens[$found[0] + 1] ?? [], 'BY')) {
                return false;
            }
        }
        return true;
    }

    private static function joinTypeBefore(array $tokens, int $joinIndex, int $depth): string
    {
        $previous = $tokens[$joinIndex - 1] ?? [];
        if (($previous['depth'] ?? -1) === $depth && in_array(strtoupper($previous['value'] ?? ''), ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'], true)) {
            return strtoupper($previous['value']);
        }
        if (($previous['depth'] ?? -1) === $depth && self::isKeyword($previous, 'OUTER')) {
            $type = strtoupper($tokens[$joinIndex - 2]['value'] ?? '');
            return in_array($type, ['LEFT', 'RIGHT', 'FULL'], true) ? $type : 'UNKNOWN';
        }
        return 'INNER';
    }

    private static function baseDepth(array $tokens): int
    {
        $depths = array_column($tokens, 'depth');
        return $depths === [] ? 0 : min($depths);
    }

    private static function isKeyword(array $token, string $keyword): bool
    {
        return ($token['kind'] ?? '') === 'identifier'
            && strtoupper((string)($token['value'] ?? '')) === $keyword;
    }

    private static function emptyScope(): array
    {
        return [
            'tables' => [],
            'dependencies' => [],
            'selectItems' => [],
            'predicates' => ['where' => null, 'joins' => [], 'dateColumns' => [], 'governedFilters' => []],
            'groupBy' => [],
            'orderBy' => [],
            'limit' => null,
            'formattedAliases' => [],
            'joins' => [],
            'outputAliases' => [],
            'ambiguous' => false,
        ];
    }

    private static function emptyAnalysis(): array
    {
        $scope = self::emptyScope();
        return [
            'ctes' => [],
            'tables' => [],
            'selectItems' => [],
            'predicates' => $scope['predicates'],
            'groupBy' => [],
            'orderBy' => [],
            'limit' => null,
            'formattedAliases' => [],
            'ambiguous' => false,
        ];
    }
}
