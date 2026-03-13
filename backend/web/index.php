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

// In Docker the PHP-FPM process clears environment variables (clear_env = yes),
// so getenv('YII_ENV') returns false even when docker-compose injects YII_ENV=dev.
// The fallback is 'dev' so Docker development works without any extra config.
// On bare-metal production, deploy.sh generates backend/config/env.php which
// calls putenv('YII_ENV=prod') *before* this define(), so production is secure.
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
