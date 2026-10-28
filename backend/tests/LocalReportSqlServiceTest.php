<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\exceptions\PolicyViolationException;
use app\services\LocalReportSqlService;
use yii\base\InvalidConfigException;
use yii\console\Application;
use yii\db\Connection;

class LocalReportGrantFailureConnection extends Connection
{
    public $driverCode = 1142;

    public function getMasterPdo()
    {
        throw new \yii\db\Exception('Insufficient privilege', ['42000', $this->driverCode, 'denied']);
    }
}

class LocalReportTransientFailureConnection extends Connection
{
    public $failOnce = true;
    public $closeCalls = 0;

    public function getMasterPdo()
    {
        if ($this->failOnce) {
            $this->failOnce = false;
            throw new \yii\db\Exception('MySQL server has gone away', ['HY000', 2006, 'disconnected']);
        }
        return parent::getMasterPdo();
    }

    public function close()
    {
        $this->closeCalls++;
        parent::close();
    }
}

function localReportServiceAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function localReportServiceAssertThrows(string $class, callable $callback, string $message): ?\Throwable
{
    try {
        $callback();
    } catch (\Throwable $exception) {
        if (!$exception instanceof $class) {
            fwrite(STDERR, "FAIL: {$message}\nExpected exception: {$class}\nActual exception: " . get_class($exception) . "\n");
            exit(1);
        }
        return $exception;
    }

    fwrite(STDERR, "FAIL: {$message}\nExpected exception: {$class}\n");
    exit(1);
}

$testUser = bin2hex(random_bytes(8));
$testPassword = bin2hex(random_bytes(16));
putenv('LOCAL_REPORT_DB_USER=' . $testUser);
putenv('LOCAL_REPORT_DB_PASS=' . $testPassword);

new Application([
    'id' => 'local-report-sql-service-test',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\\commands',
    'components' => [
        'db' => [
            'class' => Connection::class,
            'dsn' => 'sqlite::memory:',
        ],
        'localReportDb' => [
            'class' => Connection::class,
            'dsn' => 'sqlite::memory:',
        ],
        'folioDb' => [
            'class' => Connection::class,
            'dsn' => 'sqlite::memory:',
        ],
    ],
]);

Yii::$app->db->createCommand('CREATE TABLE route_marker (value TEXT NOT NULL)')->execute();
Yii::$app->db->createCommand("INSERT INTO route_marker (value) VALUES ('main')")->execute();
Yii::$app->localReportDb->createCommand('CREATE TABLE route_marker (value TEXT NOT NULL)')->execute();
Yii::$app->localReportDb->createCommand("INSERT INTO route_marker (value) VALUES ('local')")->execute();

LocalReportSqlService::validateSql('SELECT value FROM route_marker');
foreach ([
    'SELECT 1 INTO OUTFILE \'/tmp/report.csv\'',
    'SELECT 1 INTO DUMPFILE \'/tmp/report.bin\'',
    'SELECT /*!50000 1 */ 1',
    'SELECT /*M!100100 1 */ 1',
    'SELECT 1; SELECT 2',
] as $unsafeSql) {
    $exception = localReportServiceAssertThrows(
        PolicyViolationException::class,
        function () use ($unsafeSql) {
            LocalReportSqlService::validateSql($unsafeSql);
        },
        'Unsafe local SQL must be refused: ' . $unsafeSql
    );
    localReportServiceAssertSame(
        LocalReportSqlService::POLICY_MESSAGE,
        $exception->getMessage(),
        'Unsafe local SQL uses the reporting policy message.'
    );
}

$rows = LocalReportSqlService::queryAll('SELECT value FROM route_marker WHERE value = :value', [':value' => 'local']);
localReportServiceAssertSame('local', $rows[0]['value'] ?? null, 'Local reporting queries use localReportDb.');

$successfulStatement = null;
LocalReportSqlService::withStatement('SELECT 1', [], function ($statement) use (&$successfulStatement) {
    $successfulStatement = $statement;
    return 'finished';
});
localReportServiceAssertSame(
    false,
    $successfulStatement->fetch(\PDO::FETCH_ASSOC),
    'The local SQL cursor closes after a successful callback.'
);

$failedStatement = null;
localReportServiceAssertThrows(\RuntimeException::class, function () use (&$failedStatement) {
    LocalReportSqlService::withStatement('SELECT 1', [], function ($statement) use (&$failedStatement) {
        $failedStatement = $statement;
        throw new \RuntimeException('callback failed');
    });
}, 'Callback failures propagate.');
localReportServiceAssertSame(
    false,
    $failedStatement->fetch(\PDO::FETCH_ASSOC),
    'The local SQL cursor closes after a failed callback.'
);

putenv('LOCAL_REPORT_DB_USER=');
putenv('LOCAL_REPORT_DB_PASS=');
localReportServiceAssertThrows(
    InvalidConfigException::class,
    function () {
        LocalReportSqlService::queryAll('SELECT value FROM route_marker');
    },
    'Missing local report credentials fail closed.'
);

putenv('LOCAL_REPORT_DB_USER=' . $testUser);
putenv('LOCAL_REPORT_DB_PASS=' . $testPassword);
$grantFailureConnection = new LocalReportGrantFailureConnection([
    'dsn' => 'sqlite::memory:',
]);
Yii::$app->set('localReportDb', $grantFailureConnection);
foreach ([1044, 1142, 1143] as $driverCode) {
    $grantFailureConnection->driverCode = $driverCode;
    $grantException = localReportServiceAssertThrows(
        PolicyViolationException::class,
        function () {
            LocalReportSqlService::queryAll('SELECT 1');
        },
        'Database grant errors map to a policy violation.'
    );
    localReportServiceAssertSame(
        LocalReportSqlService::POLICY_MESSAGE,
        $grantException->getMessage(),
        'Database grant errors use the reporting policy message.'
    );
}

$retryConnection = new LocalReportTransientFailureConnection([
    'dsn' => 'sqlite::memory:',
]);
Yii::$app->set('localReportDb', $retryConnection);
$retryRows = LocalReportSqlService::queryAll('SELECT 7 AS value');
localReportServiceAssertSame(7, (int) ($retryRows[0]['value'] ?? 0), 'Transient local connection failures retry once.');
localReportServiceAssertSame(true, $retryConnection->closeCalls > 0, 'Transient local connection failures reconnect the worker connection.');

$dbConfig = require __DIR__ . '/../config/db_local_report.php';
localReportServiceAssertSame(
    false,
    $dbConfig['attributes'][\PDO::MYSQL_ATTR_MULTI_STATEMENTS],
    'The local reporting connection disables multi-statements.'
);
localReportServiceAssertSame(true, $dbConfig['emulatePrepare'], 'The local reporting connection keeps emulated prepares.');

echo "OK: LocalReportSqlService validates, routes and cleans up SQL safely.\n";
