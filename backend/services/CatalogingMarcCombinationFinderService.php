<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;
use app\models\ReportTemplate;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/CatalogingMarcLocationScopeService.php';
require_once __DIR__ . '/CatalogingMarcConditionValidator.php';
require_once __DIR__ . '/CanonicalSeedJsonService.php';

/** Compiles the reviewed multi-condition MARC report without binding inactive slots. */
final class CatalogingMarcCombinationFinderService
{
    public const REPORT_SLUG = 'marc-field-combination-finder';
    private const LOCATION_TOKEN = '{{target_instances}}';
    private const PREDICATES_TOKEN = '{{active_condition_predicates}}';
    private const COLUMNS_TOKEN = '{{active_condition_columns}}';
    private const FETCH_ROW_LIMIT = 100001;
    private const CANONICAL_REPORT_NAME = 'MARC Field Combination Finder';
    private const TEMPLATE_HASH = '007d683165861e57d610040e6cc04e2666ddf79cc1a5eb7695192a3d8f4f0feb';
    private const PARAMETERS_HASH = 'e649ca1e821eaffa7022f851ece5f3f2805dfa616885fae354e6bff9e7a7a72d';
    private const EXECUTION_HASH = '2efff87a597201b87fee8fe5116a3c03ef4b551193d5dded621b196b8f3efc1b';
    private const HELP_TEXT = 'Search active locations by campus, library, location name, or location code; select up to 100 locations and choose Effective item or Permanent item as the location basis. Add one to five contiguous MARC conditions and combine them as all conditions (AND) or any condition (OR). A condition can find a matching field occurrence or an instance missing one. Results contain one row per instance and report the conditions evaluated for each row. These findings are facts, not cataloging judgments; review them in the context of record type, cataloging rules, legacy practice, and local policy. Holdings with no items (Smith) searches Smith-owned MARC instances with holdings and no items; suppressed records are included and marked-for-deletion records are excluded. Instances with no holdings (Smith) searches Smith-owned MARC instances without holdings; suppressed records are included and marked-for-deletion records are excluded.';
    private const LEGACY_HELP_TEXT = 'Search active locations by campus, library, location name, or location code; select up to 100 locations and choose Effective item or Permanent item as the location basis. Add one to five contiguous MARC conditions and combine them as all conditions (AND) or any condition (OR). A condition can find a matching field occurrence or an instance missing one. Results contain one row per instance and report the conditions evaluated for each row. These findings are facts, not cataloging judgments; review them in the context of record type, cataloging rules, legacy practice, and local policy.';
    private const LEGACY_TEMPLATE_HASH = 'e25101e5af5365f4c64d09486145afaaf9e150c101ee8c52d195c1900cf71122';
    private const LEGACY_PARAMETERS_HASH = '42e71d5b6ab36eb70d872075fefc6d13c89a65b8b6311b295681ce1cfdb6b3f0';
    private const CONDITION_FIELDS = [
        'marcTag', 'occurrenceCondition', 'firstIndicator', 'secondIndicator',
        'subfieldCode', 'contentRule', 'searchValue', 'caseExact',
    ];

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    /** @return array{sql:string,params:array,location:array,locations:array,marcTag:string,identity:array} */
    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        if (!self::supports($report)) {
            throw new \InvalidArgumentException('Unsupported MARC combination finder report template.');
        }
        self::assertParameterDefinitions($report);
        if (!self::isCanonicalSeedDefinition(self::reportDefinition($report))) {
            throw new \InvalidArgumentException('MARC combination finder report definition does not match the reviewed seed contract.');
        }

