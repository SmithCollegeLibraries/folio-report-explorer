<?php

define('YII_ENV', 'prod');
define('YII_DEBUG', false);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/Ops006TestSupport.php';

ops006Test('production PHP warnings remain exceptions and are logged redacted', function () {
    ops006RequireClass('app\\components\\RedactingFileTarget');
    ops006RequireClass('app\\components\\RedactingConsoleErrorHandler');

    $directory = ops006TempDir('ops006-warning-');
    $logPath = $directory . DIRECTORY_SEPARATOR . 'warning.log';
    new \yii\console\Application([
        'id' => 'ops006-production-warning-test',
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

    $handler = \Yii::$app->getErrorHandler();
    $handler->register();
    $caught = null;
    $warningMaskEnabled = (error_reporting() & E_USER_WARNING) !== 0;
    try {
        trigger_error('PHP warning marker x-api-key: SYNTHETIC_PRODUCTION_WARNING_KEY_123456', E_USER_WARNING);
    } catch (\yii\base\ErrorException $exception) {
        $caught = $exception;
    } finally {
        $handler->unregister();
    }

    \Yii::getLogger()->flush(true);
    $contents = file_get_contents($logPath);
    ops006Assert($warningMaskEnabled && (error_reporting() & E_USER_WARNING) !== 0, 'Production debug-off mode should leave PHP warning reporting enabled.');
    ops006Assert($caught instanceof \yii\base\ErrorException, 'Yii should continue converting the warning into an ErrorException.');
    ops006Assert(is_string($contents) && strpos($contents, 'PHP warning marker') !== false, 'The warning should still reach the application log.');
    ops006Assert(strpos($contents, 'SYNTHETIC_PRODUCTION_WARNING_KEY_123456') === false, 'The warning API key should not reach the log.');
    ops006Assert(strpos($contents, '[REDACTED]') !== false, 'The logged warning should carry a redaction marker.');
});

exit(ops006Finish());
