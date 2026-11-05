<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/AcrlSectionAssignmentRepository.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentRepository.php';
require_once __DIR__ . '/../services/AcrlInviteMailer.php';
require_once __DIR__ . '/../services/AcrlInviteService.php';

use app\models\User;
use app\services\AcrlInviteMailer;
use app\services\AcrlInviteService;
use app\services\AcrlQuestionAssignmentRepository;
use app\services\AcrlSectionAssignmentRepository;
use yii\web\Application;

$app = new Application([
    'id' => 'acrl-invite-service-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'test-key'],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['class' => yii\web\User::class, 'identityClass' => User::class, 'enableSession' => false],
        'log' => ['targets' => []],
    ],
]);
$app->errorHandler->unregister();
$appBasePath = '/reports';
putenv('APP_BASE_PATH=' . $appBasePath);
$db = $app->db;
$db->createCommand('PRAGMA foreign_keys = ON')->execute();
$db->createCommand('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, smith_id TEXT NOT NULL UNIQUE, username TEXT NOT NULL,
    first_name TEXT, last_name TEXT, affiliation TEXT, email TEXT, role TEXT NOT NULL DEFAULT \'user\',
    is_approved INTEGER NOT NULL DEFAULT 0, receive_notifications INTEGER NOT NULL DEFAULT 1,
    default_campus TEXT, allocation_colleges TEXT, last_login TEXT, refresh_token TEXT,
    created_at TEXT, updated_at TEXT
)')->execute();
$db->createCommand('CREATE TABLE acrl_survey_years (survey_year INTEGER PRIMARY KEY, status TEXT NOT NULL)')->execute();
$db->createCommand('CREATE TABLE acrl_questions (
    survey_year INTEGER NOT NULL, sheet TEXT NOT NULL, question_id TEXT NOT NULL, part TEXT NOT NULL DEFAULT \'\',
    section TEXT NOT NULL, prompt TEXT NOT NULL, answer_type TEXT NOT NULL DEFAULT \'text\',
    required_status TEXT NOT NULL DEFAULT \'no\', survey_order INTEGER NOT NULL,
    PRIMARY KEY (survey_year, sheet, question_id, part)
)')->execute();
$db->createCommand('CREATE TABLE acrl_section_assignments (
    survey_year INTEGER NOT NULL, section TEXT NOT NULL, user_id INTEGER NOT NULL, assigned_by INTEGER NULL,
    UNIQUE (survey_year, section, user_id),
    FOREIGN KEY (survey_year) REFERENCES acrl_survey_years (survey_year) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
)')->execute();
$db->createCommand('CREATE TABLE acrl_question_assignments (
    survey_year INTEGER NOT NULL, sheet TEXT NOT NULL, question_id TEXT NOT NULL, part TEXT NOT NULL DEFAULT \'\',
    user_id INTEGER NOT NULL, assigned_by INTEGER NULL, assigned_at TEXT,
    UNIQUE (survey_year, sheet, question_id, part, user_id),
    FOREIGN KEY (survey_year) REFERENCES acrl_survey_years (survey_year) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
)')->execute();
$db->createCommand('CREATE TABLE acrl_invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, survey_year INTEGER NOT NULL, invited_by INTEGER NULL,
    link_origin TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'pending\', send_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL, expires_at TEXT NOT NULL, last_sent_at TEXT NULL, accepted_user_id INTEGER NULL,
    accepted_at TEXT NULL, revoked_at TEXT NULL,
    FOREIGN KEY (survey_year) REFERENCES acrl_survey_years (survey_year) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL,
    FOREIGN KEY (accepted_user_id) REFERENCES users (id) ON DELETE SET NULL
)')->execute();
$db->createCommand('CREATE TABLE acrl_invite_targets (
    invite_id INTEGER NOT NULL, kind TEXT NOT NULL, section TEXT NOT NULL DEFAULT \'\', sheet TEXT NOT NULL DEFAULT \'\',
    question_id TEXT NOT NULL DEFAULT \'\', part TEXT NOT NULL DEFAULT \'\',
    UNIQUE (invite_id, kind, section, sheet, question_id, part),
    FOREIGN KEY (invite_id) REFERENCES acrl_invites (id) ON DELETE CASCADE
)')->execute();

