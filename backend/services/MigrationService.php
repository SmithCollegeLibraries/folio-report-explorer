<?php

namespace app\services;

class MigrationService
{
    const DEFAULT_MIGRATION_DIR = __DIR__ . '/../../mysql/migrations';

    public static function schemaMigrationsTableSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS schema_migrations (\n"
            . "    id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "    filename VARCHAR(255) NOT NULL UNIQUE,\n"
            . "    checksum CHAR(64) NOT NULL,\n"
            . "    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "    execution_ms INT NULL,\n"
            . "    INDEX idx_schema_migrations_applied_at (applied_at)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function discoverMigrationFiles(?string $directory = null): array
    {
        $directory = $directory ?: self::DEFAULT_MIGRATION_DIR;
        if (!is_dir($directory)) {
            throw new \RuntimeException("Migration directory does not exist: {$directory}");
        }

        $paths = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($paths, SORT_STRING);
        if (empty($paths)) {
            throw new \RuntimeException("Migration directory contains no SQL files: {$directory}");
        }

        $files = [];
        foreach ($paths as $path) {
            $filename = basename($path);
            $number = self::migrationNumber($filename);
            $sql = (string)file_get_contents($path);
            $files[] = [
                'path' => $path,
                'filename' => $filename,
                'number' => $number,
                'checksum' => hash('sha256', $sql),
                'bytes' => strlen($sql),
            ];
        }

        return $files;
    }

    public static function auditDirectory(?string $directory = null, array $appliedRows = []): array
    {
        $files = self::discoverMigrationFiles($directory);
        $duplicates = [];
        $byNumber = [];
        foreach ($files as $file) {
            if ($file['number'] === '') {
                continue;
            }
            $byNumber[$file['number']][] = $file['filename'];
        }
        foreach ($byNumber as $number => $filenames) {
            if (count($filenames) > 1) {
                sort($filenames, SORT_STRING);
                $duplicates[$number] = $filenames;
            }
        }

        $appliedByFilename = [];
        foreach ($appliedRows as $row) {
            $appliedByFilename[(string)$row['filename']] = (string)$row['checksum'];
        }

        $changedChecksums = [];
        $unapplied = [];
        $nonIdempotentRisks = [];
        foreach ($files as $file) {
            if (isset($appliedByFilename[$file['filename']])) {
                if ($appliedByFilename[$file['filename']] !== $file['checksum']) {
                    $changedChecksums[] = [
                        'filename' => $file['filename'],
                        'expected' => $appliedByFilename[$file['filename']],
                        'actual' => $file['checksum'],
                    ];
                }
            } else {
                $unapplied[] = $file['filename'];
            }

            $riskReasons = self::nonIdempotentRiskReasons((string)file_get_contents($file['path']));
            if (!empty($riskReasons)) {
                $nonIdempotentRisks[] = [
                    'filename' => $file['filename'],
                    'reasons' => $riskReasons,
                ];
            }
        }

        return [
            'files' => $files,
            'duplicateNumbers' => $duplicates,
            'changedChecksums' => $changedChecksums,
            'unapplied' => $unapplied,
            'nonIdempotentRisks' => $nonIdempotentRisks,
        ];
    }

    public static function run($db, ?string $directory = null, bool $dryRun = false): array
    {
        self::ensureSchemaTable($db);
        $appliedRows = self::loadAppliedRows($db);
        $audit = self::auditDirectory($directory, $appliedRows);

        if (!empty($audit['duplicateNumbers'])) {
            throw new \RuntimeException('Duplicate migration numbers found: ' . json_encode($audit['duplicateNumbers']));
        }
        if (!empty($audit['changedChecksums'])) {
            throw new \RuntimeException('Applied migration checksums changed: ' . json_encode($audit['changedChecksums']));
        }

        $files = $audit['files'];
        if (empty($appliedRows) && self::databaseAppearsCurrent($db)) {
            if ($dryRun) {
                return ['applied' => [], 'skipped' => [], 'baselined' => array_column($files, 'filename'), 'dryRun' => true];
            }
            foreach ($files as $file) {
                self::recordApplied($db, $file, 0);
            }
            return ['applied' => [], 'skipped' => [], 'baselined' => array_column($files, 'filename'), 'dryRun' => false];
        }

        $appliedByFilename = [];
        foreach ($appliedRows as $row) {
            $appliedByFilename[(string)$row['filename']] = true;
        }

        $result = ['applied' => [], 'skipped' => [], 'baselined' => [], 'dryRun' => $dryRun];
        foreach ($files as $file) {
            if (isset($appliedByFilename[$file['filename']])) {
                $result['skipped'][] = $file['filename'];
                continue;
            }

            if (self::migrationAppearsApplied($db, $file['filename'])) {
                if (!$dryRun) {
                    self::recordApplied($db, $file, 0);
                }
                $result['baselined'][] = $file['filename'];
                continue;
            }

            if ($dryRun) {
                $result['applied'][] = $file['filename'];
                continue;
            }

            $started = microtime(true);
            self::executeSqlFile($db, $file['path']);
            self::recordApplied($db, $file, (int)round((microtime(true) - $started) * 1000));
            $result['applied'][] = $file['filename'];
        }

        return $result;
    }

    public static function ensureSchemaTable($db): void
    {
        $db->createCommand(self::schemaMigrationsTableSql())->execute();
    }

    public static function loadAppliedRows($db): array
    {
        if ($db->schema->getTableSchema('schema_migrations', true) === null) {
            return [];
        }

        return $db->createCommand('SELECT filename, checksum FROM schema_migrations ORDER BY filename')->queryAll();
    }

    public static function splitSqlStatements(string $sql): array
    {
        $delimiter = ';';
        $buffer = '';
        $statements = [];
        $lines = preg_split('/\R/', $sql);

        foreach ($lines as $line) {
            if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $line, $matches) === 1) {
                $delimiter = $matches[1];
                continue;
            }

            $buffer .= $line . "\n";
            if ($delimiter !== '' && substr(rtrim($buffer), -strlen($delimiter)) === $delimiter) {
                $statement = substr(rtrim($buffer), 0, -strlen($delimiter));
                $statement = trim($statement);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    private static function executeSqlFile($db, string $path): void
    {
        $sql = (string)file_get_contents($path);
        foreach (self::splitSqlStatements($sql) as $statement) {
            $db->createCommand($statement)->execute();
        }
    }

    private static function recordApplied($db, array $file, int $executionMs): void
    {
        $db->createCommand()->insert('schema_migrations', [
            'filename' => $file['filename'],
            'checksum' => $file['checksum'],
            'execution_ms' => $executionMs,
        ])->execute();
    }

    private static function databaseAppearsCurrent($db): bool
    {
        foreach (['users', 'query_jobs', 'report_templates', 'ai_clarification_events', 'ai_query_feedback', 'folio_reference_tables'] as $table) {
            if ($db->schema->getTableSchema($table, true) === null) {
                return false;
            }
        }

        return self::hasColumn($db, 'report_templates', 'help_text')
            && self::rowExists(
                $db,
                'report_templates',
                'id = 37 AND slug = :slug',
                [':slug' => 'budget-year-fund-report']
            );
    }

    private static function migrationAppearsApplied($db, string $filename): bool
    {
        switch ($filename) {
            case '002_add_nl_fields.sql':
                return self::hasColumns($db, 'saved_queries', ['source', 'nl_prompt', 'is_pinned']);
            case '003_add_users_and_auth.sql':
                return self::hasTable($db, 'users')
                    && self::hasColumns($db, 'query_jobs', ['user_id', 'sql_hash'])
                    && self::hasColumn($db, 'saved_queries', 'user_id')
                    && self::hasColumn($db, 'query_log', 'user_id')
                    && self::hasColumn($db, 'ai_training_hints', 'user_id');
            case '004_add_user_email_and_notifications.sql':
                return self::hasColumns($db, 'users', ['email', 'receive_notifications']);
            case '005_add_job_name.sql':
                return self::hasColumn($db, 'query_jobs', 'name');
            case '006_per_user_dashboard.sql':
                return self::hasColumn($db, 'saved_queries', 'is_global') && self::hasTable($db, 'user_dashboard_prefs');
            case '007_seed_dev_user.sql':
                return self::hasTable($db, 'users') && self::rowExists($db, 'users', 'smith_id = :smith_id', [':smith_id' => 'dev']);
            case '008_dashboard_display.sql':
                return self::hasColumn($db, 'saved_queries', 'last_job_id') && self::hasColumns($db, 'user_dashboard_prefs', ['display_type', 'chart_config']);
            case '009_user_default_campus.sql':
                return self::hasColumn($db, 'users', 'default_campus');
            case '010_local_supplementary_data.sql':
                return self::hasTable($db, 'acrl_statistics')
                    && self::hasTable($db, 'report_expense_allocations')
                    && self::hasColumn($db, 'query_log', 'data_source')
                    && self::hasColumn($db, 'query_jobs', 'data_source');
            case '011_seed_acrl_data.sql':
                return self::hasTable($db, 'acrl_statistics') && self::tableRowCount($db, 'acrl_statistics') > 0;
            case '012_seed_allocation_data.sql':
                return self::hasTable($db, 'report_expense_allocations') && self::tableRowCount($db, 'report_expense_allocations') > 0;
            case '013_report_template_datasource.sql':
                return self::hasColumns($db, 'report_templates', ['data_source', 'composite_config']) && self::hasColumn($db, 'query_jobs', 'metadata');
            case '014_seed_composite_reports.sql':
                return self::rowExists($db, 'report_templates', 'id IN (34, 35)');
            case '015_add_pg_backend_pid.sql':
                return self::hasColumn($db, 'query_jobs', 'pg_backend_pid');
            case '016_seed_budget_expense_report.sql':
                return self::rowExists($db, 'report_templates', 'id = 36');
            case '017_add_export_support.sql':
                return self::hasColumns($db, 'query_jobs', ['output_mode', 'export_file_path', 'estimated_rows', 'estimated_cost']);
            case '018_user_expense_monitors.sql':
                return self::hasTable($db, 'user_expense_monitors');
            case '019_budget_report_acq_unit_param.sql':
                return self::rowExists($db, 'report_templates', 'id = 36');
            case '020_fix_report34_acq_unit_options_db.sql':
                return self::rowExists($db, 'report_templates', 'id = 34');
            case '021_round_budget_report_amounts.sql':
                return self::rowExists($db, 'report_templates', 'id = 36');
            case '022_dashboard_widgets.sql':
                return self::hasTable($db, 'dashboard_widget_templates') && self::hasTable($db, 'user_dashboard_widgets');
            case '023_saved_queries_source_report.sql':
                return self::columnTypeContains($db, 'saved_queries', 'source', "'report'");
            case '024_catchup_skipped_migrations.sql':
                return self::hasColumns($db, 'report_templates', ['data_source', 'composite_config'])
                    && self::hasColumn($db, 'query_jobs', 'metadata')
                    && self::columnTypeContains($db, 'saved_queries', 'source', "'report'");
            case '025_widen_estimated_cost.sql':
                return self::columnTypeContains($db, 'query_jobs', 'estimated_cost', 'double');
            case '026_fix_title_list_payment_date_cast.sql':
            case '027_restore_title_list_invoice_date.sql':
            case '028_title_list_invoice_date_date_only.sql':
                return self::rowExists($db, 'report_templates', 'id = 3 OR slug = :slug', [':slug' => 'title-list-report']);
            case '029_same_title_holdings_overlap_hint.sql':
                return self::rowExists($db, 'ai_training_hints', 'hint_key = :hint_key', [':hint_key' => 'same-title holdings overlap']);
            case '030_collection_location_reference_hint.sql':
                return self::rowExists($db, 'ai_training_hints', 'hint_key = :hint_key', [':hint_key' => 'collection location scope']);
            case '031_query_job_name_text.sql':
                return self::columnTypeContains($db, 'query_jobs', 'name', 'text');
            case '032_ai_clarification_events.sql':
                return self::hasTable($db, 'ai_clarification_events');
            case '033_ai_query_feedback.sql':
                return self::hasTable($db, 'ai_query_feedback');
            case '034_folio_reference_cache.sql':
                return self::hasTable($db, 'folio_reference_tables') && self::hasTable($db, 'folio_reference_values');
            case '035_budget_year_fund_report.sql':
                return self::hasColumn($db, 'report_templates', 'help_text')
                    && self::rowExists(
                        $db,
                        'report_templates',
                        'id = 37 AND slug = :slug',
                        [':slug' => 'budget-year-fund-report']
                    );
        }

        return false;
    }

    private static function hasTable($db, string $table): bool
    {
        return $db->schema->getTableSchema($table, true) !== null;
    }

    private static function hasColumn($db, string $table, string $column): bool
    {
        $schema = $db->schema->getTableSchema($table, true);
        return $schema !== null && isset($schema->columns[$column]);
    }

    private static function hasColumns($db, string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!self::hasColumn($db, $table, (string)$column)) {
                return false;
            }
        }

        return true;
    }

