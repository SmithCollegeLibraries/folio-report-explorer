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

defined('YII_ENV') or define('YII_ENV', getenv('YII_ENV') ?: 'prod');
require __DIR__ . '/../config/debug_mode.php';
defined('YII_DEBUG') or define('YII_DEBUG', resolveYiiDebugMode(getenv('YII_DEBUG'), YII_ENV));

if (!YII_DEBUG) {
    ini_set('display_errors', '0');
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
