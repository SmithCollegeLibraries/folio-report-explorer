<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use yii\web\Application;
use yii\web\NotFoundHttpException;

$config = require __DIR__ . '/../config/web.php';
$app = new Application([
    'id' => 'acrl-retired-routes-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'request' => ['cookieValidationKey' => 'test-key'],
        'response' => ['class' => yii\web\Response::class],
        'urlManager' => $config['components']['urlManager'],
    ],
]);
Yii::$app->errorHandler->unregister();

$retiredUrls = [
    ['GET', '/local/acrl'],
    ['GET', '/local/acrl/years'],
    ['POST', '/local/acrl'],
    ['PUT', '/local/acrl/1'],
    ['DELETE', '/local/acrl/1'],
    ['POST', '/local/acrl/copy-year'],
];

foreach ($retiredUrls as [$method, $url]) {
    $_SERVER['REQUEST_METHOD'] = $method;
    $app->request->setPathInfo($url);
    $status = 200;
    try {
        $app->request->resolve();
    } catch (NotFoundHttpException $exception) {
        $status = $exception->statusCode;
    }
    if ($status !== 404) {
        fwrite(STDERR, "Expected {$method} {$url} to resolve as 404; got {$status}.\n");
        exit(1);
    }
}

echo "ACRL retired route resolution test passed (6 routes return 404)\n";
