<?php

namespace app\services;

use app\models\ReportTemplate;

require_once __DIR__ . '/SqlSelectStructureService.php';
require_once __DIR__ . '/CatalogingMarcLocationScopeService.php';

/**
 * Resolves the two reviewed structural slots in the fixed MARC missing-tag
 * report. All client-supplied values remain PDO parameters.
 */
final class CatalogingMarcMissingTagReportService
{
    public const REPORT_SLUG = 'marc-bibliographic-records-missing-tag';
    public const LOCATION_TOKEN = '{{location_from}}';
    public const MARC_TABLE_TOKEN = '{{marc_table}}';
    public const PUBLIC_ROW_CAP = 100000;
    public const FETCH_ROW_LIMIT = 100001;

    private const EXPECTED_PARAMETER_NAMES = ['locationIds', 'locationBasis', 'marcTag'];
    private const CANONICAL_TEMPLATE_SHA256 = '71353449b0b03cbb95f7cf2ac17b054606f8d411670abe4fd8f85af72c44d4d8';
    private const CANONICAL_PARAMETERS_SHA256 = '1e08003f820b425e6616303310902e72e0d8026f53a5284ebcaa0290a977d03b';
    private const LEGACY_TEMPLATE_SHA256 = 'aa19ffbe4b6407dfbc163b82fad04e44c329d7e06bc851c50d41301fc7b5eea8';
    private const LEGACY_PARAMETERS_SHA256 = '35630e90865f1e98bfd67957d19611031ac60cbe7547ab5079d8dbb1ecd27457';
    private const CANONICAL_REPORT_NAME = 'MARC Bibliographic Records Missing a Tag';

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    /**
     * This is the authoritative deployment-current contract for the fixed
     * report seed. Migration recognition and runtime compilation must reject
     * any unreviewed template or metadata drift.
     */
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

    /**
     * Migration baselining may recognize the exact seed installed by 040/041,
     * but runtime compilation only accepts the current canonical definition.
     */
    public static function isLegacySeedDefinition(array $definition): bool
    {
        return self::hasExactSeedValue($definition, 'slug', self::REPORT_SLUG)
            && self::hasExactSeedValue($definition, 'name', self::CANONICAL_REPORT_NAME)
            && self::hasExactSeedValue($definition, 'category', 'cataloging')
            && self::hasExactSeedValue($definition, 'data_source', 'folio')
            && self::hasExactSeedValue($definition, 'default_limit', '100000')
            && self::hasExactSeedValue($definition, 'is_active', '1')
            && self::hasExactSeedValue($definition, 'created_by', 'manual')
            && is_string($definition['sql_template'] ?? null)
            && hash_equals(self::LEGACY_TEMPLATE_SHA256, hash('sha256', $definition['sql_template']))
            && self::hasParametersFingerprint($definition['parameters'] ?? null, self::LEGACY_PARAMETERS_SHA256)
            && self::hasCanonicalExecutionConfig($definition['execution_config'] ?? null);
    }

    /**
     * @return array{sql:string,params:array,location:array{id:string,name:mixed,code:mixed},locations:array,marcTag:string,locationName:mixed,locationCode:mixed}
     */
    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        if (!self::supports($report)) {
            throw new \InvalidArgumentException('Unsupported report template.');
        }

        self::assertParameterDefinitions($report);
        self::assertTemplateContract((string) $report->sql_template);

        $scope = CatalogingMarcLocationScopeService::resolve(
            $inputs,
            $folioDb,
            ['effective_item', 'permanent_item', 'permanent_holdings']
        );
        $locationIds = $scope['locationIds'];
        $locationBasis = $scope['locationBasis'];
        $locationFragment = $scope['locationFragment'];

        $marcTag = $inputs['marcTag'] ?? null;
        if (!is_string($marcTag) || preg_match('/^(?:00[1-9]|0[1-9][0-9]|[1-9][0-9]{2})$/D', $marcTag) !== 1) {
            throw new \InvalidArgumentException('MARC tag must be exactly three ASCII digits from 001 through 999.');
        }

        $template = (string) $report->sql_template;
        self::assertSingleStructuralToken($template, self::LOCATION_TOKEN);
        self::assertSingleStructuralToken($template, self::MARC_TABLE_TOKEN);

        $marcTable = 'marctab.mt' . $marcTag;
        if (strpos($locationFragment, ':') !== false || strpos($marcTable, ':') !== false) {
            throw new \InvalidArgumentException('Structural SQL replacements cannot contain bind markers.');
        }

        $resolvedSql = str_replace(self::LOCATION_TOKEN, $locationFragment, $template);
        $resolvedSql = str_replace(self::MARC_TABLE_TOKEN, $marcTable, $resolvedSql);
        if (preg_match('/\{\{[^{}]+\}\}/', $resolvedSql) === 1) {
            throw new \InvalidArgumentException('Report template contains an unresolved structural token.');
        }

