<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use yii\web\Application;

function savedPromoteAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function savedPromoteReset($db)
{
    $db->createCommand('DELETE FROM saved_queries')->execute();
    $db->createCommand('DELETE FROM report_templates')->execute();
}

function savedPromoteAddQuery($db, $name, $sql, $source = 'builder')
{
    $db->createCommand()->insert('saved_queries', [
        'name' => $name,
        'description' => 'Synthetic test query',
        'generated_sql' => $sql,
        'nl_prompt' => 'Synthetic prompt',
        'source' => $source,
    ])->execute();

    return (int) $db->getLastInsertID();
}

function savedPromoteCall($controller, $id)
{
    Yii::$app->response->statusCode = 200;
    $body = $controller->actionSavedPromote($id);

    return [Yii::$app->response->statusCode, $body];
}

function savedPromoteRunCase($name, $test, &$passed, &$failed)
{
    try {
        $test();
        $passed++;
        fwrite(STDOUT, "PASS: " . $name . "\n");
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL: " . $name . " — " . $error->getMessage() . "\n");
    }
}

$app = new Application([
    'id' => 'saved-promote-controller-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'test-key', 'enableCsrfValidation' => false],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['enableSession' => false],
    ],
]);
Yii::$app->errorHandler->unregister();

$db = Yii::$app->db;
$db->createCommand(<<<'SQL'
CREATE TABLE report_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    category TEXT DEFAULT 'other',
    sql_template TEXT NOT NULL,
    parameters TEXT NOT NULL,
    data_source TEXT DEFAULT 'folio',
    default_limit INTEGER DEFAULT 100,
    is_active INTEGER DEFAULT 1,
    created_by TEXT DEFAULT 'manual'
)
SQL
)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE saved_queries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    generated_sql TEXT,
    nl_prompt TEXT,
    source TEXT
)
SQL
)->execute();

$controller = new FolioQueryController('folio-query', Yii::$app);
$passed = 0;
$failed = 0;

savedPromoteRunCase('first promote creates a 201 report with the established body, slug, category, parameters, and creator', function () use ($db, $controller) {
    savedPromoteReset($db);
    $id = savedPromoteAddQuery($db, 'Budget Year / Fund Report', 'SELECT 1', 'nl');

    list($status, $body) = savedPromoteCall($controller, $id);
    savedPromoteAssert($status === 201, 'A new report must return HTTP 201.');
    savedPromoteAssert($body === [
        'id' => $body['id'],
        'slug' => 'budget-year-fund-report',
        'name' => 'Budget Year / Fund Report',
    ], 'A new report must keep the established id, slug, and name body shape.');

    $report = \app\models\ReportTemplate::findOne($body['id']);
    savedPromoteAssert($report !== null, 'The new report must be persisted.');
    savedPromoteAssert($report->category === 'other', 'Promoted reports must keep category other.');
    savedPromoteAssert($report->parameters === '[]', 'Promoted reports must keep an empty parameter list.');
    savedPromoteAssert($report->created_by === 'ai', 'NL saved queries must keep the AI creator value.');
    savedPromoteAssert((int) $report->is_active === 1, 'New promoted reports must be active.');
}, $passed, $failed);

savedPromoteRunCase('repeated promote returns the same report with 200 and leaves the report count unchanged', function () use ($db, $controller) {
    savedPromoteReset($db);
    $id = savedPromoteAddQuery($db, 'Synthetic duplicate report', 'SELECT 1');
    list($firstStatus, $firstBody) = savedPromoteCall($controller, $id);
    $countAfterFirst = (int) $db->createCommand('SELECT COUNT(*) FROM report_templates')->queryScalar();

    list($secondStatus, $secondBody) = savedPromoteCall($controller, $id);
    $countAfterSecond = (int) $db->createCommand('SELECT COUNT(*) FROM report_templates')->queryScalar();

    savedPromoteAssert($firstStatus === 201, 'The first promote must return HTTP 201.');
    savedPromoteAssert($secondStatus === 200, 'A repeated promote must return HTTP 200.');
    savedPromoteAssert($secondBody === $firstBody, 'A repeated promote must return the existing report body.');
    savedPromoteAssert($countAfterSecond === $countAfterFirst, 'A repeated promote must not insert a report.');
}, $passed, $failed);

savedPromoteRunCase('renamed saved query creates a separate report', function () use ($db, $controller) {
    savedPromoteReset($db);
    $id = savedPromoteAddQuery($db, 'Original saved query', 'SELECT 1');
    list($firstStatus, $firstBody) = savedPromoteCall($controller, $id);
    $db->createCommand()->update('saved_queries', ['name' => 'Renamed saved query'], ['id' => $id])->execute();

    list($status, $body) = savedPromoteCall($controller, $id);
    $count = (int) $db->createCommand('SELECT COUNT(*) FROM report_templates')->queryScalar();

    savedPromoteAssert($firstStatus === 201, 'The initial promote must create a report.');
    savedPromoteAssert($status === 201, 'A changed name must create a new report.');
    savedPromoteAssert($body['id'] !== $firstBody['id'], 'A changed name must not reuse the old report.');
    savedPromoteAssert($body['name'] === 'Renamed saved query', 'The new report must use the current saved-query name.');
    savedPromoteAssert($count === 2, 'The renamed query must result in a second report.');
    $renamedReport = \app\models\ReportTemplate::findOne($body['id']);
    savedPromoteAssert($renamedReport->created_by === 'manual', 'Builder saved queries must keep the manual creator value.');
}, $passed, $failed);

