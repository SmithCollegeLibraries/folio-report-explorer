<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/AcrlUserApprovalService.php';

use app\models\User;
use app\services\AcrlUserApprovalService;
use yii\web\Application;

$app = new Application([
    'id' => 'acrl-user-approval-test',
    'basePath' => dirname(__DIR__),
    'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:']],
]);
$app->errorHandler->unregister();
$db = $app->db;
$db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, smith_id TEXT, username TEXT, email TEXT, first_name TEXT, last_name TEXT, affiliation TEXT, role TEXT, is_approved INTEGER)')->execute();
$db->createCommand('CREATE TABLE acrl_institution_members (user_id INTEGER, institution_code TEXT, role TEXT, granted_by TEXT, granted_at TEXT, PRIMARY KEY(user_id, institution_code))')->execute();
$db->createCommand()->batchInsert('users', ['id', 'smith_id', 'username', 'email', 'role', 'is_approved'], [
    [1, 'smith-one', 'smith-one', 'member@smith.edu', 'user', 0],
    [2, 'smith-two', 'smith-two', 'admin@smith.edu', 'user', 1],
    [3, 'amherst', 'amherst', 'person@amherst.edu', 'user', 0],
    [4, 'smith-fail', 'smith-fail', 'fail@smith.edu', 'user', 0],
])->execute();
$db->createCommand()->insert('acrl_institution_members', [
    'user_id' => 2, 'institution_code' => 'SC', 'role' => 'admin', 'granted_by' => null, 'granted_at' => '2026-11-13 12:00:00',
])->execute();

$failures = [];
function acrlApprovalCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

$service = new AcrlUserApprovalService($db);
$service->setApproved(1, true);
acrlApprovalCheck((int)User::findOne(1)->is_approved === 1, 'Approval transition persists.');
acrlApprovalCheck((string)$db->createCommand('SELECT role FROM acrl_institution_members WHERE user_id = 1 AND institution_code = \'SC\'')->queryScalar() === 'member', 'Approving a Smith account creates an SC member.');
$service->setApproved(2, true);
acrlApprovalCheck((string)$db->createCommand('SELECT role FROM acrl_institution_members WHERE user_id = 2 AND institution_code = \'SC\'')->queryScalar() === 'admin', 'Repeated approval does not downgrade or re-add an existing college admin.');
$service->setApproved(2, false);
$service->setApproved(2, true);
acrlApprovalCheck((string)$db->createCommand('SELECT role FROM acrl_institution_members WHERE user_id = 2 AND institution_code = \'SC\'')->queryScalar() === 'admin', 'Reapproval preserves an existing college admin.');
$db->createCommand()->delete('acrl_institution_members', ['user_id' => 2])->execute();
$service->setApproved(2, true);
acrlApprovalCheck((int)$db->createCommand('SELECT COUNT(*) FROM acrl_institution_members WHERE user_id = 2')->queryScalar() === 0, 'Repeated approval after membership removal does not restore membership.');
$service->setApproved(2, false);
$service->setApproved(2, true);
acrlApprovalCheck((string)$db->createCommand('SELECT role FROM acrl_institution_members WHERE user_id = 2 AND institution_code = \'SC\'')->queryScalar() === 'member', 'A new approval transition after unapproval restores removed Smith membership.');
$service->setApproved(3, true);
acrlApprovalCheck((int)$db->createCommand('SELECT COUNT(*) FROM acrl_institution_members WHERE user_id = 3')->queryScalar() === 0, 'Approving an Amherst account does not create an SC membership.');

$db->createCommand('DROP TABLE acrl_institution_members')->execute();
$db->createCommand('CREATE TABLE acrl_institution_members (user_id INTEGER, institution_code TEXT CHECK(institution_code <> \'SC\'), role TEXT, granted_by TEXT, granted_at TEXT, PRIMARY KEY(user_id, institution_code))')->execute();
$thrown = false;
try {
    $service->setApproved(4, true);
} catch (Throwable $error) {
    $thrown = true;
}
acrlApprovalCheck($thrown && (int)User::findOne(4)->is_approved === 0, 'A member insert failure rolls approval back.');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL user approval checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}
echo "PASS: ACRL approval transitions and Smith membership\n";
