<?php

namespace app\services;

use app\models\User;
use Yii;

require_once __DIR__ . '/AcrlInviteRequestService.php';
require_once __DIR__ . '/AcrlInvitePresentationService.php';
require_once __DIR__ . '/AcrlInstitutionMembershipService.php';

/** Persists, sends, lists, and accepts ACRL invitations. */
class AcrlInviteService
{
    private $db;
    private $sectionAssignments;
    private $questionAssignments;
    private $mailer;
    private $requests;
    private $presenter;

    public function __construct($db, AcrlSectionAssignmentRepository $sectionAssignments, AcrlQuestionAssignmentRepository $questionAssignments, AcrlInviteMailer $mailer)
    {
        $this->db = $db ?: Yii::$app->db;
        $this->sectionAssignments = $sectionAssignments;
        $this->questionAssignments = $questionAssignments;
        $this->mailer = $mailer;
        $this->requests = new AcrlInviteRequestService($this->db, $sectionAssignments, $questionAssignments);
        $this->presenter = new AcrlInvitePresentationService($this->db);
    }

    public static function linkOriginFromRequest(string $hostInfo, bool $isDev): string
    {
        return AcrlInviteRequestService::linkOriginFromRequest($hostInfo, $isDev);
    }

    public static function inviteFromAddress(string $linkOrigin): string
    {
        return AcrlInviteRequestService::inviteFromAddress($linkOrigin);
    }

    public function createInvite(int $year, string $email, array $targets, User $admin, string $linkOrigin, string $institutionCode)
    {
        $this->requireSmithInstitution($institutionCode);
        $email = $this->validateCreateBasics($email, $targets, $admin, $institutionCode);
        $inviteId = $this->transactionWithYearLock($year, function () use ($year, $email, $targets, $admin, $linkOrigin, $institutionCode) {
            if (!$this->sectionAssignments->isPublishedYear($year)) {
                throw new \InvalidArgumentException('The survey year must be published before inviting someone.');
            }
            $this->requests->assertNoMatchingAccount($email);
            $normalizedTargets = $this->requests->normalizeTargets($year, $targets);
            $existing = $this->db->createCommand(
                "SELECT id FROM acrl_invites WHERE institution_code = :institution AND email = :email AND survey_year = :year"
                    . " AND status = 'pending' AND expires_at > :now ORDER BY id DESC LIMIT 1",
                [':institution' => $institutionCode, ':email' => $email, ':year' => $year, ':now' => $this->now()]
            )->queryScalar();
            $expires = date('Y-m-d H:i:s', time() + (90 * 86400));
            if ($existing) {
                $id = (int)$existing;
                $this->db->createCommand()->update('acrl_invites', ['expires_at' => $expires], ['id' => $id])->execute();
            } else {
                $this->db->createCommand()->insert('acrl_invites', [
                    'institution_code' => $institutionCode,
                    'email' => $email,
                    'survey_year' => $year,
                    'invited_by' => (int)$admin->id,
                    'link_origin' => $linkOrigin,
                    'status' => 'pending',
                    'send_count' => 0,
                    'created_at' => $this->now(),
                    'expires_at' => $expires,
                ])->execute();
                $id = (int)$this->db->getLastInsertID();
            }
            $this->requests->addTargets($id, $normalizedTargets);
            return $id;
        });

        return $this->sendAndReturn((int)$inviteId);
    }

    public function validateCreateBasics(string $email, array $targets, User $admin, string $institutionCode = 'SC'): string
    {
        return $this->requests->validateBasics($email, $targets, $admin, $institutionCode);
    }

    public function listInvites(int $year, string $institutionCode): array
    {
        $this->requireSmithInstitution($institutionCode);
        return $this->presenter->listInvites($year, $institutionCode);
    }

    public function resendInvite(int $inviteId, User $admin)
    {
        $this->mutatePendingInvite($inviteId, function (array $row) {
            $this->db->createCommand()->update('acrl_invites', [
                'expires_at' => date('Y-m-d H:i:s', time() + (90 * 86400)),
            ], ['id' => (int)$row['id'], 'status' => 'pending'])->execute();
        });
        return $this->sendAndReturn($inviteId);
    }

    public function revokeInvite(int $inviteId, User $admin)
    {
        $row = $this->mutatePendingInvite($inviteId, function (array $row) {
            $this->db->createCommand()->update('acrl_invites', [
                'status' => 'revoked',
                'revoked_at' => $this->now(),
            ], ['id' => (int)$row['id'], 'status' => 'pending'])->execute();
        });
        return ['invite' => $this->presenter->loadFormattedInvite($inviteId)];
    }

    public function institutionForInvite(int $inviteId): ?string
    {
        $institution = $this->db->createCommand('SELECT institution_code FROM acrl_invites WHERE id = :id', [':id' => $inviteId])->queryScalar();
        return $institution === false || $institution === null ? null : (string)$institution;
    }

