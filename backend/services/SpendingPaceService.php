<?php

namespace app\services;

/** Builds the current and previous July-to-June paid-spending lines for one unit. */
final class SpendingPaceService
{
    private $folioQueries;
    private $historyDb;
    private $cache;
    private $today;

    public function __construct($folioDb, $historyDb, $cache, $validator = null, ?string $today = null)
    {
        $this->folioQueries = new VisualizationFolioQueryService($folioDb, $validator);
        $this->historyDb = $historyDb;
        $this->cache = $cache;
        $this->today = $today ?: (new \DateTimeImmutable('now', new \DateTimeZone('America/New_York')))->format('Y-m-d');
    }

    public function get(string $acqUnitId): array
    {
        $acqUnitId = trim($acqUnitId);
        if ($acqUnitId === '') throw new \InvalidArgumentException('Choose a valid acquisitions unit id.');
        $historyService = new VisualizationHistoryService($this->historyDb, $this->cache, []);
        $key = self::cacheKey($acqUnitId, $this->today, $historyService->version());
        $cached = $this->cache->get($key);
        if (is_array($cached)) return $cached;

        $rows = $this->folioQueries->read([[
            VisualizationFolioQueryService::contextSql(), [':unitId' => $acqUnitId],
        ]])[0];
        if (empty($rows)) throw new \InvalidArgumentException('Unknown acquisitions unit.');
        $unit = ['id' => (string)$rows[0]['id'], 'code' => trim((string)$rows[0]['code']), 'name' => trim((string)$rows[0]['name'])];
        $history = $historyService->list($unit['code']);
        $seriesYears = $this->seriesYears($rows);
        $anchorYear = $this->anchorYear($this->today);

        if (!$this->hasSeries($rows)) {
            return $this->cacheUnavailable($key, $this->unavailable('no_series', $unit, $anchorYear, $history, []));
        }

        $currentYear = $this->currentYear($rows, $this->today);
        if ($currentYear === null) {
            $allocation = $this->allocation($history, $anchorYear);
            if ($allocation === null && isset($seriesYears[$anchorYear][0])) {
                $allocation = $this->folioAllocation($acqUnitId, $anchorYear, $seriesYears[$anchorYear][0]);
            }
            return $this->cacheUnavailable($key, $this->unavailable('no_current_fy', $unit, $anchorYear, $history, $allocation));
        }

        $currentEnd = $currentYear['year'];
        $previousYear = isset($seriesYears[$currentEnd - 1]) ? $currentEnd - 1 : null;
        $startYear = $previousYear === null ? $currentEnd - 1 : $currentEnd - 2;
        $paymentRows = $this->folioQueries->read([[
            $this->paidByMonthSql(), [
                ':unitId' => $acqUnitId,
                ':startDate' => sprintf('%04d-07-01', $startYear),
                ':asOf' => $this->today,
            ],
        ]])[0];
        if (empty($paymentRows) || (int)$paymentRows[0]['fundCount'] < 1) {
            return $this->cacheUnavailable($key, $this->unavailable('no_funds', $unit, $anchorYear, $history, null));
        }

        $encumbrance = $this->folioQueries->read([[
            $this->encumbrancesSql(), [':unitId' => $acqUnitId, ':fiscalYearId' => $currentYear['id']],
        ]])[0];
        $allocation = $this->allocation($history, $currentEnd);
        if ($allocation === null) $allocation = $this->folioAllocation($acqUnitId, $currentEnd, $currentYear['id']);
        $result = [
            'available' => true, 'reason' => null, 'unit' => $unit,
            'currentFiscalYearEnd' => $currentEnd, 'previousFiscalYearEnd' => $previousYear,
            'asOf' => $this->today,
            'months' => $this->months($paymentRows, $currentEnd, $previousYear),
            'openEncumbrances' => round((float)$encumbrance[0]['amount'], 2),
            'allocated' => $allocation ?: ['amount' => null, 'source' => null, 'fiscalYearEnd' => $currentEnd],
        ];
        $this->cache->set($key, $result, 3600);
        return $result;
    }

    public static function cacheKey(string $acqUnitId, string $today, int $historyVersion): string
    {
        return 'visualizations:spending-pace:v1:' . hash('sha256', serialize([$acqUnitId, $today, $historyVersion]));
    }

