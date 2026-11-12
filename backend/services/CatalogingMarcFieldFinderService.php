<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;
use app\models\ReportTemplate;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/SqlSelectStructureService.php';
require_once __DIR__ . '/CatalogingMarcLocationScopeService.php';
require_once __DIR__ . '/CatalogingMarcConditionValidator.php';
require_once __DIR__ . '/CanonicalSeedJsonService.php';

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
        $condition = CatalogingMarcConditionValidator::validate($inputs);
        $marcTag = $condition['marcTag'];
        $occurrenceCondition = $condition['occurrenceCondition'];
        $firstIndicator = $condition['firstIndicator'];
        $secondIndicator = $condition['secondIndicator'];
        $subfieldCode = $condition['subfieldCode'];
        $contentRule = $condition['contentRule'];
        $searchValue = $condition['searchValue'];
        $caseExact = $condition['caseExact'];

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

        // A syntactically valid but deleted location is an integrity failure,
        // not a user-editable parameter error. Let the controller preserve its
        // existing safe 422 response for this case.
        $resolvedScope = CatalogingMarcLocationScopeService::resolveLocations($scope, $folioDb);

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

        $tokens = SqlSelectStructureService::tokenizeForAnalysis($sql);
        $physicalTables = self::resolvedMarcTableReferences($tokens);
        if (count($physicalTables) !== 2
            || count(array_unique($physicalTables)) !== 1
            || $physicalTables[0] !== strtolower($marcTable)) {
            throw new \InvalidArgumentException('Compiled SQL must reference exactly two copies of the selected MARC table.');
        }

        $topLevelOrderByCount = 0;
        $topLevelLimits = [];
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
            if (self::isKeyword($token, 'LIMIT')) {
                $topLevelLimits[] = $index;
            }
        }
        if ($topLevelOrderByCount !== 1) {
            throw new \InvalidArgumentException('Compiled SQL must contain exactly one top-level ORDER BY clause.');
        }
        if (count($topLevelLimits) !== 1) {
            throw new \InvalidArgumentException('Compiled SQL must contain exactly one top-level LIMIT 100001 clause.');
        }
        $limitToken = $tokens[$topLevelLimits[0] + 1] ?? null;
        if (($limitToken['depth'] ?? -1) !== 0
            || ($limitToken['kind'] ?? '') !== 'number'
            || (string) ($limitToken['value'] ?? '') !== (string) self::FETCH_ROW_LIMIT) {
            throw new \InvalidArgumentException('Compiled SQL must contain exactly one top-level LIMIT 100001 clause.');
        }
    }

    private static function resolvedMarcTableReferences(array $tokens): array
    {
        $references = [];
        foreach ($tokens as $index => $token) {
            if (!self::isKeyword($token, 'FROM') && !self::isKeyword($token, 'JOIN')) {
                continue;
            }
            $schema = $tokens[$index + 1] ?? null;
            $separator = $tokens[$index + 2] ?? null;
            $table = $tokens[$index + 3] ?? null;
            if (($schema['kind'] ?? '') !== 'identifier'
                || strtolower((string) ($schema['value'] ?? '')) !== 'marctab'
                || ($separator['value'] ?? '') !== '.'
                || ($table['kind'] ?? '') !== 'identifier') {
                continue;
            }
            $references[] = 'marctab.' . strtolower((string) $table['value']);
        }
        return $references;
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
        return CanonicalSeedJsonService::matchesFingerprint($parameters, self::CANONICAL_PARAMETERS_SHA256);
    }

    private static function hasCanonicalExecutionConfig($executionConfig): bool
    {
        return CanonicalSeedJsonService::normalize($executionConfig) === CanonicalSeedJsonService::normalize([
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

}
