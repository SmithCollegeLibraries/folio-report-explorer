<?php

namespace app\services;

use app\models\QueryJob;
use Yii;

/**
 * IndexRecommendationService
 *
 * Builds a compact workload snapshot from query history and provides helpers
 * to validate/deduplicate model-generated index recommendations.
 */
class IndexRecommendationService
{
    const DEFAULT_LOOKBACK_DAYS = 30;
    const DEFAULT_MAX_LOGS = 300;
    const DEFAULT_MAX_PATTERNS = 25;
    const MAX_SQL_SAMPLE_CHARS = 1200;
    const DEFAULT_MAX_HEURISTIC_RECOMMENDATIONS = 8;

    /**
     * Build workload context from completed query history.
     *
     * @param int $days
     * @param int $maxLogs
     * @param int $maxPatterns
     * @return array
     */
    public static function buildWorkloadSnapshot($days = self::DEFAULT_LOOKBACK_DAYS, $maxLogs = self::DEFAULT_MAX_LOGS, $maxPatterns = self::DEFAULT_MAX_PATTERNS)
    {
        $days = max(1, min(180, (int)$days));
        $maxLogs = max(50, min(2000, (int)$maxLogs));
        $maxPatterns = max(5, min(100, (int)$maxPatterns));

        $cutoffTs = time() - ($days * 86400);
        $cutoff = gmdate('Y-m-d H:i:s', $cutoffTs);

        $rows = QueryJob::find()
            ->select([
                'id',
                'sql_text',
                'source',
                'execution_time_ms',
                'row_count',
                'completed_at',
            ])
            ->where(['status' => 'completed'])
            ->andWhere(['data_source' => 'folio'])
            ->andWhere(['>=', 'completed_at', $cutoff])
            ->andWhere(['>', 'execution_time_ms', 0])
            ->andWhere(['not', ['sql_text' => null]])
            ->orderBy(['completed_at' => SORT_DESC])
            ->limit($maxLogs)
            ->asArray()
            ->all();

        $patterns = [];
        $eligibleLogCount = 0;

        foreach ($rows as $row) {
            $sql = trim((string)($row['sql_text'] ?? ''));
            if ($sql === '' || !self::isSqlEligible($sql)) {
                continue;
            }

            $eligibleLogCount++;
            $normalized = self::normalizeSql($sql);
            $hash = hash('sha256', $normalized);

            if (!isset($patterns[$hash])) {
                $patterns[$hash] = [
                    'sqlHash' => $hash,
                    'sampleSql' => self::truncateSql($sql),
                    'source' => (string)($row['source'] ?? 'unknown'),
                    'executions' => 0,
                    'totalExecutionMs' => 0,
                    'maxExecutionMs' => 0,
                    'rowCountSum' => 0,
                    'rowCountSamples' => 0,
                    'tables' => [],
                    'sampleJobIds' => [],
                    'lastSeenAt' => (string)($row['completed_at'] ?? ''),
                ];
            }

            $executionMs = (int)($row['execution_time_ms'] ?? 0);
            $patterns[$hash]['executions']++;
            $patterns[$hash]['totalExecutionMs'] += $executionMs;
            $patterns[$hash]['maxExecutionMs'] = max($patterns[$hash]['maxExecutionMs'], $executionMs);
            $patterns[$hash]['lastSeenAt'] = max(
                (string)$patterns[$hash]['lastSeenAt'],
                (string)($row['completed_at'] ?? '')
            );

            if ($row['row_count'] !== null) {
                $patterns[$hash]['rowCountSum'] += (int)$row['row_count'];
                $patterns[$hash]['rowCountSamples']++;
            }

            if (count($patterns[$hash]['sampleJobIds']) < 8) {
                $patterns[$hash]['sampleJobIds'][] = (string)$row['id'];
            }

            $tables = self::extractTablesFromSql($sql);
            if (!empty($tables)) {
                $patterns[$hash]['tables'] = array_values(array_unique(array_merge($patterns[$hash]['tables'], $tables)));
            }
        }

        $patternList = [];
        foreach ($patterns as $pattern) {
            $executions = max(1, (int)$pattern['executions']);
            $avgExecutionMs = (int)round($pattern['totalExecutionMs'] / $executions);
            $avgRowCount = $pattern['rowCountSamples'] > 0
                ? (int)round($pattern['rowCountSum'] / $pattern['rowCountSamples'])
                : null;

            // Weight repeated slow patterns higher than one-off slow outliers.
            $score = (float)$avgExecutionMs * log($executions + 1, 2);

            $patternList[] = [
                'sqlHash' => $pattern['sqlHash'],
                'sampleSql' => $pattern['sampleSql'],
                'source' => $pattern['source'],
                'executions' => $executions,
                'avgExecutionMs' => $avgExecutionMs,
                'maxExecutionMs' => (int)$pattern['maxExecutionMs'],
                'avgRowCount' => $avgRowCount,
                'tables' => $pattern['tables'],
                'sampleJobIds' => $pattern['sampleJobIds'],
                'lastSeenAt' => $pattern['lastSeenAt'],
                'score' => $score,
            ];
        }

        usort($patternList, function ($a, $b) {
            return ($b['score'] <=> $a['score']);
        });

        $patternList = array_slice($patternList, 0, $maxPatterns);

        $allTables = [];
        foreach ($patternList as $i => &$pattern) {
            $pattern['patternId'] = sprintf('Q%03d', $i + 1);
            unset($pattern['score']);
            $allTables = array_merge($allTables, $pattern['tables']);
        }
        unset($pattern);

        $allTables = array_values(array_unique($allTables));
        sort($allTables);

        $existingIndexesByTable = self::fetchExistingIndexesForTables($allTables);

        return [
            'generatedAt' => gmdate('c'),
            'windowDays' => $days,
            'workload' => [
                'logsAnalyzed' => count($rows),
                'eligibleLogs' => $eligibleLogCount,
                'uniqueQueryPatterns' => count($patternList),
                'tables' => $allTables,
                'queryPatterns' => $patternList,
            ],
            'existingIndexesByTable' => $existingIndexesByTable,
        ];
    }

