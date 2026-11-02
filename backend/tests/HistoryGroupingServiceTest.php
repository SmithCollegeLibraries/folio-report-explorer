<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/HistoryGroupingService.php';

use app\services\HistoryGroupingService;
use yii\web\Application;

new Application([
    'id' => 'history-grouping-service-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
]);

$db = Yii::$app->db;
$sqliteVersion = $db->createCommand('SELECT sqlite_version()')->queryScalar();
if (version_compare($sqliteVersion, '3.25.0', '<')) {
    fwrite(STDOUT, 'SKIP: SQLite ' . $sqliteVersion . ' lacks window functions; orchestrator runs grouping SQL on MySQL/MariaDB.' . "\n");
    exit(0);
}

$db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id TEXT PRIMARY KEY,
    sql_text TEXT NOT NULL,
    params TEXT NULL,
    source TEXT DEFAULT 'report',
    data_source TEXT DEFAULT 'folio',
    name TEXT NULL,
    user_id INTEGER NULL,
    status TEXT DEFAULT 'completed',
    result_columns TEXT NULL,
    result_rows TEXT NULL,
    row_count INTEGER DEFAULT 0,
    execution_time_ms INTEGER DEFAULT 0,
    error_message TEXT NULL,
    progress_message TEXT NULL,
    output_mode TEXT DEFAULT 'table',
    export_file_path TEXT NULL,
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    completed_at TEXT NULL,
    history_group_key CHAR(40) NULL
)
SQL
)->execute();

$db->createCommand(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    email TEXT NULL
)
SQL
)->execute();
$db->createCommand()->batchInsert('users', ['id', 'email'], [[7, 'owner@example.test'], [8, 'other@example.test']])->execute();

$service = new HistoryGroupingService($db);
$failures = [];

function historyGroupingAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true));
    }
}

function historyGroupingCase($name, $callback)
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

function historyGroupingReset($db)
{
    $db->createCommand('DELETE FROM query_jobs')->execute();
}

function historyGroupingJob($db, $id, $owner, $key, $status, $created, $completed = null)
{
    $db->createCommand()->insert('query_jobs', [
        'id' => $id,
        'sql_text' => 'SELECT 1',
        'params' => '{}',
        'source' => 'report',
        'data_source' => 'folio',
        'name' => 'Synthetic report',
        'user_id' => $owner,
        'status' => $status,
        'progress_message' => ucfirst($status),
        'row_count' => 3,
        'created_at' => $created,
        'started_at' => $status === 'completed' ? null : $created,
        'completed_at' => $completed,
        'history_group_key' => $key,
    ])->execute();
}

historyGroupingCase('list groups only equal owner and key, keeping different settings, owners, NULL owners, and NULL keys separate', function () use ($db, $service) {
    historyGroupingReset($db);
    historyGroupingJob($db, 'owner-a-new', 7, 'key-a', 'completed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');
    historyGroupingJob($db, 'owner-a-old', 7, 'key-a', 'completed', '2026-07-19 10:00:00', '2026-07-19 10:01:00');
    historyGroupingJob($db, 'different-settings', 7, 'key-b', 'completed', '2026-07-19 10:04:00', '2026-07-19 10:05:00');
    historyGroupingJob($db, 'different-owner', 8, 'key-a', 'completed', '2026-07-19 10:06:00', '2026-07-19 10:07:00');
    historyGroupingJob($db, 'deleted-owner-a', null, 'key-a', 'completed', '2026-07-19 10:08:00', '2026-07-19 10:09:00');
    historyGroupingJob($db, 'deleted-owner-b', null, 'key-a', 'completed', '2026-07-19 10:10:00', '2026-07-19 10:11:00');
    historyGroupingJob($db, 'null-key-a', 7, null, 'completed', '2026-07-19 10:12:00', '2026-07-19 10:13:00');
    historyGroupingJob($db, 'null-key-b', 7, null, 'completed', '2026-07-19 10:14:00', '2026-07-19 10:15:00');

    $result = $service->list(['status' => 'all', 'mineOnly' => false, 'userId' => 7, 'limit' => 50, 'offset' => 0]);
    historyGroupingAssertSame(7, $result['total'], 'Only the same non-NULL owner and key should collapse.');
    $byId = [];
    foreach ($result['items'] as $item) {
        $byId[$item['jobId']] = $item;
    }
    historyGroupingAssertSame(2, $byId['owner-a-new']['runCount'], 'Matching report runs should share a group.');
    historyGroupingAssertSame(1, $byId['deleted-owner-a']['runCount'], 'A NULL owner job must stay a singleton.');
    historyGroupingAssertSame(1, $byId['deleted-owner-b']['runCount'], 'Separate NULL owner jobs must not collapse.');
    historyGroupingAssertSame(1, $byId['null-key-a']['runCount'], 'A NULL group key must stay a singleton.');
    historyGroupingAssertSame(1, $byId['null-key-b']['runCount'], 'Separate NULL-key jobs must not collapse.');
});

