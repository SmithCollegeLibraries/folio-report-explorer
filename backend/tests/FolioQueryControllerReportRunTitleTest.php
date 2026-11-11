<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/ReportRunTitleService.php';
require_once __DIR__ . '/../services/HistoryItemSerializer.php';

use app\controllers\FolioQueryController;
use yii\db\Connection;
use yii\web\Application;
use yii\web\Request;
use yii\web\Response;

class ReportReplayTestIdentity extends \app\models\DummyIdentity
{
    private $identityId;
    private $administrator;

    public function __construct($identityId, $administrator = false)
    {
        $this->identityId = $identityId;
        $this->administrator = $administrator;
    }

    public function getId() { return $this->identityId; }
    public function isAdmin(): bool { return $this->administrator; }
}

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
    completed_at TEXT,
    history_group_key CHAR(40) NULL
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
Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE saved_queries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    name TEXT NOT NULL,
    description TEXT NULL,
    query_definition TEXT NOT NULL,
    generated_sql TEXT NULL,
    source TEXT NULL,
    nl_prompt TEXT NULL,
    last_job_id TEXT NULL,
    is_pinned INTEGER DEFAULT 0,
    is_global INTEGER DEFAULT 0,
    created_at TEXT NULL,
    updated_at TEXT NULL
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
    reportRunIntegrationAssertSame([
        'inputs' => ['choice' => 'first'],
        'outputMode' => 'table',
        'exportKind' => 'worklist',
    ], $metadata['reportReplay'] ?? null, 'Store the submitted replay snapshot without losing composite metadata.');

    Yii::$app->request->setBodyParams(['fromJobId' => $result['jobId'], 'name' => 'Saved composite report']);
    Yii::$app->response->statusCode = 200;
    $saved = $controller->actionSave();
    reportRunIntegrationAssertSame(201, Yii::$app->response->statusCode, 'Save the composite run from history.');
    Yii::$app->request->setBodyParams(['params' => ['choice' => 'first']]);
    Yii::$app->response->statusCode = 200;
    $direct = $controller->actionReportRun($compositeReportId);
    $directJob = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $direct['jobId']])->queryOne();
    Yii::$app->response->statusCode = 200;
    $refresh = $controller->actionDashboardRefresh($saved['id']);
    $refreshJob = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $refresh['jobId']])->queryOne();
    foreach (['sql_text', 'params', 'data_source', 'output_mode', 'history_group_key'] as $field) {
        reportRunIntegrationAssertSame($directJob[$field], $refreshJob[$field], 'Composite refresh matches a direct report run on ' . $field . '.');
    }
    $directMetadata = json_decode($directJob['metadata'], true);
    $refreshMetadata = json_decode($refreshJob['metadata'], true);
    foreach (['composite_config', 'bound_params', 'reportTemplateId', 'reportReplay'] as $field) {
        reportRunIntegrationAssertSame($directMetadata[$field] ?? null, $refreshMetadata[$field] ?? null, 'Composite refresh preserves ' . $field . '.');
    }
});