$users = [
    [1, 'test-admin', 'admin', 'Synthetic', 'Admin', 'admin@smith.example', 'admin', 1],
    [2, 'test-admin-no-email', 'admin-no-email', 'Synthetic', 'No Email', null, 'admin', 1],
    [3, 'test-existing-approved', 'existing', 'Synthetic', 'Existing', 'known@smith.example', 'user', 1],
    [4, 'test-existing-pending', 'waiting', 'Synthetic', 'Waiting', 'elsewhere@example.test', 'user', 0],
    [5, 'test-new-user', 'newuser', 'Synthetic', 'Invitee', 'old-address@example.test', 'user', 0],
    [6, 'test-assigned-user', 'assigned', 'Synthetic', 'Assigned', 'assigned@smith.example', 'user', 1],
    [7, 'test-deleted-inviter', 'deleted-inviter', 'Synthetic', 'Inviter', 'deleted@smith.example', 'admin', 1],
    [8, 'test-approved-admin', 'approved-admin', 'Synthetic', 'Approved', 'already@smith.example', 'admin', 1],
];
foreach ($users as $user) {
    $db->createCommand()->insert('users', [
        'id' => $user[0], 'smith_id' => $user[1], 'username' => $user[2], 'first_name' => $user[3],
        'last_name' => $user[4], 'email' => $user[5], 'role' => $user[6], 'is_approved' => $user[7],
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ])->execute();
}
foreach ([[9, 'test-stored-email-user', 'stored-email-user', 'old-address@example.test'], [10, 'test-unmatched-user', 'unmatched-user', null], [11, 'test-expired-user', 'expired-user', null], [12, 'test-revoked-user', 'revoked-user', null], [13, 'test-no-target-user', 'no-target', null]] as $user) {
    $db->createCommand()->insert('users', [
        'id' => $user[0], 'smith_id' => $user[1], 'username' => $user[2], 'email' => $user[3],
        'first_name' => 'Synthetic', 'last_name' => 'Invitee', 'role' => 'user', 'is_approved' => 0,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ])->execute();
}
$db->createCommand()->batchInsert('acrl_survey_years', ['survey_year', 'status'], [[2025, 'draft'], [2026, 'published']])->execute();
foreach ([['survey', '60', 'A', 'Circulation'], ['survey', '61', '', 'Library services'], ['trends', 'T1', '', 'Trends']] as $index => $question) {
    $db->createCommand()->insert('acrl_questions', [
        'survey_year' => 2026, 'sheet' => $question[0], 'question_id' => $question[1], 'part' => $question[2],
        'section' => $question[3], 'prompt' => 'Synthetic ' . $question[1], 'survey_order' => $index + 1,
    ])->execute();
}

$failures = [];
function acrlInviteServiceCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

function acrlInviteServiceScenario(string $name, callable $scenario): void
{
    try {
        $scenario();
    } catch (Throwable $error) {
        acrlInviteServiceCheck(false, $name . ' threw ' . get_class($error) . ': ' . $error->getMessage());
    }
}

function acrlInviteServiceCall(callable $call, string $label)
{
    try {
        return $call();
    } catch (Throwable $error) {
        acrlInviteServiceCheck(false, $label . ' threw ' . get_class($error) . ': ' . $error->getMessage());
        return null;
    }
}

function acrlInviteServiceExpectError(callable $call, string $messagePart, string $label): void
{
    $error = null;
    try {
        $call();
    } catch (Throwable $caught) {
        $error = $caught;
    }
    acrlInviteServiceCheck($error !== null, $label . ' must be rejected.');
    acrlInviteServiceCheck($error === null || strpos($error->getMessage(), $messagePart) !== false, $label . ' returns the expected validation message.');
}