historyGroupingCase('representative is newest by created_at and then greatest id', function () use ($db, $service) {
    historyGroupingReset($db);
    historyGroupingJob($db, 'job-a', 7, 'same-key', 'completed', '2026-07-19 10:00:00', '2026-07-19 10:01:00');
    historyGroupingJob($db, 'job-z', 7, 'same-key', 'completed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');
    historyGroupingJob($db, 'job-y', 7, 'same-key', 'completed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');

    $result = $service->list(['status' => 'all', 'mineOnly' => false, 'userId' => 7, 'limit' => 50, 'offset' => 0]);
    historyGroupingAssertSame('job-z', $result['items'][0]['jobId'], 'The larger id wins when created_at ties.');
});

historyGroupingCase('deleting the newest run promotes the next newest group member', function () use ($db, $service) {
    historyGroupingReset($db);
    historyGroupingJob($db, 'newest-member', 7, 'delete-key', 'completed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');
    historyGroupingJob($db, 'next-member', 7, 'delete-key', 'completed', '2026-07-19 10:00:00', '2026-07-19 10:01:00');

    $before = $service->list(['status' => 'all', 'mineOnly' => false, 'userId' => 7, 'limit' => 50, 'offset' => 0]);
    historyGroupingAssertSame('newest-member', $before['items'][0]['jobId'], 'The first representative is the newest member.');
    $db->createCommand()->delete('query_jobs', ['id' => 'newest-member'])->execute();
    $after = $service->list(['status' => 'all', 'mineOnly' => false, 'userId' => 7, 'limit' => 50, 'offset' => 0]);
    historyGroupingAssertSame('next-member', $after['items'][0]['jobId'], 'The next newest member becomes representative.');
    historyGroupingAssertSame(1, $after['items'][0]['runCount'], 'The surviving group count decreases after deletion.');
});

historyGroupingCase('status filter is applied before grouping and run counts use only filtered members', function () use ($db, $service) {
    historyGroupingReset($db);
    historyGroupingJob($db, 'completed-run', 7, 'status-key', 'completed', '2026-07-19 10:00:00', '2026-07-19 10:01:00');
    historyGroupingJob($db, 'failed-run', 7, 'status-key', 'failed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');
    historyGroupingJob($db, 'running-run', 7, 'status-key', 'running', '2026-07-19 10:04:00');

    $completed = $service->list(['status' => 'completed', 'mineOnly' => false, 'userId' => 7, 'limit' => 50, 'offset' => 0]);
    historyGroupingAssertSame(1, $completed['total'], 'The completed filter has one visible group.');
    historyGroupingAssertSame('completed-run', $completed['items'][0]['jobId'], 'Only the matching member is a candidate.');
    historyGroupingAssertSame(1, $completed['items'][0]['runCount'], 'Filtered runs determine the group count.');

    $active = $service->list(['status' => 'active', 'mineOnly' => false, 'userId' => 7, 'limit' => 50, 'offset' => 0]);
    historyGroupingAssertSame(1, $active['items'][0]['runCount'], 'The active filter excludes completed and failed members.');
    historyGroupingAssertSame(1, $active['items'][0]['activeRunCount'], 'The filtered active member contributes to activeRunCount.');
});

historyGroupingCase('Mine only filters groups to the current user', function () use ($db, $service) {
    historyGroupingReset($db);
    historyGroupingJob($db, 'mine', 7, 'mine-key', 'completed', '2026-07-19 10:00:00', '2026-07-19 10:01:00');
    historyGroupingJob($db, 'theirs', 8, 'theirs-key', 'completed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');
    historyGroupingJob($db, 'deleted-owner', null, 'null-owner-key', 'completed', '2026-07-19 10:04:00', '2026-07-19 10:05:00');

    $result = $service->list(['status' => 'all', 'mineOnly' => true, 'userId' => 7, 'limit' => 50, 'offset' => 0]);
    historyGroupingAssertSame(['mine'], array_column($result['items'], 'jobId'), 'Mine only must not include other or deleted owners.');
});

