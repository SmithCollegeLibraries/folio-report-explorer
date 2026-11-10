<?php

namespace app\services;

use Yii;

/** Chooses and persists the Vea zones that represent library entrances. */
class VeaGateZoneService
{
    private $client;
    private $db;

    public function __construct(?VeaClientService $client = null, $db = null)
    {
        $this->client = $client ?: new VeaClientService();
        $this->db = $db;
    }

    /** @return string[] */
    public function selectedZoneIds(): array
    {
        try {
            return $this->db()->createCommand(
                'SELECT zone_id FROM vea_gate_zones ORDER BY sort_order'
            )->queryColumn();
        } catch (\Throwable $error) {
            throw new \RuntimeException('vea_error');
        }
    }

    public function status(): array
    {
        return [
            'configured' => $this->client->isConfigured(),
            'selectedZoneIds' => $this->selectedZoneIds(),
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

        $transaction = null;
        try {
            $db = $this->db();
            $transaction = $db->beginTransaction();
            $db->createCommand('DELETE FROM vea_gate_zones')->execute();
            foreach ($zoneIds as $sortOrder => $zoneId) {
                $db->createCommand(
                    'INSERT INTO vea_gate_zones (zone_id, sort_order) VALUES (:zone_id, :sort_order)',
                    [':zone_id' => $zoneId, ':sort_order' => $sortOrder]
                )->execute();
            }
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction !== null && $transaction->isActive) {
                try {
                    $transaction->rollBack();
                } catch (\Throwable $rollbackError) {
                }
            }
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

    private function db()
    {
        return $this->db !== null ? $this->db : Yii::$app->db;
    }
}
