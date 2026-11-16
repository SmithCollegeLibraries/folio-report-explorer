<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/AcrlInstitutionMembershipService.php';

use app\services\AcrlInstitutionMembershipService;
use yii\web\Application;

$app = new Application([
    'id' => 'acrl-institution-membership-test',
    'basePath' => dirname(__DIR__),
    'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:']],
]);
$app->errorHandler->unregister();
$db = $app->db;
$db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, username TEXT, first_name TEXT, last_name TEXT, role TEXT, is_approved INTEGER)')->execute();
$db->createCommand('CREATE TABLE acrl_institution_members (user_id INTEGER, institution_code TEXT, role TEXT, granted_by TEXT, granted_at TEXT, PRIMARY KEY(user_id, institution_code))')->execute();
$db->createCommand('CREATE TABLE acrl_section_assignments (institution_code TEXT, survey_year INTEGER, section TEXT, user_id INTEGER)')->execute();
$db->createCommand('CREATE TABLE acrl_question_assignments (institution_code TEXT, survey_year INTEGER, sheet TEXT, question_id TEXT, part TEXT, user_id INTEGER)')->execute();
$db->createCommand()->batchInsert('users', ['id', 'email', 'username', 'first_name', 'last_name', 'role', 'is_approved'], [
    [1, 'appadmin@smith.edu', 'appadmin', 'App', 'Admin', 'admin', 1],
    [2, 'admin@smith.edu', 'college-admin', 'College', 'Admin', 'user', 1],
    [3, 'member@smith.edu', 'member', 'Synthetic', 'Member', 'user', 1],
    [4, 'other@amherst.edu', 'other', 'Other', 'College', 'user', 1],
    [5, 'pending@smith.edu', 'pending', 'Pending', 'User', 'user', 0],
])->execute();
$db->createCommand()->batchInsert('acrl_institution_members', ['user_id', 'institution_code', 'role', 'granted_by', 'granted_at'], [
    [2, 'SC', 'admin', 1, '2026-11-13 12:00:00'],
    [3, 'HC', 'member', 1, '2026-11-13 12:00:00'],
])->execute();

$failures = [];
function acrlMembershipCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

$service = new AcrlInstitutionMembershipService($db);
$service->add(2, 'SC', 3, 'member');
foreach (['AC', 'HC', 'MH', 'SC', 'UM'] as $institution) $service->add(1, $institution, 4, $institution === 'HC' ? 'admin' : 'member');
acrlMembershipCheck((int)$db->createCommand('SELECT COUNT(*) FROM acrl_institution_members WHERE user_id = 4')->queryScalar() === 5
    && (string)$db->createCommand('SELECT role FROM acrl_institution_members WHERE user_id = 4 AND institution_code = \'HC\'')->queryScalar() === 'admin',
    'App admins may stage memberships in all five colleges.');
$badDomain = false;
try { $service->add(2, 'SC', 4, 'member'); } catch (DomainException $error) { $badDomain = true; }
acrlMembershipCheck($badDomain, 'College admins cannot add a user from another college.');
$unapproved = false;
try { $service->add(1, 'SC', 5, 'member'); } catch (DomainException $error) { $unapproved = true; }
acrlMembershipCheck($unapproved, 'Only approved users can be added.');
acrlMembershipCheck((string)$db->createCommand('SELECT role FROM users WHERE id = 3')->queryScalar() === 'user'
    && (int)$db->createCommand('SELECT is_approved FROM users WHERE id = 3')->queryScalar() === 1,
    'Membership changes do not change app role or approval.');
acrlMembershipCheck($service->isMember(1, 'SC') && $service->canManage(2, 'SC') && !$service->canManage(3, 'SC'), 'App admins and college admin roles are scoped as expected.');
acrlMembershipCheck(count($service->institutionsForUser(1)) === 1 && $service->institutionsForUser(1)[0]['role'] === 'admin', 'The institution list exposes enabled colleges to app admins.');
$service->update(2, 'SC', 3, 'member');
acrlMembershipCheck((string)$db->createCommand('SELECT role FROM acrl_institution_members WHERE user_id = 3 AND institution_code = \'SC\'')->queryScalar() === 'member', 'Repeating a member role update is accepted.');

$db->createCommand()->batchInsert('acrl_section_assignments', ['institution_code', 'survey_year', 'section', 'user_id'], [
    ['SC', 2025, 'Circulation', 3], ['SC', 2026, 'Trends', 3], ['HC', 2026, 'Circulation', 3],
])->execute();
$db->createCommand()->batchInsert('acrl_question_assignments', ['institution_code', 'survey_year', 'sheet', 'question_id', 'part', 'user_id'], [
    ['SC', 2025, 'survey', '1', '', 3], ['SC', 2026, 'survey', '2', '', 3], ['HC', 2026, 'survey', '3', '', 3],
])->execute();
$service->remove(2, 'SC', 3);
acrlMembershipCheck((int)$db->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE user_id = 3 AND institution_code = \'SC\'')->queryScalar() === 0
    && (int)$db->createCommand('SELECT COUNT(*) FROM acrl_question_assignments WHERE user_id = 3 AND institution_code = \'SC\'')->queryScalar() === 0,
    'Removal deletes the member\'s assignments across all years in that college.');
acrlMembershipCheck((int)$db->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE user_id = 3 AND institution_code = \'HC\'')->queryScalar() === 1
    && (int)$db->createCommand('SELECT COUNT(*) FROM acrl_question_assignments WHERE user_id = 3 AND institution_code = \'HC\'')->queryScalar() === 1,
    'Removal leaves the same user\'s assignments in another college untouched.');
$db->createCommand()->insert('acrl_section_assignments', ['institution_code' => 'SC', 'survey_year' => 2026, 'section' => 'Self removal', 'user_id' => 2])->execute();
$service->remove(2, 'SC', 2);
acrlMembershipCheck((int)$db->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE user_id = 2 AND institution_code = \'SC\'')->queryScalar() === 0, 'College admins may remove themselves and clear their assignments.');
$routes = (string)file_get_contents(__DIR__ . '/../config/web.php');
foreach ([
    "'GET acrl/institutions' => 'acrl-membership/institutions'",
    "'GET acrl/institutions/<code:[A-Z]{2}>/members' => 'acrl-membership/members'",
    "'GET acrl/institutions/<code:[A-Z]{2}>/member-candidates' => 'acrl-membership/candidates'",
    "'POST acrl/institutions/<code:[A-Z]{2}>/members' => 'acrl-membership/add-member'",
    "'PUT acrl/institutions/<code:[A-Z]{2}>/members/<userId:\\\\d+>' => 'acrl-membership/update-member'",
    "'DELETE acrl/institutions/<code:[A-Z]{2}>/members/<userId:\\\\d+>' => 'acrl-membership/remove-member'",
] as $route) acrlMembershipCheck(strpos($routes, $route) !== false, 'Members API route is registered: ' . $route);

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL membership checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}
echo "PASS: ACRL membership roles, eligibility, and scoped removal\n";