historyGroupingCase('total and paging count grouped rows rather than retained jobs', function () use ($db, $service) {
    historyGroupingReset($db);
    historyGroupingJob($db, 'same-a', 7, 'group-a', 'completed', '2026-07-19 10:00:00', '2026-07-19 10:01:00');
    historyGroupingJob($db, 'same-b', 7, 'group-a', 'completed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');
    historyGroupingJob($db, 'group-b', 7, 'group-b', 'completed', '2026-07-19 10:04:00', '2026-07-19 10:05:00');
    historyGroupingJob($db, 'group-c', 7, 'group-c', 'completed', '2026-07-19 10:06:00', '2026-07-19 10:07:00');

    $result = $service->list(['status' => 'all', 'mineOnly' => false, 'userId' => 7, 'limit' => 1, 'offset' => 1]);
    historyGroupingAssertSame(3, $result['total'], 'The total is the number of groups after collapse.');
    historyGroupingAssertSame(1, count($result['items']), 'The page contains the requested number of groups.');
    historyGroupingAssertSame(1, $result['offset'], 'The returned offset identifies the group page.');
});

historyGroupingCase('active groups sort before completed rows and ties use latest activity then id', function () use ($db, $service) {
    historyGroupingReset($db);
    for ($index = 1; $index <= 50; $index++) {
        $id = 'completed-' . str_pad((string)$index, 2, '0', STR_PAD_LEFT);
        historyGroupingJob($db, $id, 7, 'key-' . $id, 'completed', '2026-07-20 10:00:00', '2026-07-20 10:00:00');
    }
    historyGroupingJob($db, 'active-a', 7, 'active-a-key', 'running', '2026-07-19 10:00:00');
    historyGroupingJob($db, 'active-z', 7, 'active-z-key', 'pending_export', '2026-07-19 10:00:00');
    historyGroupingJob($db, 'active-y', 7, 'active-y-key', 'pending', '2026-07-19 09:59:00');

    $result = $service->list(['status' => 'all', 'mineOnly' => false, 'userId' => 7, 'limit' => 50, 'offset' => 0]);
    historyGroupingAssertSame('active-z', $result['items'][0]['jobId'], 'Active groups remain ahead of 50 completed rows and use id as the final tie-break.');
    historyGroupingAssertSame('active-a', $result['items'][1]['jobId'], 'Latest activity sorts active groups before id tie-break.');
    historyGroupingAssertSame('active-y', $result['items'][2]['jobId'], 'Older active activity sorts after newer activity.');
    if (in_array('completed-01', array_column($result['items'], 'jobId'), true)) {
        throw new RuntimeException('The 50 completed rows must not push an active group off page one.');
    }
});

historyGroupingCase('activeRunCount includes pending, pending_export, running, and cancelling members', function () use ($db, $service) {
    historyGroupingReset($db);
    foreach (['pending', 'pending_export', 'running', 'cancelling'] as $index => $status) {
        historyGroupingJob($db, 'active-' . $index, 7, 'all-active', $status, '2026-07-19 10:0' . $index . ':00');
    }

    $result = $service->list(['status' => 'all', 'mineOnly' => false, 'userId' => 7, 'limit' => 50, 'offset' => 0]);
    historyGroupingAssertSame(4, $result['items'][0]['activeRunCount'], 'All four active statuses contribute to the group badge.');
});

historyGroupingCase('expand resolves any member id and applies status filtering, paging, and singleton rules', function () use ($db, $service) {
    historyGroupingReset($db);
    historyGroupingJob($db, 'member-one', 7, 'expand-key', 'completed', '2026-07-19 10:00:00', '2026-07-19 10:01:00');
    historyGroupingJob($db, 'member-two', 7, 'expand-key', 'failed', '2026-07-19 10:02:00', '2026-07-19 10:03:00');
    historyGroupingJob($db, 'member-three', 7, 'expand-key', 'completed', '2026-07-19 10:04:00', '2026-07-19 10:05:00');
    historyGroupingJob($db, 'single-null-owner', null, 'expand-key', 'completed', '2026-07-19 10:06:00', '2026-07-19 10:07:00');

    $page = $service->expand('member-two', ['status' => 'all', 'limit' => 1, 'offset' => 1]);
    historyGroupingAssertSame(3, $page['total'], 'A nonrepresentative member resolves the complete owner-key group.');
    historyGroupingAssertSame(1, count($page['items']), 'Expansion uses the requested page size.');
    historyGroupingAssertSame('member-two', $page['items'][0]['jobId'], 'Expansion order is newest first.');

    $filtered = $service->expand('member-two', ['status' => 'completed', 'limit' => 20, 'offset' => 0]);
    historyGroupingAssertSame(2, $filtered['total'], 'Expansion applies the same status filter as the list.');
    historyGroupingAssertSame(1, $service->expand('single-null-owner', ['status' => 'all', 'limit' => 20, 'offset' => 0])['total'], 'An ungrouped NULL-owner job expands as itself.');
});

fwrite(STDOUT, count($failures) . " history grouping service case(s) failed; SQLite " . $sqliteVersion . " supports window functions.\n");
exit($failures === [] ? 0 : 1);
