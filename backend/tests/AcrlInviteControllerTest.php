<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../controllers/AcrlInviteController.php';

use app\controllers\AcrlInviteController;
use app\models\User;
use yii\web\Application;

$app = new Application([
    'id' => 'acrl-invite-controller-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'test-key'],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['class' => yii\web\User::class, 'identityClass' => User::class, 'enableSession' => false],
    ],
]);
$app->errorHandler->unregister();
$db = $app->db;
$db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, smith_id TEXT, username TEXT, email TEXT, first_name TEXT, last_name TEXT, role TEXT, is_approved INTEGER)')->execute();
foreach ([
    [1, 'admin-id', 'admin', 'admin@smith.example', 'Synthetic', 'Admin', 'admin', 1],
    [2, 'approved-user-id', 'approved-user', 'user@smith.example', 'Synthetic', 'User', 'user', 1],
    [3, 'revoked-admin-id', 'revoked-admin', 'revoked@smith.example', 'Synthetic', 'Revoked', 'admin', 0],
    [4, 'no-email-admin-id', 'no-email-admin', null, 'Synthetic', 'No Email', 'admin', 1],
    [5, 'known-user-id', 'known', 'known@smith.example', 'Synthetic', 'Known', 'user', 1],
    [6, 'waiting-user-id', 'waiting', 'other@example.test', 'Synthetic', 'Waiting', 'user', 0],
    [7, 'known-alias-approved-id', 'known', 'alias@smith.example', 'Synthetic', 'Alias', 'user', 1],
    [8, 'known-alias-pending-id', 'known', 'pending-alias@example.test', 'Synthetic', 'Pending Alias', 'user', 0],
] as $user) {
    $db->createCommand()->insert('users', [
        'id' => $user[0], 'smith_id' => $user[1], 'username' => $user[2], 'email' => $user[3],
        'first_name' => $user[4], 'last_name' => $user[5], 'role' => $user[6], 'is_approved' => $user[7],
    ])->execute();
}
$controller = new AcrlInviteController('acrl-invite', $app);
$failures = [];
function acrlInviteControllerCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

function acrlInviteControllerInvoke(AcrlInviteController $controller, string $action, array $arguments, string $label)
{
    global $failures;
    Yii::$app->response->statusCode = 200;
    try {
        return call_user_func_array([$controller, $action], $arguments);
    } catch (Throwable $error) {
        $failures[] = $label . ' threw before returning its API response: ' . $error->getMessage();
        return null;
    }
}

function acrlInviteControllerDenied(AcrlInviteController $controller, string $action, array $arguments, $identity, bool $guest, string $label): void
{
    Yii::$app->user->setIdentity($identity);
    if ($guest) Yii::$app->user->setIdentity(null);
    acrlInviteControllerInvoke($controller, $action, $arguments, $label);
    acrlInviteControllerCheck(Yii::$app->response->statusCode === 403, $label . ' is denied before an invite/year lookup.');
}

// Deliberately omit ACRL tables during denied calls. A lookup before the persisted-admin guard
// would fail against this database instead of returning 403.
$actions = [
    ['actionCreate', [2026]], ['actionList', [2026]], ['actionResend', [1]], ['actionRevoke', [1]],
];
foreach ($actions as $route) {
    list($action, $arguments) = $route;
    acrlInviteControllerDenied($controller, $action, $arguments, null, true, $action . ' guest');
    acrlInviteControllerDenied($controller, $action, $arguments, User::findOne(2), false, $action . ' approved non-admin');
    acrlInviteControllerDenied($controller, $action, $arguments, User::findOne(3), false, $action . ' revoked admin');
}

Yii::$app->request->hostInfo = 'https://reports.example.test';
$db->createCommand('CREATE TABLE acrl_survey_years (survey_year INTEGER PRIMARY KEY, status TEXT NOT NULL)')->execute();
$db->createCommand()->batchInsert('acrl_survey_years', ['survey_year', 'status'], [
    [2026, 'published'], [2030, 'draft'],
])->execute();
$db->createCommand('CREATE TABLE acrl_questions (
    survey_year INTEGER NOT NULL, sheet TEXT NOT NULL, question_id TEXT NOT NULL, part TEXT NOT NULL DEFAULT \'\',
    section TEXT NOT NULL, prompt TEXT NOT NULL, survey_order INTEGER NOT NULL
)')->execute();
$db->createCommand()->insert('acrl_questions', [
    'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A',
    'section' => 'Circulation', 'prompt' => 'Synthetic circulation question', 'survey_order' => 1,
])->execute();
$db->createCommand('CREATE TABLE acrl_section_assignments (
    survey_year INTEGER NOT NULL, section TEXT NOT NULL, user_id INTEGER NOT NULL, assigned_by INTEGER NULL,
    UNIQUE (survey_year, section, user_id)
)')->execute();
$db->createCommand('CREATE TABLE acrl_question_assignments (
    survey_year INTEGER NOT NULL, sheet TEXT NOT NULL, question_id TEXT NOT NULL, part TEXT NOT NULL DEFAULT \'\',
    user_id INTEGER NOT NULL, assigned_by INTEGER NULL, assigned_at TEXT,
    UNIQUE (survey_year, sheet, question_id, part, user_id)
)')->execute();
$db->createCommand('CREATE TABLE acrl_invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, survey_year INTEGER NOT NULL, invited_by INTEGER NULL,
    link_origin TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'pending\', send_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL, expires_at TEXT NOT NULL, last_sent_at TEXT NULL, accepted_user_id INTEGER NULL,
    accepted_at TEXT NULL, revoked_at TEXT NULL
)')->execute();
$db->createCommand('CREATE TABLE acrl_invite_targets (
    invite_id INTEGER NOT NULL, kind TEXT NOT NULL, section TEXT NOT NULL DEFAULT \'\', sheet TEXT NOT NULL DEFAULT \'\',
    question_id TEXT NOT NULL DEFAULT \'\', part TEXT NOT NULL DEFAULT \'\',
    UNIQUE (invite_id, kind, section, sheet, question_id, part)
)')->execute();

