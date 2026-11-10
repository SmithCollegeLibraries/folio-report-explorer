<?php

namespace app\services;

/** Builds Eastern-time gate-count series from the selected Vea zones. */
class GateCountVisualizationService
{
    private const TIME_ZONE = 'America/New_York';
    private $client;
    private $zoneIds;
    private $clock;
    private $gateZoneService;
    private $traffic;

    public function __construct(?VeaClientService $client = null, ?array $zoneIds = null, ?callable $clock = null, ?VeaGateZoneService $gateZoneService = null)
    {
        $this->client = $client ?: new VeaClientService();
        $this->zoneIds = $zoneIds;
        $this->clock = $clock ?: function () { return new \DateTimeImmutable('now', new \DateTimeZone(self::TIME_ZONE)); };
        $this->gateZoneService = $gateZoneService;
        $this->traffic = new VeaTrafficRequest($this->client);
    }

    public function get(array $params): array
    {
        $timezone = new \DateTimeZone(self::TIME_ZONE);
        $today = $this->today($timezone);
        $years = $this->years($params);
        list($start, $end) = $this->period($params, $today, $timezone);
        $zoneIds = $this->selectedZoneIds();
        if (!$zoneIds) throw new \RuntimeException('no_doors');
        if (!$this->client->isConfigured()) throw new \RuntimeException('not_configured');

        $currentFiscalYearEnd = (int)$today->format('Y') + ((int)$today->format('n') >= 7 ? 1 : 0);
        $firstFiscalYearEnd = $currentFiscalYearEnd - $years + 1;
        $monthStart = new \DateTimeImmutable(sprintf('%04d-07-01 00:00:00', $firstFiscalYearEnd - 1), $timezone);
        $currentMonth = $today->modify('first day of this month');
        $monthEnd = $currentMonth->modify('+1 month');
        $monthly = $this->traffic->fetch($zoneIds, $monthStart, $monthEnd, 'month', true);

        $hourEnd = $end->modify('+1 day');
        $hourly = $this->traffic->fetch($zoneIds, $start, $hourEnd, 'hour', true);
        $names = $monthly['names'] + $hourly['names'];

        $monthlyTotals = [];
        $firstMonth = $monthStart->format('Y-m');
        $lastMonth = $currentMonth->format('Y-m');
        foreach ($monthly['rows'] as $row) {
            $localMonth = $row['bucketTime']->setTimezone($timezone)->format('Y-m');
            if ($localMonth < $firstMonth || $localMonth > $lastMonth) {
                throw new \RuntimeException('vea_error');
            }
            $this->addCount($monthlyTotals, $localMonth, $row['sumins']);
        }

        $dailyTotals = [];
        $heatmapTotals = [];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $heatmapTotals[$weekday . '-' . $hour] = 0;
            }
        }
        foreach ($hourly['rows'] as $row) {
            $local = $row['bucketTime']->setTimezone($timezone);
            $date = $local->format('Y-m-d');
            if ($date < $start->format('Y-m-d') || $date > $end->format('Y-m-d')) {
                throw new \RuntimeException('vea_error');
            }
            $this->addCount($dailyTotals, $date, $row['sumins']);
            $this->addCount($heatmapTotals, (int)$local->format('w') . '-' . (int)$local->format('G'), $row['sumins']);
        }

        $daily = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $daily[] = ['date' => $date, 'visits' => $dailyTotals[$date] ?? 0];
        }
        $heatmap = [];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $heatmap[] = [
                    'weekday' => $weekday,
                    'hour' => $hour,
                    'visits' => $heatmapTotals[$weekday . '-' . $hour],
                ];
            }
        }

        $topDays = array_values(array_filter($daily, function ($row) { return $row['visits'] > 0; }));
        usort($topDays, function ($left, $right) {
            if ($left['visits'] !== $right['visits']) return $right['visits'] <=> $left['visits'];
            return strcmp($left['date'], $right['date']);
        });
        $topDays = array_slice($topDays, 0, 10);

        return [
            'doors' => $this->traffic->doorNames($zoneIds, $names),
            'period' => ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')],
            'monthlyByFiscalYear' => $this->monthlySeries($monthlyTotals, $firstFiscalYearEnd, $currentFiscalYearEnd, $lastMonth),
            'daily' => $daily,
            'heatmap' => $heatmap,
            'topDays' => $topDays,
        ];
    }

    private function today(\DateTimeZone $timezone): \DateTimeImmutable
    {
        $now = call_user_func($this->clock);
        if (!$now instanceof \DateTimeInterface) {
            throw new \UnexpectedValueException('The gate-count clock must return a date-time value.');
        }
        return (new \DateTimeImmutable($now->format('Y-m-d H:i:s.uP')))->setTimezone($timezone)->setTime(0, 0, 0);
    }

    private function years(array $params): int
    {
        if (!array_key_exists('years', $params)) return 3;
        $value = $params['years'];
        if (!(is_int($value) || (is_string($value) && preg_match('/^\d+$/D', $value) === 1))) {
            throw new \InvalidArgumentException('Choose a valid gate-count date range and year count.');
        }
        $years = (int)$value;
        if ($years < 1 || $years > 5) {
            throw new \InvalidArgumentException('Choose a valid gate-count date range and year count.');
        }
        return $years;
    }

    private function period(array $params, \DateTimeImmutable $today, \DateTimeZone $timezone): array
    {
        $startValue = array_key_exists('start', $params) ? $params['start'] : null;
        $endValue = array_key_exists('end', $params) ? $params['end'] : null;
        $startBlank = $startValue === null || (is_string($startValue) && trim($startValue) === '');
        $endBlank = $endValue === null || (is_string($endValue) && trim($endValue) === '');
        if ($startBlank && $endBlank) {
            $fiscalStartYear = (int)$today->format('Y') - ((int)$today->format('n') < 7 ? 1 : 0);
            $currentStart = new \DateTimeImmutable(sprintf('%04d-07-01 00:00:00', $fiscalStartYear), $timezone);
            $yesterday = $today->modify('-1 day');
            $elapsedDays = $yesterday < $currentStart ? 0 : $currentStart->diff($yesterday)->days + 1;
            if ($elapsedDays < 7) {
                return [$currentStart->modify('-1 year'), $currentStart->modify('-1 day')];
            }
            return [$currentStart, $yesterday];
        }
        if ($startBlank || $endBlank) {
            throw new \InvalidArgumentException('Choose a valid gate-count date range and year count.');
        }
        $start = $this->parseDate($startValue, $timezone);
        $end = $this->parseDate($endValue, $timezone);
        if ($start > $end || $end > $today || $start->diff($end)->days + 1 > 366) {
            throw new \InvalidArgumentException('Choose a valid gate-count date range and year count.');
        }
        return [$start, $end];
    }

    private function parseDate($value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new \InvalidArgumentException('Choose a valid gate-count date range and year count.');
        }
        return new \DateTimeImmutable($value . ' 00:00:00', $timezone);
    }

    private function selectedZoneIds(): array
    {
        if ($this->zoneIds === null) {
            $service = $this->gateZoneService ?: new VeaGateZoneService($this->client);
            $zoneIds = $service->selectedZoneIds();
        } else {
            $zoneIds = $this->zoneIds;
        }
        if (!is_array($zoneIds)) return [];
        $selected = [];
        foreach ($zoneIds as $zoneId) {
            if (is_string($zoneId) && trim($zoneId) !== '' && !in_array($zoneId, $selected, true)) {
                $selected[] = $zoneId;
            }
        }
        return $selected;
    }

    private function monthlySeries(array $totals, int $firstFiscalYearEnd, int $currentFiscalYearEnd, string $lastMonth): array
    {
        $series = [];
        for ($fiscalYearEnd = $firstFiscalYearEnd; $fiscalYearEnd <= $currentFiscalYearEnd; $fiscalYearEnd++) {
            $months = [];
            $firstMonth = new \DateTimeImmutable(sprintf('%04d-07-01 00:00:00', $fiscalYearEnd - 1), new \DateTimeZone(self::TIME_ZONE));
            for ($index = 0; $index < 12; $index++) {
                $month = $firstMonth->modify('+' . $index . ' months')->format('Y-m');
                if ($month > $lastMonth) break;
                $months[] = ['month' => $month, 'visits' => $totals[$month] ?? 0];
            }
            $series[] = ['fiscalYearEnd' => $fiscalYearEnd, 'months' => $months];
        }
        return $series;
    }

    private function addCount(array &$totals, string $key, int $count): void
    {
        $current = $totals[$key] ?? 0;
        if ($count > PHP_INT_MAX - $current) throw new \RuntimeException('vea_error');
        $totals[$key] = $current + $count;
    }
}
