<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use app\models\User;
use yii\base\Action;
use yii\filters\AccessControl;
use yii\web\Application;
use yii\web\ForbiddenHttpException;

new Application([
    'id' => 'allocation-editor-access-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'request' => ['cookieValidationKey' => 'test-key', 'enableCsrfValidation' => false],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['identityClass' => User::class, 'enableSession' => false],
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
]);

$createUsersSql = <<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    smith_id VARCHAR(50) UNIQUE,
    username VARCHAR(100),
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    affiliation VARCHAR(100),
    email VARCHAR(255),
    role VARCHAR(20),
    is_approved INTEGER,
    receive_notifications INTEGER,
    default_campus VARCHAR(50),
    allocation_colleges VARCHAR(32) NULL,
    last_login DATETIME NULL,
    refresh_token VARCHAR(512) NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
)
SQL;
Yii::$app->db->createCommand($createUsersSql)->execute();

Yii::$app->db->createCommand()->batchInsert(
    'users',
    ['id', 'smith_id', 'username', 'role', 'is_approved', 'receive_notifications', 'default_campus', 'allocation_colleges'],
    [
        [1, 'rpt008-admin', 'admin', 'admin', 1, 1, 'Smith College', null],
        [2, 'rpt008-plain', 'plain', 'user', 1, 1, 'Smith College', null],
        [3, 'rpt008-editor', 'editor', 'user', 1, 1, 'Smith College', 'MH'],
    ]
)->execute();

$createAllocationsSql = <<<'SQL'
CREATE TABLE report_expense_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fiscal_year INTEGER NOT NULL,
    expense_class_code VARCHAR(32) NOT NULL,
    allocation_amount NUMERIC NOT NULL,
    UNIQUE(fiscal_year, expense_class_code)
)
SQL;
Yii::$app->db->createCommand($createAllocationsSql)->execute();

Yii::$app->db->createCommand()->batchInsert(
    'report_expense_allocations',
    ['id', 'fiscal_year', 'expense_class_code', 'allocation_amount'],
    [
        [1, 2025, 'MHBOOKS', 100],
        [2, 2025, 'SCBOOKS', 200],
        [3, 2025, 'MHOTHER', 50],
        [4, 2026, 'SCBOOKS', 220],
    ]
)->execute();

final class AllocationEditorFolioCommand
{
    private $sql;
    private $params;

