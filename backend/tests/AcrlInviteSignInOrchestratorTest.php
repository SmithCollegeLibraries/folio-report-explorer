<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/AcrlInviteSignInOrchestrator.php';

use app\models\User;
use app\services\AcrlInviteSignInOrchestrator;
use yii\web\Application;

$app = new Application([
    'id' => 'acrl-invite-sign-in-orchestrator-test',
    'basePath' => dirname(__DIR__),
    'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:']],
]);
$app->errorHandler->unregister();
$db = $app->db;
$db->createCommand('CREATE TABLE users (id INTEGER PRIMARY KEY, smith_id TEXT, username TEXT, role TEXT, is_approved INTEGER)')->execute();
$db->createCommand()->batchInsert('users', ['id', 'smith_id', 'username', 'role', 'is_approved'], [
    [1, 'new-unapproved', 'new-unapproved', 'user', 0],
    [2, 'approved-existing', 'approved-existing', 'admin', 1],
])->execute();

$failures = [];
function acrlInviteOrchestratorCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

function acrlInviteOrchestratorRun(int $userId, bool $isNewUser, $acceptResult, bool $throw, string $case, ?string $eppn = 'sign-in@smith.example', ?string $uid = 'sign-in'): array
{
    global $db;
    if ($userId === 1) $db->createCommand()->update('users', ['is_approved' => 0], ['id' => 1])->execute();
    $user = User::findOne($userId);
    $events = [];
    $logs = [];
    $orchestrator = new AcrlInviteSignInOrchestrator();
    $result = null;
    try {
        $result = $orchestrator->run(
            $user,
            $isNewUser,
            $eppn,
            $uid,
            function ($passedUser, $eppn, $uid) use (&$events, $acceptResult, $throw, $case, $db) {
                $events[] = ['accept', $passedUser->id, $eppn, $uid];
                if ($throw) {
                    $passedUser->is_approved = 1;
                    throw new RuntimeException('Synthetic database failure with private details jane.test@smith.example');
                }
                if (is_callable($acceptResult)) {
                    return call_user_func($acceptResult, $eppn, $uid);
                }
                if ($acceptResult) {
                    $db->createCommand()->update('users', ['is_approved' => 1], ['id' => $passedUser->id])->execute();
                }
                return $acceptResult;
            },
            function ($passedUser) use (&$events) {
                $events[] = ['reload', $passedUser->id];
                return User::findOne($passedUser->id);
            },
            function ($passedUser) use (&$events) {
                $events[] = ['notify', $passedUser->id];
            },
            function ($passedUser) use (&$events) {
                $events[] = ['tokens', $passedUser->id];
                return ['accessToken' => 'synthetic-access', 'refreshToken' => 'synthetic-refresh'];
            },
            function ($code) use (&$logs) {
                $logs[] = $code;
            }
        );
    } catch (Throwable $error) {
        acrlInviteOrchestratorCheck(false, $case . ' must catch acceptance errors and continue sign-in: ' . $error->getMessage());
    }
    return ['result' => $result, 'events' => $events, 'logs' => $logs];
}

$accepted = acrlInviteOrchestratorRun(1, true, true, false, 'accepted invite');
$acceptedEvents = array_column($accepted['events'], 0);
acrlInviteOrchestratorCheck($acceptedEvents === ['accept', 'reload', 'tokens'], 'Successful invite acceptance reloads before issuing tokens and skips the new-user admin email.');
acrlInviteOrchestratorCheck(($accepted['result']['redirect'] ?? null) === '/', 'Successful invite sign-in returns the home-page destination.');
acrlInviteOrchestratorCheck(($accepted['result']['tokens']['accessToken'] ?? null) === 'synthetic-access' && ($accepted['result']['tokens']['refreshToken'] ?? null) === 'synthetic-refresh', 'Successful invite sign-in issues both tokens in the same sign-in.');

