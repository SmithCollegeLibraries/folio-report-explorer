<?php

require __DIR__ . '/Ops006TestSupport.php';

exec('command -v jq >/dev/null 2>&1', $jqOutput, $jqStatus);
if ($jqStatus !== 0) {
    fwrite(STDOUT, "SKIP: jq is required for report_shadow_metrics.sh\n");
    exit(0);
}

ops006Test('configured redacting telemetry target emits parser-readable telemetry with a redacted error', function () {
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

    $directory = ops006TempDir('ops006-configured-telemetry-');
    list($logger) = ops006CreateLogger($targets, $directory);
    $payload = [
        'event' => 'nl2sql.shadow_compare',
        'timestamp' => '2026-10-30T12:00:00+00:00',
        'promptFingerprint' => 'synthetic-fingerprint',
        'sqlHashMatch' => true,
        'error' => 'x-api-key: SYNTHETIC_TELEMETRY_ERROR_KEY_123456',
    ];
    $logger->log('NL2SQL telemetry: ' . json_encode($payload), \yii\log\Logger::LEVEL_INFO, 'nl2sql.telemetry');
    $logger->flush(true);

    $logPath = $directory . DIRECTORY_SEPARATOR . 'target-' . $targetIndex . '.log';
    $contents = file_get_contents($logPath);
    ops006Assert(is_string($contents), 'The configured redacting target should write telemetry.');
    ops006Assert(strpos($contents, 'SYNTHETIC_TELEMETRY_ERROR_KEY_123456') === false, 'The telemetry error key should be redacted in the emitted log.');
    ops006Assert(strpos($contents, '[REDACTED]') !== false, 'The emitted telemetry should preserve a redaction marker.');
    ops006Assert(preg_match('/NL2SQL telemetry: (\{.*\})$/m', trim($contents), $matches) === 1, 'The configured target should preserve a complete telemetry JSON line.');
    $redactedPayload = json_decode($matches[1], true);
    ops006Assert(is_array($redactedPayload), 'The redacted telemetry JSON should remain parseable.');
    ops006AssertSame(JSON_ERROR_NONE, json_last_error(), 'The redacted telemetry JSON should remain valid.');

    $date = date('Y-m-d');
    $reportDirectory = $directory;
    $script = dirname(__DIR__, 2) . '/scripts/nl2sql/report_shadow_metrics.sh';
    $command = 'bash ' . escapeshellarg($script) . ' ' . escapeshellarg($date)
        . ' ' . escapeshellarg($logPath) . ' ' . escapeshellarg($reportDirectory);
    exec($command . ' 2>&1', $output, $status);
    ops006AssertSame(0, $status, 'The shadow metrics parser should accept telemetry written by the configured target: ' . implode("\n", $output));
    $reportPath = $reportDirectory . DIRECTORY_SEPARATOR . $date . '_nl2sql-008-shadow-metrics.md';
    $report = file_get_contents($reportPath);
    ops006Assert(is_string($report) && strpos($report, '- shadow_compare events: 1') !== false, 'The report should count the emitted redacted telemetry event.');
});

exit(ops006Finish());
