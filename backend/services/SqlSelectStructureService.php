<?php

namespace app\services;

/**
 * Conservative structural analysis for SELECT statements.
 *
 * This is intentionally not a general SQL parser. Canonical Builder binding uses
 * the strict path, which accepts only a direct table plus explicit INNER/LEFT
 * joins with one equality predicate. Policy checks use the same tokenizer to
 * enumerate table references, including implicit comma joins.
 */
class SqlSelectStructureService
{
    private const CLAUSE_KEYWORDS = [
        'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET', 'FETCH',
        'UNION', 'INTERSECT', 'EXCEPT', 'WINDOW', 'FOR',
    ];

    private const ALIAS_STOP_WORDS = [
        'ON', 'USING', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS',
        'NATURAL', 'OUTER', 'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT',
        'OFFSET', 'FETCH', 'UNION', 'INTERSECT', 'EXCEPT', 'WINDOW', 'FOR',
    ];

    /**
     * Return canonical table and join semantics, failing closed on structures
     * that cannot be bound safely to a Builder definition.
     */
    public static function analyzeCanonical(string $sql): array
    {
        $tokens = self::tokenize($sql);
        if (empty($tokens) || self::upper($tokens[0]) !== 'SELECT') {
            throw new \InvalidArgumentException('Edited canonical SQL must be one SELECT statement.');
        }

        $depths = self::depths($tokens);
        foreach ($tokens as $index => $token) {
            if ($token['value'] === ';' && $index !== count($tokens) - 1) {
                throw new \InvalidArgumentException('Edited canonical SQL must contain one statement.');
            }
            if (($depths[$index] ?? 0) === 0 && in_array(self::upper($token), ['UNION', 'INTERSECT', 'EXCEPT'], true)) {
                throw new \InvalidArgumentException('Set operations are not supported in edited canonical SQL.');
            }
            if (($depths[$index] ?? 0) > 0 && self::upper($token) === 'SELECT') {
                throw new \InvalidArgumentException(
                    'Nested queries are not supported in edited canonical SQL.'
                );
            }
        }

        $fromIndex = self::findTopLevelKeyword($tokens, $depths, 'FROM');
        if ($fromIndex === null) {
            throw new \InvalidArgumentException('Edited canonical SQL must contain a direct FROM table.');
        }

        $cursor = $fromIndex + 1;
        [$baseTable, $cursor] = self::readTableName($tokens, $cursor);
        [$baseAlias, $cursor] = self::readAlias($tokens, $cursor, $baseTable);
        $aliases = [$baseAlias => $baseTable, $baseTable => $baseTable];
        $tables = [$baseTable];
        $joins = [];

        while ($cursor < count($tokens)) {
            if ($tokens[$cursor]['value'] === ';') {
                $cursor++;
                break;
            }
            $keyword = self::upper($tokens[$cursor]);
            if (in_array($keyword, self::CLAUSE_KEYWORDS, true)) {
                break;
            }
            if ($tokens[$cursor]['value'] === ',') {
                throw new \InvalidArgumentException('Implicit comma joins are not supported in edited canonical SQL.');
            }

            [$joinType, $cursor] = self::readJoinType($tokens, $cursor);
            [$joinedTable, $cursor] = self::readTableName($tokens, $cursor);
            [$joinedAlias, $cursor] = self::readAlias($tokens, $cursor, $joinedTable);
            if (isset($aliases[$joinedAlias]) || in_array($joinedTable, $tables, true)) {
                throw new \InvalidArgumentException('Duplicate or ambiguous table aliases are not supported in edited canonical SQL.');
            }
            $aliases[$joinedAlias] = $joinedTable;
            $aliases[$joinedTable] = $joinedTable;
            $tables[] = $joinedTable;

            if (!isset($tokens[$cursor]) || self::upper($tokens[$cursor]) !== 'ON') {
                throw new \InvalidArgumentException('Edited canonical joins must use one ON equality predicate.');
            }
            $cursor++;
            $predicateStart = $cursor;
            $localDepth = 0;
            while ($cursor < count($tokens)) {
                $value = $tokens[$cursor]['value'];
                if ($value === '(') {
                    $localDepth++;
                } elseif ($value === ')') {
                    $localDepth--;
                    if ($localDepth < 0) {
                        throw new \InvalidArgumentException('Unbalanced join predicate parentheses.');
                    }
                }
                if ($localDepth === 0 && self::isJoinOrClauseStart($tokens, $cursor)) {
                    break;
                }
                if ($localDepth === 0 && $value === ';') {
                    break;
                }
                $cursor++;
            }
            $predicate = array_slice($tokens, $predicateStart, $cursor - $predicateStart);
            [$leftEndpoint, $rightEndpoint] = self::parseEqualityPredicate($predicate, $aliases);
            $endpoints = [$leftEndpoint, $rightEndpoint];
            sort($endpoints, SORT_STRING);
            $joins[] = [
                'join_type' => $joinType,
                'table' => $joinedTable,
                'endpoints' => $endpoints,
            ];
        }

        while ($cursor < count($tokens) && $tokens[$cursor]['value'] !== ';') {
            $cursor++;
        }
        if ($cursor < count($tokens) - 1) {
            throw new \InvalidArgumentException('Edited canonical SQL must contain one statement.');
        }

        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        usort($joins, static fn(array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));

        return ['tables' => $tables, 'joins' => $joins];
    }