    private function paidByMonthSql(): string
    {
        $amount = VisualizationFolioQueryService::paymentAmountExpression('fd');
        return "WITH selected_unit AS (SELECT id, TRIM(name) AS unit_code FROM orders.acquisitions_unit__t WHERE id = :unitId AND NOT is_deleted),\n"
            . VisualizationFolioQueryService::unitFundsCte() . ",\n"
            . "fund_count AS (SELECT COUNT(*) AS fund_count FROM unit_funds),\n"
            . "paid_lines AS (SELECT {$amount} AS amount, (inv.payment_date AT TIME ZONE 'America/New_York')::date AS paid_date\n"
            . "FROM invoice.invoice_lines__t__fund_distributions fd JOIN invoice.invoices__t inv ON inv.id = fd.invoice_id\n"
            . "JOIN unit_funds uf ON uf.fund_id = fd.fund_distributions__fund_id\n"
            . "WHERE fd.invoice_line_status = 'Paid' AND inv.payment_date IS NOT NULL\n"
            . "AND (inv.payment_date AT TIME ZONE 'America/New_York')::date BETWEEN CAST(:startDate AS date) AND CAST(:asOf AS date)),\n"
            . "monthly AS (SELECT CASE WHEN EXTRACT(MONTH FROM paid_date)::int >= 7 THEN EXTRACT(YEAR FROM paid_date)::int + 1 ELSE EXTRACT(YEAR FROM paid_date)::int END AS fiscal_year_end,\n"
            . "CASE WHEN EXTRACT(MONTH FROM paid_date)::int >= 7 THEN EXTRACT(MONTH FROM paid_date)::int - 6 ELSE EXTRACT(MONTH FROM paid_date)::int + 6 END AS fiscal_month,\n"
            . "SUM(amount) AS amount FROM paid_lines GROUP BY fiscal_year_end, fiscal_month)\n"
            . "SELECT fund_count.fund_count AS \"fundCount\", monthly.fiscal_year_end AS \"fiscalYearEnd\",\n"
            . "monthly.fiscal_month AS \"fiscalMonth\", monthly.amount AS amount FROM fund_count LEFT JOIN monthly ON TRUE\n"
            . 'ORDER BY monthly.fiscal_year_end, monthly.fiscal_month';
    }

    private function encumbrancesSql(): string
    {
        return <<<'SQL'
WITH selected_year AS (SELECT id FROM finance.fiscal_year__t WHERE id = :fiscalYearId),
selected_unit AS (SELECT id, TRIM(name) AS unit_code FROM orders.acquisitions_unit__t WHERE id = :unitId AND NOT is_deleted),
unit_funds AS (SELECT DISTINCT fau.id AS fund_id FROM finance.fund__t__acq_unit_ids fau
 JOIN finance.fund__t f ON f.id = fau.id JOIN selected_unit au ON au.id = fau.acq_unit_ids)
SELECT COALESCE(SUM(COALESCE(t.encumbrance__initial_amount_encumbered, 0)
 - COALESCE(t.encumbrance__amount_expended, 0) - COALESCE(t.encumbrance__amount_awaiting_payment, 0)), 0) AS amount
FROM finance.transaction__t t JOIN selected_year sy ON sy.id = t.fiscal_year_id
JOIN unit_funds uf ON uf.fund_id = t.from_fund_id
WHERE t.transaction_type = 'Encumbrance' AND t.encumbrance__status IN ('Unreleased', 'Active')
SQL;
    }

    private function budgetSql(): string
    {
        return "WITH selected_unit AS (SELECT id, TRIM(name) AS unit_code FROM orders.acquisitions_unit__t WHERE id = :unitId AND NOT is_deleted),\n"
            . "selected_year AS (SELECT id FROM finance.fiscal_year__t WHERE id = :fiscalYearId),\n"
            . "unit_funds AS (SELECT DISTINCT fau.id AS fund_id FROM finance.fund__t__acq_unit_ids fau\n"
            . " JOIN finance.fund__t f ON f.id = fau.id JOIN selected_unit au ON au.id = fau.acq_unit_ids)\n"
            . 'SELECT SUM(COALESCE(b.allocated, 0)) AS amount FROM finance.budget__t b' . "\n"
            . 'JOIN unit_funds uf ON uf.fund_id = b.fund_id JOIN selected_year sy ON sy.id = b.fiscal_year_id';
    }

