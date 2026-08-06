<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;
use app\models\ReportTemplate;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/SqlSelectStructureService.php';
require_once __DIR__ . '/CatalogingMarcLocationScopeService.php';

final class CatalogingMarcFieldFinderService
{
    public const REPORT_SLUG = 'marc-field-indicator-content-finder';
    public const LOCATION_TOKEN = '{{location_from}}';
    public const MARC_TABLE_TOKEN = '{{marc_table}}';
    public const PUBLIC_ROW_CAP = 100000;
    public const FETCH_ROW_LIMIT = 100001;

    private const EXPECTED_PARAMETER_NAMES = [
        'locationIds',
        'locationBasis',
        'marcTag',
        'occurrenceCondition',
        'firstIndicator',
        'secondIndicator',
        'subfieldCode',
        'contentRule',
        'searchValue',
        'caseExact',
    ];
    private const CONTENT_RULES = [
        'any',
        'contains',
        'not_contains',
        'equals',
        'not_equals',
        'begins',
        'not_begins',
        'blank',
        'not_blank',
        'has_lowercase',
        'has_non_alphanumeric',
    ];
    private const TEXT_CONTENT_RULES = [
        'contains',
        'not_contains',
        'equals',
        'not_equals',
        'begins',
        'not_begins',
    ];
    private const CANONICAL_REPORT_NAME = 'MARC Field, Indicator, and Content Finder';
    private const CANONICAL_TEMPLATE_SHA256 = 'b6663a679553da0ac42a65c9140734d241b67341b46ae979aa42d06b334158a8';
    private const CANONICAL_PARAMETERS_SHA256 = '2d76737032a3fe4c1e5f21f65cb8bcbfbe7bbc4565d0ceba4ffca50cd70f1759';

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    /**
     * @return array{sql:string,params:array,location:array,locations:array,marcTag:string}
     */
    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        if (!self::supports($report)) {
            throw new \InvalidArgumentException('Unsupported MARC finder report template.');
        }

        self::assertParameterDefinitions($report);
        if (!self::isCanonicalSeedDefinition(self::reportDefinition($report))) {
            throw new \InvalidArgumentException('MARC finder report definition does not match the reviewed seed contract.');
        }

        $template = (string) $report->sql_template;
        self::assertStructuralTokenCount($template, self::LOCATION_TOKEN, 1);
        self::assertStructuralTokenCount($template, self::MARC_TABLE_TOKEN, 2);

        $scope = self::validateLocationScope($inputs);
        $marcTag = self::validateMarcTag($inputs['marcTag'] ?? null);
        $occurrenceCondition = self::oneOf(
            $inputs['occurrenceCondition'] ?? null,
            ['has', 'missing'],
            'occurrenceCondition',
            'Occurrence condition must be has or missing.'
        );
        $firstIndicator = self::normalizeIndicator($inputs['firstIndicator'] ?? null, 'firstIndicator');
        $secondIndicator = self::normalizeIndicator($inputs['secondIndicator'] ?? null, 'secondIndicator');
        $subfieldCode = self::validateSubfieldCode($inputs['subfieldCode'] ?? null);
        $contentRule = self::oneOf(
            $inputs['contentRule'] ?? null,
            self::CONTENT_RULES,
            'contentRule',
            'A supported content rule is required.'
        );
        $searchValue = self::validateSearchValue($inputs['searchValue'] ?? null, $contentRule);
        $caseExact = self::oneOf(
            $inputs['caseExact'] ?? null,
            ['true', 'false'],
            'caseExact',
            'Case matching must be true or false.'
        );
        if (!in_array($contentRule, self::TEXT_CONTENT_RULES, true)) {
            $caseExact = 'false';
        }

        $marcTable = 'marctab.mt' . $marcTag;
        $locationFragment = $scope['locationFragment'];
        if (strpos($locationFragment, ':') !== false || strpos($marcTable, ':') !== false) {
            throw new \InvalidArgumentException('Structural SQL replacements cannot contain bind markers.');
        }

        $resolvedSql = str_replace(self::LOCATION_TOKEN, $locationFragment, $template);
        $resolvedSql = str_replace(self::MARC_TABLE_TOKEN, $marcTable, $resolvedSql);
        self::assertNoStructuralTokens($resolvedSql, 'Report template contains an unresolved structural token.');