    /**
     * Enumerate direct table references after FROM/JOIN, including subsequent
     * comma-separated FROM entries. Derived expressions are scanned internally
     * by the same token stream, so their own FROM/JOIN references are included.
     */
    public static function extractTableReferences(string $sql): array
    {
        $tokens = self::tokenize($sql);
        $references = [];
        $count = count($tokens);
        $depth = 0;
        $fromContext = [];

        for ($index = 0; $index < $count; $index++) {
            $value = $tokens[$index]['value'];
            if ($value === '(') {
                $depth++;
                continue;
            }
            if ($value === ')') {
                unset($fromContext[$depth]);
                $depth = max(0, $depth - 1);
                continue;
            }

            $keyword = self::upper($tokens[$index]);
            if (in_array($keyword, self::CLAUSE_KEYWORDS, true)) {
                $fromContext[$depth] = false;
                continue;
            }
            if ($keyword === 'FROM') {
                $fromContext[$depth] = true;
            }
            $startsTable = $keyword === 'FROM'
                || ($keyword === 'JOIN' && !empty($fromContext[$depth]))
                || ($value === ',' && !empty($fromContext[$depth]));
            if (!$startsTable) {
                continue;
            }

            $cursor = $index + 1;
            $only = false;
            if (isset($tokens[$cursor]) && self::upper($tokens[$cursor]) === 'ONLY') {
                $only = true;
                $cursor++;
            }
            if (!isset($tokens[$cursor])) {
                continue;
            }
            if ($only && $tokens[$cursor]['value'] === '(') {
                [$table, $afterTable] = self::readTableName($tokens, $cursor + 1);
                if (!isset($tokens[$afterTable]) || $tokens[$afterTable]['value'] !== ')') {
                    throw new \InvalidArgumentException('Unsupported PostgreSQL ONLY table source.');
                }
                $references[] = $table;
                continue;
            }
            if ($tokens[$cursor]['value'] === '(') continue;
            try {
                [$table] = self::readTableName($tokens, $cursor);
                $references[] = $table;
            } catch (\InvalidArgumentException $e) {
                // Functions/derived expressions are not direct table references.
            }
        }

        $references = array_values(array_unique($references));
        return $references;
    }

