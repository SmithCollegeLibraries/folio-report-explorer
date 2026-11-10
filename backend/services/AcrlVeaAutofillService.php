<?php

namespace app\services;

/** Builds the fixed Vea traffic suggestions for ACRL gate-count questions. */
class AcrlVeaAutofillService
{
    private const TIME_ZONE = 'America/New_York';
    private const DEFINITIONS = [
        'survey|79a|' => [
            'queryId' => 'vea-79a', 'version' => 1,
            'summary' => [
                'Counts entries (ins only) at the doors selected in Settings, named in the result.',
                'Vea counts are grouped by Eastern time.',
                'Uses the Smith fiscal year, 1 July to 30 June.',
            ],
        ],
        'survey|79c|' => [
            'queryId' => 'vea-79c', 'version' => 1,
            'summary' => [
                'Counts entries (ins only) at the doors selected in Settings, named in the result.',
                'Vea counts are grouped by Eastern time.',
                'Totals seven days for the chosen week.',
            ],
        ],
    ];

    private $client;
    private $clock;
    private $zoneIds;
    private $gateZoneService;

    public function __construct(?VeaClientService $client = null, ?callable $clock = null, ?array $zoneIds = null, ?VeaGateZoneService $gateZoneService = null)
    {
        $this->client = $client ?: new VeaClientService();
        $this->clock = $clock ?: function () { return new \DateTimeImmutable('now'); };
        $this->zoneIds = $zoneIds;
        $this->gateZoneService = $gateZoneService;
    }

