<?php

namespace app\services;

/** Recognizes institution-aware shapes for migrations 066 and 067. */
trait AcrlInstitutionAwareMigrationRecognitionTrait
{
    public static function acrlQuestionAssignmentsAppearComplete($db): bool
    {
        $expected = [
            'survey_year' => ['smallint', 'NO', null, null, null, null],
            'sheet' => ['varchar', 'NO', 8, 'ascii', 'ascii_bin', null],
            'question_id' => ['varchar', 'NO', 32, 'ascii', 'ascii_bin', null],
            'part' => ['varchar', 'NO', 1, 'ascii', 'ascii_bin', ''],
            'user_id' => ['int', 'NO', null, null, null, null],
            'assigned_by' => ['int', 'YES', null, null, null, null],
            'assigned_at' => ['datetime', 'NO', null, null, null, 'CURRENT_TIMESTAMP(6)'],
        ];
        $hasInstitution = self::hasInstitutionCodeColumn($db, 'acrl_question_assignments');
        return self::tableShapeAppearsComplete($db, 'acrl_question_assignments', $expected, true)
            && ($hasInstitution
                ? self::oneOfIndexesAppearsComplete($db, 'acrl_question_assignments', [
                    ['uq_acrl_question_assignment', ['survey_year', 'sheet', 'question_id', 'part', 'user_id']],
                    ['uq_acrl_question_assignment', ['institution_code', 'survey_year', 'sheet', 'question_id', 'part', 'user_id']],
                    ['uq_acrl_question_assignment_institution', ['institution_code', 'survey_year', 'sheet', 'question_id', 'part', 'user_id']],
                ], true)
                : self::indexAppearsComplete($db, 'acrl_question_assignments', 'uq_acrl_question_assignment',
                    ['survey_year', 'sheet', 'question_id', 'part', 'user_id'], true))
            && self::foreignKeysAppearComplete($db, 'acrl_question_assignments', [
                'survey_year' => ['acrl_survey_years', 'survey_year', 'CASCADE'],
                'user_id' => ['users', 'id', 'CASCADE'],
                'assigned_by' => ['users', 'id', 'SET NULL'],
            ], $hasInstitution);
    }

    public static function acrlInvitesAppearComplete($db): bool
    {
        $invites = [
            'id' => ['int', 'NO', null, null, null, null],
            'email' => ['varchar', 'NO', 191, 'utf8mb4', null, null],
            'survey_year' => ['smallint', 'NO', null, null, null, null],
            'invited_by' => ['int', 'YES', null, null, null, null],
            'link_origin' => ['varchar', 'NO', 255, 'utf8mb4', null, null],
            'status' => ['varchar', 'NO', 10, 'utf8mb4', null, 'pending'],
            'send_count' => ['int', 'NO', null, null, null, 0],
            'created_at' => ['datetime', 'NO', null, null, null, 'CURRENT_TIMESTAMP(6)'],
            'expires_at' => ['datetime', 'NO', null, null, null, null],
            'last_sent_at' => ['datetime', 'YES', null, null, null, null],
            'accepted_user_id' => ['int', 'YES', null, null, null, null],
            'accepted_at' => ['datetime', 'YES', null, null, null, null],
            'revoked_at' => ['datetime', 'YES', null, null, null, null],
        ];
        $targets = [
            'invite_id' => ['int', 'NO', null, null, null, null],
            'kind' => ['varchar', 'NO', 8, 'utf8mb4', null, null],
            'section' => ['varchar', 'NO', 120, 'utf8mb4', null, ''],
            'sheet' => ['varchar', 'NO', 8, 'ascii', 'ascii_bin', ''],
            'question_id' => ['varchar', 'NO', 32, 'ascii', 'ascii_bin', ''],
            'part' => ['varchar', 'NO', 1, 'ascii', 'ascii_bin', ''],
        ];

        $hasInstitution = self::hasInstitutionCodeColumn($db, 'acrl_invites');
        return self::tableShapeAppearsComplete($db, 'acrl_invites', $invites, true)
            && self::tableShapeAppearsComplete($db, 'acrl_invite_targets', $targets)
            && self::indexAppearsComplete($db, 'acrl_invites', 'PRIMARY', ['id'], true)
            && self::indexAppearsComplete($db, 'acrl_invites', 'idx_acrl_invites_email_status', ['email', 'status'], false)
            && self::indexAppearsComplete($db, 'acrl_invites', 'idx_acrl_invites_survey_year', ['survey_year'], false)
            && self::indexAppearsComplete($db, 'acrl_invite_targets', 'uq_acrl_invite_target',
                ['invite_id', 'kind', 'section', 'sheet', 'question_id', 'part'], true)
            && self::foreignKeysAppearComplete($db, 'acrl_invites', [
                'survey_year' => ['acrl_survey_years', 'survey_year', 'CASCADE'],
                'invited_by' => ['users', 'id', 'SET NULL'],
                'accepted_user_id' => ['users', 'id', 'SET NULL'],
            ], $hasInstitution)
            && self::foreignKeysAppearComplete($db, 'acrl_invite_targets', [
                'invite_id' => ['acrl_invites', 'id', 'CASCADE'],
            ]);
    }

    private static function hasInstitutionCodeColumn($db, string $table): bool
    {
        return (int)$db->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = 'institution_code'",
            [':table' => $table]
        )->queryScalar() > 0;
    }

    private static function oneOfIndexesAppearsComplete($db, string $table, array $indexes, bool $unique): bool
    {
        foreach ($indexes as $index) {
            if (self::indexAppearsComplete($db, $table, $index[0], $index[1], $unique)) {
                return true;
            }
        }
        return false;
    }
}
