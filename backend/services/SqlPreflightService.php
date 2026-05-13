<?php

namespace app\services;

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
     * @return array|null ['rows' => int|null, 'cost' => float|null] or ['error' => string]
     */
    public static function estimateQueryComplexity($db, string $sql, int $queryTimeoutMs, int $preflightTimeoutMs = 10000)
    {
        try {
            $db->createCommand('SET statement_timeout = ' . (int) $preflightTimeoutMs)->execute();
            try {
                $row = $db->createCommand('EXPLAIN (FORMAT JSON) ' . $sql)->queryOne();
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
            if (stripos($msg, 'statement timeout') !== false || stripos($msg, 'canceling statement') !== false) {
                return null;
            }
            if (preg_match('/ERROR:\s*(.+?)(?:\n|HINT:|DETAIL:|$)/s', $msg, $matches) === 1) {
                return ['error' => trim((string) ($matches[1] ?? ''))];
            }
            return ['error' => $msg];
        }
    }
}