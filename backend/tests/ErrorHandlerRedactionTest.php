<?php

$ops006Mode = $argv[1] ?? '';
if (strpos($ops006Mode, '--child-') === 0) {
    $ops006Debug = strpos($ops006Mode, '-on') !== false;
    define('YII_ENV', $ops006Debug ? 'dev' : 'prod');
    define('YII_DEBUG', $ops006Debug);
}

require __DIR__ . '/Ops006TestSupport.php';

function ops006RunErrorHandlerChild($mode, array $secrets, $fallback = false)
{
    $arguments = [$mode];
    $errorLog = null;
    if ($fallback) {
        $directory = ops006TempDir('ops006-fallback-');
        $errorLog = $directory . DIRECTORY_SEPARATOR . 'php-error.log';
        $arguments[] = $errorLog;
    }

    list($status, $stdout, $stderr) = ops006RunChild($arguments);
    $fileLog = $errorLog !== null && file_exists($errorLog) ? file_get_contents($errorLog) : '';
    $combined = $stdout . $stderr . $fileLog;
    ops006Assert($status !== 78 && strpos($combined, 'MISSING_CLASS:') === false, 'The configured redacting error handler is not implemented yet.');
    foreach ($secrets as $secret) {
        ops006Assert(strpos($combined, $secret) === false, 'A synthetic secret or server-context value reached an error sink.');
    }
    ops006Assert(strpos($combined, '[REDACTED]') !== false, 'The captured error output should contain a redaction marker.');
    if ($fallback) {
        ops006Assert(strpos($combined, '$_SERVER =') === false, 'The fallback handler must not append $_SERVER in either debug mode.');
        ops006Assert(is_string($fileLog) && $fileLog !== '', 'The fallback handler should write its sanitized diagnostic to the isolated PHP error log.');
    } else {
        ops006AssertSame(0, $status, 'renderException should return after writing the captured console exception.');
        ops006Assert(strpos($stderr, '[REDACTED]') !== false, 'Console renderException should write redacted text to captured stderr.');
    }
}

if (strpos($ops006Mode, '--child-') === 0) {
    $handlerClass = null;
    $reflectionMethod = null;
    $exception = null;

    if (strpos($ops006Mode, '--child-render-') === 0) {
        $handlerClass = 'app\\components\\RedactingConsoleErrorHandler';
        $reflectionMethod = 'renderException';
        if ($ops006Mode === '--child-render-on-exception') {
            $exception = new \RuntimeException(
                'Authorization: Custom SYNTHETIC_CONSOLE_RENDER_OUTER_123456',
                0,
                new \RuntimeException('x-api-key: SYNTHETIC_CONSOLE_RENDER_PREVIOUS_123456')
            );
        } elseif ($ops006Mode === '--child-render-on-error' || $ops006Mode === '--child-render-off-error') {
            $exception = new \Error('x-goog-api-key: SYNTHETIC_CONSOLE_RENDER_ERROR_123456');
        } else {
            $exception = new \RuntimeException('api_key=SYNTHETIC_CONSOLE_RENDER_OFF_123456');
        }
    } else {
        $handlerKind = strpos($ops006Mode, 'fallback-web-') !== false ? 'Web' : 'Console';
        $handlerClass = 'app\\components\\Redacting' . $handlerKind . 'ErrorHandler';
        $reflectionMethod = 'handleFallbackExceptionMessage';
        ini_set('error_log', $argv[2] ?? '/dev/null');
        $_SERVER['OPS006_SYNTHETIC_API_KEY'] = 'SYNTHETIC_SERVER_CONTEXT_KEY_123456';
        putenv('OPS006_SYNTHETIC_TOKEN=SYNTHETIC_ENV_CONTEXT_TOKEN_123456');
        $exception = new \RuntimeException('x-api-key: SYNTHETIC_FALLBACK_HANDLER_KEY_123456');
        $previousException = new \RuntimeException('Authorization: Bearer SYNTHETIC_FALLBACK_PREVIOUS_KEY_123456');
    }

    if (!class_exists($handlerClass)) {
        fwrite(STDERR, 'MISSING_CLASS: ' . $handlerClass . "\n");
        exit(78);
    }

    new \yii\console\Application([
        'id' => 'ops006-error-handler-child',
        'basePath' => dirname(__DIR__),
        'controllerNamespace' => 'app\\commands',
        'components' => [
            'log' => ['targets' => []],
        ],
    ]);
    $handler = new $handlerClass();
    $method = new \ReflectionMethod($handler, $reflectionMethod);
    $method->setAccessible(true);
    if ($reflectionMethod === 'renderException') {
        $method->invoke($handler, $exception);
        exit(0);
    }

    $method->invoke($handler, $exception, $previousException);
    exit(0);
}

$renderSecretSet = [
    'SYNTHETIC_CONSOLE_RENDER_OUTER_123456',
    'SYNTHETIC_CONSOLE_RENDER_PREVIOUS_123456',
];
ops006Test('console renderException redacts an Exception and chained previous exception in debug mode', function () use ($renderSecretSet) {
    ops006RunErrorHandlerChild('--child-render-on-exception', $renderSecretSet);
});

ops006Test('console renderException redacts an Error in debug mode', function () {
    ops006RunErrorHandlerChild('--child-render-on-error', ['SYNTHETIC_CONSOLE_RENDER_ERROR_123456']);
});

ops006Test('console renderException redacts an Exception in production mode', function () {
    ops006RunErrorHandlerChild('--child-render-off-exception', ['SYNTHETIC_CONSOLE_RENDER_OFF_123456']);
});

ops006Test('console renderException redacts an Error in production mode', function () {
    ops006RunErrorHandlerChild('--child-render-off-error', ['SYNTHETIC_CONSOLE_RENDER_ERROR_123456']);
});

$fallbackSecrets = [
    'SYNTHETIC_FALLBACK_HANDLER_KEY_123456',
    'SYNTHETIC_FALLBACK_PREVIOUS_KEY_123456',
    'SYNTHETIC_SERVER_CONTEXT_KEY_123456',
    'SYNTHETIC_ENV_CONTEXT_TOKEN_123456',
];
foreach (['console', 'web'] as $handlerKind) {
    foreach (['on', 'off'] as $debugMode) {
        $mode = '--child-fallback-' . $handlerKind . '-' . $debugMode;
        ops006Test("{$handlerKind} fallback handler redacts and omits server context with debug {$debugMode}", function () use ($mode, $fallbackSecrets) {
            ops006RunErrorHandlerChild($mode, $fallbackSecrets, true);
        });
    }
}

exit(ops006Finish());
