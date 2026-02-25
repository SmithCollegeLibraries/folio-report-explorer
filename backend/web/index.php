<?php

/**
 * Yii2 web application entry point.
 * Routes all /api/* requests through the Yii2 application.
 */

// Load .env on bare-metal deploys (Docker injects env vars directly)
$envLoader = __DIR__ . '/../config/env.php';
if (file_exists($envLoader)) {
    require $envLoader;
}

// In production, set YII_DEBUG=false and YII_ENV=prod in your .env file
defined('YII_DEBUG') or define('YII_DEBUG', getenv('YII_DEBUG') !== 'false');
defined('YII_ENV') or define('YII_ENV', getenv('YII_ENV') ?: 'dev');

if (!YII_DEBUG) {
    error_reporting(0);
    ini_set('display_errors', '0');
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
