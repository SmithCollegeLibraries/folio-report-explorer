<?php

namespace app\services;

use app\models\ReportTemplate;

require_once __DIR__ . '/CatalogingMarcMissingTagReportService.php';
require_once __DIR__ . '/CatalogingMarcFieldFinderService.php';
require_once __DIR__ . '/CatalogingHoldingslessReportService.php';
require_once __DIR__ . '/AcquisitionsOpenOrdersReportService.php';
require_once __DIR__ . '/AcquisitionsInvoiceStatusReportService.php';
require_once __DIR__ . '/BudgetYearExpenseClassReportService.php';

/**
 * Dispatches governed cataloging report templates to their reviewed compiler.
 *
 * Cataloging reports intentionally bypass ReportTemplate::bindParams(); each
 * compiler owns its structural-token and parameter contract.
 */
final class CatalogingReportCompilerService
{
    public static function supports(ReportTemplate $report): bool
    {
        return CatalogingMarcMissingTagReportService::supports($report)
            || CatalogingMarcFieldFinderService::supports($report)
            || CatalogingHoldingslessReportService::supports($report)
            || AcquisitionsOpenOrdersReportService::supports($report)
            || AcquisitionsInvoiceStatusReportService::supports($report)
            || BudgetYearExpenseClassReportService::supports($report);
    }

    /**
     * @return array{sql:string,params:array,location:array,locations:array,marcTag:string}
     */
    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        if (CatalogingMarcMissingTagReportService::supports($report)) {
            return CatalogingMarcMissingTagReportService::build($report, $inputs, $folioDb);
        }
        if (CatalogingMarcFieldFinderService::supports($report)) {
            return CatalogingMarcFieldFinderService::build($report, $inputs, $folioDb);
        }

        if (CatalogingHoldingslessReportService::supports($report)) {
            return CatalogingHoldingslessReportService::build($report, $inputs, $folioDb);
        }
        if (AcquisitionsOpenOrdersReportService::supports($report)) {
            return AcquisitionsOpenOrdersReportService::build($report, $inputs, $folioDb);
        }
        if (AcquisitionsInvoiceStatusReportService::supports($report)) {
            return AcquisitionsInvoiceStatusReportService::build($report, $inputs, $folioDb);
        }
        if (BudgetYearExpenseClassReportService::supports($report)) {
            return BudgetYearExpenseClassReportService::build($report, $inputs, $folioDb);
        }

        throw new \InvalidArgumentException('Unsupported cataloging report template.');
    }
}
