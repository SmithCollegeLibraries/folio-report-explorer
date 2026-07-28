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

    private const HIERARCHY_DIMENSIONS = [
        'institution',
        'campus',
        'library',
        'location',
        'service_point',
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

        $sql = self::sqlWithoutIgnoredText($sql);
        $aliasCode = self::sqlCode($sql, true);
        $structureCode = self::sqlCode($sql, false);
        self::stripTrailingSemicolon($sql, $aliasCode, $structureCode);

        list($whereStart, $whereEnd) = self::supportedWhereBounds($structureCode);
        $aliases = self::tableAliases($aliasCode);
        $expectedFilters = self::combinedExpectedFilters($resolvedFilters, $aliases);
        $whereSql = substr($sql, $whereStart, $whereEnd - $whereStart);
        $whereCode = substr($structureCode, $whereStart, $whereEnd - $whereStart);
        if (preg_match('/\bBETWEEN\b/i', $whereCode) === 1) {
            self::mismatch();
        }
        $terms = self::conjunctiveTerms($whereSql, $whereCode);
        $positivePredicates = self::positiveNameValues($terms, $aliases);
        self::assertSupportedHierarchyPredicates($terms, $aliases);

        foreach ($expectedFilters as $filter) {
            $actualValues = [];
            foreach ($positivePredicates as $predicate) {
                if ($predicate['source_table'] === $filter['source_table']) {
                    $actualValues[] = $predicate['value'];
                }
            }

            if ($filter['values'] !== self::normalizedValueSet($actualValues)) {
                self::mismatch();
            }
        }

        self::assertNoWrongHierarchyValues($resolvedFilters, $positivePredicates);
        self::assertNoInstitutionConflict($resolvedFilters, $positivePredicates);
    }

    private static function supportedWhereBounds(string $code): array
    {
        self::assertBalancedParentheses($code);

        if (preg_match('/\A\s*SELECT\b/i', $code) !== 1) {
            self::mismatch();
        }

        preg_match_all('/\bSELECT\b/i', $code, $selectMatches);
        if (count($selectMatches[0]) !== 1) {
            self::mismatch();
        }

        if (
            preg_match(
                '/\b(?:WITH|UNION|INTERSECT|EXCEPT|EXISTS|CASE|OR)\b/i',
                $code
            ) === 1
        ) {
            self::mismatch();
        }

        $whereOffset = null;
        $whereEnd = strlen($code);
        $seenWhere = false;
        foreach (self::topLevelWords($code) as $word) {
            if ($word['word'] === 'where') {
                if ($seenWhere) {
                    self::mismatch();
                }
                $seenWhere = true;
                $whereOffset = $word['offset'] + strlen($word['text']);
                continue;
            }

            if (
                $seenWhere
                && in_array(
                    $word['word'],
                    ['group', 'order', 'having', 'limit', 'offset', 'window', 'fetch', 'for'],
                    true
                )
            ) {
                $whereEnd = $word['offset'];
                break;
            }
        }

        if ($whereOffset === null || trim(substr($code, $whereOffset, $whereEnd - $whereOffset)) === '') {
            self::mismatch();
        }

        $whereCode = substr($code, $whereOffset, $whereEnd - $whereOffset);
        if (preg_match('/\b(?:TRUE|FALSE)\b/i', $whereCode) === 1) {
            self::mismatch();
        }

        return [$whereOffset, $whereEnd];
    }

    private static function topLevelWords(string $code): array
    {
        $words = [];
        $depth = 0;
        $length = strlen($code);

        for ($index = 0; $index < $length;) {
            $character = $code[$index];
            if ($character === '(') {
                $depth++;
                $index++;
                continue;
            }
            if ($character === ')') {
                $depth--;
                $index++;
                continue;
            }
            if (
                $depth !== 0
                || preg_match('/[A-Za-z_]/', $character) !== 1
            ) {
                $index++;
                continue;
            }

            $start = $index;
            $index++;
            while (
                $index < $length
                && preg_match('/[A-Za-z0-9_$]/', $code[$index]) === 1
            ) {
                $index++;
            }
            $text = substr($code, $start, $index - $start);
            $words[] = [
                'word' => strtolower($text),
                'text' => $text,
                'offset' => $start,
            ];
        }

        return $words;
    }

    private static function assertBalancedParentheses(string $code): void
    {
        $depth = 0;
        $length = strlen($code);
        for ($index = 0; $index < $length; $index++) {
            if ($code[$index] === '(') {
                $depth++;
            } elseif ($code[$index] === ')') {
                $depth--;
                if ($depth < 0) {
                    self::mismatch();
                }
            }
        }

        if ($depth !== 0) {
            self::mismatch();
        }
    }

    private static function tableAliases(string $sql): array
    {
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

    private static function combinedExpectedFilters(array $resolvedFilters, array $aliases): array
    {
        $combined = [];
        foreach ($resolvedFilters as $filter) {
            $dimension = strtolower(trim((string) ($filter['dimension'] ?? '')));
            $sourceTable = strtolower(trim((string) ($filter['source_table'] ?? '')));
            $column = strtolower(trim((string) ($filter['column'] ?? '')));
            $values = $filter['values'] ?? null;

            if (
                !isset(self::DIMENSION_TABLES[$dimension])
                || self::DIMENSION_TABLES[$dimension] !== $sourceTable
                || $column !== 'name'
                || !is_array($values)
                || empty($values)
                || !in_array($sourceTable, $aliases, true)
            ) {
                self::mismatch();
            }

            $key = $dimension . "\0" . $sourceTable;
            if (!isset($combined[$key])) {
                $combined[$key] = [
                    'dimension' => $dimension,
                    'source_table' => $sourceTable,
                    'values' => [],
                ];
            }
            foreach ($values as $value) {
                $combined[$key]['values'][] = $value;
            }
        }

        foreach ($combined as &$filter) {
            $filter['values'] = self::normalizedValueSet($filter['values']);
        }
        unset($filter);

        return array_values($combined);
    }

    private static function conjunctiveTerms(string $sql, string $code): array
    {
        list($sql, $code) = self::trimAligned($sql, $code);
        while (self::hasEnclosingParentheses($code)) {
            list($sql, $code) = self::trimAligned(
                substr($sql, 1, -1),
                substr($code, 1, -1)
            );
        }

        $andOffsets = [];
        foreach (self::topLevelWords($code) as $word) {
            if ($word['word'] === 'and') {
                $andOffsets[] = [
                    'offset' => $word['offset'],
                    'length' => strlen($word['text']),
                ];
            }
        }

        if (empty($andOffsets)) {
            return $sql === '' ? [] : [$sql];
        }

        $terms = [];
        $start = 0;
        foreach ($andOffsets as $andOffset) {
            $length = $andOffset['offset'] - $start;
            $terms = array_merge(
                $terms,
                self::conjunctiveTerms(
                    substr($sql, $start, $length),
                    substr($code, $start, $length)
                )
            );
            $start = $andOffset['offset'] + $andOffset['length'];
        }
        $terms = array_merge(
            $terms,
            self::conjunctiveTerms(substr($sql, $start), substr($code, $start))
        );

        return $terms;
    }

    private static function trimAligned(string $sql, string $code): array
    {
        $length = strlen($sql);
        $start = 0;
        while ($start < $length && preg_match('/\s/', $sql[$start]) === 1) {
            $start++;
        }

        $end = $length;
        while ($end > $start && preg_match('/\s/', $sql[$end - 1]) === 1) {
            $end--;
        }

        return [
            substr($sql, $start, $end - $start),
            substr($code, $start, $end - $start),
        ];
    }

    private static function hasEnclosingParentheses(string $code): bool
    {
        $length = strlen($code);
        if ($length < 2 || $code[0] !== '(' || $code[$length - 1] !== ')') {
            return false;
        }

        $depth = 0;
        for ($index = 0; $index < $length; $index++) {
            if ($code[$index] === '(') {
                $depth++;
            } elseif ($code[$index] === ')') {
                $depth--;
                if ($depth === 0 && $index !== $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    private static function positiveNameValues(array $terms, array $aliases): array
    {
        $identifier = '(?:"(?:[^"]|"")*"|[A-Za-z_][A-Za-z0-9_$]*)';
        $reference = $identifier . '(?:\s*\.\s*' . $identifier . ')*';
        $nameField = '(' . $reference . '\s*\.\s*(?:"name"|name))';
        $literal = "('(?:''|[^'])*')";
        $literalToken = "'(?:''|[^'])*'";
        $predicates = [];

        foreach ($terms as $term) {
            if (
                preg_match(
                    '/\ALOWER\s*\(\s*' . $nameField . '\s*\)\s*=\s*LOWER\s*\(\s*'
                        . $literal . '\s*\)\z/i',
                    $term,
                    $match
                ) === 1
            ) {
                self::appendResolvedPredicate(
                    $predicates,
                    $aliases,
                    $match[1],
                    self::unquoteSqlLiteral($match[2])
                );
                continue;
            }

            if (
                preg_match(
                    '/\ALOWER\s*\(\s*' . $literal . '\s*\)\s*=\s*LOWER\s*\(\s*'
                        . $nameField . '\s*\)\z/i',
                    $term,
                    $match
                ) === 1
            ) {
                self::appendResolvedPredicate(
                    $predicates,
                    $aliases,
                    $match[2],
                    self::unquoteSqlLiteral($match[1])
                );
                continue;
            }

            if (
                preg_match(
                    '/\A' . $nameField . '\s*=\s*' . $literal . '\z/i',
                    $term,
                    $match
                ) === 1
            ) {
                self::appendResolvedPredicate(
                    $predicates,
                    $aliases,
                    $match[1],
                    self::unquoteSqlLiteral($match[2])
                );
                continue;
            }

            if (
                preg_match(
                    '/\A' . $literal . '\s*=\s*' . $nameField . '\z/i',
                    $term,
                    $match
                ) === 1
            ) {
                self::appendResolvedPredicate(
                    $predicates,
                    $aliases,
                    $match[2],
                    self::unquoteSqlLiteral($match[1])
                );
                continue;
            }

            if (
                preg_match(
                    '/\A' . $nameField . '\s+(?:ILIKE|LIKE)\s+' . $literal
                        . '(?:\s+ESCAPE\s+' . $literal . ')?\z/i',
                    $term,
                    $match
                ) === 1
            ) {
                $escapeLiteral = isset($match[3]) && $match[3] !== ''
                    ? $match[3]
                    : null;
                self::appendResolvedPredicate(
                    $predicates,
                    $aliases,
                    $match[1],
                    self::exactLikeValue($match[2], $escapeLiteral)
                );
                continue;
            }

            if (
                preg_match(
                    '/\A' . $nameField . '\s+IN\s*\((.*)\)\z/is',
                    $term,
                    $match
                ) === 1
            ) {
                $list = $match[2];
                if (
                    preg_match(
                        '/\A\s*' . $literalToken
                            . '(?:\s*,\s*' . $literalToken . ')*\s*\z/s',
                        $list
                    ) !== 1
                ) {
                    continue;
                }

                preg_match_all('/' . $literalToken . '/', $list, $literalMatches);
                foreach ($literalMatches[0] as $valueLiteral) {
                    self::appendResolvedPredicate(
                        $predicates,
                        $aliases,
                        $match[1],
                        self::unquoteSqlLiteral($valueLiteral)
                    );
                }
            }
        }

        return $predicates;
    }

    private static function assertSupportedHierarchyPredicates(array $terms, array $aliases): void
    {
        foreach ($terms as $term) {
            if (!self::containsHierarchyNameField($term, $aliases)) {
                continue;
            }

            if (!empty(self::positiveNameValues([$term], $aliases))) {
                continue;
            }

            if (self::isSupportedNegativeHierarchyPredicate($term, $aliases)) {
                continue;
            }

            self::mismatch();
        }
    }

    private static function containsHierarchyNameField(string $term, array $aliases): bool
    {
        $identifier = '(?:"(?:[^"]|"")*"|[A-Za-z_][A-Za-z0-9_$]*)';
        $reference = $identifier . '(?:\s*\.\s*' . $identifier . ')*';
        preg_match_all('/(' . $reference . '\s*\.\s*(?:"name"|name))/i', $term, $matches);

        foreach ($matches[1] as $field) {
            if (self::isHierarchyNameField((string) $field, $aliases)) {
                return true;
            }
        }

        return false;
    }

    private static function isSupportedNegativeHierarchyPredicate(string $term, array $aliases): bool
    {
        $identifier = '(?:"(?:[^"]|"")*"|[A-Za-z_][A-Za-z0-9_$]*)';
        $reference = $identifier . '(?:\s*\.\s*' . $identifier . ')*';
        $nameField = '(' . $reference . '\s*\.\s*(?:"name"|name))';
        $literal = "('(?:''|[^'])*')";
        $literalToken = "'(?:''|[^'])*'";
        $patterns = [
            '/\A' . $nameField . '\s*(?:<>|!=)\s*' . $literal . '\z/i',
            '/\A' . $literal . '\s*(?:<>|!=)\s*' . $nameField . '\z/i',
            '/\A' . $nameField . '\s+NOT\s+(?:ILIKE|LIKE)\s+' . $literal
                . '(?:\s+ESCAPE\s+' . $literal . ')?\z/i',
            '/\A' . $nameField . '\s+NOT\s+IN\s*\(\s*' . $literalToken
                . '(?:\s*,\s*' . $literalToken . ')*\s*\)\z/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $term, $match) !== 1) {
                continue;
            }

            foreach ($match as $candidate) {
                if (is_string($candidate) && self::isHierarchyNameField($candidate, $aliases)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isHierarchyNameField(string $field, array $aliases): bool
    {
        $field = self::normalizeQualifiedIdentifier($field);
        $parts = explode('.', $field);
        if (count($parts) < 2 || array_pop($parts) !== 'name') {
            return false;
        }

        $reference = implode('.', $parts);
        $sourceTable = $aliases[$reference] ?? null;
        $dimension = is_string($sourceTable)
            ? (self::TABLE_DIMENSIONS[$sourceTable] ?? null)
            : null;

        return is_string($dimension)
            && in_array($dimension, self::HIERARCHY_DIMENSIONS, true);
    }

    private static function exactLikeValue(string $literal, ?string $escapeLiteral): string
    {
        $pattern = self::unquoteSqlLiteral($literal);
        $escape = '\\';
        if ($escapeLiteral !== null) {
            $escape = self::unquoteSqlLiteral($escapeLiteral);
            if (strlen($escape) > 1) {
                return "\0invalid_like_escape";
            }
        }

        $value = '';
        $length = strlen($pattern);
        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($escape !== '' && $character === $escape) {
                if ($index + 1 >= $length) {
                    return "\0invalid_like_escape";
                }

                $escaped = $pattern[++$index];
                if (!in_array($escaped, ['%', '_', $escape], true)) {
                    return "\0invalid_like_escape";
                }
                $value .= $escaped;
                continue;
            }

            if ($character === '%' || $character === '_') {
                return "\0invalid_like_pattern";
            }
            $value .= $character;
        }

        return $value;
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
        array $resolvedFilters,
        array $positivePredicates
    ): void {
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
            if ($dimension === null) {
                continue;
            }
            if (
                in_array($dimension, self::HIERARCHY_DIMENSIONS, true)
                && !isset($allowedValues[$dimension])
            ) {
                self::mismatch();
            }
            if (!isset($allowedValues[$dimension])) {
                continue;
            }

            $predicateSet = self::normalizedValueSet([$predicate['value']]);
            if (empty(array_intersect($allowedValues[$dimension], $predicateSet))) {
                self::mismatch();
            }
        }
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

    private static function stripTrailingSemicolon(
        string &$sql,
        string &$aliasCode,
        string &$structureCode
    ): void {
        $trimmedLength = strlen(rtrim($structureCode));
        $semicolonOffset = strpos($structureCode, ';');
        if ($semicolonOffset === false) {
            return;
        }

        if (
            $trimmedLength === 0
            || $structureCode[$trimmedLength - 1] !== ';'
            || strpos($structureCode, ';', $semicolonOffset + 1) !== false
        ) {
            self::mismatch();
        }

        $sql = substr($sql, 0, $trimmedLength - 1);
        $aliasCode = substr($aliasCode, 0, $trimmedLength - 1);
        $structureCode = substr($structureCode, 0, $trimmedLength - 1);
    }

    private static function sqlWithoutIgnoredText(string $sql): string
    {
        $length = strlen($sql);
        $result = '';

        for ($index = 0; $index < $length;) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($character === "'") {
                $start = $index++;
                while ($index < $length) {
                    if ($sql[$index] !== "'") {
                        $index++;
                        continue;
                    }
                    if ($index + 1 < $length && $sql[$index + 1] === "'") {
                        $index += 2;
                        continue;
                    }
                    $index++;
                    break;
                }
                if ($index > $length || $sql[$index - 1] !== "'") {
                    self::mismatch();
                }
                $result .= substr($sql, $start, $index - $start);
                continue;
            }

            if ($character === '"') {
                $start = $index++;
                while ($index < $length) {
                    if ($sql[$index] !== '"') {
                        $index++;
                        continue;
                    }
                    if ($index + 1 < $length && $sql[$index + 1] === '"') {
                        $index += 2;
                        continue;
                    }
                    $index++;
                    break;
                }
                if ($index > $length || $sql[$index - 1] !== '"') {
                    self::mismatch();
                }
                $result .= substr($sql, $start, $index - $start);
                continue;
            }

            if ($character === '-' && $next === '-') {
                $start = $index;
                $index += 2;
                while ($index < $length && $sql[$index] !== "\n" && $sql[$index] !== "\r") {
                    $index++;
                }
                $result .= self::maskedText(substr($sql, $start, $index - $start));
                continue;
            }

            if ($character === '/' && $next === '*') {
                $start = $index;
                $index += 2;
                $depth = 1;
                while ($index < $length && $depth > 0) {
                    $following = $index + 1 < $length ? $sql[$index + 1] : '';
                    if ($sql[$index] === '/' && $following === '*') {
                        $depth++;
                        $index += 2;
                    } elseif ($sql[$index] === '*' && $following === '/') {
                        $depth--;
                        $index += 2;
                    } else {
                        $index++;
                    }
                }
                if ($depth !== 0) {
                    self::mismatch();
                }
                $result .= self::maskedText(substr($sql, $start, $index - $start));
                continue;
            }

            if ($character === '$') {
                $tail = substr($sql, $index);
                if (preg_match('/\A\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', $tail, $match) === 1) {
                    $delimiter = $match[0];
                    $closingOffset = strpos($sql, $delimiter, $index + strlen($delimiter));
                    if ($closingOffset === false) {
                        self::mismatch();
                    }
                    $end = $closingOffset + strlen($delimiter);
                    $result .= self::maskedText(substr($sql, $index, $end - $index));
                    $index = $end;
                    continue;
                }
            }

            $result .= $character;
            $index++;
        }

        return $result;
    }

    private static function sqlCode(string $sql, bool $preserveDoubleQuotes): string
    {
        $length = strlen($sql);
        $result = '';
        for ($index = 0; $index < $length;) {
            $character = $sql[$index];
            if ($character !== "'" && ($character !== '"' || $preserveDoubleQuotes)) {
                $result .= $character;
                $index++;
                continue;
            }

            $quote = $character;
            $start = $index++;
            while ($index < $length) {
                if ($sql[$index] !== $quote) {
                    $index++;
                    continue;
                }
                if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                    $index += 2;
                    continue;
                }
                $index++;
                break;
            }
            $result .= self::maskedText(substr($sql, $start, $index - $start));
        }

        return $result;
    }

    private static function maskedText(string $text): string
    {
        return preg_replace('/[^\r\n]/', ' ', $text);
    }

    private static function mismatch(): void
    {
        throw new \InvalidArgumentException('resolved_reference_filter_mismatch');
    }
}
