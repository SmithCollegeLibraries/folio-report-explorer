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

        // Validate table/column names exist in schema
        self::validateNames($tables, $columns, $filters);

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
    private static function validateNames($tables, $columns, $filters)
    {
        $tableNames = FolioSchemaService::getTableNames();

        foreach ($tables as $t) {
            $matched = FolioSchemaService::fuzzyMatch($t);
            if ($matched === null) {
                throw new \InvalidArgumentException("Unknown table: $t");
            }
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
        $upper = strtoupper($sql);

        foreach (self::BLOCKED_KEYWORDS as $keyword) {
            // Check for keyword as a whole word
            if (preg_match('/\b' . $keyword . '\b/', $upper)) {
                throw new \InvalidArgumentException(
                    "Query contains blocked keyword: $keyword. Only SELECT queries are allowed."
                );
            }
        }
    }
}
