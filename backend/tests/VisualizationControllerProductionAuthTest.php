<?php
defined('YII_ENV') or define('YII_ENV', 'prod');
require_once __DIR__ . '/support/VisualizationControllerTestSupport.php';

use app\models\User;
use app\services\GateCountVisualizationService;
use app\services\VeaClientService;

function visualizationRegisterGateCountService(&$requests)
{
    putenv('VEA_CLIENT_ID=synthetic-client-id');
    putenv('VEA_CLIENT_SECRET=synthetic-client-secret');
    $client = new VeaClientService(function ($url, $options) use (&$requests) {
        $requests[] = $url;
        if (strpos($url, 'oauth/token') !== false) {
            return ['body' => '{"access_token":"synthetic-access-token"}', 'httpCode' => 200, 'curlError' => ''];
        }
        if (strpos($url, '/data/traffic?') !== false) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $dateKey = $query['dateGroupings'] === 'month' ? 'recordDate_month_1' : 'recordDate_hour_1';
            $date = $query['dateGroupings'] === 'month' ? '2026-07-01T04:00:00.000Z' : '2025-01-01T05:00:00.000Z';
            return ['body' => json_encode(['messages' => [], 'results' => [[
                'zoneId' => 'synthetic-door', 'name' => 'Synthetic Door', 'sumins' => 3, $dateKey => $date,
            ]]]), 'httpCode' => 200, 'curlError' => ''];
        }
        return ['body' => '[]', 'httpCode' => 200, 'curlError' => ''];
    });
    Yii::$container->set(GateCountVisualizationService::class, new GateCountVisualizationService(
        $client,
        ['synthetic-door'],
        function () { return new DateTimeImmutable('2026-11-10T12:00:00-05:00'); }
    ));
}

// Production regression guards: these cases are expected to pass before the dev-mode fix.
visualizationCase('production: every visualization route returns 401 without a token', function () {
    foreach (['options', 'index', 'spending-pace', 'gate-counts', 'history', 'history-upsert', 'history-delete'] as $action) {
        list($f, $controller) = visualizationControllerFixture();
        $veaRequests = [];
        if ($action === 'gate-counts') {
            visualizationRegisterGateCountService($veaRequests);
            Yii::$app->request->setQueryParams(['start' => '2025-01-01', 'end' => '2025-01-01', 'years' => '1']);
        }
        Yii::$app->user->setIdentity(null);
        Yii::$app->request->headers->remove('Authorization');
        Yii::$app->request->setBodyParams([
            'acqUnitCode' => 'SC', 'kind' => 'budget', 'fiscalYearEnd' => 2025, 'totalAmount' => 1,
        ]);
        list($status) = visualizationInvoke($controller, $action, $action === 'history-delete' ? ['id' => 1] : []);
        visualizationSame(401, $status, 'Production ' . $action . ' must reject a request without a bearer token.');
        if ($action === 'gate-counts') visualizationSame([], $veaRequests, 'A guest request must not call Vea.');
    }
});

visualizationCase('production: a persisted non-admin receives 403 on each history route', function () {
    list($f, $controller) = visualizationControllerFixture();
    $f->db->createCommand()->update('users', ['role' => 'user'], ['id' => 7])->execute();
    Yii::$app->user->setIdentity(User::findOne(7));
    $token = \Firebase\JWT\JWT::encode(['sub' => 7, 'exp' => time() + 3600], 'synthetic-visualization-test-key', 'HS256');
    Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token);
    Yii::$app->request->setBodyParams([
        'acqUnitCode' => 'SC', 'kind' => 'budget', 'fiscalYearEnd' => 2025, 'totalAmount' => 1,
    ]);

    foreach (['history', 'history-upsert', 'history-delete'] as $action) {
        list($status) = visualizationInvoke($controller, $action, $action === 'history-delete' ? ['id' => 1] : []);
        visualizationSame(403, $status, 'Production history action ' . $action . ' stays admin-only.');
    }
    visualizationSame(0, (int)$f->db->createCommand('SELECT COUNT(*) FROM visualization_history')->queryScalar(), 'Denied writes cannot create history.');
});

visualizationCase('production: approved non-admin can load gate counts without querying the LDP', function () {
    list($f, $controller) = visualizationControllerFixture('user');
    $veaRequests = [];
    visualizationRegisterGateCountService($veaRequests);
    Yii::$app->request->setQueryParams(['start' => '2025-01-01', 'end' => '2025-01-01', 'years' => '1']);
    $folioQueriesBefore = count($f->folio->queries);
    list($status, $body) = visualizationInvoke($controller, 'gate-counts');

    visualizationSame(200, $status, 'Approved non-admin users can load gate counts.');
    visualizationSame(['Synthetic Door'], $body['doors'], 'Return the synthetic door name.');
    visualizationSame(3, $body['daily'][0]['visits'], 'Return the synthetic hourly total.');
    visualizationSame(3, count($veaRequests), 'Use one token request and two traffic requests.');
    visualizationSame($folioQueriesBefore, count($f->folio->queries), 'Gate counts never query the FOLIO LDP.');
});

visualizationCase('production: an unapproved bearer token receives 401 before the gate-count action', function () {
    list($f, $controller) = visualizationControllerFixture('user');
    $identity = Yii::$app->user->identity;
    $f->db->createCommand()->update('users', ['is_approved' => 0], ['id' => 7])->execute();
    Yii::$app->user->setIdentity($identity);
    $veaRequests = [];
    visualizationRegisterGateCountService($veaRequests);
    Yii::$app->request->setQueryParams(['start' => '2025-01-01', 'end' => '2025-01-01', 'years' => '1']);
    list($status) = visualizationInvoke($controller, 'gate-counts');

    visualizationSame(401, $status, 'The shared bearer filter rejects unapproved users before the action.');
    visualizationSame([], $veaRequests, 'An unapproved request must not call Vea.');
});

visualizationCase('production: invalid gate-count input returns 422 before Vea or LDP calls', function () {
    list($f, $controller) = visualizationControllerFixture('user');
    $veaRequests = [];
    visualizationRegisterGateCountService($veaRequests);
    Yii::$app->request->setQueryParams(['start' => '2025-02-30', 'end' => '2025-03-01', 'years' => '1']);
    $folioQueriesBefore = count($f->folio->queries);
    list($status) = visualizationInvoke($controller, 'gate-counts');

    visualizationSame(422, $status, 'Invalid dates return HTTP 422.');
    visualizationSame([], $veaRequests, 'Invalid input is rejected before a Vea call.');
    visualizationSame($folioQueriesBefore, count($f->folio->queries), 'Invalid input does not query the LDP.');
});

visualizationFinish();