reportRunIntegrationCase('report replay snapshots keep scalar inputs and the final mode only', function () use ($controller, $insertReport) {
    $definitions = [
        ['name' => 'fiscalYear', 'type' => 'number'],
        ['name' => 'zero', 'type' => 'number'],
        ['name' => 'disabled', 'type' => 'boolean'],
        ['name' => 'emptyText', 'type' => 'text'],
        ['name' => 'arrayValue', 'type' => 'text'],
        ['name' => 'objectValue', 'type' => 'text'],
        ['name' => 'omitted', 'type' => 'text'],
    ];
    $reportId = $insertReport(
        'Synthetic Replay Snapshot Report',
        'local',
        $definitions,
        null,
        'SELECT :fiscalYear AS year, :zero AS zero, :disabled AS disabled, :emptyText AS empty, :arrayValue AS array, :objectValue AS object'
    );
    $params = [
        'fiscalYear' => 2025,
        'zero' => 0,
        'disabled' => false,
        'emptyText' => '',
        'arrayValue' => ['not', 'scalar'],
        'objectValue' => (object) ['not' => 'scalar'],
        'unlisted' => 'ignored',
    ];
    $jobsByMode = [];

    foreach (['table', 'file'] as $requestedMode) {
        Yii::$app->request->setBodyParams(['params' => $params, 'outputMode' => $requestedMode]);
        Yii::$app->response->statusCode = 200;
        $result = $controller->actionReportRun($reportId);
        reportRunIntegrationAssertSame(202, Yii::$app->response->statusCode, 'Queue the synthetic local report run.');
        $job = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $result['jobId']])->queryOne();
        $metadata = json_decode($job['metadata'] ?? '', true);
        reportRunIntegrationAssertSame([
            'inputs' => [
                'fiscalYear' => 2025,
                'zero' => 0,
                'disabled' => false,
                'emptyText' => '',
            ],
            'outputMode' => $requestedMode,
            'exportKind' => 'worklist',
        ], $metadata['reportReplay'] ?? null, 'Keep scalar types and omit unsubmitted, array, object, and undeclared values.');
        reportRunIntegrationAssertSame($requestedMode, $job['output_mode'], 'Store the actual output mode.');
        $jobsByMode[$requestedMode] = $job;
    }
    reportRunIntegrationAssertSame(false,
        $jobsByMode['table']['history_group_key'] === $jobsByMode['file']['history_group_key'],
        'Table and file runs use separate history groups.');

    Yii::$app->db->createCommand()->update('report_templates', [
        'execution_config' => json_encode([
            'public_row_cap' => 100000,
            'fetch_row_limit' => 100001,
            'preserve_export_order' => true,
            'identifier_export' => ['source_column' => 'id', 'header' => 'ID'],
        ]),
    ], ['id' => $reportId])->execute();
    Yii::$app->request->setBodyParams(['params' => $params, 'outputMode' => 'table', 'exportKind' => 'identifier']);
    Yii::$app->response->statusCode = 200;
    $identifierResult = $controller->actionReportRun($reportId);
    $identifierJob = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $identifierResult['jobId']])->queryOne();
    reportRunIntegrationAssertSame('file', $identifierJob['output_mode'], 'Identifier export forces file mode.');
    reportRunIntegrationAssertSame(false,
        $jobsByMode['file']['history_group_key'] === $identifierJob['history_group_key'],
        'Worklist and identifier runs use separate history groups.');

    $folioReportId = $insertReport(
        'Synthetic Plain FOLIO Report',
        'folio',
        [['name' => 'term', 'type' => 'text']],
        null,
        'SELECT :term AS result'
    );
    Yii::$app->request->setBodyParams(['params' => ['term' => 'folio-run'], 'outputMode' => 'table']);
    Yii::$app->response->statusCode = 200;
    $folioRun = $controller->actionReportRun($folioReportId);
    reportRunIntegrationAssertSame(202, Yii::$app->response->statusCode, 'Queue a plain FOLIO table report.');
    $folioJob = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $folioRun['jobId']])->queryOne();
    $folioMetadata = json_decode($folioJob['metadata'], true);
    reportRunIntegrationAssertSame('folio', $folioJob['data_source'], 'Keep the plain report FOLIO data source.');
    reportRunIntegrationAssertSame([
        'inputs' => ['term' => 'folio-run'],
        'outputMode' => 'table',
        'exportKind' => 'worklist',
    ], $folioMetadata['reportReplay'] ?? null, 'Plain FOLIO runs carry the same replay snapshot.');
});

