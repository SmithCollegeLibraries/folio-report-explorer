<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use yii\web\Application;
use yii\web\NotFoundHttpException;

$config = require __DIR__ . '/../config/web.php';
$app = new Application([
    'id' => 'acrl-question-assignment-routes-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'request' => ['cookieValidationKey' => 'test-key'],
        'response' => ['class' => yii\web\Response::class],
        'urlManager' => $config['components']['urlManager'],
    ],
]);
Yii::$app->errorHandler->unregister();

$failures = [];
function acrlQuestionRouteCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$routes = [
    ['POST', 'acrl/years/2026/question-assignments', 'acrl-assignment/save-question-assignments'],
    ['GET', 'acrl/years/2026/assignments', 'acrl-assignment/list-assignments'],
];
foreach ($routes as $case) {
    list($method, $path, $expectedRoute) = $case;
    $_SERVER['REQUEST_METHOD'] = $method;
    $app->request->setPathInfo($path);
    try {
        list($route) = $app->request->resolve();
        acrlQuestionRouteCheck($route === $expectedRoute, $method . ' ' . $path . ' must resolve to ' . $expectedRoute . '; got ' . $route . '.');
    } catch (NotFoundHttpException $error) {
        acrlQuestionRouteCheck(false, $method . ' ' . $path . ' must resolve to ' . $expectedRoute . '.');
    } catch (Throwable $error) {
        acrlQuestionRouteCheck(false, $method . ' ' . $path . ' failed to resolve: ' . $error->getMessage());
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL question assignment route checks failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "PASS: ACRL question assignment routes resolve with the body-based tuple contract\n";
