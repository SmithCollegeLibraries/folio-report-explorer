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
    institution_code TEXT NOT NULL, survey_year INTEGER NOT NULL, section TEXT NOT NULL, user_id INTEGER NOT NULL, assigned_by INTEGER NULL,
    UNIQUE (institution_code, survey_year, section, user_id),
    FOREIGN KEY (survey_year) REFERENCES acrl_survey_years (survey_year) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
)')->execute();
$db->createCommand('CREATE TABLE acrl_question_assignments (
    institution_code TEXT NOT NULL, survey_year INTEGER NOT NULL, sheet TEXT NOT NULL, question_id TEXT NOT NULL, part TEXT NOT NULL DEFAULT \'\',
    user_id INTEGER NOT NULL, assigned_by INTEGER NULL, assigned_at TEXT,
    UNIQUE (institution_code, survey_year, sheet, question_id, part, user_id),
    FOREIGN KEY (survey_year) REFERENCES acrl_survey_years (survey_year) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
)')->execute();
$db->createCommand('CREATE TABLE acrl_institution_members (
    user_id INTEGER NOT NULL, institution_code TEXT NOT NULL, role TEXT NOT NULL, granted_by TEXT NULL, granted_at TEXT NOT NULL,
    PRIMARY KEY (user_id, institution_code), CHECK(user_id <> 9999)
)')->execute();
$db->createCommand('CREATE TABLE acrl_invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT, institution_code TEXT NOT NULL, email TEXT NOT NULL, survey_year INTEGER NOT NULL, invited_by INTEGER NULL,
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
    [1, 'test-admin', 'admin', 'Synthetic', 'Admin', 'admin@smith.edu', 'admin', 1],
    [2, 'test-admin-no-email', 'admin-no-email', 'Synthetic', 'No Email', null, 'admin', 1],
    [3, 'test-existing-approved', 'existing', 'Synthetic', 'Existing', 'known@smith.edu', 'user', 1],
    [4, 'test-existing-pending', 'waiting', 'Synthetic', 'Waiting', 'elsewhere@example.test', 'user', 0],
    [5, 'test-new-user', 'newuser', 'Synthetic', 'Invitee', 'old-address@example.test', 'user', 0],
    [6, 'test-assigned-user', 'assigned', 'Synthetic', 'Assigned', 'assigned@smith.edu', 'user', 1],
    [7, 'test-deleted-inviter', 'deleted-inviter', 'Synthetic', 'Inviter', 'deleted@smith.edu', 'admin', 1],
    [8, 'test-approved-admin', 'approved-admin', 'Synthetic', 'Approved', 'already@smith.edu', 'admin', 1],
];
foreach ($users as $user) {
    $db->createCommand()->insert('users', [
        'id' => $user[0], 'smith_id' => $user[1], 'username' => $user[2], 'first_name' => $user[3],
        'last_name' => $user[4], 'email' => $user[5], 'role' => $user[6], 'is_approved' => $user[7],
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ])->execute();
}
$db->createCommand()->insert('acrl_institution_members', [
    'user_id' => 8, 'institution_code' => 'SC', 'role' => 'admin', 'granted_by' => null, 'granted_at' => '2026-01-01 00:00:00',
])->execute();
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

function acrlInviteServiceInsertInvite(int $id, string $email, string $status = 'pending', ?int $invitedBy = 1, ?string $expiresAt = null, string $institutionCode = 'SC'): void
{
    global $db;
    $db->createCommand()->insert('acrl_invites', [
        'id' => $id, 'institution_code' => $institutionCode, 'email' => strtolower($email), 'survey_year' => 2026, 'invited_by' => $invitedBy,
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
        $service->createInvite(2026, 'reader@smith.edu', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(2), 'https://reports.example.test', 'SC');
    }, 'no email on file', 'admin without a stored email');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'reader@other.example', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC');
    }, 'domain', 'address outside the inviting admin domain');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2030, 'reader@smith.edu', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC');
    }, 'published', 'unpublished survey year');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'reader@smith.edu', [], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC');
    }, 'target', 'empty invite targets');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'not-an-email', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC');
    }, 'email', 'invalid email address');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'reader@smith.edu', [['kind' => 'section', 'section' => 'Not a section']], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC');
    }, 'target', 'unknown section target');
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'reader@smith.edu', [['kind' => 'question', 'sheet' => 'survey', 'questionId' => '60', 'part' => '']], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC');
    }, 'target', 'question target whose full tuple is not in the catalogue');
});

