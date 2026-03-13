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

// Default to 'prod' for security — dev mode must be explicitly opted into.
// Docker dev: docker-compose.yml injects YII_ENV=dev, so the fallback never fires.
// Bare-metal production: deploy.sh writes putenv('YII_ENV=prod') into env.php,
// loaded above, so getenv('YII_ENV') returns 'prod' before this define().
defined('YII_DEBUG') or define('YII_DEBUG', getenv('YII_DEBUG') !== 'false');
defined('YII_ENV') or define('YII_ENV', getenv('YII_ENV') ?: 'prod');

if (!YII_DEBUG) {
    error_reporting(0);
    ini_set('display_errors', '0');
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
