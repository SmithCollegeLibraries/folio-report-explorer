<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
class_exists(\yii\db\ActiveRecord::class);
require_once __DIR__ . '/support/CatalogingMarcCombinationFinderFixture.php';
require_once __DIR__ . '/../services/CatalogingMarcCombinationFinderService.php';
require_once __DIR__ . '/../services/HistoryGroupKeyService.php';

use app\commands\ExportWorkerController;
use app\controllers\FolioQueryController;
use app\models\DummyIdentity;
use app\services\HistoryGroupKeyService;
use yii\db\Connection;
use yii\web\Application;
use yii\web\Request;
use yii\web\Response;

class MarcCombinationControllerSqliteConnection extends Connection
{
    public function createCommand($sql = null, $params = [])
    {
        if (is_string($sql)) {
            if (preg_match('/^\s*SET statement_timeout\b/i', $sql) === 1) {
                $sql = 'SELECT 1';
                $params = [];
            } elseif (strpos($sql, 'EXPLAIN (FORMAT JSON) ') === 0) {
                $sql = 'SELECT \'[{"Plan":{"Plan Rows":1,"Total Cost":1}}]\' AS "QUERY PLAN"';
                $params = [];
            } elseif (strpos($sql, 'to_regclass(') !== false) {
                $sql = "SELECT (SELECT name FROM marctab.sqlite_master WHERE type = 'table' AND name = SUBSTR(:table_name, 9)) AS \"to_regclass\"";
            } elseif (strpos($sql, "SELECT set_config('application_name'") === 0) {
                $sql = 'SELECT :application_name';
            } elseif (strpos($sql, 'SELECT pg_backend_pid()') === 0) {
                $sql = 'SELECT 1 AS pg_backend_pid';
                $params = [];
            }
            $sql = str_replace('id::text AS id', 'id AS id', $sql);
        }
        return parent::createCommand($sql, $params);
    }
}

$failures = [];

function marcCombinationControllerCase(string $name, callable $callback): void
{
    global $failures;
    $before = count($failures);
    try {
        $callback();
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . get_class($error) . ': ' . $error->getMessage();
        fwrite(STDERR, "FAIL: {$name}: " . $error->getMessage() . "\n");
    }
    if (count($failures) === $before) {
        fwrite(STDOUT, "PASS: {$name}\n");
    }
}

function marcCombinationControllerSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        global $failures;
        $failures[] = $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true);
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function marcCombinationControllerRun(FolioQueryController $controller, int $reportId, array $params, string $exportKind = 'worklist'): array
{
    Yii::$app->request->setBodyParams(['params' => $params, 'exportKind' => $exportKind]);
    Yii::$app->response->statusCode = 200;
    $body = $controller->actionReportRun($reportId);
    return ['status' => Yii::$app->response->statusCode, 'body' => $body];
}

function marcCombinationControllerJob(string $jobId): array
{
    $row = Yii::$app->db->createCommand('SELECT * FROM query_jobs WHERE id = :id', [':id' => $jobId])->queryOne();
    if (!is_array($row)) {
        throw new RuntimeException('The report controller did not persist the query job.');
    }
    return $row;
}

function marcCombinationControllerAssertBindings(array $job): void
{
    $sql = (string) ($job['sql_text'] ?? '');
    $params = json_decode((string) ($job['params'] ?? ''), true);
    if (!is_array($params)) {
        throw new RuntimeException('The controller did not persist a JSON parameter map for the worker.');
    }
    $withoutCasts = preg_replace('/::[A-Za-z][A-Za-z0-9_]*(?:\[\])?/', '', $sql);
    preg_match_all('/(?<!:):([A-Za-z][A-Za-z0-9_]*)/', (string) $withoutCasts, $matches);
    $placeholders = array_values(array_unique(array_map(function ($name) { return ':' . $name; }, $matches[1] ?? [])));
    $boundNames = array_keys($params);
    sort($placeholders, SORT_STRING);
    sort($boundNames, SORT_STRING);
    marcCombinationControllerSame($placeholders, $boundNames, 'The controller must persist exactly the bindings the worker SQL uses.');
}

