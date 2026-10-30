<?php

$ops006Mode = $argv[1] ?? '';
if ($ops006Mode === '--child-uncaught-warning') {
    define('YII_ENV', 'prod');
    define('YII_DEBUG', false);
}

require __DIR__ . '/Ops006TestSupport.php';

if ($ops006Mode === '--child-uncaught-warning') {
    $logPath = $argv[2];
    new \yii\console\Application([
        'id' => 'ops006-uncaught-warning',
        'basePath' => dirname(__DIR__),
        'controllerNamespace' => 'app\\commands',
        'bootstrap' => ['log'],
        'components' => [
            'log' => [
                'targets' => [[
                    'class' => 'app\\components\\RedactingFileTarget',
                    'levels' => ['error', 'warning'],
                    'logVars' => [],
                    'logFile' => $logPath,
                    'enableRotation' => false,
                ]],
            ],
            'errorHandler' => [
                'class' => 'app\\components\\RedactingConsoleErrorHandler',
            ],
        ],
    ]);
    \Yii::$app->getErrorHandler()->register();
    trigger_error('Uncaught warning x-api-key: SYNTHETIC_UNCAUGHT_WARNING_123456', E_USER_WARNING);
    exit(0);
}

ops006Test('uncaught PHP warning follows Yii logging once with its exception category', function () {
    $directory = ops006TempDir('ops006-uncaught-warning-');
    $logPath = $directory . DIRECTORY_SEPARATOR . 'warning.log';
    list($status, , $stderr) = ops006RunChild(['--child-uncaught-warning', $logPath]);
    $contents = file_get_contents($logPath);
    ops006AssertSame(1, $status, 'An uncaught warning should be handled as an exception.');
    ops006Assert(is_string($contents), 'The isolated redacting target should write the error log.');
    ops006AssertSame(1, substr_count($contents, '[yii\\base\\ErrorException:512]'), 'Yii should log the uncaught warning once under its normal exception category.');
    ops006AssertSame(1, substr_count($contents, '[REDACTED]'), 'The one logged exception should contain one redaction marker.');
    ops006Assert(strpos($contents, 'SYNTHETIC_UNCAUGHT_WARNING_123456') === false, 'The warning key should not reach the log.');
    ops006Assert(strpos($stderr, 'SYNTHETIC_UNCAUGHT_WARNING_123456') === false, 'The console exception output should also redact the warning key.');
});

exit(ops006Finish());
