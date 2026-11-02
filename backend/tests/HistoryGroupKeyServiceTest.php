<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/HistoryGroupKeyService.php';

use app\models\ReportTemplate;
use app\services\HistoryGroupKeyService;
use yii\web\Application;

new Application([
    'id' => 'history-group-key-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
]);

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE report_templates (
    id INTEGER PRIMARY KEY,
    name TEXT,
    sql_template TEXT,
    parameters TEXT,
    default_limit INTEGER
)
SQL
)->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id TEXT PRIMARY KEY,
    user_id INTEGER NULL,
    history_group_key CHAR(40) NULL
)
SQL
)->execute();

$failures = [];

function historyGroupKeyAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true));
    }
}

function historyGroupKeyCase($name, $callback)
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

function historyGroupKeyReport(array $parameterDefinitions)
{
    $report = new ReportTemplate();
    $report->sql_template = 'SELECT :term';
    $report->parameters = json_encode($parameterDefinitions);
    $report->default_limit = 100;
    return $report;
}

historyGroupKeyCase('omitted optional parameter binds to the same group key as its stored default', function () {
    $report = historyGroupKeyReport([
        ['name' => 'term', 'type' => 'text', 'required' => false, 'default' => 'catalog'],
    ]);
    $omitted = $report->bindParams([])['params'];
    $explicitDefault = $report->bindParams(['term' => 'catalog'])['params'];

    historyGroupKeyAssertSame(
        HistoryGroupKeyService::build(12, $omitted, 'worklist', 'table'),
        HistoryGroupKeyService::build(12, $explicitDefault, 'worklist', 'table'),
        'The group key must use bound values after the report default has been applied.'
    );
});

historyGroupKeyCase('whitespace-different text and different bound values produce different group keys', function () {
    $base = HistoryGroupKeyService::build(12, [':term' => 'catalog'], 'worklist', 'table');
    $whitespaceDifferent = HistoryGroupKeyService::build(12, [':term' => ' catalog '], 'worklist', 'table');
    $differentValue = HistoryGroupKeyService::build(12, [':term' => 'archives'], 'worklist', 'table');

    if ($base === $whitespaceDifferent || $base === $differentValue || $whitespaceDifferent === $differentValue) {
        throw new RuntimeException('Every distinct stored parameter value must produce a distinct key.');
    }
});

historyGroupKeyCase('final output mode and export kind participate in the group key', function () {
    $forcedFile = HistoryGroupKeyService::build(12, [':term' => 'catalog'], 'identifier', 'file');
    $tableWorklist = HistoryGroupKeyService::build(12, [':term' => 'catalog'], 'worklist', 'table');
    $fileWorklist = HistoryGroupKeyService::build(12, [':term' => 'catalog'], 'worklist', 'file');

    if ($forcedFile === $tableWorklist || $forcedFile === $fileWorklist || $fileWorklist === $tableWorklist) {
        throw new RuntimeException('Export kind and the final stored output mode must both distinguish keys.');
    }
});

historyGroupKeyCase('group id is owner-specific and remains stable after its representative is deleted', function () {
    $key = HistoryGroupKeyService::build(12, [':term' => 'catalog'], 'worklist', 'table');
    $firstGroupId = HistoryGroupKeyService::groupId(7, $key);
    $sameOwnerGroupId = HistoryGroupKeyService::groupId(7, $key);
    $otherOwnerGroupId = HistoryGroupKeyService::groupId(8, $key);

    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => 'representative', 'user_id' => 7, 'history_group_key' => $key,
    ])->execute();
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => 'remaining-member', 'user_id' => 7, 'history_group_key' => $key,
    ])->execute();
    Yii::$app->db->createCommand()->delete('query_jobs', ['id' => 'representative'])->execute();

    historyGroupKeyAssertSame($firstGroupId, $sameOwnerGroupId, 'An owner and key identify one stable group.');
    historyGroupKeyAssertSame(
        $firstGroupId,
        HistoryGroupKeyService::groupId(7, Yii::$app->db->createCommand(
            'SELECT history_group_key FROM query_jobs WHERE id = :id',
            [':id' => 'remaining-member']
        )->queryScalar()),
        'Deleting the representative must not change the owner-key group identity.'
    );
    if ($firstGroupId === $otherOwnerGroupId) {
        throw new RuntimeException('Identical keys owned by different users must have different group ids.');
    }
});

fwrite(STDOUT, count($failures) . " history group key case(s) failed.\n");
exit($failures === [] ? 0 : 1);
