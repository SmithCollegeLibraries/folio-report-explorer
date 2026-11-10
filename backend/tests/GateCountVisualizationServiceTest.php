<?php
require_once __DIR__ . '/../services/VeaClientService.php';
require_once __DIR__ . '/../services/VeaTrafficRequest.php';
require_once __DIR__ . '/../services/GateCountVisualizationService.php';

use app\services\GateCountVisualizationService;
use app\services\VeaClientService;

function gateCountAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function gateCountSame($expected, $actual, $message)
{
    gateCountAssert($expected === $actual, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

function gateCountCase($name, $test)
{
    try {
        $test();
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL: ' . $name . ' — ' . $error->getMessage() . "\n");
        exit(1);
    }
}

function gateCountClient($monthlyRows, $hourlyRows, &$requests, $zones = '[]')
{
    putenv('VEA_CLIENT_ID=synthetic-client-id');
    putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
    return new VeaClientService(function ($url, $options) use (&$requests, $monthlyRows, $hourlyRows, $zones) {
        $requests[] = [$url, $options];
        if (strpos($url, 'oauth/token') !== false) {
            return ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''];
        }
        if (strpos($url, '/data/traffic?') !== false) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $rows = $query['dateGroupings'] === 'month' ? $monthlyRows : $hourlyRows;
            return ['body' => json_encode(['messages' => [], 'results' => $rows]), 'httpCode' => 200, 'curlError' => ''];
        }
        return ['body' => $zones, 'httpCode' => 200, 'curlError' => ''];
    });
}

gateCountCase('groups synthetic month and hour traffic in Eastern time with one request per grouping', function () {
    $requests = [];
    $monthly = [
        ['zoneId' => 'door-a', 'name' => 'East Entry', 'sumins' => 6, 'recordDate_month_1' => '2026-07-01T04:00:00.000Z'],
        ['zoneId' => 'door-b', 'name' => 'West Entry', 'sumins' => '4', 'recordDate_month_1' => '2026-07-01T04:00:00.000Z'],
        ['zoneId' => 'door-a', 'name' => 'East Entry', 'sumins' => 10, 'recordDate_month_1' => '2026-11-01T04:00:00.000Z'],
    ];
    $hourly = [
        ['zoneId' => 'door-a', 'name' => 'East Entry', 'sumins' => 4, 'recordDate_hour_1' => '2025-11-02T05:00:00.000Z'],
        ['zoneId' => 'door-a', 'name' => 'East Entry', 'sumins' => 5, 'recordDate_hour_1' => '2025-11-02T06:00:00.000Z'],
        ['zoneId' => 'door-b', 'name' => 'West Entry', 'sumins' => 2, 'recordDate_hour_1' => '2025-11-02T05:00:00.000Z'],
        ['zoneId' => 'door-a', 'name' => 'East Entry', 'sumins' => 3, 'recordDate_hour_1' => '2025-11-03T05:00:00.000Z'],
        ['zoneId' => 'door-b', 'name' => 'West Entry', 'sumins' => 4, 'recordDate_hour_1' => '2025-11-03T05:00:00.000Z'],
        ['zoneId' => 'door-a', 'name' => 'East Entry', 'sumins' => 7, 'recordDate_hour_1' => '2025-11-04T05:00:00.000Z'],
    ];
    $service = new GateCountVisualizationService(
        gateCountClient($monthly, $hourly, $requests),
        ['door-a', 'door-b'],
        function () { return new DateTimeImmutable('2026-11-10T14:00:00-05:00'); }
    );

    $result = $service->get(['start' => '2025-11-02', 'end' => '2025-11-05', 'years' => '2']);

    gateCountSame(['East Entry', 'West Entry'], $result['doors'], 'Use row names in saved door order.');
    gateCountSame(['start' => '2025-11-02', 'end' => '2025-11-05'], $result['period'], 'Return inclusive local dates.');
    gateCountSame([
        ['date' => '2025-11-02', 'visits' => 11],
        ['date' => '2025-11-03', 'visits' => 7],
        ['date' => '2025-11-04', 'visits' => 7],
        ['date' => '2025-11-05', 'visits' => 0],
    ], $result['daily'], 'Both fall-back 1 a.m. hours contribute to the same Eastern day.');
    gateCountSame(168, count($result['heatmap']), 'Always return every weekday and hour cell.');
    gateCountSame(['weekday' => 0, 'hour' => 1, 'visits' => 11], $result['heatmap'][1], 'Merge both fall-back hours and sum doors.');
    gateCountSame([
        ['date' => '2025-11-02', 'visits' => 11],
        ['date' => '2025-11-03', 'visits' => 7],
        ['date' => '2025-11-04', 'visits' => 7],
    ], $result['topDays'], 'Exclude zero days and sort equal totals by the earlier date.');
    gateCountSame(2026, $result['monthlyByFiscalYear'][0]['fiscalYearEnd'], 'Return fiscal years in order.');
    gateCountSame('2026-06', $result['monthlyByFiscalYear'][0]['months'][11]['month'], 'A completed fiscal year includes all twelve months.');
    gateCountSame(2027, $result['monthlyByFiscalYear'][1]['fiscalYearEnd'], 'Return the current fiscal-year series.');
    gateCountSame([
        ['month' => '2026-07', 'visits' => 10],
        ['month' => '2026-08', 'visits' => 0],
        ['month' => '2026-09', 'visits' => 0],
        ['month' => '2026-10', 'visits' => 0],
        ['month' => '2026-11', 'visits' => 10],
    ], $result['monthlyByFiscalYear'][1]['months'], 'Fill elapsed fiscal months with zero and omit future months.');

    $trafficRequests = [];
    foreach (array_slice($requests, 1) as $request) {
        if (strpos($request[0], '/data/traffic?') !== false) $trafficRequests[] = $request;
    }
    gateCountSame(2, count($trafficRequests), 'Use one monthly and one hourly Vea call.');
    $queries = [];
    foreach ($trafficRequests as $request) {
        parse_str(parse_url($request[0], PHP_URL_QUERY), $query);
        $queries[$query['dateGroupings']] = $query;
        gateCountSame(60, $request[1][CURLOPT_TIMEOUT], 'Give both traffic requests a 60 second timeout.');
    }
    gateCountSame('door-a,door-b', $queries['hour']['entityIds'], 'Join saved zone ids into one value.');
    gateCountSame('ins', $queries['hour']['metrics'], 'Count entries only.');
    gateCountSame('false', $queries['hour']['excludeClosedHours'], 'Include closed hours.');
    gateCountSame('2025-11-02T00:00:00-04:00', $queries['hour']['startDate'], 'Use the start date offset in Eastern time.');
    gateCountSame('2025-11-06T00:00:00-05:00', $queries['hour']['endDate'], 'Use the exclusive end offset after the DST change.');
    gateCountSame('month', $queries['month']['dateGroupings'], 'Request monthly buckets for fiscal series.');
});