function marcCombinationControllerTranslateQueuedSql(string $jobId): void
{
    $row = Yii::$app->db->createCommand('SELECT sql_text FROM query_jobs WHERE id = :id', [':id' => $jobId])->queryOne();
    $sqliteSql = marcCombinationFixtureSqliteSql((string) ($row['sql_text'] ?? ''));
    Yii::$app->db->createCommand()->update('query_jobs', ['sql_text' => $sqliteSql], ['id' => $jobId])->execute();
}

function marcCombinationControllerParameters(array $overrides = []): array
{
    $inputs = marcCombinationFixtureInputs(array_merge([
        'c1_contentRule' => 'contains',
        'c1_searchValue' => "Needle' OR 1=1 --",
        'c1_subfieldCode' => 'a',
        'c2_marcTag' => '563',
        'c2_occurrenceCondition' => 'missing',
        'c2_subfieldCode' => 'a',
    ], $overrides));
    for ($slot = 3; $slot <= 5; $slot++) {
        foreach (['marcTag', 'occurrenceCondition', 'firstIndicator', 'secondIndicator', 'subfieldCode', 'contentRule', 'searchValue', 'caseExact'] as $field) {
            $inputs['c' . $slot . '_' . $field] = '';
        }
    }
    return array_merge($inputs, $overrides);
}

$runtimeRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'marc-combination-controller-worker-' . bin2hex(random_bytes(8));
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
    'id' => 'marc-combination-controller-worker-test',
    'basePath' => dirname(__DIR__),
    'runtimePath' => $runtimeRoot,
    'components' => [
        'request' => ['class' => Request::class, 'cookieValidationKey' => 'synthetic-test-key', 'enableCsrfValidation' => false],
        'response' => ['class' => Response::class],
        'user' => ['identityClass' => DummyIdentity::class, 'enableSession' => false],
        'db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => MarcCombinationControllerSqliteConnection::class, 'dsn' => 'sqlite::memory:'],
    ],
    'params' => [
        'maxQueryRows' => 100,
        'queryTimeoutMs' => 1000,
        'exportRowLimit' => 100,
        'exportPreviewRows' => 5,
    ],
]);
Yii::$app->errorHandler->unregister();
Yii::$app->user->setIdentity(new DummyIdentity());

$db = Yii::$app->db;
$db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id TEXT PRIMARY KEY, sql_text TEXT NOT NULL, sql_hash TEXT, params TEXT, source TEXT, data_source TEXT,
    name TEXT, user_id INTEGER, status TEXT, result_columns TEXT, result_rows TEXT, row_count INTEGER,
    execution_time_ms INTEGER, error_message TEXT, progress_message TEXT, pg_backend_pid INTEGER,
    output_mode TEXT, export_file_path TEXT, estimated_rows INTEGER, estimated_cost REAL, metadata TEXT,
    created_at TEXT, started_at TEXT, completed_at TEXT, history_group_key TEXT
)
SQL
)->execute();
$db->createCommand('CREATE TABLE query_log (id INTEGER PRIMARY KEY AUTOINCREMENT, sql_text TEXT, params TEXT, source TEXT, data_source TEXT, user_id INTEGER, row_count INTEGER, execution_time_ms INTEGER, error_message TEXT)')->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE report_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, name TEXT, description TEXT, help_text TEXT, category TEXT,
    sql_template TEXT, parameters TEXT, data_source TEXT, composite_config TEXT, execution_config TEXT,
    default_limit INTEGER, is_active INTEGER, created_by TEXT, created_at TEXT, updated_at TEXT
)
SQL
)->execute();

