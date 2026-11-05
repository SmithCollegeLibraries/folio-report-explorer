<?php

defined('YII_ENV') or define('YII_ENV', 'dev');
require_once __DIR__ . '/../services/AcrlInviteService.php';

use app\services\AcrlInviteService;

$origin = null;
$error = null;
try {
    $origin = AcrlInviteService::linkOriginFromRequest('http://localhost:8090', YII_ENV === 'dev');
} catch (Throwable $caught) {
    $error = $caught;
}
if ($error !== null || $origin !== 'http://localhost:8090') {
    fwrite(STDERR, 'FAIL: YII_ENV=dev must allow a valid HTTP invite origin; got ' . ($error ? $error->getMessage() : var_export($origin, true)) . "\n");
    exit(1);
}

echo "PASS: YII_ENV=dev allows HTTP invite origins\n";
