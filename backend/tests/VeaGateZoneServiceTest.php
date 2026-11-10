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

function veaGateFixture($settingsPath, &$requests, $db = null)
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
    return new VeaGateZoneService($client, $db);
}

class VeaGateSelectionTestDb
{
    public $rows = [];
    public $failAt;
    public $insertCount = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function beginTransaction()
    {
        if ($this->failAt === 'begin') {
            throw new RuntimeException('synthetic database failure');
        }
        return new VeaGateSelectionTestTransaction($this);
    }
    public function createCommand($sql = null, $params = [])
    {
        return new class($this, $sql, $params) {
            private $db;
            private $sql;
            private $params;
            public function __construct($db, $sql, $params) { $this->db = $db; $this->sql = $sql; $this->params = $params; }
            public function queryColumn()
            {
                if ($this->db->failAt === 'read') {
                    throw new RuntimeException('synthetic database failure with private details');
                }
                $rows = $this->db->rows;
                usort($rows, function ($left, $right) { return $left['sort_order'] - $right['sort_order']; });
                return array_column($rows, 'zone_id');
            }
            public function execute()
            {
                if (strpos($this->sql, 'DELETE FROM vea_gate_zones') === 0) {
                    $this->db->rows = [];
                    if ($this->db->failAt === 'delete') {
                        throw new RuntimeException('synthetic database failure');
                    }
                    return 1;
                }
                if (strpos($this->sql, 'INSERT INTO vea_gate_zones') === 0) {
                    $this->db->insertCount++;
                    if ($this->db->failAt === 'insert' && $this->db->insertCount === 2) {
                        throw new RuntimeException('synthetic database failure');
                    }
                    $this->db->rows[] = [
                        'zone_id' => $this->params[':zone_id'],
                        'sort_order' => $this->params[':sort_order'],
                    ];
                    return 1;
                }
                throw new RuntimeException('Unexpected SQL command.');
            }
        };
    }
}

class VeaGateSelectionTestTransaction
{
    public $isActive = true;
    private $db;
    private $rows;
    public function __construct($db) { $this->db = $db; $this->rows = $db->rows; }
    public function commit() { $this->isActive = false; }
    public function rollBack() { $this->db->rows = $this->rows; $this->isActive = false; }
}

veaGateCase('reads selected doors from the app database in saved order', function () {
    $directory = sys_get_temp_dir() . '/vea-gates-db-' . uniqid('', true);
    mkdir($directory);
    $settingsPath = $directory . '/settings.json';
    file_put_contents($settingsPath, '{"vea_gate_zone_ids":["legacy-door"]}');
    $requests = [];
    $db = new VeaGateSelectionTestDb([
        ['zone_id' => 'door-b', 'sort_order' => 2],
        ['zone_id' => 'door-a', 'sort_order' => 1],
    ]);
    $service = veaGateFixture($settingsPath, $requests, $db);

    veaGateSame(['door-a', 'door-b'], $service->selectedZoneIds(), 'The shared accessor follows sort_order.');
    veaGateSame(['door-a', 'door-b'], $service->status()['selectedZoneIds'], 'Status uses the database selection, not a stale settings value.');
    veaGateSame([], $requests, 'Reading saved doors makes no Vea request.');

    unlink($settingsPath);
    rmdir($directory);
});

