<?php

namespace app\services;

require_once __DIR__ . '/VeaTrafficRequest.php';

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

        $trafficRequest = new VeaTrafficRequest($this->client);
        $traffic = $trafficRequest->fetch($zoneIds, $start, $end, 'day');
        $value = $traffic['total'];
        $doors = $trafficRequest->doorNames($zoneIds, $traffic['names']);
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

}