        $table = $folioDb->createCommand(
            'SELECT to_regclass(:table_name)',
            [':table_name' => $marcTable]
        )->queryOne();
        $resolvedTable = is_array($table) ? reset($table) : null;
        if ($resolvedTable === null || $resolvedTable === false || $resolvedTable === '') {
            throw new \InvalidArgumentException("Reporting schema is missing the expected MARC tag table {$marcTable}.");
        }

        try {
            $resolvedScope = CatalogingMarcLocationScopeService::resolveLocations($scope, $folioDb);
        } catch (\InvalidArgumentException $exception) {
            throw new ReportParameterValidationException('locationIds', $exception->getMessage());
        }

        $normalizedInputs = [
            'locationIds' => implode(',', $scope['locationIds']),
            'locationBasis' => $scope['locationBasis'],
            'marcTag' => $marcTag,
            'occurrenceCondition' => $occurrenceCondition,
            'firstIndicator' => $firstIndicator,
            'secondIndicator' => $secondIndicator,
            'subfieldCode' => $subfieldCode,
            'contentRule' => $contentRule,
            'searchValue' => $searchValue,
            'caseExact' => $caseExact,
        ];
        $bound = $report->bindParams($normalizedInputs, $resolvedSql);
        self::assertBoundParameterSet($bound['params'] ?? null);
        self::assertCompiledSql((string) ($bound['sql'] ?? ''), $marcTable);

        return [
            'sql' => $bound['sql'],
            'params' => $bound['params'],
            'location' => $resolvedScope['location'],
            'locations' => $resolvedScope['locations'],
            'marcTag' => $marcTag,
        ];
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

    private static function reportDefinition(ReportTemplate $report): array
    {
        return [
            'slug' => $report->slug,
            'name' => $report->name,
            'category' => $report->category,
            'sql_template' => $report->sql_template,
            'parameters' => $report->parameters,
            'data_source' => $report->data_source,
            'execution_config' => $report->execution_config,
            'default_limit' => $report->default_limit,
            'is_active' => $report->is_active,
            'created_by' => $report->created_by,
        ];
    }

    private static function assertParameterDefinitions(ReportTemplate $report): void
    {
        $names = [];
        foreach ($report->getDecodedParameters() as $definition) {
            $name = $definition['name'] ?? null;
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('MARC finder parameter definitions must each have a name.');
            }
            $names[] = $name;
        }

        foreach ($names as $name) {
            foreach ($names as $otherName) {
                if ($name !== $otherName && strpos($name, $otherName) === 0) {
                    throw new \InvalidArgumentException('MARC finder parameter names must not prefix-collide.');
                }
            }
        }

