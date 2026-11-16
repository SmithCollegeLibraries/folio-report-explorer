<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../controllers/AcrlMembershipController.php';

use app\controllers\AcrlMembershipController;
use app\models\User;
use yii\web\Application;

$app = new Application([
    'id' => 'acrl-membership-controller-test',
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
$db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, smith_id TEXT, username TEXT, first_name TEXT, last_name TEXT, email TEXT, role TEXT, is_approved INTEGER)')->execute();
$db->createCommand('CREATE TABLE acrl_institution_members (user_id INTEGER, institution_code TEXT, role TEXT, granted_by TEXT, granted_at TEXT, PRIMARY KEY(user_id, institution_code))')->execute();
$db->createCommand('CREATE TABLE acrl_section_assignments (institution_code TEXT, survey_year INTEGER, section TEXT, user_id INTEGER)')->execute();
$db->createCommand('CREATE TABLE acrl_question_assignments (institution_code TEXT, survey_year INTEGER, sheet TEXT, question_id TEXT, part TEXT, user_id INTEGER)')->execute();
$db->createCommand()->batchInsert('users', ['id', 'smith_id', 'username', 'email', 'role', 'is_approved'], [
    [1, 'app-admin', 'app-admin', 'admin@smith.edu', 'admin', 1],
    [2, 'sc-admin', 'sc-admin', 'admin@smith.edu', 'user', 1],
    [3, 'sc-member', 'sc-member', 'member@smith.edu', 'user', 1],
    [4, 'hc-admin', 'hc-admin', 'admin@hampshire.edu', 'user', 1],
])->execute();
$db->createCommand()->batchInsert('acrl_institution_members', ['user_id', 'institution_code', 'role', 'granted_by', 'granted_at'], [
    [2, 'SC', 'admin', 1, '2026-11-13 12:00:00'],
    [3, 'SC', 'member', 1, '2026-11-13 12:00:00'],
    [4, 'HC', 'admin', 1, '2026-11-13 12:00:00'],
])->execute();
$db->createCommand()->batchInsert('acrl_section_assignments', ['institution_code', 'survey_year', 'section', 'user_id'], [
    ['SC', 2025, 'Circulation', 3], ['SC', 2026, 'Trends', 3], ['HC', 2026, 'Circulation', 3],
])->execute();
$db->createCommand()->batchInsert('acrl_question_assignments', ['institution_code', 'survey_year', 'sheet', 'question_id', 'part', 'user_id'], [
    ['SC', 2025, 'survey', '1', '', 3], ['SC', 2026, 'survey', '2', '', 3], ['HC', 2026, 'survey', '3', '', 3],
])->execute();
$controller = new AcrlMembershipController('acrl-membership', $app);

$app->user->setIdentity(User::findOne(1));
$institutions = $controller->actionInstitutions();
$members = $controller->actionMembers('HC');
if (($institutions[0]['code'] ?? null) !== 'SC' || ($institutions[0]['role'] ?? null) !== 'admin'
    || count($members['members'] ?? []) !== 1 || $app->response->statusCode !== 200) {
    fwrite(STDERR, "FAIL: app admins see enabled institutions and can manage staged non-SC members.\n");
    exit(1);
}

$app->user->setIdentity(User::findOne(2));
$app->response->statusCode = 200;
$deniedMembers = $controller->actionMembers('HC');
if ($app->response->statusCode !== 403 || ($deniedMembers['error'] ?? null) !== 'Forbidden') {
    fwrite(STDERR, "FAIL: college admins cannot manage another college's members.\n");
    exit(1);
}
$app->user->setIdentity(User::findOne(3));
$app->response->statusCode = 200;
$deniedCandidates = $controller->actionCandidates('SC');
if ($app->response->statusCode !== 403 || ($deniedCandidates['error'] ?? null) !== 'Forbidden') {
    fwrite(STDERR, "FAIL: members without admin rights cannot search member candidates.\n");
    exit(1);
}

$app->user->setIdentity(User::findOne(2));
$app->response->statusCode = 200;
$app->request->bodyParams = ['role' => 'admin'];
$updated = $controller->actionUpdateMember('SC', 3);
if ($app->response->statusCode !== 200 || ($updated['updated'] ?? false) !== true) {
    fwrite(STDERR, "FAIL: SC admins can update memberships for their college.\n");
    exit(1);
}
$removed = $controller->actionRemoveMember('SC', 3);
if ($app->response->statusCode !== 200 || ($removed['removed'] ?? false) !== true
    || (int)$db->createCommand("SELECT COUNT(*) FROM acrl_section_assignments WHERE institution_code = 'SC' AND user_id = 3")->queryScalar() !== 0
    || (int)$db->createCommand("SELECT COUNT(*) FROM acrl_question_assignments WHERE institution_code = 'SC' AND user_id = 3")->queryScalar() !== 0
    || (int)$db->createCommand("SELECT COUNT(*) FROM acrl_section_assignments WHERE institution_code = 'HC' AND user_id = 3")->queryScalar() !== 1) {
    fwrite(STDERR, "FAIL: membership removal clears same-college assignments and preserves other-college rows.\n");
    exit(1);
}

echo "PASS: ACRL membership API scopes managers and assignment cleanup\n";
