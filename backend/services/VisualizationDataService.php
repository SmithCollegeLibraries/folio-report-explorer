<?php

namespace app\services;

/** Reads and combines unit-scoped purchase, material and budget figures. */
final class VisualizationDataService
{
    private const YEARS = [5, 10, 15, 20];
    private const ELECTRONIC = [
        'Data File', 'Database', 'E-Book', 'E-Book Package', 'E-Journal', 'E-Journal Package',
        'E-Newspaper', 'Streaming Video', 'E-Score', 'Streaming Audio', 'E-Thesis/Dissertation',
    ];
    private const PHYSICAL = [
        'Audio CD', 'Book', 'DVD/Blu-ray', 'Journal', 'Newspaper', 'Score', 'Serial', 'Map', 'Microform',
        'Analog Game', 'Archival material', 'Artifact/Object', 'Audiocassette', 'CD-ROM', 'Equipment', 'Film',
        'Government Publication', 'Image', 'LP Phonorecord', 'Musical Instrument', 'Supplement',
        'Thesis/Dissertation', 'Videocassette', 'Video Game',
    ];
    private const COLLECTION_SUPPORT_FUNDS = ['SCECS', 'SCPCS', 'SCILL'];
    private $folioDb;
    private $historyDb;
    private $cache;
    private $validator;
    private $today;

    public function __construct($folioDb, $historyDb, $cache, $validator = null, ?string $today = null)
    {
        $this->folioDb = $folioDb;
        $this->historyDb = $historyDb;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->today = $today ?: date('Y-m-d');
    }

    public function options(): array
    {
        $sql = 'SELECT id, TRIM(name) AS "code", TRIM(name) AS "name" FROM orders.acquisitions_unit__t'
            . ' WHERE NOT is_deleted AND :activeOnly IS TRUE ORDER BY TRIM(name)';
        $rows = $this->readFolioQueries([[$sql, [':activeOnly' => true]]]);
        $units = [];
        foreach ($rows[0] as $row) $units[] = ['id' => (string)$row['id'], 'code' => trim((string)$row['code']), 'name' => trim((string)$row['name'])];
        return ['units' => $units, 'years' => self::YEARS];
    }

