<?php

namespace app\services;

/** Sends and validates one synthetic-safe Vea traffic request. */
class VeaTrafficRequest
{
    private const TIME_ZONE = 'America/New_York';
    private $client;

    public function __construct(?VeaClientService $client = null)
    {
        $this->client = $client ?: new VeaClientService();
    }

    public function fetch(array $zoneIds, \DateTimeInterface $start, \DateTimeInterface $end, string $grouping, bool $validateBuckets = false): array
    {
        if (!in_array($grouping, ['day', 'month', 'hour'], true)) {
            throw new \RuntimeException('vea_error');
        }
        $selected = array_fill_keys($zoneIds, true);
        if (!$selected) {
            throw new \RuntimeException('vea_error');
        }

        $timezone = new \DateTimeZone(self::TIME_ZONE);
        $startLocal = (new \DateTimeImmutable($start->format('Y-m-d H:i:s.uP')))->setTimezone($timezone);
        $endLocal = (new \DateTimeImmutable($end->format('Y-m-d H:i:s.uP')))->setTimezone($timezone);
        $response = $this->client->getWithQuery('/data/traffic', [
            'relativeDate' => 'custom',
            'startDate' => $startLocal->format('Y-m-d\TH:i:sP'),
            'endDate' => $endLocal->format('Y-m-d\TH:i:sP'),
            'entityType' => 'zone',
            'entityIds' => implode(',', $zoneIds),
            'metrics' => 'ins',
            'excludeClosedHours' => 'false',
            'dateGroupings' => $grouping,
        ], 60);

        if (!$response instanceof \stdClass || !property_exists($response, 'results')
            || !is_array($response->results) || !$this->isList($response->results)) {
            throw new \RuntimeException('vea_error');
        }

        $total = 0;
        $names = [];
        $rows = [];
        $seen = [];
        $bucketProperty = 'recordDate_' . $grouping . '_1';
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

            $bucketTime = null;
            if ($validateBuckets) {
                if (!property_exists($row, $bucketProperty) || !is_string($row->$bucketProperty)) {
                    throw new \RuntimeException('vea_error');
                }
                $bucketTime = $this->parseUtcTimestamp($row->$bucketProperty);
                $instant = $bucketTime->format('U.u');
                $duplicateKey = $row->zoneId . '|' . $instant;
                if (isset($seen[$duplicateKey])) {
                    throw new \RuntimeException('vea_error');
                }
                $seen[$duplicateKey] = true;
            }

            $name = isset($row->name) && is_string($row->name) && trim($row->name) !== ''
                ? $row->name : null;
            if ($name !== null && !isset($names[$row->zoneId])) {
                $names[$row->zoneId] = $name;
            }
            $rows[] = [
                'zoneId' => $row->zoneId,
                'sumins' => $count,
                'name' => $name,
                'bucketTime' => $bucketTime,
            ];
        }

        return ['total' => $total, 'names' => $names, 'rows' => $rows];
    }

    public function doorNames(array $zoneIds, array $names): array
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

    private function parseCount($value): int
    {
        if (is_int($value)) {
            if ($value < 0) throw new \RuntimeException('vea_error');
            return $value;
        }
        if (!is_string($value) || preg_match('/^\d+$/D', $value) !== 1) {
            throw new \RuntimeException('vea_error');
        }
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $max = (string)PHP_INT_MAX;
        if (strlen($normalized) > strlen($max)
            || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) > 0)) {
            throw new \RuntimeException('vea_error');
        }
        return (int)$normalized;
    }

    private function parseUtcTimestamp(string $value): \DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?Z$/D', $value, $matches) !== 1
            || !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])
            || (int)$matches[4] > 23 || (int)$matches[5] > 59 || (int)$matches[6] > 59) {
            throw new \RuntimeException('vea_error');
        }
        $timestamp = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        if (\DateTimeImmutable::getLastErrors() !== false) {
            throw new \RuntimeException('vea_error');
        }
        return $timestamp;
    }

    private function isList(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $item) {
            if ($key !== $index) return false;
            $index++;
        }
        return true;
    }
}
