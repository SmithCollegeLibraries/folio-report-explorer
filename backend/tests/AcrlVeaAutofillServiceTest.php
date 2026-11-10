<?php

foreach (['SettingsService', 'VeaClientService', 'VeaGateZoneService', 'AcrlVeaAutofillService', 'AcrlAutofillService'] as $serviceName) {
    $servicePath = __DIR__ . '/../services/' . $serviceName . '.php';
    if (is_file($servicePath)) {
        require_once $servicePath;
    }
}

use app\services\AcrlAutofillService;
use app\services\AcrlVeaAutofillService;
use app\services\SettingsService;
use app\services\VeaClientService;
use app\services\VeaGateZoneService;

function acrlVeaAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function acrlVeaSame($expected, $actual, $message)
{
    acrlVeaAssert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

class AcrlVeaTestRepository
{
    public $status = 'published';
    public function year($year) { return ['survey_year' => $year, 'status' => $this->status]; }
    public function question($year, $sheet, $questionId, $part)
    {
        return in_array($questionId, ['79a', '79c'], true) ? ['question_id' => $questionId] : null;
    }
}

class AcrlVeaNeverUseFolioDb
{
    public $calls = 0;
    public function beginTransaction()
    {
        $this->calls++;
        throw new RuntimeException('A Vea fill must not open an LDP transaction.');
    }
    public function createCommand($sql, array $params = [])
    {
        $this->calls++;
        throw new RuntimeException('A Vea fill must not issue an LDP command.');
    }
}

function acrlVeaFixture($trafficBody, $zoneBody, array $zoneIds, &$requests)
{
    putenv('VEA_CLIENT_ID=synthetic-client-id');
    putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
    $client = new VeaClientService(function ($url, $options) use (&$requests, $trafficBody, $zoneBody) {
        $requests[] = [$url, $options];
        if (strpos($url, 'oauth/token') !== false) {
            return ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''];
        }
        if (strpos($url, '/data/traffic?') !== false) {
            return ['body' => $trafficBody, 'httpCode' => 200, 'curlError' => ''];
        }
        return ['body' => $zoneBody, 'httpCode' => 200, 'curlError' => ''];
    });
    return new AcrlVeaAutofillService(
        $client,
        function () { return new DateTimeImmutable('2026-10-28T12:34:56-04:00'); },
        $zoneIds
    );
}

function acrlVeaRows(array $rows)
{
    return json_encode(['messages' => [], 'results' => $rows]);
}

class AcrlVeaGateTestDb
{
    public $rows = [];
    public $fail = false;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function createCommand($sql = null, $params = [])
    {
        return new class($this) {
            private $db;
            public function __construct($db) { $this->db = $db; }
            public function queryColumn()
            {
                if ($this->db->fail) {
                    throw new RuntimeException('synthetic private database details');
                }
                $rows = $this->db->rows;
                usort($rows, function ($left, $right) { return $left['sort_order'] - $right['sort_order']; });
                return array_column($rows, 'zone_id');
            }
        };
    }
}

function acrlVeaCase($name, $test)
{
    try {
        $test();
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL: ' . $name . ' — ' . $error->getMessage() . "\n");
        exit(1);
    }
}

