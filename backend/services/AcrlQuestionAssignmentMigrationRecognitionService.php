<?php

namespace app\services;

/** Recognizes the ACRL 066 and 067 schemas used by current-schema baselining. */
class AcrlQuestionAssignmentMigrationRecognitionService
{
    private static function migrationDefinitions(): array
    {
        return [
            '066_acrl_question_assignments.sql' => [
                'check' => 'acrlQuestionAssignmentsAppearComplete',
                'error' => 'Migration 066 left acrl_question_assignments without its required schema.',
            ],
            '067_acrl_invites.sql' => [
                'check' => 'acrlInvitesAppearComplete',
                'error' => 'Migration 067 left acrl_invites or acrl_invite_targets without its required schema.',
            ],
        ];
    }

    public static function migrationSchemaAppearsComplete($db, string $filename): bool
    {
        $definitions = self::migrationDefinitions();
        if (!isset($definitions[$filename])) {
            return false;
        }
        $method = $definitions[$filename]['check'];
        return self::$method($db);
    }

    public static function assertMigrationSchemaAppearsComplete($db, string $filename): void
    {
        $definitions = self::migrationDefinitions();
        if (isset($definitions[$filename]) && !self::migrationSchemaAppearsComplete($db, $filename)) {
            throw new \RuntimeException($definitions[$filename]['error']);
        }
    }

    public static function assertMigrationSchemaAfterExecution($db, string $filename): void
    {
        self::assertMigrationSchemaAppearsComplete($db, $filename);
    }

    public static function assertAcrlQuestionAssignmentsAppearComplete($db): void
    {
        self::assertMigrationSchemaAppearsComplete($db, '066_acrl_question_assignments.sql');
    }

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
        return self::tableShapeAppearsComplete($db, 'acrl_question_assignments', $expected)
            && self::indexAppearsComplete($db, 'acrl_question_assignments', 'uq_acrl_question_assignment',
                ['survey_year', 'sheet', 'question_id', 'part', 'user_id'], true);
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

        return self::tableShapeAppearsComplete($db, 'acrl_invites', $invites)
            && self::tableShapeAppearsComplete($db, 'acrl_invite_targets', $targets)
            && self::indexAppearsComplete($db, 'acrl_invites', 'PRIMARY', ['id'], true)
            && self::indexAppearsComplete($db, 'acrl_invites', 'idx_acrl_invites_email_status', ['email', 'status'], false)
            && self::indexAppearsComplete($db, 'acrl_invites', 'idx_acrl_invites_survey_year', ['survey_year'], false)
            && self::indexAppearsComplete($db, 'acrl_invite_targets', 'uq_acrl_invite_target',
                ['invite_id', 'kind', 'section', 'sheet', 'question_id', 'part'], true);
    }

    private static function tableShapeAppearsComplete($db, string $table, array $expected): bool
    {
        try {
            $tableRows = $db->createCommand(
                'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                [':table' => $table]
            )->queryAll();
            if (count($tableRows) !== 1 || strtolower((string)$tableRows[0]['ENGINE']) !== 'innodb'
                || stripos((string)$tableRows[0]['TABLE_COLLATION'], 'utf8mb4_') !== 0) {
                return false;
            }

            $columns = $db->createCommand(
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME,'
                    . ' COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, EXTRA FROM information_schema.COLUMNS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                [':table' => $table]
            )->queryAll();
            if (count($columns) !== count($expected)) {
                return false;
            }

            $actual = [];
            foreach ($columns as $column) {
                $actual[strtolower((string)$column['COLUMN_NAME'])] = $column;
            }
            foreach ($expected as $name => $shape) {
                if (!isset($actual[$name])) {
                    return false;
                }
                $column = $actual[$name];
                if (!$column || !self::columnTypeMatches($column, $shape[0])
                    || strtoupper((string)$column['IS_NULLABLE']) !== $shape[1]
                    || ($shape[2] !== null && (int)$column['CHARACTER_MAXIMUM_LENGTH'] !== $shape[2])
                    || ($shape[3] !== null && strtolower((string)$column['CHARACTER_SET_NAME']) !== $shape[3])
                    || ($shape[4] !== null && strtolower((string)$column['COLLATION_NAME']) !== $shape[4])
                    || !self::columnDefaultMatches($column['COLUMN_DEFAULT'], $shape[5])) {
                    return false;
                }
            }
            if ($table === 'acrl_invites' && stripos((string)$actual['id']['EXTRA'], 'auto_increment') === false) {
                return false;
            }
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    private static function columnTypeMatches(array $column, string $expectedType): bool
    {
        $dataType = strtolower((string)$column['DATA_TYPE']);
        $columnType = strtolower(preg_replace('/\s+/', '', (string)$column['COLUMN_TYPE']));
        if ($dataType !== $expectedType) {
            return false;
        }
        if ($expectedType === 'int' || $expectedType === 'smallint') {
            return preg_match('/^' . $expectedType . '(?:\([0-9]+\))?$/', $columnType) === 1;
        }
        if ($expectedType === 'datetime') {
            return $columnType === 'datetime(6)';
        }
        return true;
    }

    private static function columnDefaultMatches($actual, $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }
        if ($actual === null) {
            return false;
        }
        $actual = trim((string)$actual);
        if (strlen($actual) >= 2 && $actual[0] === "'" && substr($actual, -1) === "'") {
            $actual = str_replace("''", "'", substr($actual, 1, -1));
        }
        if (is_string($expected) && stripos($expected, 'CURRENT_TIMESTAMP') === 0) {
            return strtolower($actual) === strtolower($expected);
        }
        return (string)$actual === (string)$expected;
    }

    private static function indexAppearsComplete($db, string $table, string $index, array $columns, bool $unique): bool
    {
        try {
            $rows = $db->createCommand(
                'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index'
                    . ' ORDER BY SEQ_IN_INDEX',
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
        } catch (\Throwable $error) {
            return false;
        }
    }

    public static function baselineCurrentSchema($db, array $files, bool $dryRun): array
    {
        $result = ['applied' => [], 'skipped' => [], 'baselined' => [], 'dryRun' => $dryRun];
        $schemaMigrations = [
            '066_acrl_question_assignments.sql' => true,
            '067_acrl_invites.sql' => true,
        ];
        foreach ($files as $file) {
            $filename = $file['filename'];
            if (isset($schemaMigrations[$filename])) {
                if (!self::migrationSchemaAppearsComplete($db, $filename)) {
                    if (!$dryRun) {
                        $started = microtime(true);
                        MigrationService::executeMigrationFile($db, $file);
                        $db->createCommand()->insert('schema_migrations', [
                            'filename' => $filename,
                            'checksum' => $file['checksum'],
                            'execution_ms' => (int)round((microtime(true) - $started) * 1000),
                        ])->execute();
                    }
                    $result['applied'][] = $filename;
                    continue;
                }
            }
            if (!$dryRun) {
                $db->createCommand()->insert('schema_migrations', [
                    'filename' => $filename,
                    'checksum' => $file['checksum'],
                    'execution_ms' => 0,
                ])->execute();
            }
            $result['baselined'][] = $filename;
        }
        return $result;
    }
}