foreach ([
    ['unmatched', false, false],
    ['expired', false, false],
    ['zero usable targets', false, false],
] as $case) {
    list($label, $acceptedResult, $throws) = $case;
    $run = acrlInviteOrchestratorRun(1, true, $acceptedResult, $throws, $label);
    $events = array_column($run['events'], 0);
    acrlInviteOrchestratorCheck($events === ['accept', 'reload', 'notify'], $label . ' reloads after failed acceptance, notifies admins, and redirects pending without tokens.');
    acrlInviteOrchestratorCheck(($run['result']['redirect'] ?? null) === '/auth/pending', $label . ' remains on the normal pending-user path.');
    acrlInviteOrchestratorCheck(!isset($run['result']['tokens']), $label . ' issues no tokens for an unapproved user.');
}

$failed = acrlInviteOrchestratorRun(1, true, false, true, 'thrown acceptance error');
acrlInviteOrchestratorCheck(($failed['logs'] ?? null) === ['ACRL_INVITE_ACCEPT_FAILED'], 'Acceptance Throwable logs only the fixed failure code.');
acrlInviteOrchestratorCheck(array_column($failed['events'], 0) === ['accept', 'reload', 'notify'], 'A Throwable after an in-memory approval reloads the persisted user before notification.');
acrlInviteOrchestratorCheck(!isset($failed['result']['tokens']), 'Caught acceptance Throwable cannot issue tokens for an unapproved user.');

$approved = acrlInviteOrchestratorRun(2, false, false, false, 'approved user with unmatched invite');
acrlInviteOrchestratorCheck(array_column($approved['events'], 0) === ['accept', 'reload', 'tokens'], 'Failed invite acceptance leaves approved-user access unchanged.');
acrlInviteOrchestratorCheck(($approved['result']['redirect'] ?? null) === '/', 'Approved user still reaches the home page.');

$authorizeSource = (string)file_get_contents(__DIR__ . '/../../admin/authorize.php');
$findPosition = strpos($authorizeSource, 'User::findOne([\'smith_id\' => $smithId])');
$notifyPosition = strpos($authorizeSource, 'User::notifyAdminsOfNewUser($user)');
$orchestratorPosition = strpos($authorizeSource, 'AcrlInviteSignInOrchestrator');
acrlInviteOrchestratorCheck($findPosition !== false && $orchestratorPosition !== false && $notifyPosition !== false
    && $findPosition < $orchestratorPosition && $orchestratorPosition < $notifyPosition,
    'authorize.php calls the testable invite sign-in orchestrator after find-or-create and before new-user notification.');
$previousRemoteUser = $_SERVER['REMOTE_USER'] ?? null;
$_SERVER['REMOTE_USER'] = 'sign-in';
$remoteIdentity = (string)$_SERVER['REMOTE_USER'];
$withoutShibUid = acrlInviteOrchestratorRun(1, true, function ($eppn, $uid) use ($remoteIdentity) {
    return ($eppn !== null && strtolower($eppn) === $remoteIdentity . '@smith.example')
        || ($uid !== null && strtolower($uid) === $remoteIdentity);
}, false, 'missing Shibboleth uid with matching REMOTE_USER', null, null);
if ($previousRemoteUser === null) unset($_SERVER['REMOTE_USER']); else $_SERVER['REMOTE_USER'] = $previousRemoteUser;
$remoteEvents = $withoutShibUid['events'];
acrlInviteOrchestratorCheck(isset($remoteEvents[0]) && $remoteEvents[0][0] === 'accept'
    && $remoteEvents[0][2] === null && $remoteEvents[0][3] === null,
    'With no Shibboleth uid, a matching REMOTE_USER is not passed as an invite identity.');
acrlInviteOrchestratorCheck(array_column($remoteEvents, 0) === ['accept', 'reload', 'notify']
    && !isset($withoutShibUid['result']['tokens']),
    'Absent Shibboleth uid does not accept the invite or issue tokens based on REMOTE_USER.');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL invite sign-in orchestration checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}

echo "PASS: ACRL invite acceptance and authorize.php sign-in orchestration\n";
