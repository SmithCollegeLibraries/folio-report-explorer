<?php

$ops006Mode = $argv[1] ?? '';
if ($ops006Mode === '--child-worker') {
    require __DIR__ . '/Ops006TestSupport.php';
    $className = 'app\\services\\WorkerOutput';
    if (!class_exists($className)) {
        fwrite(STDERR, 'MISSING_CLASS: ' . $className . "\n");
        exit(78);
    }
    $stream = ($argv[2] ?? '') === 'stderr' ? STDERR : STDOUT;
    $message = ($argv[2] ?? '') . ' worker marker x-api-key: SYNTHETIC_WORKER_OUTPUT_KEY_123456';
    call_user_func([$className, 'write'], $message . "\n", $stream);
    exit(0);
}

require __DIR__ . '/Ops006TestSupport.php';

function ops006AssertWorkerStream($streamName)
{
    list($status, $stdout, $stderr) = ops006RunChild(['--child-worker', $streamName]);
    $combined = $stdout . $stderr;
    ops006Assert($status !== 78 && strpos($stderr, 'MISSING_CLASS:') === false, 'The shared WorkerOutput helper is not implemented yet.');
    ops006AssertSame(0, $status, 'The isolated worker print process should complete successfully.');
    ops006Assert(strpos($combined, $streamName . ' worker marker') !== false, 'The worker helper should preserve ordinary output text.');
    ops006Assert(strpos($combined, 'SYNTHETIC_WORKER_OUTPUT_KEY_123456') === false, 'The worker helper should redact before printing.');
    ops006Assert(strpos($combined, '[REDACTED]') !== false, 'The worker helper should print a redaction marker.');
    if ($streamName === 'stdout') {
        ops006Assert(strpos($stdout, '[REDACTED]') !== false && $stderr === '', 'The stdout helper should write only to captured stdout.');
    } else {
        ops006Assert(strpos($stderr, '[REDACTED]') !== false && $stdout === '', 'The stderr helper should write only to captured stderr.');
    }
}

ops006Test('shared worker print helper redacts stdout', function () {
    ops006AssertWorkerStream('stdout');
});

ops006Test('shared worker print helper redacts stderr', function () {
    ops006AssertWorkerStream('stderr');
});

exit(ops006Finish());