function acrlInviteServiceNew(?callable $sender = null): AcrlInviteService
{
    global $db;
    $mailer = new AcrlInviteMailer($sender);
    return new AcrlInviteService(
        $db,
        new AcrlSectionAssignmentRepository($db),
        new AcrlQuestionAssignmentRepository($db),
        $mailer
    );
}

function acrlInviteServiceAdmin(int $id = 1): User
{
    return User::findOne($id);
}

function acrlInviteServiceInsertInvite(int $id, string $email, string $status = 'pending', ?int $invitedBy = 1, ?string $expiresAt = null): void
{
    global $db;
    $db->createCommand()->insert('acrl_invites', [
        'id' => $id, 'email' => strtolower($email), 'survey_year' => 2026, 'invited_by' => $invitedBy,
        'link_origin' => 'https://reports.example.test', 'status' => $status, 'send_count' => 0,
        'created_at' => date('Y-m-d H:i:s'), 'expires_at' => $expiresAt ?: date('Y-m-d H:i:s', time() + 86400),
    ])->execute();
}

function acrlInviteServiceInsertTarget(int $inviteId, string $kind, string $section = '', string $sheet = '', string $questionId = '', string $part = ''): void
{
    global $db;
    $db->createCommand()->insert('acrl_invite_targets', [
        'invite_id' => $inviteId, 'kind' => $kind, 'section' => $section,
        'sheet' => $sheet, 'question_id' => $questionId, 'part' => $part,
    ])->execute();
}

$senderState = ['result' => true, 'calls' => [], 'committed' => []];
$service = acrlInviteServiceNew(function ($recipient, $subject, $body, $from, $replyTo) use (&$senderState, $db) {
    $senderState['calls'][] = [$recipient, $subject, $body, $from, $replyTo];
    $senderState['committed'][] = $db->getTransaction() === null;
    return $senderState['result'];
});

acrlInviteServiceScenario('invite request validation', function () use ($service) {
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'reader@smith.example', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(2), 'https://reports.example.test');
    }, 'no Smith email on file', 'admin without a stored email');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'reader@other.example', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test');
    }, 'domain', 'address outside the inviting admin domain');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2030, 'reader@smith.example', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test');
    }, 'published', 'unpublished survey year');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'reader@smith.example', [], acrlInviteServiceAdmin(), 'https://reports.example.test');
    }, 'target', 'empty invite targets');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'not-an-email', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test');
    }, 'email', 'invalid email address');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'reader@smith.example', [['kind' => 'section', 'section' => 'Not a section']], acrlInviteServiceAdmin(), 'https://reports.example.test');
    }, 'target', 'unknown section target');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'reader@smith.example', [['kind' => 'question', 'sheet' => 'survey', 'questionId' => '60', 'part' => '']], acrlInviteServiceAdmin(), 'https://reports.example.test');
    }, 'target', 'question target whose full tuple is not in the catalogue');
});

acrlInviteServiceScenario('existing matching accounts return ordered 409 conflicts', function () use ($service) {
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'known@smith.example', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test');
    }, 'Already in the app - assign them directly.', 'approved matching account');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'waiting@smith.example', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test');
    }, 'Waiting for approval - approve them on the Users page.', 'unapproved username@domain account');
});

