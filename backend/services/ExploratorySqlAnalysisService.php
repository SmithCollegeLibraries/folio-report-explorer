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

    private const EXPRESSION_TERMINALS = [
        'END', 'ELSE', 'THEN', 'WHEN', 'NULL', 'TRUE', 'FALSE',
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
            'sourceAliases' => $finalScope['sourceAliases'],
            'selectItems' => $finalScope['selectItems'],
            'predicates' => $finalScope['predicates'],
            'groupBy' => $finalScope['groupBy'],
            'joins' => $finalScope['joins'],
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

        $declaredCtes = self::declaredCteNames($tokens);
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

            $scope = self::analyzeSelectScope(array_slice($tokens, $open + 1, $cursor - $open - 1), $declaredCtes);
            foreach ($scope['dependencies'] as $dependency) {
                if ($dependency === $name || !in_array($dependency, $knownCtes, true)) {
                    $scope['ambiguous'] = true;
                }
            }
            $result['ctes'][$name] = [
                'tables' => $scope['tables'],
                'dependencies' => $scope['dependencies'],
                'sourceAliases' => $scope['sourceAliases'],
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

    private static function declaredCteNames(array $tokens): array
    {
        if (!self::isKeyword($tokens[0] ?? [], 'WITH')) {
            return [];
        }
        $names = [];
        $cursor = self::isKeyword($tokens[1] ?? [], 'RECURSIVE') ? 2 : 1;
        while (isset($tokens[$cursor])
            && ($tokens[$cursor]['depth'] ?? -1) === 0
            && ($tokens[$cursor]['kind'] ?? '') === 'identifier') {
            $names[] = $tokens[$cursor]['value'];
            $cursor++;
            if (!self::isKeyword($tokens[$cursor] ?? [], 'AS')) {
                break;
            }
            $cursor++;
            if (($tokens[$cursor]['value'] ?? '') !== '(' || ($tokens[$cursor]['depth'] ?? -1) !== 0) {
                break;
            }
            $cursor++;
            while (isset($tokens[$cursor])
                && !(($tokens[$cursor]['value'] ?? '') === ')' && ($tokens[$cursor]['depth'] ?? -1) === 0)) {
                $cursor++;
            }
            if (!isset($tokens[$cursor])) {
                break;
            }
            $cursor++;
            if (($tokens[$cursor]['value'] ?? '') !== ',' || ($tokens[$cursor]['depth'] ?? -1) !== 0) {
                break;
            }
            $cursor++;
        }
        return array_values(array_unique($names));
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
            if (($token['depth'] ?? -1) > $base && self::isKeyword($token, 'SELECT')) {
                $scope['ambiguous'] = true;
            }
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
            $ambiguousAlias = self::hasAmbiguousAliasBoundary($itemTokens);
            if ($ambiguousAlias) {
                $scope['ambiguous'] = true;
                $alias = null;
                $expressionTokens = $itemTokens;
            } else {
                $alias = self::outputAlias($itemTokens);
                $expressionTokens = self::withoutOutputAlias($itemTokens);
            }
            $functions = self::functionNames($expressionTokens);
            $scope['selectItems'][] = [
                'expression' => self::expressionText($expressionTokens),
                'alias' => $alias,
                'referencedAliases' => self::referencedAliases($expressionTokens),
                'referencedColumns' => self::referencedColumns($expressionTokens),
                'referencedColumnOccurrences' => self::referencedColumnOccurrences($expressionTokens),
                'functions' => $functions,
                'aggregate' => count(array_intersect($functions, ['avg', 'count', 'max', 'min', 'sum'])) > 0,
                'exactAggregate' => self::exactAggregate($expressionTokens),
                'aggregateMultiplication' => self::aggregateMultiplication($expressionTokens),
                'division' => self::divisionStructure($expressionTokens),
                'provenNumeric' => self::isProvenNumericExpression($expressionTokens),
                'callNumberClassDerivation' => self::callNumberClassDerivation($expressionTokens),
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
        $predicateGroups = $whereTokens === [] ? [] : [[
            'tokens' => $whereTokens,
            'provenance' => self::predicateProvenance('where'),
        ]];
        $joinPath = [];
        foreach ($scope['joins'] as $join) {
            $joinPath[] = [
                'type' => $join['type'],
                'alias' => $join['alias'],
                'source' => $join['source'],
                'sourceKind' => $join['sourceKind'],
            ];
            if ($join['predicate'] !== '') {
                $predicateGroups[] = [
                    'tokens' => $join['predicateTokens'],
                    'provenance' => self::predicateProvenance('join_on', $join, $joinPath),
                ];
            }
        }
        $allPredicateTokens = [];
        $literalPredicates = [];
        $dateWindows = [];
        $columnComparisons = [];
        $predicateAmbiguous = false;
        foreach ($predicateGroups as $group) {
            $allPredicateTokens = array_merge($allPredicateTokens, $group['tokens']);
            $evidence = self::analyzeConjunction($group['tokens']);
            $literalPredicates = array_merge(
                $literalPredicates,
                self::factsWithProvenance($evidence['literalPredicates'], $group['provenance'])
            );
            $dateWindows = array_merge(
                $dateWindows,
                self::factsWithProvenance($evidence['dateWindows'], $group['provenance'])
            );
            $columnComparisons = array_merge(
                $columnComparisons,
                self::factsWithProvenance($evidence['columnComparisons'], $group['provenance'])
            );
            $predicateAmbiguous = $predicateAmbiguous || $evidence['ambiguous'];
        }
        $scope['predicates'] = [
            'where' => $whereTokens === [] ? null : self::expressionText($whereTokens),
            'joins' => array_values(array_filter(array_column($scope['joins'], 'predicate'))),
            'dateColumns' => self::datePredicateColumns($allPredicateTokens),
            'dateWindows' => $dateWindows,
            'governedFilters' => array_values(array_unique(array_column($literalPredicates, 'column'))),
            'literalPredicates' => $literalPredicates,
            'columnComparisons' => $columnComparisons,
        ];
        if ($predicateAmbiguous) {
            $scope['ambiguous'] = true;
        }
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
                $direction = self::controlValue($orderItem[$last]);
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
        $last = count($tokens) - 1;
        if ($last > 0 && ($tokens[$last - 1]['depth'] ?? -1) === $base
            && self::isKeyword($tokens[$last - 1], 'AS')) {
            return ($tokens[$last]['kind'] ?? '') === 'identifier' ? $tokens[$last]['value'] : null;
        }
        if (($tokens[$last]['kind'] ?? '') !== 'identifier' || ($tokens[$last]['depth'] ?? -1) !== $base) {
            return null;
        }
        if ($last === 0 || ($tokens[$last - 1]['value'] ?? '') === '.'
            || ($tokens[$last - 1]['value'] ?? '') === '::') {
            return null;
        }
        if (self::isAnyKeyword($tokens[$last], array_merge(self::SOURCE_STOPS, self::EXPRESSION_TERMINALS))) {
            return null;
        }
        $previousKind = $tokens[$last - 1]['kind'] ?? '';
        $previousValue = $tokens[$last - 1]['value'] ?? '';
        if ($previousValue === ')' || in_array($previousKind, ['identifier', 'string', 'number'], true)) {
            return $tokens[$last]['value'];
        }
        return null;
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

    private static function referencedColumns(array $tokens): array
    {
        return array_values(array_unique(self::referencedColumnOccurrences($tokens)));
    }

    private static function referencedColumnOccurrences(array $tokens): array
    {
        $columns = [];
        for ($index = 0; $index + 2 < count($tokens); $index++) {
            if (($tokens[$index]['kind'] ?? '') === 'identifier'
                && ($tokens[$index + 1]['value'] ?? '') === '.'
                && ($tokens[$index + 2]['kind'] ?? '') === 'identifier') {
                $columns[] = $tokens[$index]['value'] . '.' . $tokens[$index + 2]['value'];
            }
        }
        return $columns;
    }

    private static function aggregateMultiplication(array $tokens): ?array
    {
        $argument = self::singleFunctionArgument($tokens, 'SUM');
        if ($argument === null) {
            return null;
        }
        $base = self::baseDepth($argument);
        foreach ($argument as $token) {
            if (($token['depth'] ?? -1) === $base
                && in_array($token['value'] ?? '', ['+', '-', '/'], true)) {
                return null;
            }
        }
        $factorTokens = self::splitAtDepth($argument, '*', $base);
        if (count($factorTokens) < 2) {
            return null;
        }
        $factors = [];
        foreach ($factorTokens as $factor) {
            $factor = self::withoutWrappingParentheses($factor);
            $factors[] = [
                'columns' => self::referencedColumnOccurrences($factor),
                'exactColumn' => self::exactColumnReference($factor),
                'numericLiteral' => count($factor) === 1 && ($factor[0]['kind'] ?? '') === 'number'
                    ? (string)$factor[0]['value'] : null,
            ];
        }
        return ['operator' => '*', 'factors' => $factors];
    }

    private static function divisionStructure(array $tokens): ?array
    {
        if ($tokens === []) {
            return null;
        }
        $base = self::baseDepth($tokens);
        $divisionIndexes = [];
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) === $base && ($token['value'] ?? '') === '/') {
                $divisionIndexes[] = $index;
            }
        }
        if (count($divisionIndexes) !== 1) {
            return null;
        }
        $index = $divisionIndexes[0];
        $numerator = array_slice($tokens, 0, $index);
        $denominator = array_slice($tokens, $index + 1);
        $zeroSafeOperand = self::zeroSafeOperandTokens($denominator);
        return [
            'numeratorColumns' => self::referencedColumns($numerator),
            'denominatorColumns' => self::referencedColumns($denominator),
            'zeroSafeDenominatorColumns' => self::zeroSafeOperandColumns($denominator),
            'numeratorAggregate' => self::exactAggregate($numerator),
            'denominatorAggregate' => $zeroSafeOperand === null ? null : self::exactAggregate($zeroSafeOperand),
        ];
    }

    private static function zeroSafeOperandColumns(array $tokens): array
    {
        $operand = self::zeroSafeOperandTokens($tokens);
        return $operand === null ? [] : self::referencedColumns($operand);
    }

    private static function zeroSafeOperandTokens(array $tokens): ?array
    {
        $tokens = self::withoutWrappingParentheses($tokens);
        if (count($tokens) >= 5 && self::isKeyword($tokens[0], 'NULLIF')
            && ($tokens[1]['value'] ?? '') === '(' && end($tokens)['value'] === ')') {
            $arguments = self::splitTopLevel(array_slice($tokens, 2, -1), ',');
            if (count($arguments) === 2 && count($arguments[1]) === 1
                && ($arguments[1][0]['kind'] ?? '') === 'number'
                && ($arguments[1][0]['value'] ?? '') === '0') {
                return $arguments[0];
            }
            return null;
        }

        $base = self::baseDepth($tokens);
        if (!self::isKeyword($tokens[0] ?? [], 'CASE') || !self::isKeyword(end($tokens) ?: [], 'END')) {
            return null;
        }
        $branches = ['WHEN' => [], 'THEN' => [], 'ELSE' => [], 'END' => []];
        foreach ($tokens as $index => $token) {
            foreach (array_keys($branches) as $keyword) {
                if (($token['depth'] ?? -1) === $base && self::isKeyword($token, $keyword)) {
                    $branches[$keyword][] = $index;
                }
            }
        }
        if (count($branches['WHEN']) !== 1 || count($branches['THEN']) !== 1
            || count($branches['ELSE']) !== 1 || $branches['END'] !== [count($tokens) - 1]) {
            return null;
        }
        $when = $branches['WHEN'][0];
        $then = $branches['THEN'][0];
        $else = $branches['ELSE'][0];
        if (!($when < $then && $then < $else) || $else !== $then + 2
            || !self::isKeyword($tokens[$then + 1] ?? [], 'NULL')) {
            return null;
        }
        $condition = array_slice($tokens, $when + 1, $then - $when - 1);
        $equals = null;
        foreach ($condition as $index => $token) {
            if (($token['depth'] ?? -1) === self::baseDepth($condition) && ($token['value'] ?? '') === '=') {
                $equals = $index;
                break;
            }
        }
        if ($equals === null || count($condition) !== $equals + 2
            || ($condition[$equals + 1]['kind'] ?? '') !== 'number'
            || ($condition[$equals + 1]['value'] ?? '') !== '0') {
            return null;
        }
        $conditionOperand = array_slice($condition, 0, $equals);
        $elseOperand = array_slice($tokens, $else + 1, count($tokens) - $else - 2);
        if (self::expressionText($conditionOperand) !== self::expressionText($elseOperand)) {
            return null;
        }
        return $elseOperand;
    }

    private static function exactAggregate(array $tokens): ?array
    {
        $tokens = self::withoutWrappingParentheses($tokens);
        foreach (['SUM', 'COUNT'] as $function) {
            $argument = self::singleFunctionArgument($tokens, $function);
            if ($argument === null) {
                continue;
            }
            $argument = self::withoutWrappingParentheses($argument);
            if (count($argument) !== 3 || ($argument[0]['kind'] ?? '') !== 'identifier'
                || ($argument[1]['value'] ?? '') !== '.' || ($argument[2]['kind'] ?? '') !== 'identifier') {
                return null;
            }
            return [
                'function' => strtolower($function),
                'column' => $argument[0]['value'] . '.' . $argument[2]['value'],
            ];
        }
        return null;
    }

    private static function singleFunctionArgument(array $tokens, string $function): ?array
    {
        $tokens = self::withoutWrappingParentheses($tokens);
        if (count($tokens) < 4 || !self::isKeyword($tokens[0], $function)
            || ($tokens[1]['value'] ?? '') !== '(' || (end($tokens)['value'] ?? '') !== ')') {
            return null;
        }
        $openDepth = $tokens[1]['depth'] ?? -1;
        foreach ($tokens as $index => $token) {
            if ($index < count($tokens) - 1 && $index > 1
                && ($token['value'] ?? '') === ')' && ($token['depth'] ?? -1) === $openDepth) {
                return null;
            }
        }
        return array_slice($tokens, 2, -1);
    }

    private static function isProvenNumericExpression(array $tokens): bool
    {
        $tokens = self::withoutWrappingParentheses($tokens);
        if (count($tokens) === 1 && ($tokens[0]['kind'] ?? '') === 'number') {
            return true;
        }
        if (self::exactAggregate($tokens) !== null) {
            return true;
        }
        $zeroSafe = self::zeroSafeOperandTokens($tokens);
        if ($zeroSafe !== null) {
            return self::isProvenNumericExpression($zeroSafe);
        }
        $base = self::baseDepth($tokens);
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) === $base && in_array($token['value'] ?? '', ['+', '-', '*', '/'], true)) {
                return self::isProvenNumericExpression(array_slice($tokens, 0, $index))
                    && self::isProvenNumericExpression(array_slice($tokens, $index + 1));
            }
        }
        return false;
    }

    private static function callNumberClassDerivation(array $tokens): ?string
    {
        $argument = self::singleFunctionArgument($tokens, 'MIN');
        if ($argument === null) {
            return null;
        }
        $argument = self::withoutWrappingParentheses($argument);
        if (self::isSubstringClassDerivation($argument)) {
            return 'substring_alpha_prefix';
        }
        return self::isDocumentedCaseClassDerivation($argument) ? 'documented_lc_dewey_case' : null;
    }

    private static function isSubstringClassDerivation(array $tokens): bool
    {
        $argument = self::singleFunctionArgument($tokens, 'SUBSTRING');
        if ($argument === null) {
            return false;
        }
        $base = self::baseDepth($argument);
        $from = self::findKeywordIndex($argument, 'FROM', 0, $base);
        if ($from === null) {
            return false;
        }
        $column = array_slice($argument, 0, $from);
        $pattern = array_slice($argument, $from + 1);
        return count($column) === 3
            && self::columnLeafFromTokens($column) === 'effective_call_number_components__call_number'
            && count($pattern) === 1 && ($pattern[0]['kind'] ?? '') === 'string'
            && self::literalTokenValue($pattern[0]) === '^[A-Za-z]+';
    }

    private static function isDocumentedCaseClassDerivation(array $tokens): bool
    {
        return self::derivationTokenSignature($tokens) === [
            'case', 'when', '<call_number>', '~', 'string:^[A-Z]{1,3}[0-9]',
            'then', 'regexp_replace', '(', '<call_number>', ',', 'string:^([A-Z]{1,3})[0-9].*', ',', 'string:\\1', ')',
            'when', '<call_number>', '~', 'string:^[0-9]',
            'then', 'lpad', '(', 'cast', '(', 'floor', '(', 'cast', '(', 'regexp_replace', '(',
            '<call_number>', ',', 'string:^([0-9]+).*', ',', 'string:\\1', ')', 'as', 'numeric', ')',
            '/', '100', ')', '*', '100', 'as', 'text', ')', ',', '3', ',', 'string:0', ')',
            'else', 'string:Unknown', 'end',
        ];
    }

    private static function derivationTokenSignature(array $tokens): array
    {
        $signature = [];
        for ($index = 0; $index < count($tokens); $index++) {
            if (($tokens[$index]['kind'] ?? '') === 'identifier'
                && ($tokens[$index + 1]['value'] ?? '') === '.'
                && ($tokens[$index + 2]['kind'] ?? '') === 'identifier'
                && strtolower((string)$tokens[$index + 2]['value']) === 'effective_call_number_components__call_number') {
                $signature[] = '<call_number>';
                $index += 2;
                continue;
            }
            if (($tokens[$index]['kind'] ?? '') === 'string') {
                $signature[] = 'string:' . self::literalTokenValue($tokens[$index]);
                continue;
            }
            $signature[] = (string)($tokens[$index]['value'] ?? '');
        }
        return $signature;
    }

    private static function columnLeafFromTokens(array $tokens): ?string
    {
        if (count($tokens) !== 3 || ($tokens[1]['value'] ?? '') !== '.') {
            return null;
        }
        return strtolower((string)($tokens[2]['value'] ?? ''));
    }

    private static function exactColumnReference(array $tokens): ?string
    {
        return count($tokens) === 3 && ($tokens[0]['kind'] ?? '') === 'identifier'
            && ($tokens[1]['value'] ?? '') === '.' && ($tokens[2]['kind'] ?? '') === 'identifier'
            ? $tokens[0]['value'] . '.' . $tokens[2]['value'] : null;
    }


    private static function withoutWrappingParentheses(array $tokens): array
    {
        while (count($tokens) >= 2 && ($tokens[0]['value'] ?? '') === '(' && (end($tokens)['value'] ?? '') === ')') {
            $base = $tokens[0]['depth'] ?? 0;
            $closesAtEnd = true;
            foreach ($tokens as $index => $token) {
                if ($index < count($tokens) - 1 && ($token['value'] ?? '') === ')' && ($token['depth'] ?? -1) === $base) {
                    $closesAtEnd = false;
                    break;
                }
            }
            if (!$closesAtEnd) {
                break;
            }
            $tokens = array_slice($tokens, 1, -1);
        }
        return $tokens;
    }

    private static function splitAtDepth(array $tokens, string $separator, int $depth): array
    {
        $parts = [];
        $start = 0;
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) === $depth && ($token['value'] ?? '') === $separator) {
                $parts[] = array_slice($tokens, $start, $index - $start);
                $start = $index + 1;
            }
        }
        $parts[] = array_slice($tokens, $start);
        return array_values(array_filter($parts, static function (array $part): bool {
            return $part !== [];
        }));
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

    private static function analyzeConjunction(array $tokens): array
    {
        $result = [
            'literalPredicates' => [],
            'dateWindows' => [],
            'columnComparisons' => [],
            'ambiguous' => false,
        ];
        $tokens = self::withoutWrappingParentheses($tokens);
        foreach ($tokens as $token) {
            if (self::isKeyword($token, 'OR')) {
                $result['ambiguous'] = true;
                return $result;
            }
        }
        $base = self::baseDepth($tokens);
        $atoms = [];
        $start = 0;
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) === $base && self::isKeyword($token, 'AND')) {
                $atoms[] = array_slice($tokens, $start, $index - $start);
                $start = $index + 1;
            }
        }
        $atoms[] = array_slice($tokens, $start);
        foreach ($atoms as $atom) {
            $atom = self::withoutWrappingParentheses($atom);
            $evidence = self::analyzePredicateAtom($atom);
            if ($evidence === null) {
                $result['literalPredicates'] = [];
                $result['dateWindows'] = [];
                $result['columnComparisons'] = [];
                $result['ambiguous'] = true;
                return $result;
            }
            if (isset($evidence['literalPredicate'])) {
                $result['literalPredicates'][] = $evidence['literalPredicate'];
            }
            if (isset($evidence['dateWindow'])) {
                $result['dateWindows'][] = $evidence['dateWindow'];
            }
            if (isset($evidence['columnComparison'])) {
                $result['columnComparisons'][] = $evidence['columnComparison'];
            }
        }
        return $result;
    }

    private static function analyzePredicateAtom(array $tokens): ?array
    {
        if ($tokens === []) {
            return null;
        }
        $dateWindow = self::dateWindowAtom($tokens);
        if ($dateWindow !== null) {
            return ['dateWindow' => $dateWindow];
        }
        $base = self::baseDepth($tokens);
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) !== $base) {
                continue;
            }
            if (self::isKeyword($token, 'IN')) {
                $column = self::normalizedColumnOperand(array_slice($tokens, 0, $index));
                $values = self::literalListValues($tokens, $index + 1);
                $close = count($tokens) - 1;
                if ($column === null || $values === null || ($tokens[$close]['value'] ?? '') !== ')') {
                    return null;
                }
                return ['literalPredicate' => [
                    'column' => $column,
                    'operator' => 'IN',
                    'values' => $values,
                    'negated' => false,
                ]];
            }
            if (!in_array($token['value'] ?? '', ['=', '!=', '<>', '>', '<', '>=', '<='], true)) {
                continue;
            }
            $left = self::normalizedColumnOperand(array_slice($tokens, 0, $index));
            $rightTokens = array_slice($tokens, $index + 1);
            if ($left === null) {
                return null;
            }
            $rightColumn = self::normalizedColumnOperand($rightTokens);
            if ($rightColumn !== null && ($token['value'] ?? '') === '=') {
                return ['columnComparison' => ['left' => $left, 'operator' => '=', 'right' => $rightColumn]];
            }
            if (count($rightTokens) === 1
                && in_array($rightTokens[0]['kind'] ?? '', ['string', 'number'], true)) {
                return ['literalPredicate' => [
                    'column' => $left,
                    'operator' => (string)$token['value'],
                    'values' => [self::literalTokenValue($rightTokens[0])],
                    'negated' => false,
                ]];
            }
            return null;
        }
        $is = self::findKeywordIndex($tokens, 'IS', 0, $base);
        if ($is !== null && self::normalizedColumnOperand(array_slice($tokens, 0, $is)) !== null
            && count($tokens) === $is + 3
            && self::isKeyword($tokens[$is + 1] ?? [], 'NOT')
            && self::isKeyword($tokens[$is + 2] ?? [], 'NULL')) {
            return [];
        }
        return null;
    }

    private static function normalizedColumnOperand(array $tokens): ?string
    {
        $tokens = self::withoutWrappingParentheses($tokens);
        $column = self::exactColumnReference($tokens);
        if ($column !== null) {
            return $column;
        }
        foreach (['TRIM', 'LOWER', 'UPPER'] as $function) {
            $argument = self::singleFunctionArgument($tokens, $function);
            if ($argument !== null) {
                return self::exactColumnReference(self::withoutWrappingParentheses($argument));
            }
        }
        return null;
    }

    private static function dateWindowAtom(array $tokens): ?array
    {
        $tokens = self::withoutWrappingParentheses($tokens);
        if (count($tokens) !== 8) {
            return null;
        }
        $column = self::exactColumnReference(array_slice($tokens, 0, 3));
        $operator = (string)($tokens[3]['value'] ?? '');
        if ($column === null || !in_array($operator, ['>=', '>'], true)
            || !self::isKeyword($tokens[4] ?? [], 'CURRENT_DATE')
            || ($tokens[5]['value'] ?? '') !== '-'
            || !self::isKeyword($tokens[6] ?? [], 'INTERVAL')
            || ($tokens[7]['kind'] ?? '') !== 'string') {
            return null;
        }
        $interval = strtolower(self::literalTokenValue($tokens[7]));
        if (preg_match('/^[0-9]+\s+(?:day|days|month|months|year|years)$/', $interval) !== 1) {
            return null;
        }
        return [
            'column' => $column,
            'operator' => $operator,
            'expression' => 'current_date - interval ' . $interval,
        ];
    }

    private static function literalListValues(array $tokens, int $openIndex): ?array
    {
        if (($tokens[$openIndex]['value'] ?? '') !== '(') {
            return null;
        }
        $depth = ($tokens[$openIndex]['depth'] ?? -1) + 1;
        $values = [];
        $expectValue = true;
        for ($index = $openIndex + 1; $index < count($tokens); $index++) {
            if (($tokens[$index]['value'] ?? '') === ')' && ($tokens[$index]['depth'] ?? -1) === $depth - 1) {
                return !$expectValue && $values !== [] ? $values : null;
            }
            if (($tokens[$index]['depth'] ?? -1) !== $depth) {
                return null;
            }
            if ($expectValue) {
                if (!in_array($tokens[$index]['kind'] ?? '', ['string', 'number'], true)) {
                    return null;
                }
                $values[] = self::literalTokenValue($tokens[$index]);
                $expectValue = false;
            } elseif (($tokens[$index]['value'] ?? '') === ',') {
                $expectValue = true;
            } else {
                return null;
            }
        }
        return null;
    }

    private static function literalTokenValue(array $token): string
    {
        $value = (string)($token['value'] ?? '');
        if (($token['kind'] ?? '') === 'string' && strlen($value) >= 2) {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        return $value;
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
            if (self::isAnyKeyword($tokens[$cursor], self::CLAUSE_STARTS)) {
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
                && !self::isAnyKeyword($tokens[$afterSource], self::SOURCE_STOPS)) {
                $alias = $tokens[$afterSource]['value'];
                $afterSource++;
            }

            $sourceKind = count($parts) === 1 && in_array($source, $knownCtes, true) ? 'cte' : 'table';
            if (isset($scope['sourceAliases'][$alias])) {
                $scope['ambiguous'] = true;
            }
            $scope['sourceAliases'][$alias] = ['kind' => $sourceKind, 'source' => $source];

            if ($sourceKind === 'cte') {
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
                    'sourceKind' => $sourceKind,
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

    private static function predicateProvenance(
        string $origin,
        ?array $join = null,
        array $joinPath = []
    ): array {
        return [
            'origin' => $origin,
            'joinType' => $join['type'] ?? null,
            'joinedAlias' => $join['alias'] ?? null,
            'joinedSource' => $join['source'] ?? null,
            'joinedSourceKind' => $join['sourceKind'] ?? null,
            'joinPath' => $joinPath,
        ];
    }

    private static function factsWithProvenance(array $facts, array $provenance): array
    {
        return array_map(static function (array $fact) use ($provenance): array {
            return array_merge($fact, $provenance);
        }, $facts);
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

    private static function hasAmbiguousAliasBoundary(array $tokens): bool
    {
        $base = self::baseDepth($tokens);
        $penultimate = count($tokens) - 2;
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) === $base && self::isKeyword($token, 'AS') && $index !== $penultimate) {
                return true;
            }
        }
        $alias = self::outputAlias($tokens);
        if ($alias === null) {
            return false;
        }
        $expressionTokens = self::withoutOutputAlias($tokens);
        return self::outputAlias($expressionTokens) !== null;
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
            if (($token['value'] ?? '') === '::'
                && self::isTextType($tokens[$index + 1] ?? [])) {
                return true;
            }
            if (self::isKeyword($token, 'AS') && self::isTextType($tokens[$index + 1] ?? [])) {
                return true;
            }
            if (($token['kind'] ?? '') === 'string') {
                return true;
            }
        }
        return false;
    }

    private static function isTextType(array $token): bool
    {
        return self::isAnyKeyword($token, ['TEXT', 'VARCHAR', 'CHAR', 'CHARACTER']);
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
                || self::isAnyKeyword($tokens[$index], ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'NATURAL'])
                || self::isAnyKeyword($tokens[$index], self::CLAUSE_STARTS)) {
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
        $orderedClauses = ['SELECT', 'FROM', 'WHERE', 'GROUP', 'HAVING', 'WINDOW', 'ORDER', 'LIMIT', 'OFFSET', 'FETCH', 'FOR'];
        $lastPosition = -1;
        foreach ($orderedClauses as $keyword) {
            $position = self::findKeywordIndex($tokens, $keyword, 0, $depth);
            if ($position === null) {
                continue;
            }
            if ($position < $lastPosition) {
                return false;
            }
            $lastPosition = $position;
        }
        return true;
    }

    private static function joinTypeBefore(array $tokens, int $joinIndex, int $depth): string
    {
        $previous = $tokens[$joinIndex - 1] ?? [];
        if (($previous['depth'] ?? -1) === $depth
            && self::isAnyKeyword($previous, ['INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'])) {
            return self::controlValue($previous);
        }
        if (($previous['depth'] ?? -1) === $depth && self::isKeyword($previous, 'OUTER')) {
            $typeToken = $tokens[$joinIndex - 2] ?? [];
            return self::isAnyKeyword($typeToken, ['LEFT', 'RIGHT', 'FULL'])
                ? self::controlValue($typeToken)
                : 'UNKNOWN';
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
            && self::controlValue($token) === $keyword;
    }

    private static function isAnyKeyword(array $token, array $keywords): bool
    {
        return ($token['kind'] ?? '') === 'identifier'
            && in_array(self::controlValue($token), $keywords, true);
    }

    private static function controlValue(array $token): string
    {
        if (!empty($token['quoted'])) {
            return '';
        }
        return strtoupper((string)($token['value'] ?? ''));
    }

    private static function emptyScope(): array
    {
        return [
            'tables' => [],
            'dependencies' => [],
            'sourceAliases' => [],
            'selectItems' => [],
            'predicates' => [
                'where' => null,
                'joins' => [],
                'dateColumns' => [],
                'dateWindows' => [],
                'governedFilters' => [],
                'literalPredicates' => [],
                'columnComparisons' => [],
            ],
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
            'sourceAliases' => [],
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