$app->user->setIdentity(User::findOne(1));
$invalidRequests = [
    ['email outside inviting admin domain', 2026, ['email' => 'reader@other.example', 'targets' => [['kind' => 'section', 'section' => 'Circulation']]], 422, 'domain'],
    ['unpublished year', 2030, ['email' => 'reader@smith.example', 'targets' => [['kind' => 'section', 'section' => 'Circulation']]], 422, 'published'],
    ['empty targets', 2026, ['email' => 'reader@smith.example', 'targets' => []], 422, 'target'],
    ['unknown targets', 2026, ['email' => 'reader@smith.example', 'targets' => [['kind' => 'section', 'section' => 'Missing section']]], 422, 'target'],
];
foreach ($invalidRequests as $case) {
    list($label, $year, $body, $status, $message) = $case;
    Yii::$app->request->bodyParams = $body;
    Yii::$app->response->statusCode = 200;
    try {
        $response = $controller->actionCreate($year);
    } catch (Throwable $error) {
        $response = null;
        $failures[] = $label . ' threw instead of returning an API error: ' . $error->getMessage();
    }
    acrlInviteControllerCheck(Yii::$app->response->statusCode === $status, $label . ' returns HTTP ' . $status . '.');
    acrlInviteControllerCheck(is_array($response) && strpos(strtolower((string)($response['error'] ?? '')), strtolower($message)) !== false, $label . ' returns the expected error message.');
}
$app->user->setIdentity(User::findOne(4));
Yii::$app->request->bodyParams = ['email' => 'reader@smith.example', 'targets' => [['kind' => 'section', 'section' => 'Circulation']]];
Yii::$app->response->statusCode = 200;
$noEmailResponse = $controller->actionCreate(2026);
acrlInviteControllerCheck(Yii::$app->response->statusCode === 422 && ($noEmailResponse['error'] ?? null) === 'Your account has no Smith email on file; sign in again.', 'No-email admin gets the D6 422 response exactly.');

$app->user->setIdentity(User::findOne(1));
foreach ([['known@smith.example', 'Already in the app - assign them directly.', 5], ['waiting@smith.example', 'Waiting for approval - approve them on the Users page.', 6]] as $case) {
    list($email, $message, $userId) = $case;
    Yii::$app->request->bodyParams = ['email' => $email, 'targets' => [['kind' => 'section', 'section' => 'Circulation']]];
    Yii::$app->response->statusCode = 200;
    try {
        $response = $controller->actionCreate(2026);
    } catch (Throwable $error) {
        $response = null;
        $failures[] = 'Matching existing user ' . $email . ' threw: ' . $error->getMessage();
    }
    acrlInviteControllerCheck(Yii::$app->response->statusCode === 409, 'Matching account ' . $email . ' returns HTTP 409.');
    acrlInviteControllerCheck(($response['error'] ?? null) === $message && (int)($response['user']['id'] ?? 0) === $userId && !empty($response['user']['displayName']), 'Matching account response returns the exact message and id/displayName payload.');
}
Yii::$app->request->bodyParams = ['email' => 'known@smith.example', 'targets' => [['kind' => 'section', 'section' => 'Circulation']]];
Yii::$app->response->statusCode = 200;
try {
    $multipleMatches = $controller->actionCreate(2026);
} catch (Throwable $error) {
    $multipleMatches = null;
    $failures[] = 'Multiple matching accounts threw instead of returning the selected account: ' . $error->getMessage();
}
acrlInviteControllerCheck((int)($multipleMatches['user']['id'] ?? 0) === 5, 'When multiple accounts match, approved account wins and the lowest approved ID is returned.');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL invite controller checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}

echo "PASS: ACRL invite controller routes enforce the persisted-admin guard before invite lookups\n";
