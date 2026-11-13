<?php

namespace app\services;

/** Recognizes migration 073 and its legitimate in-progress column states. */
class AcrlInstitutionMigrationRecognitionService
{
    private const TABLES = [
        'acrl_answers' => ['fk_acrl_answers_institution', 'PRIMARY', ['institution_code', 'survey_year', 'sheet', 'question_id', 'part']],
        'acrl_answer_history' => ['fk_acrl_answer_history_institution', 'idx_acrl_answer_history_institution', ['institution_code', 'survey_year', 'sheet', 'question_id', 'part', 'changed_at']],
        'acrl_section_status' => ['fk_acrl_section_status_institution', 'PRIMARY', ['institution_code', 'survey_year', 'section']],
        'acrl_section_assignments' => ['fk_acrl_section_assignment_institution', 'uq_acrl_section_assignment_institution', ['institution_code', 'survey_year', 'section', 'user_id']],
        'acrl_question_assignments' => ['fk_acrl_question_assignments_institution', 'uq_acrl_question_assignment_institution', ['institution_code', 'survey_year', 'sheet', 'question_id', 'part', 'user_id']],
        'acrl_question_notes' => ['fk_acrl_question_notes_institution', 'idx_acrl_question_notes_institution', ['institution_code', 'survey_year', 'sheet', 'question_id', 'part', 'created_at']],
        'acrl_invites' => ['fk_acrl_invites_institution', 'idx_acrl_invites_institution_year', ['institution_code', 'survey_year']],
    ];

    public static function migrationAppearsComplete($db): bool
    {
        return self::institutionTableAppearsComplete($db) && self::institutionRowsAppearComplete($db)
            && self::acrlTablesAppearComplete($db);
    }

    public static function institutionColumnIsPartialOrComplete($db, string $table): bool
    {
        $row = $db->createCommand(
            'SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT '
                . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table '
                . "AND COLUMN_NAME = 'institution_code'",
            [':table' => $table]
        )->queryOne();
        if (!$row) {
            return true;
        }
        $default = $row['COLUMN_DEFAULT'];
        if (strtolower((string)$row['DATA_TYPE']) !== 'char'
            || strtolower((string)$row['COLUMN_TYPE']) !== 'char(2)'
            || strtoupper((string)$row['IS_NULLABLE']) !== 'NO'
            || strtolower((string)$row['CHARACTER_SET_NAME']) !== 'ascii'
            || strtolower((string)$row['COLLATION_NAME']) !== 'ascii_bin'
            || ($default !== null && !in_array(strtolower((string)$default), ["'sc'", 'sc'], true))) {
            return false;
        }

        return true;
    }

    public static function institutionColumnAppearsComplete($db, string $table): bool
    {
        if (!self::institutionColumnIsPartialOrComplete($db, $table)) {
            return false;
        }
        $row = $db->createCommand(
            'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
                . "AND TABLE_NAME = :table AND COLUMN_NAME = 'institution_code'",
            [':table' => $table]
        )->queryOne();
        return is_array($row) && $row['COLUMN_DEFAULT'] === null;
    }

    private static function institutionTableAppearsComplete($db): bool
    {
        $columns = $db->createCommand(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT '
                . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            [':table' => 'acrl_institutions']
        )->queryAll();
        if (count($columns) !== 4) {
            return false;
        }
        $byName = [];
        foreach ($columns as $column) {
            $byName[strtolower((string)$column['COLUMN_NAME'])] = $column;
        }
        $code = $byName['code'] ?? null;
        $name = $byName['name'] ?? null;
        $order = $byName['sort_order'] ?? null;
        $active = $byName['active'] ?? null;
        $table = $db->createCommand(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            [':table' => 'acrl_institutions']
        )->queryOne();
        return is_array($table) && strtolower((string)$table['ENGINE']) === 'innodb'
            && stripos((string)$table['TABLE_COLLATION'], 'utf8mb4_') === 0
            && $code && strtolower((string)$code['DATA_TYPE']) === 'char'
            && strtolower((string)$code['COLUMN_TYPE']) === 'char(2)'
            && strtoupper((string)$code['IS_NULLABLE']) === 'NO'
            && strtolower((string)$code['CHARACTER_SET_NAME']) === 'ascii'
            && strtolower((string)$code['COLLATION_NAME']) === 'ascii_bin'
            && $code['COLUMN_DEFAULT'] === null
            && $name && strtolower((string)$name['DATA_TYPE']) === 'varchar'
            && strtolower((string)$name['COLUMN_TYPE']) === 'varchar(100)'
            && strtoupper((string)$name['IS_NULLABLE']) === 'NO'
            && $order && strtolower((string)$order['DATA_TYPE']) === 'tinyint'
            && strtoupper((string)$order['IS_NULLABLE']) === 'NO'
            && $active && strtolower((string)$active['DATA_TYPE']) === 'tinyint'
            && strtoupper((string)$active['IS_NULLABLE']) === 'NO'
            && strtolower((string)$active['COLUMN_TYPE']) === 'tinyint(1)'
            && (int)$active['COLUMN_DEFAULT'] === 1
            && self::indexAppearsComplete($db, 'acrl_institutions', 'PRIMARY', ['code'], true);
    }

