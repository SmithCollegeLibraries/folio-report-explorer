<?php

namespace app\services;

require_once __DIR__ . '/CatalogingHoldingslessReportService.php';
require_once __DIR__ . '/AcquisitionsOpenOrdersReportService.php';
require_once __DIR__ . '/AcquisitionsMissingUnitReportService.php';
require_once __DIR__ . '/AcquisitionsInvoiceStatusReportService.php';
require_once __DIR__ . '/AcquisitionsMissingUnitReportMigrationService.php';
require_once __DIR__ . '/BudgetYearExpenseClassReportService.php';

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

    public static function budgetYearExpenseClassReportAppearsComplete($db): bool
    {
        if (!self::canonicalReportAppearsComplete($db, BudgetYearExpenseClassReportService::class)) {
            return false;
        }

        $report34 = $db->createCommand(
            'SELECT sql_template FROM report_templates WHERE id = 34 LIMIT 1'
        )->queryOne();
        if (!is_array($report34)
            || strpos((string) ($report34['sql_template'] ?? ''), 'UPPER(BTRIM(ect.code)) AS "Expense Code"') === false) {
            return false;
        }

        $codeLength = $db->createCommand(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            [':table' => 'report_expense_allocations', ':column' => 'expense_class_code']
        )->queryScalar();
        return (int) $codeLength >= 32;
    }

    public static function reportsAppearComplete($db): bool
    {
        return self::holdingslessReportAppearsComplete($db)
            && self::acquisitionsInvoiceStatusReportAppearsComplete($db)
            && self::acquisitionsFeedbackReportsAppearComplete($db)
            && self::budgetYearExpenseClassReportAppearsComplete($db);
    }

    public static function migrationDefinitionAppearsComplete($db, string $filename): bool
    {
        if ($filename === '051_budget_year_expense_class_report.sql') {
            return self::budgetYearExpenseClassReportAppearsComplete($db);
        }
        if ($filename === '053_acquisitions_feedback_report_filters.sql') {
            return self::acquisitionsFeedbackReportsAppearComplete($db);
        }
        if ($filename === '056_open_order_report_expansion.sql') {
            return self::acquisitionsOpenOrdersReportAppearsComplete($db);
        }
        return false;
    }

    public static function acquisitionsFeedbackReportsAppearComplete($db): bool
    {
        return self::acquisitionsOpenOrdersReportAppearsComplete($db)
            && self::ordersMissingAcqUnitReportAppearsComplete($db)
            && self::polReportIsInactive($db);
    }

    private static function polReportIsInactive($db): bool
    {
        $report = $db->createCommand(
            'SELECT is_active FROM report_templates WHERE id = 11 AND slug = :slug LIMIT 1',
            [':slug' => 'pol-report']
        )->queryOne();
        return is_array($report) && array_key_exists('is_active', $report) && (int) $report['is_active'] === 0;
    }

    private static function canonicalReportAppearsComplete($db, string $serviceClass): bool
    {
        $definition = $db->createCommand(
            'SELECT slug, name, description, help_text, category, sql_template, parameters, data_source, execution_config, composite_config, default_limit, is_active, created_by'
                . ' FROM report_templates WHERE slug = :slug LIMIT 1',
            [':slug' => $serviceClass::REPORT_SLUG]
        )->queryOne();
        return is_array($definition) && $serviceClass::isCanonicalSeedDefinition($definition);
    }
}
