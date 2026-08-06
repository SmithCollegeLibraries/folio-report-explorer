<?php

namespace app\services;

final class CatalogingMarcLocationScopeService
{
    public const MAX_LOCATION_SELECTIONS = 100;

    private const LOCATION_FRAGMENTS = [
        'effective_item' => "FROM inventory.item__t item\nJOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id\nJOIN inventory.instance__t instance ON instance.id = holdings.instance_id\nJOIN inventory.location__t location ON location.id = item.effective_location_id",
        'permanent_item' => "FROM inventory.item__t item\nJOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id\nJOIN inventory.instance__t instance ON instance.id = holdings.instance_id\nJOIN inventory.location__t location ON location.id = item.permanent_location_id",
        'permanent_holdings' => "FROM inventory.holdings_record__t holdings\nJOIN inventory.instance__t instance ON instance.id = holdings.instance_id\nJOIN inventory.location__t location ON location.id = holdings.permanent_location_id",
    ];

    public static function resolve(array $inputs, $folioDb, array $allowedBases): array
    {
        return self::resolveLocations(self::validate($inputs, $allowedBases), $folioDb);
    }

    public static function validate(array $inputs, array $allowedBases): array
    {
        $rawIds = $inputs['locationIds'] ?? null;
        if (!is_string($rawIds) || trim($rawIds) === '') {
            throw new \InvalidArgumentException('At least one location is required.');
        }

        $locationIds = array_map('trim', explode(',', $rawIds));
        if (count($locationIds) > self::MAX_LOCATION_SELECTIONS) {
            throw new \InvalidArgumentException('No more than 100 locations may be selected.');
        }
        foreach ($locationIds as &$locationId) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $locationId) !== 1) {
                throw new \InvalidArgumentException('Every selected location must be a valid UUID.');
            }
            $locationId = strtolower($locationId);
        }
        unset($locationId);
        if (count(array_unique($locationIds)) !== count($locationIds)) {
            throw new \InvalidArgumentException('Selected locations must be unique.');
        }

        $basis = $inputs['locationBasis'] ?? null;
        if (!is_string($basis)
            || !in_array($basis, $allowedBases, true)
            || !array_key_exists($basis, self::LOCATION_FRAGMENTS)) {
            throw new \InvalidArgumentException('A supported location basis is required.');
        }

        return [
            'locationIds' => $locationIds,
            'locationBasis' => $basis,
            'locationFragment' => self::LOCATION_FRAGMENTS[$basis],
        ];
    }

    public static function resolveLocations(array $scope, $folioDb): array
    {
        $locationIds = $scope['locationIds'];

        $lookupParams = [];
        $placeholders = [];
        foreach ($locationIds as $index => $id) {
            $marker = ':location_lookup_' . $index;
            $placeholders[] = $marker;
            $lookupParams[$marker] = $id;
        }
        $rows = $folioDb->createCommand(
            'SELECT id::text AS id, name, code FROM inventory.location__t'
                . ' WHERE id IN (' . implode(', ', $placeholders) . ')'
                . ' ORDER BY name, code, id',
            $lookupParams
        )->queryAll();
        $byId = [];
        foreach ($rows as $row) {
            $byId[strtolower((string) $row['id'])] = $row;
        }
        $locations = [];
        foreach ($locationIds as $id) {
            if (!isset($byId[$id])) {
                throw new \InvalidArgumentException('A selected location no longer exists.');
            }
            $locations[] = $byId[$id];
        }

        $location = count($locations) === 1
            ? ['id' => $locationIds[0], 'name' => $locations[0]['name'] ?? null, 'code' => $locations[0]['code'] ?? null]
            : ['id' => implode(',', $locationIds), 'name' => count($locations) . ' Locations', 'code' => 'MULTI'];

        return [
            'locationIds' => $locationIds,
            'locationBasis' => $scope['locationBasis'],
            'locationFragment' => $scope['locationFragment'],
            'location' => $location,
            'locations' => $locations,
        ];
    }
}