reportRunIntegrationCase('history exposes report replay eligibility and established ineligible reasons', function () use ($compositeReportId) {
    $validReplay = [
        'inputs' => (object) ['choice' => 'first'],
        'outputMode' => 'table',
        'exportKind' => 'worklist',
    ];
    $serialize = function ($source, $metadata) {
        return \app\services\HistoryItemSerializer::serialize([
            'id' => 'synthetic-history-job',
            'source' => $source,
            'metadata' => json_encode($metadata),
        ]);
    };

    reportRunIntegrationAssertSame([
        'reportTemplateId' => $compositeReportId,
        'canSave' => true,
        'reason' => null,
    ], $serialize('report', [
        'reportTemplateId' => $compositeReportId,
        'reportReplay' => $validReplay,
    ])['reportRun'] ?? null, 'An active report with a valid replay snapshot can be saved.');

    reportRunIntegrationAssertSame('older_run', $serialize('report', [
        'reportTemplateId' => $compositeReportId,
    ])['reportRun']['reason'] ?? null, 'Older report runs without snapshots stay visible but cannot be saved.');

    Yii::$app->db->createCommand()->update('report_templates', ['is_active' => 0], ['id' => $compositeReportId])->execute();
    reportRunIntegrationAssertSame('report_retired', $serialize('report', [
        'reportTemplateId' => $compositeReportId,
        'reportReplay' => $validReplay,
    ])['reportRun']['reason'] ?? null, 'A valid replay for a retired report explains why it cannot be saved.');
    Yii::$app->db->createCommand()->update('report_templates', ['is_active' => 1], ['id' => $compositeReportId])->execute();

    reportRunIntegrationAssertSame('report_deleted', $serialize('report', [
        'reportTemplateId' => 99999,
        'reportReplay' => $validReplay,
    ])['reportRun']['reason'] ?? null, 'A valid replay for a missing report explains why it cannot be saved.');
    reportRunIntegrationAssertSame('invalid_snapshot', $serialize('report', [
        'reportTemplateId' => $compositeReportId,
        'reportReplay' => ['inputs' => [], 'outputMode' => 'immediate', 'exportKind' => 'worklist'],
    ])['reportRun']['reason'] ?? null, 'Malformed snapshots are marked invalid.');
    foreach ([['nested' => ['array']], ['nested' => (object) ['object' => true]]] as $invalidInputs) {
        reportRunIntegrationAssertSame('invalid_snapshot', $serialize('report', [
            'reportTemplateId' => $compositeReportId,
            'reportReplay' => [
                'inputs' => (object) $invalidInputs,
                'outputMode' => 'table',
                'exportKind' => 'worklist',
            ],
        ])['reportRun']['reason'] ?? null, 'Replay inputs must contain scalar values only.');
    }
    reportRunIntegrationAssertSame(false, array_key_exists('reportRun', $serialize('builder', [])), 'Non-report history items do not get report eligibility fields.');
});

