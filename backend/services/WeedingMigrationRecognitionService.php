<?php

namespace app\services;

final class WeedingMigrationRecognitionService
{
    private const MIGRATION = '075_weeding.sql';

    private const TABLES = [
        'weeding_areas' => [
            'columns' => [
                'id' => ['int unsigned', 'NO', null, 'auto_increment'],
                'name' => ['varchar(200)', 'NO', null, ''],
                'lc_from' => ['varchar(20)', 'YES', null, ''],
                'lc_to' => ['varchar(20)', 'YES', null, ''],
                'location_ids' => ['text', 'YES', null, ''],
                'status' => ["enum('snapshotting','looking_up','ready','paused','failed')", 'NO', 'snapshotting', ''],
                'title_count' => ['int', 'NO', '0', ''],
                'item_count' => ['int', 'NO', '0', ''],
                'non_lc_excluded' => ['int', 'NO', '0', ''],
                'no_oclc_count' => ['int', 'NO', '0', ''],
                'created_by' => ['int', 'YES', null, ''],
                'created_at' => ['datetime', 'NO', null, ''],
                'snapshot_at' => ['datetime', 'YES', null, ''],
                'error' => ['text', 'YES', null, ''],
            ],
            'indexes' => ['PRIMARY' => [['id'], true]],
            'foreign_keys' => ['fk_weeding_areas_created_by' => [['created_by'], 'users', ['id'], 'SET NULL']],
        ],
        'weeding_area_titles' => [
            'columns' => [
                'area_id' => ['int unsigned', 'NO', null, ''], 'instance_id' => ['char(36)', 'NO', null, ''],
                'instance_hrid' => ['varchar(50)', 'NO', null, ''], 'oclc_number' => ['bigint unsigned', 'YES', null, ''],
                'title' => ['varchar(500)', 'NO', null, ''], 'author' => ['varchar(255)', 'YES', null, ''],
                'pub_year' => ['smallint', 'YES', null, ''], 'smith_items' => ['smallint', 'NO', null, ''],
                'area_items' => ['smallint', 'NO', null, ''], 'total_checkouts' => ['int', 'NO', null, ''],
                'last_folio_checkout' => ['date', 'YES', null, ''],
            ],
            'indexes' => ['PRIMARY' => [['area_id', 'instance_id'], true], 'idx_weeding_area_titles_oclc' => [['area_id', 'oclc_number'], false]],
            'foreign_keys' => ['fk_weeding_area_titles_area' => [['area_id'], 'weeding_areas', ['id'], 'CASCADE']],
        ],
        'weeding_area_items' => [
            'columns' => [
                'area_id' => ['int unsigned', 'NO', null, ''], 'instance_id' => ['char(36)', 'NO', null, ''],
                'item_id' => ['char(36)', 'NO', null, ''], 'item_hrid' => ['varchar(50)', 'NO', null, ''],
                'barcode' => ['varchar(50)', 'YES', null, ''], 'location_code' => ['varchar(50)', 'NO', null, ''],
                'call_number' => ['varchar(150)', 'YES', null, ''], 'call_number_sort' => ['varchar(160)', 'YES', null, ''],
                'volume' => ['varchar(100)', 'YES', null, ''], 'status' => ['varchar(50)', 'NO', null, ''],
            ],
            'indexes' => ['PRIMARY' => [['area_id', 'item_id'], true], 'idx_weeding_area_items_instance' => [['area_id', 'instance_id'], false]],
            'foreign_keys' => ['fk_weeding_area_items_title' => [['area_id', 'instance_id'], 'weeding_area_titles', ['area_id', 'instance_id'], 'CASCADE']],
        ],
        'weeding_area_title_checkouts' => [
            'columns' => [
                'area_id' => ['int unsigned', 'NO', null, ''], 'instance_id' => ['char(36)', 'NO', null, ''],
                'year' => ['smallint', 'NO', null, ''], 'checkouts' => ['int', 'NO', null, ''],
            ],
            'indexes' => ['PRIMARY' => [['area_id', 'instance_id', 'year'], true]],
            'foreign_keys' => ['fk_weeding_area_title_checkouts_title' => [['area_id', 'instance_id'], 'weeding_area_titles', ['area_id', 'instance_id'], 'CASCADE']],
        ],
        'weeding_worldcat_cache' => [
            'columns' => [
                'oclc_number' => ['bigint unsigned', 'NO', null, ''], 'resolved_oclc' => ['bigint unsigned', 'YES', null, ''],
                'status' => ["enum('ok','not_found','error')", 'NO', null, ''], 'total_count' => ['int', 'YES', null, ''],
                'shared_print_count' => ['smallint', 'YES', null, ''], 'five_college_count' => ['smallint', 'YES', null, ''],
                'ma_count' => ['smallint', 'YES', null, ''], 'east_count' => ['smallint', 'YES', null, ''],
                'smith_retains' => ['tinyint(1)', 'YES', null, ''], 'retainers' => ['text', 'YES', null, ''],
                'fetched_at' => ['datetime', 'NO', null, ''], 'error' => ['text', 'YES', null, ''],
            ],
            'indexes' => ['PRIMARY' => [['oclc_number'], true], 'idx_weeding_worldcat_cache_fetched_at' => [['fetched_at'], false]],
            'foreign_keys' => [],
        ],
        'weeding_oclc_calls' => [
            'columns' => ['day' => ['date', 'NO', null, ''], 'calls' => ['int', 'NO', '0', '']],
            'indexes' => ['PRIMARY' => [['day'], true]], 'foreign_keys' => [],
        ],
        'weeding_oclc_block' => [
            'columns' => [
                'id' => ['tinyint', 'NO', null, ''], 'blocked_until' => ['datetime', 'YES', null, ''],
                'reason' => ["enum('daily_cap','monthly_cap','provider_low','rate_limited')", 'YES', null, ''],
                'updated_at' => ['datetime', 'NO', null, ''],
            ],
            'indexes' => ['PRIMARY' => [['id'], true]], 'foreign_keys' => [],
        ],
        'weeding_scenarios' => [
            'columns' => [
                'id' => ['int unsigned', 'NO', null, 'auto_increment'], 'area_id' => ['int unsigned', 'NO', null, ''],
                'name' => ['varchar(100)', 'NO', null, ''], 'criteria' => ['text', 'NO', null, ''],
                'created_by' => ['int', 'YES', null, ''], 'created_at' => ['datetime', 'NO', null, ''],
                'updated_at' => ['datetime', 'NO', null, ''],
            ],
            'indexes' => [
                'PRIMARY' => [['id'], true], 'uq_weeding_scenarios_area_name' => [['area_id', 'name'], true],
            ],
            'foreign_keys' => [
                'fk_weeding_scenarios_area' => [['area_id'], 'weeding_areas', ['id'], 'CASCADE'],
                'fk_weeding_scenarios_created_by' => [['created_by'], 'users', ['id'], 'SET NULL'],
            ],
        ],
    ];

