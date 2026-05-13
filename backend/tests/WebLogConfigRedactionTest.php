<?php

$configPath = __DIR__ . '/../config/web.php';

if (!file_exists($configPath)) {
    fwrite(STDERR, "web.php is missing at {$configPath}\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
    }
}

if (!defined('YII_DEBUG')) {
    define('YII_DEBUG', false);
}

$dbStubPath = tempnam(sys_get_temp_dir(), 'fre-db-stub-');
$dbFolioStubPath = tempnam(sys_get_temp_dir(), 'fre-folio-db-stub-');
$paramsStubPath = tempnam(sys_get_temp_dir(), 'fre-params-stub-');

if ($dbStubPath === false || $dbFolioStubPath === false || $paramsStubPath === false) {
    fwrite(STDERR, "Failed to create temporary config stubs.\n");
    exit(1);
}

file_put_contents($dbStubPath, "<?php\nreturn ['class' => 'db-stub'];\n");
file_put_contents($dbFolioStubPath, "<?php\nreturn ['class' => 'folio-db-stub'];\n");
file_put_contents($paramsStubPath, "<?php\nreturn [];\n");

register_shutdown_function(static function () use ($dbStubPath, $dbFolioStubPath, $paramsStubPath): void {
    foreach ([$dbStubPath, $dbFolioStubPath, $paramsStubPath] as $path) {
        if (is_string($path) && file_exists($path)) {
            unlink($path);
        }
    }
});

$configSource = file_get_contents($configPath);
if ($configSource === false) {
    fwrite(STDERR, "Failed to read {$configPath}\n");
    exit(1);
}

$configSource = str_replace("require __DIR__ . '/db.php';", "require '{$dbStubPath}';", $configSource);
$configSource = str_replace("require __DIR__ . '/db-folio.php';", "require '{$dbFolioStubPath}';", $configSource);
$configSource = str_replace("require __DIR__ . '/params.php';", "require '{$paramsStubPath}';", $configSource);
$configSource = str_replace("yii\\web\\Response::FORMAT_JSON", "'json'", $configSource);

$tempConfigPath = tempnam(sys_get_temp_dir(), 'fre-web-config-');
if ($tempConfigPath === false) {
    fwrite(STDERR, "Failed to create temporary web config file.\n");
    exit(1);
}
file_put_contents($tempConfigPath, $configSource);

register_shutdown_function(static function () use ($tempConfigPath): void {
    if (is_string($tempConfigPath) && file_exists($tempConfigPath)) {
        unlink($tempConfigPath);
    }
});

$config = require $tempConfigPath;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$targets = $config['components']['log']['targets'] ?? null;
if (!is_array($targets) || $targets === []) {
    fwrite(STDERR, "Web config should define at least one log target.\n");
    exit(1);
}

$fileTarget = $targets[0] ?? null;
if (!is_array($fileTarget)) {
    fwrite(STDERR, "The primary web log target should be an array configuration.\n");
    exit(1);
}

assertSameValue(
    [],
    $fileTarget['logVars'] ?? null,
    'The primary web log target should disable Yii request-context logging so warning/error entries do not dump cookies, environment variables, or API keys into app.log.'
);

$telemetryTarget = null;
foreach ($targets as $target) {
    if (!is_array($target)) {
        continue;
    }

    $levels = $target['levels'] ?? [];
    $categories = $target['categories'] ?? [];

    if (in_array('info', $levels, true) && in_array('nl2sql.telemetry', $categories, true)) {
        $telemetryTarget = $target;
        break;
    }
}

if (!is_array($telemetryTarget)) {
    fwrite(STDERR, "Web config should route info-level nl2sql.telemetry entries to a file target so shadow comparison metrics reach app.log.\n");
    exit(1);
}

assertSameValue(
    [],
    $telemetryTarget['logVars'] ?? null,
    'The info-level nl2sql.telemetry target should also disable request-context logging so successful shadow metrics do not reintroduce secret leakage.'
);

fwrite(STDOUT, "Web log config redaction test passed\n");