gateCountCase('uses the previous fiscal year when fewer than seven current-year days have elapsed', function () {
    $requests = [];
    $client = gateCountClient([], [], $requests, '[{"zoneId":"door-a","name":"Synthetic Door"}]');
    $service = new GateCountVisualizationService(
        $client,
        ['door-a'],
        function () { return new DateTimeImmutable('2026-07-06T12:00:00-04:00'); }
    );

    $result = $service->get([]);

    gateCountSame(['start' => '2025-07-01', 'end' => '2026-06-30'], $result['period'], 'Use the previous complete fiscal year for the short current-year window.');
    gateCountSame(3, count($result['monthlyByFiscalYear']), 'Default to three fiscal years.');
    gateCountSame(['Synthetic Door'], $result['doors'], 'Resolve missing names with the shared zone lookup.');
    gateCountAssert(count($requests) >= 3, 'The default request runs through the synthetic client.');
});

gateCountCase('accepts the inclusive 366-day date limit', function () {
    $requests = [];
    $service = new GateCountVisualizationService(
        gateCountClient([], [], $requests, '[{"zoneId":"door-a","name":"Synthetic Door"}]'),
        ['door-a'],
        function () { return new DateTimeImmutable('2026-11-10T12:00:00-05:00'); }
    );
    $result = $service->get(['start' => '2025-01-01', 'end' => '2026-01-01', 'years' => 1]);
    gateCountSame(366, count($result['daily']), 'The inclusive 366-day maximum is accepted.');
});

