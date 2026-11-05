<?php

defined('YII_ENV') or define('YII_ENV', 'test');
require_once __DIR__ . '/../services/AcrlInviteService.php';

use app\services\AcrlInviteService;

$failures = [];
function acrlInviteHostCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

function acrlInviteHostRejected(string $hostInfo, bool $isDev, string $label): void
{
    global $failures;
    $error = null;
    try {
        AcrlInviteService::linkOriginFromRequest($hostInfo, $isDev);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    acrlInviteHostCheck($error instanceof InvalidArgumentException, $label . ' is rejected as an invalid request host.');
}

$httpsOrigin = null;
try {
    $httpsOrigin = AcrlInviteService::linkOriginFromRequest('https://reports.example.test:8443', false);
} catch (Throwable $error) {
    $failures[] = 'A valid HTTPS host with an optional port is accepted: ' . $error->getMessage();
}
acrlInviteHostCheck($httpsOrigin === 'https://reports.example.test:8443', 'Link origin preserves the validated request host and port.');
$fromAddress = null;
try {
    $fromAddress = AcrlInviteService::inviteFromAddress((string)$httpsOrigin);
} catch (Throwable $error) {
    $failures[] = 'Mail From and Reply-To host conversion failed: ' . $error->getMessage();
}
acrlInviteHostCheck($fromAddress === 'noreply@reports.example.test', 'Mail From and Reply-To omit the request port.');

$devOrigin = null;
try {
    $devOrigin = AcrlInviteService::linkOriginFromRequest('http://localhost:8080', true);
} catch (Throwable $error) {
    $failures[] = 'HTTP is allowed for a dev request: ' . $error->getMessage();
}
acrlInviteHostCheck($devOrigin === 'http://localhost:8080', 'YII_ENV dev allows an HTTP origin for local invite links.');
acrlInviteHostRejected('http://localhost:8080', false, 'HTTP outside dev');
acrlInviteHostRejected("https://reports.example.test\r\n.evil.example", false, 'CR/LF Host header');
acrlInviteHostRejected('https://user@reports.example.test', false, 'user-info in Host header');
acrlInviteHostRejected('https://reports.example.test/path', false, 'path in Host header');
acrlInviteHostRejected('https://reports_example.test', false, 'underscore in hostname');
acrlInviteHostRejected('https://reports.example.test:65536', false, 'out-of-range port');
acrlInviteHostRejected('ftp://reports.example.test', false, 'non-HTTP scheme');

$controllerSource = (string)file_get_contents(__DIR__ . '/../controllers/AcrlInviteController.php');
acrlInviteHostCheck(strpos($controllerSource, 'YII_ENV === \'dev\'') !== false
    && strpos($controllerSource, 'linkOriginFromRequest') !== false,
    'Controller permits the HTTP exception only when its real YII_ENV is dev.');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL invite host checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}

echo "PASS: ACRL invite request origin and mail host validation\n";
