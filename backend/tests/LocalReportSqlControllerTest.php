<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use yii\db\Connection;
use yii\web\Application;
use yii\web\Request;
use yii\web\Response;

function localReportControllerAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$runtimeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'local-report-controller-' . bin2hex(random_bytes(8));
mkdir($runtimeRoot, 0700, true);
register_shutdown_function(static function () use ($runtimeRoot): void {
    if (!is_dir($runtimeRoot)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($runtimeRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($runtimeRoot);
});

new Application([
    'id' => 'local-report-sql-controller-test',
    'basePath' => dirname(__DIR__),
    'runtimePath' => $runtimeRoot,
    'controllerNamespace' => 'app\\controllers',
    'components' => [
        'request' => [
            'class' => Request::class,
            'cookieValidationKey' => 'test-key',
        ],
        'response' => ['class' => Response::class],
        'user' => [
            'class' => yii\web\User::class,
            'identityClass' => app\models\DummyIdentity::class,
        ],
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
    'params' => [
        'maxQueryRows' => 100,
        'maxQuerySeconds' => 30,
        'derivedPath' => dirname(__DIR__) . '/data/folio_derived_tables.json',
    ],
]);

$testUser = bin2hex(random_bytes(8));
$testPassword = bin2hex(random_bytes(16));
putenv('LOCAL_REPORT_DB_USER=' . $testUser);
putenv('LOCAL_REPORT_DB_PASS=' . $testPassword);

foreach ([Yii::$app->db, Yii::$app->localReportDb] as $connection) {
    $connection->createCommand('CREATE TABLE route_marker (value TEXT NOT NULL)')->execute();
}
Yii::$app->db->createCommand("INSERT INTO route_marker (value) VALUES ('main')")->execute();
Yii::$app->localReportDb->createCommand("INSERT INTO route_marker (value) VALUES ('local')")->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sql_text TEXT,
    params TEXT,
    source TEXT,
    data_source TEXT,
    user_id INTEGER,
    row_count INTEGER,
    execution_time_ms INTEGER,
    error_message TEXT,
    created_at TEXT
)
SQL
)->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id TEXT PRIMARY KEY,
    sql_text TEXT,
    sql_hash TEXT,
    params TEXT,
    source TEXT,
    name TEXT,
    data_source TEXT,
    user_id INTEGER,
    status TEXT,
    result_columns TEXT,
    result_rows TEXT,
    row_count INTEGER,
    execution_time_ms INTEGER,
    error_message TEXT,
    progress_message TEXT,
    pg_backend_pid INTEGER,
    output_mode TEXT,
    export_file_path TEXT,
    estimated_rows INTEGER,
    estimated_cost NUMERIC,
    metadata TEXT,
    created_at TEXT,
    started_at TEXT,
    completed_at TEXT
)
SQL
)->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE report_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT,
    name TEXT,
    description TEXT,
    help_text TEXT,
    category TEXT,
    sql_template TEXT,
    parameters TEXT,
    data_source TEXT,
    composite_config TEXT,
    execution_config TEXT,
    default_limit INTEGER,
    is_active INTEGER,
    created_by TEXT,
    created_at TEXT,
    updated_at TEXT
)
SQL
)->execute();

$controller = new FolioQueryController('folio-query', Yii::$app);
Yii::$app->request->setBodyParams([
    'sql' => 'SELECT value FROM route_marker',
    'dataSource' => 'local',
]);
$executeResult = $controller->actionExecute();
localReportControllerAssertSame('local', $executeResult['rows'][0]['value'] ?? null, '/execute uses localReportDb for local SQL.');

putenv('LOCAL_REPORT_DB_USER=');
putenv('LOCAL_REPORT_DB_PASS=');
Yii::$app->request->setBodyParams([
    'sql' => 'SELECT value FROM route_marker',
    'dataSource' => 'local',
]);
Yii::$app->response->statusCode = 200;
$unconfiguredExecuteResult = $controller->actionExecute();
localReportControllerAssertSame(403, Yii::$app->response->statusCode, 'Local execute refuses to fall back when credentials are missing.');
localReportControllerAssertSame(
    "Local reports aren't configured on this server.",
    $unconfiguredExecuteResult['error'] ?? null,
    'Missing local report credentials use the configured refusal message.'
);

$testUser = bin2hex(random_bytes(8));
$testPassword = bin2hex(random_bytes(16));
putenv('LOCAL_REPORT_DB_USER=' . $testUser);
putenv('LOCAL_REPORT_DB_PASS=' . $testPassword);

Yii::$app->request->setBodyParams([
    'sql' => 'SELECT /*!50000 1 */ 1',
    'dataSource' => 'local',
]);
Yii::$app->response->statusCode = 200;
$submitResult = $controller->actionQuerySubmit();
localReportControllerAssertSame(403, Yii::$app->response->statusCode, 'Local submit refuses executable comments at queue time.');
localReportControllerAssertSame(
    'This query is blocked by reporting data policy.',
    $submitResult['error'] ?? null,
    'Local submit uses the standard policy refusal message.'
);
localReportControllerAssertSame(0, (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar(), 'A refused local submit is never queued.');

$insertReport = function ($slug, $sql, $dataSource, $compositeConfig = null) {
    return Yii::$app->db->createCommand()->insert('report_templates', [
        'slug' => $slug,
        'name' => $slug,
        'description' => '',
        'category' => 'other',
        'sql_template' => $sql,
        'parameters' => '[]',
        'data_source' => $dataSource,
        'composite_config' => $compositeConfig === null ? null : json_encode($compositeConfig),
        'default_limit' => 100,
        'is_active' => 1,
        'created_by' => 'manual',
    ])->execute();
};

$insertReport('local-executable-comment', 'SELECT /*!50000 1 */ 1', 'local');
Yii::$app->response->statusCode = 200;
$reportResult = $controller->actionReportRun(1);
localReportControllerAssertSame(403, Yii::$app->response->statusCode, 'Local report SQL is checked before queuing.');
localReportControllerAssertSame('This query is blocked by reporting data policy.', $reportResult['error'] ?? null, 'Local report refusal uses the standard message.');
localReportControllerAssertSame(0, (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar(), 'A refused local report is never queued.');

$insertReport('composite-bad-secondary', 'SELECT 1', 'composite', [
    'secondary_sql' => 'SELECT /*!50000 1 */ 1',
    'merge_key' => ['primary' => 'id', 'secondary' => 'id'],
]);
Yii::$app->response->statusCode = 200;
$compositeResult = $controller->actionReportRun(2);
localReportControllerAssertSame(403, Yii::$app->response->statusCode, 'Composite local SQL is checked before queuing.');
localReportControllerAssertSame('This query is blocked by reporting data policy.', $compositeResult['error'] ?? null, 'Composite refusal uses the standard message.');
localReportControllerAssertSame(0, (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar(), 'A refused composite report is never queued.');

echo "OK: local execute, queue-time submit and report checks are enforced.\n";