acrlInviteServiceScenario('repeat create deduplicates targets, resets expiry, and sends only after commit', function () use ($service, &$senderState, $db, $appBasePath) {
    $first = acrlInviteServiceCall(function () use ($service) { return $service->createInvite(2026, 'READER@SMITH.EXAMPLE', [
        ['kind' => 'section', 'section' => 'Circulation'],
    ], acrlInviteServiceAdmin(), 'https://reports.example.test'); }, 'First valid create');
    acrlInviteServiceCheck(is_array($first) && isset($first['invite']['id'], $first['invite']['email'], $first['invite']['surveyYear'], $first['invite']['status'], $first['invite']['targets'], $first['invite']['invitedBy'], $first['invite']['createdAt'], $first['invite']['expiresAt'], $first['invite']['lastSentAt'], $first['invite']['sendCount'], $first['invite']['acceptedAt'], $first['inviteText']['subject'], $first['inviteText']['body'], $first['emailSent']), 'Create returns the full invite shape, send outcome, and copy text.');
    acrlInviteServiceCheck(($first['inviteText']['subject'] ?? null) === "[FOLIO Report Explorer] You're invited to help with the ACRL survey FY 2026", 'Invite subject follows the exact ACRL FY template.');
    $body = (string)($first['inviteText']['body'] ?? '');
    acrlInviteServiceCheck(strpos($body, 'Synthetic Admin') !== false && strpos($body, 'Circulation') !== false, 'Invite body includes inviter name and target label.');
    acrlInviteServiceCheck(strpos($body, 'https://reports.example.test' . $appBasePath . '/acrl/entry/2026') !== false, 'Invite body link uses the stored request origin and APP_BASE_PATH.');
    acrlInviteServiceCheck(strpos($body, 'Sign in with your Smith login, then open this link again.') !== false, 'Invite body includes the D1 sign-in instruction.');
    $inviteId = (int)($first['invite']['id'] ?? 0);
    $sectionTarget = $db->createCommand('SELECT kind, section, sheet, question_id, part FROM acrl_invite_targets WHERE invite_id = :id', [':id' => $inviteId])->queryOne();
    acrlInviteServiceCheck(($sectionTarget['kind'] ?? null) === 'section' && ($sectionTarget['section'] ?? null) === 'Circulation'
        && ($sectionTarget['sheet'] ?? null) === '' && ($sectionTarget['question_id'] ?? null) === '' && ($sectionTarget['part'] ?? null) === '',
        'Section target stores empty question tuple columns.');
    $oldExpiry = date('Y-m-d H:i:s', time() + 60);
    $db->createCommand()->update('acrl_invites', ['expires_at' => $oldExpiry], ['id' => $inviteId])->execute();
    $second = acrlInviteServiceCall(function () use ($service) { return $service->createInvite(2026, 'reader@smith.example', [
        ['kind' => 'section', 'section' => 'Circulation'],
        ['kind' => 'question', 'sheet' => 'survey', 'questionId' => '60', 'part' => 'A'],
    ], acrlInviteServiceAdmin(), 'https://reports.example.test'); }, 'Repeated valid create');
    $row = $db->createCommand('SELECT * FROM acrl_invites WHERE id = :id', [':id' => $inviteId])->queryOne();
    $targetCount = (int)$db->createCommand('SELECT COUNT(*) FROM acrl_invite_targets WHERE invite_id = :id', [':id' => $inviteId])->queryScalar();
    acrlInviteServiceCheck(is_array($second) && (int)($second['invite']['id'] ?? 0) === $inviteId, 'Repeat invite reuses the active pending invite.');
    acrlInviteServiceCheck($targetCount === 2, 'Repeat invite adds distinct targets without duplicating the existing section target.');
    $questionTarget = $db->createCommand('SELECT kind, section, sheet, question_id, part FROM acrl_invite_targets WHERE invite_id = :id AND kind = :kind', [':id' => $inviteId, ':kind' => 'question'])->queryOne();
    acrlInviteServiceCheck(($questionTarget['kind'] ?? null) === 'question' && ($questionTarget['section'] ?? null) === 'Circulation'
        && ($questionTarget['sheet'] ?? null) === 'survey' && ($questionTarget['question_id'] ?? null) === '60' && ($questionTarget['part'] ?? null) === 'A',
        'Question target stores its section and full question tuple.');
    acrlInviteServiceCheck(isset($row['expires_at']) && $row['expires_at'] > $oldExpiry, 'Adding targets resets the 90-day expiry from server time.');
    acrlInviteServiceCheck(isset($row['expires_at']) && abs(strtotime($row['expires_at']) - (time() + 90 * 86400)) <= 5, 'Reset expiry is exactly 90 days from server time.');
    acrlInviteServiceCheck((int)($row['send_count'] ?? 0) === count($senderState['calls']), 'Successful sends increment send_count.');
    acrlInviteServiceCheck(count($senderState['committed']) >= 2 && !in_array(false, $senderState['committed'], true), 'Both invite emails are sent after the transaction commits.');
    acrlInviteServiceCheck(strtolower((string)($row['email'] ?? '')) === 'reader@smith.example', 'Invite email is stored in lower-case.');
});