$folioDb = Yii::$app->folioDb;
$folioDb->open();
$folioPdo = $folioDb->getMasterPdo();
$folioPdo->exec("ATTACH DATABASE ':memory:' AS inventory");
$folioPdo->exec("ATTACH DATABASE ':memory:' AS marctab");
$folioPdo->sqliteCreateFunction('LEFT_FN', function ($value, $length) { return substr((string) $value, 0, (int) $length); }, 2);
$folioPdo->sqliteCreateFunction('regexp', function ($pattern, $value) {
    return preg_match('/' . str_replace('/', '\\/', (string) $pattern) . '/', (string) $value) === 1 ? 1 : 0;
}, 2);
$folioPdo->exec('CREATE TABLE inventory.location__t (id TEXT PRIMARY KEY, name TEXT, code TEXT, library_id TEXT)');
$folioPdo->exec('CREATE TABLE inventory.instance__t (id TEXT PRIMARY KEY, hrid TEXT, title TEXT, source TEXT)');
$folioPdo->exec('CREATE TABLE inventory.holdings_record__t (id TEXT PRIMARY KEY, instance_id TEXT)');
$folioPdo->exec('CREATE TABLE inventory.item__t (id TEXT PRIMARY KEY, holdings_record_id TEXT, effective_location_id TEXT, permanent_location_id TEXT)');
foreach (['655', '563', '856'] as $tag) {
    $folioPdo->exec("CREATE TABLE marctab.mt{$tag} (instance_id TEXT, ind1 TEXT, ind2 TEXT, ord INTEGER, line INTEGER, sf TEXT, content TEXT)");
}
$locationId = '11111111-1111-4111-8111-111111111111';
$folioPdo->exec("INSERT INTO inventory.location__t (id,name,code) VALUES ('{$locationId}','Main','MAIN')");
$insertInstance = $folioPdo->prepare('INSERT INTO inventory.instance__t VALUES (?, ?, ?, ?)');
$insertHolding = $folioPdo->prepare('INSERT INTO inventory.holdings_record__t VALUES (?, ?)');
$insertItem = $folioPdo->prepare('INSERT INTO inventory.item__t VALUES (?, ?, ?, ?)');
$insertMarc = $folioPdo->prepare('INSERT INTO marctab.mt655 VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ([
    ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'in0001', 'Alpha', "Needle' OR 1=1 --"],
    ['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'in0002', 'Beta', 'Needle'],
] as $index => $instance) {
    $insertInstance->execute([$instance[0], $instance[1], $instance[2], 'MARC']);
    $holdingId = 'holding-' . $index;
    $insertHolding->execute([$holdingId, $instance[0]]);
    $insertItem->execute(['item-' . $index, $holdingId, $locationId, $locationId]);
    $insertMarc->execute([$instance[0], '7', ' ', 1, 1, 'a', $instance[3]]);
}

$reportDefinition = marcCombinationFixtureSeed();
$db->createCommand()->insert('report_templates', array_merge($reportDefinition, ['id' => 26]))->execute();
$reportId = 26;
$controller = new FolioQueryController('folio-query', Yii::$app);
$exportWorker = new ExportWorkerController('export-worker', Yii::$app);

marcCombinationControllerCase('controller parameters survive into the export worker and are evaluated by synthetic SQLite MARC rows', function () use ($controller, $exportWorker, $reportId) {
    $result = marcCombinationControllerRun($controller, $reportId, marcCombinationControllerParameters());
    marcCombinationControllerSame(202, $result['status'], 'A valid combination request is queued.');
    $jobId = (string) ($result['body']['jobId'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('The controller response omitted the queued job ID.');
    }
    $job = marcCombinationControllerJob($jobId);
    marcCombinationControllerSame('pending_export', $job['status'] ?? null, 'Governed report runs go through the export worker.');
    marcCombinationControllerAssertBindings($job);
    $params = json_decode((string) $job['params'], true);
    marcCombinationControllerSame("Needle' OR 1=1 --", $params[':c1_searchValue'] ?? null, 'The controller persists adversarial search text as a bound value.');
    marcCombinationControllerSame(false, array_key_exists(':c3_searchValue', $params), 'Inactive condition bindings do not reach the worker.');

    marcCombinationControllerTranslateQueuedSql($jobId);
    $exportWorker->actionProcessOnce();
    $job = marcCombinationControllerJob($jobId);
    marcCombinationControllerSame('completed', $job['status'] ?? null, 'The export worker executes the persisted SQL and bindings.');
    marcCombinationControllerSame(1, (int) ($job['row_count'] ?? 0), 'Bound search text selects only the matching synthetic instance.');
    $rows = json_decode((string) ($job['result_rows'] ?? ''), true);
    marcCombinationControllerSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $rows[0]['Instance UUID'] ?? null, 'The worker preview contains the SQL-matched instance.');
    $csv = is_file((string) ($job['export_file_path'] ?? '')) ? fopen((string) $job['export_file_path'], 'r') : false;
    if (!is_resource($csv)) {
        marcCombinationControllerSame(true, false, 'The worker created the worklist CSV.');
    } else {
        $headers = fgetcsv($csv);
        $row = fgetcsv($csv);
        fclose($csv);
        marcCombinationControllerSame('Instance UUID', $headers[0] ?? null, 'Worklist CSV columns come from the query result.');
        marcCombinationControllerSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $row[0] ?? null, 'The worklist CSV contains the worker-evaluated identifier.');
    }
});

