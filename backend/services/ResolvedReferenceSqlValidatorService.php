<?php

namespace app\services;

final class ResolvedReferenceSqlValidatorService
{
    private const DIMENSION_TABLES = [
        'institution' => 'inventory.locinstitution__t',
        'campus' => 'inventory.loccampus__t',
        'library' => 'inventory.loclibrary__t',
        'location' => 'inventory.location__t',
        'service_point' => 'inventory.service_point__t',
        'material_type' => 'inventory.material_type__t',
    ];

    private const TABLE_DIMENSIONS = [
        'inventory.locinstitution__t' => 'institution',
        'inventory.loccampus__t' => 'campus',
        'inventory.loclibrary__t' => 'library',
        'inventory.location__t' => 'location',
        'inventory.service_point__t' => 'service_point',
        'inventory.material_type__t' => 'material_type',
    ];

    private const ALIAS_STOP_WORDS = [
        'where', 'join', 'inner', 'left', 'right', 'full', 'cross', 'natural',
        'outer', 'on', 'using', 'group', 'order', 'having', 'limit', 'offset',
        'union', 'intersect', 'except', 'window', 'fetch', 'for',
    ];

    public static function validate(string $sql, array $resolvedFilters): void
    {
        if (empty($resolvedFilters)) {
            return;
        }

        $aliases = self::tableAliases($sql);
        $positivePredicates = self::positiveNameValues($sql, $aliases);

        foreach ($resolvedFilters as $filter) {
            $sourceTable = strtolower(trim((string) ($filter['source_table'] ?? '')));
            $column = strtolower(trim((string) ($filter['column'] ?? '')));
            $expectedValues = $filter['values'] ?? null;

            if (
                $sourceTable === ''
                || $column !== 'name'
                || !is_array($expectedValues)
                || empty($expectedValues)
                || !in_array($sourceTable, $aliases, true)
            ) {
                self::mismatch();
            }

            $actualValues = [];
            foreach ($positivePredicates as $predicate) {
                if ($predicate['source_table'] === $sourceTable) {
                    $actualValues[] = $predicate['value'];
                }
            }

            $expectedSet = self::normalizedValueSet($expectedValues);
            $actualSet = self::normalizedValueSet($actualValues);
            if ($expectedSet !== $actualSet) {
                self::mismatch();
            }
        }

        self::assertNoWrongHierarchyValues($sql, $resolvedFilters, $aliases);
        self::assertNoInstitutionConflict($resolvedFilters, $positivePredicates);
    }

    private static function tableAliases(string $sql): array
    {
        $sql = self::sqlWithoutComments($sql);
        $identifier = '(?:"(?:[^"]|"")*"|[A-Za-z_][A-Za-z0-9_$]*)';
        $qualifiedIdentifier = $identifier . '(?:\s*\.\s*' . $identifier . ')*';
        $joinPrefix = '(?:'
            . 'FROM'
            . '|(?:(?:INNER|LEFT(?:\s+OUTER)?|RIGHT(?:\s+OUTER)?|FULL(?:\s+OUTER)?'
            . '|CROSS|NATURAL(?:\s+(?:INNER|LEFT(?:\s+OUTER)?|RIGHT(?:\s+OUTER)?'
            . '|FULL(?:\s+OUTER)?))?)\s+)?JOIN'
            . ')';
        $pattern = '/\b' . $joinPrefix . '\s+(' . $qualifiedIdentifier . ')'
            . '(?:\s+(?:AS\s+)?(' . $identifier . '))?/i';

        preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);
        $aliases = [];
        foreach ($matches as $match) {
            $sourceTable = self::normalizeQualifiedIdentifier($match[1]);
            $aliasToken = isset($match[2]) ? trim($match[2]) : '';
            $hasAlias = $aliasToken !== ''
                && (
                    substr($aliasToken, 0, 1) === '"'
                    || !in_array(strtolower($aliasToken), self::ALIAS_STOP_WORDS, true)
                );

            if ($hasAlias) {
                $aliases[self::normalizeQualifiedIdentifier($aliasToken)] = $sourceTable;
                continue;
            }

            $parts = explode('.', $sourceTable);
            $aliases[$sourceTable] = $sourceTable;
            $aliases[end($parts)] = $sourceTable;
        }

