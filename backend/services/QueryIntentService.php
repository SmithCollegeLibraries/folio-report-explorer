<?php

namespace app\services;

/**
 * QueryIntentService
 *
 * Defines the server-side QueryIntent v1 contract, validates incoming intent payloads,
 * and translates them into SqlBuilderService query definitions.
 */
class QueryIntentService
{
    const CONTRACT_VERSION = 1;

    /**
     * Validate a QueryIntent payload and return structured errors.
     *
     * QueryIntent v1 contract:
     * {
     *   intentVersion?: 1,
     *   query: {
     *     tables: string[],
     *     select: [{table, column, alias?, aggregate?}],
     *     where?: [{table, column, op, value}],
     *     joins?: "auto" | [{fromTable, fromColumn, toTable, toColumn, joinType?}],
     *     groupBy?: [{table, column}],
     *     having?: [{aggregate, table, column, op, value}],
     *     sort?: [{table, column, direction?}],
     *     distinct?: bool,
     *     limit?: int
     *   }
     * }
     *
     * @param mixed $intent
     * @return array {valid: bool, errors: array, normalizedIntent: array|null}
     */
    public static function validateIntent($intent)
    {
        $errors = [];

        if (!is_array($intent)) {
            $errors[] = self::err('intent', 'type', 'Intent must be an object.');
            return [
                'valid' => false,
                'errors' => $errors,
                'normalizedIntent' => null,
            ];
        }

        $version = $intent['intentVersion'] ?? self::CONTRACT_VERSION;
        if (!is_int($version)) {
            $errors[] = self::err('intentVersion', 'type', 'intentVersion must be an integer.');
        } elseif ($version !== self::CONTRACT_VERSION) {
            $errors[] = self::err(
                'intentVersion',
                'unsupported_version',
                'Unsupported intentVersion: ' . $version . '. Expected ' . self::CONTRACT_VERSION . '.'
            );
        }

        $query = $intent['query'] ?? null;
        if (!is_array($query)) {
            $errors[] = self::err('query', 'required', 'query object is required.');
            return [
                'valid' => false,
                'errors' => $errors,
                'normalizedIntent' => null,
            ];
        }

        $tables = self::validateStringArray($query['tables'] ?? null, 'query.tables', true, $errors);
        $select = self::validateSelect($query['select'] ?? null, 'query.select', $errors);
        $where = self::validateWhere($query['where'] ?? [], 'query.where', $errors);
        $groupBy = self::validateTableColumnList($query['groupBy'] ?? [], 'query.groupBy', false, $errors);
        $having = self::validateHaving($query['having'] ?? [], 'query.having', $errors);
        $sort = self::validateSort($query['sort'] ?? [], 'query.sort', $errors);
        $joins = self::validateJoins($query['joins'] ?? 'auto', 'query.joins', $errors);

        $distinct = $query['distinct'] ?? false;
        if (!is_bool($distinct)) {
            $errors[] = self::err('query.distinct', 'type', 'distinct must be a boolean.');
        }

        $limit = $query['limit'] ?? 100;
        if (!is_int($limit)) {
            $errors[] = self::err('query.limit', 'type', 'limit must be an integer.');
        } elseif ($limit < 1) {
            $errors[] = self::err('query.limit', 'range', 'limit must be greater than 0.');
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors,
                'normalizedIntent' => null,
            ];
        }