acrlInviteServiceScenario('failed sender leaves counters unchanged and expired invites remain history', function () use ($service, &$senderState, $db) {
    $senderState['result'] = false;
    $result = acrlInviteServiceCall(function () use ($service) { return $service->createInvite(2026, 'mail-fails@smith.example', [
        ['kind' => 'question', 'sheet' => 'survey', 'questionId' => '61', 'part' => ''],
    ], acrlInviteServiceAdmin(), 'https://reports.example.test'); }, 'Create with failing sender');
    acrlInviteServiceCheck(is_array($result) && ($result['emailSent'] ?? true) === false, 'Mail failure returns emailSent=false and copy text.');
    $id = (int)($result['invite']['id'] ?? 0);
    $row = $id ? $db->createCommand('SELECT send_count, last_sent_at FROM acrl_invites WHERE id = :id', [':id' => $id])->queryOne() : [];
    acrlInviteServiceCheck((int)($row['send_count'] ?? -1) === 0 && ($row['last_sent_at'] ?? null) === null, 'Failed mail does not change send_count or last_sent_at.');
    $db->createCommand()->update('acrl_invites', ['expires_at' => date('Y-m-d H:i:s', time() - 1)], ['id' => $id])->execute();
    $senderState['result'] = true;
    $replacement = acrlInviteServiceCall(function () use ($service) { return $service->createInvite(2026, 'mail-fails@smith.example', [
        ['kind' => 'question', 'sheet' => 'survey', 'questionId' => '61', 'part' => ''],
    ], acrlInviteServiceAdmin(), 'https://reports.example.test'); }, 'Create after expired invite');
    acrlInviteServiceCheck((int)($replacement['invite']['id'] ?? 0) !== $id, 'A new invite after expiry creates a fresh history row.');
    $historyCount = (int)$db->createCommand('SELECT COUNT(*) FROM acrl_invites WHERE email = :email AND survey_year = 2026', [':email' => 'mail-fails@smith.example'])->queryScalar();
    acrlInviteServiceCheck($historyCount === 2, 'Expired invitation history is retained.');
});