    /**
     * Finalize model recommendations by validating shape, removing duplicates,
     * and dropping candidates that already exist.
     *
     * @param array $recommendations
     * @param array $existingIndexesByTable
     * @param array $allowedTables
     * @return array
     */
    public static function finalizeRecommendations(array $recommendations, array $existingIndexesByTable, array $allowedTables = [])
    {
        $allowedTableSet = [];
        foreach ($allowedTables as $table) {
            $allowedTableSet[strtolower((string)$table)] = true;
        }

        $existingSignatures = self::buildExistingSignatureSet($existingIndexesByTable);
        $seen = [];
        $final = [];

        foreach ($recommendations as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $table = strtolower(trim((string)($candidate['table'] ?? '')));
            if ($table === '') {
                continue;
            }
            if (!empty($allowedTableSet) && !isset($allowedTableSet[$table])) {
                continue;
            }
            if (!preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $table)) {
                continue;
            }

            $rawColumns = $candidate['columns'] ?? [];
            if (is_string($rawColumns)) {
                $rawColumns = array_map('trim', explode(',', $rawColumns));
            }
            if (!is_array($rawColumns) || empty($rawColumns)) {
                continue;
            }

            $columns = [];
            foreach ($rawColumns as $col) {
                $normalized = self::normalizeColumnName((string)$col);
                if ($normalized !== null) {
                    $columns[] = $normalized;
                }
            }
            $columns = array_values(array_unique($columns));
            if (empty($columns)) {
                continue;
            }

            $signature = self::buildSignature($table, $columns);
            if (isset($existingSignatures[$signature]) || isset($seen[$signature])) {
                continue;
            }

            $indexType = strtolower(trim((string)($candidate['indexType'] ?? 'btree')));
            if (!in_array($indexType, ['btree', 'gin', 'gist', 'hash'], true)) {
                $indexType = 'btree';
            }

            $confidence = strtolower(trim((string)($candidate['confidence'] ?? 'medium')));
            if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                $confidence = 'medium';
            }