    public static function validateWeekStart($weekStart): ?string
    {
        if ($weekStart === null || $weekStart === '') {
            return null;
        }
        if (!is_string($weekStart) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $weekStart) !== 1) {
            throw new \InvalidArgumentException('The selected week must fall within the fiscal year.');
        }
        list($year, $month, $day) = array_map('intval', explode('-', $weekStart));
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException('The selected week must fall within the fiscal year.');
        }
        return $weekStart;
    }

    public static function methods(): array
    {
        $methods = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            list($sheet, $questionId, $part) = explode('|', $key);
            $methods[] = [
                'sheet' => $sheet,
                'questionId' => $questionId,
                'part' => $part,
                'queryId' => $definition['queryId'],
                'queryVersion' => $definition['version'],
                'kind' => 'dated',
                'source' => 'vea',
                'summary' => $definition['summary'],
                'sql' => null,
            ];
        }
        return $methods;
    }

    public function run(int $year, string $questionId, $weekStart = null): array
    {
        $definition = self::DEFINITIONS['survey|' . $questionId . '|'] ?? null;
        if ($definition === null) {
            throw new \DomainException('No Vea auto-fill is defined for this question.');
        }
        $weekStart = $questionId === '79c' ? self::validateWeekStart($weekStart) : null;
        $timezone = new \DateTimeZone(self::TIME_ZONE);
        if ($questionId === '79c') {
            if ($weekStart === null) {
                $default = new \DateTimeImmutable(sprintf('%04d-10-15 00:00:00', $year - 1), $timezone);
                $weekStart = $default->modify('-' . (int)$default->format('w') . ' days')->format('Y-m-d');
            }
            $start = new \DateTimeImmutable($weekStart . ' 00:00:00', $timezone);
            $end = $start->modify('+7 days');
            $fyStart = new \DateTimeImmutable(sprintf('%04d-07-01 00:00:00', $year - 1), $timezone);
            $fyEnd = new \DateTimeImmutable(sprintf('%04d-07-01 00:00:00', $year), $timezone);
            if ($start < $fyStart || $end > $fyEnd) {
                throw new \InvalidArgumentException('The selected week must fall within the fiscal year.');
            }
        } else {
            $start = new \DateTimeImmutable(sprintf('%04d-07-01 00:00:00', $year - 1), $timezone);
            $end = new \DateTimeImmutable(sprintf('%04d-07-01 00:00:00', $year), $timezone);
        }

        $zoneIds = $this->selectedZoneIds();
        if (!$zoneIds) {
            throw new \RuntimeException('no_doors');
        }
        if (!$this->client->isConfigured()) {
            throw new \RuntimeException('not_configured');
        }

        $response = $this->client->getWithQuery('/data/traffic', [
            'relativeDate' => 'custom',
            'startDate' => $start->format('Y-m-d\TH:i:sP'),
            'endDate' => $end->format('Y-m-d\TH:i:sP'),
            'entityType' => 'zone',
            'entityIds' => implode(',', $zoneIds),
            'metrics' => 'ins',
            'excludeClosedHours' => 'false',
            'dateGroupings' => 'day',
        ], 60);
        list($value, $names) = $this->parseTrafficResponse($response, $zoneIds);
        $doors = $this->doorNames($zoneIds, $names);
        $ranAt = call_user_func($this->clock);
        if (!$ranAt instanceof \DateTimeInterface) {
            throw new \UnexpectedValueException('The Vea auto-fill clock must return a date-time value.');
        }

        $result = [
            'value' => $value,
            'queryId' => $definition['queryId'],
            'queryVersion' => $definition['version'],
            'ranAt' => $ranAt->format(DATE_ATOM),
            'asOf' => null,
            'fyStart' => $start->format('Y-m-d'),
            'fyEnd' => $end->format('Y-m-d'),
            'kind' => 'dated',
            'source' => 'vea',
            'doors' => $doors,
        ];
        if ($questionId === '79c') {
            $result['weekStart'] = $start->format('Y-m-d');
            $result['weekEnd'] = $end->modify('-1 day')->format('Y-m-d');
        }
        return $result;
    }

    private function selectedZoneIds(): array
    {
        if ($this->zoneIds === null) {
            $gateZoneService = $this->gateZoneService ?: new VeaGateZoneService($this->client);
            $zoneIds = $gateZoneService->selectedZoneIds();
        } else {
            $zoneIds = $this->zoneIds;
        }
        if (!is_array($zoneIds)) {
            return [];
        }

        $selected = [];
        foreach ($zoneIds as $zoneId) {
            if (is_string($zoneId) && trim($zoneId) !== '' && !in_array($zoneId, $selected, true)) {
                $selected[] = $zoneId;
            }
        }
        return $selected;
    }

    private function parseTrafficResponse($response, array $zoneIds): array
    {
        if (!$response instanceof \stdClass || !property_exists($response, 'results')
            || !is_array($response->results) || !$this->isList($response->results)) {
            throw new \RuntimeException('vea_error');
        }

        $selected = array_fill_keys($zoneIds, true);
        $total = 0;
        $names = [];
        foreach ($response->results as $row) {
            if (!$row instanceof \stdClass || !isset($row->zoneId) || !is_string($row->zoneId)
                || !isset($selected[$row->zoneId]) || !property_exists($row, 'sumins')) {
                throw new \RuntimeException('vea_error');
            }
            $count = $this->parseCount($row->sumins);
            if ($count > PHP_INT_MAX - $total) {
                throw new \RuntimeException('vea_error');
            }
            $total += $count;
            if (isset($row->name) && is_string($row->name) && trim($row->name) !== '' && !isset($names[$row->zoneId])) {
                $names[$row->zoneId] = $row->name;
            }
        }
        return [$total, $names];
    }

    private function parseCount($raw): int
    {
        if (is_int($raw)) {
            if ($raw < 0) {
                throw new \RuntimeException('vea_error');
            }
            return $raw;
        }
        if (!is_string($raw) || preg_match('/^\d+$/D', $raw) !== 1) {
            throw new \RuntimeException('vea_error');
        }

        $normalized = ltrim($raw, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string)PHP_INT_MAX;
        if (strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            throw new \RuntimeException('vea_error');
        }
        return (int)$normalized;
    }

    private function doorNames(array $zoneIds, array $names): array
    {
        if (count($names) < count($zoneIds)) {
            foreach ($this->client->getZones() as $zone) {
                $zoneId = $zone['zoneId'] ?? null;
                $name = $zone['name'] ?? null;
                if (is_string($zoneId) && in_array($zoneId, $zoneIds, true)
                    && is_string($name) && trim($name) !== '' && !isset($names[$zoneId])) {
                    $names[$zoneId] = $name;
                }
            }
        }

        $doors = [];
        foreach ($zoneIds as $zoneId) {
            $doors[] = $names[$zoneId] ?? 'Unknown door';
        }
        return $doors;
    }

    private function isList(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $item) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }
        return true;
    }
}