    private static function tokenize(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';
            if (ctype_space($char)) {
                $index++;
                continue;
            }
            if ($char === '-' && $next === '-') {
                $index += 2;
                while ($index < $length && $sql[$index] !== "\n") $index++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $index + 2);
                if ($end === false) throw new \InvalidArgumentException('Unterminated SQL comment.');
                $index = $end + 2;
                continue;
            }
            if ($char === "'") {
                $start = $index++;
                $closed = false;
                while ($index < $length) {
                    if ($sql[$index] === "'" && $index + 1 < $length && $sql[$index + 1] === "'") {
                        $index += 2;
                        continue;
                    }
                    if ($sql[$index++] === "'") {
                        $closed = true;
                        break;
                    }
                }
                if (!$closed) throw new \InvalidArgumentException('Unterminated SQL string.');
                $tokens[] = ['kind' => 'string', 'value' => substr($sql, $start, $index - $start)];
                continue;
            }
            if ($char === '"') {
                $index++;
                $value = '';
                $closed = false;
                while ($index < $length) {
                    if ($sql[$index] === '"' && $index + 1 < $length && $sql[$index + 1] === '"') {
                        $value .= '"';
                        $index += 2;
                        continue;
                    }
                    if ($sql[$index] === '"') {
                        $index++;
                        $closed = true;
                        break;
                    }
                    $value .= $sql[$index++];
                }
                if (!$closed) throw new \InvalidArgumentException('Unterminated quoted identifier.');
                $tokens[] = ['kind' => 'identifier', 'value' => strtolower($value)];
                continue;
            }
            if (preg_match('/[A-Za-z_]/', $char) === 1) {
                $start = $index++;
                while ($index < $length && preg_match('/[A-Za-z0-9_$-]/', $sql[$index]) === 1) $index++;
                $tokens[] = ['kind' => 'identifier', 'value' => strtolower(substr($sql, $start, $index - $start))];
                continue;
            }
            if (ctype_digit($char)) {
                $start = $index++;
                while ($index < $length && preg_match('/[0-9.]/', $sql[$index]) === 1) $index++;
                $tokens[] = ['kind' => 'number', 'value' => substr($sql, $start, $index - $start)];
                continue;
            }
            if (in_array($char . $next, ['>=', '<=', '<>', '!=', '::'], true)) {
                $tokens[] = ['kind' => 'symbol', 'value' => $char . $next];
                $index += 2;
                continue;
            }
            $tokens[] = ['kind' => 'symbol', 'value' => $char];
            $index++;
        }
        return $tokens;
    }

    private static function depths(array $tokens): array
    {
        $depth = 0;
        $depths = [];
        foreach ($tokens as $index => $token) {
            if ($token['value'] === ')') $depth--;
            if ($depth < 0) throw new \InvalidArgumentException('Unbalanced SQL parentheses.');
            $depths[$index] = $depth;
            if ($token['value'] === '(') $depth++;
        }
        if ($depth !== 0) throw new \InvalidArgumentException('Unbalanced SQL parentheses.');
        return $depths;
    }

    private static function findTopLevelKeyword(array $tokens, array $depths, string $keyword): ?int
    {
        foreach ($tokens as $index => $token) {
            if (($depths[$index] ?? -1) === 0 && self::upper($token) === $keyword) return $index;
        }
        return null;
    }

    private static function readTableName(array $tokens, int $cursor): array
    {
        if (!isset($tokens[$cursor]) || $tokens[$cursor]['kind'] !== 'identifier') {
            throw new \InvalidArgumentException('Expected a direct schema-qualified table name.');
        }
        if (in_array(self::upper($tokens[$cursor]), ['SELECT', 'LATERAL', 'UNNEST', 'ONLY'], true)) {
            throw new \InvalidArgumentException('Derived table expressions are not supported here.');
        }
        $parts = [$tokens[$cursor]['value']];
        $cursor++;
        while (isset($tokens[$cursor + 1]) && $tokens[$cursor]['value'] === '.' && $tokens[$cursor + 1]['kind'] === 'identifier') {
            $parts[] = $tokens[$cursor + 1]['value'];
            $cursor += 2;
        }
        if (count($parts) > 2) throw new \InvalidArgumentException('Three-part table names are not supported.');
        return [implode('.', $parts), $cursor];
    }

    private static function readAlias(array $tokens, int $cursor, string $table): array
    {
        if (isset($tokens[$cursor]) && self::upper($tokens[$cursor]) === 'AS') $cursor++;
        if (isset($tokens[$cursor]) && $tokens[$cursor]['kind'] === 'identifier'
            && !in_array(self::upper($tokens[$cursor]), self::ALIAS_STOP_WORDS, true)) {
            return [$tokens[$cursor]['value'], $cursor + 1];
        }
        $parts = explode('.', $table);
        return [end($parts), $cursor];
    }

    private static function readJoinType(array $tokens, int $cursor): array
    {
        $keyword = self::upper($tokens[$cursor] ?? ['value' => '']);
        if ($keyword === 'JOIN') return ['INNER', $cursor + 1];
        if ($keyword === 'INNER' && self::upper($tokens[$cursor + 1] ?? ['value' => '']) === 'JOIN') {
            return ['INNER', $cursor + 2];
        }
        if ($keyword === 'LEFT') {
            $cursor++;
            if (self::upper($tokens[$cursor] ?? ['value' => '']) === 'OUTER') $cursor++;
            if (self::upper($tokens[$cursor] ?? ['value' => '']) === 'JOIN') return ['LEFT', $cursor + 1];
        }
        if (in_array($keyword, ['RIGHT', 'FULL', 'CROSS', 'NATURAL'], true)) {
            throw new \InvalidArgumentException($keyword . ' JOIN is not supported in edited canonical SQL.');
        }
        throw new \InvalidArgumentException('Unsupported or ambiguous FROM/JOIN structure in edited canonical SQL.');
    }

    private static function parseEqualityPredicate(array $tokens, array $aliases): array
    {
        while (count($tokens) >= 2 && $tokens[0]['value'] === '(' && end($tokens)['value'] === ')' && self::wrapsEntireExpression($tokens)) {
            array_shift($tokens);
            array_pop($tokens);
        }
        $equals = [];
        foreach ($tokens as $index => $token) if ($token['value'] === '=') $equals[] = $index;
        if (count($equals) !== 1) throw new \InvalidArgumentException('Edited canonical joins must contain one equality predicate.');
        $position = $equals[0];
        $left = self::parseColumnReference(array_slice($tokens, 0, $position), $aliases);
        $right = self::parseColumnReference(array_slice($tokens, $position + 1), $aliases);
        if ($left === $right) throw new \InvalidArgumentException('A join predicate must connect two distinct columns.');
        return [$left, $right];
    }

    private static function wrapsEntireExpression(array $tokens): bool
    {
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if ($token['value'] === '(') $depth++;
            if ($token['value'] === ')') $depth--;
            if ($depth === 0 && $index < count($tokens) - 1) return false;
        }
        return $depth === 0;
    }

    private static function parseColumnReference(array $tokens, array $aliases): string
    {
        while (count($tokens) >= 2 && $tokens[0]['value'] === '(' && end($tokens)['value'] === ')' && self::wrapsEntireExpression($tokens)) {
            array_shift($tokens);
            array_pop($tokens);
        }
        if (count($tokens) !== 3 || $tokens[0]['kind'] !== 'identifier' || $tokens[1]['value'] !== '.' || $tokens[2]['kind'] !== 'identifier') {
            throw new \InvalidArgumentException('Join endpoints must be qualified table-alias columns.');
        }
        $alias = $tokens[0]['value'];
        if (!isset($aliases[$alias])) throw new \InvalidArgumentException('Join predicate references an unknown table alias.');
        return $aliases[$alias] . '.' . $tokens[2]['value'];
    }

    private static function isJoinOrClauseStart(array $tokens, int $cursor): bool
    {
        return self::isJoinStart($tokens, $cursor)
            || in_array(self::upper($tokens[$cursor]), self::CLAUSE_KEYWORDS, true);
    }

    private static function isJoinStart(array $tokens, int $cursor): bool
    {
        return in_array(self::upper($tokens[$cursor] ?? ['value' => '']), ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'NATURAL'], true);
    }

    private static function upper(array $token): string
    {
        return strtoupper((string)($token['value'] ?? ''));
    }
}
