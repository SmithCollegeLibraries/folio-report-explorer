<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/HistoryGroupKeyService.php';

use app\controllers\FolioQueryController;
use app\models\User;
use app\services\HistoryGroupKeyService;
use yii\web\Application;

$runtimeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'history-grouping-controller-' . bin2hex(random_bytes(8));
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

putenv('LOCAL_REPORT_DB_USER=' . bin2hex(random_bytes(8)));
putenv('LOCAL_REPORT_DB_PASS=' . bin2hex(random_bytes(16)));

new Application([
    'id' => 'history-grouping-controller-test',
    'basePath' => dirname(__DIR__),
    'runtimePath' => $runtimeRoot,
    'components' => [
        'request' => ['cookieValidationKey' => 'synthetic-test-key', 'enableCsrfValidation' => false],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['identityClass' => User::class, 'enableSession' => false],
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'localReportDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
    'params' => ['maxQueryRows' => 100, 'queryTimeoutMs' => 1000],
]);
Yii::$app->errorHandler->unregister();

$db = Yii::$app->db;
$db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id TEXT PRIMARY KEY,
    sql_text TEXT NOT NULL,
    sql_hash TEXT NULL,
    params TEXT NULL,
    source TEXT DEFAULT 'manual',
    data_source TEXT DEFAULT 'folio',
    name TEXT NULL,
    user_id INTEGER NULL,
    status TEXT DEFAULT 'pending',
    result_columns TEXT NULL,
    result_rows TEXT NULL,
    row_count INTEGER DEFAULT 0,
    execution_time_ms INTEGER DEFAULT 0,
    error_message TEXT NULL,
    progress_message TEXT NULL,
    pg_backend_pid INTEGER NULL,
    output_mode TEXT DEFAULT 'table',
    export_file_path TEXT NULL,
    estimated_rows INTEGER NULL,
    estimated_cost REAL NULL,
    metadata TEXT NULL,
    created_at TEXT NULL,
    started_at TEXT NULL,
    completed_at TEXT NULL,
    history_group_key CHAR(40) NULL
)
SQL
)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE report_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NULL,
    help_text TEXT NULL,
    category TEXT DEFAULT 'other',
    sql_template TEXT NOT NULL,
    parameters TEXT NOT NULL,
    data_source TEXT DEFAULT 'local',
    composite_config TEXT NULL,
    execution_config TEXT NULL,
    default_limit INTEGER DEFAULT 100,
    is_active INTEGER DEFAULT 1,
    created_by TEXT DEFAULT 'manual'
)
SQL
)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE saved_queries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    name TEXT NOT NULL,
    description TEXT NULL,
    query_definition TEXT NULL,
    generated_sql TEXT NULL,
    source TEXT NULL,
    last_job_id TEXT NULL,
    is_pinned INTEGER DEFAULT 0,
    is_global INTEGER DEFAULT 0,
    created_at TEXT NULL,
    updated_at TEXT NULL
)
SQL
)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    smith_id TEXT,
    username TEXT,
    email TEXT,
    role TEXT,
    is_approved INTEGER
)
SQL
)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_generations (
    id TEXT PRIMARY KEY,
    parent_generation_id TEXT NULL,
    query_job_id TEXT NULL,
    user_id INTEGER NULL,
    original_question TEXT NOT NULL,
    follow_up_context TEXT NULL,
    generated_sql TEXT NULL,
    confidence_evidence_json TEXT NOT NULL
)
SQL
)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_reviews (
    id TEXT PRIMARY KEY,
    generation_id TEXT NOT NULL,
    advisory_state TEXT NOT NULL,
    superseded_by_job_id TEXT NULL,
    administrator_notes TEXT NULL,
    updated_at TEXT NOT NULL
)
SQL
)->execute();

$identity = new User();
$identity->setAttributes([
    'id' => 7,
    'smith_id' => 'synthetic-7',
    'username' => 'synthetic-owner',
    'email' => 'owner@example.test',
    'role' => 'user',
    'is_approved' => 1,
], false);
Yii::$app->user->setIdentity($identity);