marcCombinationControllerCase('the export worker writes 100000 rows and consumes the 100001st as the truncation sentinel', function () use ($exportWorker) {
    $jobId = 'cap-' . bin2hex(random_bytes(8));
    $contract = [
        'reportTemplateId' => 26,
        'reportSlug' => 'marc-field-combination-finder',
        'publicRowCap' => 100000,
        'fetchRowLimit' => 100001,
        'preserveExportOrder' => true,
        'exportKind' => 'identifier',
        'identifierExport' => ['sourceColumn' => 'Instance UUID', 'header' => 'UUID'],
        'downloadFilename' => 'marc-field-combination-finder-cap-test-folio-uuids.csv',
    ];
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $jobId,
        'sql_text' => "WITH RECURSIVE instances(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM instances WHERE n < 100001) SELECT printf('00000000-0000-4000-8000-%012d', n) AS \"Instance UUID\" FROM instances ORDER BY n LIMIT 100001",
        'params' => '{}',
        'source' => 'report_template',
        'data_source' => 'folio',
        'name' => 'MARC Field Combination Finder cap test',
        'user_id' => 1,
        'status' => 'pending_export',
        'output_mode' => 'file',
        'metadata' => json_encode(['reportExecution' => $contract]),
    ])->execute();

    $exportWorker->actionProcessOnce();
    $job = marcCombinationControllerJob($jobId);
    marcCombinationControllerSame('completed', $job['status'] ?? null, 'The synthetic sentinel export completes.');
    marcCombinationControllerSame(100000, (int) ($job['row_count'] ?? 0), 'The public export contains exactly 100000 identifiers.');
    $metadata = json_decode((string) ($job['metadata'] ?? ''), true);
    marcCombinationControllerSame(true, $metadata['reportExecution']['truncated'] ?? null, 'The 100001st fetched row marks the export truncated.');

    $csv = is_file((string) ($job['export_file_path'] ?? '')) ? fopen((string) $job['export_file_path'], 'r') : false;
    if (!is_resource($csv)) {
        marcCombinationControllerSame(true, false, 'The capped export CSV exists.');
        return;
    }
    $header = fgetcsv($csv);
    $dataRows = 0;
    $first = null;
    $last = null;
    while (($row = fgetcsv($csv)) !== false) {
        $dataRows++;
        $first = $first ?? ($row[0] ?? null);
        $last = $row[0] ?? null;
    }
    fclose($csv);
    marcCombinationControllerSame(['UUID'], $header, 'The identifier export keeps its configured UUID header.');
    marcCombinationControllerSame(100000, $dataRows, 'The sentinel is not written to the public CSV.');
    marcCombinationControllerSame('00000000-0000-4000-8000-000000000001', $first, 'The export begins with the first ordered identifier.');
    marcCombinationControllerSame('00000000-0000-4000-8000-000000100000', $last, 'The export excludes the 100001st sentinel identifier.');
});

marcCombinationControllerCase('controller returns the safe condition-specific missing-table message', function () use ($controller, $reportId) {
    $result = marcCombinationControllerRun($controller, $reportId, marcCombinationControllerParameters(['c2_marcTag' => '999']));
    marcCombinationControllerSame(422, $result['status'], 'A missing condition table is a report integrity failure.');
    marcCombinationControllerSame("MARC tag 999 (condition 2) isn't available in the reporting database.", $result['body']['error'] ?? null, 'The API names only the tag and condition number.');
});