        $template = (string) $report->sql_template;
        self::assertTokenCount($template, self::LOCATION_TOKEN);
        self::assertTokenCount($template, self::PREDICATES_TOKEN);
        self::assertTokenCount($template, self::COLUMNS_TOKEN);
        $scope = self::validateLocationScope($inputs);
        $combine = $inputs['combine'] ?? null;
        if (!is_string($combine) || !in_array($combine, ['all', 'any'], true)) {
            throw new ReportParameterValidationException('combine', 'Combine must be all (AND) or any (OR).');
        }
        $conditions = self::activeConditions($inputs);
        $params = [
            ':locationBasis' => $scope['locationBasis'],
            ':combine' => $combine,
        ];
        if ($scope['locationIds']) $params[':locationIds'] = implode(',', $scope['locationIds']);
        $predicates = [];
        $columns = [];
        $identity = [
            'locationIds' => implode(',', $scope['locationIds']),
            'locationBasis' => $scope['locationBasis'],
            'combine' => $combine,
        ];
        foreach ($conditions as $number => $condition) {
            $table = 'marctab.mt' . $condition['marcTag'];
            $lookup = $folioDb->createCommand('SELECT to_regclass(:table_name)', [':table_name' => $table])->queryOne();
            $resolvedTable = is_array($lookup) ? reset($lookup) : null;
            if ($resolvedTable === null || $resolvedTable === false || $resolvedTable === '') {
                throw new \InvalidArgumentException("MARC tag {$condition['marcTag']} (condition {$number}) isn't available in the reporting database.");
            }
            $condition['table'] = $table;
            $conditions[$number] = $condition;
            $predicates[] = self::conditionSatisfaction($condition, 'target_instances', 'condition_' . $number);
            $columns[] = self::conditionColumns($condition, $number);
            foreach (self::CONDITION_FIELDS as $field) {
                $identity['c' . $number . '_' . $field] = $condition[$field];
                if ($field !== 'marcTag') {
                    $params[':c' . $number . '_' . $field] = $condition[$field];
                }
            }
            $params[':c' . $number . '_marcTag'] = $condition['marcTag'];
        }