    public function get(string $acqUnitId, int $years = 10, $materialFiscalYearEnd = null): array
    {
        $acqUnitId = trim($acqUnitId);
        if ($acqUnitId === '') throw new \InvalidArgumentException('Choose an acquisitions unit.');
        if (!in_array($years, self::YEARS, true)) throw new \InvalidArgumentException('Choose 5, 10, 15 or 20 years.');
        if ($materialFiscalYearEnd !== null && $materialFiscalYearEnd !== 'all'
            && !(is_int($materialFiscalYearEnd) || (is_string($materialFiscalYearEnd) && preg_match('/^\d+$/', $materialFiscalYearEnd)))) {
            throw new \InvalidArgumentException('Choose a valid material fiscal year.');
        }
        if (is_string($materialFiscalYearEnd) && $materialFiscalYearEnd !== 'all') $materialFiscalYearEnd = (int)$materialFiscalYearEnd;
        $version = (new VisualizationHistoryService($this->historyDb, $this->cache, []))->version();
        $key = self::cacheKey($acqUnitId, $years, $materialFiscalYearEnd, $version);
        $cached = $this->cache->get($key);
        if (is_array($cached)) return $cached;

        $raw = $this->readFolioQueries([[$this->contextSql(), [':unitId' => $acqUnitId]]]);
        if (empty($raw[0])) throw new \InvalidArgumentException('Unknown acquisitions unit.');
        $context = $this->contextFromRows($raw[0]);
        $lastYear = $context['currentFiscalYearEnd'];
        $firstYear = $lastYear - $years + 1;
        $baselineYear = $firstYear - 1;
        $queries = [
            [$this->purchasesSql(), [':unitId' => $acqUnitId, ':firstYear' => $firstYear, ':lastYear' => $lastYear]],
            [$this->materialsSql(), [':unitId' => $acqUnitId]],
            [$this->budgetSql(), [':unitId' => $acqUnitId, ':firstYear' => $baselineYear, ':lastYear' => $lastYear]],
        ];
        $raw = array_merge($raw, $this->readFolioQueries($queries));
        $history = (new VisualizationHistoryService($this->historyDb, $this->cache, []))->list($context['code']);
        $materialRows = [];
        $materialYears = [];
        foreach ($raw[2] as $row) {
            $year = (int)$row['fiscalYearEnd'];
            $materialRows[] = ['fiscalYearEnd' => $year, 'materialType' => trim((string)$row['materialType']), 'amount' => (float)$row['amount']];
            $materialYears[$year] = $year;
        }
        $materialYears = array_values($materialYears);
        sort($materialYears, SORT_NUMERIC);
        $materialYear = $this->selectedMaterialYear($materialFiscalYearEnd, $context['currentFiscalYearEnd'], $materialYears);
        $result = [
            'unit' => ['id' => $context['id'], 'code' => $context['code'], 'name' => $context['name']],
            'currentFiscalYearEnd' => $context['currentFiscalYearEnd'], 'fiscalYearEnds' => range($firstYear, $lastYear),
            'materialFiscalYearEnds' => $materialYears,
            'purchasesByCategory' => $this->mergePurchases($raw[1], $history, $firstYear, $lastYear),
            'purchasesByMaterial' => ['fiscalYearEnd' => $materialYear, 'items' => $this->materialItems($materialRows, $materialYear)],
            'budget' => $this->mergeBudget($raw[3], $history, $baselineYear, $firstYear, $lastYear),
        ];
        $this->cache->set($key, $result, 3600);
        return $result;
    }

    public static function categoryForMaterial(?string $materialType): string
    {
        $materialType = strtolower(trim((string)$materialType));
        foreach (self::ELECTRONIC as $type) if ($materialType === strtolower($type)) return 'Electronic';
        foreach (self::PHYSICAL as $type) if ($materialType === strtolower($type)) return 'Physical';
        return 'Other';
    }

    public static function categoryPercentages(float $electronic, float $physical, float $other): array
    {
        $amounts = [$electronic, $physical, $other];
        $total = array_sum($amounts);
        if ($total <= 0) return ['electronicPct' => null, 'physicalPct' => null, 'otherPct' => null];
        $tenths = $remainders = [];
        foreach ($amounts as $index => $amount) {
            $exact = ($amount / $total) * 1000;
            $tenths[$index] = (int)floor($exact + 0.000000001);
            $remainders[$index] = $exact - $tenths[$index];
        }
        $remaining = 1000 - array_sum($tenths);
        while ($remaining > 0) {
            $best = 0;
            for ($index = 1; $index < 3; $index++) if ($remainders[$index] > $remainders[$best]) $best = $index;
            $tenths[$best]++;
            $remainders[$best] = -1;
            $remaining--;
        }
        return ['electronicPct' => round($tenths[0] / 10, 1), 'physicalPct' => round($tenths[1] / 10, 1), 'otherPct' => round($tenths[2] / 10, 1)];
    }

    public static function cacheKey(string $acqUnitId, int $years, $materialFiscalYearEnd, int $historyVersion): string
    {
        return 'visualizations:rpt-029-v2:' . hash('sha256', serialize([$acqUnitId, $years, $materialFiscalYearEnd, $historyVersion]));
    }

    private function contextSql(): string
    {
        return <<<'SQL'
SELECT au.id AS "id", TRIM(au.name) AS "code", TRIM(au.name) AS "name", fy.id AS "fiscalYearId",
       fy.period_start::date AS "periodStart", fy.period_end::date AS "periodEnd", EXTRACT(YEAR FROM fy.period_end::date)::int AS "fiscalYearEnd"
FROM orders.acquisitions_unit__t au LEFT JOIN finance.fiscal_year__t fy
  ON fy.series = TRIM(au.name) || 'FY' AND fy.period_end IS NOT NULL
WHERE au.id = :unitId AND NOT au.is_deleted ORDER BY fy.period_end
SQL;
    }

