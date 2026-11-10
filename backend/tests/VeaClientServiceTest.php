<?php

$servicePath = __DIR__ . '/../services/VeaClientService.php';
if (is_file($servicePath)) {
    require_once $servicePath;
}

use app\services\VeaClientService;

function veaClientAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function veaClientSame($expected, $actual, $message)
{
    veaClientAssert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

function veaClientCase($name, $test)
{
    global $veaClientFailures;
    try {
        $test();
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    } catch (Throwable $error) {
        $veaClientFailures[] = $name;
        fwrite(STDERR, 'FAIL: ' . $name . ' — ' . $error->getMessage() . "\n");
    }
}

veaClientCase('uses the client credentials token once and sends it as a verified Bearer request', function () {
    putenv('VEA_CLIENT_ID=synthetic-client-id');
    putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
    $requests = [];
    $responses = [
        ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''],
        ['body' => '[{"zoneId":"zone-1","name":"North Door","countType":1,"disabled":false}]', 'httpCode' => 200, 'curlError' => ''],
        ['body' => '[]', 'httpCode' => 200, 'curlError' => ''],
    ];
    $client = new VeaClientService(function ($url, $options) use (&$requests, &$responses) {
        $requests[] = [$url, $options];
        return array_shift($responses);
    });

    veaClientSame(1, count($client->getZones()), 'The first GET returns one zone.');
    veaClientSame([], $client->getZones(), 'The second GET can reuse the request token.');
    veaClientSame(3, count($requests), 'Two data requests require only one token request.');
    veaClientSame('https://auth.sensourceinc.com/oauth/token', $requests[0][0], 'Use the documented token endpoint.');
    veaClientSame(true, $requests[0][1][CURLOPT_POST], 'Token acquisition uses POST.');
    veaClientSame([
        'grant_type' => 'client_credentials',
        'client_id' => 'synthetic-client-id',
        'client_secret' => 'synthetic-client-secret',
    ], json_decode($requests[0][1][CURLOPT_POSTFIELDS], true), 'Send the client-credentials JSON body.');
    veaClientSame(['Content-Type: application/json'], $requests[0][1][CURLOPT_HTTPHEADER], 'Send JSON to the token endpoint.');
    veaClientSame('https://vea.sensourceinc.com/api/zone', $requests[1][0], 'Use the documented data API base.');
    veaClientSame(['Content-Type: application/json', 'Authorization: Bearer synthetic-access-token'], $requests[1][1][CURLOPT_HTTPHEADER], 'Send JSON and the token as a Bearer header.');
    veaClientSame(true, $requests[1][1][CURLOPT_HTTPGET], 'Read zones with GET.');
    veaClientSame(true, $requests[1][1][CURLOPT_SSL_VERIFYPEER], 'Verify the TLS certificate.');
    veaClientSame(2, $requests[1][1][CURLOPT_SSL_VERIFYHOST], 'Verify the TLS hostname.');
    veaClientSame(20, $requests[1][1][CURLOPT_TIMEOUT], 'Use a 20 second call timeout.');
    veaClientSame(10, $requests[1][1][CURLOPT_CONNECTTIMEOUT], 'Use a 10 second connect timeout.');
    veaClientSame(false, $requests[1][1][CURLOPT_POST] ?? false, 'Data reads are not POST requests.');
});

veaClientCase('builds a GET query URL and supports the longer traffic timeout', function () {
    putenv('VEA_CLIENT_ID=synthetic-client-id');
    putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
    $requests = [];
    $responses = [
        ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''],
        ['body' => '{"messages":[],"results":[]}', 'httpCode' => 200, 'curlError' => ''],
    ];
    $client = new VeaClientService(function ($url, $options) use (&$requests, &$responses) {
        $requests[] = [$url, $options];
        return array_shift($responses);
    });

    $result = $client->getWithQuery('/data/traffic', [
        'relativeDate' => 'custom',
        'startDate' => '2024-07-01T00:00:00-04:00',
        'endDate' => '2025-07-01T00:00:00-04:00',
        'entityType' => 'zone',
        'entityIds' => 'door-a,door-b',
        'metrics' => 'ins',
        'excludeClosedHours' => 'false',
        'dateGroupings' => 'day',
    ], 60);

    veaClientSame('[]', json_encode($result->results), 'Return the JSON object while preserving its results list.');
    veaClientSame(2, count($requests), 'Use one token call and one data GET.');
    veaClientSame('https://vea.sensourceinc.com/api/data/traffic', strtok($requests[1][0], '?'), 'Use the Vea data API path.');
    parse_str(parse_url($requests[1][0], PHP_URL_QUERY), $query);
    veaClientSame('door-a,door-b', $query['entityIds'], 'Encode all selected zones as one comma-joined value.');
    veaClientSame('false', $query['excludeClosedHours'], 'Preserve the literal false query value.');
    veaClientSame(true, $requests[1][1][CURLOPT_HTTPGET], 'Use GET for the data request.');
    veaClientSame(60, $requests[1][1][CURLOPT_TIMEOUT], 'Allow the traffic request 60 seconds.');
    veaClientSame(['Content-Type: application/json', 'Authorization: Bearer synthetic-access-token'], $requests[1][1][CURLOPT_HTTPHEADER], 'Reuse the normal Bearer token handling.');
});

