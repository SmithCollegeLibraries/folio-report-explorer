<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/ReportRunTitleService.php';

use app\controllers\FolioQueryController;
use yii\db\Connection;
use yii\web\Application;
use yii\web\Request;
use yii\web\Response;

$reportRunIntegrationFailures = [];

function reportRunIntegrationAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function reportRunIntegrationCase($name, $callback)
{
    global $reportRunIntegrationFailures;
    try {
        $callback();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        $reportRunIntegrationFailures[] = $name;
        fwrite(STDERR, "FAIL: {$name}: " . $error->getMessage() . "\n");
    }
}

$runtimeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'report-run-title-' . bin2hex(random_bytes(8));
mkdir($runtimeRoot, 0700, true);
register_shutdown_function(static function () use ($runtimeRoot) {
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

$localUser = bin2hex(random_bytes(8));
$localPassword = bin2hex(random_bytes(16));
putenv('LOCAL_REPORT_DB_USER=' . $localUser);
putenv('LOCAL_REPORT_DB_PASS=' . $localPassword);

new Application([
    'id' => 'report-run-title-test',
    'basePath' => dirname(__DIR__),
    'runtimePath' => $runtimeRoot,
    'controllerNamespace' => 'app\\controllers',
    'components' => [
        'request' => [
            'class' => Request::class,
            'cookieValidationKey' => 'synthetic-test-key',
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

Yii::$app->localReportDb->createCommand('CREATE TABLE route_options (value TEXT, label TEXT)')->execute();
Yii::$app->localReportDb->createCommand("INSERT INTO route_options VALUES ('first', 'First label')")->execute();

$insertReport = function ($name, $dataSource, array $parameters, $compositeConfig = null, $sql = 'SELECT 1') {
    Yii::$app->db->createCommand()->insert('report_templates', [
        'slug' => strtolower(str_replace(' ', '-', $name)),
        'name' => $name,
        'description' => '',
        'category' => 'other',
        'sql_template' => $sql,
        'parameters' => json_encode($parameters),
        'data_source' => $dataSource,
        'composite_config' => $compositeConfig === null ? null : json_encode($compositeConfig),
        'default_limit' => 100,
        'is_active' => 1,
        'created_by' => 'manual',
    ])->execute();
    return (int) Yii::$app->db->getLastInsertID();
};

$controller = new FolioQueryController('folio-query', Yii::$app);
$compositeConfig = [
    'secondary_sql' => 'SELECT 1',
    'secondary_db' => 'local',
    'merge_key' => ['primary' => 'id', 'secondary' => 'id'],
    'append_columns' => [],
];
$compositeReportId = $insertReport(
    'Synthetic Composite Report',
    'composite',
    [[
        'name' => 'choice',
        'type' => 'select',
        'options_db' => 'local',
        'options_sql' => 'SELECT value, label FROM route_options',
    ]],
    $compositeConfig,
    'SELECT :choice AS result'
);

reportRunIntegrationCase('actionReportRun stores a title and merges reportTemplateId with composite metadata', function () use ($controller, $compositeReportId, $compositeConfig) {
    Yii::$app->request->setBodyParams(['params' => ['choice' => 'first']]);
    Yii::$app->response->statusCode = 200;
    $result = $controller->actionReportRun($compositeReportId);

    reportRunIntegrationAssertSame(202, Yii::$app->response->statusCode, 'Queue the synthetic report run.');
    $job = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $result['jobId']])->queryOne();
    reportRunIntegrationAssertSame(
        'Synthetic Composite Report — First label',
        $job['name'] ?? null,
        'Build the title with the selected option label.'
    );
    $metadata = json_decode($job['metadata'] ?? '', true);
    reportRunIntegrationAssertSame($compositeReportId, $metadata['reportTemplateId'] ?? null, 'Store the report template id in job metadata.');
    reportRunIntegrationAssertSame($compositeConfig, $metadata['composite_config'] ?? null, 'Keep composite metadata when adding the report template id.');
});

$throwingLookupReportId = $insertReport(
    'Synthetic Lookup Failure Report',
    'local',
    [[
        'name' => 'choice',
        'type' => 'select',
        'options_db' => 'local',
        'options_sql' => 'SELECT value, label FROM missing_report_title_options',
    ]],
    null,
    'SELECT :choice AS result'
);

reportRunIntegrationCase('actionReportRun still queues when title option lookup throws', function () use ($controller, $throwingLookupReportId) {
    Yii::$app->request->setBodyParams(['params' => ['choice' => 'raw-choice']]);
    Yii::$app->response->statusCode = 200;
    $result = $controller->actionReportRun($throwingLookupReportId);

    reportRunIntegrationAssertSame(202, Yii::$app->response->statusCode, 'An unavailable title option must not stop a run.');
    reportRunIntegrationAssertSame(36, strlen((string) ($result['jobId'] ?? '')), 'A job id is returned for a run that survives option lookup failure.');
    reportRunIntegrationAssertSame(
        1,
        (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs WHERE id = :id', [':id' => $result['jobId']])->queryScalar(),
        'Persist the run even when its title option lookup fails.'
    );
});

$longReportName = str_repeat('Synthetic Long Report ', 20);
$longReportId = $insertReport($longReportName, 'local', [], null, 'SELECT 1');

reportRunIntegrationCase('actionReportRun truncates a long title through the existing normalizer', function () use ($controller, $longReportId) {
    Yii::$app->request->setBodyParams(['params' => []]);
    Yii::$app->response->statusCode = 200;
    $result = $controller->actionReportRun($longReportId);

    reportRunIntegrationAssertSame(202, Yii::$app->response->statusCode, 'Queue a report with a long title.');
    $name = (string) Yii::$app->db->createCommand(
        'SELECT name FROM query_jobs WHERE id = :id',
        [':id' => $result['jobId']]
    )->queryScalar();
    $normalizer = new ReflectionMethod(FolioQueryController::class, 'normalizeQueryJobName');
    $normalizer->setAccessible(true);
    reportRunIntegrationAssertSame(
        $normalizer->invoke($controller, str_repeat('Synthetic Long Report ', 20)),
        $name,
        'Pass the generated title through the existing query-job name normalizer.'
    );
    reportRunIntegrationAssertSame(240, strlen($name), 'Use the query-job display-name length limit.');
    reportRunIntegrationAssertSame('...', substr($name, -3), 'Use the normalizer ellipsis truncation.');
});

putenv('LOCAL_REPORT_DB_USER=');
putenv('LOCAL_REPORT_DB_PASS=');

if (!empty($reportRunIntegrationFailures)) {
    fwrite(STDERR, count($reportRunIntegrationFailures) . " report-run controller case(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "Report run controller tests passed (3 cases).\n");
