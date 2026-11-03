<?php
require_once __DIR__ . '/VisualizationFixture.php';
require_once __DIR__ . '/../../controllers/VisualizationController.php';

use app\controllers\VisualizationController;
use app\models\User;
use yii\web\Application;

function visualizationControllerFixture($role = 'admin', $approved = 1)
{
    $f = new VisualizationFixture();
    $f->payment(30);
    $unitId = '123e4567-e89b-12d3-a456-426614174000';
    $f->insert('orders.acquisitions_unit__t', ['id' => $unitId, 'name' => ' SC ']);
    $f->insert('finance.fund__t__acq_unit_ids', ['id' => 'fund-s', 'acq_unit_ids' => $unitId]);
    $f->insert('users', ['id' => 7, 'role' => $role, 'is_approved' => $approved, 'username' => 'synthetic-user', 'smith_id' => 'synthetic-7']);
    new Application([
        'id' => 'visualization-controller-test', 'basePath' => dirname(__DIR__, 2),
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
    Yii::$app->request->setQueryParams(['acqUnitId' => $unitId, 'years' => '5', 'acqUnitCode' => 'SC']);
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