veaClientCase('reports fixed error codes with unreachable taking precedence and no credential leakage', function () {
    putenv('VEA_CLIENT_ID=synthetic-client-id');
    putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
    $cases = [
        ['token', 'unreachable', ['body' => 'synthetic-client-secret synthetic-access-token', 'httpCode' => 401, 'curlError' => 'timeout']],
        ['token', 'auth_failed', ['body' => 'synthetic-client-secret', 'httpCode' => 400, 'curlError' => '']],
        ['token', 'auth_failed', ['body' => 'synthetic-client-secret', 'httpCode' => 401, 'curlError' => '']],
        ['token', 'auth_failed', ['body' => 'synthetic-client-secret', 'httpCode' => 403, 'curlError' => '']],
        ['token', 'vea_error', ['body' => 'synthetic-client-secret', 'httpCode' => 503, 'curlError' => '']],
        ['token', 'auth_failed', ['body' => '{"access_token":""}', 'httpCode' => 200, 'curlError' => '']],
        ['token', 'auth_failed', ['body' => '{"access_token":7}', 'httpCode' => 200, 'curlError' => '']],
        ['data', 'unreachable', ['body' => 'synthetic-access-token', 'httpCode' => 403, 'curlError' => 'TLS error']],
        ['data', 'auth_failed', ['body' => 'synthetic-access-token', 'httpCode' => 401, 'curlError' => '']],
        ['data', 'auth_failed', ['body' => 'synthetic-access-token', 'httpCode' => 403, 'curlError' => '']],
        ['data', 'vea_error', ['body' => 'synthetic-access-token', 'httpCode' => 503, 'curlError' => '']],
        ['data', 'vea_error', ['body' => '{"messages":[],"results":[]}', 'httpCode' => 200, 'curlError' => '']],
    ];
    $logPath = tempnam(sys_get_temp_dir(), 'vea-log-');
    $oldErrorLog = ini_get('error_log');
    ini_set('error_log', $logPath);

    foreach ($cases as $index => $case) {
        $responses = $case[0] === 'token'
            ? [$case[2]]
            : [
                ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''],
                $case[2],
            ];
        $client = new VeaClientService(function () use (&$responses) {
            return array_shift($responses);
        });
        try {
            $client->getZones();
            throw new RuntimeException('Expected request case ' . $index . ' to fail.');
        } catch (RuntimeException $error) {
            veaClientSame($case[1], $error->getMessage(), 'Choose the required error precedence for case ' . $index . '.');
            foreach (['synthetic-client-id', 'synthetic-client-secret', 'synthetic-access-token'] as $secret) {
                veaClientAssert(strpos($error->getMessage(), $secret) === false, 'Error codes must not expose credentials or tokens.');
            }
        }
    }

    $logs = file_get_contents($logPath);
    foreach (['synthetic-client-id', 'synthetic-client-secret', 'synthetic-access-token'] as $secret) {
        veaClientAssert(strpos($logs, $secret) === false, 'Captured logs must not contain credentials or tokens.');
    }
    ini_set('error_log', $oldErrorLog);
    unlink($logPath);
});

veaClientCase('does not call the transport when either environment credential is empty', function () {
    foreach ([
        ['VEA_CLIENT_ID', '', 'VEA_CLIENT_SECRET', 'synthetic-client-secret'],
        ['VEA_CLIENT_ID', null, 'VEA_CLIENT_SECRET', 'synthetic-client-secret'],
        ['VEA_CLIENT_SECRET', '', 'VEA_CLIENT_ID', 'synthetic-client-id'],
        ['VEA_CLIENT_SECRET', null, 'VEA_CLIENT_ID', 'synthetic-client-id'],
    ] as $case) {
        if ($case[1] === null) {
            putenv($case[0]);
        } else {
            putenv($case[0] . '=' . $case[1]);
        }
        putenv($case[2] . '=' . $case[3]);
        $calls = 0;
        $client = new VeaClientService(function () use (&$calls) {
            $calls++;
            return ['body' => '[]', 'httpCode' => 200, 'curlError' => ''];
        });
        try {
            $client->getZones();
            throw new RuntimeException('Missing credentials must fail before transport.');
        } catch (RuntimeException $error) {
            veaClientSame('not_configured', $error->getMessage(), 'Report missing environment credentials as not_configured.');
        }
        veaClientSame(0, $calls, 'Missing credentials must not make a network call.');
    }
});

$failed = count($veaClientFailures ?? []);
fwrite(STDOUT, 'Vea client cases: ' . $failed . " failed\n");
exit($failed === 0 ? 0 : 1);
