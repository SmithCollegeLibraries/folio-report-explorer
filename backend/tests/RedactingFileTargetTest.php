<?php

require __DIR__ . '/Ops006TestSupport.php';

function ops006WriteTargetMessage($message)
{
    $className = 'app\\components\\RedactingFileTarget';
    ops006RequireClass($className);
    ops006Assert(is_subclass_of($className, \yii\log\FileTarget::class), 'The redacting target should extend Yii FileTarget.');

    $directory = ops006TempDir('ops006-target-');
    $path = $directory . DIRECTORY_SEPARATOR . 'messages.log';
    $target = new $className([
        'levels' => ['error'],
        'logVars' => [],
        'logFile' => $path,
        'enableRotation' => false,
    ]);
    $logger = new \yii\log\Logger();
    new \yii\log\Dispatcher([
        'logger' => $logger,
        'targets' => [$target],
    ]);

    $logger->log($message, \yii\log\Logger::LEVEL_ERROR, 'ops006.file-target-test');
    $logger->flush(true);

    $contents = file_get_contents($path);
    ops006Assert($contents !== false, 'The real Yii FileTarget should write the test message to the temporary log file.');
    return $contents;
}

function ops006AssertFileTargetRedacted($message, $secret, $label)
{
    $contents = ops006WriteTargetMessage($message);
    ops006Assert(strpos($contents, $secret) === false, "{$label}: the synthetic secret reached the file.");
    ops006Assert(strpos($contents, '[REDACTED]') !== false, "{$label}: the file should contain the redaction marker.");
}

ops006Test('real FileTarget redacts an Exception message', function () {
    ops006AssertFileTargetRedacted(
        new \RuntimeException('Authorization: Custom SYNTHETIC_FILE_EXCEPTION_KEY_271828'),
        'SYNTHETIC_FILE_EXCEPTION_KEY_271828',
        'Exception formatting'
    );
});

ops006Test('real FileTarget redacts an Error message', function () {
    ops006AssertFileTargetRedacted(
        new \Error('x-goog-api-key: SYNTHETIC_FILE_ERROR_KEY_271828'),
        'SYNTHETIC_FILE_ERROR_KEY_271828',
        'Error formatting'
    );
});

ops006Test('real FileTarget redacts a nested array message', function () {
    ops006AssertFileTargetRedacted(
        ['request' => ['Authorization' => 'Bearer SYNTHETIC_FILE_ARRAY_KEY_271828'], 'status' => 'kept'],
        'SYNTHETIC_FILE_ARRAY_KEY_271828',
        'nested array formatting'
    );
});

ops006Test('real FileTarget redacts an object message', function () {
    ops006AssertFileTargetRedacted(
        (object)['x-api-key' => 'SYNTHETIC_FILE_OBJECT_KEY_271828', 'status' => 'kept'],
        'SYNTHETIC_FILE_OBJECT_KEY_271828',
        'object formatting'
    );
});

exit(ops006Finish());
