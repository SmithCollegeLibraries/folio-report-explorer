<?php

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', false);

$GLOBALS['ops006TestFailures'] = 0;

function ops006Test($name, callable $test)
{
    try {
        $test();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (\Throwable $error) {
        $GLOBALS['ops006TestFailures']++;
        fwrite(STDERR, "FAIL: {$name}\n  " . get_class($error) . ': ' . $error->getMessage() . "\n");
    }
}

function ops006Assert($condition, $message)
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function ops006AssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function ops006RequireClass($className)
{
    ops006Assert(class_exists($className), "Required class {$className} is not implemented yet.");
}

function ops006Finish()
{
    if ($GLOBALS['ops006TestFailures'] > 0) {
        fwrite(STDERR, "OPS-006 test failures: {$GLOBALS['ops006TestFailures']}\n");
        return 1;
    }

    fwrite(STDOUT, "OPS-006 tests passed\n");
    return 0;
}

function ops006TempDir($prefix = 'ops006-')
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new \RuntimeException('Could not create a temporary test directory.');
    }

    register_shutdown_function(function () use ($directory) {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    });

    return $directory;
}

function ops006LoadConfig($name)
{
    $path = __DIR__ . '/../config/' . $name . '.php';
    $source = file_get_contents($path);
    if ($source === false) {
        throw new \RuntimeException("Could not read {$name} configuration.");
    }

    $source = str_replace("$" . "db = require __DIR__ . '/db.php';", "$" . "db = [];", $source);
    $source = str_replace("$" . "dbLocalReport = require __DIR__ . '/db_local_report.php';", "$" . "dbLocalReport = [];", $source);
    $source = str_replace("$" . "dbFolio = require __DIR__ . '/db-folio.php';", "$" . "dbFolio = [];", $source);
    $source = str_replace("$" . "params = require __DIR__ . '/params.php';", "$" . "params = [];", $source);

    $temporaryPath = tempnam(sys_get_temp_dir(), 'ops006-config-');
    if ($temporaryPath === false || file_put_contents($temporaryPath, $source) === false) {
        throw new \RuntimeException('Could not create an isolated application configuration.');
    }
    register_shutdown_function(function () use ($temporaryPath) {
        if (file_exists($temporaryPath)) {
            unlink($temporaryPath);
        }
    });

    return require $temporaryPath;
}

function ops006CreateLogger(array $targetConfigurations, $directory)
{
    foreach ($targetConfigurations as $index => $configuration) {
        $targetConfigurations[$index]['logFile'] = $directory . DIRECTORY_SEPARATOR . 'target-' . $index . '.log';
        $targetConfigurations[$index]['enableRotation'] = false;
    }

    $logger = new \yii\log\Logger();
    $dispatcher = new \yii\log\Dispatcher([
        'logger' => $logger,
        'targets' => $targetConfigurations,
    ]);

    return [$logger, $dispatcher];
}

function ops006RunChild(array $arguments)
{
    $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
    if (!is_string($scriptPath) || $scriptPath === '' || ($scriptPath = realpath($scriptPath)) === false) {
        throw new \RuntimeException('The current PHP test script path is not available.');
    }
    $parts = [escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath)];
    foreach ($arguments as $argument) {
        $parts[] = escapeshellarg((string)$argument);
    }

    $process = proc_open(implode(' ', $parts), [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start the isolated PHP test process.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);

    return [$status, $stdout, $stderr];
}