    private static function institutionRowsAppearComplete($db): bool
    {
        $rows = $db->createCommand('SELECT code, name, sort_order, active FROM acrl_institutions ORDER BY code')->queryAll();
        $expected = [
            ['AC', 'Amherst College', 1],
            ['HC', 'Hampshire College', 2],
            ['MH', 'Mount Holyoke College', 3],
            ['SC', 'Smith College', 4],
            ['UM', 'University of Massachusetts Amherst', 5],
        ];
        $actual = [];
        foreach ($rows as $row) {
            if ((int)$row['active'] !== 1) {
                return false;
            }
            $actual[] = [(string)$row['code'], (string)$row['name'], (int)$row['sort_order']];
        }
        return $actual === $expected;
    }

    private static function acrlTablesAppearComplete($db): bool
    {
        foreach (self::TABLES as $table => $shape) {
            if (!self::institutionColumnAppearsComplete($db, $table)
                || !self::indexAppearsComplete($db, $table, $shape[1], $shape[2], in_array($table, [
                    'acrl_answers', 'acrl_section_status', 'acrl_section_assignments', 'acrl_question_assignments',
                ], true))
                || !self::institutionForeignKeyAppearsComplete($db, $table, $shape[0])) {
                return false;
            }
        }
        return self::indexAppearsComplete($db, 'acrl_answers', 'idx_acrl_answers_question', ['survey_year', 'sheet', 'question_id', 'part'], false)
            && self::indexAppearsComplete($db, 'acrl_section_status', 'idx_acrl_section_status_year', ['survey_year'], false)
            && self::indexAppearsComplete($db, 'acrl_section_assignments', 'idx_acrl_section_assignment_year', ['survey_year'], false)
            && self::indexAppearsComplete($db, 'acrl_question_assignments', 'idx_acrl_question_assignments_year', ['survey_year'], false)
            && self::indexAppearsComplete($db, 'acrl_question_assignments', 'idx_acrl_question_assignments_user_year', ['user_id', 'survey_year'], false)
            && self::indexAppearsComplete($db, 'acrl_question_notes', 'idx_acrl_question_notes_key', ['survey_year', 'sheet', 'question_id', 'part', 'created_at'], false)
            && self::indexAppearsComplete($db, 'acrl_invites', 'idx_acrl_invites_survey_year', ['survey_year'], false);
    }

    private static function institutionForeignKeyAppearsComplete($db, string $table, string $constraint): bool
    {
        $rows = $db->createCommand(
            'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME '
                . 'FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() '
                . 'AND k.TABLE_NAME = :table AND k.CONSTRAINT_NAME = :constraint AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [':table' => $table, ':constraint' => $constraint]
        )->queryAll();
        return count($rows) === 1 && strtolower((string)$rows[0]['COLUMN_NAME']) === 'institution_code'
            && strtolower((string)$rows[0]['REFERENCED_TABLE_SCHEMA']) === strtolower((string)$db->createCommand('SELECT DATABASE()')->queryScalar())
            && strtolower((string)$rows[0]['REFERENCED_TABLE_NAME']) === 'acrl_institutions'
            && strtolower((string)$rows[0]['REFERENCED_COLUMN_NAME']) === 'code';
    }

    private static function indexAppearsComplete($db, string $table, string $index, array $columns, bool $unique): bool
    {
        $rows = $db->createCommand(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index ORDER BY SEQ_IN_INDEX',
            [':table' => $table, ':index' => $index]
        )->queryAll();
        if (count($rows) !== count($columns)) {
            return false;
        }
        foreach ($columns as $position => $column) {
            if ((int)$rows[$position]['NON_UNIQUE'] !== ($unique ? 0 : 1)
                || (int)$rows[$position]['SEQ_IN_INDEX'] !== $position + 1
                || strtolower((string)$rows[$position]['COLUMN_NAME']) !== $column
                || $rows[$position]['SUB_PART'] !== null) {
                return false;
            }
        }
        return true;
    }
}