    private function paymentCtes(bool $withRange): string
    {
        $range = $withRange ? ' AND EXTRACT(YEAR FROM fy.period_end::date)::int BETWEEN :firstYear AND :lastYear' : '';
        return "WITH selected_unit AS (SELECT id, TRIM(name) AS unit_code FROM orders.acquisitions_unit__t WHERE id = :unitId AND NOT is_deleted),\n"
            . "selected_years AS (SELECT fy.id, EXTRACT(YEAR FROM fy.period_end::date)::int AS fiscal_year_end FROM finance.fiscal_year__t fy JOIN selected_unit au ON fy.series = au.unit_code || 'FY' WHERE fy.period_end IS NOT NULL{$range}),\n"
            . "unit_funds AS (SELECT DISTINCT fau.id AS fund_id, f.code AS fund_code FROM finance.fund__t__acq_unit_ids fau JOIN finance.fund__t f ON f.id = fau.id JOIN selected_unit au ON au.id = fau.acq_unit_ids),\n"
            . "paid_lines AS (SELECT sy.fiscal_year_end, COALESCE(CASE WHEN LOWER(COALESCE(fd.fund_distributions__distribution_type, '')) = 'amount' THEN fd.fund_distributions__value ELSE COALESCE(fd.total, 0) * COALESCE(fd.fund_distributions__value, 0) / 100.0 END, 0) AS amount, COALESCE(ermt.name, pmt.name, 'No material type') AS material_type, COALESCE(uf.fund_code, '') AS fund_code\n"
            . "FROM invoice.invoice_lines__t__fund_distributions fd JOIN invoice.invoices__t inv ON inv.id = fd.invoice_id JOIN selected_years sy ON sy.id = inv.fiscal_year_id JOIN unit_funds uf ON uf.fund_id = fd.fund_distributions__fund_id\n"
            . "LEFT JOIN orders.po_line__t pol ON pol.id = fd.po_line_id LEFT JOIN inventory.material_type__t ermt ON ermt.id = pol.eresource__material_type LEFT JOIN inventory.material_type__t pmt ON pmt.id = pol.physical__material_type WHERE fd.invoice_line_status = 'Paid')\n";
    }

    private function purchasesSql(): string
    {
        $electronic = "'" . implode("', '", array_map('strtolower', self::ELECTRONIC)) . "'";
        $physical = "'" . implode("', '", array_map('strtolower', self::PHYSICAL)) . "'";
        $supportFunds = "'" . implode("', '", self::COLLECTION_SUPPORT_FUNDS) . "'";
        return $this->paymentCtes(true)
            . "SELECT fiscal_year_end AS \"fiscalYearEnd\", SUM(amount) AS total,\n"
            . "SUM(CASE WHEN UPPER(fund_code) NOT IN ({$supportFunds}) AND LOWER(material_type) IN ({$electronic}) THEN amount ELSE 0 END) AS electronic,\n"
            . "SUM(CASE WHEN UPPER(fund_code) NOT IN ({$supportFunds}) AND LOWER(material_type) IN ({$physical}) THEN amount ELSE 0 END) AS physical,\n"
            . "SUM(CASE WHEN UPPER(fund_code) IN ({$supportFunds}) OR LOWER(material_type) NOT IN ({$electronic}, {$physical}) THEN amount ELSE 0 END) AS other\n"
            . 'FROM paid_lines GROUP BY fiscal_year_end ORDER BY fiscal_year_end';
    }