$db->createCommand()->insert('report_templates', [
    'id' => 12,
    'slug' => 'synthetic-history-report',
    'name' => 'Synthetic History Report',
    'description' => 'Synthetic report fixture.',
    'category' => 'other',
    'sql_template' => 'SELECT :term AS result',
    'parameters' => json_encode([['name' => 'term', 'type' => 'text', 'required' => false, 'default' => 'catalog']]),
    'data_source' => 'local',
    'execution_config' => json_encode(['identifier_export' => ['source_column' => 'id', 'header' => 'ID']]),
    'default_limit' => 100,
    'is_active' => 1,
    'created_by' => 'manual',
])->execute();
$db->createCommand()->insert('saved_queries', [
    'id' => 5,
    'user_id' => 7,
    'name' => 'Synthetic dashboard report',
    'query_definition' => json_encode([
        'report_template_id' => 12,
        'bound_params' => [':term' => 'catalog'],
    ]),
    'generated_sql' => 'SELECT :term AS result',
    'source' => 'report',
    'last_job_id' => null,
])->execute();

$controller = new FolioQueryController('folio-query', Yii::$app);
$failures = [];

function historyControllerAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true));
    }
}

function historyControllerCase($name, $callback)
{
    global $failures;
    try {
        $callback();
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    } catch (Throwable $error) {
        $failures[] = $name;
        fwrite(STDERR, 'FAIL: ' . $name . ': ' . $error->getMessage() . "\n");
    }
}

function historyControllerJob($db, $id, $owner, $key, $status, $created = '2026-07-19 10:00:00', $completed = null)
{
    $db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => 'SELECT 1',
        'params' => '{}',
        'source' => 'report',
        'data_source' => 'local',
        'name' => 'Synthetic report',
        'user_id' => $owner,
        'status' => $status,
        'progress_message' => ucfirst($status),
        'error_message' => $status === 'failed' ? 'Synthetic failure' : null,
        'row_count' => 3,
        'created_at' => $created,
        'completed_at' => $completed,
        'history_group_key' => $key,
    ])->execute();
}

historyControllerCase('report run stores a group key from its bound parameters and the final forced-file mode', function () use ($controller, $db) {
    Yii::$app->request->setBodyParams([
        'params' => [],
        'outputMode' => 'table',
        'exportKind' => 'identifier',
    ]);
    Yii::$app->response->statusCode = 200;
    $result = $controller->actionReportRun(12);
    $job = $db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $result['jobId']])->queryOne();

    historyControllerAssertSame(202, Yii::$app->response->statusCode, 'The synthetic report should queue.');
    historyControllerAssertSame('file', $job['output_mode'], 'Identifier exports force the final mode to file.');
    historyControllerAssertSame('report', $job['source'], 'Only report runs receive the group key.');
    if (!preg_match('/^[a-f0-9]{40}$/', (string)($job['history_group_key'] ?? ''))) {
        throw new RuntimeException('actionReportRun must persist a 40-character SHA-1 key for a new report run.');
    }
    $storedParams = json_decode($job['params'], true);
    $expectedKey = HistoryGroupKeyService::build(12, $storedParams, 'identifier', 'file');
    historyControllerAssertSame($expectedKey, $job['history_group_key'], 'The key must use stored bound params and final file output mode.');
});

historyControllerCase('dashboard refresh keeps history_group_key NULL', function () use ($controller, $db) {
    Yii::$app->response->statusCode = 200;
    $result = $controller->actionDashboardRefresh(5);
    $job = $db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $result['jobId']])->queryOne();

    historyControllerAssertSame(202, Yii::$app->response->statusCode, 'The synthetic dashboard refresh should queue.');
    historyControllerAssertSame(null, $job['history_group_key'], 'Dashboard refresh jobs must remain ungrouped.');
});

historyControllerCase('history list collapses runs and returns group counts without changing item fields', function () use ($controller, $db) {
    $db->createCommand('DELETE FROM query_jobs')->execute();
    historyControllerJob($db, 'group-new', 7, str_repeat('a', 40), 'completed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');
    historyControllerJob($db, 'group-old', 7, str_repeat('a', 40), 'completed', '2026-07-19 10:00:00', '2026-07-19 10:01:00');
    historyControllerJob($db, 'other-owner', 8, str_repeat('a', 40), 'completed', '2026-07-19 10:04:00', '2026-07-19 10:05:00');
    Yii::$app->request->setQueryParams(['status' => 'all', 'limit' => 50, 'offset' => 0]);

    $result = $controller->actionQueryHistory();
    historyControllerAssertSame(2, $result['total'], 'The list total counts groups, not matching jobs.');
    historyControllerAssertSame(2, count($result['items']), 'Different owners retain separate rows.');
    $group = null;
    foreach ($result['items'] as $item) {
        if ($item['jobId'] === 'group-new') {
            $group = $item;
        }
    }
    historyControllerAssertSame(2, $group['runCount'] ?? null, 'The representative reports the retained run count.');
    historyControllerAssertSame(0, $group['activeRunCount'] ?? null, 'Completed groups have no active member count.');
    historyControllerAssertSame(true, array_key_exists('runBy', $group), 'The established runBy field remains in the item shape.');
    historyControllerAssertSame(true, array_key_exists('canDelete', $group), 'The established canDelete field remains in the item shape.');
});