acrlInviteServiceScenario('list, resend, and revoke preserve pending-only states', function () use ($service, $db) {
    $db->createCommand()->update('acrl_invites', ['status' => 'revoked'])->execute();
    acrlInviteServiceInsertInvite(100, 'pending-list@smith.example');
    acrlInviteServiceInsertTarget(100, 'question', 'Circulation', 'survey', '60', 'A');
    acrlInviteServiceInsertInvite(101, 'expired-list@smith.example', 'pending', 1, date('Y-m-d H:i:s', time() - 10));
    acrlInviteServiceInsertInvite(102, 'revoked-list@smith.example', 'revoked');
    acrlInviteServiceInsertInvite(103, 'accepted-list@smith.example', 'accepted');
    $listed = acrlInviteServiceCall(function () use ($service) { return $service->listInvites(2026); }, 'List invites');
    acrlInviteServiceCheck(is_array($listed) && isset($listed['invites'], $listed['pendingCount']), 'List returns invites and pendingCount.');
    acrlInviteServiceCheck(($listed['pendingCount'] ?? null) === 1, 'Only unexpired pending invites contribute to pendingCount.');
    $statuses = is_array($listed['invites'] ?? null) ? array_column($listed['invites'], 'status') : [];
    acrlInviteServiceCheck(in_array('expired', $statuses, true) && in_array('revoked', $statuses, true) && in_array('accepted', $statuses, true), 'List retains expired, revoked, and accepted history with computed status.');
    $listedIds = is_array($listed['invites'] ?? null) ? array_map('intval', array_column($listed['invites'], 'id')) : [];
    $newestFirst = $listedIds;
    rsort($newestFirst, SORT_NUMERIC);
    acrlInviteServiceCheck($listedIds === $newestFirst, 'Invite list orders newest first.');
    $pendingItem = null;
    foreach (($listed['invites'] ?? []) as $item) if ((int)($item['id'] ?? 0) === 100) $pendingItem = $item;
    acrlInviteServiceCheck(is_array($pendingItem) && isset($pendingItem['inviteText']['subject'], $pendingItem['inviteText']['body']), 'Pending list items include copyable invite text without sending.');
    acrlInviteServiceCheck(is_array($pendingItem) && isset($pendingItem['id'], $pendingItem['email'], $pendingItem['surveyYear'], $pendingItem['status'], $pendingItem['targets'], $pendingItem['invitedBy'], $pendingItem['createdAt'], $pendingItem['expiresAt'], $pendingItem['lastSentAt'], $pendingItem['sendCount'], $pendingItem['acceptedAt']), 'List invite item contains all documented invite response fields.');
    $questionLabel = $pendingItem['targets'][0]['label'] ?? null;
    acrlInviteServiceCheck($questionLabel === 'Q60 · A', 'Invite target labels use the full question tuple.');
    $resend = acrlInviteServiceCall(function () use ($service) { return $service->resendInvite(100, acrlInviteServiceAdmin()); }, 'Resend pending invite');
    acrlInviteServiceCheck(is_array($resend) && isset($resend['invite'], $resend['emailSent'], $resend['inviteText']), 'Resend returns the updated invite, send outcome, and invite text.');
    $revoked = acrlInviteServiceCall(function () use ($service) { return $service->revokeInvite(100, acrlInviteServiceAdmin()); }, 'Revoke pending invite');
    acrlInviteServiceCheck(is_array($revoked) && ($revoked['invite']['status'] ?? null) === 'revoked', 'Revoke changes a pending invite to revoked.');
    foreach ([101, 102, 103] as $id) {
        acrlInviteServiceExpectError(function () use ($service, $id) { $service->resendInvite($id, acrlInviteServiceAdmin()); }, 'no longer pending', 'resend of expired or non-pending invite ' . $id);
        acrlInviteServiceExpectError(function () use ($service, $id) { $service->revokeInvite($id, acrlInviteServiceAdmin()); }, 'no longer pending', 'revoke of expired or non-pending invite ' . $id);
    }
});