        $table = $folioDb->createCommand(
            'SELECT to_regclass(:table_name)',
            [':table_name' => $marcTable]
        )->queryOne();
        $resolvedTable = is_array($table) ? reset($table) : null;
        if ($resolvedTable === null || $resolvedTable === false || $resolvedTable === '') {
            throw new \InvalidArgumentException("Reporting schema is missing the expected MARC tag table {$marcTable}.");
        }

        $normalizedInputs = $inputs;
        $normalizedInputs['locationIds'] = implode(',', $locationIds);
        $bound = $report->bindParams($normalizedInputs, $resolvedSql);
        self::assertCompiledSql((string) ($bound['sql'] ?? ''));

        $location = $scope['location'];
        $locations = $scope['locations'];

        return [
            'sql' => $bound['sql'],
            'params' => $bound['params'],
            'location' => $location,
            'locations' => $locations,
            'marcTag' => $marcTag,
            'locationName' => $location['name'] ?? null,
            'locationCode' => $location['code'] ?? null,
        ];
    }

    private static function assertParameterDefinitions(ReportTemplate $report): void
    {
        $names = [];
        foreach ($report->getDecodedParameters() as $definition) {
            $name = $definition['name'] ?? null;
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('MARC report parameter definitions must each have a name.');
            }
            $names[] = $name;
        }

        if (count($names) !== count(self::EXPECTED_PARAMETER_NAMES) || count(array_unique($names)) !== count($names) || array_diff($names, self::EXPECTED_PARAMETER_NAMES) || array_diff(self::EXPECTED_PARAMETER_NAMES, $names)) {
            throw new \InvalidArgumentException('MARC report must declare exactly locationIds, locationBasis, and marcTag once each.');
        }

        foreach ($names as $name) {
            foreach ($names as $otherName) {
                if ($name !== $otherName && strpos($name, $otherName) === 0) {
                    throw new \InvalidArgumentException('MARC report parameter names must not prefix-collide.');
                }
            }
        }
    }

    private static function assertSingleStructuralToken(string $sql, string $token): void
    {
        if (substr_count($sql, $token) !== 1) {
            throw new \InvalidArgumentException("Report template must contain exactly one {$token} token.");
        }
    }

    private static function assertTemplateContract(string $sql): void
    {
        if (!self::hasCanonicalTemplate($sql)) {
            throw new \InvalidArgumentException('MARC report SQL template does not match the reviewed cataloging report contract.');
        }
    }

    private static function hasCanonicalTemplate($sql): bool
    {
        return is_string($sql)
            && hash_equals(self::CANONICAL_TEMPLATE_SHA256, hash('sha256', $sql));
    }

    private static function hasCanonicalParameters($parameters): bool
    {
        return self::hasParametersFingerprint($parameters, self::CANONICAL_PARAMETERS_SHA256);
    }

    private static function hasParametersFingerprint($parameters, string $expectedHash): bool
    {
        if (!is_string($parameters)) {
            return false;
        }

        $decoded = json_decode($parameters, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        $canonical = json_encode(
            self::normalizeJsonForFingerprint($decoded),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($canonical)
            && hash_equals($expectedHash, hash('sha256', $canonical));
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
                'identifier_export' => [
                    'source_column' => 'Instance UUID',
                    'header' => 'UUID',
                ],
            ]);
    }

    private static function hasExactSeedValue(array $definition, string $field, string $expected): bool
    {
        if (!array_key_exists($field, $definition) || is_array($definition[$field]) || is_object($definition[$field])) {
            return false;
        }

        return (string) $definition[$field] === $expected;
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
            if ($key !== $expectedIndex) {
                return false;
            }
            $expectedIndex++;
        }

        return true;
    }

    private static function assertCompiledSql(string $sql): void
    {
        if (preg_match('/\{\{[^{}]+\}\}/', $sql) === 1) {
            throw new \InvalidArgumentException('Compiled SQL contains an unresolved structural token.');
        }
        $tokens = SqlSelectStructureService::tokenizeForAnalysis($sql);
        $topLevelOrderByCount = 0;
        $topLevelNumericLimits = [];

        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) !== 0) {
                continue;
            }
            if (strtoupper((string) $token['value']) === 'ORDER'
                && isset($tokens[$index + 1])
                && ($tokens[$index + 1]['depth'] ?? -1) === 0
                && strtoupper((string) $tokens[$index + 1]['value']) === 'BY') {
                $topLevelOrderByCount++;
            }
            if (strtoupper((string) $token['value']) === 'LIMIT'
                && isset($tokens[$index + 1])
                && ($tokens[$index + 1]['depth'] ?? -1) === 0
                && ($tokens[$index + 1]['kind'] ?? '') === 'number') {
                $topLevelNumericLimits[] = (string) $tokens[$index + 1]['value'];
            }
        }

        if ($topLevelOrderByCount !== 1) {
            throw new \InvalidArgumentException('Compiled SQL must contain exactly one top-level ORDER BY clause.');
        }
        if ($topLevelNumericLimits !== [(string) self::FETCH_ROW_LIMIT]) {
            throw new \InvalidArgumentException('Compiled SQL must contain exactly one LIMIT 100001 clause.');
        }
    }
}
