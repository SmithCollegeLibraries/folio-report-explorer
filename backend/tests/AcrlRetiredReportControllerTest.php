<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use yii\web\Application;
use yii\web\IdentityInterface;

final class AcrlRetiredReportIdentity implements IdentityInterface
{
    public function getId() { return 17; }
    public static function findIdentity($id) { return new self(); }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getAuthKey() { return null; }
    public function validateAuthKey($authKey) { return false; }
}

function acrlRetiredReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$app = new Application([
    'id' => 'acrl-retired-report-controller-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'test-key', 'enableCsrfValidation' => false],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['identityClass' => AcrlRetiredReportIdentity::class, 'enableSession' => false],
    ],
]);
Yii::$app->errorHandler->unregister();
Yii::$app->user->setIdentity(new AcrlRetiredReportIdentity());

$db = Yii::$app->db;
$db->createCommand('CREATE TABLE report_templates (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, sql_template TEXT, parameters TEXT, data_source TEXT, default_limit INTEGER, is_active INTEGER)')->execute();
$db->createCommand()->insert('report_templates', [
    'id' => 7,
    'slug' => 'acrl-statistics',
    'name' => 'ACRL Statistics',
    'sql_template' => 'SELECT 1',
    'parameters' => '[]',
    'data_source' => 'local',
    'default_limit' => 100,
    'is_active' => 0,
])->execute();
$db->createCommand('CREATE TABLE saved_queries (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, description TEXT, query_definition TEXT, generated_sql TEXT, source TEXT, last_job_id TEXT, is_pinned INTEGER, is_global INTEGER, created_at TEXT, updated_at TEXT)')->execute();
$db->createCommand()->insert('saved_queries', [
    'id' => 12,
    'user_id' => 17,
    'name' => 'Cached ACRL report',
    'query_definition' => json_encode(['report_template_id' => 7, 'bound_params' => [':year' => 2023]]),
    'generated_sql' => 'SELECT 1',
    'source' => 'report',
    'last_job_id' => 'cached-job-12',
    'is_pinned' => 1,
    'is_global' => 0,
])->execute();
$db->createCommand('CREATE TABLE query_jobs (id TEXT PRIMARY KEY, sql_text TEXT, sql_hash TEXT, params TEXT, source TEXT, data_source TEXT, name TEXT, user_id INTEGER, status TEXT, progress_message TEXT, output_mode TEXT, result_columns TEXT, result_rows TEXT, row_count INTEGER, execution_time_ms INTEGER, error_message TEXT, export_file_path TEXT, estimated_rows INTEGER, estimated_cost REAL, pg_backend_pid INTEGER, metadata TEXT, created_at TEXT, started_at TEXT, completed_at TEXT)')->execute();
$db->createCommand('CREATE TABLE dashboard_widget_templates (id INTEGER PRIMARY KEY, name TEXT, description TEXT, category TEXT, icon TEXT, widget_type TEXT, report_template_id INTEGER, default_params TEXT, sort_order INTEGER, is_enabled INTEGER)')->execute();
$db->createCommand()->insert('dashboard_widget_templates', [
    'id' => 3,
    'name' => 'ACRL Statistics',
    'description' => 'Retired local report',
    'category' => 'reports',
    'icon' => 'table',
    'widget_type' => 'report',
    'report_template_id' => 7,
    'default_params' => '{}',
    'sort_order' => 1,
    'is_enabled' => 1,
])->execute();
$db->createCommand('CREATE TABLE user_dashboard_widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, widget_template_id INTEGER, saved_query_id INTEGER)')->execute();

$controller = new FolioQueryController('folio-query', Yii::$app);
Yii::$app->response->statusCode = 200;
$refresh = $controller->actionDashboardRefresh(12);
acrlRetiredReportAssert(Yii::$app->response->statusCode === 410, 'Refreshing a saved query with an inactive report template must return HTTP 410.');
acrlRetiredReportAssert(($refresh['error'] ?? null) === 'This report has been retired.', 'Retired report refresh must return the standard message.');
acrlRetiredReportAssert((int)$db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar() === 0, 'Retired report refresh must not enqueue a query job.');
acrlRetiredReportAssert($db->createCommand('SELECT last_job_id FROM saved_queries WHERE id = 12')->queryScalar() === 'cached-job-12', 'Retired refresh must preserve the cached dashboard job ID.');

Yii::$app->response->statusCode = 200;
$widgets = $controller->actionDashboardWidgets();
acrlRetiredReportAssert($widgets['widgets'] === [], 'Widget listing must hide report widgets whose template is inactive.');

Yii::$app->request->setBodyParams(['params' => []]);
Yii::$app->response->statusCode = 200;
$added = $controller->actionDashboardWidgetAdd(3);
acrlRetiredReportAssert(Yii::$app->response->statusCode === 410, 'Adding a widget for an inactive report template must return HTTP 410.');
acrlRetiredReportAssert(($added['error'] ?? null) === 'This report has been retired.', 'Inactive widget binding must return the standard retired message.');
acrlRetiredReportAssert((int)$db->createCommand('SELECT COUNT(*) FROM user_dashboard_widgets')->queryScalar() === 0, 'Inactive widget binding must not add a dashboard widget.');

echo "ACRL retired report controller test passed\n";