historyControllerCase('single-item endpoint serializes failed job fields and returns 404 for a missing job', function () use ($controller, $db) {
    $db->createCommand('DELETE FROM query_jobs')->execute();
    historyControllerJob($db, 'failed-item', 7, str_repeat('b', 40), 'failed');

    Yii::$app->response->statusCode = 200;
    $item = $controller->actionQueryHistoryItem('failed-item');
    historyControllerAssertSame('failed-item', $item['jobId'] ?? null, 'The single endpoint returns the selected item.');
    historyControllerAssertSame('failed', $item['status'] ?? null, 'The item retains its status.');
    historyControllerAssertSame('Synthetic failure', $item['errorMessage'] ?? null, 'Failed item errorMessage is retained.');
    foreach ([
        'jobId', 'name', 'status', 'sql', 'source', 'dataSource', 'progressMessage', 'rowCount',
        'executionTimeMs', 'errorMessage', 'createdAt', 'startedAt', 'completedAt', 'runBy', 'canDelete',
    ] as $field) {
        historyControllerAssertSame(true, array_key_exists($field, $item), 'The single-item serializer includes ' . $field . '.');
    }

    Yii::$app->response->statusCode = 200;
    $missing = $controller->actionQueryHistoryItem('missing-item');
    historyControllerAssertSame(404, Yii::$app->response->statusCode, 'A missing single history item returns 404.');
    historyControllerAssertSame(['error' => 'Job not found'], $missing, 'A missing item has the established not-found body.');
});

historyControllerCase('group expansion resolves a member id, filters and pages members, and returns 404 for a missing id', function () use ($controller, $db) {
    $db->createCommand('DELETE FROM query_jobs')->execute();
    historyControllerJob($db, 'expand-new', 7, str_repeat('c', 40), 'completed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');
    historyControllerJob($db, 'expand-member', 7, str_repeat('c', 40), 'failed', '2026-07-19 10:00:00', '2026-07-19 10:01:00');
    Yii::$app->request->setQueryParams(['status' => 'all', 'limit' => 1, 'offset' => 1]);

    Yii::$app->response->statusCode = 200;
    $page = $controller->actionQueryHistoryGroup('expand-member');
    historyControllerAssertSame(2, $page['total'] ?? null, 'Any group member id resolves the owner-key group.');
    historyControllerAssertSame(1, count($page['items'] ?? []), 'Expansion honors its page size.');
    historyControllerAssertSame('expand-member', $page['items'][0]['jobId'] ?? null, 'Expansion orders members newest first.');

    Yii::$app->response->statusCode = 200;
    $missing = $controller->actionQueryHistoryGroup('missing-group');
    historyControllerAssertSame(404, Yii::$app->response->statusCode, 'A missing expansion id returns 404.');
    historyControllerAssertSame(['error' => 'Job not found'], $missing, 'A missing group member has the not-found body.');
});

historyControllerCase('renaming a job leaves its history group key unchanged', function () use ($controller, $db) {
    $db->createCommand('DELETE FROM query_jobs')->execute();
    historyControllerJob($db, 'rename-job', 7, str_repeat('d', 40), 'completed');
    Yii::$app->request->setBodyParams(['name' => 'Renamed synthetic report']);

    $result = $controller->actionRenameHistoryJob('rename-job');
    $key = $db->createCommand('SELECT history_group_key FROM query_jobs WHERE id = :id', [':id' => 'rename-job'])->queryScalar();
    historyControllerAssertSame('Renamed synthetic report', $result['name'] ?? null, 'The job can still be renamed.');
    historyControllerAssertSame(str_repeat('d', 40), $key, 'A per-job rename does not rekey a report group.');
});

putenv('LOCAL_REPORT_DB_USER=');
putenv('LOCAL_REPORT_DB_PASS=');

fwrite(STDOUT, count($failures) . " history controller case(s) failed.\n");
exit($failures === [] ? 0 : 1);
