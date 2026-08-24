<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\services\DatabaseRetryService;
use yii\db\Connection;

class TrackingRetryConnection extends Connection
{
    public $closeCalls = 0;
    public $openCalls = 0;

    public function close()
    {
        $this->closeCalls++;
        parent::close();
    }

    public function open()
    {
        $this->openCalls++;
        parent::open();
    }
}

function retryServiceAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$db = new TrackingRetryConnection(['dsn' => 'sqlite::memory:']);
$db->open();
$db->closeCalls = 0;
$db->openCalls = 0;

$attempts = 0;
$result = DatabaseRetryService::runWithReconnectRetry(
    $db,
    static function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            throw new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }

        return 'recovered';
    },
    'test.mysql.disconnect'
);

retryServiceAssertSame('recovered', $result, 'MySQL error 2006 should reconnect and retry the failed operation.');
retryServiceAssertSame(2, $attempts, 'The operation should run once more after reconnecting.');
retryServiceAssertSame(1, $db->closeCalls, 'The stale MySQL connection should be closed exactly once.');
retryServiceAssertSame(1, $db->openCalls, 'The MySQL connection should be reopened exactly once.');

$db->closeCalls = 0;
$db->openCalls = 0;
$attempts = 0;
$result = DatabaseRetryService::runWithReconnectRetry(
    $db,
    static function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            throw new RuntimeException('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query');
        }

        return 'recovered';
    },
    'test.mysql.lost-connection'
);

retryServiceAssertSame('recovered', $result, 'MySQL error 2013 should reconnect and retry the failed operation.');
retryServiceAssertSame(2, $attempts, 'MySQL error 2013 should retry the operation once.');
retryServiceAssertSame(1, $db->closeCalls, 'MySQL error 2013 should close the stale connection once.');
retryServiceAssertSame(1, $db->openCalls, 'MySQL error 2013 should reopen the connection once.');

$db->closeCalls = 0;
$db->openCalls = 0;
$attempts = 0;
$result = DatabaseRetryService::runWithReconnectRetry(
    $db,
    static function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            throw new yii\db\Exception(
                'Queue database connection interrupted',
                ['HY000', 2006, 'Connection interrupted']
            );
        }

        return 'recovered';
    },
    'test.mysql.structured-error'
);

retryServiceAssertSame('recovered', $result, 'MySQL driver error code 2006 should trigger reconnect without relying on message wording.');
retryServiceAssertSame(2, $attempts, 'A structured MySQL disconnect should retry the operation once.');
retryServiceAssertSame(1, $db->closeCalls, 'A structured MySQL disconnect should close the stale connection once.');
retryServiceAssertSame(1, $db->openCalls, 'A structured MySQL disconnect should reopen the connection once.');

fwrite(STDOUT, "Database retry service test passed\n");
