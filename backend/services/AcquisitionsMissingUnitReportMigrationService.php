<?php

namespace app\services;

require_once __DIR__ . '/AcquisitionsMissingUnitReportService.php';

/** Recognizes the canonical RPT-004 seed through its governed report contract. */
final class AcquisitionsMissingUnitReportMigrationService
{
    public const REPORT_SLUG = 'orders-missing-acquisitions-unit';

    public static function ordersMissingAcqUnitReportAppearsComplete($db): bool
    {
        $definition = $db->createCommand(
            'SELECT slug, name, description, help_text, category, sql_template, parameters, data_source, execution_config, default_limit, is_active, created_by'
                . ' FROM report_templates WHERE slug = :slug LIMIT 1',
            [':slug' => self::REPORT_SLUG]
        )->queryOne();

        return is_array($definition) && AcquisitionsMissingUnitReportService::isCanonicalSeedDefinition($definition);
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        return AcquisitionsMissingUnitReportService::isCanonicalSeedDefinition($definition);
    }
}