acrlVeaCase('dispatches the annual fill to Vea with six selected doors and no LDP use', function () {
    $ids = ['door-1', 'door-2', 'door-3', 'door-4', 'door-5', 'door-6'];
    $rows = [];
    foreach ($ids as $index => $id) {
        $rows[] = ['zoneId' => $id, 'name' => 'Test Door ' . ($index + 1), 'sumins' => (string)($index + 1)];
    }
    $requests = [];
    $vea = acrlVeaFixture(acrlVeaRows($rows), '[]', $ids, $requests);
    $db = new AcrlVeaNeverUseFolioDb();
    $repository = new AcrlVeaTestRepository();
    $autofill = new AcrlAutofillService(
        $repository,
        $db,
        function () { return new DateTimeImmutable('2026-10-28T12:34:56-04:00'); },
        null,
        $vea
    );

    $result = $autofill->run(2025, 'survey', '79a', '');
    acrlVeaSame(21, $result['value'], 'Sum entries across every returned row.');
    acrlVeaSame('vea', $result['source'], 'Mark the result source.');
    acrlVeaSame(['Test Door 1', 'Test Door 2', 'Test Door 3', 'Test Door 4', 'Test Door 5', 'Test Door 6'], $result['doors'], 'Return every selected door once, in the saved order.');
    acrlVeaSame('2024-07-01', $result['fyStart'], 'Return the annual FY start date.');
    acrlVeaSame('2025-07-01', $result['fyEnd'], 'Return the exclusive annual FY end date.');
    acrlVeaSame(null, $result['asOf'], 'Dated Vea results have no snapshot date.');
    acrlVeaSame('2026-10-28T12:34:56-04:00', $result['ranAt'], 'Return the query run time.');
    acrlVeaSame(0, $db->calls, 'Dispatch must happen before the FOLIO transaction and SQL path.');
    acrlVeaSame(2, count($requests), 'One traffic GET requires one token request and no zone lookup when row names exist.');
    $beforeMethods = count($requests);
    $autofill->methods();
    acrlVeaSame($beforeMethods, count($requests), 'The methods catalog must not call Vea.');
    $repository->status = 'draft';
    try {
        $autofill->run(2025, 'survey', '79a', '');
        throw new RuntimeException('An unpublished year reached Vea.');
    } catch (DomainException $error) {
        acrlVeaSame($beforeMethods, count($requests), 'Shared year checks must run before Vea.');
    }

    parse_str(parse_url($requests[1][0], PHP_URL_QUERY), $query);
    acrlVeaSame('2024-07-01T00:00:00-04:00', $query['startDate'], 'Send the Eastern July start bound.');
    acrlVeaSame('2025-07-01T00:00:00-04:00', $query['endDate'], 'Send the Eastern exclusive July end bound.');
    acrlVeaSame('door-1,door-2,door-3,door-4,door-5,door-6', $query['entityIds'], 'Send the selected ids as one comma-joined query value.');
    acrlVeaSame('ins', $query['metrics'], 'Request entries only.');
    acrlVeaSame('zone', $query['entityType'], 'Group the request by zones.');
    acrlVeaSame('day', $query['dateGroupings'], 'Request day buckets.');
    acrlVeaSame('false', $query['excludeClosedHours'], 'Include traffic outside opening hours.');
    acrlVeaSame(60, $requests[1][1][CURLOPT_TIMEOUT], 'Use the 60 second traffic timeout.');
});

acrlVeaCase('reads fill doors through the shared database accessor and stops before Vea on read failure', function () {
    $directory = sys_get_temp_dir() . '/acrl-vea-settings-' . uniqid('', true);
    mkdir($directory);
    $settingsPath = $directory . '/settings.json';
    file_put_contents($settingsPath, '{"vea_gate_zone_ids":["legacy-door"]}');
    SettingsService::setFilePathForTests($settingsPath);
    putenv('VEA_CLIENT_ID=synthetic-client-id');
    putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
    $requests = [];
    $client = new VeaClientService(function ($url, $options) use (&$requests) {
        $requests[] = [$url, $options];
        if (strpos($url, 'oauth/token') !== false) {
            return ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''];
        }
        if (strpos($url, '/data/traffic?') !== false) {
            return ['body' => acrlVeaRows([
                ['zoneId' => 'door-from-db', 'name' => 'Database Door', 'sumins' => 5],
            ]), 'httpCode' => 200, 'curlError' => ''];
        }
        return ['body' => '[]', 'httpCode' => 200, 'curlError' => ''];
    });
    $db = new AcrlVeaGateTestDb([
        ['zone_id' => 'door-from-db', 'sort_order' => 0],
    ]);
    $gateZones = new VeaGateZoneService($client, $db);
    $autofill = new AcrlVeaAutofillService(
        $client,
        function () { return new DateTimeImmutable('2026-10-28T12:34:56-04:00'); },
        null,
        $gateZones
    );
    $result = $autofill->run(2025, '79a');
    acrlVeaSame(['Database Door'], $result['doors'], 'Use the table selection instead of the stale settings value.');
    parse_str(parse_url($requests[1][0], PHP_URL_QUERY), $query);
    acrlVeaSame('door-from-db', $query['entityIds'], 'Send the database selection to Vea.');

    $requests = [];
    $db->rows = [];
    try {
        $autofill->run(2025, '79a');
        throw new RuntimeException('An empty database selection was accepted.');
    } catch (RuntimeException $error) {
        acrlVeaSame('no_doors', $error->getMessage(), 'Only a successful empty read uses the choose-doors response path.');
    }
    acrlVeaSame([], $requests, 'An empty database selection makes no Vea request.');

    $db->rows = [['zone_id' => 'door-from-db', 'sort_order' => 0]];
    $db->fail = true;
    try {
        $autofill->run(2025, '79a');
        throw new RuntimeException('A database read failure was accepted.');
    } catch (RuntimeException $error) {
        acrlVeaSame('vea_error', $error->getMessage(), 'Return a sanitized error code.');
    }
    acrlVeaSame([], $requests, 'A database read failure makes no Vea request.');

    SettingsService::setFilePathForTests(null);
    unlink($settingsPath);
    rmdir($directory);
});