    private function materialsSql(): string
    {
        return $this->paymentCtes(false) . "SELECT fiscal_year_end AS \"fiscalYearEnd\", material_type AS \"materialType\", SUM(amount) AS amount\n"
            . 'FROM paid_lines GROUP BY fiscal_year_end, material_type ORDER BY fiscal_year_end, amount DESC, material_type';
    }

    private function budgetSql(): string
    {
        return <<<'SQL'
WITH selected_unit AS (SELECT id, TRIM(name) AS unit_code FROM orders.acquisitions_unit__t WHERE id = :unitId AND NOT is_deleted),
selected_years AS (SELECT fy.id, EXTRACT(YEAR FROM fy.period_end::date)::int AS fiscal_year_end FROM finance.fiscal_year__t fy
 JOIN selected_unit au ON fy.series = au.unit_code || 'FY' WHERE fy.period_end IS NOT NULL
 AND EXTRACT(YEAR FROM fy.period_end::date)::int BETWEEN :firstYear AND :lastYear),
unit_funds AS (SELECT DISTINCT fau.id AS fund_id FROM finance.fund__t__acq_unit_ids fau JOIN finance.fund__t f ON f.id = fau.id
 JOIN selected_unit au ON au.id = fau.acq_unit_ids)
SELECT sy.fiscal_year_end AS "fiscalYearEnd", ROUND(SUM(COALESCE(b.allocated, 0)), 2) AS allocated FROM finance.budget__t b
JOIN unit_funds uf ON uf.fund_id = b.fund_id JOIN selected_years sy ON sy.id = b.fiscal_year_id
GROUP BY sy.fiscal_year_end ORDER BY sy.fiscal_year_end
SQL;
    }

    private function readFolioQueries(array $queries): array
    {
        $transaction = $this->folioDb->beginTransaction();
        try {
            $this->folioDb->createCommand('SET TRANSACTION READ ONLY')->execute();
            $this->folioDb->createCommand("SET LOCAL statement_timeout = '30s'")->execute();
            $results = [];
            foreach ($queries as $query) {
                list($sql, $params) = $query;
                if ($this->validator !== null) {
                    $this->validator->validateSafety($sql);
                    $this->validator->validateTablePolicy($sql);
                } else {
                    SqlBuilderService::validateSafety($sql);
                    SqlBuilderService::validateTablePolicy($sql);
                }
                $results[] = $this->folioDb->createCommand($sql, $params)->queryAll();
            }
            $transaction->commit();
            return $results;
        } catch (\Throwable $error) {
            if ($transaction->getIsActive()) $transaction->rollBack();
            throw $error;
        }
    }

    private function contextFromRows(array $rows): array
    {
        $context = $latest = null;
        foreach ($rows as $row) {
            if (!isset($row['id']) || !isset($row['code'])) continue;
            if ($context === null) $context = ['id' => (string)$row['id'], 'code' => trim((string)$row['code']), 'name' => trim((string)$row['name'])];
            if (empty($row['fiscalYearEnd'])) continue;
            $year = (int)$row['fiscalYearEnd'];
            $period = ['year' => $year, 'start' => substr((string)$row['periodStart'], 0, 10), 'end' => substr((string)$row['periodEnd'], 0, 10)];
            if ($latest === null || $year > $latest['year']) $latest = $period;
            if ($period['start'] !== '' && $period['end'] !== '' && $this->today >= $period['start'] && $this->today <= $period['end']) {
                $context['currentFiscalYearEnd'] = $year;
                break;
            }
        }
        if ($context === null) throw new \InvalidArgumentException('Unknown acquisitions unit.');
        if (!isset($context['currentFiscalYearEnd'])) $context['currentFiscalYearEnd'] = $latest !== null ? $latest['year'] : ((int)substr($this->today, 5, 2) >= 7 ? (int)substr($this->today, 0, 4) + 1 : (int)substr($this->today, 0, 4));
        return $context;
    }