savedPromoteRunCase('changed saved SQL creates a separate report', function () use ($db, $controller) {
    savedPromoteReset($db);
    $id = savedPromoteAddQuery($db, 'Synthetic saved query', 'SELECT 1');
    list($firstStatus, $firstBody) = savedPromoteCall($controller, $id);
    $db->createCommand()->update('saved_queries', ['generated_sql' => 'SELECT 2'], ['id' => $id])->execute();

    list($status, $body) = savedPromoteCall($controller, $id);
    $count = (int) $db->createCommand('SELECT COUNT(*) FROM report_templates')->queryScalar();

    savedPromoteAssert($firstStatus === 201, 'The initial promote must create a report.');
    savedPromoteAssert($status === 201, 'Changed SQL must create a new report.');
    savedPromoteAssert($body['id'] !== $firstBody['id'], 'Changed SQL must not reuse the old report.');
    savedPromoteAssert($count === 2, 'Changed SQL must result in a second report.');
}, $passed, $failed);

savedPromoteRunCase('inactive matching report does not block creation', function () use ($db, $controller) {
    savedPromoteReset($db);
    $db->createCommand()->insert('report_templates', [
        'slug' => 'inactive-match',
        'name' => 'Synthetic saved query',
        'description' => 'Inactive fixture',
        'category' => 'other',
        'sql_template' => 'SELECT 1',
        'parameters' => '[]',
        'is_active' => 0,
        'created_by' => 'manual',
    ])->execute();
    $id = savedPromoteAddQuery($db, 'Synthetic saved query', 'SELECT 1');

    list($status, $body) = savedPromoteCall($controller, $id);
    $count = (int) $db->createCommand('SELECT COUNT(*) FROM report_templates')->queryScalar();
    $created = \app\models\ReportTemplate::findOne($body['id']);

    savedPromoteAssert($status === 201, 'An inactive matching report must not be returned as an existing report.');
    savedPromoteAssert($count === 2, 'An inactive match must not prevent creating an active report.');
    savedPromoteAssert((int) $created->is_active === 1, 'The new report must be active.');
}, $passed, $failed);

savedPromoteRunCase('missing saved query keeps the 404 error response', function () use ($db, $controller) {
    savedPromoteReset($db);
    list($status, $body) = savedPromoteCall($controller, 9999);

    savedPromoteAssert($status === 404, 'An unknown saved query must return HTTP 404.');
    savedPromoteAssert($body === ['error' => 'Saved query not found'], 'The 404 body must remain unchanged.');
}, $passed, $failed);

savedPromoteRunCase('saved query without SQL keeps the 422 error response', function () use ($db, $controller) {
    savedPromoteReset($db);
    $id = savedPromoteAddQuery($db, 'Query without SQL', null);
    list($status, $body) = savedPromoteCall($controller, $id);

    savedPromoteAssert($status === 422, 'A saved query without SQL must return HTTP 422.');
    savedPromoteAssert($body === ['error' => 'No SQL to promote — run the query first'], 'The 422 body must remain unchanged.');
    savedPromoteAssert((int) $db->createCommand('SELECT COUNT(*) FROM report_templates')->queryScalar() === 0, 'A query without SQL must not create a report.');
}, $passed, $failed);

savedPromoteRunCase('slug collision for a new report keeps the timestamp suffix generation', function () use ($db, $controller) {
    savedPromoteReset($db);
    $db->createCommand()->insert('report_templates', [
        'slug' => 'budget-year-fund-report',
        'name' => 'Different existing report',
        'description' => 'Synthetic collision fixture',
        'category' => 'other',
        'sql_template' => 'SELECT 9',
        'parameters' => '[]',
        'is_active' => 1,
        'created_by' => 'manual',
    ])->execute();
    $id = savedPromoteAddQuery($db, 'Budget Year / Fund Report', 'SELECT 1');

    list($status, $body) = savedPromoteCall($controller, $id);

    savedPromoteAssert($status === 201, 'A genuinely new report must return HTTP 201.');
    savedPromoteAssert((bool) preg_match('/^budget-year-fund-report-[0-9]+$/', $body['slug']), 'A collision must keep the generated timestamp suffix.');
}, $passed, $failed);

fwrite(STDOUT, "Saved promote backend cases: " . $passed . " passed, " . $failed . " failed\n");
exit($failed === 0 ? 0 : 1);
