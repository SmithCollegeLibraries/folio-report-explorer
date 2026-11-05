<?php

namespace app\services;

use app\models\User;

/** Validates invite input and resolves it to the current ACRL catalogue. */
class AcrlInviteRequestService
{
    private $db;
    private $sectionAssignments;
    private $questionAssignments;

    public function __construct($db, AcrlSectionAssignmentRepository $sectionAssignments, AcrlQuestionAssignmentRepository $questionAssignments)
    {
        $this->db = $db;
        $this->sectionAssignments = $sectionAssignments;
        $this->questionAssignments = $questionAssignments;
    }

    public static function linkOriginFromRequest(string $hostInfo, bool $isDev): string
    {
        if (preg_match('/^(https?):\/\/([^\/?#@]+)$/i', $hostInfo, $matches) !== 1) {
            throw new \InvalidArgumentException('Request host is invalid.');
        }
        $scheme = strtolower($matches[1]);
        $authority = $matches[2];
        if (($scheme !== 'https' && !($isDev && $scheme === 'http'))
            || preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $authority) !== 1
            || strpos($authority, '..') !== false) {
            throw new \InvalidArgumentException('Request host is invalid.');
        }
        $hostParts = explode(':', $authority);
        if (!self::validHostname($hostParts[0])
            || (count($hostParts) === 2 && ((int)$hostParts[1] < 1 || (int)$hostParts[1] > 65535))) {
            throw new \InvalidArgumentException('Request host is invalid.');
        }
        return $scheme . '://' . $authority;
    }

    private static function validHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (strlen($label) > 63 || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    public static function inviteFromAddress(string $linkOrigin): string
    {
        $parts = parse_url($linkOrigin);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Request host is invalid.');
        }
        return 'noreply@' . $parts['host'];
    }

    public function validateBasics(string $email, array $targets, User $admin): string
    {
        $email = strtolower(trim($email));
        $adminEmail = strtolower(trim((string)$admin->email));
        if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Your account has no Smith email on file; sign in again.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Enter a valid email address.');
        }
        if (strtolower(substr(strrchr($email, '@'), 1)) !== strtolower(substr(strrchr($adminEmail, '@'), 1))) {
            throw new \InvalidArgumentException('Invite email must use the same domain as your Smith email.');
        }
        if (empty($targets)) {
            throw new \InvalidArgumentException('Choose at least one invite target.');
        }
        return $email;
    }

    public function assertNoMatchingAccount(string $email): void
    {
        $at = strrpos($email, '@');
        $username = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $byEmail = $this->db->createCommand(
            'SELECT id, username, email, first_name, last_name, is_approved FROM users WHERE LOWER(email) = :email',
            [':email' => $email]
        )->queryAll();
        $byName = $this->db->createCommand(
            'SELECT id, username, email, first_name, last_name, is_approved FROM users WHERE LOWER(username) = :username',
            [':username' => $username]
        )->queryAll();
        $matches = [];
        foreach (array_merge($byEmail, $byName) as $row) {
            $emailMatch = strtolower((string)$row['email']) === $email;
            $usernameMatch = strtolower((string)$row['username']) === $username && $email === $username . '@' . $domain;
            if ($emailMatch || $usernameMatch) {
                $matches[(int)$row['id']] = $row;
            }
        }
        if (empty($matches)) {
            return;
        }
        $matches = array_values($matches);
        usort($matches, function ($left, $right) {
            $approval = (int)$right['is_approved'] <=> (int)$left['is_approved'];
            return $approval !== 0 ? $approval : ((int)$left['id'] <=> (int)$right['id']);
        });
        $user = $matches[0];
        $approved = (int)$user['is_approved'] === 1;
        $message = $approved ? 'Already in the app - assign them directly.' : 'Waiting for approval - approve them on the Users page.';
        $name = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
        if ($name === '') {
            $name = (string)$user['username'];
        }
        throw new AcrlInviteConflictException($message, ['id' => (int)$user['id'], 'displayName' => $name]);
    }

    public function normalizeTargets(int $year, array $targets): array
    {
        $normalized = [];
        foreach ($targets as $target) {
            if (!is_array($target) || !isset($target['kind']) || !is_string($target['kind'])) {
                throw new \InvalidArgumentException('An invite target is invalid.');
            }
            if ($target['kind'] === 'section') {
                $section = isset($target['section']) && is_string($target['section']) ? trim($target['section']) : '';
                if ($section === '' || !in_array($section, $this->sectionAssignments->sections($year), true)) {
                    throw new \InvalidArgumentException('An invite target is not in the published survey.');
                }
                $row = ['kind' => 'section', 'section' => $section, 'sheet' => '', 'question_id' => '', 'part' => ''];
            } elseif ($target['kind'] === 'question') {
                foreach (['sheet', 'questionId', 'part'] as $key) {
                    if (!isset($target[$key]) || !is_string($target[$key])) {
                        throw new \InvalidArgumentException('An invite target is invalid.');
                    }
                }
                if ($target['sheet'] !== 'survey' && $target['sheet'] !== 'trends') {
                    throw new \InvalidArgumentException('An invite target is not in the published survey.');
                }
                $question = $this->questionAssignments->question($year, $target['sheet'], $target['questionId'], $target['part']);
                if (!$question) {
                    throw new \InvalidArgumentException('An invite target is not in the published survey.');
                }
                $row = [
                    'kind' => 'question', 'section' => (string)$question['section'], 'sheet' => $target['sheet'],
                    'question_id' => $target['questionId'], 'part' => $target['part'],
                ];
            } else {
                throw new \InvalidArgumentException('An invite target is invalid.');
            }
            $normalized[implode("\0", $row)] = $row;
        }
        if (empty($normalized)) {
            throw new \InvalidArgumentException('Choose at least one invite target.');
        }
        return array_values($normalized);
    }

    public function addTargets(int $inviteId, array $targets): void
    {
        foreach ($targets as $target) {
            $exists = $this->db->createCommand(
                'SELECT COUNT(*) FROM acrl_invite_targets WHERE invite_id = :invite AND kind = :kind'
                    . ' AND section = :section AND sheet = :sheet AND question_id = :question AND part = :part',
                [':invite' => $inviteId, ':kind' => $target['kind'], ':section' => $target['section'],
                    ':sheet' => $target['sheet'], ':question' => $target['question_id'], ':part' => $target['part']]
            )->queryScalar();
            if ((int)$exists === 0) {
                $target['invite_id'] = $inviteId;
                $this->db->createCommand()->insert('acrl_invite_targets', $target)->execute();
            }
        }
    }
}