marcCombinationControllerCase('controller titles and history identity include active tags and combine mode only', function () use ($controller, $reportId) {
    $base = marcCombinationControllerParameters(['c3_marcTag' => '', 'c3_searchValue' => 'ignored']);
    $all = marcCombinationControllerRun($controller, $reportId, $base);
    marcCombinationControllerSame(202, $all['status'], 'Queue the two-condition AND run.');
    $allJob = marcCombinationControllerJob((string) $all['body']['jobId']);
    $allParams = marcCombinationControllerParameters($base);
    $identity = ['locationIds' => $allParams['locationIds'], 'locationBasis' => $allParams['locationBasis'], 'combine' => 'all'];
    for ($slot = 1; $slot <= 2; $slot++) {
        foreach (['marcTag','occurrenceCondition','firstIndicator','secondIndicator','subfieldCode','contentRule','searchValue','caseExact'] as $field) {
            $key = 'c' . $slot . '_' . $field;
            $identity[$key] = $allParams[$key];
        }
    }
    $expectedKey = HistoryGroupKeyService::build($reportId, $identity, 'worklist', 'file');
    marcCombinationControllerSame($expectedKey, $allJob['history_group_key'] ?? null, 'History grouping uses the compiler identity map without inactive slots.');
    if (strpos((string) $allJob['name'], '655') === false || strpos((string) $allJob['name'], '563') === false
        || (strpos((string) $allJob['name'], 'AND') === false && strpos((string) $allJob['name'], 'All conditions') === false)) {
        marcCombinationControllerSame(true, false, 'The run title lists active tags and the AND mode.');
    }
    if (strpos((string) $allJob['name'], 'ignored') !== false) {
        marcCombinationControllerSame(false, true, 'Inactive-slot values do not appear in the run title.');
    }

    $differentTag = marcCombinationControllerRun($controller, $reportId, marcCombinationControllerParameters(['c2_marcTag' => '856']));
    $differentMode = marcCombinationControllerRun($controller, $reportId, marcCombinationControllerParameters(['combine' => 'any']));
    $sameActiveSearch = marcCombinationControllerRun($controller, $reportId, marcCombinationControllerParameters(['c3_searchValue' => 'different ignored value']));
    foreach ([$differentTag, $differentMode, $sameActiveSearch] as $queued) {
        marcCombinationControllerSame(202, $queued['status'], 'Each history identity request queues successfully.');
    }
    $tagJob = marcCombinationControllerJob((string) $differentTag['body']['jobId']);
    $modeJob = marcCombinationControllerJob((string) $differentMode['body']['jobId']);
    $inactiveJob = marcCombinationControllerJob((string) $sameActiveSearch['body']['jobId']);
    if ($allJob['history_group_key'] === $tagJob['history_group_key']) {
        marcCombinationControllerSame(false, true, 'Changing an active MARC tag creates a different history group.');
    }
    if ($allJob['history_group_key'] === $modeJob['history_group_key']) {
        marcCombinationControllerSame(false, true, 'Changing AND to OR creates a different history group.');
    }
    marcCombinationControllerSame($allJob['history_group_key'], $inactiveJob['history_group_key'], 'Inactive slot values do not split history groups.');
});

marcCombinationControllerCase('identifier export uses the compiler tag list in its safe filename and emits one UUID column', function () use ($controller, $exportWorker, $reportId) {
    $result = marcCombinationControllerRun($controller, $reportId, marcCombinationControllerParameters(), 'identifier');
    marcCombinationControllerSame(202, $result['status'], 'Queue the identifier export.');
    $jobId = (string) $result['body']['jobId'];
    $job = marcCombinationControllerJob($jobId);
    $metadata = json_decode((string) $job['metadata'], true);
    $contract = $metadata['reportExecution'] ?? [];
    marcCombinationControllerSame('marc-field-combination-finder-655-563-main-main-folio-uuids.csv', $contract['downloadFilename'] ?? null, 'The filename normalizes the active tag list as one safe component.');
    marcCombinationControllerTranslateQueuedSql($jobId);
    $exportWorker->actionProcessOnce();
    $job = marcCombinationControllerJob($jobId);
    marcCombinationControllerSame('completed', $job['status'] ?? null, 'The identifier export is streamed by the same worker.');
    $csv = is_file((string) ($job['export_file_path'] ?? '')) ? fopen((string) $job['export_file_path'], 'r') : false;
    if (!is_resource($csv)) {
        marcCombinationControllerSame(true, false, 'The worker created the identifier CSV.');
    } else {
        $headers = fgetcsv($csv);
        $row = fgetcsv($csv);
        fclose($csv);
        marcCombinationControllerSame(['UUID'], $headers, 'Identifier CSV contains only its configured header.');
        marcCombinationControllerSame(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], $row, 'Identifier CSV projects the instance UUID.');
    }
});

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " MARC combination controller/worker cases failed.\n");
    exit(1);
}

echo "PASS: MARC combination controller/worker contracts\n";
