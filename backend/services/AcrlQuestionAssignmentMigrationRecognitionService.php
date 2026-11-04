<?php

namespace app\services;

/** Recognizes migration 066 without changing the legacy current-schema baseline. */
class AcrlQuestionAssignmentMigrationRecognitionService
{
    public static function assertAcrlQuestionAssignmentsAppearComplete($db): void
    {
        if (!self::acrlQuestionAssignmentsAppearComplete($db)) {
            throw new \RuntimeException('Migration 066 left acrl_question_assignments without its required schema.');
        }
    }

    public static function acrlQuestionAssignmentsAppearComplete($db): bool
    {
        try {
            $table = $db->createCommand(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                [':table' => 'acrl_question_assignments']
            )->queryScalar();
            if ((int)$table !== 1) {
                return false;
            }
            $columns = $db->createCommand(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH'
                    . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                [':table' => 'acrl_question_assignments']
            )->queryAll();
            if (count($columns) !== 7) {
                return false;
            }
            $actual = [];
            foreach ($columns as $column) {
                $actual[strtolower((string)$column['COLUMN_NAME'])] = $column;
            }
            $expected = [
                'survey_year' => ['smallint', 'NO', null],
                'sheet' => ['varchar', 'NO', 8],
                'question_id' => ['varchar', 'NO', 32],
                'part' => ['varchar', 'NO', 1],
                'user_id' => ['int', 'NO', null],
                'assigned_by' => ['int', 'YES', null],
                'assigned_at' => ['datetime', 'NO', null],
            ];
            foreach ($expected as $name => $shape) {
                if (!isset($actual[$name])) {
                    return false;
                }
                $column = $actual[$name];
                $columnType = strtolower((string)$column['COLUMN_TYPE']);
                $typeMatches = strtolower((string)$column['DATA_TYPE']) === $shape[0];
                if (in_array($shape[0], ['smallint', 'int'], true)) {
                    $typeMatches = $typeMatches && preg_match('/^' . $shape[0] . '(?:\([0-9]+\))?$/', $columnType) === 1;
                }
                if ($shape[0] === 'datetime') {
                    $typeMatches = $typeMatches && $columnType === 'datetime(6)';
                }
                if (!$typeMatches || strtoupper((string)$column['IS_NULLABLE']) !== $shape[1]
                    || ($shape[2] !== null && (int)$column['CHARACTER_MAXIMUM_LENGTH'] !== $shape[2])) {
                    return false;
                }
            }
            foreach (['sheet', 'question_id', 'part'] as $name) {
                if (strtolower((string)$actual[$name]['CHARACTER_SET_NAME']) !== 'ascii'
                    || strtolower((string)$actual[$name]['COLLATION_NAME']) !== 'ascii_bin') {
                    return false;
                }
            }
            if (!in_array((string)$actual['part']['COLUMN_DEFAULT'], ['', "''"], true)) {
                return false;
            }
            $rows = $db->createCommand(
                'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index ORDER BY SEQ_IN_INDEX',
                [':table' => 'acrl_question_assignments', ':index' => 'uq_acrl_question_assignment']
            )->queryAll();
            $uniqueColumns = ['survey_year', 'sheet', 'question_id', 'part', 'user_id'];
            if (count($rows) !== count($uniqueColumns)) {
                return false;
            }
            foreach ($uniqueColumns as $index => $name) {
                if ((int)$rows[$index]['NON_UNIQUE'] !== 0
                    || (int)$rows[$index]['SEQ_IN_INDEX'] !== $index + 1
                    || strtolower((string)$rows[$index]['COLUMN_NAME']) !== $name
                    || $rows[$index]['SUB_PART'] !== null) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public static function baselineCurrentSchema($db, array $files, bool $dryRun): array
    {
        $result = ['applied' => [], 'skipped' => [], 'baselined' => [], 'dryRun' => $dryRun];
        foreach ($files as $file) {
            if ($file['filename'] === '066_acrl_question_assignments.sql'
                && !self::acrlQuestionAssignmentsAppearComplete($db)) {
                if (!$dryRun) {
                    $started = microtime(true);
                    MigrationService::executeMigrationFile($db, $file);
                    $db->createCommand()->insert('schema_migrations', [
                        'filename' => $file['filename'],
                        'checksum' => $file['checksum'],
                        'execution_ms' => (int)round((microtime(true) - $started) * 1000),
                    ])->execute();
                }
                $result['applied'][] = $file['filename'];
                continue;
            }
            if (!$dryRun) {
                $db->createCommand()->insert('schema_migrations', [
                    'filename' => $file['filename'],
                    'checksum' => $file['checksum'],
                    'execution_ms' => 0,
                ])->execute();
            }
            $result['baselined'][] = $file['filename'];
        }
        return $result;
    }
}
