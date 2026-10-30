<?php

require __DIR__ . '/Ops006TestSupport.php';
require_once __DIR__ . '/../config/debug_mode.php';

$cases = [
    ['unset debug in dev enables debug', false, 'dev', true],
    ['unset debug in prod disables debug', false, 'prod', false],
    ['true debug in prod enables debug', 'true', 'prod', true],
    ['false debug in dev disables debug', 'false', 'dev', false],
];

foreach ($cases as $case) {
    list($name, $debugValue, $environmentValue, $expected) = $case;
    ops006Test($name, function () use ($debugValue, $environmentValue, $expected) {
        ops006AssertSame($expected, resolveYiiDebugMode($debugValue, $environmentValue), 'Debug mode resolution returned the wrong value.');
    });
}

ops006Test('both entry points use the shared debug resolver', function () {
    foreach ([__DIR__ . '/../web/index.php', __DIR__ . '/../yii'] as $entryPoint) {
        $source = file_get_contents($entryPoint);
        ops006Assert(is_string($source), 'Both Yii entry points should be readable.');
        ops006Assert(strpos($source, 'resolveYiiDebugMode(') !== false, 'Each entry point should resolve debug through the shared helper.');
    }
});

ops006Test('debug-off entry points keep PHP error reporting enabled', function () {
    foreach ([__DIR__ . '/../web/index.php', __DIR__ . '/../yii'] as $entryPoint) {
        $source = file_get_contents($entryPoint);
        ops006Assert(is_string($source), 'Both Yii entry points should be readable.');
        ops006Assert(preg_match('/error_reporting\s*\(\s*0\s*\)|ini_set\s*\(\s*[\'\"]error_reporting[\'\"]\s*,\s*0\s*\)/', $source) !== 1, 'Debug-off startup must not disable PHP error reporting.');
    }
});

exit(ops006Finish());