    public function acceptOnSignIn(User $user, ?string $eppn, ?string $uid): bool
    {
        $eppn = strtolower(trim((string)$eppn));
        if ($eppn === '') {
            return false;
        }
        $candidates = $this->db->createCommand(
            "SELECT id, institution_code, email, survey_year FROM acrl_invites WHERE institution_code = 'SC' ORDER BY survey_year, id"
        )->queryAll();
        $matched = [];
        foreach ($candidates as $candidate) {
            if ($this->matchesSignIn((string)$candidate['email'], $eppn)) {
                $matched[(int)$candidate['id']] = $candidate;
            }
        }
        if (empty($matched)) {
            return false;
        }
        $inviteIds = array_keys($matched);
        $years = array_values(array_unique(array_map(function ($row) { return (int)$row['survey_year']; }, $matched)));
        sort($years, SORT_NUMERIC);

        return $this->inTransaction(function () use ($inviteIds, $years, $matched, $user, $eppn) {
            $yearRows = $this->lockYears($years);
            $published = [];
            foreach ($yearRows as $yearRow) {
                $published[(int)$yearRow['survey_year']] = $yearRow['status'] === 'published';
            }
            $rows = $this->lockInvites($inviteIds);
            $toAccept = [];
            foreach ($rows as $invite) {
                $year = (int)$invite['survey_year'];
                if (!$this->matchesSignIn((string)$invite['email'], $eppn)
                    || $invite['status'] !== 'pending' || strtotime((string)$invite['expires_at']) <= time()) {
                    continue;
                }
                $targets = $this->presenter->targetRows((int)$invite['id']);
                $usable = [];
                if (!empty($published[$year])) {
                    foreach ($targets as $target) {
                        $resolved = $this->resolveStoredTarget($year, $target);
                        if ($resolved !== null) {
                            $usable[] = $resolved;
                        }
                    }
                }
                if (empty($usable)) {
                    continue;
                }
                $toAccept[] = ['invite' => $invite, 'targets' => $usable];
            }
            if (empty($toAccept)) return false;
            $approval = $this->db->createCommand(
                'SELECT is_approved FROM users WHERE id = :id' . ($this->db->driverName === 'sqlite' ? '' : ' FOR UPDATE'),
                [':id' => (int)$user->id]
            )->queryScalar();
            if ($approval === false || $approval === null) return false;
            if (!(int)$approval) {
                $user->is_approved = 1;
                if (!$user->save(false)) throw new \RuntimeException('Could not save invited user approval.');
            }
            foreach ($toAccept as $item) {
                $invite = $item['invite'];
                $year = (int)$invite['survey_year'];
                $institution = (string)$invite['institution_code'];
                $this->grantInviteMembership((int)$user->id, $institution);
                $assignedBy = $invite['invited_by'] === null ? null : (int)$invite['invited_by'];
                foreach ($item['targets'] as $target) {
                    if ($target['kind'] === 'section') {
                        $this->sectionAssignments->addSectionAssignment($year, $target['section'], (int)$user->id, $assignedBy, (string)$invite['institution_code']);
                    } else {
                        $this->questionAssignments->addQuestionAssignment(
                            $year, $target['sheet'], $target['question_id'], $target['part'], (int)$user->id, $assignedBy, (string)$invite['institution_code']
                        );
                    }
                }
                $this->db->createCommand()->update('acrl_invites', [
                    'status' => 'accepted', 'accepted_user_id' => (int)$user->id,
                    'accepted_at' => $this->now(),
                ], ['id' => (int)$invite['id'], 'status' => 'pending'])->execute();
            }
            return true;
        });
    }

    private function transactionWithYearLock(int $year, callable $callback)
    {
        return $this->inTransaction(function () use ($year, $callback) {
            $this->lockYears([$year]);
            return call_user_func($callback);
        });
    }

    private function mutatePendingInvite(int $inviteId, callable $mutator): array
    {
        return $this->inTransaction(function () use ($inviteId, $mutator) {
            $year = $this->db->createCommand('SELECT survey_year FROM acrl_invites WHERE id = :id', [':id' => $inviteId])->queryScalar();
            if ($year === false || $year === null) {
                throw new AcrlInviteConflictException('This invite is no longer pending.');
            }
            $this->lockYears([(int)$year]);
            $row = $this->db->createCommand(
                $this->forUpdate('SELECT id, institution_code, email, survey_year, invited_by, link_origin, status, send_count, created_at, expires_at, last_sent_at, accepted_user_id, accepted_at, revoked_at FROM acrl_invites WHERE id = :id'),
                [':id' => $inviteId]
            )->queryOne();
            if ($row && !in_array((string)$row['institution_code'], AcrlInstitutionMembershipService::ENABLED_INSTITUTIONS, true)) {
                throw new \DomainException('The invite was not found.');
            }
            if (!$row || $row['status'] !== 'pending' || strtotime((string)$row['expires_at']) <= time()) {
                throw new AcrlInviteConflictException('This invite is no longer pending.');
            }
            call_user_func($mutator, $row);
            return $row;
        });
    }

