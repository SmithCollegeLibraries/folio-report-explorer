<?php

$ops006StartupMode = $argv[1] ?? '';
if (strpos($ops006StartupMode, '--child-') === 0) {
    $ops006StartupDebug = strpos($ops006StartupMode, '-on') !== false;
    define('YII_ENV', $ops006StartupDebug ? 'dev' : 'prod');
    define('YII_DEBUG', $ops006StartupDebug);
}

require __DIR__ . '/Ops006TestSupport.php';

function ops006RemoveTree($directory)
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) {
            ops006RemoveTree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

function ops006StartupSandbox($generatedLoader = null, $dotEnv = '')
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ops006-startup-' . bin2hex(random_bytes(8));
    $backend = $directory . DIRECTORY_SEPARATOR . 'backend';
    $config = $backend . DIRECTORY_SEPARATOR . 'config';
    mkdir($directory, 0700);
    mkdir($backend, 0700);
    mkdir($config, 0700);
    copy(dirname(__DIR__) . '/yii', $backend . '/yii');
    copy(dirname(__DIR__) . '/config/debug_mode.php', $config . '/debug_mode.php');
    symlink(dirname(__DIR__) . '/vendor', $backend . '/vendor');
    file_put_contents($config . '/console.php', "<?php\nreturn ['id' => 'ops006-startup', 'basePath' => dirname(__DIR__), 'controllerNamespace' => 'app\\\\commands', 'components' => ['log' => ['targets' => []]]];\n");
    if ($generatedLoader !== null) {
        file_put_contents($config . '/env.php', $generatedLoader);
    }
    file_put_contents($directory . '/.env', $dotEnv);
    $probe = $directory . '/shutdown-probe.php';
    file_put_contents($probe, "<?php\nregister_shutdown_function(function () { echo \"\\nOPS006_DEBUG_STATE=\" . YII_ENV . ':' . (YII_DEBUG ? 'on' : 'off') . \"\\n\"; });\n");
    register_shutdown_function(function () use ($directory) {
        ops006RemoveTree($directory);
    });
    return [$directory, $backend . '/yii', $probe];
}

function ops006RunYiiStartup($entryPoint, $probe, array $environment)
{
    $command = 'env -i';
    foreach ($environment as $key => $value) {
        $command .= ' ' . escapeshellarg($key . '=' . $value);
    }
    $command .= ' ' . escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('auto_prepend_file=' . $probe)
        . ' ' . escapeshellarg($entryPoint) . ' help';
    $baseEnvironment = ['PATH' => getenv('PATH'), 'TMPDIR' => sys_get_temp_dir()];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(dirname($entryPoint)), $baseEnvironment);
    if (!is_resource($process)) {
        throw new \RuntimeException('Could not start the isolated Yii console entry point.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return [$status, $stdout, $stderr];
}

function ops006GeneratedEnvLoader()
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/deploy.sh');
    ops006Assert(is_string($source), 'deploy.sh should be readable.');
    $startLine = 'cat > "$ENV_PHP" << \'ENVPHP\'';
    $start = strpos($source, $startLine);
    ops006Assert($start !== false, 'deploy.sh should contain the generated env.php loader.');
    $bodyStart = strpos($source, "\n", $start) + 1;
    $end = strpos($source, "\nENVPHP", $bodyStart);
    ops006Assert($end !== false, 'The generated env.php heredoc should have a closing marker.');
    return substr($source, $bodyStart, $end - $bodyStart);
}

function ops006RunStartupState($entryPoint, $probe, array $environment)
{
    list($status, $stdout, $stderr) = ops006RunYiiStartup($entryPoint, $probe, $environment);
    ops006AssertSame(0, $status, 'The real Yii console entry point should complete its help command.');
    ops006Assert(strpos($stderr, 'PHP Fatal error') === false, 'The isolated Yii startup should not fail.');
    return $stdout . $stderr;
}

ops006Test('real console entry point defaults to production with debug off when environment is unset', function () {
    list(, $entryPoint, $probe) = ops006StartupSandbox();
    $environment = ['PATH' => getenv('PATH'), 'TMPDIR' => sys_get_temp_dir()];
    $output = ops006RunStartupState($entryPoint, $probe, $environment);
    preg_match('/OPS006_DEBUG_STATE=[^\\r\\n]+/', $output, $matches);
    ops006AssertSame('OPS006_DEBUG_STATE=prod:off', $matches[0] ?? null, 'Console startup without env.php or debug variables should use prod with debug off.');
});

foreach (['0', ''] as $processDebugValue) {
    $label = $processDebugValue === '0' ? 'zero' : 'empty';
    ops006Test("generated env.php preserves a process YII_DEBUG value of {$label} through console startup", function () use ($processDebugValue) {
        $loader = ops006GeneratedEnvLoader();
        list(, $entryPoint, $probe) = ops006StartupSandbox($loader, "YII_DEBUG=true\n");
        $environment = [
            'PATH' => getenv('PATH'),
            'TMPDIR' => sys_get_temp_dir(),
            'YII_DEBUG' => $processDebugValue,
        ];
        $output = ops006RunStartupState($entryPoint, $probe, $environment);
        preg_match('/OPS006_DEBUG_STATE=[^\\r\\n]+/', $output, $matches);
        ops006AssertSame('OPS006_DEBUG_STATE=prod:off', $matches[0] ?? null, 'A set process YII_DEBUG value should win over .env, including falsey values.');
    });
}

ops006Test('.env.example explains falsey process environment precedence', function () {
    $example = file_get_contents(dirname(__DIR__, 2) . '/.env.example');
    ops006Assert(is_string($example), '.env.example should be readable.');
    ops006Assert(strpos($example, 'even when its value is empty or "0"') !== false, 'The precedence note should include empty and zero-valued process variables.');
});

exit(ops006Finish());