    private function folioAllocation(string $unitId, int $year, string $fiscalYearId): ?array
    {
        $rows = $this->folioQueries->read([[
            $this->budgetSql(), [':unitId' => $unitId, ':fiscalYearId' => $fiscalYearId],
        ]])[0];
        if (empty($rows) || $rows[0]['amount'] === null) return null;
        return ['amount' => round((float)$rows[0]['amount'], 2), 'source' => 'folio', 'fiscalYearEnd' => $year];
    }

    private function months(array $rows, int $currentYear, ?int $previousYear): array
    {
        $amounts = [];
        foreach ($rows as $row) {
            if ($row['fiscalYearEnd'] === null || $row['fiscalMonth'] === null) continue;
            $amounts[(int)$row['fiscalYearEnd']][(int)$row['fiscalMonth']] = (float)$row['amount'];
        }
        $date = new \DateTimeImmutable($this->today, new \DateTimeZone('America/New_York'));
        $calendarMonth = (int)$date->format('n');
        $elapsedMonth = $calendarMonth >= 7 ? $calendarMonth - 6 : $calendarMonth + 6;
        $labels = ['Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
        $currentCumulative = 0.0;
        $previousCumulative = 0.0;
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $current = null;
            if ($month <= $elapsedMonth) {
                $currentCumulative += $amounts[$currentYear][$month] ?? 0;
                $current = round($currentCumulative, 2);
            }
            $previous = null;
            if ($previousYear !== null) {
                $previousCumulative += $amounts[$previousYear][$month] ?? 0;
                $previous = round($previousCumulative, 2);
            }
            $months[] = [
                'fiscalMonth' => $month,
                'label' => $labels[$month - 1] . ($month === $elapsedMonth ? ' (to date)' : ''),
                'currentCumulative' => $current,
                'previousCumulative' => $previous,
            ];
        }
        return $months;
    }

    private function seriesYears(array $rows): array
    {
        $years = [];
        foreach ($rows as $row) {
            if (!empty($row['fiscalYearId']) && !empty($row['utcFiscalYearEnd'])) {
                $years[(int)$row['utcFiscalYearEnd']][] = (string)$row['fiscalYearId'];
            }
        }
        return $years;
    }

    private function hasSeries(array $rows): bool
    {
        foreach ($rows as $row) if (!empty($row['fiscalYearId'])) return true;
        return false;
    }

    private function currentYear(array $rows, string $today): ?array
    {
        foreach ($rows as $row) {
            if (empty($row['fiscalYearId']) || empty($row['utcFiscalYearEnd'])
                || empty($row['utcPeriodStart']) || empty($row['utcPeriodEnd'])) continue;
            $start = substr((string)$row['utcPeriodStart'], 0, 10);
            $end = substr((string)$row['utcPeriodEnd'], 0, 10);
            if ($start <= $today && $today <= $end) return ['year' => (int)$row['utcFiscalYearEnd'], 'id' => (string)$row['fiscalYearId']];
        }
        return null;
    }

    private function anchorYear(string $today): int
    {
        $date = new \DateTimeImmutable($today, new \DateTimeZone('America/New_York'));
        return (int)$date->format('Y') + ((int)$date->format('n') >= 7 ? 1 : 0);
    }

    private function allocation(array $history, int $year): ?array
    {
        foreach ($history as $row) {
            if ($row['kind'] === 'budget' && (int)$row['fiscalYearEnd'] === $year) {
                return ['amount' => round((float)$row['totalAmount'], 2), 'source' => 'history', 'fiscalYearEnd' => $year];
            }
        }
        return null;
    }

    private function unavailable(string $reason, array $unit, int $year, array $history, ?array $allocation): array
    {
        return [
            'available' => false, 'reason' => $reason, 'unit' => $unit,
            'asOf' => $this->today, 'currentFiscalYearEnd' => null, 'previousFiscalYearEnd' => null,
            'months' => [], 'openEncumbrances' => null,
            'allocated' => $allocation ?: $this->allocation($history, $year)
                ?: ['amount' => null, 'source' => null, 'fiscalYearEnd' => null],
        ];
    }

    private function cacheUnavailable(string $key, array $result): array
    {
        $this->cache->set($key, $result, 3600);
        return $result;
    }
}
