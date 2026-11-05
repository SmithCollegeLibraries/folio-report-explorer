<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\commands\ExportWorkerController;
use app\models\QueryJob;
use app\services\ReportExecutionContractService;
use yii\db\Command;
use yii\db\Connection;
use yii\console\Application;

class ExportWorkerReadOnlyModeCommand extends Command
{
    public function execute()
    {
        $result = parent::execute();
        $this->db->events[] = ['type' => 'read_only_set'];
        return $result;
    }
}

class ExportWorkerFolioPdo extends PDO
{
    public $connection;

    #[ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        $this->connection->events[] = ['type' => 'prepare', 'sql' => $query];

        if (preg_match('/^\s*(INSERT|UPDATE|DELETE|TRUNCATE)\b/i', $query)) {
            $readOnly = (int) $this->query('PRAGMA query_only')->fetchColumn() === 1;
            if ($readOnly) {
                throw new PDOException('write rejected by read-only session state');
            }
        }

        if ($this->connection->failNextReportPrepare && strpos($query, 'AS source') !== false) {
            $this->connection->failNextReportPrepare = false;
            throw new PDOException('SSL syscall error: EOF detected');
        }

        return parent::prepare($query, $options);
    }
}

class ExportWorkerFolioConnection extends Connection
{
    public $events = [];
    public $failNextReportPrepare = false;

    protected function createPdoInstance()
    {
        $pdo = new ExportWorkerFolioPdo('sqlite::memory:');
        $pdo->connection = $this;
        return $pdo;
    }

    public function createCommand($sql = null, $params = [])
    {
        if ($sql === 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY') {
            return new ExportWorkerReadOnlyModeCommand(['db' => $this, 'sql' => 'PRAGMA query_only = ON']);
        }
        if (is_string($sql) && strpos($sql, 'SET statement_timeout = ') === 0) {
            return parent::createCommand('SELECT 1');
        }
        if (is_string($sql) && strpos($sql, "SELECT set_config('application_name'") === 0) {
            return parent::createCommand('SELECT 1');
        }
        if ($sql === 'SELECT pg_backend_pid()') {
            return parent::createCommand('SELECT 123 AS pg_backend_pid');
        }

        return parent::createCommand($sql, $params);
    }
}

$localReportUser = bin2hex(random_bytes(8));
$localReportPassword = bin2hex(random_bytes(16));
putenv('LOCAL_REPORT_DB_USER=' . $localReportUser);
putenv('LOCAL_REPORT_DB_PASS=' . $localReportPassword);

$exportWorkerContractRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'export-worker-report-contract-' . bin2hex(random_bytes(8));
mkdir($exportWorkerContractRoot, 0700, true);
register_shutdown_function(static function () use ($exportWorkerContractRoot): void {
    if (!is_dir($exportWorkerContractRoot)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($exportWorkerContractRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($exportWorkerContractRoot);
});

new Application([
    'id' => 'export-worker-report-contract-test',
    'basePath' => dirname(__DIR__),
    'runtimePath' => $exportWorkerContractRoot,
    'controllerNamespace' => 'app\\commands',
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'localReportDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => ExportWorkerFolioConnection::class, 'dsn' => 'sqlite::memory:'],
    ],
    'params' => [
        'queryTimeoutMs' => 30000,
        'exportRowLimit' => 99,
        'exportPreviewRows' => 2,
    ],
]);

$queryJobsTableSql = <<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    sql_text TEXT NOT NULL,
    sql_hash VARCHAR(64) NULL,
    params TEXT NULL,
    source VARCHAR(20) DEFAULT 'manual',
    data_source VARCHAR(20) DEFAULT 'folio',
    name VARCHAR(255) NULL,
    user_id INTEGER NULL,
    status VARCHAR(20) DEFAULT 'pending',
    result_columns TEXT NULL,
    result_rows TEXT NULL,
    row_count INTEGER DEFAULT 0,
    execution_time_ms INTEGER DEFAULT 0,
    error_message TEXT NULL,
    progress_message VARCHAR(255) NULL,
    pg_backend_pid INTEGER NULL,
    output_mode VARCHAR(20) DEFAULT 'table',
    export_file_path VARCHAR(500) NULL,
    estimated_rows INTEGER NULL,
    estimated_cost NUMERIC NULL,
    metadata TEXT NULL,
    created_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL
)
SQL;
Yii::$app->db->createCommand($queryJobsTableSql)->execute();
Yii::$app->db->createCommand('CREATE TABLE query_log (id INTEGER PRIMARY KEY AUTOINCREMENT, sql_text TEXT, params TEXT, source VARCHAR(20), data_source VARCHAR(20), user_id INTEGER, row_count INTEGER, execution_time_ms INTEGER, error_message TEXT)')->execute();

function exportContractAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function exportContractAssertTrue($actual, string $message): void
{
    exportContractAssertSame(true, $actual, $message);
}

function exportContract(): array
{
    return [
        'reportTemplateId' => 38,
        'reportSlug' => 'marc-bibliographic-records-missing-tag',
        'publicRowCap' => 2,
        'fetchRowLimit' => 3,
        'preserveExportOrder' => true,
        'exportKind' => 'worklist',
        'identifierExport' => ['sourceColumn' => 'Instance UUID', 'header' => 'UUID'],
        'downloadFilename' => 'marc-bibliographic-records-missing-tag-856-sc-main-worklist.csv',
    ];
}

function runExportContractJob(string $id, string $sql, ?array $contract = null): QueryJob
{
    $metadata = $contract === null ? null : json_encode([ReportExecutionContractService::METADATA_KEY => $contract]);
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => $sql,
        'params' => '{}',
        'source' => 'report',
        'data_source' => 'local',
        'status' => 'running',
        'output_mode' => 'file',
        'metadata' => $metadata,
        'created_at' => '2026-08-06 10:00:00',
        'started_at' => '2026-08-06 10:00:01',
    ])->execute();
    $job = QueryJob::findOne($id);
    $worker = new ExportWorkerController('export-worker', Yii::$app);
    $method = new ReflectionMethod($worker, 'executeExportJob');
    $method->setAccessible(true);
    $method->invoke($worker, $job);
    $job->refresh();
    return $job;
}

function runFolioExportJob(string $id, string $sql): QueryJob
{
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => $sql,
        'params' => '{}',
        'source' => 'report',
        'data_source' => 'folio',
        'status' => 'running',
        'output_mode' => 'file',
        'created_at' => '2026-08-06 10:00:00',
        'started_at' => '2026-08-06 10:00:01',
    ])->execute();
    $job = QueryJob::findOne($id);
    $worker = new ExportWorkerController('export-worker', Yii::$app);
    $method = new ReflectionMethod($worker, 'executeExportJob');
    $method->setAccessible(true);
    $method->invoke($worker, $job);
    $job->refresh();
    return $job;
}

$rowsSql = <<<'SQL'
SELECT '11111111-1111-4111-8111-111111111111' AS "Instance UUID", 'hrid-1' AS "Instance HRID", 'Alpha' AS "Title", 'Main' AS "Selected Location", 'Effective item' AS "Location Basis", '856' AS "Missing MARC Tag"
UNION ALL SELECT '11111111-1111-4111-8111-111111111111', 'hrid-2', 'Beta', 'Main', 'Effective item', '856'
UNION ALL SELECT 'not-a-uuid', 'hrid-3', 'Gamma', 'Main', 'Effective item', '856'
ORDER BY "Title" LIMIT 3
SQL;

$worklist = runExportContractJob('worklist-export', $rowsSql, exportContract());
exportContractAssertSame('completed', $worklist->status, 'Governed worklist exports must complete.');
exportContractAssertSame(2, $worklist->row_count, 'Governed worklist exports must omit the sentinel row.');
exportContractAssertTrue($worklist->toStatusArray(true)['truncated'], 'The third source row must mark the governed export as truncated.');
exportContractAssertSame(
    ['Instance UUID', 'Instance HRID', 'Title', 'Selected Location', 'Location Basis', 'Missing MARC Tag'],
    $worklist->getDecodedColumns(),
    'Worklist preview headers must retain the original six-column shape.'
);
$worklistContents = file_get_contents($worklist->export_file_path);
exportContractAssertSame(3, count(array_filter(explode("\n", trim($worklistContents)))), 'Worklist CSV must write the header and only its two retained data rows.');

$identifierContract = exportContract();
$identifierContract['exportKind'] = 'identifier';
$identifierContract['downloadFilename'] = 'marc-bibliographic-records-missing-tag-856-sc-main-folio-uuids.csv';
$identifierRowsSql = <<<'SQL'
SELECT '11111111-1111-4111-8111-111111111111' AS "Instance UUID", 'hrid-1' AS "Instance HRID", 'Alpha' AS "Title", 'Main' AS "Selected Location", 'Effective item' AS "Location Basis", '856' AS "Missing MARC Tag"
UNION ALL SELECT 'not-a-uuid', 'hrid-2', 'Beta', 'Main', 'Effective item', '856'
UNION ALL SELECT '11111111-1111-4111-8111-111111111111', 'hrid-3', 'Gamma', 'Main', 'Effective item', '856'
ORDER BY "Title" LIMIT 3
SQL;
$identifier = runExportContractJob('identifier-export', $identifierRowsSql, $identifierContract);
exportContractAssertSame(1, $identifier->row_count, 'Identifier projection must deduplicate valid UUIDs after sentinel detection.');
exportContractAssertTrue($identifier->toStatusArray(true)['truncated'], 'Sentinel detection must precede identifier deduplication.');
exportContractAssertSame(1, $identifier->toStatusArray(true)['identifierSkippedCount'] ?? null, 'Identifier exports must surface non-conforming source identifiers that were skipped.');
exportContractAssertSame(['UUID'], $identifier->getDecodedColumns(), 'Identifier previews must have UUID-only headers.');
exportContractAssertSame([['UUID' => '11111111-1111-4111-8111-111111111111']], $identifier->getDecodedRows(), 'Identifier previews must contain only projected UUID rows.');
$identifierContents = file_get_contents($identifier->export_file_path);
exportContractAssertSame("UUID\r\n11111111-1111-4111-8111-111111111111\r\n", $identifierContents, 'Identifier exports must use CRLF and UUID-only output.');