gateCountCase('converts a spring-forward day to 23 local hours and sums its daily visits', function () {
    $requests = [];
    $hourly = [
        ['zoneId' => 'door-a', 'name' => 'North Door', 'sumins' => 2, 'recordDate_hour_1' => '2026-03-08T06:00:00.000Z'],
        ['zoneId' => 'door-a', 'name' => 'North Door', 'sumins' => 3, 'recordDate_hour_1' => '2026-03-08T07:00:00.000Z'],
    ];
    $service = new GateCountVisualizationService(
        gateCountClient([], $hourly, $requests),
        ['door-a'],
        function () { return new DateTimeImmutable('2026-11-10T12:00:00-05:00'); }
    );
    $result = $service->get(['start' => '2026-03-08', 'end' => '2026-03-08', 'years' => 1]);
    $hourRequest = null;
    foreach ($requests as $request) {
        if (strpos($request[0], '/data/traffic?') === false) continue;
        parse_str(parse_url($request[0], PHP_URL_QUERY), $query);
        if ($query['dateGroupings'] === 'hour') $hourRequest = $query;
    }
    gateCountSame(5, $result['daily'][0]['visits'], 'Sum hourly entries over the spring-forward day.');
    gateCountSame('2026-03-08T00:00:00-05:00', $hourRequest['startDate'], 'Use the pre-transition offset at the start.');
    gateCountSame('2026-03-09T00:00:00-04:00', $hourRequest['endDate'], 'Use the post-transition offset for the exclusive end.');
    gateCountSame(23 * 60 * 60, strtotime($hourRequest['endDate']) - strtotime($hourRequest['startDate']), 'Request exactly 23 elapsed hours for the spring-forward day.');
});

gateCountCase('rejects invalid date and year parameters before any Vea call', function () {
    $invalid = [
        [['start' => '2025-01-01'], 'A missing end date is invalid.'],
        [['end' => '2025-01-01'], 'A missing start date is invalid.'],
        [['start' => '2025-02-30', 'end' => '2025-03-01'], 'An impossible date is invalid.'],
        [['start' => ['2025-01-01'], 'end' => '2025-01-02'], 'An array date is invalid.'],
        [['start' => '2025-01-01', 'end' => '2026-01-02'], 'A 367-day inclusive span is invalid.'],
        [['start' => '2026-11-10', 'end' => '2026-11-11'], 'A future end date is invalid.'],
        [['start' => '2025-01-01', 'end' => '2025-01-02', 'years' => '2.0'], 'A non-integer year count is invalid.'],
        [['start' => '2025-01-01', 'end' => '2025-01-02', 'years' => 6], 'A year count above five is invalid.'],
    ];
    foreach ($invalid as $case) {
        $requests = [];
        $service = new GateCountVisualizationService(
            gateCountClient([], [], $requests),
            ['door-a'],
            function () { return new DateTimeImmutable('2026-11-10T12:00:00-05:00'); }
        );
        try {
            $service->get($case[0]);
            throw new RuntimeException($case[1]);
        } catch (InvalidArgumentException $error) {
            gateCountSame([], $requests, 'Reject bad input before a token or traffic call.');
        }
    }
});

gateCountCase('rejects malformed monthly or hourly buckets, counts and duplicate zone instants', function () {
    $badResponses = [
        ['month', ['zoneId' => 'door-a', 'sumins' => 1]],
        ['hour', ['zoneId' => 'door-a', 'sumins' => 1]],
        ['hour', ['zoneId' => 'door-a', 'sumins' => true, 'recordDate_hour_1' => '2025-01-01T05:00:00.000Z']],
        ['hour', [
            ['zoneId' => 'door-a', 'sumins' => 1, 'recordDate_hour_1' => '2025-01-01T05:00:00.000Z'],
            ['zoneId' => 'door-a', 'sumins' => 2, 'recordDate_hour_1' => '2025-01-01T05:00:00.000Z'],
        ]],
        ['hour', [['zoneId' => 'other-door', 'sumins' => 1, 'recordDate_hour_1' => '2025-01-01T05:00:00.000Z']]],
    ];
    foreach ($badResponses as $badResponse) {
        list($grouping, $rows) = $badResponse;
        $requests = [];
        putenv('VEA_CLIENT_ID=synthetic-client-id');
        putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
        $client = new VeaClientService(function ($url) use (&$requests, $rows, $grouping) {
            $requests[] = $url;
            if (strpos($url, 'oauth/token') !== false) {
                return ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''];
            }
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $resultRows = $query['dateGroupings'] === $grouping ? $rows : [];
            return ['body' => json_encode(['messages' => [], 'results' => $resultRows]), 'httpCode' => 200, 'curlError' => ''];
        });
        $service = new GateCountVisualizationService(
            $client,
            ['door-a'],
            function () { return new DateTimeImmutable('2026-11-10T12:00:00-05:00'); }
        );
        try {
            $service->get(['start' => '2025-01-01', 'end' => '2025-01-01', 'years' => 1]);
            throw new RuntimeException('Malformed Vea traffic was accepted.');
        } catch (RuntimeException $error) {
            gateCountSame('vea_error', $error->getMessage(), 'Use the fixed Vea error code for malformed rows.');
        }
    }
});

fwrite(STDOUT, "Gate count visualization cases: 0 failed\n");
