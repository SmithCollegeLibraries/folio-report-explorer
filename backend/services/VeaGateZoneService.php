<?php

namespace app\services;

/** Chooses and persists the Vea zones that represent library entrances. */
class VeaGateZoneService
{
    private $client;

    public function __construct(?VeaClientService $client = null)
    {
        $this->client = $client ?: new VeaClientService();
    }

    public function status(): array
    {
        $settings = SettingsService::load();
        $selected = $settings['vea_gate_zone_ids'] ?? [];
        if (!is_array($selected)) {
            $selected = [];
        }

        return [
            'configured' => $this->client->isConfigured(),
            'selectedZoneIds' => array_values(array_filter($selected, 'is_string')),
        ];
    }

    public function test(): array
    {
        return ['ok' => true, 'zoneCount' => count($this->client->getZones())];
    }

    public function zones(): array
    {
        $zones = [];
        foreach ($this->client->getZones() as $zone) {
            $countType = $zone['countType'] ?? null;
            $disabled = isset($zone['disabled']) ? (bool)$zone['disabled'] : false;
            $zones[] = [
                'zoneId' => isset($zone['zoneId']) && is_string($zone['zoneId']) ? $zone['zoneId'] : '',
                'name' => isset($zone['name']) && is_string($zone['name']) ? $zone['name'] : '',
                'countType' => $countType,
                'countApplication' => $zone['countApplication'] ?? null,
                'disabled' => $disabled,
                'selectable' => $countType === 1 && !$disabled,
            ];
        }

        usort($zones, function ($left, $right) {
            return strcasecmp($left['name'], $right['name']);
        });
        return $zones;
    }

    public function save($payload): array
    {
        if (!is_object($payload) || !property_exists($payload, 'zoneIds')
            || !is_array($payload->zoneIds) || !$this->isList($payload->zoneIds)) {
            return ['error' => 'invalid_input'];
        }

        $zoneIds = [];
        $seen = [];
        foreach ($payload->zoneIds as $zoneId) {
            if (!is_string($zoneId) || trim($zoneId) === '') {
                return ['error' => 'invalid_input'];
            }
            if (!isset($seen[$zoneId])) {
                $zoneIds[] = $zoneId;
                $seen[$zoneId] = true;
            }
        }

        if ($zoneIds) {
            $zonesById = [];
            foreach ($this->zones() as $zone) {
                $zonesById[$zone['zoneId']] = $zone;
            }
            foreach ($zoneIds as $zoneId) {
                if (!isset($zonesById[$zoneId])) {
                    return ['error' => 'unknown_zone'];
                }
            }
            foreach ($zoneIds as $zoneId) {
                if (!$zonesById[$zoneId]['selectable']) {
                    return ['error' => 'not_selectable'];
                }
            }
        }

        try {
            SettingsService::save(['vea_gate_zone_ids' => $zoneIds]);
        } catch (\Throwable $error) {
            return ['error' => 'save_failed'];
        }

        return ['selectedZoneIds' => $zoneIds];
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