    public function __construct($sql, array $params)
    {
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryOne()
    {
        return [
            'funds' => '[{"id":"synthetic-fund","code":"MHBPS","name":"Books"}]',
            'expense_classes' => '[{"code":"MHBOOKS","name":"MH Books"}]',
            'fiscal_years' => '[{"year":2026,"code":"MHFY2026","startDate":"2025-07-01","endDate":"2026-06-30"}]',
        ];
    }

    public function queryAll()
    {
        $pattern = $this->params[':classPattern'] ?? '';
        $rows = [];
        foreach ($this->params as $key => $code) {
            if (strpos($key, ':class_') !== 0) {
                continue;
            }
            $code = strtoupper(trim((string) $code));
            if ($pattern !== '' && strpos($code, substr($pattern, 0, 2)) === 0) {
                $rows[] = ['expense_class_code' => $code];
            }
        }
        return $rows;
    }
}

final class AllocationEditorFolioDatabase
{
    public function createCommand($sql, array $params = [])
    {
        return new AllocationEditorFolioCommand($sql, $params);
    }
}

Yii::$app->set('folioDb', new AllocationEditorFolioDatabase());

function allocationEditorAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function allocationEditorCall($identityId, $action, array $query = [], array $body = [], array $params = [])
{
    Yii::$app->user->setIdentity(User::findOne($identityId));
    Yii::$app->request->setQueryParams($query);
    Yii::$app->request->setBodyParams($body);
    Yii::$app->response->statusCode = 200;
    Yii::$app->response->data = null;

    $controller = new FolioQueryController('folio-query', Yii::$app);
    try {
        if ($action === 'local-alloc-list') {
            $result = $controller->actionLocalAllocList();
        } elseif ($action === 'local-alloc-years') {
            $result = $controller->actionLocalAllocYears();
        } elseif ($action === 'local-alloc-upsert') {
            $result = $controller->actionLocalAllocUpsert();
        } elseif ($action === 'local-alloc-delete') {
            $result = $controller->actionLocalAllocDelete($params['id']);
        } elseif ($action === 'local-alloc-copy-year') {
            $result = $controller->actionLocalAllocCopyYear();
        } elseif ($action === 'budget-year-expense-class-options') {
            $result = $controller->actionBudgetYearExpenseClassOptions();
        } else {
            throw new InvalidArgumentException('Unexpected allocation action: ' . $action);
        }
        return ['status' => Yii::$app->response->statusCode, 'body' => $result];
    } catch (ForbiddenHttpException $error) {
        return ['status' => $error->statusCode, 'body' => ['error' => $error->getMessage()]];
    }
}

function allocationEditorRouteAccess($identityId, $action)
{
    Yii::$app->user->setIdentity(User::findOne($identityId));
    Yii::$app->response->statusCode = 200;
    Yii::$app->response->data = null;
    $controller = new FolioQueryController('folio-query', Yii::$app);
    $config = $controller->behaviors()['access'];
    unset($config['class']);
    $access = new AccessControl($config);

    try {
        return [
            'allowed' => $access->beforeAction(new Action($action, $controller)),
            'status' => Yii::$app->response->statusCode,
        ];
    } catch (ForbiddenHttpException $error) {
        return ['allowed' => false, 'status' => $error->statusCode];
    }
}

foreach ([
    'local-alloc-list', 'local-alloc-years', 'local-alloc-upsert',
    'local-alloc-delete', 'local-alloc-copy-year',
] as $action) {
    allocationEditorAssert(!allocationEditorRouteAccess(2, $action)['allowed'], 'The route rule rejects an ordinary user for ' . $action . '.');
    allocationEditorAssert(allocationEditorRouteAccess(3, $action)['allowed'], 'The route rule admits an assigned editor for ' . $action . '.');
    allocationEditorAssert(allocationEditorRouteAccess(1, $action)['allowed'], 'The route rule admits an administrator for ' . $action . '.');
}

foreach ([
    ['local-alloc-list', ['prefix' => 'MH']],
    ['local-alloc-years', ['prefix' => 'MH']],
    ['local-alloc-upsert', [], ['prefix' => 'MH', 'fiscalYear' => 2026, 'code' => 'MHBOOKS', 'amount' => 125]],
    ['local-alloc-delete', ['prefix' => 'MH'], [], ['id' => 1]],
    ['local-alloc-copy-year', [], ['prefix' => 'MH', 'fiscalYear' => 2026]],
] as $case) {
    $query = $case[1] ?? [];
    $body = $case[2] ?? [];
    $params = $case[3] ?? [];
    $plainResult = allocationEditorCall(2, $case[0], $query, $body, $params);
    allocationEditorAssert($plainResult['status'] === 403, 'An ordinary user is denied ' . $case[0] . '.');
}

$mhList = allocationEditorCall(3, 'local-alloc-list', ['prefix' => 'MH']);
allocationEditorAssert($mhList['status'] === 200 && count($mhList['body']['items']) === 2, 'An MH editor can list MH allocations.');
$scList = allocationEditorCall(3, 'local-alloc-list', ['prefix' => 'SC']);
allocationEditorAssert($scList['status'] === 403, 'An MH editor cannot list SC allocations.');
$mhYears = allocationEditorCall(3, 'local-alloc-years', ['prefix' => 'MH']);
allocationEditorAssert($mhYears['status'] === 200, 'An MH editor can list MH allocation years.');
$scYears = allocationEditorCall(3, 'local-alloc-years', ['prefix' => 'SC']);
allocationEditorAssert($scYears['status'] === 403, 'An MH editor cannot list SC allocation years.');
$mhUpsert = allocationEditorCall(3, 'local-alloc-upsert', [], ['prefix' => 'MH', 'fiscalYear' => 2026, 'code' => 'MHBOOKS', 'amount' => 125]);
allocationEditorAssert($mhUpsert['status'] === 200, 'An MH editor can upsert an MH allocation.');
$scUpsert = allocationEditorCall(3, 'local-alloc-upsert', [], ['prefix' => 'SC', 'fiscalYear' => 2026, 'code' => 'SCBOOKS', 'amount' => 125]);
allocationEditorAssert($scUpsert['status'] === 403, 'An MH editor cannot upsert an SC allocation.');
$scDelete = allocationEditorCall(3, 'local-alloc-delete', ['prefix' => 'SC'], [], ['id' => 2]);
allocationEditorAssert($scDelete['status'] === 403, 'An MH editor cannot delete an SC allocation row.');
$mhCopy = allocationEditorCall(3, 'local-alloc-copy-year', [], ['prefix' => 'MH', 'fiscalYear' => 2026]);
allocationEditorAssert($mhCopy['status'] === 200, 'An MH editor can copy allocations within MH across adjacent fiscal years.');
$scCopy = allocationEditorCall(3, 'local-alloc-copy-year', [], ['prefix' => 'SC', 'fiscalYear' => 2026]);
allocationEditorAssert($scCopy['status'] === 403, 'An MH editor cannot copy allocations for SC.');
$mhOptions = allocationEditorCall(3, 'budget-year-expense-class-options', ['acqUnit' => 'MH']);
allocationEditorAssert($mhOptions['status'] === 200, 'An MH editor can load MH budget options.');
$scOptions = allocationEditorCall(3, 'budget-year-expense-class-options', ['acqUnit' => 'SC']);
allocationEditorAssert($scOptions['status'] === 200, 'An MH editor can load SC budget options for reports.');
$reportColleges = ['AC', 'HC', 'MH', 'SC', 'UM'];
allocationEditorAssert(
    allocationEditorRouteAccess(2, 'budget-year-expense-class-options')['allowed'],
    'An ordinary authenticated user can access the report options route.'
);
foreach ($reportColleges as $college) {
    $plainOptions = allocationEditorCall(2, 'budget-year-expense-class-options', ['acqUnit' => $college]);
    allocationEditorAssert(
        $plainOptions['status'] === 200 && $plainOptions['body']['prefix'] === $college,
        'An ordinary authenticated user can load report options for ' . $college . '.'
    );
}
allocationEditorAssert(
    allocationEditorRouteAccess(2, 'report-run')['allowed'],
    'An ordinary authenticated user can run report 36 through the report-run route.'
);
$allocationAssignmentEditor = allocationEditorRouteAccess(3, 'user-allocation-colleges');
allocationEditorAssert(!$allocationAssignmentEditor['allowed'], 'Allocation editors cannot access the administrator allocation-assignment route.');
$allocationAssignmentPlain = allocationEditorRouteAccess(2, 'user-allocation-colleges');
allocationEditorAssert(!$allocationAssignmentPlain['allowed'], 'Ordinary users cannot access the administrator allocation-assignment route.');
$allocationAssignmentAdmin = allocationEditorRouteAccess(1, 'user-allocation-colleges');
allocationEditorAssert($allocationAssignmentAdmin['allowed'], 'Administrators can access the allocation-assignment route.');

$spoofedDelete = allocationEditorCall(3, 'local-alloc-delete', ['prefix' => 'MH'], [], ['id' => 2]);
allocationEditorAssert($spoofedDelete['status'] === 403, 'An MH editor cannot delete an SC row by spoofing the MH request prefix.');

foreach ([
    ['local-alloc-list', ['prefix' => 'MH']],
    ['local-alloc-list', ['prefix' => 'SC']],
    ['local-alloc-years', ['prefix' => 'SC']],
    ['local-alloc-upsert', [], ['prefix' => 'SC', 'fiscalYear' => 2026, 'code' => 'SCBOOKS', 'amount' => 300]],
    ['local-alloc-delete', ['prefix' => 'SC'], [], ['id' => 2]],
    ['local-alloc-copy-year', [], ['prefix' => 'SC', 'fiscalYear' => 2026]],
] as $case) {
    $query = $case[1] ?? [];
    $body = $case[2] ?? [];
    $params = $case[3] ?? [];
    $adminResult = allocationEditorCall(1, $case[0], $query, $body, $params);
    allocationEditorAssert($adminResult['status'] !== 403, 'An administrator retains access to ' . $case[0] . '.');
}

fwrite(STDOUT, "Allocation editor access matrix test passed\n");
