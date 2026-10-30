<?php

require __DIR__ . '/Ops006TestSupport.php';

$redactingTargetClass = 'app\\components\\RedactingFileTarget';
$webConfig = ops006LoadConfig('web');
$consoleConfig = ops006LoadConfig('console');
$webTargets = $webConfig['components']['log']['targets'] ?? [];
$consoleTargets = $consoleConfig['components']['log']['targets'] ?? [];

ops006Test('every web and console file target is redacting with logVars disabled', function () use ($webTargets, $consoleTargets, $redactingTargetClass) {
    ops006Assert(count($webTargets) > 0 && count($consoleTargets) > 0, 'Both entry configurations should define file log targets.');
    foreach (array_merge($webTargets, $consoleTargets) as $target) {
        ops006AssertSame($redactingTargetClass, $target['class'] ?? null, 'Every configured file target should use the redacting Yii target.');
        ops006AssertSame([], $target['logVars'] ?? null, 'Every file target should keep logVars disabled.');
    }
});

ops006Test('web and console select redacting error-handler subclasses', function () use ($webConfig, $consoleConfig) {
    ops006AssertSame('app\\components\\RedactingWebErrorHandler', $webConfig['components']['errorHandler']['class'] ?? null, 'The web app should use its redacting fallback handler.');
    ops006AssertSame('app\\components\\RedactingConsoleErrorHandler', $consoleConfig['components']['errorHandler']['class'] ?? null, 'The console app should use its redacting fallback handler.');
});

function ops006ConsoleLogFixture()
{
    $config = ops006LoadConfig('console');
    $targets = $config['components']['log']['targets'] ?? [];
    $directory = ops006TempDir('ops006-console-log-');
    list($logger) = ops006CreateLogger($targets, $directory);

    $logger->log('ordinary info marker', \yii\log\Logger::LEVEL_INFO, 'application');
    $logger->log('http info marker x-api-key: SYNTHETIC_CONFIG_INFO_KEY_123456', \yii\log\Logger::LEVEL_INFO, 'yii\\httpclient\\CurlTransport::send');
    $logger->log('http warning marker x-api-key: SYNTHETIC_CONFIG_WARNING_KEY_123456', \yii\log\Logger::LEVEL_WARNING, 'yii\\httpclient\\CurlTransport::send');
    $logger->log('http error marker x-api-key: SYNTHETIC_CONFIG_ERROR_KEY_123456', \yii\log\Logger::LEVEL_ERROR, 'yii\\httpclient\\CurlTransport::send');
    $logger->log('profile marker', \yii\log\Logger::LEVEL_PROFILE, 'application');
    $logger->flush(true);

    $contents = '';
    foreach (glob($directory . DIRECTORY_SEPARATOR . 'target-*.log') as $path) {
        $contents .= file_get_contents($path);
    }
    return $contents;
}

ops006Test('console info target excludes yii httpclient categories', function () use ($consoleTargets) {
    $matches = [];
    foreach ($consoleTargets as $target) {
        if (in_array('info', $target['levels'] ?? [], true)) {
            $matches[] = $target;
        }
    }
    ops006Assert(count($matches) === 1, 'Console info should be routed through one info target.');
    ops006AssertSame(['yii\\httpclient\\*'], $matches[0]['except'] ?? null, 'The console info target should exclude all Yii httpclient categories.');
});

ops006Test('console httpclient info is excluded by real Logger routing', function () {
    $contents = ops006ConsoleLogFixture();
    ops006Assert(strpos($contents, 'http info marker') === false, 'An httpclient info message should not reach a console file target.');
    ops006Assert(strpos($contents, 'SYNTHETIC_CONFIG_INFO_KEY_123456') === false, 'The excluded httpclient info message should not appear in a log.');
});

ops006Test('console warning and error targets retain all categories', function () use ($consoleTargets) {
    $matches = [];
    foreach ($consoleTargets as $target) {
        if (in_array('warning', $target['levels'] ?? [], true) || in_array('error', $target['levels'] ?? [], true)) {
            $matches[] = $target;
        }
    }
    ops006Assert(count($matches) === 1, 'Warnings and errors should share one target.');
    ops006AssertSame(['error', 'warning'], $matches[0]['levels'] ?? null, 'The warning/error target should retain both existing levels.');
    ops006AssertSame([], $matches[0]['categories'] ?? [], 'The warning/error target should continue to accept all categories.');
    ops006AssertSame([], $matches[0]['except'] ?? [], 'The warning/error target should not exclude httpclient categories.');
});

ops006Test('console httpclient warnings and errors are retained and redacted', function () {
    $contents = ops006ConsoleLogFixture();
    ops006Assert(strpos($contents, 'http warning marker') !== false, 'The httpclient warning should remain logged.');
    ops006Assert(strpos($contents, 'http error marker') !== false, 'The httpclient error should remain logged.');
    ops006Assert(strpos($contents, 'SYNTHETIC_CONFIG_WARNING_KEY_123456') === false, 'The httpclient warning secret should be redacted.');
    ops006Assert(strpos($contents, 'SYNTHETIC_CONFIG_ERROR_KEY_123456') === false, 'The httpclient error secret should be redacted.');
    ops006Assert(substr_count($contents, '[REDACTED]') >= 2, 'The retained httpclient messages should include redaction markers.');
});

ops006Test('console delivers other info exactly once and keeps profile excluded', function () {
    $contents = ops006ConsoleLogFixture();
    ops006AssertSame(1, substr_count($contents, 'ordinary info marker'), 'Other info messages should be delivered exactly once.');
    ops006Assert(strpos($contents, 'profile marker') === false, 'Profile level messages should remain excluded.');
});

exit(ops006Finish());
