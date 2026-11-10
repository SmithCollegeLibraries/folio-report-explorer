<?php

defined('YII_ENV') or define('YII_ENV', 'prod');
require_once __DIR__ . '/support/VisualizationControllerTestSupport.php';
$controllerPath = __DIR__ . '/../controllers/VeaController.php';
if (is_file($controllerPath)) {
    require_once $controllerPath;
}
require_once __DIR__ . '/../services/VeaGateZoneService.php';
require_once __DIR__ . '/../services/VeaClientService.php';
require_once __DIR__ . '/../services/SettingsService.php';

use app\controllers\VeaController;
use app\models\User;
use app\services\SettingsService;
use app\services\VeaClientService;
use app\services\VeaGateZoneService;

function veaControllerAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function veaControllerSame($expected, $actual, $message)
{
    veaControllerAssert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

function veaControllerCase($name, $test)
{
    global $veaControllerFailures;
    try {
        $test();
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    } catch (Throwable $error) {
        $veaControllerFailures[] = $name;
        fwrite(STDERR, 'FAIL: ' . $name . ' — ' . $error->getMessage() . "\n");
    }
}

class VeaControllerTestService extends VeaGateZoneService
{
    public $calls = [];
    public $testError;
    public $zonesError;
    public $saveResult = ['selectedZoneIds' => ['zone-a']];

    public function __construct() {}
    public function status(): array
    {
        $this->calls[] = 'status';
        return ['configured' => true, 'selectedZoneIds' => ['zone-a']];
    }
    public function test(): array
    {
        $this->calls[] = 'test';
        if ($this->testError) throw new RuntimeException($this->testError);
        return ['ok' => true, 'zoneCount' => 2];
    }
    public function zones(): array
    {
        $this->calls[] = 'zones';
        if ($this->zonesError) throw new RuntimeException($this->zonesError);
        return [['zoneId' => 'zone-a', 'name' => 'Main Door', 'countType' => 1, 'countApplication' => 1, 'disabled' => false, 'selectable' => true]];
    }
    public function save($payload): array
    {
        $this->calls[] = ['save', $payload];
        return $this->saveResult;
    }
}

function veaControllerFixture()
{
    list($fixture) = visualizationControllerFixture();
    $service = new VeaControllerTestService();
    return [$fixture, new VeaController('vea', Yii::$app, [], $service), $service];
}

function veaControllerInvoke($controller, $action, $method = 'GET', $rawBody = null)
{
    Yii::$app->response->statusCode = 200;
    $_SERVER['REQUEST_METHOD'] = $method;
    if ($rawBody !== null) {
        Yii::$app->request->setRawBody($rawBody);
    }
    try {
        $body = $controller->runAction($action);
        return [Yii::$app->response->statusCode, $body];
    } catch (yii\web\HttpException $error) {
        return [$error->statusCode, ['error' => $error->getMessage()]];
    }
}

veaControllerCase('registers the four Vea routes and returns the documented success and error shapes', function () {
    $routes = file_get_contents(__DIR__ . '/../config/web.php');
    foreach ([
        "'GET vea/status' => 'vea/status'",
        "'POST vea/test' => 'vea/test'",
        "'GET vea/zones' => 'vea/zones'",
        "'PUT vea/gate-zones' => 'vea/gate-zones'",
    ] as $route) {
        veaControllerAssert(strpos($routes, $route) !== false, 'Register route ' . $route . '.');
    }

    list($fixture, $controller, $service) = veaControllerFixture();
    list($status, $body) = veaControllerInvoke($controller, 'status');
    veaControllerSame(200, $status, 'Status succeeds.');
    veaControllerSame(['configured' => true, 'selectedZoneIds' => ['zone-a']], $body, 'Status has only configuration and saved ids.');
    veaControllerSame(['status'], $service->calls, 'Status never makes a Vea request.');

    list($status, $body) = veaControllerInvoke($controller, 'test', 'POST');
    veaControllerSame(200, $status, 'A successful test returns HTTP 200.');
    veaControllerSame(['ok' => true, 'zoneCount' => 2], $body, 'Connection test returns its zone count.');
    list($status, $body) = veaControllerInvoke($controller, 'zones');
    veaControllerSame(200, $status, 'Zone list succeeds.');
    veaControllerSame('Main Door', $body[0]['name'], 'Zone list returns the service result.');

    foreach ([['not_configured', 409], ['unreachable', 502], ['auth_failed', 502], ['vea_error', 502]] as $case) {
        $service->zonesError = $case[0];
        list($status, $body) = veaControllerInvoke($controller, 'zones');
        veaControllerSame($case[1], $status, 'Map ' . $case[0] . ' to the specified zone-list status.');
        veaControllerSame(['error' => $case[0]], $body, 'Return only the fixed error code.');
        foreach (['synthetic-client-id', 'synthetic-client-secret', 'synthetic-access-token'] as $secret) {
            veaControllerAssert(strpos(json_encode($body), $secret) === false, 'Responses must not expose credentials or tokens.');
        }
    }

    $service->testError = 'auth_failed';
    list($status, $body) = veaControllerInvoke($controller, 'test', 'POST');
    veaControllerSame(200, $status, 'Connection failures use HTTP 200.');
    veaControllerSame(['ok' => false, 'error' => 'auth_failed'], $body, 'Connection failures return a fixed error code.');

    foreach ([['invalid_input', 422], ['unknown_zone', 422], ['not_selectable', 422], ['unreachable', 502], ['not_configured', 502], ['save_failed', 500]] as $case) {
        $service->saveResult = ['error' => $case[0]];
        list($status, $body) = veaControllerInvoke($controller, 'gate-zones', 'PUT', '{"zoneIds":["zone-a"]}');
        veaControllerSame($case[1], $status, 'Map ' . $case[0] . ' to the specified save status.');
        veaControllerSame(['error' => $case[0]], $body, 'Save failures return only the error code.');
    }
    $service->saveResult = ['selectedZoneIds' => ['zone-a']];
    list($status, $body) = veaControllerInvoke($controller, 'gate-zones', 'PUT', '{"zoneIds":["zone-a"]}');
    veaControllerSame(200, $status, 'A successful save returns HTTP 200.');
    veaControllerSame(['selectedZoneIds' => ['zone-a']], $body, 'A successful save returns selected ids, not the SettingsService result.');
    $lastCall = $service->calls[count($service->calls) - 1];
    veaControllerAssert(is_object($lastCall[1]) && $lastCall[1]->zoneIds === ['zone-a'], 'The controller passes a parsed JSON object to the service.');
});

veaControllerCase('rejects unauthenticated, non-admin and demoted admin requests before Vea calls or saves', function () {
    foreach (['status', 'test', 'zones', 'gate-zones'] as $action) {
        list($fixture, $controller, $service) = veaControllerFixture();
        Yii::$app->user->setIdentity(null);
        Yii::$app->request->headers->remove('Authorization');
        list($status) = veaControllerInvoke($controller, $action, $action === 'test' ? 'POST' : ($action === 'gate-zones' ? 'PUT' : 'GET'), '{"zoneIds":["zone-a"]}');
        veaControllerSame(401, $status, 'Unauthenticated ' . $action . ' is rejected by bearer auth.');
        veaControllerSame([], $service->calls, 'An unauthenticated request makes no Vea call and no save.');
    }

    list($fixture, $controller, $service) = veaControllerFixture();
    $fixture->db->createCommand()->update('users', ['role' => 'user'], ['id' => 7])->execute();
    foreach (['status', 'test', 'zones', 'gate-zones'] as $action) {
        list($status) = veaControllerInvoke($controller, $action, $action === 'test' ? 'POST' : ($action === 'gate-zones' ? 'PUT' : 'GET'), '{"zoneIds":["zone-a"]}');
        veaControllerSame(403, $status, 'A persisted non-admin is rejected by ' . $action . '.');
    }
    veaControllerSame([], $service->calls, 'A non-admin request makes no Vea call and no save.');

    list($fixture, $controller, $service) = veaControllerFixture();
    $fixture->db->createCommand()->update('users', ['is_approved' => 0], ['id' => 7])->execute();
    foreach (['Status', 'Test', 'Zones', 'GateZones'] as $action) {
        Yii::$app->response->statusCode = 200;
        if ($action === 'GateZones') Yii::$app->request->setRawBody('{"zoneIds":["zone-a"]}');
        $body = $controller->{'action' . $action}();
        veaControllerSame(403, Yii::$app->response->statusCode, 'The trait rejects a demoted or unapproved administrator.');
        veaControllerSame(['error' => 'Forbidden'], $body, 'Return the trait response.');
    }
    veaControllerSame([], $service->calls, 'A demoted admin request makes no Vea call and no save.');
});

veaControllerCase('never exposes synthetic credentials, tokens or upstream bodies in HTTP responses or logs', function () {
    $directory = sys_get_temp_dir() . '/vea-controller-log-' . uniqid('', true);
    mkdir($directory);
    $settingsPath = $directory . '/settings.json';
    file_put_contents($settingsPath, '{}');
    SettingsService::setFilePathForTests($settingsPath);
    putenv('VEA_CLIENT_ID=synthetic-client-id');
    putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
    list($fixture) = visualizationControllerFixture();

    $cases = [
        ['token', 'unreachable', ['body' => 'synthetic-client-id synthetic-client-secret synthetic-access-token', 'httpCode' => 401, 'curlError' => 'DNS failure']],
        ['token', 'auth_failed', ['body' => 'synthetic-client-id synthetic-client-secret synthetic-access-token', 'httpCode' => 401, 'curlError' => '']],
        ['token', 'vea_error', ['body' => 'synthetic-client-id synthetic-client-secret synthetic-access-token', 'httpCode' => 503, 'curlError' => '']],
        ['token', 'auth_failed', ['body' => 'synthetic-client-id synthetic-client-secret', 'httpCode' => 200, 'curlError' => '']],
        ['data', 'unreachable', ['body' => 'synthetic-client-id synthetic-client-secret synthetic-access-token', 'httpCode' => 403, 'curlError' => 'TLS failure']],
        ['data', 'auth_failed', ['body' => 'synthetic-client-id synthetic-client-secret synthetic-access-token', 'httpCode' => 401, 'curlError' => '']],
        ['data', 'auth_failed', ['body' => 'synthetic-client-id synthetic-client-secret synthetic-access-token', 'httpCode' => 403, 'curlError' => '']],
        ['data', 'vea_error', ['body' => 'synthetic-client-id synthetic-client-secret synthetic-access-token', 'httpCode' => 503, 'curlError' => '']],
        ['data', 'vea_error', ['body' => 'synthetic-client-id synthetic-client-secret synthetic-access-token', 'httpCode' => 200, 'curlError' => '']],
    ];
    $logPath = $directory . '/php-error.log';
    $oldErrorLog = ini_get('error_log');
    ini_set('error_log', $logPath);

    foreach ($cases as $index => $case) {
        foreach (['test', 'zones'] as $action) {
            file_put_contents($logPath, '');
            $responses = $case[0] === 'token'
                ? [$case[2]]
                : [
                    ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''],
                    $case[2],
                ];
            $client = new VeaClientService(function () use (&$responses) {
                return array_shift($responses);
            });
            $controller = new VeaController('vea', Yii::$app, [], new VeaGateZoneService($client));
            list($status, $body) = veaControllerInvoke($controller, $action, $action === 'test' ? 'POST' : 'GET');
            $expectedStatus = $action === 'test' ? 200 : ($case[1] === 'not_configured' ? 409 : 502);
            veaControllerSame($expectedStatus, $status, 'Return the correct ' . $action . ' status for failure ' . $index . '.');
            $expectedBody = $action === 'test'
                ? ['ok' => false, 'error' => $case[1]]
                : ['error' => $case[1]];
            veaControllerSame($expectedBody, $body, 'Return only the fixed code for failure ' . $index . '.');
            $captured = json_encode($body) . file_get_contents($logPath);
            foreach (['synthetic-client-id', 'synthetic-client-secret', 'synthetic-access-token'] as $secret) {
                veaControllerAssert(strpos($captured, $secret) === false, 'No HTTP response or log may expose credentials or tokens.');
            }
        }
    }

    ini_set('error_log', $oldErrorLog);
    unlink($settingsPath);
    if (file_exists($logPath)) unlink($logPath);
    rmdir($directory);
});

$failed = count($veaControllerFailures ?? []);
fwrite(STDOUT, 'Vea controller cases: ' . $failed . " failed\n");
exit($failed === 0 ? 0 : 1);
