<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/AcrlAutofillService.php';
require_once __DIR__ . '/../services/AcrlVeaAutofillService.php';
require_once __DIR__ . '/../controllers/AcrlController.php';

use app\controllers\AcrlController;
use app\models\User;
use app\services\AcrlAutofillService;
use yii\web\Application;

class AcrlAutofillMethodsNeverUseDb
{
    public $calls = 0;
    public function createCommand($sql, array $params = []) { $this->calls++; throw new RuntimeException('Method listing must not query a database.'); }
    public function beginTransaction() { $this->calls++; throw new RuntimeException('Method listing must not start a transaction.'); }
}

class AcrlAutofillMethodsController extends AcrlController
{
    public $autofillService;
    protected function createAcrlAutofillService() { return $this->autofillService; }
}

$app = new Application([
    'id' => 'acrl-autofill-methods-access-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'user' => ['class' => yii\web\User::class, 'identityClass' => User::class, 'enableSession' => false],
        'request' => ['cookieValidationKey' => 'test-key'],
        'response' => ['class' => yii\web\Response::class],
    ],
]);
$app->errorHandler->unregister();
$app->db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, smith_id TEXT, username TEXT, first_name TEXT, last_name TEXT, email TEXT, role TEXT, is_approved INTEGER)')->execute();
$app->db->createCommand()->batchInsert('users', ['id', 'smith_id', 'username', 'role', 'is_approved'], [
    [1, 'approved', 'approved', 'user', 1],
    [2, 'unapproved', 'unapproved', 'user', 0],
])->execute();
$controller = new AcrlAutofillMethodsController('acrl', $app);
$routes = (string)file_get_contents(__DIR__ . '/../config/web.php');
$userActions = $controller->behaviors()['access']['rules'][0]['actions'] ?? [];
if (strpos($routes, "'GET acrl/autofill/methods'") === false || !in_array('autofill-methods', $userActions, true)) {
    fwrite(STDERR, "FAIL: the methods route is registered for approved signed-in users.\n");
    exit(1);
}
$methodsDb = new AcrlAutofillMethodsNeverUseDb();
$controller->autofillService = new AcrlAutofillService(new stdClass(), $methodsDb, null, __DIR__ . '/../sql/acrl');

$app->user->setIdentity(null);
$guest = $controller->actionAutofillMethods();
if ($app->response->statusCode !== 401 || ($guest['error'] ?? null) !== 'Unauthorized') {
    fwrite(STDERR, "FAIL: guests must not receive ACRL autofill methods.\n");
    exit(1);
}
$app->user->setIdentity(User::findOne(2));
$unapproved = $controller->actionAutofillMethods();
if ($app->response->statusCode !== 403 || ($unapproved['error'] ?? null) !== 'Forbidden') {
    fwrite(STDERR, "FAIL: unapproved users must not receive ACRL autofill methods.\n");
    exit(1);
}
$app->user->setIdentity(User::findOne(1));
$approved = $controller->actionAutofillMethods();
$methods = $approved['methods'] ?? [];
$byKey = [];
foreach ($methods as $method) $byKey[($method['sheet'] ?? '') . '|' . ($method['questionId'] ?? '') . '|' . ($method['part'] ?? '')] = $method;
$failures = [];
if (count($methods) !== 16) $failures[] = 'approved users receive the complete methods catalog';
if (($byKey['survey|40a|']['queryVersion'] ?? null) !== 3) $failures[] = 'Q40a advertises version 3';
foreach (['survey|20|' => 'acrl-20', 'survey|20a|' => 'acrl-20a', 'survey|21|' => 'acrl-21', 'survey|21a|' => 'acrl-21a', 'survey|21b|' => 'acrl-21b'] as $key => $queryId) {
    if (($byKey[$key]['queryId'] ?? null) !== $queryId || ($byKey[$key]['queryVersion'] ?? null) !== 1 || ($byKey[$key]['kind'] ?? null) !== 'dated') {
        $failures[] = $key . ' advertises its currency query method';
    }
}
foreach (['survey|79a|' => 'vea-79a', 'survey|79c|' => 'vea-79c'] as $key => $queryId) {
    if (($byKey[$key]['queryId'] ?? null) !== $queryId || ($byKey[$key]['source'] ?? null) !== 'vea'
        || !array_key_exists('sql', $byKey[$key] ?? []) || $byKey[$key]['sql'] !== null) {
        $failures[] = $key . ' exposes Vea metadata without SQL';
    }
}
if ($methodsDb->calls !== 0) $failures[] = 'method listing makes no FOLIO database calls';
if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . "\n");
    exit(1);
}
echo "PASS: ACRL autofill methods are approved-user metadata with no live queries\n";
