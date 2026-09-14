<?php
namespace app\services;

use app\exceptions\ReportParameterValidationException;
use app\models\ReportTemplate;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/ReportExecutionContractService.php';

/** Fixed instance review: ownership must never depend on holdings or items. */
final class CatalogingHoldingslessReportService
{
    public const REPORT_SLUG = 'instances-without-holdings';
    private const SQL_SHA256 = 'e90f25025e6c66c9e8e6c2ae4187ff6c80f2be19eb84fd3db58bea2a9090b605';
    private const PARAMETERS_SHA256 = '9b9cc1f4a59062626f8eaf9199410f3ab6204d28f9de716df40fc592ccb9e7f9';
    private const OWNERS = ['all', 'SM', 'AM', 'MH', 'UM', 'HA', 'YB', 'DP', 'RC'];
    private const SUPPRESSION_MODES = ['all', 'exclude_discovery', 'exclude_staff', 'exclude_both'];

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        foreach ([
            'slug' => self::REPORT_SLUG, 'name' => 'Instances Without Holdings — Cataloging Review',
            'category' => 'cataloging', 'data_source' => 'folio', 'created_by' => 'manual',
            'default_limit' => '100000', 'is_active' => '1',
        ] as $key => $expected) {
            if (!is_scalar($definition[$key] ?? null) || (string)$definition[$key] !== $expected) {
                return false;
            }
        }
        $sql = $definition['sql_template'] ?? null;
        $parameters = self::normalizedJson($definition['parameters'] ?? null);
        $config = self::normalizedJson($definition['execution_config'] ?? null);
        return is_string($sql) && hash_equals(self::SQL_SHA256, hash('sha256', $sql))
            && is_array($parameters)
            && hash_equals(self::PARAMETERS_SHA256, hash('sha256', json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            && $config === self::normalizeJson([
                'public_row_cap' => 100000, 'fetch_row_limit' => 100001, 'preserve_export_order' => true,
                'identifier_export' => ['source_column' => 'Instance UUID', 'header' => 'UUID'],
            ]);
    }

    private static function normalizedJson($value)
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? self::normalizeJson($value) : null;
    }

    private static function normalizeJson($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        return array_map([self::class, 'normalizeJson'], $value);
    }

    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        $definition = [];
        foreach (['slug', 'name', 'category', 'data_source', 'created_by', 'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config'] as $key) {
            $definition[$key] = $report->$key;
        }
        if (!self::isCanonicalSeedDefinition($definition)) {
            throw new \InvalidArgumentException('Holdings-less report definition does not match the reviewed seed contract.');
        }
        $ownership = $inputs['ownershipCode'] ?? 'all';
        if (is_string($ownership)) {
            $ownership = strtolower(trim($ownership)) === 'all' ? 'all' : strtoupper(trim($ownership));
        }
        $ownership = self::choice($ownership, self::OWNERS, 'ownershipCode');
        $suppression = self::choice($inputs['suppressionMode'] ?? 'exclude_both', self::SUPPRESSION_MODES, 'suppressionMode');
        $exclusionMatch = self::choice($inputs['exclusionMatch'] ?? 'any', ['any', 'all'], 'exclusionMatch');
        $prefixes = self::prefixes($inputs['excluded035Prefixes'] ?? '');
        foreach (['marctab.mt998', 'marctab.mt035'] as $table) {
            $row = $folioDb->createCommand('SELECT to_regclass(:table_name)', [':table_name' => $table])->queryOne();
            if (!is_array($row) || !reset($row)) {
                throw new \InvalidArgumentException("Reporting schema is missing the expected MARC tag table {$table}.");
            }
        }
        $params = [
            ':ownershipCode' => $ownership, ':suppressionMode' => $suppression,
            ':excluded035Prefixes' => $prefixes,
            ':excluded035Patterns' => self::patterns($prefixes),
            ':exclusionMatch' => $exclusionMatch,
        ];
        ReportExecutionContractService::assertStaticExportSql($report->sql_template, ['fetchRowLimit' => 100001]);
        // Preserve the shared report worker's filename context without a location lookup.
        return [
            'sql' => $report->sql_template, 'params' => $params, 'marcTag' => '998-035',
            'location' => ['name' => 'no-holdings', 'code' => $ownership], 'locations' => [],
        ];
    }

    private static function choice($value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ReportParameterValidationException($field, 'Choose one of the listed options.');
        }
        return $value;
    }

    private static function prefixes($value): string
    {
        if (!is_string($value) || strlen($value) > 2000) {
            throw new ReportParameterValidationException('excluded035Prefixes', 'Enter comma-separated prefixes, at most 2,000 bytes.');
        }
        if (trim($value) === '') {
            return '';
        }
        $prefixes = array_map('trim', explode(',', $value));
        if (count($prefixes) > 20 || in_array('', $prefixes, true) || preg_match('/[\x00-\x1F\x7F]/', implode('', $prefixes))) {
            throw new ReportParameterValidationException('excluded035Prefixes', 'Enter up to 20 nonempty prefixes without control characters.');
        }
        return implode(',', array_values(array_unique($prefixes)));
    }

    private static function patterns(string $prefixes): string
    {
        if ($prefixes === '') {
            return '';
        }
        $patterns = [];
        foreach (explode(',', $prefixes) as $prefix) {
            // Only * is a wildcard. Escape SQL LIKE punctuation with the SQL's ! escape.
            $pattern = strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_', '*' => '%']) . '%';
            $patterns[] = $pattern;
        }
        return implode(',', array_values(array_unique($patterns)));
    }
}
