<?php

namespace app\services;

use app\exceptions\DatabaseQueryCancelledException;

require_once __DIR__ . '/../exceptions/DatabaseQueryCancelledException.php';

class SqlPreflightService
{
    /**
     * Estimate query complexity using EXPLAIN (FORMAT JSON).
     * Returns null when estimation is unavailable.
     *
     * @param object $db Yii DB connection-like object with createCommand().
     * @param string $sql
     * @param int $queryTimeoutMs
     * @param int $preflightTimeoutMs
     * @param array $params
     * @return array|null ['rows' => int|null, 'cost' => float|null] or ['error' => string]
     */
    public static function estimateQueryComplexity($db, string $sql, int $queryTimeoutMs, int $preflightTimeoutMs = 10000, array $params = [])
    {
        try {
            $db->createCommand('SET statement_timeout = ' . (int) $preflightTimeoutMs)->execute();
            try {
                $row = $db->createCommand('EXPLAIN (FORMAT JSON) ' . $sql, $params)->queryOne();
            } finally {
                $db->createCommand('SET statement_timeout = ' . (int) $queryTimeoutMs)->execute();
            }

            if ($row === false || empty($row)) {
                return null;
            }

            $first = array_values($row)[0] ?? null;
            if ($first === null) {
                return null;
            }

            if (is_string($first)) {
                $decoded = json_decode($first, true);
            } elseif (is_array($first)) {
                $decoded = $first;
            } else {
                return null;
            }

            if (!is_array($decoded) || empty($decoded[0]['Plan'])) {
                return null;
            }

            $stack = [$decoded[0]['Plan']];
            $maxCost = 0.0;
            $topRows = null;
            $firstNode = true;
            while (!empty($stack)) {
                $node = array_pop($stack);
                if ($firstNode) {
                    $topRows = isset($node['Plan Rows']) ? (int) $node['Plan Rows'] : null;
                    $firstNode = false;
                }
                if (isset($node['Total Cost'])) {
                    $maxCost = max($maxCost, (float) $node['Total Cost']);
                }
                foreach (['Plans', 'InitPlans'] as $key) {
                    if (!empty($node[$key]) && is_array($node[$key])) {
                        foreach ($node[$key] as $child) {
                            $stack[] = $child;
                        }
                    }
                }
            }

            return [
                'rows' => $topRows,
                'cost' => $maxCost > 0 ? $maxCost : null,
            ];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (preg_match('/SQLSTATE\[57014\]|statement timeout|cancel(?:ing|ling)? statement|query (?:canceled|cancelled)/i', $msg) === 1) {
                throw new DatabaseQueryCancelledException($e);
            }
            if (preg_match('/ERROR:\s*(.+?)(?:\n|HINT:|DETAIL:|$)/s', $msg, $matches) === 1) {
                return ['error' => trim((string) ($matches[1] ?? ''))];
            }
            return ['error' => $msg];
        }
    }
}
