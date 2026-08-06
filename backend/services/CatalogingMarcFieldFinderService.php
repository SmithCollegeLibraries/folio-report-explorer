<?php

namespace app\services;

use app\models\ReportTemplate;

/**
 * Identifies the reviewed seed contract for the flexible MARC finder.
 * Compilation of its structural tokens belongs to the subsequent task.
 */
final class CatalogingMarcFieldFinderService
{
    public const REPORT_SLUG = 'marc-field-indicator-content-finder';
    public const PUBLIC_ROW_CAP = 100000;
    public const FETCH_ROW_LIMIT = 100001;

    private const CANONICAL_REPORT_NAME = 'MARC Field, Indicator, and Content Finder';
    private const CANONICAL_TEMPLATE_SHA256 = 'b6663a679553da0ac42a65c9140734d241b67341b46ae979aa42d06b334158a8';
    private const CANONICAL_PARAMETERS_SHA256 = '2d76737032a3fe4c1e5f21f65cb8bcbfbe7bbc4565d0ceba4ffca50cd70f1759';

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        return self::hasExactSeedValue($definition, 'slug', self::REPORT_SLUG)
            && self::hasExactSeedValue($definition, 'name', self::CANONICAL_REPORT_NAME)
            && self::hasExactSeedValue($definition, 'category', 'cataloging')
            && self::hasExactSeedValue($definition, 'data_source', 'folio')
            && self::hasExactSeedValue($definition, 'default_limit', '100000')
            && self::hasExactSeedValue($definition, 'is_active', '1')
            && self::hasExactSeedValue($definition, 'created_by', 'manual')
            && self::hasCanonicalTemplate($definition['sql_template'] ?? null)
            && self::hasCanonicalParameters($definition['parameters'] ?? null)
            && self::hasCanonicalExecutionConfig($definition['execution_config'] ?? null);
    }

    private static function hasCanonicalTemplate($sql): bool
    {
        return is_string($sql)
            && hash_equals(self::CANONICAL_TEMPLATE_SHA256, hash('sha256', $sql));
    }

    private static function hasCanonicalParameters($parameters): bool
    {
        if (!is_string($parameters)) {
            return false;
        }
        $decoded = json_decode($parameters, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        $canonical = json_encode(self::normalizeJsonForFingerprint($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($canonical)
            && hash_equals(self::CANONICAL_PARAMETERS_SHA256, hash('sha256', $canonical));
    }

    private static function hasCanonicalExecutionConfig($executionConfig): bool
    {
        if (!is_string($executionConfig)) {
            return false;
        }
        $decoded = json_decode($executionConfig, true);
        return json_last_error() === JSON_ERROR_NONE
            && self::normalizeJsonForFingerprint($decoded) === self::normalizeJsonForFingerprint([
                'public_row_cap' => self::PUBLIC_ROW_CAP,
                'fetch_row_limit' => self::FETCH_ROW_LIMIT,
                'preserve_export_order' => true,
                'identifier_export' => ['source_column' => 'Instance UUID', 'header' => 'UUID'],
            ]);
    }

    private static function hasExactSeedValue(array $definition, string $field, string $expected): bool
    {
        return array_key_exists($field, $definition)
            && !is_array($definition[$field])
            && !is_object($definition[$field])
            && (string) $definition[$field] === $expected;
    }

    private static function normalizeJsonForFingerprint($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!self::isJsonList($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizeJsonForFingerprint($item);
        }
        return $value;
    }

    private static function isJsonList(array $value): bool
    {
        $expectedIndex = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expectedIndex++) {
                return false;
            }
        }
        return true;
    }
}