acrlInviteServiceScenario('accept matches only current Shibboleth identity and rejects unusable invites', function () use ($service, $db) {
    acrlInviteServiceInsertInvite(200, 'newuser@smith.example');
    acrlInviteServiceInsertTarget(200, 'section', 'Circulation');
    $eppnUser = User::findOne(5);
    $acceptedByEppn = acrlInviteServiceCall(function () use ($service, $eppnUser) { return $service->acceptOnSignIn($eppnUser, 'NEWUSER@SMITH.EXAMPLE', 'unrelated'); }, 'EPPN invite acceptance');
    acrlInviteServiceCheck($acceptedByEppn === true, 'EPPN match is case-insensitive and accepts the invite.');
    acrlInviteServiceCheck((int)User::findOne(5)->is_approved === 1, 'Accepted new user is approved through the ActiveRecord model.');
    acrlInviteServiceCheck((string)User::findOne(5)->role === 'user', 'Invite acceptance leaves the user role unchanged.');
    acrlInviteServiceCheck((string)$db->createCommand('SELECT status FROM acrl_invites WHERE id = 200')->queryScalar() === 'accepted', 'Matched invite is marked accepted.');
    acrlInviteServiceExpectError(function () use ($service) { $service->revokeInvite(200, acrlInviteServiceAdmin()); }, 'no longer pending', 'revoke after acceptance');
    acrlInviteServiceCheck((int)User::findOne(5)->is_approved === 1, 'A revoke attempt cannot undo an approval already granted by acceptance.');

    acrlInviteServiceInsertInvite(201, 'newuid@smith.example');
    acrlInviteServiceInsertTarget(201, 'question', 'Library services', 'survey', '61', '');
    $uidUser = new User(['smith_id' => 'synthetic-uid-user', 'username' => 'newuid', 'role' => 'user', 'is_approved' => 0]);
    $uidUser->save(false);
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service, $uidUser) { return $service->acceptOnSignIn($uidUser, null, 'newuid'); }, 'UID invite acceptance') === true, 'UID@invite-domain match accepts when EPPN is absent.');

    acrlInviteServiceInsertInvite(202, 'old-address@example.test');
    acrlInviteServiceInsertTarget(202, 'section', 'Circulation');
    $storedEmailUser = User::findOne(9);
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service, $storedEmailUser) { return $service->acceptOnSignIn($storedEmailUser, 'different@smith.example', 'different'); }, 'Stored-email mismatch') === false, 'Stored users.email is ignored when matching sign-in.');

    acrlInviteServiceInsertInvite(203, 'nobody@smith.example');
    acrlInviteServiceInsertTarget(203, 'section', 'Circulation');
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service) { return $service->acceptOnSignIn(User::findOne(10), 'another@smith.example', 'another'); }, 'No invite match') === false, 'A non-matching sign-in leaves invite and user unchanged.');
    acrlInviteServiceInsertInvite(204, 'expired-user@smith.example', 'pending', 1, date('Y-m-d H:i:s', time() - 1));
    acrlInviteServiceInsertTarget(204, 'section', 'Circulation');
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service) { return $service->acceptOnSignIn(User::findOne(11), 'expired-user@smith.example', 'expired-user'); }, 'Expired invite') === false, 'Expired pending invite is ignored.');
    acrlInviteServiceInsertInvite(205, 'revoked-user@smith.example', 'revoked');
    acrlInviteServiceInsertTarget(205, 'section', 'Circulation');
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service) { return $service->acceptOnSignIn(User::findOne(12), 'revoked-user@smith.example', 'revoked-user'); }, 'Revoked invite') === false, 'Revoked invite is ignored.');
    acrlInviteServiceInsertInvite(206, 'no-target@smith.example');
    acrlInviteServiceInsertTarget(206, 'question', 'Removed section', 'survey', 'missing', '');
    $before = (int)User::findOne(13)->is_approved;
    acrlInviteServiceCheck(acrlInviteServiceCall(function () use ($service) { return $service->acceptOnSignIn(User::findOne(13), 'no-target@smith.example', 'no-target'); }, 'Invite without usable targets') === false, 'Invite with no current published target is not accepted.');
    acrlInviteServiceCheck((int)User::findOne(13)->is_approved === $before && $before === 0, 'Invite with no usable target does not approve the new user.');
    acrlInviteServiceCheck((string)$db->createCommand('SELECT status FROM acrl_invites WHERE id = 206')->queryScalar() === 'pending', 'Invite with no usable target remains pending.');
});

