<?php

namespace app\services;

/**
 * Recognizes the canonical RPT-004 plain-template seed when migration history is absent.
 */
final class AcquisitionsMissingUnitReportMigrationService
{
    public const REPORT_SLUG = 'orders-missing-acquisitions-unit';
    private const REPORT_NAME = 'Orders Missing an Acquisitions Unit';
    private const REPORT_DESCRIPTION = 'Finds purchase orders with no non-null acquisitions unit whose order numbers begin with SC or contain digits only, and shows vendor and fund-code evidence that can help staff identify likely Smith orders.';
    private const SQL_TEMPLATE_SHA256 = 'd4ed9c99abb049375ce81893db93802343d556892c30f8abfa7a589ec6b5ba99';
    private const HELP_TEXT_SHA256 = '9223ade92d51beabaf071d038b6f4c5c7e52c53952847f17d10143f690bcabfe';

    public static function ordersMissingAcqUnitReportAppearsComplete($db): bool
    {
        $definition = $db->createCommand(
            'SELECT slug, name, description, help_text, category, sql_template, parameters, data_source, execution_config, default_limit, is_active, created_by'
                . ' FROM report_templates WHERE slug = :slug LIMIT 1',
            [':slug' => self::REPORT_SLUG]
        )->queryOne();

        return is_array($definition) && self::isCanonicalSeedDefinition($definition);
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        $parameters = json_decode((string) ($definition['parameters'] ?? ''), true);
        $executionConfig = json_decode((string) ($definition['execution_config'] ?? ''), true);

        return ($definition['slug'] ?? null) === self::REPORT_SLUG
            && ($definition['name'] ?? null) === self::REPORT_NAME
            && ($definition['description'] ?? null) === self::REPORT_DESCRIPTION
            && ($definition['category'] ?? null) === 'acquisitions'
            && ($definition['data_source'] ?? null) === 'folio'
            && ($definition['created_by'] ?? null) === 'manual'
            && (int) ($definition['default_limit'] ?? 0) === 100000
            && (int) ($definition['is_active'] ?? 0) === 1
            && is_string($definition['sql_template'] ?? null)
            && hash('sha256', $definition['sql_template']) === self::SQL_TEMPLATE_SHA256
            && is_string($definition['help_text'] ?? null)
            && hash('sha256', $definition['help_text']) === self::HELP_TEXT_SHA256
            && $parameters === []
            && $executionConfig === [
                'public_row_cap' => 100000,
                'fetch_row_limit' => 100001,
                'preserve_export_order' => true,
                'identifier_export' => ['source_column' => 'PO UUID', 'header' => 'UUID'],
            ];
    }
}