        if (count($names) !== count(self::EXPECTED_PARAMETER_NAMES)
            || count(array_unique($names)) !== count($names)
            || array_diff($names, self::EXPECTED_PARAMETER_NAMES)
            || array_diff(self::EXPECTED_PARAMETER_NAMES, $names)) {
            throw new \InvalidArgumentException('MARC finder must declare exactly the ten reviewed parameters once each.');
        }
    }

    private static function validateLocationScope(array $inputs): array
    {
        $basis = $inputs['locationBasis'] ?? null;
        if (!is_string($basis) || !in_array($basis, ['effective_item', 'permanent_item'], true)) {
            throw new ReportParameterValidationException(
                'locationBasis',
                'Location basis must be effective_item or permanent_item.'
            );
        }

        try {
            return CatalogingMarcLocationScopeService::validate(
                $inputs,
                ['effective_item', 'permanent_item']
            );
        } catch (\InvalidArgumentException $exception) {
            throw new ReportParameterValidationException('locationIds', $exception->getMessage());
        }
    }

    private static function validateMarcTag($value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:00[1-9]|0[1-9][0-9]|[1-9][0-9]{2})$/D', $value) !== 1) {
            throw new ReportParameterValidationException(
                'marcTag',
                'MARC tag must be exactly three ASCII digits from 001 through 999.'
            );
        }
        return $value;
    }

    private static function normalizeIndicator($value, string $field): string
    {
        if (!is_string($value)) {
            throw new ReportParameterValidationException($field, 'Indicator must be any, blank, or char: plus one character.');
        }
        if ($value === 'any' || $value === 'blank') {
            return $value;
        }
        if (preg_match('/\Achar:(.)\z/us', $value, $matches) !== 1) {
            throw new ReportParameterValidationException($field, 'Indicator must be any, blank, or char: plus one character.');
        }
        if ($matches[1] === '\\' || trim($matches[1]) === '') {
            return 'blank';
        }
        return $value;
    }

    private static function validateSubfieldCode($value): string
    {
        if (!is_string($value) || ($value !== '' && preg_match('/\A[A-Za-z0-9]\z/D', $value) !== 1)) {
            throw new ReportParameterValidationException(
                'subfieldCode',
                'Subfield code must be blank or one ASCII alphanumeric character.'
            );
        }
        return $value;
    }

    private static function validateSearchValue($value, string $contentRule): string
    {
        if (!is_string($value)) {
            throw new ReportParameterValidationException('searchValue', 'Search text must be a string.');
        }
        $usesText = in_array($contentRule, self::TEXT_CONTENT_RULES, true);
        if ($usesText && $value === '') {
            throw new ReportParameterValidationException('searchValue', 'Search text is required for this content rule.');
        }
        if (!$usesText && $value !== '') {
            throw new ReportParameterValidationException('searchValue', 'Search text must be empty for this content rule.');
        }
        return $value;
    }

    private static function oneOf($value, array $allowed, string $field, string $message): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ReportParameterValidationException($field, $message);
        }
        return $value;
    }

    private static function assertStructuralTokenCount(string $sql, string $token, int $expected): void
    {
        if (substr_count($sql, $token) !== $expected) {
            throw new \InvalidArgumentException("Report template must contain exactly {$expected} {$token} token occurrences.");
        }
    }

    private static function assertNoStructuralTokens(string $sql, string $message): void
    {
        if (preg_match('/\{\{[^{}]+\}\}/', $sql) === 1) {
            throw new \InvalidArgumentException($message);
        }
    }

    private static function assertBoundParameterSet($params): void
    {
        if (!is_array($params)) {
            throw new \InvalidArgumentException('MARC finder binding did not return a parameter set.');
        }
        $expected = array_map(static function (string $name): string {
            return ':' . $name;
        }, self::EXPECTED_PARAMETER_NAMES);
        $actual = array_keys($params);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Compiled MARC finder parameters do not match the reviewed parameter set.');
        }
    }

    private static function assertCompiledSql(string $sql, string $marcTable): void
    {
        self::assertNoStructuralTokens($sql, 'Compiled SQL contains an unresolved structural token.');
        $lowerSql = strtolower($sql);
        foreach (['folio_source_record.marctab', 'parsed_record__content'] as $forbidden) {
            if (strpos($lowerSql, $forbidden) !== false) {
                throw new \InvalidArgumentException('Compiled SQL references a forbidden MARC source.');
            }
        }

        preg_match_all('/\bmarctab\.[a-z_][a-z0-9_$-]*\b/i', $sql, $matches);
        $physicalTables = array_map('strtolower', $matches[0] ?? []);
        if (count($physicalTables) !== 2
            || count(array_unique($physicalTables)) !== 1
            || $physicalTables[0] !== strtolower($marcTable)) {
            throw new \InvalidArgumentException('Compiled SQL must reference exactly two copies of the selected MARC table.');
        }

        $references = SqlSelectStructureService::extractTableReferences($sql);
        foreach ($references as $reference) {
            if (strpos($reference, 'marctab.') === 0 && $reference !== strtolower($marcTable)) {
                throw new \InvalidArgumentException('Compiled SQL contains an unselected MARC physical table.');
            }
        }

        $tokens = SqlSelectStructureService::tokenizeForAnalysis($sql);
        $topLevelOrderByCount = 0;
        $topLevelNumericLimits = [];
        foreach ($tokens as $index => $token) {
            if (($token['depth'] ?? -1) !== 0) {
                continue;
            }
            if (self::isKeyword($token, 'ORDER')
                && isset($tokens[$index + 1])
                && ($tokens[$index + 1]['depth'] ?? -1) === 0
                && self::isKeyword($tokens[$index + 1], 'BY')) {
                $topLevelOrderByCount++;
            }
            if (self::isKeyword($token, 'LIMIT')
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
            throw new \InvalidArgumentException('Compiled SQL must contain exactly one top-level LIMIT 100001 clause.');
        }
    }

    private static function isKeyword(array $token, string $keyword): bool
    {
        return ($token['kind'] ?? '') === 'identifier'
            && empty($token['quoted'])
            && strtoupper((string) ($token['value'] ?? '')) === $keyword;
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