    private static function rowExists($db, string $table, string $where, array $params = []): bool
    {
        if (!self::hasTable($db, $table)) {
            return false;
        }

        return (int)$db->createCommand("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params)->queryScalar() > 0;
    }

    private static function tableRowCount($db, string $table): int
    {
        if (!self::hasTable($db, $table)) {
            return 0;
        }

        return (int)$db->createCommand("SELECT COUNT(*) FROM {$table}")->queryScalar();
    }

    private static function columnTypeContains($db, string $table, string $column, string $needle): bool
    {
        $row = $db->createCommand(
            'SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1',
            [':table' => $table, ':column' => $column]
        )->queryOne();

        return is_array($row) && stripos((string)($row['COLUMN_TYPE'] ?? ''), $needle) !== false;
    }

    private static function migrationNumber(string $filename): string
    {
        return preg_match('/^(\d+)_/', $filename, $matches) === 1 ? $matches[1] : '';
    }

    private static function nonIdempotentRiskReasons(string $sql): array
    {
        $risks = [];
        if (preg_match('/\bCREATE\s+TABLE\b/i', $sql) === 1 && preg_match('/\bCREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $sql) !== 1) {
            $risks[] = 'CREATE TABLE without IF NOT EXISTS';
        }
        if (preg_match('/\bALTER\s+TABLE\b/i', $sql) === 1 && preg_match('/IF\s+NOT\s+EXISTS|information_schema/i', $sql) !== 1) {
            $risks[] = 'ALTER TABLE without IF NOT EXISTS or information_schema guard';
        }

        return $risks;
    }
}
