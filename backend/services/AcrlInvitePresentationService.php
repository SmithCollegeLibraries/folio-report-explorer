<?php

namespace app\services;

/** Shapes invite API responses and the copyable plain-text email. */
class AcrlInvitePresentationService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function listInvites(int $year): array
    {
        $rows = $this->db->createCommand(
            'SELECT i.id, i.email, i.survey_year, i.invited_by, i.link_origin, i.status, i.send_count, i.created_at, '
                . 'i.expires_at, i.last_sent_at, i.accepted_user_id, i.accepted_at, i.revoked_at, '
                . 'u.id AS inviter_id, u.username AS inviter_username, u.first_name AS inviter_first_name, '
                . 'u.last_name AS inviter_last_name FROM acrl_invites i LEFT JOIN users u ON u.id = i.invited_by '
                . 'WHERE i.survey_year = :year ORDER BY i.id DESC',
            [':year' => $year]
        )->queryAll();
        $invites = [];
        $pendingCount = 0;
        foreach ($rows as $row) {
            $item = $this->formatInvite($row);
            if ($item['status'] === 'pending') {
                $item['inviteText'] = $this->inviteText($row, $item['targets']);
                $pendingCount++;
            }
            $invites[] = $item;
        }
        return ['invites' => $invites, 'pendingCount' => $pendingCount];
    }

    public function loadFormattedInvite(int $inviteId): array
    {
        $row = $this->loadInviteRow($inviteId);
        if (!$row) {
            throw new AcrlInviteConflictException('This invite is no longer pending.');
        }
        return $this->formatInvite($row);
    }

    public function loadInviteRow(int $inviteId): ?array
    {
        $row = $this->db->createCommand(
            'SELECT i.id, i.email, i.survey_year, i.invited_by, i.link_origin, i.status, i.send_count, i.created_at, '
                . 'i.expires_at, i.last_sent_at, i.accepted_user_id, i.accepted_at, i.revoked_at, '
                . 'u.id AS inviter_id, u.username AS inviter_username, u.first_name AS inviter_first_name, '
                . 'u.last_name AS inviter_last_name FROM acrl_invites i LEFT JOIN users u ON u.id = i.invited_by WHERE i.id = :id',
            [':id' => $inviteId]
        )->queryOne();
        return $row ?: null;
    }

    public function formatInvite(array $row): array
    {
        $status = (string)$row['status'];
        if ($status === 'pending' && strtotime((string)$row['expires_at']) <= time()) {
            $status = 'expired';
        }
        $invitedBy = null;
        if ($row['inviter_id'] !== null) {
            $name = trim((string)$row['inviter_first_name'] . ' ' . (string)$row['inviter_last_name']);
            $invitedBy = ['id' => (int)$row['inviter_id'], 'displayName' => $name !== '' ? $name : (string)$row['inviter_username']];
        }
        return [
            'id' => (int)$row['id'], 'email' => (string)$row['email'], 'surveyYear' => (int)$row['survey_year'],
            'status' => $status, 'targets' => $this->formatTargets((int)$row['id']), 'invitedBy' => $invitedBy,
            'createdAt' => $row['created_at'], 'expiresAt' => $row['expires_at'], 'lastSentAt' => $row['last_sent_at'] ?: '',
            'sendCount' => (int)$row['send_count'], 'acceptedAt' => $row['accepted_at'] ?: '',
        ];
    }

    public function formatTargets(int $inviteId): array
    {
        $targets = [];
        foreach ($this->targetRows($inviteId) as $target) {
            $label = $target['kind'] === 'section' ? (string)$target['section'] : 'Q' . $target['question_id'];
            if ($target['kind'] === 'question' && (string)$target['part'] !== '') {
                $label .= ' · ' . $target['part'];
            }
            $targets[] = [
                'kind' => (string)$target['kind'], 'section' => (string)$target['section'],
                'sheet' => (string)$target['sheet'], 'questionId' => (string)$target['question_id'],
                'part' => (string)$target['part'], 'label' => $label,
            ];
        }
        return $targets;
    }

    public function targetRows(int $inviteId): array
    {
        return $this->db->createCommand(
            'SELECT invite_id, kind, section, sheet, question_id, part FROM acrl_invite_targets WHERE invite_id = :id'
                . ' ORDER BY kind, section, sheet, question_id, part',
            [':id' => $inviteId]
        )->queryAll();
    }

    public function inviteText(array $row, array $targets): array
    {
        $year = (int)$row['survey_year'];
        $name = trim((string)($row['inviter_first_name'] ?? '') . ' ' . (string)($row['inviter_last_name'] ?? ''));
        if ($name === '') {
            $name = (string)($row['inviter_username'] ?? 'ACRL survey administrator');
        }
        $basePath = rtrim(getenv('APP_BASE_PATH') ?: '', '/');
        $link = rtrim((string)$row['link_origin'], '/') . $basePath . '/acrl/entry/' . $year;
        $labels = array_map(function ($target) { return $target['label']; }, $targets);
        $body = 'Hello, ' . "\n\n" . $name . ' invited you to help with the ACRL survey FY ' . $year . '.';
        if (!empty($labels)) {
            $body .= "\n\nAssigned to: " . implode(', ', $labels) . '.';
        }
        $body .= "\n\nOpen the survey: " . $link
            . "\n\nSign in with your Smith login, then open this link again."
            . "\n\nThis invite expires on " . date('Y-m-d', strtotime((string)$row['expires_at'])) . '.';
        return [
            'subject' => "[FOLIO Report Explorer] You're invited to help with the ACRL survey FY " . $year,
            'body' => $body,
        ];
    }
}