    public static function schemaAppearsComplete($db): bool
    {
        try {
            if (!self::columnsAppearComplete($db, 'users', [
                'weeding_access' => ['tinyint(1)', 'NO', '0', ''],
            ])) {
                return false;
            }
            foreach (self::TABLES as $table => $shape) {
                if (!self::tableAppearsComplete($db, $table, $shape)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public static function assertSchemaAppearsComplete($db): void
    {
        if (!self::schemaAppearsComplete($db)) {
            throw new \RuntimeException('Migration 075 left the Weeding schema incomplete.');
        }
    }

    public static function baselineCurrentSchema($db, array $files, bool $dryRun): array
    {
        $baselineFiles = [];
        $weedingFile = null;
        foreach ($files as $file) {
            if ($file['filename'] === self::MIGRATION) {
                $weedingFile = $file;
            } else {
                $baselineFiles[] = $file;
            }
        }
        $result = AcrlQuestionAssignmentMigrationRecognitionService::baselineCurrentSchema($db, $baselineFiles, $dryRun);
        if ($weedingFile === null) {
            return $result;
        }

        if (!self::schemaAppearsComplete($db)) {
            if (!$dryRun) {
                $started = microtime(true);
                MigrationService::executeMigrationFile($db, $weedingFile);
                $db->createCommand()->insert('schema_migrations', [
                    'filename' => $weedingFile['filename'], 'checksum' => $weedingFile['checksum'],
                    'execution_ms' => (int)round((microtime(true) - $started) * 1000),
                ])->execute();
            }
            $result['applied'][] = self::MIGRATION;
        } else {
            if (!$dryRun) {
                $db->createCommand()->insert('schema_migrations', [
                    'filename' => $weedingFile['filename'], 'checksum' => $weedingFile['checksum'], 'execution_ms' => 0,
                ])->execute();
            }
            $result['baselined'][] = self::MIGRATION;
        }
        return $result;
    }

    private static function tableAppearsComplete($db, string $table, array $shape): bool
    {
        $tableRow = $db->createCommand(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            [':table' => $table]
        )->queryOne();
        return is_array($tableRow) && strtolower((string)$tableRow['ENGINE']) === 'innodb'
            && stripos((string)$tableRow['TABLE_COLLATION'], 'utf8mb4_') === 0
            && self::columnsAppearComplete($db, $table, $shape['columns'])
            && self::indexesAppearComplete($db, $table, $shape['indexes'])
            && self::foreignKeysAppearComplete($db, $table, $shape['foreign_keys']);
    }

    private static function columnsAppearComplete($db, string $table, array $expected): bool
    {
        $rows = $db->createCommand(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            [':table' => $table]
        )->queryAll();
        $columns = [];
        foreach ($rows as $row) {
            $columns[strtolower((string)$row['COLUMN_NAME'])] = $row;
        }
        foreach ($expected as $name => $shape) {
            if (!isset($columns[$name])) {
                return false;
            }
            $column = $columns[$name];
            $actualDefault = $column['COLUMN_DEFAULT'];
            if ($shape[2] === null && is_string($actualDefault) && strtoupper(trim($actualDefault)) === 'NULL') {
                $actualDefault = null;
            }
            $defaultMatches = $shape[2] === null
                ? $actualDefault === null
                : trim((string)$actualDefault, "'") === (string)$shape[2];
            if (self::normalizeColumnType((string)$column['COLUMN_TYPE']) !== $shape[0]
                || strtoupper((string)$column['IS_NULLABLE']) !== $shape[1]
                || !$defaultMatches
                || strtolower((string)$column['EXTRA']) !== $shape[3]) {
                return false;
            }
        }
        return true;
    }

    private static function normalizeColumnType(string $type): string
    {
        $type = strtolower($type);
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\((\d+)\)(.*)$/', $type, $matches) === 1
            && !($matches[1] === 'tinyint' && $matches[2] === '1')) {
            return $matches[1] . $matches[3];
        }
        return $type;
    }

    private static function indexesAppearComplete($db, string $table, array $expected): bool
    {
        foreach ($expected as $name => $shape) {
            $rows = $db->createCommand(
                'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS'
                    . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index ORDER BY SEQ_IN_INDEX',
                [':table' => $table, ':index' => $name]
            )->queryAll();
            if (count($rows) !== count($shape[0])) {
                return false;
            }
            foreach ($shape[0] as $position => $column) {
                if ((int)$rows[$position]['NON_UNIQUE'] !== ($shape[1] ? 0 : 1)
                    || (int)$rows[$position]['SEQ_IN_INDEX'] !== $position + 1
                    || strtolower((string)$rows[$position]['COLUMN_NAME']) !== $column
                    || $rows[$position]['SUB_PART'] !== null) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function foreignKeysAppearComplete($db, string $table, array $expected): bool
    {
        $rows = $db->createCommand(
            'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME,'
                . ' k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k'
                . ' INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA'
                . ' AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME'
                . ' WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = :table'
                . ' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            [':table' => $table]
        )->queryAll();
        $actual = [];
        foreach ($rows as $row) {
            $actual[$row['CONSTRAINT_NAME']][] = [
                strtolower((string)$row['COLUMN_NAME']), strtolower((string)$row['REFERENCED_TABLE_SCHEMA']),
                strtolower((string)$row['REFERENCED_TABLE_NAME']), strtolower((string)$row['REFERENCED_COLUMN_NAME']),
                strtoupper((string)$row['DELETE_RULE']),
            ];
        }
        if (count($actual) !== count($expected)) {
            return false;
        }
        $database = strtolower((string)$db->createCommand('SELECT DATABASE()')->queryScalar());
        foreach ($expected as $name => $shape) {
            if (!isset($actual[$name]) || count($actual[$name]) !== count($shape[0])) {
                return false;
            }
            foreach ($shape[0] as $position => $column) {
                $row = $actual[$name][$position];
                if ($row !== [
                    $column, $database, strtolower($shape[1]), strtolower($shape[2][$position]), strtoupper($shape[3]),
                ]) {
                    return false;
                }
            }
        }
        return true;
    }
}
