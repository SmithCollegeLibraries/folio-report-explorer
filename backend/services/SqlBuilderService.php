<?php

namespace app\services;

use Yii;

/**
 * SqlBuilderService — translates a structured query definition into
 * parameterized SQL with automatic JOIN clause generation.
 */
class SqlBuilderService
{
    /** Operators allowed in WHERE clauses */
    const VALID_OPERATORS = [
        '=', '!=', '<>', '>', '<', '>=', '<=',
        'LIKE', 'ILIKE', 'NOT LIKE',
        'IN', 'NOT IN',
        'IS NULL', 'IS NOT NULL',
        'BETWEEN',
    ];

    /** DDL/DML keywords that must never appear in user queries */
    const BLOCKED_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
        'CREATE', 'GRANT', 'REVOKE', 'EXECUTE', 'COPY',
    ];

    /** Aggregate functions allowed in SELECT clauses */
    const VALID_AGGREGATES = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /** Unquoted SQL identifier pattern (used for aliases and column refs) */
    const VALID_IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Build a SQL SELECT statement from a structured query definition.
     *
     * @param array $queryDef {
     *   tables: string[],
     *   columns: [{table: string, column: string, alias?: string, aggregate?: string}],
     *   filters: [{table: string, column: string, op: string, value: mixed}],
     *   joins: "auto" | [{from_table, from_column, to_table, to_column}],
     *   orderBy: [{table: string, column: string, dir: "ASC"|"DESC"}],
     *   groupBy: [{table: string, column: string}],
     *   having: [{aggregate: string, table: string, column: string, op: string, value: mixed}],
     *   distinct: bool,
     *   limit: int,
     * }
     * @return array {sql: string, params: array}
     * @throws \InvalidArgumentException
     */
    public static function build(array $queryDef)
    {
        $tables = $queryDef['tables'] ?? [];
        $columns = $queryDef['columns'] ?? [];
        $filters = $queryDef['filters'] ?? [];
        $joins = $queryDef['joins'] ?? 'auto';
        $orderBy = $queryDef['orderBy'] ?? [];
        $groupBy = $queryDef['groupBy'] ?? [];
        $having = $queryDef['having'] ?? [];
        $distinct = !empty($queryDef['distinct']);
        $limit = min(
            (int)($queryDef['limit'] ?? Yii::$app->params['defaultQueryLimit']),
            Yii::$app->params['maxQueryRows']
        );

        if (empty($tables)) {
            throw new \InvalidArgumentException('At least one table is required');
        }

        // Validate table/column references before assembling any SQL.
        self::validateNames($tables, $columns, $filters, $joins, $groupBy, $having, $orderBy);

        // Build table aliases
        $aliases = self::buildAliases($tables);

        // Auto-detect GROUP BY: if any column has an aggregate, non-aggregated columns become GROUP BY
        if (empty($groupBy)) {
            $hasAggregate = false;
            foreach ($columns as $col) {
                if (!empty($col['aggregate']) && in_array(strtoupper($col['aggregate']), self::VALID_AGGREGATES)) {
                    $hasAggregate = true;
                    break;
                }
            }
            if ($hasAggregate) {
                foreach ($columns as $col) {
                    if (empty($col['aggregate'])) {
                        $groupBy[] = ['table' => $col['table'], 'column' => $col['column']];
                    }
                }
            }
        }

        // Build SELECT clause
        $selectParts = self::buildSelect($columns, $aliases);

        // Build FROM + JOIN clauses
        $fromJoin = self::buildFromJoin($tables, $joins, $aliases);

        // Build WHERE clause with parameterized values
        list($whereSql, $params) = self::buildWhere($filters, $aliases);

        // Build GROUP BY
        $groupBySql = self::buildGroupBy($groupBy, $aliases);

        // Build HAVING
        list($havingSql, $havingParams) = self::buildHaving($having, $aliases, count($params));
        $params = array_merge($params, $havingParams);

        // Build ORDER BY
        $orderSql = self::buildOrderBy($orderBy, $aliases);

        // Assemble
        $sql = "SELECT ";
        if ($distinct) {
            $sql .= "DISTINCT ";
        }
        $sql .= implode(",\n       ", $selectParts);
        $sql .= "\nFROM " . $fromJoin;
        if ($whereSql) {
            $sql .= "\nWHERE " . $whereSql;
        }
        if ($groupBySql) {
            $sql .= "\nGROUP BY " . $groupBySql;
        }
        if ($havingSql) {
            $sql .= "\nHAVING " . $havingSql;
        }
        if ($orderSql) {
            $sql .= "\nORDER BY " . $orderSql;
        }
        $sql .= "\nLIMIT " . $limit;

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }

    /**
     * Validate that all referenced tables and columns exist in the schema.
     */
    private static function validateNames($tables, $columns, $filters, $joins, $groupBy, $having, $orderBy)
    {
        $resolvedTables = [];
        foreach ($tables as $t) {
            $matched = FolioSchemaService::fuzzyMatch($t);
            if ($matched === null) {
                throw new \InvalidArgumentException("Unknown table: {$t}");
            }
            $resolvedTables[$matched] = true;
        }

        $columnLookup = self::buildColumnLookup(array_keys($resolvedTables));

        foreach ($columns as $idx => $col) {
            $context = "columns[{$idx}]";
            self::validateTableColumnReference(
                $col['table'] ?? '',
                $col['column'] ?? '',
                $resolvedTables,
                $columnLookup,
                $context,
                true
            );

            $aggregate = strtoupper((string)($col['aggregate'] ?? ''));
            if ($aggregate !== '' && !in_array($aggregate, self::VALID_AGGREGATES, true)) {
                throw new \InvalidArgumentException("Invalid aggregate in {$context}: {$aggregate}");
            }

            if (isset($col['alias']) && trim((string)$col['alias']) !== '') {
                self::validateIdentifier(trim((string)$col['alias']), "{$context}.alias", false);
            }
        }

        foreach ($filters as $idx => $f) {
            self::validateTableColumnReference(
                $f['table'] ?? '',
                $f['column'] ?? '',
                $resolvedTables,
                $columnLookup,
                "filters[{$idx}]",
                false
            );
        }

        foreach ($groupBy as $idx => $g) {
            self::validateTableColumnReference(
                $g['table'] ?? '',
                $g['column'] ?? '',
                $resolvedTables,
                $columnLookup,
                "groupBy[{$idx}]",
                false
            );
        }

        foreach ($orderBy as $idx => $o) {
            self::validateTableColumnReference(
                $o['table'] ?? '',
                $o['column'] ?? '',
                $resolvedTables,
                $columnLookup,
                "orderBy[{$idx}]",
                false
            );
        }

        foreach ($having as $idx => $h) {
            self::validateTableColumnReference(
                $h['table'] ?? '',
                $h['column'] ?? '',
                $resolvedTables,
                $columnLookup,
                "having[{$idx}]",
                true
            );
        }

        if (is_array($joins)) {
            foreach ($joins as $idx => $j) {
                self::validateTableColumnReference(
                    $j['from_table'] ?? '',
                    $j['from_column'] ?? '',
                    $resolvedTables,
                    $columnLookup,
                    "joins[{$idx}].from",
                    false
                );
                self::validateTableColumnReference(
                    $j['to_table'] ?? '',
                    $j['to_column'] ?? '',
                    $resolvedTables,
                    $columnLookup,
                    "joins[{$idx}].to",
                    false
                );
            }
        }
    }

    /**
     * Build a lowercase column lookup map keyed by resolved table name.
     * @param array $resolvedTables
     * @return array
     */
    private static function buildColumnLookup(array $resolvedTables)
    {
        $lookup = [];
        foreach ($resolvedTables as $tableName) {
            $detail = FolioSchemaService::getTable($tableName);
            $columns = $detail['table']['columns'] ?? [];

            $lookup[$tableName] = [];
            foreach ($columns as $col) {
                $name = $col['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $lookup[$tableName][strtolower($name)] = true;
                }
            }

            if (empty($lookup[$tableName])) {
                throw new \InvalidArgumentException("Unable to load columns for table: {$tableName}");
            }
        }

        return $lookup;
    }

    /**
     * Validate one table+column reference used by a specific query clause.
     */
    private static function validateTableColumnReference($tableInput, $columnInput, $allowedTables, $columnLookup, $context, $allowStar)
    {
        $tableInput = trim((string)$tableInput);
        $columnInput = trim((string)$columnInput);

        if ($tableInput === '') {
            throw new \InvalidArgumentException("Missing table in {$context}");
        }
        if ($columnInput === '') {
            throw new \InvalidArgumentException("Missing column in {$context}");
        }

        $resolvedTable = FolioSchemaService::fuzzyMatch($tableInput);
        if ($resolvedTable === null) {
            throw new \InvalidArgumentException("Unknown table in {$context}: {$tableInput}");
        }
        if (!isset($allowedTables[$resolvedTable])) {
            throw new \InvalidArgumentException(
                "Table '{$resolvedTable}' in {$context} must be included in tables list"
            );
        }

        self::validateIdentifier($columnInput, "{$context}.column", $allowStar);
        if ($columnInput === '*') {
            return;
        }

        if (!isset($columnLookup[$resolvedTable][strtolower($columnInput)])) {
            throw new \InvalidArgumentException(
                "Unknown column in {$context}: {$resolvedTable}.{$columnInput}"
            );
        }
    }

    /**
     * Validate a SQL identifier used in query definition inputs.
     */
    private static function validateIdentifier($identifier, $context, $allowStar)
    {
        if ($allowStar && $identifier === '*') {
            return;
        }
        if (!preg_match(self::VALID_IDENTIFIER_PATTERN, $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier in {$context}: {$identifier}");
        }
    }

    /**
     * Create short aliases for tables.
     * @param array $tables
     * @return array [tableName => alias]
     */
    private static function buildAliases(array $tables)
    {
        $aliases = [];
        $used = [];

        foreach ($tables as $i => $table) {
            $table = FolioSchemaService::fuzzyMatch($table) ?: $table;

            // Try first letter of each word part
            $parts = explode('_', $table);
            $alias = '';
            foreach ($parts as $p) {
                $alias .= substr($p, 0, 1);
            }

            // Ensure uniqueness
            $base = $alias;
            $n = 1;
            while (isset($used[$alias])) {
                $alias = $base . $n;
                $n++;
            }
            $used[$alias] = true;
            $aliases[$table] = $alias;
        }

        return $aliases;
    }

    /**
     * Build SELECT column list, with optional aggregate wrapping.
     */
    private static function buildSelect($columns, $aliases)
    {
        if (empty($columns)) {
            // Default: select all columns from all tables
            $parts = [];
            foreach ($aliases as $table => $alias) {
                $parts[] = "{$alias}.*";
            }
            return $parts;
        }

        $parts = [];
        foreach ($columns as $col) {
            $table = FolioSchemaService::fuzzyMatch($col['table'] ?? '') ?: ($col['table'] ?? '');
            $column = $col['column'] ?? '*';
            $alias = $aliases[$table] ?? $table;
            $displayAlias = $col['alias'] ?? null;
            $aggregate = !empty($col['aggregate']) ? strtoupper($col['aggregate']) : null;

            $expr = "{$alias}.{$column}";

            // Wrap in aggregate function if specified
            if ($aggregate && in_array($aggregate, self::VALID_AGGREGATES)) {
                $expr = "{$aggregate}({$expr})";
                // Auto-generate alias if none specified
                if (!$displayAlias) {
                    $displayAlias = strtolower($aggregate) . '_' . $column;
                }
            }

            if ($displayAlias) {
                $expr .= " AS {$displayAlias}";
            }
            $parts[] = $expr;
        }

        return $parts;
    }

    /**
     * Build FROM + JOIN clauses using auto-join or explicit joins.
     * Translates LDP1 table names to MetaDB schema-qualified names
     * (e.g. inventory_items → folio_inventory.item__t) in the SQL output.
     */
    private static function buildFromJoin($tables, $joins, $aliases)
    {
        // Resolve fuzzy names (LDP1 names)
        $resolved = [];
        foreach ($tables as $t) {
            $resolved[] = FolioSchemaService::fuzzyMatch($t) ?: $t;
        }

        $primary = $resolved[0];
        $primaryAlias = $aliases[$primary] ?? $primary;
        // Translate to MetaDB name for the FROM clause
        $primaryMetadb = FolioSchemaService::translateToMetadb($primary);
        $sql = "{$primaryMetadb} {$primaryAlias}";

        if (count($resolved) <= 1) {
            return $sql;
        }

        if ($joins === 'auto') {
            // Auto-discover JOINs using BFS path finder
            $joined = [$primary => true];

            for ($i = 1; $i < count($resolved); $i++) {
                $target = $resolved[$i];
                if (isset($joined[$target])) {
                    continue;
                }

                // Find path from any already-joined table to the target
                $bestPath = null;
                foreach (array_keys($joined) as $source) {
                    $path = FolioSchemaService::findShortestPath($source, $target);
                    if ($path !== null && ($bestPath === null || count($path) < count($bestPath))) {
                        $bestPath = $path;
                    }
                }

                if ($bestPath === null) {
                    throw new \InvalidArgumentException(
                        "Cannot find FK path to join table '{$target}'"
                    );
                }

                // Add each hop as a JOIN — use MetaDB names in SQL
                foreach ($bestPath as $edge) {
                    list($fromTbl, $fromCol, $toTbl, $toCol) = $edge;
                    if (!isset($joined[$toTbl])) {
                        $toAlias = $aliases[$toTbl] ?? $toTbl;
                        $fromAlias = $aliases[$fromTbl] ?? $fromTbl;
                        $toMetadb = FolioSchemaService::translateToMetadb($toTbl);
                        $sql .= "\nJOIN {$toMetadb} {$toAlias}";
                        $sql .= "\n  ON {$toAlias}.{$toCol} = {$fromAlias}.{$fromCol}";
                        $joined[$toTbl] = true;
                    }
                }
            }
        } else {
            // Explicit joins — also translate table names
            // Supports optional join_type: 'JOIN' (default) or 'LEFT JOIN'
            foreach ($joins as $j) {
                $toTbl = $j['to_table'] ?? '';
                $toCol = $j['to_column'] ?? '';
                $fromTbl = $j['from_table'] ?? '';
                $fromCol = $j['from_column'] ?? '';
                $toAlias = $aliases[$toTbl] ?? $toTbl;
                $fromAlias = $aliases[$fromTbl] ?? $fromTbl;
                $toMetadb = FolioSchemaService::translateToMetadb($toTbl);
                // Only allow JOIN or LEFT JOIN
                $joinType = 'JOIN';
                if (isset($j['join_type']) && strtoupper(trim($j['join_type'])) === 'LEFT JOIN') {
                    $joinType = 'LEFT JOIN';
                }
                $sql .= "\n{$joinType} {$toMetadb} {$toAlias}";
                $sql .= "\n  ON {$toAlias}.{$toCol} = {$fromAlias}.{$fromCol}";
            }
        }

        return $sql;
    }

    /**
     * Build WHERE clause with parameterized values.
     * @return array [whereString, params]
     */
    private static function buildWhere($filters, $aliases)
    {
        if (empty($filters)) {
            return ['', []];
        }

        $conditions = [];
        $params = [];
        $paramIndex = 0;

        foreach ($filters as $f) {
            $table = FolioSchemaService::fuzzyMatch($f['table'] ?? '') ?: ($f['table'] ?? '');
            $column = $f['column'] ?? '';
            $op = strtoupper($f['op'] ?? '=');
            $value = $f['value'] ?? null;
            $alias = $aliases[$table] ?? $table;

            if (!in_array($op, self::VALID_OPERATORS)) {
                throw new \InvalidArgumentException("Invalid operator: $op");
            }

            $qualifiedCol = "{$alias}.{$column}";

            if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                $conditions[] = "{$qualifiedCol} {$op}";
            } elseif ($op === 'IN' || $op === 'NOT IN') {
                $values = is_array($value) ? $value : explode(',', $value);
                $placeholders = [];
                foreach ($values as $v) {
                    $paramName = ':p' . $paramIndex++;
                    $placeholders[] = $paramName;
                    $params[$paramName] = trim($v);
                }
                $conditions[] = "{$qualifiedCol} {$op} (" . implode(', ', $placeholders) . ")";
            } elseif ($op === 'BETWEEN') {
                $values = is_array($value) ? $value : explode(',', $value);
                if (count($values) !== 2) {
                    throw new \InvalidArgumentException("BETWEEN requires exactly 2 values");
                }
                $p1 = ':p' . $paramIndex++;
                $p2 = ':p' . $paramIndex++;
                $params[$p1] = trim($values[0]);
                $params[$p2] = trim($values[1]);
                $conditions[] = "{$qualifiedCol} BETWEEN {$p1} AND {$p2}";
            } else {
                $paramName = ':p' . $paramIndex++;
                $params[$paramName] = $value;
                $conditions[] = "{$qualifiedCol} {$op} {$paramName}";
            }
        }

        $connector = ' AND ';
        return [implode($connector, $conditions), $params];
    }

    /**
     * Build GROUP BY clause.
     */
    private static function buildGroupBy($groupBy, $aliases)
    {
        if (empty($groupBy)) {
            return '';
        }

        $parts = [];
        foreach ($groupBy as $g) {
            $table = FolioSchemaService::fuzzyMatch($g['table'] ?? '') ?: ($g['table'] ?? '');
            $column = $g['column'] ?? '';
            $alias = $aliases[$table] ?? $table;
            $parts[] = "{$alias}.{$column}";
        }

        return implode(', ', $parts);
    }

    /**
     * Build HAVING clause with parameterized values.
     * @param array $having [{aggregate: string, table: string, column: string, op: string, value: mixed}]
     * @param array $aliases
     * @param int $paramOffset Starting param index to avoid conflicts with WHERE params
     * @return array [havingString, params]
     */
    private static function buildHaving($having, $aliases, $paramOffset = 0)
    {
        if (empty($having)) {
            return ['', []];
        }

        $conditions = [];
        $params = [];
        $paramIndex = $paramOffset;

        foreach ($having as $h) {
            $aggregate = strtoupper($h['aggregate'] ?? 'COUNT');
            $table = FolioSchemaService::fuzzyMatch($h['table'] ?? '') ?: ($h['table'] ?? '');
            $column = $h['column'] ?? '*';
            $op = strtoupper($h['op'] ?? '=');
            $value = $h['value'] ?? null;
            $alias = $aliases[$table] ?? $table;

            if (!in_array($aggregate, self::VALID_AGGREGATES)) {
                throw new \InvalidArgumentException("Invalid aggregate in HAVING: $aggregate");
            }
            if (!in_array($op, ['=', '!=', '>', '<', '>=', '<='])) {
                throw new \InvalidArgumentException("Invalid operator in HAVING: $op");
            }

            $expr = "{$aggregate}({$alias}.{$column})";
            $paramName = ':h' . $paramIndex++;
            $params[$paramName] = $value;
            $conditions[] = "{$expr} {$op} {$paramName}";
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Build ORDER BY clause.
     */
    private static function buildOrderBy($orderBy, $aliases)
    {
        if (empty($orderBy)) {
            return '';
        }

        $parts = [];
        foreach ($orderBy as $o) {
            $table = FolioSchemaService::fuzzyMatch($o['table'] ?? '') ?: ($o['table'] ?? '');
            $column = $o['column'] ?? '';
            $dir = strtoupper($o['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
            $alias = $aliases[$table] ?? $table;
            $parts[] = "{$alias}.{$column} {$dir}";
        }

        return implode(', ', $parts);
    }

    /**
     * Safety check: reject SQL containing DDL/DML keywords.
     * @param string $sql
     * @throws \InvalidArgumentException
     */
    public static function validateSafety($sql)
    {
        $trimmed = trim((string)$sql);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('SQL cannot be empty.');
        }

        // Single statement only: allow optional trailing semicolon, reject inner semicolons.
        $normalized = rtrim($trimmed, " \t\r\n;");
        if (self::hasSemicolonOutsideLiteralsOrComments($normalized)) {
            throw new \InvalidArgumentException(
                'Only a single SELECT statement is allowed.'
            );
        }

        $upper = strtoupper($normalized);

        if (!preg_match('/^\s*SELECT\b/', $upper) && !preg_match('/^\s*WITH\b/', $upper)) {
            throw new \InvalidArgumentException('Only SELECT queries are allowed.');
        }

        foreach (self::BLOCKED_KEYWORDS as $keyword) {
            // Check for keyword as a whole word
            if (preg_match('/\b' . $keyword . '\b/', $upper)) {
                throw new \InvalidArgumentException(
                    "Query contains blocked keyword: $keyword. Only SELECT queries are allowed."
                );
            }
        }
    }

    /**
     * Enforce blocked schema/table policy for SQL execution.
     * @param string $sql
     * @throws \InvalidArgumentException
     */
    public static function validateTablePolicy($sql)
    {
        $blockedTables = array_map('strtolower', FolioSchemaService::EXCLUDED_TABLES);
        $blockedSchemas = array_map('strtolower', FolioSchemaService::EXCLUDED_SCHEMAS);

        // Extract schema-qualified table references from FROM/JOIN clauses.
        preg_match_all('/(?:FROM|JOIN)\s+([\w-]+(?:\.[\w-]+)?)/i', (string)$sql, $matches);

        foreach (($matches[1] ?? []) as $ref) {
            $tableRef = strtolower(trim($ref));
            if ($tableRef === '' || $tableRef === 'select' || $tableRef === 'lateral' || $tableRef === 'unnest') {
                continue;
            }

            if (in_array($tableRef, $blockedTables, true)) {
                throw new \InvalidArgumentException("Query references blocked table: {$tableRef}");
            }

            if (strpos($tableRef, '.') !== false) {
                list($schemaName, $tableName) = explode('.', $tableRef, 2);

                if ($schemaName === 'users' && $tableName !== 'groups__t') {
                    throw new \InvalidArgumentException("Query references blocked schema table: {$tableRef}");
                }

                if ($schemaName === 'perms') {
                    throw new \InvalidArgumentException("Query references blocked schema table: {$tableRef}");
                }

                if (in_array($schemaName, $blockedSchemas, true)) {
                    throw new \InvalidArgumentException("Query references blocked schema: {$schemaName}");
                }
            }
        }
    }

    /**
     * Detect semicolons that are not inside strings/comments.
     */
    private static function hasSemicolonOutsideLiteralsOrComments($sql)
    {
        $len = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if ($inSingle) {
                if ($ch === "'" && $next === "'") {
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $inSingle = false;
                }
                continue;
            }

            if ($inDouble) {
                if ($ch === '"') {
                    $inDouble = false;
                }
                continue;
            }

            if ($ch === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }

            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                continue;
            }

            if ($ch === '"') {
                $inDouble = true;
                continue;
            }

            if ($ch === ';') {
                return true;
            }
        }

        return false;
    }
}