    private function sendAndReturn(int $inviteId): array
    {
        $row = $this->presenter->loadInviteRow($inviteId);
        if (!$row) {
            throw new \RuntimeException('Invite was not found after save.');
        }
        $targets = $this->presenter->formatTargets($inviteId);
        $text = $this->presenter->inviteText($row, $targets);
        $from = self::inviteFromAddress((string)$row['link_origin']);
        $sent = $this->mailer->send($inviteId, (string)$row['email'], $text['subject'], $text['body'], $from, $from);
        if ($sent) {
            $this->inTransaction(function () use ($inviteId) {
                $this->db->createCommand(
                    'UPDATE acrl_invites SET send_count = send_count + 1, last_sent_at = :sentAt WHERE id = :id',
                    [':sentAt' => $this->now(), ':id' => $inviteId]
                )->execute();
            });
        }
        $updated = $this->presenter->loadFormattedInvite($inviteId);
        return ['invite' => $updated, 'emailSent' => $sent, 'inviteText' => $text];
    }

    private function resolveStoredTarget(int $year, array $target): ?array
    {
        if ($target['kind'] === 'section') {
            return in_array((string)$target['section'], $this->sectionAssignments->sections($year), true) ? $target : null;
        }
        $question = $this->questionAssignments->question($year, (string)$target['sheet'], (string)$target['question_id'], (string)$target['part']);
        if (!$question || (string)$target['sheet'] !== (string)$question['sheet']
            || (string)$target['question_id'] !== (string)$question['question_id']
            || (string)$target['part'] !== (string)$question['part']) {
            return null;
        }
        return [
            'kind' => 'question', 'section' => (string)$question['section'], 'sheet' => (string)$question['sheet'],
            'question_id' => (string)$question['question_id'], 'part' => (string)$question['part'],
        ];
    }

    private function grantInviteMembership(int $userId, string $institution): void
    {
        if ($this->db->driverName === 'sqlite') {
            $sql = "INSERT INTO acrl_institution_members (user_id, institution_code, role, granted_by, granted_at)"
                . " SELECT :user, :institution, 'member', NULL, :grantedAt WHERE NOT EXISTS"
                . ' (SELECT 1 FROM acrl_institution_members WHERE user_id = :user AND institution_code = :institution)';
        } else {
            $sql = "INSERT IGNORE INTO acrl_institution_members (user_id, institution_code, role, granted_by, granted_at)"
                . " VALUES (:user, :institution, 'member', NULL, :grantedAt)";
        }
        $this->db->createCommand($sql, [
            ':user' => $userId, ':institution' => $institution, ':grantedAt' => $this->now(),
        ])->execute();
    }

    private function requireSmithInstitution(string $institutionCode): void
    {
        if (!in_array($institutionCode, AcrlInstitutionMembershipService::ENABLED_INSTITUTIONS, true)) {
            throw new \DomainException('institution_not_enabled', 403);
        }
    }

    private function matchesSignIn(string $inviteEmail, string $eppn): bool
    {
        $inviteEmail = strtolower(trim($inviteEmail));
        return $eppn !== '' && $inviteEmail === strtolower(trim($eppn));
    }

    private function lockYears(array $years): array
    {
        if (empty($years)) {
            return [];
        }
        sort($years, SORT_NUMERIC);
        $placeholders = [];
        $params = [];
        foreach (array_values($years) as $index => $year) {
            $key = ':year' . $index;
            $placeholders[] = $key;
            $params[$key] = (int)$year;
        }
        $sql = 'SELECT survey_year, status FROM acrl_survey_years WHERE survey_year IN (' . implode(', ', $placeholders) . ') ORDER BY survey_year';
        return $this->db->createCommand($this->forUpdate($sql), $params)->queryAll();
    }

    private function lockInvites(array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = ':invite' . $index;
            $placeholders[] = $key;
            $params[$key] = (int)$id;
        }
        $sql = 'SELECT id, institution_code, email, survey_year, invited_by, status, expires_at FROM acrl_invites WHERE id IN ('
            . implode(', ', $placeholders) . ') ORDER BY survey_year, id';
        return $this->db->createCommand($this->forUpdate($sql), $params)->queryAll();
    }

    private function forUpdate(string $sql): string
    {
        return $this->db->driverName === 'sqlite' ? $sql : $sql . ' FOR UPDATE';
    }

    private function inTransaction(callable $callback)
    {
        $transaction = $this->db->beginTransaction();
        try {
            $result = call_user_func($callback);
            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $error;
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s.u');
    }
}

class AcrlInviteConflictException extends \DomainException
{
    public $user;

    public function __construct(string $message, ?array $user = null)
    {
        parent::__construct($message, 409);
        $this->user = $user;
    }
}