/** @var ExportWorkerFolioConnection $folioDb */
$folioDb = Yii::$app->folioDb;
exportContractAssertSame([], $folioDb->events, 'Local exports must not issue FOLIO session read-only commands.');

$folioSql = "SELECT 'folio' AS source";
$folioJob = runFolioExportJob('folio-export', $folioSql);
exportContractAssertSame('completed', $folioJob->status, 'FOLIO file exports must complete.');
$expectedFolioExportSql = $folioSql . "\nLIMIT 99";
$readOnlyEvents = [];
$reportPrepareEvents = [];
foreach ($folioDb->events as $index => $event) {
    if ($event['type'] === 'read_only_set') {
        $readOnlyEvents[] = $index;
    }
    if ($event['type'] === 'prepare' && $event['sql'] === $expectedFolioExportSql) {
        $reportPrepareEvents[] = $index;
    }
}
exportContractAssertSame(1, count($readOnlyEvents), 'Each FOLIO export attempt must set the session read-only.');
exportContractAssertSame(1, count($reportPrepareEvents), 'The FOLIO report SQL must be prepared once on the successful first attempt.');
exportContractAssertTrue($readOnlyEvents[0] < $reportPrepareEvents[0], 'The FOLIO session must be read-only before report SQL preparation.');

$folioDb->events = [];
$folioDb->failNextReportPrepare = true;
$retriedFolioJob = runFolioExportJob('folio-export-retry', $folioSql);
exportContractAssertSame('completed', $retriedFolioJob->status, 'A FOLIO export must recover after a transient reconnect.');
$readOnlyEvents = [];
$reportPrepareEvents = [];
foreach ($folioDb->events as $index => $event) {
    if ($event['type'] === 'read_only_set') {
        $readOnlyEvents[] = $index;
    }
    if ($event['type'] === 'prepare' && $event['sql'] === $expectedFolioExportSql) {
        $reportPrepareEvents[] = $index;
    }
}
exportContractAssertSame(2, count($readOnlyEvents), 'A reconnect retry must reapply session read-only mode on both attempts.');
exportContractAssertSame(2, count($reportPrepareEvents), 'The report SQL must be prepared again after reconnect.');
exportContractAssertTrue($readOnlyEvents[0] < $reportPrepareEvents[0], 'The first attempt must set read-only before preparing the report SQL.');
exportContractAssertTrue($readOnlyEvents[1] < $reportPrepareEvents[1], 'The retry must set read-only before preparing the report SQL.');

$folioDb->events = [];
$writeJob = runFolioExportJob('folio-export-write-attempt', "INSERT INTO query_jobs (id) VALUES ('blocked-write')");
exportContractAssertSame('failed', $writeJob->status, 'A write through the FOLIO export path must be rejected.');
exportContractAssertTrue(strpos((string) $writeJob->error_message, 'write rejected by read-only session state') !== false, 'The fake FOLIO connection must reject writes based on the session state set by the worker.');

$worker = new ExportWorkerController('export-worker', Yii::$app);
$prepareExportSql = new ReflectionMethod($worker, 'prepareExportSql');
$prepareExportSql->setAccessible(true);
exportContractAssertSame($rowsSql, $prepareExportSql->invoke($worker, $worklist, 99), 'Governed export SQL must preserve its reviewed ORDER BY and sentinel limit.');
$ordinaryJob = new QueryJob();
$ordinaryJob->sql_text = 'SELECT value FROM sample ORDER BY value LIMIT 7';
$ordinaryJob->metadata = null;
exportContractAssertSame('SELECT value FROM sample LIMIT 99', $prepareExportSql->invoke($worker, $ordinaryJob, 99), 'Ordinary export SQL must retain its legacy order stripping and generic limit replacement.');

fwrite(STDOUT, "Export worker report contract test passed\n");