reportRunIntegrationCase('saving from report history stores a replay snapshot owned by the saver', function () use ($controller, $compositeReportId, $insertReport) {
    $insertJob = function ($jobId, $ownerId, $metadata) {
        Yii::$app->db->createCommand()->insert('query_jobs', [
            'id' => $jobId,
            'sql_text' => 'SELECT :choice AS result',
            'params' => json_encode([':choice' => 'first']),
            'source' => 'report',
            'data_source' => 'composite',
            'user_id' => $ownerId,
            'status' => 'completed',
            'metadata' => json_encode($metadata),
        ])->execute();
    };
    $replay = [
        'inputs' => (object) ['choice' => 'first'],
        'outputMode' => 'table',
        'exportKind' => 'worklist',
    ];

    foreach ([
        ['owner saves own run', 2, 2, false],
        ['another user saves visible run', 4, 3, false],
        ['admin saves visible run', 5, 1, true],
        ['user saves NULL-owner run', null, 6, false],
    ] as $index => $scenario) {
        list($label, $ownerId, $saverId, $isAdmin) = $scenario;
        $jobId = 'replay-save-' . $index;
        $insertJob($jobId, $ownerId, [
            'reportTemplateId' => $compositeReportId,
            'reportReplay' => $replay,
        ]);
        Yii::$app->user->setIdentity(new ReportReplayTestIdentity($saverId, $isAdmin));
        Yii::$app->request->setBodyParams([
            'fromJobId' => $jobId,
            'name' => 'Saved ' . $label,
            'description' => 'Synthetic description',
            'isPinned' => true,
            'generatedSql' => 'SELECT client_sql',
            'queryDefinition' => ['params' => ['client' => 'value']],
            'source' => 'builder',
        ]);
        Yii::$app->response->statusCode = 200;
        $saved = $controller->actionSave();
        reportRunIntegrationAssertSame(201, Yii::$app->response->statusCode, $label . ' should save a visible report run.');
        $row = Yii::$app->db->createCommand('SELECT * FROM saved_queries WHERE id = :id', [':id' => $saved['id']])->queryOne();
        reportRunIntegrationAssertSame($saverId, (int) $row['user_id'], $label . ' should own the saved copy.');
        reportRunIntegrationAssertSame('report', $row['source'], $label . ' should retain report identity.');
        reportRunIntegrationAssertSame('SELECT :choice AS result', $row['generated_sql'], $label . ' should use the source job SQL.');
        reportRunIntegrationAssertSame(1, (int) $row['is_pinned'], $label . ' should honor isPinned.');
        $definition = json_decode($row['query_definition'], true);
        reportRunIntegrationAssertSame([
            'report_template_id' => $compositeReportId,
            'report_replay' => [
                'inputs' => ['choice' => 'first'],
                'outputMode' => 'table',
                'exportKind' => 'worklist',
            ],
            'saved_from_job' => $jobId,
        ], $definition, $label . ' should persist the snapshot and ignore client SQL, params, and source.');
    }

    Yii::$app->user->setIdentity(new ReportReplayTestIdentity(1, true));
    Yii::$app->request->setBodyParams(['fromJobId' => 'missing-report-job', 'name' => 'Missing']);
    Yii::$app->response->statusCode = 200;
    $missing = $controller->actionSave();
    reportRunIntegrationAssertSame(404, Yii::$app->response->statusCode, 'A missing history job returns 404.');
    reportRunIntegrationAssertSame('Job not found', $missing['error'] ?? null, 'A missing history job has the stable error.');

    $insertJob('replay-save-older', 2, ['reportTemplateId' => $compositeReportId]);
    Yii::$app->request->setBodyParams(['fromJobId' => 'replay-save-older', 'name' => 'Older']);
    Yii::$app->response->statusCode = 200;
    $older = $controller->actionSave();
    reportRunIntegrationAssertSame(422, Yii::$app->response->statusCode, 'An older report run cannot be saved.');
    reportRunIntegrationAssertSame('older_run', $older['error'] ?? null, 'Ineligible saves return the history reason.');

    $insertJob('replay-save-invalid', 2, [
        'reportTemplateId' => $compositeReportId,
        'reportReplay' => ['inputs' => [], 'outputMode' => 'bad', 'exportKind' => 'worklist'],
    ]);
    Yii::$app->request->setBodyParams(['fromJobId' => 'replay-save-invalid', 'name' => 'Invalid']);
    Yii::$app->response->statusCode = 200;
    $invalid = $controller->actionSave();
    reportRunIntegrationAssertSame(422, Yii::$app->response->statusCode, 'A malformed replay cannot be saved.');
    reportRunIntegrationAssertSame('invalid_snapshot', $invalid['error'] ?? null, 'Malformed saves return the shared validation reason.');

    $parameterlessReportId = $insertReport('Synthetic Parameterless Report', 'local', [], null, 'SELECT 1');
    Yii::$app->request->setBodyParams(['params' => []]);
    Yii::$app->response->statusCode = 200;
    $parameterlessRun = $controller->actionReportRun($parameterlessReportId);
    $emptyMetadata = json_decode(Yii::$app->db->createCommand(
        'SELECT metadata FROM query_jobs WHERE id = :id',
        [':id' => $parameterlessRun['jobId']]
    )->queryScalar());
    reportRunIntegrationAssertSame(true, is_object($emptyMetadata->reportReplay->inputs ?? null), 'Parameterless runs store an empty inputs object.');
    Yii::$app->request->setBodyParams([
        'fromJobId' => $parameterlessRun['jobId'],
        'name' => 'Saved parameterless report',
    ]);
    Yii::$app->response->statusCode = 200;
    $parameterlessSaved = $controller->actionSave();
    reportRunIntegrationAssertSame(201, Yii::$app->response->statusCode, 'An empty replay input object is valid for parameterless reports.');
    $savedDefinition = json_decode(Yii::$app->db->createCommand(
        'SELECT query_definition FROM saved_queries WHERE id = :id',
        [':id' => $parameterlessSaved['id']]
    )->queryScalar());
    reportRunIntegrationAssertSame(true, is_object($savedDefinition->report_replay->inputs ?? null), 'The saved snapshot keeps an empty input object.');
});

