<?php

namespace yii\db {
    class ActiveRecord
    {
        private $attributes = [];
        public function __get($name) { return $this->attributes[$name] ?? null; }
        public function __set($name, $value): void { $this->attributes[$name] = $value; }
        public function hasAttribute($name): bool { return true; }
    }
}

namespace {
    require_once __DIR__ . '/../models/ReportTemplate.php';
    require_once __DIR__ . '/../services/CatalogingMarcMissingTagReportService.php';

    use app\models\ReportTemplate;
    use app\services\CatalogingMarcMissingTagReportService;

    function multiLocationFail(string $message): void
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function multiLocationSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            multiLocationFail($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }

    function multiLocationContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) === false) {
            multiLocationFail($message . "\nMissing: {$needle}");
        }
    }

    function multiLocationThrows(callable $callback, string $expectedMessage, string $message): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException $exception) {
            multiLocationSame($expectedMessage, $exception->getMessage(), $message);
            return;
        }
        multiLocationFail($message . '\nNo InvalidArgumentException was thrown.');
    }

    final class MultiLocationCommand
    {
        private $sql;
        private $params;
        private $db;

        public function __construct(string $sql, array $params, MultiLocationDb $db)
        {
            $this->sql = $sql;
            $this->params = $params;
            $this->db = $db;
        }

        public function queryAll(): array
        {
            if (strpos($this->sql, 'FROM inventory.location__t') === false) {
                throw new \RuntimeException('Unexpected queryAll lookup: ' . $this->sql);
            }
            $this->db->locationLookupCount++;
            $this->db->locationLookupSql = $this->sql;
            $this->db->locationLookupParams = $this->params;
            $requested = array_values($this->params);
            return array_values(array_filter($this->db->locations, function (array $location) use ($requested) {
                return in_array($location['id'], $requested, true);
            }));
        }

        public function queryOne()
        {
            if (strpos($this->sql, 'to_regclass') !== false) {
                return ['to_regclass' => $this->db->tables[$this->params[':table_name']] ?? null];
            }
            throw new \RuntimeException('Unexpected queryOne lookup: ' . $this->sql);
        }
    }

    final class MultiLocationDb
    {
        public $locations = [];
        public $tables = ['marctab.mt856' => 'marctab.mt856'];
        public $locationLookupCount = 0;
        public $locationLookupSql = '';
        public $locationLookupParams = [];

        public function createCommand(string $sql, array $params = []): MultiLocationCommand
        {
            return new MultiLocationCommand($sql, $params, $this);
        }
    }

    function multiLocationReport(): ReportTemplate
    {
        $path = __DIR__ . '/../../mysql/migrations/042_cataloging_marc_multi_location.sql';
        $migration = is_file($path) ? (string) file_get_contents($path) : '';
        if (preg_match("/SET\n.*?  `sql_template` = '(WITH target_instances AS MATERIALIZED \\(.*?LIMIT 100001)',\n  `parameters` = '(\\[.*?\\])',\n/s", $migration, $matches) !== 1) {
            multiLocationFail('Migration 042 must store the reviewed multi-location SQL and parameter definitions.');
        }

        $report = new ReportTemplate();
        $report->slug = CatalogingMarcMissingTagReportService::REPORT_SLUG;
        $report->sql_template = str_replace("''", "'", $matches[1]);
        $report->parameters = str_replace("''", "'", $matches[2]);
        $report->default_limit = 100000;
        return $report;
    }

    $mainId = '11111111-1111-4111-8111-111111111111';
    $scienceId = '22222222-2222-4222-8222-222222222222';
    $inactiveId = '33333333-3333-4333-8333-333333333333';
    $db = new MultiLocationDb();
    $db->locations = [
        ['id' => $mainId, 'name' => 'Main Library', 'code' => 'MAIN'],
        ['id' => $scienceId, 'name' => 'Science Library', 'code' => 'SCI'],
        ['id' => $inactiveId, 'name' => 'Closed Library', 'code' => 'CLOSED', 'is_active' => false],
    ];
    $report = multiLocationReport();
    $baseInputs = [
        'locationIds' => $mainId . ',' . $scienceId,
        'locationBasis' => 'effective_item',
        'marcTag' => '856',
    ];

    try {
        $compiled = CatalogingMarcMissingTagReportService::build($report, $baseInputs, $db);
    } catch (\InvalidArgumentException $exception) {
        multiLocationFail('The compiler must accept the reviewed multi-location contract: ' . $exception->getMessage());
    }
    multiLocationContains(
        "location.id = ANY(string_to_array(:locationIds, ',')::uuid[])",
        $compiled['sql'],
        'The report must scope locations through one bound PostgreSQL UUID array.'
    );
    multiLocationSame(
        $mainId . ',' . $scienceId,
        $compiled['params'][':locationIds'] ?? null,
        'The compiler must bind the normalized UUID list as one value.'
    );
    multiLocationSame(
        ['id' => $mainId . ',' . $scienceId, 'name' => '2 Locations', 'code' => 'MULTI'],
        $compiled['location'] ?? null,
        'Multiple selections must produce deterministic export filename metadata.'
    );
    multiLocationSame(
        [':location_lookup_0' => $mainId, ':location_lookup_1' => $scienceId],
        $db->locationLookupParams,
        'The existence lookup must bind every validated UUID separately.'
    );
    multiLocationContains('id IN (:location_lookup_0, :location_lookup_1)', $db->locationLookupSql, 'The existence lookup placeholders must be server-owned.');

    $single = CatalogingMarcMissingTagReportService::build(
        $report,
        array_replace($baseInputs, ['locationIds' => $mainId]),
        $db
    );
    multiLocationSame(
        ['id' => $mainId, 'name' => 'Main Library', 'code' => 'MAIN'],
        $single['location'] ?? null,
        'One selected location must retain its real filename metadata.'
    );

    $inactive = CatalogingMarcMissingTagReportService::build(
        $report,
        array_replace($baseInputs, ['locationIds' => $inactiveId]),
        $db
    );
    multiLocationSame(
        ['id' => $inactiveId, 'name' => 'Closed Library', 'code' => 'CLOSED'],
        $inactive['location'] ?? null,
        'An existing inactive location must remain resolvable for saved URLs.'
    );

    $locationLookupsBeforeInvalidTag = $db->locationLookupCount;
    multiLocationThrows(
        function () use ($report, $baseInputs, $db) {
            CatalogingMarcMissingTagReportService::build(
                $report,
                array_replace($baseInputs, [
                    'locationIds' => '44444444-4444-4444-8444-444444444444',
                    'marcTag' => '000',
                ]),
                $db
            );
        },
        'MARC tag must be exactly three ASCII digits from 001 through 999.',
        'MARC tag validation must take precedence over a missing location.'
    );
    multiLocationSame(
        $locationLookupsBeforeInvalidTag,
        $db->locationLookupCount,
        'Invalid MARC tags must fail before the location lookup runs.'
    );

    foreach ([
        ['', 'At least one location is required.'],
        ['not-a-uuid', 'Every selected location must be a valid UUID.'],
        [$mainId . ',' . $mainId, 'Selected locations must be unique.'],
        [$mainId . ',44444444-4444-4444-8444-444444444444', 'A selected location no longer exists.'],
    ] as $invalidCase) {
        multiLocationThrows(
            function () use ($report, $baseInputs, $db, $invalidCase) {
                CatalogingMarcMissingTagReportService::build(
                    $report,
                    array_replace($baseInputs, ['locationIds' => $invalidCase[0]]),
                    $db
                );
            },
            $invalidCase[1],
            'Invalid location selections must fail before report execution.'
        );
    }

    $tooMany = [];
    for ($index = 1; $index <= 101; $index++) {
        $tooMany[] = sprintf('%08x-0000-4000-8000-%012x', $index, $index);
    }
    multiLocationThrows(
        function () use ($report, $baseInputs, $db, $tooMany) {
            CatalogingMarcMissingTagReportService::build(
                $report,
                array_replace($baseInputs, ['locationIds' => implode(',', $tooMany)]),
                $db
            );
        },
        'No more than 100 locations may be selected.',
        'The backend must enforce the selection cap independently of the UI.'
    );

    fwrite(STDOUT, "Cataloging MARC multi-location compiler tests passed\n");
}
