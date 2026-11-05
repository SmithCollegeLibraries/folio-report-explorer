<?php

namespace app\services;

use app\models\ReportTemplate;

/** Signature stub so tests can load the compiler before its implementation. */
final class CatalogingMarcCombinationFinderService
{
    public const REPORT_SLUG = 'marc-field-combination-finder';

    public static function supports(ReportTemplate $report): bool
    {
        return false;
    }

    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        return [];
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        return false;
    }
}
