<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/AcrlInviteMailer.php';

use app\services\AcrlInviteMailer;
use yii\web\Application;

$webConfig = require __DIR__ . '/../config/web.php';
$errorLogPath = sys_get_temp_dir() . '/acrl-invite-mailer-errors-' . bin2hex(random_bytes(8)) . '.log';
$infoLogPath = sys_get_temp_dir() . '/acrl-invite-mailer-info-' . bin2hex(random_bytes(8)) . '.log';
$productionTargets = $webConfig['components']['log']['targets'];
foreach ($productionTargets as $index => $productionTarget) {
    $productionTargets[$index]['logFile'] = $index === 0 ? $errorLogPath : $infoLogPath;
}
$app = new Application([
    'id' => 'acrl-invite-mailer-test',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'components' => [
        'request' => ['cookieValidationKey' => 'test-key'],
        'response' => ['class' => yii\web\Response::class],
        'errorHandler' => $webConfig['components']['errorHandler'],
        'log' => ['traceLevel' => 3, 'targets' => $productionTargets],
    ],
]);

$failures = [];
function acrlInviteMailerCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

$senderCalls = [];
$senderResult = true;
$mailer = new AcrlInviteMailer(function ($recipient, $subject, $body, $from, $replyTo) use (&$senderCalls, &$senderResult) {
    $senderCalls[] = [$recipient, $subject, $body, $from, $replyTo];
    return $senderResult;
});
$subject = "[FOLIO Report Explorer] You're invited to help with the ACRL survey FY 2026";
$body = "Invited by Synthetic Admin\nQ60 · A\nhttps://reports.example.test/acrl/entry/2026\nSign in with your Smith login, then open this link again.\nExpires 2027-02-03";
$recipient = 'jane.test@smith.example';
$from = 'noreply@reports.example.test';

$sent = null;
try {
    $sent = $mailer->send(71, $recipient, $subject, $body, $from, $from);
} catch (Throwable $error) {
    $failures[] = 'Successful injected mail sender must not throw: ' . $error->getMessage();
}
acrlInviteMailerCheck($sent === true, 'Mailer returns true when the injected sender reports success.');
acrlInviteMailerCheck(($senderCalls[0] ?? null) === [$recipient, $subject, $body, $from, $from], 'Mailer passes recipient, exact subject/body, From, and Reply-To to the injected sender.');

$beforeInvalid = count($senderCalls);
$invalidValues = [
    ['bad\r\nBcc: stolen@example.test', $subject, $body, $from, $from],
    [$recipient, "subject\nforged", $body, $from, $from],
    [$recipient, $subject, $body, "noreply@reports.example.test\r\nBcc: stolen@example.test", $from],
    [$recipient, $subject, $body, $from, "reply@reports.example.test\nX-Injected: yes"],
    ['not-an-address', $subject, $body, $from, $from],
    [$recipient, $subject, $body, 'not-an-address', $from],
    [$recipient, $subject, $body, $from, 'not-an-address'],
];
foreach ($invalidValues as $index => $args) {
    try {
        $result = call_user_func_array([$mailer, 'send'], array_merge([72 + $index], $args));
        acrlInviteMailerCheck($result === false, 'Invalid mail value ' . ($index + 1) . ' returns false without throwing.');
    } catch (Throwable $error) {
        $failures[] = 'Invalid mail value ' . ($index + 1) . ' must return false without throwing: ' . $error->getMessage();
    }
}
acrlInviteMailerCheck(count($senderCalls) === $beforeInvalid, 'Invalid recipient or header data is rejected before invoking mail().');

$senderResult = false;
$failureResult = null;
try {
    $failureResult = $mailer->send(99, $recipient, $subject, $body, $from, $from);
} catch (Throwable $error) {
    $failures[] = 'A mail transport failure must return false instead of throwing: ' . $error->getMessage();
}
acrlInviteMailerCheck($failureResult === false, 'Mailer returns false when the injected sender reports failure.');
$throwingMailer = new AcrlInviteMailer(function () { throw new RuntimeException('synthetic transport failure'); });
$throwingResult = null;
try {
    $throwingResult = $throwingMailer->send(100, $recipient, $subject, $body, $from, $from);
} catch (Throwable $error) {
    $failures[] = 'Injected sender exception must be converted to false: ' . $error->getMessage();
}
acrlInviteMailerCheck($throwingResult === false, 'Mailer converts transport exceptions to false.');
$warningMailer = new AcrlInviteMailer(function ($warningRecipient, $warningSubject, $warningBody) {
    trigger_error('synthetic transport warning ' . $warningRecipient . ' ' . $warningBody, E_USER_WARNING);
    return false;
});
$warningResult = null;
try {
    $warningResult = $warningMailer->send(101, $recipient, $subject, $body, $from, $from);
} catch (Throwable $error) {
    $failures[] = 'A transport warning under the production error handler must become false: ' . $error->getMessage();
}
acrlInviteMailerCheck($warningResult === false, 'Mailer converts an injected transport warning to false.');

Yii::getLogger()->flush(true);
$logText = (string)@file_get_contents($errorLogPath) . (string)@file_get_contents($infoLogPath);
$infoLogText = (string)@file_get_contents($infoLogPath);
acrlInviteMailerCheck(strpos($infoLogText, '71') !== false && strpos($infoLogText, '99') !== false, 'The configured production info target records invite outcomes by ID.');
acrlInviteMailerCheck(strpos($infoLogText, '101') !== false, 'The configured production info target records the warning outcome by invite ID.');
foreach ([$recipient, 'Synthetic Admin', $subject, $body, $from] as $privateValue) {
    acrlInviteMailerCheck(strpos((string)$logText, $privateValue) === false, 'Mail logs omit recipient, names, subject/body, and host-derived addresses.');
}
acrlInviteMailerCheck(preg_match('/"(?:outcome|code)"\s*:\s*"(?:sent|failed|invalid)"/', $infoLogText) === 1, 'Mail logs use a fixed outcome code.');
@unlink($errorLogPath);
@unlink($infoLogPath);

$userSource = (string)file_get_contents(__DIR__ . '/../models/User.php');
$notifyPosition = strpos($userSource, 'public static function notifyAdminsOfNewUser');
$notifySource = $notifyPosition === false ? '' : substr($userSource, $notifyPosition, strpos($userSource, "\n    }", $notifyPosition) - $notifyPosition + 6);
acrlInviteMailerCheck($notifyPosition !== false && strpos($notifySource, "@mail(") !== false && strpos($notifySource, 'New-user notification sent to') !== false, 'User::notifyAdminsOfNewUser remains the existing mail() implementation.');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL invite mailer checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}

echo "PASS: ACRL invite mail validation, delivery result, and log redaction\n";
