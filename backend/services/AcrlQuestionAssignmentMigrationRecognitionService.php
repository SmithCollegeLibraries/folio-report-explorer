<?php

namespace app\services;

require_once __DIR__ . '/CatalogingReportMigrationRecognitionService.php';
require_once __DIR__ . '/AcrlInstitutionMigrationRecognitionService.php';
require_once __DIR__ . '/AcrlInstitutionAwareMigrationRecognitionTrait.php';

/** Recognizes migrations used by current-schema baselining. */
class AcrlQuestionAssignmentMigrationRecognitionService
{
    use AcrlInstitutionAwareMigrationRecognitionTrait;

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
            '068_marc_field_combination_finder.sql' => [
                'check' => 'marcCombinationFinderDefinitionAppearsComplete',
                'error' => 'Migration 068 did not install the canonical MARC combination finder definition.',
            ],
            '069_marc_combination_finder_record_scopes.sql' => [
                'check' => 'marcCombinationFinderRecordScopesAppearComplete',
                'error' => 'Migration 069 did not install the canonical MARC combination finder definition.',
            ],
            '070_gift_reports.sql' => [
                'check' => 'giftReportsAppearComplete',
                'error' => 'Migration 070 did not install both canonical gift reports.',
            ],
            '071_vea_gate_zones.sql' => [
                'check' => 'veaGateZonesAppearComplete',
                'error' => 'Migration 071 did not install vea_gate_zones with its required schema.',
            ],
            '072_shared_record_check.sql' => [
                'check' => 'sharedRecordReportAppearsComplete',
                'error' => 'Migration 072 did not install the canonical shared record report.',
            ],
            '073_acrl_institutions.sql' => [
                'check' => 'acrlInstitutionsAppearComplete',
                'error' => 'Migration 073 left the ACRL institution schema incomplete.',
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

    private static function acrlInstitutionsAppearComplete($db): bool
    {
        return AcrlInstitutionMigrationRecognitionService::migrationAppearsComplete($db);
    }

    private static function marcCombinationFinderDefinitionAppearsComplete($db): bool
    {
        return CatalogingReportMigrationRecognitionService::marcCombinationFinder068ReportAppearsComplete($db);
    }

    private static function marcCombinationFinderRecordScopesAppearComplete($db): bool
    {
        return CatalogingReportMigrationRecognitionService::marcCombinationFinderReportAppearsComplete($db);
    }

    private static function giftReportsAppearComplete($db): bool
    {
        return CatalogingReportMigrationRecognitionService::giftReportsAppearComplete($db);
    }

    private static function veaGateZonesAppearComplete($db): bool
    {
        return self::tableShapeAppearsComplete($db, 'vea_gate_zones', [
            'zone_id' => ['varchar', 'NO', 64, 'utf8mb4', null, null, ''],
            'sort_order' => ['int', 'NO', null, null, null, null, ''],
        ]) && self::indexAppearsComplete($db, 'vea_gate_zones', 'PRIMARY', ['zone_id'], true);
    }

    private static function sharedRecordReportAppearsComplete($db): bool
    {
        return CatalogingReportMigrationRecognitionService::sharedRecordReportAppearsComplete($db);
    }

    private static function tableShapeAppearsComplete($db, string $table, array $expected, bool $allowInstitution = false): bool
    {
        try {
            $isMariaDb = stripos((string)$db->createCommand('SELECT VERSION()')->queryScalar(), 'MariaDB') !== false;
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
            $hasInstitution = false;
            if ($allowInstitution && count($columns) === count($expected) + 1) {
                $hasInstitution = AcrlInstitutionMigrationRecognitionService::institutionColumnIsPartialOrComplete($db, $table);
                if (!$hasInstitution) {
                    return false;
                }
                $columns = array_values(array_filter($columns, function ($column) {
                    return strtolower((string)$column['COLUMN_NAME']) !== 'institution_code';
                }));
            }
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
                    || (array_key_exists(6, $shape) && (!isset($column['EXTRA']) || (string)$column['EXTRA'] !== $shape[6]))
                    || !self::columnDefaultMatches(
                        $column['COLUMN_DEFAULT'], $shape[5], $isMariaDb, strtoupper((string)$column['IS_NULLABLE']) === 'YES'
                    )) {
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

    private static function columnDefaultMatches($actual, $expected, bool $isMariaDb, bool $isNullable): bool
    {
        if ($expected === null) {
            return $isMariaDb && $isNullable ? $actual === 'NULL' : $actual === null;
        }
        if ($actual === null) {
            return false;
        }
        $actual = (string)$actual;
        if ($expected === '') {
            return $isMariaDb ? $actual === "''" : $actual === '';
        }
        if (is_string($expected) && stripos($expected, 'CURRENT_TIMESTAMP') === 0) {
            return $isMariaDb ? $actual === strtolower($expected) : $actual === $expected;
        }
        if (is_string($expected)) {
            $quotedExpected = "'" . str_replace("'", "''", $expected) . "'";
            return $isMariaDb ? $actual === $quotedExpected : $actual === $expected;
        }
        return (string)$actual === (string)$expected;
    }

    private static function foreignKeysAppearComplete($db, string $table, array $expected, bool $allowInstitution = false): bool
    {
        try {
            $rows = $db->createCommand(
                'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE'
                    . ' FROM information_schema.KEY_COLUMN_USAGE k'
                    . ' INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
                    . ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME'
                    . ' AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME'
                    . ' WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = :table'
                    . ' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME',
                [':table' => $table]
            )->queryAll();
            if (count($rows) < count($expected) || count($rows) > count($expected) + ($allowInstitution ? 1 : 0)) {
                return false;
            }
            $database = strtolower((string)$db->createCommand('SELECT DATABASE()')->queryScalar());
            $actual = [];
            foreach ($rows as $row) {
                $column = strtolower((string)$row['COLUMN_NAME']);
                if (isset($actual[$column])) {
                    return false;
                }
                $actual[$column] = [
                    strtolower((string)$row['REFERENCED_TABLE_SCHEMA']),
                    strtolower((string)$row['REFERENCED_TABLE_NAME']),
                    strtolower((string)$row['REFERENCED_COLUMN_NAME']),
                    strtoupper((string)$row['DELETE_RULE']),
                ];
            }
            foreach ($expected as $column => $shape) {
                if (!isset($actual[$column]) || $actual[$column] !== [
                    $database,
                    strtolower($shape[0]),
                    strtolower($shape[1]),
                    strtoupper($shape[2]),
                ]) {
                    return false;
                }
            }
            if (isset($actual['institution_code'])) {
                $institutionForeignKey = $actual['institution_code'];
                if (array_slice($institutionForeignKey, 0, 3) !== [$database, 'acrl_institutions', 'code']
                    || !in_array($institutionForeignKey[3], ['RESTRICT', 'NO ACTION'], true)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $error) {
            return false;
        }
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
            '068_marc_field_combination_finder.sql' => true,
            '069_marc_combination_finder_record_scopes.sql' => true,
            '070_gift_reports.sql' => true,
            '071_vea_gate_zones.sql' => true,
            '072_shared_record_check.sql' => true,
            '073_acrl_institutions.sql' => true,
        ];
        foreach ($files as $file) {
            $filename = $file['filename'];
            if (isset($schemaMigrations[$filename])) {
                if (!self::migrationSchemaAppearsComplete($db, $filename)) {
                    if (!$dryRun) {
                        $started = microtime(true);
                        MigrationService::executeMigrationFile($db, $file);
                        self::assertMigrationSchemaAfterExecution($db, $filename);
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