            $createIndexSql = trim((string)($candidate['createIndexSql'] ?? ''));
            if ($createIndexSql === '') {
                $createIndexSql = self::buildCreateIndexSql($table, $columns, $indexType);
            }

            $final[] = [
                'table' => $table,
                'columns' => $columns,
                'indexType' => $indexType,
                'confidence' => $confidence,
                'reason' => trim((string)($candidate['reason'] ?? '')), 
                'evidence' => is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : null,
                'createIndexSql' => $createIndexSql,
            ];

            $seen[$signature] = true;
        }

        return $final;
    }

    /**
     * Build deterministic recommendations from observed SQL patterns.
     *
     * This path is used when model output is unavailable or empty so the
     * endpoint can still return actionable suggestions.
     *
     * @param array $workload
     * @param array $existingIndexesByTable
     * @param int $maxRecommendations
     * @return array
     */
    public static function generateHeuristicRecommendations(array $workload, array $existingIndexesByTable, $maxRecommendations = self::DEFAULT_MAX_HEURISTIC_RECOMMENDATIONS)
    {
        $maxRecommendations = max(1, min(20, (int)$maxRecommendations));
        $queryPatterns = is_array($workload['queryPatterns'] ?? null) ? $workload['queryPatterns'] : [];
        $allowedTables = is_array($workload['tables'] ?? null) ? $workload['tables'] : [];

        if (empty($queryPatterns) || empty($allowedTables)) {
            return [
                'summary' => 'No deterministic index suggestions could be derived from the available query patterns.',
                'recommendations' => [],
                'notes' => [
                    'Increase the lookback window or run additional representative reports to improve signal quality.',
                ],
            ];
        }

        $tableColumnSignals = [];
        $tablePairSignals = [];
        $patternCountWithSignals = 0;

        foreach ($queryPatterns as $pattern) {
            if (!is_array($pattern)) {
                continue;
            }

            $sampleSql = trim((string)($pattern['sampleSql'] ?? ''));
            if ($sampleSql === '') {
                continue;
            }

            $signalsByTable = self::extractIndexSignalsFromSql($sampleSql);
            if (empty($signalsByTable)) {
                continue;
            }

            $patternCountWithSignals++;
            $patternId = trim((string)($pattern['patternId'] ?? ''));
            if ($patternId === '') {
                $patternId = 'unknown';
            }
            $weight = self::calculatePatternWeight($pattern);

            foreach ($signalsByTable as $table => $signals) {
                if (!preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', (string)$table)) {
                    continue;
                }

                self::accumulateColumnSignals(
                    $tableColumnSignals,
                    $table,
                    $signals['join'] ?? [],
                    'join',
                    $weight * 1.25,
                    $patternId
                );
                self::accumulateColumnSignals(
                    $tableColumnSignals,
                    $table,
                    $signals['eq'] ?? [],
                    'eq',
                    $weight * 1.00,
                    $patternId
                );
                self::accumulateColumnSignals(
                    $tableColumnSignals,
                    $table,
                    $signals['range'] ?? [],
                    'range',
                    $weight * 0.70,
                    $patternId
                );
                self::accumulateColumnSignals(
                    $tableColumnSignals,
                    $table,
                    $signals['order'] ?? [],
                    'order',
                    $weight * 0.45,
                    $patternId
                );

                $compositeColumns = self::pickCompositeColumns($signals);
                if (count($compositeColumns) >= 2) {
                    $pairKey = implode(',', $compositeColumns);
                    if (!isset($tablePairSignals[$table])) {
                        $tablePairSignals[$table] = [];
                    }
                    if (!isset($tablePairSignals[$table][$pairKey])) {
                        $tablePairSignals[$table][$pairKey] = [
                            'columns' => $compositeColumns,
                            'score' => 0.0,
                            'patternIds' => [],
                        ];
                    }

                    $tablePairSignals[$table][$pairKey]['score'] += $weight * 1.30;
                    $tablePairSignals[$table][$pairKey]['patternIds'][$patternId] = true;
                }
            }
        }

        $candidatePool = [];

        foreach ($tablePairSignals as $table => $pairs) {
            foreach ($pairs as $pairData) {
                $candidatePool[] = [
                    'table' => $table,
                    'columns' => $pairData['columns'],
                    'score' => (float)($pairData['score'] ?? 0),
                    'signalType' => 'composite',
                    'patternIds' => array_keys($pairData['patternIds'] ?? []),
                    'reason' => 'Composite index candidate from repeated JOIN/WHERE usage in recent history.',
                ];
            }
        }

        foreach ($tableColumnSignals as $table => $columns) {
            foreach ($columns as $column => $signal) {
                $candidatePool[] = [
                    'table' => $table,
                    'columns' => [$column],
                    'score' => (float)($signal['score'] ?? 0),
                    'signalType' => 'single',
                    'patternIds' => array_keys($signal['patternIds'] ?? []),
                    'reason' => self::buildSingleColumnReason($signal),
                ];
            }
        }

        if (empty($candidatePool)) {
            return [
                'summary' => 'No deterministic index suggestions could be derived from the available query patterns.',
                'recommendations' => [],
                'notes' => [
                    'Query patterns did not expose clear JOIN/WHERE column signals for index heuristics.',
                ],
            ];
        }

        usort($candidatePool, function ($a, $b) {
            return (($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        });

        $maxScore = max(0.0001, (float)($candidatePool[0]['score'] ?? 0.0001));
        $perTableBudget = [];
        $rawRecommendations = [];
        $seenSignatures = [];

        foreach ($candidatePool as $candidate) {
            $table = (string)($candidate['table'] ?? '');
            $columns = is_array($candidate['columns'] ?? null) ? $candidate['columns'] : [];
            if ($table === '' || empty($columns)) {
                continue;
            }

            $tableCount = (int)($perTableBudget[$table] ?? 0);
            if ($tableCount >= 2) {
                continue;
            }

            $signature = self::buildSignature($table, $columns);
            if (isset($seenSignatures[$signature])) {
                continue;
            }

            $confidence = self::deriveConfidence((float)($candidate['score'] ?? 0), $maxScore);
            $estimatedImpact = $confidence;

            $rawRecommendations[] = [
                'table' => $table,
                'columns' => $columns,
                'indexType' => 'btree',
                'confidence' => $confidence,
                'reason' => (string)($candidate['reason'] ?? 'Heuristic recommendation from observed query history.'),
                'evidence' => [
                    'patternIds' => array_slice(array_values(array_unique($candidate['patternIds'] ?? [])), 0, 8),
                    'estimatedImpact' => $estimatedImpact,
                ],
            ];

            $perTableBudget[$table] = $tableCount + 1;
            $seenSignatures[$signature] = true;

            if (count($rawRecommendations) >= ($maxRecommendations * 2)) {
                break;
            }
        }

        $final = self::finalizeRecommendations($rawRecommendations, $existingIndexesByTable, $allowedTables);
        $final = array_slice($final, 0, $maxRecommendations);

        if (empty($final)) {
            return [
                'summary' => 'No new index candidates survived de-duplication against existing indexes.',
                'recommendations' => [],
                'notes' => [
                    'Existing indexes may already cover dominant query patterns.',
                ],
            ];
        }

        return [
            'summary' => sprintf(
                'Generated %d deterministic index suggestion%s from %d query pattern%s with usable predicate signals.',
                count($final),
                count($final) === 1 ? '' : 's',
                $patternCountWithSignals,
                $patternCountWithSignals === 1 ? '' : 's'
            ),
            'recommendations' => $final,
            'notes' => [
                'These recommendations were generated via deterministic workload heuristics (no model output).',
                'Validate top candidates with EXPLAIN ANALYZE before applying in production.',
            ],
        ];
    }

    /**
     * Build compact index signatures from existing index metadata.
     *
     * @param array $existingIndexesByTable
     * @return array
     */
    private static function buildExistingSignatureSet(array $existingIndexesByTable)
    {
        $set = [];
        foreach ($existingIndexesByTable as $table => $indexes) {
            foreach ($indexes as $idx) {
                $cols = $idx['columns'] ?? [];
                if (!is_array($cols) || empty($cols)) {
                    continue;
                }
                $signature = self::buildSignature((string)$table, $cols);
                $set[$signature] = true;
            }
        }
        return $set;
    }

    /**
     * Build a normalized signature for table+column sequence.
     *
     * @param string $table
     * @param array $columns
     * @return string
     */
    private static function buildSignature($table, array $columns)
    {
        $normalizedCols = array_map(function ($c) {
            return strtolower(trim((string)$c));
        }, $columns);

        return strtolower(trim((string)$table)) . '|' . implode(',', $normalizedCols);
    }

    /**
     * Calculate pattern weight from frequency and latency.
     *
     * @param array $pattern
     * @return float
     */
    private static function calculatePatternWeight(array $pattern)
    {
        $executions = max(1, (int)($pattern['executions'] ?? 1));
        $avgExecutionMs = max(1.0, (float)($pattern['avgExecutionMs'] ?? 1));
        $frequencyWeight = log($executions + 1, 2);
        $latencyWeight = max(1.0, $avgExecutionMs / 120.0);
        return $frequencyWeight * $latencyWeight;
    }

    /**
     * Add weighted evidence for a set of columns.
     *
     * @param array $tableColumnSignals
     * @param string $table
     * @param array $columns
     * @param string $kind
     * @param float $weight
     * @param string $patternId
     * @return void
     */
    private static function accumulateColumnSignals(array &$tableColumnSignals, $table, array $columns, $kind, $weight, $patternId)
    {
        if (empty($columns) || $weight <= 0) {
            return;
        }

        if (!isset($tableColumnSignals[$table])) {
            $tableColumnSignals[$table] = [];
        }

        foreach ($columns as $column) {
            $column = strtolower(trim((string)$column));
            if ($column === '' || !preg_match('/^[a-z_][a-z0-9_]*$/', $column)) {
                continue;
            }

            if (!isset($tableColumnSignals[$table][$column])) {
                $tableColumnSignals[$table][$column] = [
                    'score' => 0.0,
                    'joinScore' => 0.0,
                    'eqScore' => 0.0,
                    'rangeScore' => 0.0,
                    'orderScore' => 0.0,
                    'patternIds' => [],
                ];
            }

            $tableColumnSignals[$table][$column]['score'] += $weight;
            $tableColumnSignals[$table][$column]['patternIds'][$patternId] = true;

            if ($kind === 'join') {
                $tableColumnSignals[$table][$column]['joinScore'] += $weight;
            } elseif ($kind === 'eq') {
                $tableColumnSignals[$table][$column]['eqScore'] += $weight;
            } elseif ($kind === 'range') {
                $tableColumnSignals[$table][$column]['rangeScore'] += $weight;
            } elseif ($kind === 'order') {
                $tableColumnSignals[$table][$column]['orderScore'] += $weight;
            }
        }
    }

    /**
     * Extract JOIN/WHERE/ORDER-BY column signals grouped by table.
     *
     * @param string $sql
     * @return array
     */
    private static function extractIndexSignalsFromSql($sql)
    {
        $sql = (string)$sql;
        $aliasMap = self::extractAliasMap($sql);
        if (empty($aliasMap)) {
            return [];
        }

        $byTable = [];
        $add = function ($table, $kind, $column) use (&$byTable) {
            if (!isset($byTable[$table])) {
                $byTable[$table] = [
                    'join' => [],
                    'eq' => [],
                    'range' => [],
                    'order' => [],
                ];
            }
            if (!in_array($column, $byTable[$table][$kind], true)) {
                $byTable[$table][$kind][] = $column;
            }
        };

        if (preg_match_all(
            '/([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\s*=\s*([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)/i',
            $sql,
            $joinMatches,
            PREG_SET_ORDER
        )) {
            foreach ($joinMatches as $m) {
                $leftTable = self::resolveAliasToTable($aliasMap, (string)$m[1]);
                $rightTable = self::resolveAliasToTable($aliasMap, (string)$m[3]);
                $leftColumn = self::normalizeColumnName((string)$m[2]);
                $rightColumn = self::normalizeColumnName((string)$m[4]);

                if ($leftTable !== null && $leftColumn !== null) {
                    $add($leftTable, 'join', $leftColumn);
                }
                if ($rightTable !== null && $rightColumn !== null) {
                    $add($rightTable, 'join', $rightColumn);
                }
            }
        }

        if (preg_match_all(
            '/([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\s*(=|>=|<=|>|<|!=|<>)\s*([^\s\),;]+)/i',
            $sql,
            $comparisonMatches,
            PREG_SET_ORDER
        )) {
            foreach ($comparisonMatches as $m) {
                $table = self::resolveAliasToTable($aliasMap, (string)$m[1]);
                $column = self::normalizeColumnName((string)$m[2]);
                if ($table === null || $column === null) {
                    continue;
                }

                $operator = trim((string)$m[3]);
                $rhs = trim((string)$m[4]);
                if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/i', $rhs)) {
                    continue;
                }

                if ($operator === '=') {
                    $add($table, 'eq', $column);
                } else {
                    $add($table, 'range', $column);
                }
            }
        }

        if (preg_match_all(
            '/([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\s+in\s*\(/i',
            $sql,
            $inMatches,
            PREG_SET_ORDER
        )) {
            foreach ($inMatches as $m) {
                $table = self::resolveAliasToTable($aliasMap, (string)$m[1]);
                $column = self::normalizeColumnName((string)$m[2]);
                if ($table !== null && $column !== null) {
                    $add($table, 'eq', $column);
                }
            }
        }

        if (preg_match_all(
            '/([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\s+between\b/i',
            $sql,
            $betweenMatches,
            PREG_SET_ORDER
        )) {
            foreach ($betweenMatches as $m) {
                $table = self::resolveAliasToTable($aliasMap, (string)$m[1]);
                $column = self::normalizeColumnName((string)$m[2]);
                if ($table !== null && $column !== null) {
                    $add($table, 'range', $column);
                }
            }
        }

        if (preg_match('/\border\s+by\s+(.+?)(?:\blimit\b|\boffset\b|\bfetch\b|;|$)/is', $sql, $orderMatch)) {
            $orderClause = (string)($orderMatch[1] ?? '');
            if ($orderClause !== '' && preg_match_all(
                '/([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)/i',
                $orderClause,
                $orderCols,
                PREG_SET_ORDER
            )) {
                foreach ($orderCols as $m) {
                    $table = self::resolveAliasToTable($aliasMap, (string)$m[1]);
                    $column = self::normalizeColumnName((string)$m[2]);
                    if ($table !== null && $column !== null) {
                        $add($table, 'order', $column);
                    }
                }
            }
        }

        return $byTable;
    }

    /**
     * Extract alias->table map for FROM/JOIN entries.
     *
     * @param string $sql
     * @return array
     */
    private static function extractAliasMap($sql)
    {
        $map = [];
        if (!preg_match_all(
            '/\b(?:from|join)\s+((?:"?[a-zA-Z_][a-zA-Z0-9_]*"?\.)?"?[a-zA-Z_][a-zA-Z0-9_]*"?)(?:\s+(?:as\s+)?([a-zA-Z_][a-zA-Z0-9_]*))?/i',
            (string)$sql,
            $matches,
            PREG_SET_ORDER
        )) {
            return $map;
        }

        foreach ($matches as $m) {
            $rawTable = str_replace('"', '', trim((string)($m[1] ?? '')));
            $table = strtolower($rawTable);
            if (!preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $table)) {
                continue;
            }

            $alias = strtolower(trim((string)($m[2] ?? '')));
            if ($alias !== '') {
                $map[$alias] = $table;
            }

            $parts = explode('.', $table);
            $bareTable = $parts[1] ?? '';
            if ($bareTable !== '' && !isset($map[$bareTable])) {
                $map[$bareTable] = $table;
            }
        }

        return $map;
    }

    /**
     * Resolve an alias to its schema-qualified table.
     *
     * @param array $aliasMap
     * @param string $alias
     * @return string|null
     */
    private static function resolveAliasToTable(array $aliasMap, $alias)
    {
        $alias = strtolower(trim((string)$alias));
        if ($alias === '') {
            return null;
        }
        return isset($aliasMap[$alias]) ? (string)$aliasMap[$alias] : null;
    }

    /**
     * Pick a likely composite index prefix from local SQL signals.
     *
     * @param array $signals
     * @return array
     */
    private static function pickCompositeColumns(array $signals)
    {
        $ordered = [];
        foreach (['join', 'eq', 'range'] as $kind) {
            $cols = is_array($signals[$kind] ?? null) ? $signals[$kind] : [];
            foreach ($cols as $col) {
                $normalized = self::normalizeColumnName((string)$col);
                if ($normalized === null || in_array($normalized, $ordered, true)) {
                    continue;
                }
                $ordered[] = $normalized;
                if (count($ordered) >= 2) {
                    break 2;
                }
            }
        }

        return $ordered;
    }

    /**
     * Build a reason string for a single-column recommendation.
     *
     * @param array $signal
     * @return string
     */
    private static function buildSingleColumnReason(array $signal)
    {
        $parts = [];
        if ((float)($signal['joinScore'] ?? 0) > 0) {
            $parts[] = 'frequent JOIN predicates';
        }
        if ((float)($signal['eqScore'] ?? 0) > 0) {
            $parts[] = 'repeated WHERE equality filters';
        }
        if ((float)($signal['rangeScore'] ?? 0) > 0) {
            $parts[] = 'range filters';
        }
        if ((float)($signal['orderScore'] ?? 0) > 0) {
            $parts[] = 'ORDER BY usage';
        }

        if (empty($parts)) {
            return 'Heuristic recommendation from observed query history.';
        }

        return 'Heuristic recommendation based on ' . implode(', ', $parts) . '.';
    }

    /**
     * Convert relative score into confidence buckets.
     *
     * @param float $score
     * @param float $maxScore
     * @return string
     */
    private static function deriveConfidence($score, $maxScore)
    {
        $ratio = $maxScore > 0 ? ($score / $maxScore) : 0;
        if ($ratio >= 0.75) {
            return 'high';
        }
        if ($ratio >= 0.40) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Create a sensible CREATE INDEX statement when model output omits it.
     *
     * @param string $table
     * @param array $columns
     * @param string $indexType
     * @return string
     */
    private static function buildCreateIndexSql($table, array $columns, $indexType)
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $table)) {
            return '';
        }

        foreach ($columns as $column) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', (string)$column)) {
                return '';
            }
        }

        $indexType = strtolower((string)$indexType);
        if (!in_array($indexType, ['btree', 'gin', 'gist', 'hash'], true)) {
            $indexType = 'btree';
        }

        $nameBase = str_replace('.', '_', $table) . '_' . implode('_', $columns) . '_idx';
        $indexName = self::compactIndexName($nameBase);

        $usingClause = $indexType === 'btree' ? '' : ' USING ' . $indexType;
        return 'CREATE INDEX CONCURRENTLY ' . $indexName . ' ON ' . $table . $usingClause
            . ' (' . implode(', ', $columns) . ');';
    }

    /**
     * Compact long index names to stay under PostgreSQL identifier limits.
     *
     * @param string $name
     * @return string
     */
    private static function compactIndexName($name)
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', (string)$name));
        if (strlen($name) <= 63) {
            return $name;
        }

        $hash = substr(hash('sha256', $name), 0, 10);
        $prefix = substr($name, 0, 50);
        return rtrim($prefix, '_') . '_' . $hash;
    }

    /**
     * Fetch existing PostgreSQL indexes for schema-qualified table names.
     *
     * @param array $tables
     * @return array
     */
    private static function fetchExistingIndexesForTables(array $tables)
    {
        $tables = array_values(array_filter(array_map('trim', $tables), function ($t) {
            return preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', (string)$t) === 1;
        }));

        if (empty($tables)) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($tables as $i => $table) {
            $key = ':t' . $i;
            $placeholders[] = $key;
            $params[$key] = $table;
        }

        $sql = 'SELECT schemaname, tablename, indexname, indexdef
                FROM pg_indexes
                WHERE (schemaname || \'\.\' || tablename) IN (' . implode(', ', $placeholders) . ')
                ORDER BY schemaname, tablename, indexname';

        $rows = Yii::$app->folioDb->createCommand($sql, $params)->queryAll();
        $byTable = [];

        foreach ($rows as $row) {
            $table = strtolower((string)$row['schemaname'] . '.' . (string)$row['tablename']);
            if (!isset($byTable[$table])) {
                $byTable[$table] = [];
            }

            $indexDef = (string)($row['indexdef'] ?? '');
            $byTable[$table][] = [
                'name' => (string)($row['indexname'] ?? ''),
                'definition' => $indexDef,
                'columns' => self::parseIndexColumns($indexDef),
            ];
        }

        // Ensure all requested tables exist in the map even if index list is empty.
        foreach ($tables as $table) {
            $key = strtolower($table);
            if (!isset($byTable[$key])) {
                $byTable[$key] = [];
            }
        }

        return $byTable;
    }

    /**
     * Parse index columns from a CREATE INDEX definition.
     *
     * @param string $indexDef
     * @return array
     */
    private static function parseIndexColumns($indexDef)
    {
        if (!preg_match('/\((.+)\)/', (string)$indexDef, $m)) {
            return [];
        }

        $inside = (string)$m[1];
        $parts = explode(',', $inside);
        $columns = [];

        foreach ($parts as $part) {
            $candidate = trim($part);
            $candidate = preg_replace('/\s+COLLATE\s+\S+/i', '', $candidate);
            $candidate = preg_replace('/\s+ASC$/i', '', $candidate);
            $candidate = preg_replace('/\s+DESC$/i', '', $candidate);
            $candidate = preg_replace('/\s+NULLS\s+FIRST$/i', '', $candidate);
            $candidate = preg_replace('/\s+NULLS\s+LAST$/i', '', $candidate);

            $normalized = self::normalizeColumnName((string)$candidate);
            if ($normalized !== null) {
                $columns[] = $normalized;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * Normalize a column identifier; returns null for expressions.
     *
     * @param string $name
     * @return string|null
     */
    private static function normalizeColumnName($name)
    {
        $value = trim((string)$name);
        if ($value === '') {
            return null;
        }

        // Remove optional quoting.
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        }

        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $value) !== 1) {
            return null;
        }

        return strtolower($value);
    }

    /**
     * Determine if a SQL statement is relevant for index recommendations.
     *
     * @param string $sql
     * @return bool
     */
    private static function isSqlEligible($sql)
    {
        return preg_match('/^\s*(select|with)\b/i', (string)$sql) === 1;
    }

    /**
     * Normalize SQL for pattern hashing.
     *
     * @param string $sql
     * @return string
     */
    private static function normalizeSql($sql)
    {
        $normalized = strtolower(trim((string)$sql));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return rtrim((string)$normalized, ';');
    }

    /**
     * Extract schema-qualified table names from SQL text.
     *
     * @param string $sql
     * @return array
     */
    private static function extractTablesFromSql($sql)
    {
        $matches = [];
        preg_match_all(
            '/\b(?:from|join)\s+((?:"?[a-zA-Z_][a-zA-Z0-9_]*"?\.)"?[a-zA-Z_][a-zA-Z0-9_]*"?)/i',
            (string)$sql,
            $matches
        );

        $tables = [];
        foreach ($matches[1] ?? [] as $raw) {
            $clean = str_replace('"', '', trim((string)$raw));
            $clean = strtolower($clean);
            if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $clean)) {
                $tables[] = $clean;
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables);
        return $tables;
    }

    /**
     * Trim long SQL samples for model context.
     *
     * @param string $sql
     * @return string
     */
    private static function truncateSql($sql)
    {
        $sql = trim((string)$sql);
        if (strlen($sql) <= self::MAX_SQL_SAMPLE_CHARS) {
            return $sql;
        }

        return substr($sql, 0, self::MAX_SQL_SAMPLE_CHARS) . ' ...';
    }
}
