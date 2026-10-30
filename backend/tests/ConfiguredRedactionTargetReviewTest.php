<?php

require __DIR__ . '/Ops006TestSupport.php';

class Ops006PrivateHeadersLogFixture
{
    private $headers;
    protected $context;

    public function __construct(array $headers, $context)
    {
        $this->headers = $headers;
        $this->context = $context;
    }
}

ops006Test('configured telemetry target redacts assignments inside quoted JSON errors', function () {
    $config = ops006LoadConfig('web');
    $targets = $config['components']['log']['targets'] ?? [];
    $targetIndex = null;
    foreach ($targets as $index => $target) {
        if (in_array('info', $target['levels'] ?? [], true)
            && in_array('nl2sql.telemetry', $target['categories'] ?? [], true)) {
            $targetIndex = $index;
            break;
        }
    }
    ops006Assert($targetIndex !== null, 'Web config should contain the telemetry target.');

    $directory = ops006TempDir('ops006-quoted-target-');
    list($logger) = ops006CreateLogger($targets, $directory);
    $payload = [
        'event' => 'nl2sql.shadow_compare',
        'error' => 'OPENAI_API_KEY: SYNTHETIC_CONFIGURED_QUOTED_KEY_123456',
    ];
    $logger->log('NL2SQL telemetry: ' . json_encode($payload), \yii\log\Logger::LEVEL_INFO, 'nl2sql.telemetry');
    $logger->flush(true);

    $contents = file_get_contents($directory . DIRECTORY_SEPARATOR . 'target-' . $targetIndex . '.log');
    ops006Assert(is_string($contents), 'The configured telemetry target should write a log line.');
    ops006Assert(strpos($contents, 'SYNTHETIC_CONFIGURED_QUOTED_KEY_123456') === false, 'The configured target should redact the quoted assignment.');
    ops006Assert(preg_match('/NL2SQL telemetry: (\{.*\})$/m', trim($contents), $matches) === 1, 'The configured target should preserve the telemetry JSON line.');
    $decoded = json_decode($matches[1], true);
    ops006AssertSame(JSON_ERROR_NONE, json_last_error(), 'Redaction should leave the configured telemetry JSON valid.');
    ops006AssertSame('OPENAI_API_KEY: [REDACTED]', $decoded['error'] ?? null, 'The configured target should preserve the assignment label and replace its value.');
});

ops006Test('configured target redacts private serialized headers on a real object', function () {
    $config = ops006LoadConfig('web');
    $targets = $config['components']['log']['targets'] ?? [];
    $targetIndex = null;
    foreach ($targets as $index => $target) {
        if (in_array('error', $target['levels'] ?? [], true)) {
            $targetIndex = $index;
            break;
        }
    }
    ops006Assert($targetIndex !== null, 'Web config should contain an error target.');

    $secret = 'SYNTHETIC_PRIVATE_HEADER_TARGET_KEY_123456';
    $message = new Ops006PrivateHeadersLogFixture(
        ['x-api-key' => $secret],
        'SYNTHETIC_PROTECTED_CONTEXT_123456'
    );
    ops006Assert(strpos(serialize($message), "\0") !== false, 'The fixture should serialize private/protected property markers.');

    $directory = ops006TempDir('ops006-private-target-');
    list($logger) = ops006CreateLogger($targets, $directory);
    $logger->log($message, \yii\log\Logger::LEVEL_ERROR, 'application');
    $logger->flush(true);

    $contents = file_get_contents($directory . DIRECTORY_SEPARATOR . 'target-' . $targetIndex . '.log');
    ops006Assert(is_string($contents), 'The configured error target should write the object message.');
    ops006Assert(strpos($contents, $secret) === false, 'The serialized private x-api-key property should be redacted.');
    ops006Assert(strpos($contents, '[REDACTED]') !== false, 'The configured target should show a redaction marker.');
});

exit(ops006Finish());