        return [
            'valid' => true,
            'errors' => [],
            'normalizedIntent' => [
                'intentVersion' => $version,
                'query' => [
                    'tables' => $tables,
                    'select' => $select,
                    'where' => $where,
                    'joins' => $joins,
                    'groupBy' => $groupBy,
                    'having' => $having,
                    'sort' => $sort,
                    'distinct' => $distinct,
                    'limit' => $limit,
                ],
            ],
        ];
    }

    /**
     * Translate a valid QueryIntent into a SqlBuilder query definition.
     *
     * @param mixed $intent
     * @return array
     * @throws QueryIntentValidationException
     */
    public static function toQueryDefinition($intent)
    {
        $validation = self::validateIntent($intent);
        if (!$validation['valid']) {
            throw new QueryIntentValidationException('Invalid QueryIntent payload.', $validation['errors']);
        }

        $query = $validation['normalizedIntent']['query'];

        $columns = [];
        foreach ($query['select'] as $sel) {
            $columns[] = [
                'table' => $sel['table'],
                'column' => $sel['column'],
                'alias' => $sel['alias'] ?? null,
                'aggregate' => $sel['aggregate'] ?? '',
            ];
        }

        $filters = [];
        foreach ($query['where'] as $w) {
            $filters[] = [
                'table' => $w['table'],
                'column' => $w['column'],
                'op' => $w['op'],
                'value' => $w['value'],
            ];
        }

        $orderBy = [];
        foreach ($query['sort'] as $s) {
            $orderBy[] = [
                'table' => $s['table'],
                'column' => $s['column'],
                'dir' => $s['direction'] ?? 'ASC',
            ];
        }

        $joins = 'auto';
        if (is_array($query['joins'])) {
            $joins = [];
            foreach ($query['joins'] as $j) {
                $join = [
                    'from_table' => $j['fromTable'],
                    'from_column' => $j['fromColumn'],
                    'to_table' => $j['toTable'],
                    'to_column' => $j['toColumn'],
                ];
                if (!empty($j['joinType'])) {
                    $join['join_type'] = $j['joinType'];
                }
                $joins[] = $join;
            }
        }

        return [
            'tables' => $query['tables'],
            'columns' => $columns,
            'filters' => $filters,
            'joins' => $joins,
            'orderBy' => $orderBy,
            'groupBy' => $query['groupBy'],
            'having' => $query['having'],
            'distinct' => $query['distinct'],
            'limit' => $query['limit'],
        ];
    }

    private static function validateSelect($value, $path, &$errors)
    {
        if (!is_array($value) || empty($value)) {
            $errors[] = self::err($path, 'required', 'select must be a non-empty array.');
            return [];
        }

        $normalized = [];
        foreach ($value as $i => $item) {
            $itemPath = $path . '[' . $i . ']';
            if (!is_array($item)) {
                $errors[] = self::err($itemPath, 'type', 'Each select item must be an object.');
                continue;
            }

            $table = self::validateIdentifierField($item['table'] ?? null, $itemPath . '.table', false, $errors);
            $column = self::validateIdentifierField($item['column'] ?? null, $itemPath . '.column', true, $errors);
            $alias = null;
            if (array_key_exists('alias', $item) && $item['alias'] !== null && trim((string)$item['alias']) !== '') {
                $alias = self::validateIdentifierField($item['alias'], $itemPath . '.alias', false, $errors);
            }

            $aggregate = '';
            if (array_key_exists('aggregate', $item) && $item['aggregate'] !== null && trim((string)$item['aggregate']) !== '') {
                $aggregate = strtoupper(trim((string)$item['aggregate']));
                if (!in_array($aggregate, SqlBuilderService::VALID_AGGREGATES, true)) {
                    $errors[] = self::err(
                        $itemPath . '.aggregate',
                        'invalid_value',
                        'aggregate must be one of: ' . implode(', ', SqlBuilderService::VALID_AGGREGATES)
                    );
                }
            }

            if ($table !== null && $column !== null) {
                $normalized[] = [
                    'table' => $table,
                    'column' => $column,
                    'alias' => $alias,
                    'aggregate' => $aggregate,
                ];
            }
        }

        return $normalized;
    }

    private static function validateWhere($value, $path, &$errors)
    {
        if (!is_array($value)) {
            $errors[] = self::err($path, 'type', 'where must be an array.');
            return [];
        }

        $normalized = [];
        foreach ($value as $i => $item) {
            $itemPath = $path . '[' . $i . ']';
            if (!is_array($item)) {
                $errors[] = self::err($itemPath, 'type', 'Each where item must be an object.');
                continue;
            }

            $table = self::validateIdentifierField($item['table'] ?? null, $itemPath . '.table', false, $errors);
            $column = self::validateIdentifierField($item['column'] ?? null, $itemPath . '.column', false, $errors);
            $op = strtoupper(trim((string)($item['op'] ?? '')));
            if ($op === '') {
                $errors[] = self::err($itemPath . '.op', 'required', 'op is required.');
            } elseif (!in_array($op, SqlBuilderService::VALID_OPERATORS, true)) {
                $errors[] = self::err(
                    $itemPath . '.op',
                    'invalid_value',
                    'op must be one of: ' . implode(', ', SqlBuilderService::VALID_OPERATORS)
                );
            }

            if (!array_key_exists('value', $item) && !in_array($op, ['IS NULL', 'IS NOT NULL'], true)) {
                $errors[] = self::err($itemPath . '.value', 'required', 'value is required for operator ' . $op . '.');
            }

            if ($table !== null && $column !== null && $op !== '') {
                $normalized[] = [
                    'table' => $table,
                    'column' => $column,
                    'op' => $op,
                    'value' => $item['value'] ?? null,
                ];
            }
        }

        return $normalized;
    }

    private static function validateSort($value, $path, &$errors)
    {
        if (!is_array($value)) {
            $errors[] = self::err($path, 'type', 'sort must be an array.');
            return [];
        }

        $normalized = [];
        foreach ($value as $i => $item) {
            $itemPath = $path . '[' . $i . ']';
            if (!is_array($item)) {
                $errors[] = self::err($itemPath, 'type', 'Each sort item must be an object.');
                continue;
            }

            $table = self::validateIdentifierField($item['table'] ?? null, $itemPath . '.table', false, $errors);
            $column = self::validateIdentifierField($item['column'] ?? null, $itemPath . '.column', false, $errors);
            $direction = strtoupper(trim((string)($item['direction'] ?? 'ASC')));
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                $errors[] = self::err($itemPath . '.direction', 'invalid_value', 'direction must be ASC or DESC.');
            }

            if ($table !== null && $column !== null) {
                $normalized[] = [
                    'table' => $table,
                    'column' => $column,
                    'direction' => in_array($direction, ['ASC', 'DESC'], true) ? $direction : 'ASC',
                ];
            }
        }

        return $normalized;
    }

    private static function validateHaving($value, $path, &$errors)
    {
        if (!is_array($value)) {
            $errors[] = self::err($path, 'type', 'having must be an array.');
            return [];
        }

        $normalized = [];
        foreach ($value as $i => $item) {
            $itemPath = $path . '[' . $i . ']';
            if (!is_array($item)) {
                $errors[] = self::err($itemPath, 'type', 'Each having item must be an object.');
                continue;
            }

            $aggregate = strtoupper(trim((string)($item['aggregate'] ?? '')));
            if ($aggregate === '') {
                $errors[] = self::err($itemPath . '.aggregate', 'required', 'aggregate is required.');
            } elseif (!in_array($aggregate, SqlBuilderService::VALID_AGGREGATES, true)) {
                $errors[] = self::err(
                    $itemPath . '.aggregate',
                    'invalid_value',
                    'aggregate must be one of: ' . implode(', ', SqlBuilderService::VALID_AGGREGATES)
                );
            }

            $table = self::validateIdentifierField($item['table'] ?? null, $itemPath . '.table', false, $errors);
            $column = self::validateIdentifierField($item['column'] ?? null, $itemPath . '.column', true, $errors);

            $op = strtoupper(trim((string)($item['op'] ?? '')));
            if ($op === '') {
                $errors[] = self::err($itemPath . '.op', 'required', 'op is required.');
            } elseif (!in_array($op, ['=', '!=', '>', '<', '>=', '<='], true)) {
                $errors[] = self::err($itemPath . '.op', 'invalid_value', 'op must be one of: =, !=, >, <, >=, <=');
            }

            if (!array_key_exists('value', $item)) {
                $errors[] = self::err($itemPath . '.value', 'required', 'value is required.');
            }

            if ($table !== null && $column !== null && $aggregate !== '' && $op !== '') {
                $normalized[] = [
                    'aggregate' => $aggregate,
                    'table' => $table,
                    'column' => $column,
                    'op' => $op,
                    'value' => $item['value'] ?? null,
                ];
            }
        }

        return $normalized;
    }

    private static function validateJoins($value, $path, &$errors)
    {
        if (is_string($value)) {
            if (strtolower($value) !== 'auto') {
                $errors[] = self::err($path, 'invalid_value', 'joins string must be "auto".');
                return 'auto';
            }
            return 'auto';
        }

        if (!is_array($value)) {
            $errors[] = self::err($path, 'type', 'joins must be "auto" or an array.');
            return 'auto';
        }

        $normalized = [];
        foreach ($value as $i => $item) {
            $itemPath = $path . '[' . $i . ']';
            if (!is_array($item)) {
                $errors[] = self::err($itemPath, 'type', 'Each join item must be an object.');
                continue;
            }

            $fromTable = self::validateIdentifierField($item['fromTable'] ?? null, $itemPath . '.fromTable', false, $errors);
            $fromColumn = self::validateIdentifierField($item['fromColumn'] ?? null, $itemPath . '.fromColumn', false, $errors);
            $toTable = self::validateIdentifierField($item['toTable'] ?? null, $itemPath . '.toTable', false, $errors);
            $toColumn = self::validateIdentifierField($item['toColumn'] ?? null, $itemPath . '.toColumn', false, $errors);

            $joinType = null;
            if (array_key_exists('joinType', $item) && trim((string)$item['joinType']) !== '') {
                $joinType = strtoupper(trim((string)$item['joinType']));
                if (!in_array($joinType, ['JOIN', 'LEFT JOIN'], true)) {
                    $errors[] = self::err($itemPath . '.joinType', 'invalid_value', 'joinType must be JOIN or LEFT JOIN.');
                }
            }

            if ($fromTable !== null && $fromColumn !== null && $toTable !== null && $toColumn !== null) {
                $normalizedJoin = [
                    'fromTable' => $fromTable,
                    'fromColumn' => $fromColumn,
                    'toTable' => $toTable,
                    'toColumn' => $toColumn,
                ];
                if ($joinType !== null && in_array($joinType, ['JOIN', 'LEFT JOIN'], true)) {
                    $normalizedJoin['joinType'] = $joinType;
                }
                $normalized[] = $normalizedJoin;
            }
        }

        return $normalized;
    }

    private static function validateTableColumnList($value, $path, $allowStar, &$errors)
    {
        if (!is_array($value)) {
            $errors[] = self::err($path, 'type', basename(str_replace('.', '/', $path)) . ' must be an array.');
            return [];
        }

        $normalized = [];
        foreach ($value as $i => $item) {
            $itemPath = $path . '[' . $i . ']';
            if (!is_array($item)) {
                $errors[] = self::err($itemPath, 'type', 'Each item must be an object.');
                continue;
            }

            $table = self::validateIdentifierField($item['table'] ?? null, $itemPath . '.table', false, $errors);
            $column = self::validateIdentifierField($item['column'] ?? null, $itemPath . '.column', $allowStar, $errors);

            if ($table !== null && $column !== null) {
                $normalized[] = [
                    'table' => $table,
                    'column' => $column,
                ];
            }
        }

        return $normalized;
    }

    private static function validateStringArray($value, $path, $required, &$errors)
    {
        if (!is_array($value)) {
            if ($required) {
                $errors[] = self::err($path, 'required', $path . ' must be an array.');
            }
            return [];
        }

        if ($required && empty($value)) {
            $errors[] = self::err($path, 'required', $path . ' must not be empty.');
            return [];
        }

        $normalized = [];
        foreach ($value as $i => $entry) {
            $itemPath = $path . '[' . $i . ']';
            if (!is_string($entry) || trim($entry) === '') {
                $errors[] = self::err($itemPath, 'type', 'Value must be a non-empty string.');
                continue;
            }
            $normalized[] = trim($entry);
        }

        return $normalized;
    }

    private static function validateIdentifierField($value, $path, $allowStar, &$errors)
    {
        if (!is_string($value) || trim($value) === '') {
            $errors[] = self::err($path, 'required', 'Value is required.');
            return null;
        }

        $normalized = trim($value);
        if ($allowStar && $normalized === '*') {
            return $normalized;
        }

        if (!preg_match(SqlBuilderService::VALID_IDENTIFIER_PATTERN, $normalized)) {
            $errors[] = self::err($path, 'invalid_identifier', 'Invalid identifier: ' . $normalized);
            return null;
        }

        return $normalized;
    }

    private static function err($path, $code, $message)
    {
        return [
            'path' => $path,
            'code' => $code,
            'message' => $message,
        ];
    }
}

/**
 * QueryIntentValidationException
 *
 * Carries structured validation errors for QueryIntent contract failures.
 */
class QueryIntentValidationException extends \InvalidArgumentException
{
    /** @var array */
    private $errors;

    public function __construct($message, array $errors = [], $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
