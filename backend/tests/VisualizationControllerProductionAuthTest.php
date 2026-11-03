<?php
defined('YII_ENV') or define('YII_ENV', 'prod');
require_once __DIR__ . '/support/VisualizationControllerTestSupport.php';

use app\models\User;

// Production regression guards: these cases are expected to pass before the dev-mode fix.
visualizationCase('production: every visualization route returns 401 without a token', function () {
    foreach (['options', 'index', 'history', 'history-upsert', 'history-delete'] as $action) {
        list($f, $controller) = visualizationControllerFixture();
        Yii::$app->user->setIdentity(null);
        Yii::$app->request->headers->remove('Authorization');
        Yii::$app->request->setBodyParams([
            'acqUnitCode' => 'SC', 'kind' => 'budget', 'fiscalYearEnd' => 2025, 'totalAmount' => 1,
        ]);
        list($status) = visualizationInvoke($controller, $action, $action === 'history-delete' ? ['id' => 1] : []);
        visualizationSame(401, $status, 'Production ' . $action . ' must reject a request without a bearer token.');
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

visualizationFinish();