veaGateCase('replaces and clears doors transactionally and keeps old rows after database failures', function () {
    $directory = sys_get_temp_dir() . '/vea-gates-save-' . uniqid('', true);
    mkdir($directory);
    $settingsPath = $directory . '/settings.json';
    file_put_contents($settingsPath, '{}');
    $requests = [];
    $db = new VeaGateSelectionTestDb([
        ['zone_id' => 'previous-door', 'sort_order' => 0],
    ]);
    $service = veaGateFixture($settingsPath, $requests, $db);

    veaGateSame(['selectedZoneIds' => ['door-b', 'door-a']], $service->save((object)[
        'zoneIds' => ['door-b', 'door-a', 'door-b'],
    ]), 'Replace the saved set and deduplicate while preserving input order.');
    veaGateSame([
        ['zone_id' => 'door-b', 'sort_order' => 0],
        ['zone_id' => 'door-a', 'sort_order' => 1],
    ], $db->rows, 'Persist each selected id with its stable display order.');
    veaGateSame(['selectedZoneIds' => []], $service->save((object)['zoneIds' => []]), 'An empty set clears the selection.');
    veaGateSame([], $db->rows, 'Clearing removes every previous row.');

    foreach (['begin', 'delete', 'insert'] as $failure) {
        $db->rows = [['zone_id' => 'previous-door', 'sort_order' => 4]];
        $db->failAt = $failure;
        $db->insertCount = 0;
        veaGateSame(['error' => 'save_failed'], $service->save((object)[
            'zoneIds' => ['door-a', 'door-b'],
        ]), 'Map a ' . $failure . ' failure to save_failed.');
        veaGateSame([['zone_id' => 'previous-door', 'sort_order' => 4]], $db->rows, 'Rollback must preserve the previous selection after ' . $failure . '.');
    }

    unlink($settingsPath);
    rmdir($directory);
});

veaGateCase('lists traffic counter zones in name order and validates every selection before saving', function () {
    $directory = sys_get_temp_dir() . '/vea-gates-' . uniqid('', true);
    mkdir($directory);
    $settingsPath = $directory . '/settings.json';
    file_put_contents($settingsPath, '{"unrelated_setting":"preserve","vea_gate_zone_ids":["previous"]}');
    $requests = [];
    $db = new VeaGateSelectionTestDb([
        ['zone_id' => 'previous', 'sort_order' => 0],
    ]);
    $service = veaGateFixture($settingsPath, $requests, $db);

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
        veaGateSame([['zone_id' => 'previous', 'sort_order' => 0]], $db->rows, 'A rejected choice must not change the saved selection.');
    }
    veaGateSame($beforeInvalidSaves + 3, count($requests), 'Only the three nonempty candidates need a fresh zone list.');

    veaGateSame(['selectedZoneIds' => ['door-b', 'door-a']], $service->save((object)[
        'zoneIds' => ['door-b', 'door-a', 'door-b'],
    ]), 'Save unique zone ids in their original order.');
    veaGateSame([
        ['zone_id' => 'door-b', 'sort_order' => 0],
        ['zone_id' => 'door-a', 'sort_order' => 1],
    ], $db->rows, 'Save unique ids in their original order.');
    veaGateSame($beforeInvalidSaves + 4, count($requests), 'A nonempty selection is checked against a fresh zone list.');

    veaGateSame(['selectedZoneIds' => []], $service->save((object)['zoneIds' => []]), 'An explicit empty list clears the choice.');
    veaGateSame([], $db->rows, 'An explicit empty list clears every saved id.');
    veaGateSame('{"unrelated_setting":"preserve","vea_gate_zone_ids":["previous"]}', file_get_contents($settingsPath), 'Saving doors leaves legacy settings untouched.');
    veaGateSame($beforeInvalidSaves + 4, count($requests), 'Clearing does not call Vea.');

    unlink($settingsPath);
    rmdir($directory);
});

veaGateCase('sanitizes a failed app database read', function () {
    $directory = sys_get_temp_dir() . '/vea-gates-write-' . uniqid('', true);
    mkdir($directory);
    $settingsPath = $directory . '/settings.json';
    file_put_contents($settingsPath, '{"vea_gate_zone_ids":["legacy-door"]}');
    $requests = [];
    $db = new VeaGateSelectionTestDb([]);
    $db->failAt = 'read';
    $service = veaGateFixture($settingsPath, $requests, $db);
    try {
        $service->status();
        throw new RuntimeException('A failed app database read was accepted.');
    } catch (RuntimeException $error) {
        veaGateSame('vea_error', $error->getMessage(), 'Hide database details behind the fixed Vea error code.');
    }
    veaGateSame([], $requests, 'A failed database read makes no Vea request.');
    unlink($settingsPath);
    rmdir($directory);
});

$failed = count($veaGateFailures ?? []);
fwrite(STDOUT, 'Vea gate zone cases: ' . $failed . " failed\n");
exit($failed === 0 ? 0 : 1);
