<?php
require_once __DIR__ . '/support/VisualizationFixture.php';
require_once __DIR__ . '/../controllers/VisualizationController.php';

use app\controllers\VisualizationController;
use app\models\User;
use yii\web\Application;

function visualizationControllerFixture($role = 'admin', $approved = 1)
{
    $f = new VisualizationFixture();
    $f->payment(30);
    $f->insert('users', ['id' => 7, 'role' => $role, 'is_approved' => $approved, 'username' => 'synthetic-user', 'smith_id' => 'synthetic-7']);
    new Application([
        'id' => 'visualization-controller-test', 'basePath' => dirname(__DIR__),
        'components' => [
            'db' => $f->db, 'folioDb' => $f->folio, 'cache' => $f->cache,
            'request' => ['cookieValidationKey' => 'test-key', 'enableCsrfValidation' => false],
            'response' => ['class' => yii\web\Response::class],
            'user' => ['identityClass' => User::class, 'enableSession' => false],
        ],
    ]);
    Yii::$app->errorHandler->unregister();
    Yii::$app->user->setIdentity(User::findOne(7));
    putenv('JWT_SECRET=synthetic-visualization-test-key');
    $token = \Firebase\JWT\JWT::encode(['sub' => 7, 'exp' => time() + 3600], 'synthetic-visualization-test-key', 'HS256');
    Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token);
    Yii::$app->request->setQueryParams(['acqUnitId' => 's', 'years' => '5', 'acqUnitCode' => 'SC']);
    return [$f, new VisualizationController('visualization', Yii::$app)];
}

function visualizationInvoke($controller, $action, array $params = [], $direct = false)
{
    Yii::$app->response->statusCode = 200;
    $normalized = str_replace('-', '', strtolower($action));
    $_SERVER['REQUEST_METHOD'] = $normalized === 'historyupsert' ? 'PUT' : ($normalized === 'historydelete' ? 'DELETE' : 'GET');
    try {
        $body = $direct ? call_user_func_array([$controller, 'action' . ucfirst($action)], $params) : $controller->runAction($action, $params);
        return [Yii::$app->response->statusCode, $body];
    } catch (yii\web\HttpException $error) {
        return [$error->statusCode, ['error' => $error->getMessage()]];
    }
}

visualizationCase('visualization API registers every documented verb and route', function () {
    $source = file_get_contents(__DIR__ . '/../config/web.php');
    foreach ([
        'GET visualizations/options' => 'visualization/options',
        'GET visualizations' => 'visualization/index',
        'GET visualizations/history' => 'visualization/history',
        'PUT visualizations/history' => 'visualization/history-upsert',
        'DELETE visualizations/history/<id:\\d+>' => 'visualization/history-delete',
    ] as $pattern => $route) {
        visualizationAssert(strpos($source, "'" . $pattern . "'") !== false && strpos($source, "'" . $route . "'") !== false, 'Register ' . $pattern . ' -> ' . $route . '.');
    }
});

visualizationCase('access rejects guest and persisted-unapproved users on every route including warm caches', function () {
    foreach (['options', 'index', 'history', 'history-upsert', 'history-delete'] as $action) {
        foreach (['guest', 'unapproved'] as $identity) {
            foreach (['cold', 'warm'] as $temperature) {
                list($f, $controller) = visualizationControllerFixture();
                if ($temperature === 'warm') {
                    list($warmStatus) = visualizationInvoke($controller, 'index');
                    visualizationSame(200, $warmStatus, 'Warm the actual chart cache through the approved API.');
                    visualizationAssert($f->cache->writes !== [], 'The warm-cache access test needs actual cached chart results.');
                }
                if ($identity === 'guest') {
                    Yii::$app->user->setIdentity(null);
                    Yii::$app->request->headers->remove('Authorization');
                } else {
                    // Keep a stale approved identity while the persisted approval is revoked.
                    $f->db->createCommand()->update('users', ['is_approved' => 0], ['id' => 7])->execute();
                }
                $before = (int)$f->db->createCommand('SELECT COUNT(*) FROM visualization_history')->queryScalar();
                Yii::$app->request->setBodyParams(['acqUnitCode' => 'SC', 'kind' => 'budget', 'fiscalYearEnd' => 2025, 'totalAmount' => 1]);
                list($status) = visualizationInvoke($controller, $action, $action === 'history-delete' ? ['id' => 1] : []);
                visualizationAssert(in_array($status, [401, 403], true), 'Access must be denied before returning a warm result or changing history.');
                visualizationSame($before, (int)$f->db->createCommand('SELECT COUNT(*) FROM visualization_history')->queryScalar(), 'Denied routes cannot write history.');
            }
        }
    }
});