    private function selectedMaterialYear($selection, int $currentYear, array $materialYears)
    {
        if ($selection !== null && $selection !== 'all' && !in_array((int)$selection, $materialYears, true) && (int)$selection !== $currentYear) {
            throw new \InvalidArgumentException('Choose a fiscal year with FOLIO purchase data or all years.');
        }
        if ($selection === 'all') return empty($materialYears) ? null : 'all';
        if ($selection !== null) return (int)$selection;
        return empty($materialYears) ? null : $currentYear;
    }

    private function materialItems(array $rows, $year): array
    {
        if ($year === null) return [];
        $items = [];
        foreach ($rows as $row) {
            if ($year !== 'all' && $row['fiscalYearEnd'] !== $year) continue;
            $type = $row['materialType'] === '' ? 'No material type' : $row['materialType'];
            if (!isset($items[$type])) $items[$type] = 0;
            $items[$type] += $row['amount'];
        }
        $result = [];
        foreach ($items as $type => $amount) $result[] = ['materialType' => $type, 'amount' => round($amount, 2)];
        usort($result, function ($a, $b) { return $a['amount'] === $b['amount'] ? strcmp($a['materialType'], $b['materialType']) : ($a['amount'] > $b['amount'] ? -1 : 1); });
        return $result;
    }

    private function mergePurchases(array $folioRows, array $history, int $firstYear, int $lastYear): array
    {
        $points = [];
        foreach ($folioRows as $row) {
            $year = (int)$row['fiscalYearEnd'];
            $electronic = (float)$row['electronic']; $physical = (float)$row['physical']; $other = (float)$row['other'];
            $points[$year] = ['fiscalYearEnd' => $year, 'electronic' => round($electronic, 2), 'physical' => round($physical, 2),
                'other' => round($other, 2), 'total' => round((float)$row['total'], 2), 'source' => 'folio', '_shares' => [$electronic, $physical, $other]];
        }
        foreach ($history as $row) {
            $year = (int)$row['fiscalYearEnd'];
            if ($row['kind'] !== 'purchases' || $year < $firstYear || $year > $lastYear) continue;
            $e = round((float)$row['electronicAmount'], 2);
            $p = round((float)$row['physicalAmount'], 2);
            $total = round((float)$row['totalAmount'], 2);
            $other = $total - $e - $p;
            $points[$year] = ['fiscalYearEnd' => $year, 'electronic' => $e, 'physical' => $p, 'other' => round($other, 2), 'total' => $total, 'source' => 'history', '_shares' => [$e, $p, $other]];
        }
        ksort($points, SORT_NUMERIC);
        foreach ($points as &$point) { $point = array_merge($point, self::categoryPercentages($point['_shares'][0], $point['_shares'][1], $point['_shares'][2])); unset($point['_shares']); }
        unset($point);
        return array_values($points);
    }

    private function mergeBudget(array $folioRows, array $history, int $baselineYear, int $firstYear, int $lastYear): array
    {
        $amounts = $sources = [];
        foreach ($folioRows as $row) { $year = (int)$row['fiscalYearEnd']; $amounts[$year] = round((float)$row['allocated'], 2); $sources[$year] = 'folio'; }
        foreach ($history as $row) {
            $year = (int)$row['fiscalYearEnd'];
            if ($row['kind'] !== 'budget' || $year < $baselineYear || $year > $lastYear) continue;
            $amounts[$year] = round((float)$row['totalAmount'], 2);
            $sources[$year] = 'history';
        }
        $result = [];
        for ($year = $firstYear; $year <= $lastYear; $year++) {
            if (!array_key_exists($year, $amounts)) continue;
            $previous = $year - 1;
            $change = isset($amounts[$previous]) && $amounts[$previous] != 0.0 ? round((($amounts[$year] - $amounts[$previous]) / $amounts[$previous]) * 100, 1) : null;
            $result[] = ['fiscalYearEnd' => $year, 'allocated' => $amounts[$year], 'changePct' => $change, 'source' => $sources[$year]];
        }
        return $result;
    }
}
