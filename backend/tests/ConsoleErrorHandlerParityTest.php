<?php

$ops006Mode = $argv[1] ?? '';
if (strpos($ops006Mode, '--child-render-') === 0) {
    $ops006Debug = ($argv[3] ?? '') === 'on';
    define('YII_ENV', $ops006Debug ? 'dev' : 'prod');
    define('YII_DEBUG', $ops006Debug);
}

require __DIR__ . '/Ops006TestSupport.php';

if (strpos($ops006Mode, '--child-render-') === 0) {
    $handlerClass = ($argv[2] ?? '') === 'yii'
        ? 'yii\\console\\ErrorHandler'
        : 'app\\components\\RedactingConsoleErrorHandler';
    $exceptionKind = $argv[4] ?? '';
    if ($exceptionKind === 'base-user') {
        $exception = new \yii\base\UserException('synthetic user-facing message');
    } elseif ($exceptionKind === 'console') {
        $exception = new \yii\console\Exception('synthetic console message');
    } elseif ($exceptionKind === 'quoted-secret') {
        $exception = new \RuntimeException('{"error":"OPENAI_API_KEY: SYNTHETIC_CONSOLE_QUOTED_KEY_123456"}');
    } else {
        $exception = new \RuntimeException('synthetic internal message');
    }
    new \yii\console\Application([
        'id' => 'ops006-render-parity',
        'basePath' => dirname(__DIR__),
        'controllerNamespace' => 'app\\commands',
        'components' => ['log' => ['targets' => []]],
    ]);
    $handler = new $handlerClass();
    $method = new \ReflectionMethod($handler, 'renderException');
    $method->setAccessible(true);
    $method->invoke($handler, $exception);
    exit(0);
}

function ops006AssertConsoleRenderParity($debugMode, $exceptionKind)
{
    list($yiiStatus, $yiiStdout, $yiiStderr) = ops006RunChild(['--child-render-parity', 'yii', $debugMode, $exceptionKind]);
    list($redactingStatus, $redactingStdout, $redactingStderr) = ops006RunChild(['--child-render-parity', 'redacting', $debugMode, $exceptionKind]);
    ops006AssertSame(0, $yiiStatus, 'Yii console error handler rendering should complete.');
    ops006AssertSame(0, $redactingStatus, 'Redacting console error handler rendering should complete.');
    ops006AssertSame($yiiStdout, $redactingStdout, 'The redacting handler should preserve Yii stdout rendering.');
    ops006AssertSame($yiiStderr, $redactingStderr, 'The redacting handler should preserve Yii stderr rendering for non-secret messages.');
}

ops006Test('base UserException debug rendering matches Yii and retains its trace', function () {
    ops006AssertConsoleRenderParity('on', 'base-user');
});

ops006Test('base UserException production rendering matches Yii', function () {
    ops006AssertConsoleRenderParity('off', 'base-user');
});

ops006Test('yii console Exception rendering matches Yii in debug mode', function () {
    ops006AssertConsoleRenderParity('on', 'console');
});

ops006Test('ordinary Exception rendering matches Yii in debug mode', function () {
    ops006AssertConsoleRenderParity('on', 'runtime');
});

ops006Test('debug console rendering redacts assignments inside quoted exception messages', function () {
    list($status, , $stderr) = ops006RunChild(['--child-render-parity', 'redacting', 'on', 'quoted-secret']);
    ops006AssertSame(0, $status, 'The redacting console handler should render the exception.');
    ops006Assert(strpos($stderr, 'SYNTHETIC_CONSOLE_QUOTED_KEY_123456') === false, 'The quoted exception key should not reach stderr.');
    ops006Assert(strpos($stderr, '[REDACTED]') !== false, 'The rendered exception should show a redaction marker.');
});

exit(ops006Finish());