visualizationCase('admin-only history read/write/delete enforce the persisted-admin guard on direct actions', function () {
    list($f, $controller) = visualizationControllerFixture();
    // The session still claims admin; only persisted state changes.
    $f->db->createCommand()->update('users', ['role' => 'user'], ['id' => 7])->execute();
    Yii::$app->request->setBodyParams(['acqUnitCode' => 'SC', 'kind' => 'budget', 'fiscalYearEnd' => 2025, 'totalAmount' => 1]);
    foreach (['History' => [], 'HistoryUpsert' => [], 'HistoryDelete' => [1]] as $action => $params) {
        list($status) = visualizationInvoke($controller, $action, $params, true);
        visualizationSame(403, $status, 'Direct ' . $action . ' checks persisted role inside the action.');
    }
    visualizationSame(0, (int)$f->db->createCommand('SELECT COUNT(*) FROM visualization_history')->queryScalar(), 'Non-admin direct calls cannot persist history.');
});

visualizationCase('approved plain user can read charts/options but cannot read the historical editor', function () {
    list($f, $controller) = visualizationControllerFixture('user');
    foreach (['Options', 'Index'] as $action) {
        list($status, $body) = visualizationInvoke($controller, $action, [], true);
        visualizationSame(200, $status, 'Approved users can use ' . $action . '.');
        visualizationAssert(is_array($body), 'Read routes return a JSON object.');
    }
    list($status) = visualizationInvoke($controller, 'History', [], true);
    visualizationSame(403, $status, 'The historical editor is admin only.');
});

visualizationCase('options API exposes trimmed acquisitions-unit codes and the four supported ranges', function () {
    list($f, $controller) = visualizationControllerFixture();
    $f->insert('orders.acquisitions_unit__t', ['id' => 'deleted', 'name' => 'ZZ', 'is_deleted' => 1]);
    list($status, $body) = visualizationInvoke($controller, 'Options', [], true);
    visualizationSame(200, $status, 'Options are available to approved users.');
    visualizationSame([5, 10, 15, 20], $body['years'], 'Offer only supported year ranges.');
    $units = array_column($body['units'], null, 'id');
    visualizationSame('SC', $units['s']['code'], 'Unit code is TRIM(name).');
    visualizationAssert(isset($units['s']['name']) && !isset($units['deleted']), 'Units include a display name and exclude deleted options.');
});

visualizationCase('API rejects unknown units and invalid range/material years with 400', function () {
    foreach ([['acqUnitId' => 'unknown'], ['years' => 6], ['years' => '5junk'], ['materialFiscalYearEnd' => 'invalid'], ['materialFiscalYearEnd' => 1900]] as $invalid) {
        list($f, $controller) = visualizationControllerFixture();
        Yii::$app->request->setQueryParams(array_merge(['acqUnitId' => 's', 'years' => '5'], $invalid));
        list($status) = visualizationInvoke($controller, 'Index', [], true);
        visualizationSame(400, $status, 'Reject invalid query parameters instead of silently coercing them.');
    }
});

visualizationCase('history API returns 422 field errors and uses session updated_by/deleted_by for valid writes', function () {
    list($f, $controller) = visualizationControllerFixture();
    Yii::$app->request->setBodyParams(['acqUnitCode' => 'SC', 'kind' => 'purchases', 'fiscalYearEnd' => 1989, 'totalAmount' => 10]);
    list($status, $body) = visualizationInvoke($controller, 'HistoryUpsert', [], true);
    visualizationSame(422, $status, 'Invalid history writes return HTTP 422.');
    visualizationAssert(!empty($body['errors']['fiscalYearEnd']), 'Return field errors for the editor.');
    Yii::$app->request->setBodyParams(['acqUnitCode' => 'SC', 'kind' => 'budget', 'fiscalYearEnd' => 2025, 'totalAmount' => 10, 'updated_by' => 999, 'updatedBy' => 999]);
    list($status) = visualizationInvoke($controller, 'HistoryUpsert', [], true);
    visualizationAssert(in_array($status, [200, 201], true), 'Valid PUT saves a historical year.');
    $row = $f->db->createCommand('SELECT * FROM visualization_history')->queryOne();
    visualizationSame(7, (int)$row['updated_by'], 'Only the authenticated actor controls updated_by.');
    list($status) = visualizationInvoke($controller, 'HistoryDelete', [$row['id']], true);
    visualizationAssert(in_array($status, [200, 204], true), 'An admin can soft delete.');
    $row = $f->db->createCommand('SELECT * FROM visualization_history')->queryOne();
    visualizationSame(7, (int)$row['deleted_by'], 'Only the authenticated actor controls deleted_by.');
    visualizationAssert(!empty($row['deleted_at']), 'DELETE preserves the row with a timestamp.');
});

visualizationFinish();