acrlVeaCase('uses fiscal-year-derived week defaults and DST-safe local bounds', function () {
    $cases = [
        [2024, null, '2023-10-15', '2023-10-22', '2023-10-21', '-04:00', '-04:00', 168],
        [2025, '', '2024-10-13', '2024-10-20', '2024-10-19', '-04:00', '-04:00', 168],
        [2025, '2025-02-09', '2025-02-09', '2025-02-16', '2025-02-15', '-05:00', '-05:00', 168],
        [2026, '2025-11-02', '2025-11-02', '2025-11-09', '2025-11-08', '-04:00', '-05:00', 169],
        [2026, '2026-03-08', '2026-03-08', '2026-03-15', '2026-03-14', '-05:00', '-04:00', 167],
    ];
    foreach ($cases as $case) {
        $requests = [];
        $vea = acrlVeaFixture(acrlVeaRows([
            ['zoneId' => 'door-a', 'name' => 'North Door', 'sumins' => 5],
        ]), '[]', ['door-a'], $requests);
        $result = $vea->run($case[0], '79c', $case[1]);
        parse_str(parse_url($requests[1][0], PHP_URL_QUERY), $query);
        acrlVeaSame($case[2], $result['weekStart'], 'Return the selected or default week start.');
        acrlVeaSame($case[4], $result['weekEnd'], 'Return the inclusive last day of the week.');
        acrlVeaSame('vea', $result['source'], 'Mark weekly results as Vea.');
        acrlVeaSame($case[2], $result['fyStart'], 'Return the weekly fiscal start boundary.');
        acrlVeaSame($case[3], $result['fyEnd'], 'Return the weekly exclusive fiscal end boundary.');
        acrlVeaSame($case[2] . 'T00:00:00' . $case[5], $query['startDate'], 'Use the local start offset for the selected date.');
        acrlVeaSame($case[3] . 'T00:00:00' . $case[6], $query['endDate'], 'Use the local end offset after seven calendar days.');
        $elapsedHours = (strtotime($query['endDate']) - strtotime($query['startDate'])) / 3600;
        acrlVeaSame($case[7], $elapsedHours, 'Compute the range by local calendar days across daylight-saving changes.');
    }
});

acrlVeaCase('accepts a June 24 week and rejects out-of-year or malformed week starts before Vea', function () {
    $requests = [];
    $vea = acrlVeaFixture(acrlVeaRows([
        ['zoneId' => 'door-a', 'name' => 'North Door', 'sumins' => 1],
    ]), '[]', ['door-a'], $requests);
    $valid = $vea->run(2025, '79c', '2025-06-24');
    acrlVeaSame('2025-07-01', $valid['fyEnd'], 'Allow a week whose exclusive end is the fiscal-year boundary.');
    acrlVeaSame('2025-06-30', $valid['weekEnd'], 'Return the final inclusive day for the last week of a fiscal year.');
    $beforeInvalid = count($requests);
    foreach (['2025-06-25', '2024-06-30', '2025-02-30', '2025-02-09T00:00:00', 20250209, ['2025-02-09']] as $weekStart) {
        try {
            $vea->run(2025, '79c', $weekStart);
            throw new RuntimeException('An invalid week start was accepted.');
        } catch (InvalidArgumentException $error) {
            acrlVeaAssert($error->getMessage() === 'The selected week must fall within the fiscal year.', 'Return a fixed week validation message.');
        }
    }
    acrlVeaSame($beforeInvalid, count($requests), 'Invalid week starts must be rejected before a Vea request.');
});

