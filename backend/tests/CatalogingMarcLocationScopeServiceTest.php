<?php

namespace {
    require_once __DIR__ . '/../services/CatalogingMarcLocationScopeService.php';

    use app\services\CatalogingMarcLocationScopeService;

    function scopeFail(string $message): void
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function scopeSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            scopeFail($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    function scopeContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) === false) {
            scopeFail($message . "\nMissing: {$needle}");
        }
    }

    function scopeThrows(callable $callback, string $expectedMessage, string $message): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException $exception) {
            scopeSame($expectedMessage, $exception->getMessage(), $message);
            return;
        }
        scopeFail($message . '\nNo InvalidArgumentException was thrown.');
    }

    final class LocationScopeCommand
    {
        private $sql;
        private $params;
        private $db;

        public function __construct(string $sql, array $params, LocationScopeDb $db)
        {
            $this->sql = $sql;
            $this->params = $params;
            $this->db = $db;
        }

        public function queryAll(): array
        {
            if (strpos($this->sql, 'FROM inventory.location__t') === false) {
                throw new \RuntimeException('Unexpected location scope lookup: ' . $this->sql);
            }
            $this->db->lookupSql = $this->sql;
            $this->db->lookupParams = $this->params;
            $requested = array_values($this->params);
            return array_values(array_filter($this->db->locations, function (array $location) use ($requested) {
                return in_array($location['id'], $requested, true);
            }));
        }
    }

    final class LocationScopeDb
    {
        public $locations = [];
        public $lookupSql = '';
        public $lookupParams = [];

        public function createCommand(string $sql, array $params = []): LocationScopeCommand
        {
            return new LocationScopeCommand($sql, $params, $this);
        }
    }

    $mainId = '11111111-1111-4111-8111-111111111111';
    $scienceId = '22222222-2222-4222-8222-222222222222';
    $inactiveId = '33333333-3333-4333-8333-333333333333';
    $db = new LocationScopeDb();
    $db->locations = [
        ['id' => $mainId, 'name' => 'Main Library', 'code' => 'MAIN', 'is_active' => true],
        ['id' => $scienceId, 'name' => 'Science Library', 'code' => 'SCI', 'is_active' => true],
        ['id' => $inactiveId, 'name' => 'Closed Library', 'code' => 'CLOSED', 'is_active' => false],
    ];

    $scope = CatalogingMarcLocationScopeService::resolve(
        [
            'locationIds' => $mainId . ',' . $scienceId,
            'locationBasis' => 'effective_item',
        ],
        $db,
        ['effective_item', 'permanent_item']
    );

    scopeSame([$mainId, $scienceId], $scope['locationIds'], 'UUID order must be preserved.');
    scopeSame('effective_item', $scope['locationBasis'], 'The selected basis must be returned.');
    scopeContains('item.effective_location_id', $scope['locationFragment'], 'Effective scope must use item effective location.');
    scopeSame(
        ['id' => $mainId . ',' . $scienceId, 'name' => '2 Locations', 'code' => 'MULTI'],
        $scope['location'],
        'Multiple locations must retain deterministic export metadata.'
    );
    scopeSame(
        [':location_lookup_0' => $mainId, ':location_lookup_1' => $scienceId],
        $db->lookupParams,
        'The existence lookup must bind every validated UUID separately.'
    );
    scopeContains('id IN (:location_lookup_0, :location_lookup_1)', $db->lookupSql, 'The existence lookup placeholders must be server-owned.');

    $inactiveScope = CatalogingMarcLocationScopeService::resolve(
        ['locationIds' => $inactiveId, 'locationBasis' => 'permanent_item'],
        $db,
        ['effective_item', 'permanent_item']
    );
    scopeSame($inactiveId, $inactiveScope['location']['id'], 'An inactive existing location must remain resolvable for saved URLs.');

    foreach ([
        [$mainId . ',' . $mainId, 'Selected locations must be unique.'],
        ['not-a-uuid', 'Every selected location must be a valid UUID.'],
        ['', 'At least one location is required.'],
    ] as $invalidCase) {
        scopeThrows(
            function () use ($invalidCase, $db) {
                CatalogingMarcLocationScopeService::resolve(
                    ['locationIds' => $invalidCase[0], 'locationBasis' => 'effective_item'],
                    $db,
                    ['effective_item', 'permanent_item']
                );
            },
            $invalidCase[1],
            'Invalid location selections must retain their exact messages.'
        );
    }

    $tooMany = [];
    for ($index = 1; $index <= 101; $index++) {
        $tooMany[] = sprintf('%08x-0000-4000-8000-%012x', $index, $index);
    }
    scopeThrows(
        function () use ($tooMany, $db) {
            CatalogingMarcLocationScopeService::resolve(
                ['locationIds' => implode(',', $tooMany), 'locationBasis' => 'effective_item'],
                $db,
                ['effective_item', 'permanent_item']
            );
        },
        'No more than 100 locations may be selected.',
        'The selection cap must retain its exact message.'
    );

    scopeThrows(
        function () use ($mainId, $db) {
            CatalogingMarcLocationScopeService::resolve(
                ['locationIds' => $mainId, 'locationBasis' => 'permanent_holdings'],
                $db,
                ['effective_item', 'permanent_item']
            );
        },
        'A supported location basis is required.',
        'A new report must reject permanent holdings when it is not allowlisted.'
    );
    $legacyScope = CatalogingMarcLocationScopeService::resolve(
        ['locationIds' => $mainId, 'locationBasis' => 'permanent_holdings'],
        $db,
        ['effective_item', 'permanent_item', 'permanent_holdings']
    );
    scopeContains('holdings.permanent_location_id', $legacyScope['locationFragment'], 'The old report must retain permanent holdings support.');

    fwrite(STDOUT, "Cataloging MARC location scope tests passed\n");
}
