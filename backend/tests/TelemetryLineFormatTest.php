<?php

require __DIR__ . '/Ops006TestSupport.php';

ops006Test('real Yii FileTarget preserves the NL2SQL telemetry line format', function () {
    $directory = ops006TempDir('ops006-telemetry-format-');
    $path = $directory . DIRECTORY_SEPARATOR . 'telemetry.log';
    $payload = [
        'event' => 'nl2sql.shadow_compare',
        'timestamp' => '2026-10-30T12:00:00+00:00',
        'promptFingerprint' => 'synthetic-fingerprint',
        'sqlHashMatch' => true,
    ];
    $target = new \yii\log\FileTarget([
        'levels' => ['info'],
        'categories' => ['nl2sql.telemetry'],
        'logVars' => [],
        'logFile' => $path,
        'enableRotation' => false,
        'prefix' => function () {
            return '';
        },
    ]);
    $logger = new \yii\log\Logger();
    new \yii\log\Dispatcher([
        'logger' => $logger,
        'targets' => [$target],
    ]);
    $logger->log('NL2SQL telemetry: ' . json_encode($payload), \yii\log\Logger::LEVEL_INFO, 'nl2sql.telemetry');
    $logger->flush(true);

    $contents = file_get_contents($path);
    ops006Assert(is_string($contents), 'The real FileTarget should write a telemetry line.');
    ops006Assert(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[info\]\[nl2sql\.telemetry\] NL2SQL telemetry: (\{.*\})$/m', trim($contents), $matches) === 1, 'The telemetry prefix, category, and JSON payload format should remain unchanged.');
    ops006AssertSame($payload, json_decode($matches[1], true), 'The telemetry JSON payload should remain parseable and unchanged.');
});

exit(ops006Finish());