acrlVeaCase('parses an empty result as zero and resolves missing door names once', function () {
    $requests = [];
    $zones = json_encode([
        ['zoneId' => 'door-a', 'name' => 'North Door'],
        ['zoneId' => 'door-b', 'name' => 'South Door'],
    ]);
    $vea = acrlVeaFixture(acrlVeaRows([]), $zones, ['door-a', 'door-b', 'door-c'], $requests);
    $result = $vea->run(2025, '79a');
    acrlVeaSame(0, $result['value'], 'An empty result list is a valid zero count.');
    acrlVeaSame(['North Door', 'South Door', 'Unknown door'], $result['doors'], 'Use one zone lookup and label an unresolved selected door.');
    acrlVeaSame(3, count($requests), 'One empty traffic response needs only one additional zone lookup.');
});

acrlVeaCase('rejects malformed envelopes, counts and unselected zones', function () {
    $invalidBodies = [
        '{"messages":[]}',
        '{"messages":[],"results":{}}',
        '{"messages":[],"results":"bad"}',
        json_encode(['results' => [null]]),
        json_encode(['results' => [['zoneId' => 'door-a']]]),
        json_encode(['results' => [['zoneId' => 'door-a', 'sumins' => true]]]),
        json_encode(['results' => [['zoneId' => 'door-a', 'sumins' => 1.5]]]),
        json_encode(['results' => [['zoneId' => 'door-a', 'sumins' => -1]]]),
        json_encode(['results' => [['zoneId' => 'door-a', 'sumins' => '1.2']]]),
        json_encode(['results' => [['zoneId' => 'door-a', 'sumins' => (string)PHP_INT_MAX . '0']]]),
        json_encode(['results' => [['zoneId' => 'door-a', 'sumins' => PHP_INT_MAX], ['zoneId' => 'door-a', 'sumins' => 1]]]),
        json_encode(['results' => [['zoneId' => 'not-selected', 'sumins' => 1]]]),
    ];
    foreach ($invalidBodies as $body) {
        $requests = [];
        $vea = acrlVeaFixture($body, '[]', ['door-a'], $requests);
        try {
            $vea->run(2025, '79a');
            throw new RuntimeException('Malformed Vea data was accepted.');
        } catch (RuntimeException $error) {
            acrlVeaSame('vea_error', $error->getMessage(), 'Use the fixed parser error code.');
        }
        acrlVeaAssert(count($requests) === 2, 'Reject malformed rows before requesting zone names.');
    }
});

acrlVeaCase('returns fixed no-door and not-configured errors without network calls', function () {
    $requests = [];
    $configured = acrlVeaFixture(acrlVeaRows([]), '[]', [], $requests);
    try {
        $configured->run(2025, '79c', '2025-06-25');
        throw new RuntimeException('An out-of-year week reached the door check.');
    } catch (InvalidArgumentException $error) {
        acrlVeaSame('The selected week must fall within the fiscal year.', $error->getMessage(), 'Reject invalid weeks before checking Vea configuration.');
    }
    acrlVeaSame(0, count($requests), 'An invalid week must make no Vea calls even when no doors are saved.');
    try {
        $configured->run(2025, '79a');
        throw new RuntimeException('An empty saved door list was accepted.');
    } catch (RuntimeException $error) {
        acrlVeaSame('no_doors', $error->getMessage(), 'Report the missing door selection.');
    }
    acrlVeaSame(0, count($requests), 'Missing doors must fail before a Vea call.');

    putenv('VEA_CLIENT_ID');
    putenv('VEA_CLIENT_SECRET');
    $unconfigured = new AcrlVeaAutofillService(new VeaClientService(), null, ['door-a']);
    try {
        $unconfigured->run(2025, '79a');
        throw new RuntimeException('Missing Vea settings were accepted.');
    } catch (RuntimeException $error) {
        acrlVeaSame('not_configured', $error->getMessage(), 'Report missing Vea configuration.');
    }
});

fwrite(STDOUT, "ACRL Vea auto-fill cases: 0 failed\n");
