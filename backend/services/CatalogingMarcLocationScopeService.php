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

    private const HOLDINGS_NO_ITEMS_TARGET = "structural_instances AS MATERIALIZED (
  SELECT holdings.instance_id
  FROM inventory.holdings_record__t holdings
  LEFT JOIN inventory.item__t item ON item.holdings_record_id = holdings.id
  GROUP BY holdings.instance_id
  HAVING COUNT(item.id) = 0
),
eligible_instances AS MATERIALIZED (
  SELECT instance.id AS instance_uuid, instance.hrid AS instance_hrid, instance.title
  FROM structural_instances structural
  JOIN inventory.instance__t instance ON instance.id = structural.instance_id
  WHERE instance.source = 'MARC'
    AND NOT COALESCE(instance.deleted, false)
    AND EXISTS (
      SELECT 1 FROM marctab.mt998 ownership
      WHERE ownership.instance_id = instance.id AND ownership.sf = 'a'
        AND UPPER(BTRIM(ownership.content)) = 'SM'
      OFFSET 0
    )
    AND NOT EXISTS (
      SELECT 1 FROM inventory.instance__t__statistical_code_ids marked
      WHERE marked.id = instance.id
        AND marked.statistical_code_ids = '4921058f-8b68-43a7-a7d6-45f58c38aab9'
      OFFSET 0
    )
),
location_rows AS MATERIALIZED (
  SELECT DISTINCT eligible.instance_uuid,
    location.name || CASE WHEN location.code IS NULL THEN '' ELSE ' [' || location.code || ']' END AS location_label
  FROM eligible_instances eligible
  JOIN inventory.holdings_record__t holdings ON holdings.instance_id = eligible.instance_uuid
  JOIN inventory.location__t location ON location.id = holdings.permanent_location_id
),
target_instances AS MATERIALIZED (
  SELECT eligible.instance_uuid, eligible.instance_hrid, eligible.title,
    STRING_AGG(location_rows.location_label, '; ' ORDER BY location_rows.location_label) AS selected_locations
  FROM eligible_instances eligible
  LEFT JOIN location_rows ON location_rows.instance_uuid = eligible.instance_uuid
  GROUP BY eligible.instance_uuid, eligible.instance_hrid, eligible.title
)";

    private const NO_HOLDINGS_TARGET = "structural_instances AS MATERIALIZED (
  SELECT instance.id AS instance_uuid, instance.hrid AS instance_hrid, instance.title,
    instance.source, instance.deleted
  FROM inventory.instance__t instance
  WHERE NOT EXISTS (
    SELECT 1 FROM inventory.holdings_record__t holdings
    WHERE holdings.instance_id = instance.id
    OFFSET 0
  )
),
eligible_instances AS MATERIALIZED (
  SELECT structural.instance_uuid, structural.instance_hrid, structural.title
  FROM structural_instances structural
  WHERE structural.source = 'MARC'
    AND NOT COALESCE(structural.deleted, false)
    AND EXISTS (
      SELECT 1 FROM marctab.mt998 ownership
      WHERE ownership.instance_id = structural.instance_uuid AND ownership.sf = 'a'
        AND UPPER(BTRIM(ownership.content)) = 'SM'
      OFFSET 0
    )
    AND NOT EXISTS (
      SELECT 1 FROM inventory.instance__t__statistical_code_ids marked
      WHERE marked.id = structural.instance_uuid
        AND marked.statistical_code_ids = '4921058f-8b68-43a7-a7d6-45f58c38aab9'
      OFFSET 0
    )
),
target_instances AS MATERIALIZED (
  SELECT eligible.instance_uuid, eligible.instance_hrid, eligible.title,
    CAST(NULL AS text) AS selected_locations
  FROM eligible_instances eligible
)";

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

    public static function validateCombination(array $inputs): array
    {
        $basis = $inputs['locationBasis'] ?? null;
        if ($basis === 'holdings_no_items' || $basis === 'no_holdings') {
            return [
                'locationIds' => [],
                'locationBasis' => $basis,
                'targetInstances' => $basis === 'holdings_no_items'
                    ? self::HOLDINGS_NO_ITEMS_TARGET
                    : self::NO_HOLDINGS_TARGET,
            ];
        }

        $scope = self::validate($inputs, ['effective_item', 'permanent_item']);
        $scope['targetInstances'] = self::itemTargetInstances($scope['locationFragment']);
        return $scope;
    }

    public static function isCombinationTargetInstances(string $target): bool
    {
        return $target === self::itemTargetInstances(self::LOCATION_FRAGMENTS['effective_item'])
            || $target === self::itemTargetInstances(self::LOCATION_FRAGMENTS['permanent_item'])
            || $target === self::HOLDINGS_NO_ITEMS_TARGET
            || $target === self::NO_HOLDINGS_TARGET;
    }

    private static function itemTargetInstances(string $locationFragment): string
    {
        return "target_instances AS MATERIALIZED (
  SELECT
    instance.id AS instance_uuid,
    instance.hrid AS instance_hrid,
    instance.title,
    STRING_AGG(DISTINCT location.name || CASE WHEN location.code IS NULL THEN '' ELSE ' [' || location.code || ']' END, '; ' ORDER BY location.name || CASE WHEN location.code IS NULL THEN '' ELSE ' [' || location.code || ']' END) AS selected_locations
  " . $locationFragment . "
  WHERE location.id = ANY(string_to_array(:locationIds, ',')::uuid[])
    AND instance.source = 'MARC'
  GROUP BY instance.id, instance.hrid, instance.title
)";
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