acrlInviteServiceScenario('accept adds both assignment kinds idempotently and preserves existing assignees', function () use ($service, $db) {
    acrlInviteServiceInsertInvite(210, 'additive@smith.example');
    acrlInviteServiceInsertTarget(210, 'section', 'Circulation');
    acrlInviteServiceInsertTarget(210, 'question', 'Circulation', 'survey', '60', 'A');
    $db->createCommand()->insert('acrl_section_assignments', ['survey_year' => 2026, 'section' => 'Circulation', 'user_id' => 6, 'assigned_by' => 1])->execute();
    $db->createCommand()->insert('acrl_question_assignments', ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => 6, 'assigned_by' => 1])->execute();
    $invitee = new User(['smith_id' => 'synthetic-additive-user', 'username' => 'additive', 'role' => 'user', 'is_approved' => 0]);
    $invitee->save(false);
    acrlInviteServiceCall(function () use ($service, $invitee) { return $service->acceptOnSignIn($invitee, 'additive@smith.example', 'additive'); }, 'First additive acceptance');
    acrlInviteServiceCall(function () use ($service, $invitee) { return $service->acceptOnSignIn(User::findOne($invitee->id), 'additive@smith.example', 'additive'); }, 'Repeated additive acceptance');
    $sectionRows = $db->createCommand('SELECT user_id, assigned_by FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section ORDER BY user_id', [':section' => 'Circulation'])->queryAll();
    $questionRows = $db->createCommand('SELECT user_id, assigned_by FROM acrl_question_assignments WHERE survey_year = 2026 AND question_id = :question ORDER BY user_id', [':question' => '60'])->queryAll();
    acrlInviteServiceCheck(count($sectionRows) === 2 && count($questionRows) === 2, 'Repeated acceptance preserves existing assignees and inserts the invitee once for both target kinds.');
    acrlInviteServiceCheck((int)($sectionRows[0]['user_id'] ?? 0) === 6 && (int)($questionRows[0]['user_id'] ?? 0) === 6, 'Existing section and question assignees are not replaced.');
    acrlInviteServiceCheck((int)($sectionRows[1]['assigned_by'] ?? 0) === 1 && (int)($questionRows[1]['assigned_by'] ?? 0) === 1, 'New assignment audit actor is the invite creator.');
    acrlInviteServiceCheck((string)User::findOne($invitee->id)->role === 'user', 'Acceptance never changes the role.');

    acrlInviteServiceInsertInvite(211, 'deleted-admin@smith.example', 'pending', 7);
    acrlInviteServiceInsertTarget(211, 'section', 'Library services');
    User::deleteAll(['id' => 7]);
    $deletedInviterUser = new User(['smith_id' => 'synthetic-deleted-inviter-invitee', 'username' => 'deleted-admin', 'role' => 'user', 'is_approved' => 0]);
    $deletedInviterUser->save(false);
    acrlInviteServiceCall(function () use ($service, $deletedInviterUser) { return $service->acceptOnSignIn($deletedInviterUser, 'deleted-admin@smith.example', 'deleted-admin'); }, 'Acceptance after inviter deletion');
    $assignedBy = $db->createCommand('SELECT assigned_by FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section AND user_id = :user', [':section' => 'Library services', ':user' => $deletedInviterUser->id])->queryScalar();
    acrlInviteServiceCheck($assignedBy === null, 'Deleted invite creator produces NULL assigned_by on accepted assignments.');

    acrlInviteServiceInsertInvite(212, 'already@smith.example');
    acrlInviteServiceInsertTarget(212, 'section', 'Trends');
    $approvedAdmin = User::findOne(8);
    acrlInviteServiceCall(function () use ($service, $approvedAdmin) { return $service->acceptOnSignIn($approvedAdmin, 'already@smith.example', 'approved-admin'); }, 'Already-approved acceptance');
    acrlInviteServiceCheck((int)User::findOne(8)->is_approved === 1 && (string)User::findOne(8)->role === 'admin', 'Already-approved user keeps approval and role while receiving targets.');
    acrlInviteServiceCheck((int)$db->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section AND user_id = 8', [':section' => 'Trends'])->queryScalar() === 1, 'Already-approved user receives invite targets.');
});

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL invite service checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}

echo "PASS: ACRL invite validation, lifecycle, mail, and sign-in acceptance\n";
