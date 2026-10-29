<?php

namespace app\services;

/** Recognizes the retained ACRL data before and after migration 061. */
final class AcrlRetirementMigrationRecognitionService
{
    public static function supplementaryDataAppearsComplete($db): bool
    {
        return (self::hasTable($db, 'acrl_statistics') || self::hasTable($db, 'acrl_statistics_retired'))
            && self::hasTable($db, 'report_expense_allocations')
            && self::hasColumn($db, 'query_log', 'data_source')
            && self::hasColumn($db, 'query_jobs', 'data_source');
    }

    public static function acrlSeedAppearsComplete($db): bool
    {
        if (self::hasTable($db, 'acrl_statistics_retired')) {
            return true;
        }
        return self::hasTable($db, 'acrl_statistics')
            && self::tableRowCount($db, 'acrl_statistics') > 0;
    }

    public static function retirementAppearsComplete($db): bool
    {
        if (!self::hasTable($db, 'acrl_statistics_retired') || self::hasTable($db, 'acrl_statistics')) {
            return false;
        }
        if (!self::hasTable($db, 'report_templates')) {
            return false;
        }

        $report = $db->createCommand(
            'SELECT is_active FROM report_templates WHERE slug = :slug LIMIT 1',
            [':slug' => 'acrl-statistics']
        )->queryOne();
        return !is_array($report)
            || !array_key_exists('is_active', $report)
            || (int)$report['is_active'] === 0;
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

    private static function tableRowCount($db, string $table): int
    {
        if (!self::hasTable($db, $table)) {
            return 0;
        }
        return (int)$db->createCommand("SELECT COUNT(*) FROM {$table}")->queryScalar();
    }
}
