<?php

namespace app\services;

require_once __DIR__ . '/CatalogingHoldingslessReportService.php';
require_once __DIR__ . '/AcquisitionsOpenOrdersReportService.php';
require_once __DIR__ . '/AcquisitionsInvoiceStatusReportService.php';
require_once __DIR__ . '/AcquisitionsMissingUnitReportMigrationService.php';

/** Keeps governed report migration checks outside the legacy migration service. */
final class CatalogingReportMigrationRecognitionService
{
    public static function holdingslessReportAppearsComplete($db): bool
    {
        return self::canonicalReportAppearsComplete($db, CatalogingHoldingslessReportService::class);
    }

    public static function acquisitionsOpenOrdersReportAppearsComplete($db): bool
    {
        return self::canonicalReportAppearsComplete($db, AcquisitionsOpenOrdersReportService::class);
    }

    public static function acquisitionsInvoiceStatusReportAppearsComplete($db): bool
    {
        return self::canonicalReportAppearsComplete($db, AcquisitionsInvoiceStatusReportService::class);
    }

    public static function ordersMissingAcqUnitReportAppearsComplete($db): bool
    {
        return AcquisitionsMissingUnitReportMigrationService::ordersMissingAcqUnitReportAppearsComplete($db);
    }

    private static function canonicalReportAppearsComplete($db, string $serviceClass): bool
    {
        $definition = $db->createCommand(
            'SELECT slug, name, category, sql_template, parameters, data_source, execution_config, default_limit, is_active, created_by'
                . ' FROM report_templates WHERE slug = :slug LIMIT 1',
            [':slug' => $serviceClass::REPORT_SLUG]
        )->queryOne();
        return is_array($definition) && $serviceClass::isCanonicalSeedDefinition($definition);
    }
}