acrlInviteServiceScenario('full-email conflicts do not disclose a bare-username account', function () use ($service, $db, &$senderState) {
    acrlInviteServiceExpectError(function () use ($service) {
        $service->createInvite(2026, 'known@smith.edu', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC');
    }, 'Already in the app - assign them directly.', 'approved matching account');
    $created = acrlInviteServiceCall(function () use ($service) {
        return $service->createInvite(2026, 'waiting@smith.edu', [['kind' => 'section', 'section' => 'Circulation']], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC');
    }, 'Invite address sharing only a username');
    acrlInviteServiceCheck(is_array($created) && ($created['invite']['email'] ?? null) === 'waiting@smith.edu', 'A different email with the same bare username does not block or appear in the invite response.');
    $db->createCommand()->update('acrl_invites', ['status' => 'revoked'], ['email' => 'waiting@smith.edu'])->execute();
    $senderState['calls'] = [];
    $senderState['committed'] = [];
});

acrlInviteServiceScenario('repeat create deduplicates targets, resets expiry, and sends only after commit', function () use ($service, &$senderState, $db, $appBasePath) {
    $first = acrlInviteServiceCall(function () use ($service) { return $service->createInvite(2026, 'READER@smith.edu', [
        ['kind' => 'section', 'section' => 'Circulation'],
    ], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC'); }, 'First valid create');
    acrlInviteServiceCheck(is_array($first) && isset($first['invite']['id'], $first['invite']['institution'], $first['invite']['email'], $first['invite']['surveyYear'], $first['invite']['status'], $first['invite']['targets'], $first['invite']['invitedBy'], $first['invite']['createdAt'], $first['invite']['expiresAt'], $first['invite']['lastSentAt'], $first['invite']['sendCount'], $first['invite']['acceptedAt'], $first['inviteText']['subject'], $first['inviteText']['body'], $first['emailSent']), 'Create returns the institution-scoped invite shape, send outcome, and copy text.');
    acrlInviteServiceCheck(($first['invite']['institution'] ?? null) === 'SC', 'Create response exposes the selected institution.');
    acrlInviteServiceCheck(($first['inviteText']['subject'] ?? null) === "[FOLIO Report Explorer] You're invited to help with the ACRL survey FY 2026", 'Invite subject follows the exact ACRL FY template.');
    $body = (string)($first['inviteText']['body'] ?? '');
    acrlInviteServiceCheck(strpos($body, 'Synthetic Admin') !== false && strpos($body, 'Circulation') !== false, 'Invite body includes inviter name and target label.');
    acrlInviteServiceCheck(strpos($body, 'https://reports.example.test' . $appBasePath . '/acrl/entry/2026') !== false, 'Invite body link uses the stored request origin and APP_BASE_PATH.');
    acrlInviteServiceCheck(strpos($body, 'Sign in with your college account, then open this link again.') !== false, 'Invite body uses a college-neutral sign-in instruction.');
    $inviteId = (int)($first['invite']['id'] ?? 0);
    $sectionTarget = $db->createCommand('SELECT kind, section, sheet, question_id, part FROM acrl_invite_targets WHERE invite_id = :id', [':id' => $inviteId])->queryOne();
    acrlInviteServiceCheck(($sectionTarget['kind'] ?? null) === 'section' && ($sectionTarget['section'] ?? null) === 'Circulation'
        && ($sectionTarget['sheet'] ?? null) === '' && ($sectionTarget['question_id'] ?? null) === '' && ($sectionTarget['part'] ?? null) === '',
        'Section target stores empty question tuple columns.');
    $oldExpiry = date('Y-m-d H:i:s', time() + 60);
    $db->createCommand()->update('acrl_invites', ['expires_at' => $oldExpiry], ['id' => $inviteId])->execute();
    $second = acrlInviteServiceCall(function () use ($service) { return $service->createInvite(2026, 'reader@smith.edu', [
        ['kind' => 'section', 'section' => 'Circulation'],
        ['kind' => 'question', 'sheet' => 'survey', 'questionId' => '60', 'part' => 'A'],
    ], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC'); }, 'Repeated valid create');
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
    acrlInviteServiceCheck(strtolower((string)($row['email'] ?? '')) === 'reader@smith.edu', 'Invite email is stored in lower-case.');
});

acrlInviteServiceScenario('failed sender leaves counters unchanged and expired invites remain history', function () use ($service, &$senderState, $db) {
    $senderState['result'] = false;
    $result = acrlInviteServiceCall(function () use ($service) { return $service->createInvite(2026, 'mail-fails@smith.edu', [
        ['kind' => 'question', 'sheet' => 'survey', 'questionId' => '61', 'part' => ''],
    ], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC'); }, 'Create with failing sender');
    acrlInviteServiceCheck(is_array($result) && ($result['emailSent'] ?? true) === false, 'Mail failure returns emailSent=false and copy text.');
    $id = (int)($result['invite']['id'] ?? 0);
    $row = $id ? $db->createCommand('SELECT send_count, last_sent_at FROM acrl_invites WHERE id = :id', [':id' => $id])->queryOne() : [];
    acrlInviteServiceCheck((int)($row['send_count'] ?? -1) === 0 && ($row['last_sent_at'] ?? null) === null, 'Failed mail does not change send_count or last_sent_at.');
    $db->createCommand()->update('acrl_invites', ['expires_at' => date('Y-m-d H:i:s', time() - 1)], ['id' => $id])->execute();
    $senderState['result'] = true;
    $replacement = acrlInviteServiceCall(function () use ($service) { return $service->createInvite(2026, 'mail-fails@smith.edu', [
        ['kind' => 'question', 'sheet' => 'survey', 'questionId' => '61', 'part' => ''],
    ], acrlInviteServiceAdmin(), 'https://reports.example.test', 'SC'); }, 'Create after expired invite');
    acrlInviteServiceCheck((int)($replacement['invite']['id'] ?? 0) !== $id, 'A new invite after expiry creates a fresh history row.');
    $historyCount = (int)$db->createCommand('SELECT COUNT(*) FROM acrl_invites WHERE email = :email AND survey_year = 2026', [':email' => 'mail-fails@smith.edu'])->queryScalar();
    acrlInviteServiceCheck($historyCount === 2, 'Expired invitation history is retained.');
});

acrlInviteServiceScenario('list, resend, and revoke preserve pending-only states', function () use ($service, $db) {
    $db->createCommand()->update('acrl_invites', ['status' => 'revoked'])->execute();
    acrlInviteServiceInsertInvite(100, 'pending-list@smith.edu');
    acrlInviteServiceInsertTarget(100, 'question', 'Circulation', 'survey', '60', 'A');
    acrlInviteServiceInsertInvite(101, 'expired-list@smith.edu', 'pending', 1, date('Y-m-d H:i:s', time() - 10));
    acrlInviteServiceInsertInvite(102, 'revoked-list@smith.edu', 'revoked');
    acrlInviteServiceInsertInvite(103, 'accepted-list@smith.edu', 'accepted');
    $listed = acrlInviteServiceCall(function () use ($service) { return $service->listInvites(2026, 'SC'); }, 'List invites');
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
    acrlInviteServiceCheck(is_array($pendingItem) && isset($pendingItem['id'], $pendingItem['institution'], $pendingItem['email'], $pendingItem['surveyYear'], $pendingItem['status'], $pendingItem['targets'], $pendingItem['invitedBy'], $pendingItem['createdAt'], $pendingItem['expiresAt'], $pendingItem['lastSentAt'], $pendingItem['sendCount'], $pendingItem['acceptedAt']), 'List invite item contains all documented invite response fields.');
    $questionLabel = $pendingItem['targets'][0]['label'] ?? null;
    acrlInviteServiceCheck($questionLabel === 'Q60 · A', 'Invite target labels use the full question tuple.');
    acrlInviteServiceCheck(($pendingItem['institution'] ?? null) === 'SC', 'List response exposes each invite institution.');
    acrlInviteServiceInsertInvite(104, 'other-institution@smith.edu', 'pending', 1, null, 'HC');
    foreach (['resendInvite', 'revokeInvite'] as $operation) {
        $error = null;
        try {
            $service->$operation(104, acrlInviteServiceAdmin());
        } catch (DomainException $caught) {
            $error = $caught;
        }
        acrlInviteServiceCheck($error !== null, $operation . ' cannot act on an invite owned by another institution.');
    }
    $resend = acrlInviteServiceCall(function () use ($service) { return $service->resendInvite(100, acrlInviteServiceAdmin()); }, 'Resend pending invite');
    acrlInviteServiceCheck(is_array($resend) && isset($resend['invite'], $resend['emailSent'], $resend['inviteText']), 'Resend returns the updated invite, send outcome, and invite text.');
    $revoked = acrlInviteServiceCall(function () use ($service) { return $service->revokeInvite(100, acrlInviteServiceAdmin()); }, 'Revoke pending invite');
    acrlInviteServiceCheck(is_array($revoked) && ($revoked['invite']['status'] ?? null) === 'revoked', 'Revoke changes a pending invite to revoked.');
    foreach ([101, 102, 103] as $id) {
        acrlInviteServiceExpectError(function () use ($service, $id) { $service->resendInvite($id, acrlInviteServiceAdmin()); }, 'no longer pending', 'resend of expired or non-pending invite ' . $id);
        acrlInviteServiceExpectError(function () use ($service, $id) { $service->revokeInvite($id, acrlInviteServiceAdmin()); }, 'no longer pending', 'revoke of expired or non-pending invite ' . $id);
    }
});

require __DIR__ . '/AcrlInviteServiceAcceptanceCases.php';

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL invite service checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}

echo "PASS: ACRL invite validation, lifecycle, mail, and sign-in acceptance\n";
