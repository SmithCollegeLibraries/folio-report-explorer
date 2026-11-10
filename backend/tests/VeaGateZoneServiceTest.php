<?php

foreach (['SettingsService', 'VeaClientService', 'VeaGateZoneService'] as $service) {
    $path = __DIR__ . '/../services/' . $service . '.php';
    if (is_file($path)) {
        require_once $path;
    }
}

use app\services\SettingsService;
use app\services\VeaClientService;
use app\services\VeaGateZoneService;

function veaGateAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function veaGateSame($expected, $actual, $message)
{
    veaGateAssert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

function veaGateCase($name, $test)
{
    global $veaGateFailures;
    try {
        $test();
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    } catch (Throwable $error) {
        $veaGateFailures[] = $name;
        fwrite(STDERR, 'FAIL: ' . $name . ' — ' . $error->getMessage() . "\n");
    }
}

function veaGateFixture($settingsPath, &$requests)
{
    putenv('VEA_CLIENT_ID=synthetic-client-id');
    putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
    SettingsService::setFilePathForTests($settingsPath);
    $zones = [
        ['zoneId' => 'door-b', 'name' => 'South Door', 'countType' => 1, 'countApplication' => 1, 'disabled' => false],
        ['zoneId' => 'queue-1', 'name' => 'Queue', 'countType' => 5, 'countApplication' => 5, 'disabled' => false],
        ['zoneId' => 'door-a', 'name' => 'North Door', 'countType' => 1, 'countApplication' => 1, 'disabled' => false],
        ['zoneId' => 'door-off', 'name' => 'Closed Door', 'countType' => 1, 'countApplication' => 1, 'disabled' => true],
    ];
    $client = new VeaClientService(function ($url, $options) use (&$requests, $zones) {
        $requests[] = [$url, $options];
        if (strpos($url, 'oauth/token') !== false) {
            return ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''];
        }
        return ['body' => json_encode($zones), 'httpCode' => 200, 'curlError' => ''];
    });
    return new VeaGateZoneService($client);
}

veaGateCase('lists traffic counter zones in name order and validates every selection before saving', function () {
    $directory = sys_get_temp_dir() . '/vea-gates-' . uniqid('', true);
    mkdir($directory);
    $settingsPath = $directory . '/settings.json';
    file_put_contents($settingsPath, '{"unrelated_setting":"preserve","vea_gate_zone_ids":["previous"]}');
    $requests = [];
    $service = veaGateFixture($settingsPath, $requests);

    veaGateSame([
        ['zoneId' => 'door-off', 'name' => 'Closed Door', 'countType' => 1, 'countApplication' => 1, 'disabled' => true, 'selectable' => false],
        ['zoneId' => 'door-a', 'name' => 'North Door', 'countType' => 1, 'countApplication' => 1, 'disabled' => false, 'selectable' => true],
        ['zoneId' => 'queue-1', 'name' => 'Queue', 'countType' => 5, 'countApplication' => 5, 'disabled' => false, 'selectable' => false],
        ['zoneId' => 'door-b', 'name' => 'South Door', 'countType' => 1, 'countApplication' => 1, 'disabled' => false, 'selectable' => true],
    ], $service->zones(), 'Only active basic traffic counters are selectable, and names are sorted.');

    $beforeInvalidSaves = count($requests);
    foreach ([
        [null, 'invalid_input'],
        [(object)[], 'invalid_input'],
        [(object)['zoneIds' => null], 'invalid_input'],
        [(object)['zoneIds' => (object)['0' => 'door-a']], 'invalid_input'],
        [(object)['zoneIds' => ['door-a', 3]], 'invalid_input'],
        [(object)['zoneIds' => ['']], 'invalid_input'],
        [(object)['zoneIds' => ['missing-door']], 'unknown_zone'],
        [(object)['zoneIds' => ['queue-1']], 'not_selectable'],
        [(object)['zoneIds' => ['door-off']], 'not_selectable'],
    ] as $case) {
        veaGateSame(['error' => $case[1]], $service->save($case[0]), 'Reject malformed or ineligible input.');
        veaGateSame(['previous'], SettingsService::load()['vea_gate_zone_ids'], 'A rejected choice must not change the saved selection.');
    }
    veaGateSame($beforeInvalidSaves + 3, count($requests), 'Only the three nonempty candidates need a fresh zone list.');

    veaGateSame(['selectedZoneIds' => ['door-b', 'door-a']], $service->save((object)[
        'zoneIds' => ['door-b', 'door-a', 'door-b'],
    ]), 'Save unique zone ids in their original order.');
    SettingsService::setFilePathForTests($settingsPath);
    veaGateSame('preserve', SettingsService::load()['unrelated_setting'], 'Keep unrelated settings.');
    veaGateSame(['door-b', 'door-a'], SettingsService::load()['vea_gate_zone_ids'], 'A replacement survives a fresh settings read.');
    veaGateSame($beforeInvalidSaves + 4, count($requests), 'A nonempty selection is checked against a fresh zone list.');

    veaGateSame(['selectedZoneIds' => []], $service->save((object)['zoneIds' => []]), 'An explicit empty list clears the choice.');
    SettingsService::setFilePathForTests($settingsPath);
    veaGateSame([], SettingsService::load()['vea_gate_zone_ids'], 'The clear survives a fresh settings read.');
    veaGateSame($beforeInvalidSaves + 4, count($requests), 'Clearing does not call Vea.');

    unlink($settingsPath);
    rmdir($directory);
});

veaGateCase('maps a failed settings write to save_failed', function () {
    $directory = sys_get_temp_dir() . '/vea-gates-write-' . uniqid('', true);
    mkdir($directory);
    $requests = [];
    $service = veaGateFixture($directory . '/missing/settings.json', $requests);
    veaGateSame(['error' => 'save_failed'], $service->save((object)['zoneIds' => ['door-a']]), 'A failed disk write is reported without claiming success.');
    veaGateSame(2, count($requests), 'A valid nonempty selection is freshly checked before writing.');
    rmdir($directory);
});

$failed = count($veaGateFailures ?? []);
fwrite(STDOUT, 'Vea gate zone cases: ' . $failed . " failed\n");
exit($failed === 0 ? 0 : 1);