        return $aliases;
    }

    private static function positiveNameValues(string $sql, array $aliases): array
    {
        $sql = self::sqlWithoutComments($sql);
        $identifier = '(?:"(?:[^"]|"")*"|[A-Za-z_][A-Za-z0-9_$]*)';
        $reference = $identifier . '(?:\s*\.\s*' . $identifier . ')*';
        $nameField = '(' . $reference . '\s*\.\s*(?:"name"|name))';
        $literal = "('(?:''|[^'])*')";
        $predicates = [];

        $lowerPatterns = [
            '/LOWER\s*\(\s*' . $nameField . '\s*\)\s*=\s*LOWER\s*\(\s*'
                . $literal . '\s*\)/i',
            '/LOWER\s*\(\s*' . $literal . '\s*\)\s*=\s*LOWER\s*\(\s*'
                . $nameField . '\s*\)/i',
        ];
        foreach ($lowerPatterns as $index => $pattern) {
            preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $fieldIndex = $index === 0 ? 1 : 2;
                $literalIndex = $index === 0 ? 2 : 1;
                self::appendPositivePredicate(
                    $predicates,
                    $aliases,
                    $sql,
                    $match[0][1],
                    $match[$fieldIndex][0],
                    $match[$literalIndex][0]
                );
            }
        }

        $equalityPatterns = [
            '/' . $nameField . '\s*=\s*' . $literal . '/i',
            '/' . $literal . '\s*=\s*' . $nameField . '/i',
        ];
        foreach ($equalityPatterns as $index => $pattern) {
            preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $fieldIndex = $index === 0 ? 1 : 2;
                $literalIndex = $index === 0 ? 2 : 1;
                self::appendPositivePredicate(
                    $predicates,
                    $aliases,
                    $sql,
                    $match[0][1],
                    $match[$fieldIndex][0],
                    $match[$literalIndex][0]
                );
            }
        }

        $likePattern = '/' . $nameField . '\s+(?:(NOT)\s+)?(ILIKE|LIKE)\s+'
            . $literal . '/i';
        preg_match_all($likePattern, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            if (($match[2][0] ?? '') !== '') {
                continue;
            }

            self::appendPositivePredicate(
                $predicates,
                $aliases,
                $sql,
                $match[0][1],
                $match[1][0],
                $match[4][0],
                true
            );
        }

        $inPattern = '/' . $nameField . '\s+(?:(NOT)\s+)?IN\s*\(([^)]*)\)/i';
        preg_match_all($inPattern, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            if (($match[2][0] ?? '') !== '' || self::isNegatedAt($sql, $match[0][1])) {
                continue;
            }

            $field = $match[1][0];
            $list = $match[3][0];
            preg_match_all("/'(?:''|[^'])*'/", $list, $literals);
            foreach ($literals[0] as $valueLiteral) {
                self::appendPositivePredicate(
                    $predicates,
                    $aliases,
                    $sql,
                    $match[0][1],
                    $field,
                    $valueLiteral
                );
            }

            $unparsedList = preg_replace("/'(?:''|[^'])*'/", '', $list);
            if (trim(str_replace(',', '', (string) $unparsedList)) !== '') {
                self::appendResolvedPredicate(
                    $predicates,
                    $aliases,
                    $field,
                    "\0invalid_in_expression"
                );
            }
        }

        return $predicates;
    }

    private static function normalizedValueSet(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                self::mismatch();
            }

            $text = preg_replace('/\s+/u', ' ', trim((string) $value));
            if (function_exists('mb_strtolower')) {
                $text = mb_strtolower((string) $text, 'UTF-8');
            } else {
                $text = strtolower((string) $text);
            }
            $normalized[(string) $text] = true;
        }

        $set = array_keys($normalized);
        sort($set, SORT_STRING);

        return $set;
    }

    private static function assertNoWrongHierarchyValues(
        string $sql,
        array $resolvedFilters,
        array $aliases
    ): void {
        $positivePredicates = self::positiveNameValues($sql, $aliases);
        foreach ($resolvedFilters as $filter) {
            $sourceTable = strtolower(trim((string) ($filter['source_table'] ?? '')));
            $expectedSet = self::normalizedValueSet((array) ($filter['values'] ?? []));

            foreach ($positivePredicates as $predicate) {
                if (
                    $predicate['source_table'] === $sourceTable
                    || !isset(self::TABLE_DIMENSIONS[$predicate['source_table']])
                ) {
                    continue;
                }

                $predicateSet = self::normalizedValueSet([$predicate['value']]);
                if (!empty(array_intersect($expectedSet, $predicateSet))) {
                    self::mismatch();
                }
            }
        }
    }

    private static function assertNoInstitutionConflict(
        array $resolvedFilters,
        array $positivePredicates
    ): void {
        $filterValues = [];
        $metadataValues = [];
        foreach ($resolvedFilters as $filter) {
            $dimension = strtolower(trim((string) ($filter['dimension'] ?? '')));
            if (isset(self::DIMENSION_TABLES[$dimension])) {
                foreach ((array) ($filter['values'] ?? []) as $value) {
                    $filterValues[$dimension][] = $value;
                }
            }

            foreach ((array) ($filter['value_metadata'] ?? []) as $metadata) {
                if (!is_array($metadata)) {
                    self::mismatch();
                }

                foreach (['institution', 'campus', 'library', 'location'] as $hierarchyDimension) {
                    $metadataKey = $hierarchyDimension . '_name';
                    if (isset($metadata[$metadataKey]) && trim((string) $metadata[$metadataKey]) !== '') {
                        $metadataValues[$hierarchyDimension][] = $metadata[$metadataKey];
                    }
                }
            }
        }

        foreach ($filterValues as $dimension => $values) {
            $filterValues[$dimension] = self::normalizedValueSet($values);
        }
        foreach ($metadataValues as $dimension => $values) {
            $metadataValues[$dimension] = self::normalizedValueSet($values);
            if (
                in_array($dimension, ['institution', 'campus'], true)
                && count($metadataValues[$dimension]) > 1
            ) {
                self::mismatch();
            }
            if (
                isset($filterValues[$dimension])
                && !empty(array_diff($metadataValues[$dimension], $filterValues[$dimension]))
            ) {
                self::mismatch();
            }
        }

        $allowedValues = $metadataValues;
        foreach ($filterValues as $dimension => $values) {
            $allowedValues[$dimension] = $values;
        }

        foreach ($positivePredicates as $predicate) {
            $dimension = self::TABLE_DIMENSIONS[$predicate['source_table']] ?? null;
            if ($dimension === null || !isset($allowedValues[$dimension])) {
                continue;
            }

            $predicateSet = self::normalizedValueSet([$predicate['value']]);
            if (empty(array_intersect($allowedValues[$dimension], $predicateSet))) {
                self::mismatch();
            }
        }
    }

    private static function appendPositivePredicate(
        array &$predicates,
        array $aliases,
        string $sql,
        int $offset,
        string $field,
        string $literal,
        bool $rejectWildcards = false
    ): void {
        if (self::isNegatedAt($sql, $offset)) {
            return;
        }

        $value = self::unquoteSqlLiteral($literal);
        if (
            $rejectWildcards
            && (strpos($value, '%') !== false || strpos($value, '_') !== false)
        ) {
            $value = "\0invalid_like_pattern";
        }

        self::appendResolvedPredicate(
            $predicates,
            $aliases,
            $field,
            $value
        );
    }

    private static function appendResolvedPredicate(
        array &$predicates,
        array $aliases,
        string $field,
        string $value
    ): void {
        $field = self::normalizeQualifiedIdentifier($field);
        $parts = explode('.', $field);
        if (count($parts) < 2 || array_pop($parts) !== 'name') {
            return;
        }

        $reference = implode('.', $parts);
        if (!isset($aliases[$reference])) {
            return;
        }

        $predicates[] = [
            'source_table' => $aliases[$reference],
            'value' => $value,
        ];
    }

    private static function isNegatedAt(string $sql, int $offset): bool
    {
        $prefix = substr($sql, 0, $offset);
        if (preg_match('/\bNOT\s*$/i', (string) $prefix) === 1) {
            return true;
        }

        $openParentheses = [];
        $state = 'normal';
        $length = strlen((string) $prefix);
        for ($index = 0; $index < $length; $index++) {
            $character = $prefix[$index];
            $next = $index + 1 < $length ? $prefix[$index + 1] : '';

            if ($state === 'single_quote') {
                if ($character === "'" && $next === "'") {
                    $index++;
                } elseif ($character === "'") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'double_quote') {
                if ($character === '"' && $next === '"') {
                    $index++;
                } elseif ($character === '"') {
                    $state = 'normal';
                }
                continue;
            }
            if ($character === "'") {
                $state = 'single_quote';
                continue;
            }
            if ($character === '"') {
                $state = 'double_quote';
                continue;
            }
            if ($character === '(') {
                $openParentheses[] = $index;
            } elseif ($character === ')' && !empty($openParentheses)) {
                array_pop($openParentheses);
            }
        }

        foreach ($openParentheses as $openParenthesis) {
            $beforeParenthesis = substr($prefix, 0, $openParenthesis);
            if (preg_match('/\bNOT\s*$/i', rtrim((string) $beforeParenthesis)) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function unquoteSqlLiteral(string $literal): string
    {
        return str_replace("''", "'", substr($literal, 1, -1));
    }

    private static function normalizeQualifiedIdentifier(string $identifier): string
    {
        $parts = preg_split('/\s*\.\s*/', trim($identifier));
        $normalized = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (strlen($part) >= 2 && substr($part, 0, 1) === '"' && substr($part, -1) === '"') {
                $part = str_replace('""', '"', substr($part, 1, -1));
            }
            $normalized[] = strtolower($part);
        }

        return implode('.', $normalized);
    }

    private static function sqlWithoutComments(string $sql): string
    {
        $length = strlen($sql);
        $result = '';
        $state = 'normal';

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($state === 'single_quote') {
                $result .= $character;
                if ($character === "'" && $next === "'") {
                    $result .= $next;
                    $index++;
                } elseif ($character === "'") {
                    $state = 'normal';
                }
                continue;
            }

            if ($state === 'double_quote') {
                $result .= $character;
                if ($character === '"' && $next === '"') {
                    $result .= $next;
                    $index++;
                } elseif ($character === '"') {
                    $state = 'normal';
                }
                continue;
            }

            if ($state === 'line_comment') {
                if ($character === "\n" || $character === "\r") {
                    $result .= $character;
                    $state = 'normal';
                } else {
                    $result .= ' ';
                }
                continue;
            }

            if ($state === 'block_comment') {
                if ($character === '*' && $next === '/') {
                    $result .= '  ';
                    $index++;
                    $state = 'normal';
                } else {
                    $result .= ($character === "\n" || $character === "\r") ? $character : ' ';
                }
                continue;
            }

            if ($character === "'") {
                $result .= $character;
                $state = 'single_quote';
            } elseif ($character === '"') {
                $result .= $character;
                $state = 'double_quote';
            } elseif ($character === '-' && $next === '-') {
                $result .= '  ';
                $index++;
                $state = 'line_comment';
            } elseif ($character === '/' && $next === '*') {
                $result .= '  ';
                $index++;
                $state = 'block_comment';
            } else {
                $result .= $character;
            }
        }

        return $result;
    }

    private static function mismatch(): void
    {
        throw new \InvalidArgumentException('resolved_reference_filter_mismatch');
    }
}
