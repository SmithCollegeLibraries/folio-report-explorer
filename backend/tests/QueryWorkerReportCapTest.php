<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\commands\QueryWorkerController;
use app\services\ReportExecutionContractService;
use yii\console\Application;

new Application([
    'id' => 'query-worker-report-cap-test',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\\commands',
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
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

$queryLogTableSql = <<<'SQL'
CREATE TABLE query_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sql_text TEXT,
    params TEXT,
    source VARCHAR(20),
    data_source VARCHAR(20),
    user_id INTEGER,
    row_count INTEGER,
    execution_time_ms INTEGER,
    error_message TEXT
)
SQL;
Yii::$app->db->createCommand($queryLogTableSql)->execute();

function queryWorkerReportCapAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function queryWorkerReportCapAssertTrue($actual, string $message): void
{
    queryWorkerReportCapAssertSame(true, $actual, $message);
}

function queryWorkerReportCapContract(): array
{
    return [
        'reportTemplateId' => 38,
        'reportSlug' => 'marc-bibliographic-records-missing-tag',
        'publicRowCap' => 2,
        'fetchRowLimit' => 3,
        'preserveExportOrder' => true,
        'exportKind' => 'worklist',
        'identifierExport' => [
            'sourceColumn' => 'Instance UUID',
            'header' => 'UUID',
        ],
        'downloadFilename' => 'marc-bibliographic-records-missing-tag-856-sc-main-worklist.csv',
    ];
}

function executeQueryWorkerReportCapJob(string $id, string $sql, ?array $contract = null)
{
    $metadata = $contract === null ? null : json_encode([
        ReportExecutionContractService::METADATA_KEY => $contract,
    ]);
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => $sql,
        'params' => '{}',
        'source' => 'report',
        'data_source' => 'local',
        'status' => 'running',
        'output_mode' => 'table',
        'metadata' => $metadata,
        'created_at' => '2026-08-06 10:00:00',
        'started_at' => '2026-08-06 10:00:01',
    ])->execute();

    $job = \app\models\QueryJob::findOne($id);
    $worker = new QueryWorkerController('query-worker', Yii::$app);
    $method = new ReflectionMethod($worker, 'executeJob');
    $method->invoke($worker, $job);
    $job->refresh();

    return $job;
}

$threeRowsSql = <<<'SQL'
SELECT 'instance-1' AS "Instance UUID"
UNION ALL SELECT 'instance-2'
UNION ALL SELECT 'instance-3'
SQL;

$overCapJob = executeQueryWorkerReportCapJob('report-over-cap', $threeRowsSql, queryWorkerReportCapContract());
queryWorkerReportCapAssertSame(2, $overCapJob->row_count, 'Governed table jobs must persist no more than their public row cap.');
queryWorkerReportCapAssertSame(
    ['instance-1', 'instance-2'],
    array_column($overCapJob->getDecodedRows(), 'Instance UUID'),
    'Governed table jobs must retain the first rows in database order.'
);
queryWorkerReportCapAssertTrue($overCapJob->toStatusArray(true)['truncated'], 'The sentinel row must mark a governed table job as truncated.');

$atCapJob = executeQueryWorkerReportCapJob(
    'report-at-cap',
    "SELECT 'instance-1' AS \"Instance UUID\" UNION ALL SELECT 'instance-2'",
    queryWorkerReportCapContract()
);
queryWorkerReportCapAssertSame(2, $atCapJob->row_count, 'A governed table job at its cap must retain all source rows.');
queryWorkerReportCapAssertSame(false, $atCapJob->toStatusArray(true)['truncated'], 'A governed table job without a sentinel row must not be truncated.');

$ordinaryJob = executeQueryWorkerReportCapJob('ordinary-over-cap', $threeRowsSql);
queryWorkerReportCapAssertSame(3, $ordinaryJob->row_count, 'Ordinary table jobs must retain their existing uncapped behavior.');
queryWorkerReportCapAssertSame(
    ['instance-1', 'instance-2', 'instance-3'],
    array_column($ordinaryJob->getDecodedRows(), 'Instance UUID'),
    'Ordinary table jobs must retain all returned rows.'
);
queryWorkerReportCapAssertSame(false, array_key_exists('truncated', $ordinaryJob->toStatusArray(true)), 'Ordinary jobs must not publish report truncation metadata.');

fwrite(STDOUT, "Query worker report cap test passed\n");