        if (!CatalogingMarcLocationScopeService::isCombinationTargetInstances($scope['targetInstances'])) {
            throw new \InvalidArgumentException('MARC combination report has an unreviewed target-instance replacement.');
        }
        $resolvedSql = str_replace(self::LOCATION_TOKEN, $scope['targetInstances'], $template);
        $resolvedSql = str_replace(self::PREDICATES_TOKEN, implode($combine === 'all' ? "\n    AND " : "\n    OR ", $predicates), $resolvedSql);
        $resolvedSql = str_replace(self::COLUMNS_TOKEN, implode(",\n  ", $columns), $resolvedSql);
        if (preg_match('/\{\{[^{}]+\}\}/', $resolvedSql) === 1) {
            throw new \InvalidArgumentException('MARC combination report contains an unresolved structural token.');
        }
        if (substr_count($resolvedSql, 'LIMIT ' . self::FETCH_ROW_LIMIT) !== 1) {
            throw new \InvalidArgumentException('MARC combination report must retain its 100001 fetch limit.');
        }
        $resolvedScope = $scope['locationIds']
            ? CatalogingMarcLocationScopeService::resolveLocations($scope, $folioDb)
            : [
                'location' => ['name' => $scope['locationBasis'] === 'holdings_no_items' ? 'holdings-no-items' : 'no-holdings', 'code' => 'SM'],
                'locations' => [],
            ];
        return [
            'sql' => $resolvedSql,
            'params' => $params,
            'location' => $resolvedScope['location'],
            'locations' => $resolvedScope['locations'],
            'marcTag' => implode('+', array_column($conditions, 'marcTag')),
            'identity' => $identity,
        ];
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        return self::canonicalSeedDefinition($definition, self::HELP_TEXT, self::PARAMETERS_HASH, self::TEMPLATE_HASH);
    }

    public static function isCanonical068SeedDefinition(array $definition): bool
    {
        return self::canonicalSeedDefinition($definition, self::LEGACY_HELP_TEXT, self::LEGACY_PARAMETERS_HASH, self::LEGACY_TEMPLATE_HASH);
    }

    private static function canonicalSeedDefinition(array $definition, string $helpText, string $parametersHash, string $templateHash): bool
    {
        return self::exact($definition, 'slug', self::REPORT_SLUG)
            && self::exact($definition, 'name', self::CANONICAL_REPORT_NAME)
            && self::exact($definition, 'description', 'Finds MARC bibliographic instances that have or are missing several specified field conditions, combined with AND or OR.')
            && self::exact($definition, 'help_text', $helpText)
            && self::exact($definition, 'category', 'cataloging')
            && self::exact($definition, 'data_source', 'folio')
            && self::exact($definition, 'default_limit', '100000')
            && self::exact($definition, 'is_active', '1')
            && self::exact($definition, 'created_by', 'manual')
            && array_key_exists('composite_config', $definition)
            && $definition['composite_config'] === null
            && self::canonicalJson($definition['parameters'] ?? null, $parametersHash)
            && self::canonicalJson($definition['execution_config'] ?? null, self::EXECUTION_HASH)
            && is_string($definition['sql_template'] ?? null)
            && hash_equals($templateHash, hash('sha256', $definition['sql_template']));
    }

    private static function activeConditions(array $inputs): array
    {
        $conditions = [];
        $firstGap = null;
        for ($number = 1; $number <= 5; $number++) {
            $tagField = 'c' . $number . '_marcTag';
            $tag = $inputs[$tagField] ?? '';
            if ($tag === '' || $tag === null) {
                if ($firstGap === null) $firstGap = $tagField;
                continue;
            }
            if ($firstGap !== null) {
                throw new ReportParameterValidationException($firstGap, 'Conditions must be contiguous.');
            }
            $values = [];
            foreach (self::CONDITION_FIELDS as $field) {
                $values[$field] = $inputs['c' . $number . '_' . $field] ?? null;
            }
            $conditions[$number] = CatalogingMarcConditionValidator::validate($values, 'c' . $number . '_');
        }
        if (!$conditions) {
            throw new ReportParameterValidationException('c1_marcTag', 'At least one active MARC condition is required.');
        }
        return $conditions;
    }

    private static function conditionSatisfaction(array $condition, string $instanceAlias, string $rowAlias): string
    {
        $exists = 'EXISTS (SELECT 1 FROM ' . $condition['table'] . ' ' . $rowAlias
            . ' WHERE ' . $rowAlias . '.instance_id = ' . $instanceAlias . '.instance_uuid'
            . ' AND ' . self::rowPredicate($condition, $rowAlias) . ')';
        return $condition['occurrenceCondition'] === 'missing' ? 'NOT ' . $exists : $exists;
    }

    private static function rowPredicate(array $condition, string $alias): string
    {
        $prefix = ':c' . preg_replace('/\D/', '', $alias) . '_';
        $first = $prefix . 'firstIndicator';
        $second = $prefix . 'secondIndicator';
        $subfield = $prefix . 'subfieldCode';
        $rule = $prefix . 'contentRule';
        $search = $prefix . 'searchValue';
        $exact = $prefix . 'caseExact';
        return "({$first} = 'any' OR ({$first} = 'blank' AND (TRIM(COALESCE({$alias}.ind1, '')) = '' OR {$alias}.ind1 = CHR(92))) OR (LEFT({$first}, 5) = 'char:' AND {$alias}.ind1 = SUBSTRING({$first} FROM 6)))"
            . " AND ({$second} = 'any' OR ({$second} = 'blank' AND (TRIM(COALESCE({$alias}.ind2, '')) = '' OR {$alias}.ind2 = CHR(92))) OR (LEFT({$second}, 5) = 'char:' AND {$alias}.ind2 = SUBSTRING({$second} FROM 6)))"
            . " AND ({$subfield} = '' OR COALESCE({$alias}.sf, '') = {$subfield})"
            . " AND CASE {$rule} WHEN 'any' THEN TRUE"
            . " WHEN 'contains' THEN STRPOS(CASE WHEN {$exact} = 'true' THEN COALESCE({$alias}.content, '') ELSE LOWER(COALESCE({$alias}.content, '')) END, CASE WHEN {$exact} = 'true' THEN {$search} ELSE LOWER({$search}) END) > 0"
            . " WHEN 'not_contains' THEN STRPOS(CASE WHEN {$exact} = 'true' THEN COALESCE({$alias}.content, '') ELSE LOWER(COALESCE({$alias}.content, '')) END, CASE WHEN {$exact} = 'true' THEN {$search} ELSE LOWER({$search}) END) = 0"
            . " WHEN 'equals' THEN CASE WHEN {$exact} = 'true' THEN COALESCE({$alias}.content, '') ELSE LOWER(COALESCE({$alias}.content, '')) END = CASE WHEN {$exact} = 'true' THEN {$search} ELSE LOWER({$search}) END"
            . " WHEN 'not_equals' THEN CASE WHEN {$exact} = 'true' THEN COALESCE({$alias}.content, '') ELSE LOWER(COALESCE({$alias}.content, '')) END <> CASE WHEN {$exact} = 'true' THEN {$search} ELSE LOWER({$search}) END"
            . " WHEN 'begins' THEN LEFT(CASE WHEN {$exact} = 'true' THEN COALESCE({$alias}.content, '') ELSE LOWER(COALESCE({$alias}.content, '')) END, CHAR_LENGTH({$search})) = CASE WHEN {$exact} = 'true' THEN {$search} ELSE LOWER({$search}) END"
            . " WHEN 'not_begins' THEN LEFT(CASE WHEN {$exact} = 'true' THEN COALESCE({$alias}.content, '') ELSE LOWER(COALESCE({$alias}.content, '')) END, CHAR_LENGTH({$search})) <> CASE WHEN {$exact} = 'true' THEN {$search} ELSE LOWER({$search}) END"
            . " WHEN 'blank' THEN TRIM(COALESCE({$alias}.content, '')) = ''"
            . " WHEN 'not_blank' THEN TRIM(COALESCE({$alias}.content, '')) <> ''"
            . " WHEN 'has_lowercase' THEN COALESCE({$alias}.content, '') ~ '[a-z]'"
            . " WHEN 'has_non_alphanumeric' THEN COALESCE({$alias}.content, '') ~ '[^A-Za-z0-9]' ELSE FALSE END";
    }

    private static function conditionColumns(array $condition, int $number): string
    {
        $prefix = ':c' . $number . '_';
        $summary = "CASE {$prefix}occurrenceCondition WHEN 'missing' THEN 'missing ' ELSE '' END || {$prefix}marcTag"
            . " || CASE WHEN {$prefix}firstIndicator = 'any' AND {$prefix}secondIndicator = 'any' THEN '' ELSE ' ' || "
            . self::indicatorPositionSql($prefix . 'firstIndicator') . ' || '
            . self::indicatorPositionSql($prefix . 'secondIndicator') . ' END'
            . " || CASE WHEN {$prefix}subfieldCode = '' THEN '' ELSE ' $' || {$prefix}subfieldCode END"
            . " || CASE {$prefix}contentRule WHEN 'contains' THEN ' contains \"' || {$prefix}searchValue || '\"'"
            . " WHEN 'not_contains' THEN ' does not contain \"' || {$prefix}searchValue || '\"'"
            . " WHEN 'equals' THEN ' equals \"' || {$prefix}searchValue || '\"'"
            . " WHEN 'not_equals' THEN ' does not equal \"' || {$prefix}searchValue || '\"'"
            . " WHEN 'begins' THEN ' begins with \"' || {$prefix}searchValue || '\"'"
            . " WHEN 'not_begins' THEN ' does not begin with \"' || {$prefix}searchValue || '\"'"
            . " WHEN 'blank' THEN ' is blank' WHEN 'not_blank' THEN ' is not blank'"
            . " WHEN 'has_lowercase' THEN ' has lowercase' WHEN 'has_non_alphanumeric' THEN ' has non-alphanumeric' ELSE '' END";
        $satisfied = self::conditionSatisfaction($condition, 'matching_instances', 'condition_' . $number);
        $aggregate = self::resultAggregate($condition, $number);
        return $summary . ' AS "Condition ' . $number . '", CASE WHEN ' . $satisfied
            . " THEN CASE WHEN {$prefix}occurrenceCondition = 'missing' THEN 'Missing' ELSE COALESCE(("
            . $aggregate . "), '(blank)') END ELSE 'Not matched' END AS \"Condition {$number} result\"";
    }

    private static function indicatorPositionSql(string $parameter): string
    {
        return "CASE {$parameter} WHEN 'any' THEN '?' WHEN 'blank' THEN '#' ELSE SUBSTRING({$parameter} FROM 6) END";
    }

    private static function resultAggregate(array $condition, int $number): string
    {
        $alias = 'result_' . $number;
        $display = "CASE WHEN {$alias}.content IS NULL OR {$alias}.content ~ ('^[' || CHR(9) || CHR(10) || CHR(13) || ' ]*$') THEN '(blank)' ELSE {$alias}.content END";
        return 'SELECT CASE WHEN CHAR_LENGTH(joined_values) > 500 THEN LEFT(joined_values, 500) || \'…\' ELSE joined_values END FROM ('
            . "SELECT STRING_AGG(result_value, '; ' ORDER BY first_occurrence, first_subfield) AS joined_values FROM ("
            . 'SELECT result_value, first_occurrence, first_subfield FROM ('
            . "SELECT {$display} AS result_value, {$alias}.ord AS first_occurrence, {$alias}.line AS first_subfield, "
                . "ROW_NUMBER() OVER (PARTITION BY {$display} ORDER BY {$alias}.ord, {$alias}.line) AS content_rank"
            . ' FROM ' . $condition['table'] . ' ' . $alias
            . ' WHERE ' . $alias . '.instance_id = matching_instances.instance_uuid AND ' . self::rowPredicate($condition, $alias)
            . ') ranked_contents WHERE content_rank = 1) distinct_contents) ordered_contents';
    }

    private static function validateLocationScope(array $inputs): array
    {
        if (!is_string($inputs['locationBasis'] ?? null)
            || !in_array($inputs['locationBasis'], ['effective_item', 'permanent_item', 'holdings_no_items', 'no_holdings'], true)) {
            throw new ReportParameterValidationException('locationBasis', 'Location basis must be one of the four supported scopes.');
        }
        try {
            return CatalogingMarcLocationScopeService::validateCombination($inputs);
        } catch (\InvalidArgumentException $exception) {
            throw new ReportParameterValidationException('locationIds', $exception->getMessage());
        }
    }

    private static function assertParameterDefinitions(ReportTemplate $report): void
    {
        $expected = ['locationIds', 'locationBasis', 'combine'];
        for ($number = 1; $number <= 5; $number++) {
            foreach (self::CONDITION_FIELDS as $field) $expected[] = 'c' . $number . '_' . $field;
        }
        $names = [];
        foreach ($report->getDecodedParameters() as $definition) {
            $name = $definition['name'] ?? null;
            if (!is_string($name) || $name === '') throw new \InvalidArgumentException('MARC combination parameter definitions must each have a name.');
            $names[] = $name;
        }
        if ($names !== $expected) throw new \InvalidArgumentException('MARC combination finder must declare the reviewed 43 parameters in order.');
    }

    private static function reportDefinition(ReportTemplate $report): array
    {
        return [
            'slug' => $report->slug, 'name' => $report->name, 'description' => $report->description,
            'help_text' => $report->help_text, 'category' => $report->category, 'sql_template' => $report->sql_template,
            'parameters' => $report->parameters, 'data_source' => $report->data_source,
            'execution_config' => $report->execution_config, 'composite_config' => $report->composite_config,
            'default_limit' => $report->default_limit,
            'is_active' => $report->is_active, 'created_by' => $report->created_by,
        ];
    }

    private static function exact(array $definition, string $key, string $expected): bool
    {
        return array_key_exists($key, $definition) && !is_array($definition[$key])
            && !is_object($definition[$key]) && (string) $definition[$key] === $expected;
    }

    private static function canonicalJson($value, string $hash): bool
    {
        return CanonicalSeedJsonService::matchesFingerprint($value, $hash);
    }

    private static function assertTokenCount(string $template, string $token): void
    {
        if (substr_count($template, $token) !== 1) throw new \InvalidArgumentException('MARC combination template has an invalid structural token contract.');
    }
}