reportRunIntegrationCase('saved report refresh uses the current definition after its source job and export are deleted', function () use ($controller, $insertReport, $runtimeRoot) {
    $reportId = $insertReport(
        'Synthetic Refresh Current Definition',
        'local',
        [['name' => 'term', 'type' => 'text', 'default' => 'old-value']],
        null,
        'SELECT :term AS original'
    );
    Yii::$app->user->setIdentity(new ReportReplayTestIdentity(11, false));
    Yii::$app->request->setBodyParams(['params' => [], 'outputMode' => 'file']);
    Yii::$app->response->statusCode = 200;
    $sourceRun = $controller->actionReportRun($reportId);
    $sourceJobId = $sourceRun['jobId'];
    $exportPath = $runtimeRoot . DIRECTORY_SEPARATOR . 'source-export.csv';
    file_put_contents($exportPath, "synthetic export\n");
    Yii::$app->db->createCommand()->update('query_jobs', ['export_file_path' => $exportPath], ['id' => $sourceJobId])->execute();

    Yii::$app->request->setBodyParams([
        'fromJobId' => $sourceJobId,
        'name' => 'Saved refresh report',
        'isPinned' => true,
    ]);
    Yii::$app->response->statusCode = 200;
    $saved = $controller->actionSave();
    reportRunIntegrationAssertSame(201, Yii::$app->response->statusCode, 'Save the report run before deleting its source.');

    Yii::$app->db->createCommand()->update('report_templates', [
        'sql_template' => 'SELECT :term AS current_value, 7 AS marker',
        'parameters' => json_encode([['name' => 'term', 'type' => 'text', 'default' => 'new-value']]),
    ], ['id' => $reportId])->execute();
    Yii::$app->db->createCommand()->update('saved_queries', ['last_job_id' => 'previous-job'], ['id' => $saved['id']])->execute();
    unlink($exportPath);
    Yii::$app->db->createCommand()->delete('query_jobs', ['id' => $sourceJobId])->execute();

    Yii::$app->request->setBodyParams(['params' => [], 'outputMode' => 'file']);
    Yii::$app->response->statusCode = 200;
    $direct = $controller->actionReportRun($reportId);
    $directJob = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $direct['jobId']])->queryOne();

    Yii::$app->response->statusCode = 200;
    $refresh = $controller->actionDashboardRefresh($saved['id']);
    reportRunIntegrationAssertSame(202, Yii::$app->response->statusCode, 'A saved replay remains refreshable without its source job or export.');
    $refreshJob = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $refresh['jobId']])->queryOne();
    foreach (['sql_text', 'params', 'data_source', 'output_mode', 'history_group_key'] as $field) {
        reportRunIntegrationAssertSame($directJob[$field], $refreshJob[$field], 'Refresh should match a direct run on current report field ' . $field . '.');
    }
    $metadata = json_decode($refreshJob['metadata'], true);
    reportRunIntegrationAssertSame($reportId, $metadata['reportTemplateId'] ?? null, 'Refresh job retains its report identity.');
    reportRunIntegrationAssertSame([
        'inputs' => [],
        'outputMode' => 'file',
        'exportKind' => 'worklist',
    ], $metadata['reportReplay'] ?? null, 'An omitted parameter stays omitted in the saved snapshot.');
    reportRunIntegrationAssertSame($refresh['jobId'], Yii::$app->db->createCommand(
        'SELECT last_job_id FROM saved_queries WHERE id = :id',
        [':id' => $saved['id']]
    )->queryScalar(), 'Update last_job_id only after a successful report refresh.');

    $insertSaved = function ($name, $definition) use ($reportId) {
        Yii::$app->db->createCommand()->insert('saved_queries', [
            'user_id' => 11,
            'name' => $name,
            'description' => '',
            'query_definition' => json_encode($definition),
            'generated_sql' => 'SELECT stored_fallthrough',
            'source' => 'report',
            'last_job_id' => 'previous-job',
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    };
    $validSnapshot = [
        'inputs' => (object) [],
        'outputMode' => 'table',
        'exportKind' => 'worklist',
    ];
    foreach ([
        ['null snapshot', ['report_template_id' => $reportId, 'report_replay' => null]],
        ['scalar snapshot', ['report_template_id' => $reportId, 'report_replay' => 'invalid']],
        ['array inputs', ['report_template_id' => $reportId, 'report_replay' => ['inputs' => [], 'outputMode' => 'table', 'exportKind' => 'worklist']]],
        ['bad output mode', ['report_template_id' => $reportId, 'report_replay' => array_merge($validSnapshot, ['outputMode' => 'running'])]],
        ['bad export kind', ['report_template_id' => $reportId, 'report_replay' => array_merge($validSnapshot, ['exportKind' => 'other'])]],
        ['missing template id', ['report_replay' => $validSnapshot]],
    ] as $index => $invalidCase) {
        $invalidId = $insertSaved('Malformed replay ' . $index, $invalidCase[1]);
        $beforeJobs = (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar();
        Yii::$app->response->statusCode = 200;
        $invalidResult = $controller->actionDashboardRefresh($invalidId);
        reportRunIntegrationAssertSame(422, Yii::$app->response->statusCode, $invalidCase[0] . ' returns 422.');
        reportRunIntegrationAssertSame("This saved report can't be refreshed; save it again from History.", $invalidResult['error'] ?? null, $invalidCase[0] . ' uses the safe error.');
        reportRunIntegrationAssertSame($beforeJobs, (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar(), $invalidCase[0] . ' queues no job.');
        reportRunIntegrationAssertSame('previous-job', Yii::$app->db->createCommand(
            'SELECT last_job_id FROM saved_queries WHERE id = :id', [':id' => $invalidId]
        )->queryScalar(), $invalidCase[0] . ' leaves last_job_id untouched.');
    }

    $retiredId = $insertSaved('Retired report replay', ['report_template_id' => $reportId, 'report_replay' => $validSnapshot]);
    Yii::$app->db->createCommand()->update('report_templates', ['is_active' => 0], ['id' => $reportId])->execute();
    Yii::$app->response->statusCode = 200;
    $retired = $controller->actionDashboardRefresh($retiredId);
    reportRunIntegrationAssertSame(410, Yii::$app->response->statusCode, 'A retired report returns 410.');
    reportRunIntegrationAssertSame('This report has been retired.', $retired['error'] ?? null, 'A retired report uses the existing error.');
    reportRunIntegrationAssertSame('previous-job', Yii::$app->db->createCommand(
        'SELECT last_job_id FROM saved_queries WHERE id = :id', [':id' => $retiredId]
    )->queryScalar(), 'A retired report leaves last_job_id untouched.');
    Yii::$app->db->createCommand()->update('report_templates', ['is_active' => 1], ['id' => $reportId])->execute();

    $deletedId = $insertSaved('Deleted report replay', ['report_template_id' => 99999, 'report_replay' => $validSnapshot]);
    Yii::$app->response->statusCode = 200;
    $deleted = $controller->actionDashboardRefresh($deletedId);
    reportRunIntegrationAssertSame(404, Yii::$app->response->statusCode, 'A deleted report returns 404.');
    reportRunIntegrationAssertSame('This report no longer exists.', $deleted['error'] ?? null, 'A deleted report uses the safe error.');

    $requiredId = $insertReport(
        'Synthetic Required Refresh Report',
        'local',
        [['name' => 'requiredValue', 'label' => 'Required value', 'required' => true]],
        null,
        'SELECT :requiredValue AS result'
    );
    $requiredSavedId = $insertSaved('Missing required replay', [
        'report_template_id' => $requiredId,
        'report_replay' => $validSnapshot,
    ]);
    $beforeRequiredJobs = (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar();
    Yii::$app->response->statusCode = 200;
    $required = $controller->actionDashboardRefresh($requiredSavedId);
    reportRunIntegrationAssertSame(400, Yii::$app->response->statusCode, 'A missing required replay input uses the run response.');
    reportRunIntegrationAssertSame(['error' => 'Missing required parameters: Required value'], $required, 'A plain missing-required response has only error.');
    reportRunIntegrationAssertSame($beforeRequiredJobs, (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar(), 'A missing-required replay queues no job.');
    reportRunIntegrationAssertSame('previous-job', Yii::$app->db->createCommand(
        'SELECT last_job_id FROM saved_queries WHERE id = :id', [':id' => $requiredSavedId]
    )->queryScalar(), 'A missing-required replay leaves last_job_id untouched.');

    $blockedReportId = $insertReport('Synthetic Blocked Refresh Report', 'local', [], null, 'DELETE FROM query_jobs');
    $blockedSavedId = $insertSaved('Blocked policy replay', [
        'report_template_id' => $blockedReportId,
        'report_replay' => $validSnapshot,
    ]);
    $beforeBlockedJobs = (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar();
    Yii::$app->response->statusCode = 200;
    $blocked = $controller->actionDashboardRefresh($blockedSavedId);
    reportRunIntegrationAssertSame(403, Yii::$app->response->statusCode, 'A saved report still uses the local SQL policy.');
    reportRunIntegrationAssertSame('This query is blocked by reporting data policy.', $blocked['error'] ?? null, 'Policy failures keep the run response.');
    reportRunIntegrationAssertSame($beforeBlockedJobs, (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar(), 'A policy failure queues no job.');
    reportRunIntegrationAssertSame('previous-job', Yii::$app->db->createCommand(
        'SELECT last_job_id FROM saved_queries WHERE id = :id', [':id' => $blockedSavedId]
    )->queryScalar(), 'A policy failure leaves last_job_id untouched.');
});

reportRunIntegrationCase('a global report refresh belongs to and is readable by the refreshing user', function () use ($controller, $insertReport, $runtimeRoot) {
    $reportId = $insertReport(
        'Synthetic Global Report',
        'local',
        [['name' => 'term', 'type' => 'text']],
        null,
        'SELECT :term AS result'
    );
    Yii::$app->db->createCommand()->insert('saved_queries', [
        'user_id' => 5,
        'name' => 'Global saved report',
        'description' => '',
        'query_definition' => json_encode([
            'report_template_id' => $reportId,
            'report_replay' => [
                'inputs' => (object) ['term' => 'shared'],
                'outputMode' => 'file',
                'exportKind' => 'worklist',
            ],
        ]),
        'generated_sql' => 'SELECT stored_wrong',
        'source' => 'report',
        'is_pinned' => 1,
        'is_global' => 1,
    ])->execute();
    $savedId = (int) Yii::$app->db->getLastInsertID();

    Yii::$app->user->setIdentity(new ReportReplayTestIdentity(7, false));
    Yii::$app->response->statusCode = 200;
    $refresh = $controller->actionDashboardRefresh($savedId);
    reportRunIntegrationAssertSame(202, Yii::$app->response->statusCode, 'User B can refresh the global report.');
    $refreshJob = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $refresh['jobId']])->queryOne();
    reportRunIntegrationAssertSame(7, (int) $refreshJob['user_id'], 'The refreshed job belongs to user B.');
    reportRunIntegrationAssertSame('local', $refreshJob['data_source'], 'The refreshed job uses the report local data source.');
    reportRunIntegrationAssertSame('SELECT :term AS result' . "\n" . 'LIMIT 100', $refreshJob['sql_text'], 'The refreshed job uses the current report SQL.');

    Yii::$app->user->setIdentity(new ReportReplayTestIdentity(5, false));
    Yii::$app->request->setBodyParams(['params' => ['term' => 'shared'], 'outputMode' => 'file', 'exportKind' => 'worklist']);
    Yii::$app->response->statusCode = 200;
    $direct = $controller->actionReportRun($reportId);
    $directJob = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $direct['jobId']])->queryOne();
    reportRunIntegrationAssertSame($directJob['history_group_key'], $refreshJob['history_group_key'], 'The group key does not include the owner.');
    reportRunIntegrationAssertSame(false,
        \app\services\HistoryGroupKeyService::groupId(5, $refreshJob['history_group_key'])
        === \app\services\HistoryGroupKeyService::groupId(7, $refreshJob['history_group_key']),
        'Different owners get different history group IDs.');

    $exportPath = $runtimeRoot . DIRECTORY_SEPARATOR . 'user-b-report.csv';
    file_put_contents($exportPath, "result\nshared\n");
    Yii::$app->db->createCommand()->update('query_jobs', [
        'status' => 'completed',
        'result_columns' => json_encode(['result']),
        'result_rows' => json_encode([['result' => 'shared']]),
        'row_count' => 1,
        'export_file_path' => $exportPath,
    ], ['id' => $refresh['jobId']])->execute();
    Yii::$app->user->setIdentity(new ReportReplayTestIdentity(7, false));
    $status = $controller->actionQueryStatus($refresh['jobId']);
    reportRunIntegrationAssertSame($refresh['jobId'], $status['jobId'] ?? null, 'User B can read the refreshed job status.');
    reportRunIntegrationAssertSame('/query/export/' . $refresh['jobId'], $status['downloadUrl'] ?? null, 'The refreshed file job exposes its download route.');
    Yii::$app->response->statusCode = 200;
    $download = $controller->actionQueryExport($refresh['jobId']);
    reportRunIntegrationAssertSame(true, $download instanceof Response, 'The existing export route serves the refreshed file.');
    reportRunIntegrationAssertSame('attachment; filename="user-b-report.csv"', Yii::$app->response->headers->get('Content-Disposition'), 'The refreshed report download uses its export file.');
});

reportRunIntegrationCase('report replay queries cannot be promoted while ordinary saved queries still can', function () use ($controller, $compositeReportId) {
    $replayDefinition = [
        'report_template_id' => $compositeReportId,
        'report_replay' => [
            'inputs' => (object) ['choice' => 'first'],
            'outputMode' => 'table',
            'exportKind' => 'worklist',
        ],
        'saved_from_job' => 'synthetic-job',
    ];
    $insertSaved = function ($name, $definition, $source = 'report') {
        Yii::$app->db->createCommand()->insert('saved_queries', [
            'user_id' => 2,
            'name' => $name,
            'description' => '',
            'query_definition' => json_encode($definition),
            'generated_sql' => 'SELECT :choice AS result',
            'source' => $source,
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    };
    $countReports = function () {
        return (int) Yii::$app->db->createCommand('SELECT COUNT(*) FROM report_templates')->queryScalar();
    };

    $savedId = $insertSaved('Saved replay report', $replayDefinition);
    $before = $countReports();
    Yii::$app->response->statusCode = 200;
    $active = $controller->actionSavedPromote($savedId);
    reportRunIntegrationAssertSame(409, Yii::$app->response->statusCode, 'An active report replay cannot be promoted.');
    reportRunIntegrationAssertSame("This saved query already runs the report 'Synthetic Composite Report'.", $active['error'] ?? null, 'An active conflict names the existing report.');
    reportRunIntegrationAssertSame($before, $countReports(), 'An active report replay conflict creates no report.');

    $savedRetiredId = $insertSaved('Saved retired replay report', $replayDefinition);
    Yii::$app->db->createCommand()->update('report_templates', ['is_active' => 0], ['id' => $compositeReportId])->execute();
    Yii::$app->response->statusCode = 200;
    $retired = $controller->actionSavedPromote($savedRetiredId);
    reportRunIntegrationAssertSame(409, Yii::$app->response->statusCode, 'A retired report replay cannot be promoted.');
    reportRunIntegrationAssertSame('This saved query already runs a report that has been retired or deleted.', $retired['error'] ?? null, 'Retired reports use the safe conflict message.');

    $savedDeletedId = $insertSaved('Saved deleted replay report', $replayDefinition);
    Yii::$app->db->createCommand()->delete('report_templates', ['id' => $compositeReportId])->execute();
    Yii::$app->response->statusCode = 200;
    $deleted = $controller->actionSavedPromote($savedDeletedId);
    reportRunIntegrationAssertSame(409, Yii::$app->response->statusCode, 'A deleted report replay cannot be promoted.');
    reportRunIntegrationAssertSame('This saved query already runs a report that has been retired or deleted.', $deleted['error'] ?? null, 'Deleted reports use the safe conflict message.');

    $ordinaryId = $insertSaved('Ordinary saved query', [], 'builder');
    Yii::$app->response->statusCode = 200;
    $ordinary = $controller->actionSavedPromote($ordinaryId);
    reportRunIntegrationAssertSame(201, Yii::$app->response->statusCode, 'Ordinary saved queries remain promotable.');
    reportRunIntegrationAssertSame(true, isset($ordinary['id']), 'Ordinary promotion still creates a report.');
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

fwrite(STDOUT, "Report run controller tests passed (9 cases).\n");
