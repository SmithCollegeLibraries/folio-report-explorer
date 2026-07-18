<?php

namespace app\services;

require_once __DIR__ . '/ExploratorySqlAnalysisService.php';

class ExploratorySqlSemanticValidatorService
{
    private const RULE_METHODS = [
        'purchase_date_basis' => 'validatePurchaseDateBasis',
        'investment_cost_basis' => 'validateInvestmentCostBasis',
        'spend_before_item_join' => 'validateSpendBeforeItemJoin',
        'circulation_window' => 'validateCirculationWindow',
        'circulation_item_grain' => 'validateCirculationItemGrain',
        'call_number_grouping' => 'validateCallNumberGrouping',
        'required_output_measures' => 'validateRequiredOutputMeasures',
        'roi_formula' => 'validateRoiFormula',
        'descending_purchase_ranking' => 'validateDescendingPurchaseRanking',
        'campus_scope' => 'validateCampusScope',
        'governed_filters' => 'validateGovernedFilters',
        'numeric_output_types' => 'validateNumericOutputTypes',
    ];

    private const GUIDANCE = [
        'purchase_date_basis' => 'Use the resolved invoice date basis for the purchase window.',
        'investment_cost_basis' => 'Calculate paid spending from each fund distribution amount and percentage exactly once.',
        'spend_before_item_join' => 'Aggregate purchase count and paid spending before joining item-level data.',
        'circulation_window' => 'Apply the resolved date window to checkout events at item grain.',
        'circulation_item_grain' => 'Filter checkout actions and aggregate circulation by item before broader joins.',
        'call_number_grouping' => 'Use call-number class as the final grouping dimension.',
        'required_output_measures' => 'Return every required purchase, spend, circulation, and ROI measure.',
        'roi_formula' => 'Use zero-safe division for both ROI measures.',
        'descending_purchase_ranking' => 'Order by purchase count descending before applying a limit.',
        'campus_scope' => 'Apply the selected campus scope in the report predicates.',
        'governed_filters' => 'Remove filters that were not permitted by the request contract.',
        'numeric_output_types' => 'Return analytical measures as numeric values without display formatting.',
    ];

    public static function supportedRuleKeys(): array
    {
        return array_keys(self::RULE_METHODS);
    }

    public static function validate(string $sql, array $contract): array
    {
        $version = (int)($contract['contractVersion'] ?? 0);
        if (empty($contract['applicable'])) {
            return [
                'status' => 'not_applicable',
                'contractVersion' => $version,
                'checkedRequirements' => [],
                'violations' => [],
            ];
        }

        $analysis = ExploratorySqlAnalysisService::analyze($sql);
        $checked = [];
        $violations = [];
        foreach (($contract['requirements'] ?? []) as $requirement) {
            $rule = (string)($requirement['rule'] ?? '');
            if (!isset(self::RULE_METHODS[$rule]) || !empty($analysis['ambiguous'])) {
                $violations[] = self::violation(
                    $requirement,
                    'semantic_coverage_gap',
                    'Use a simpler report shape so every requested requirement can be verified.'
                );
                continue;
            }

            $method = self::RULE_METHODS[$rule];
            $guidance = call_user_func([self::class, $method], $analysis, $requirement, $contract);
            if ($guidance === null) {
                $checked[] = [
                    'key' => (string)($requirement['key'] ?? ''),
                    'label' => (string)($requirement['label'] ?? ''),
                ];
            } else {
                $violations[] = self::violation($requirement, self::categoryFor((string)($requirement['key'] ?? '')), $guidance);
            }
        }

        return [
            'status' => $violations === [] ? 'validated' : 'rejected',
            'contractVersion' => $version,
            'checkedRequirements' => $violations === [] ? $checked : [],
            'violations' => $violations,
        ];
    }

    private static function validatePurchaseDateBasis(array $analysis, array $requirement, array $contract): ?string
    {
        $spend = self::spendCte($analysis);
        $expected = strtolower((string)($requirement['parameters']['value'] ?? ''));
        if (!in_array($expected, ['payment_date', 'invoice_date'], true)) {
            return self::GUIDANCE['purchase_date_basis'];
        }
        return $spend !== null && self::qualifyingWindow($spend, $expected) !== null
            ? null : self::GUIDANCE['purchase_date_basis'];
    }

    private static function validateInvestmentCostBasis(array $analysis, array $requirement, array $contract): ?string
    {
        if (($requirement['parameters']['value'] ?? null) !== 'actual_paid_fund_distribution') {
            return self::GUIDANCE['investment_cost_basis'];
        }
        $spend = self::spendCte($analysis);
        $item = self::itemForAlias($spend['selectItems'] ?? [], 'spend');
        $multiplication = $item['aggregateMultiplication'] ?? null;
        $factors = $multiplication['factors'] ?? [];
        $amountFactors = self::factorsForColumn($factors, 'total');
        $percentageFactors = self::factorsForColumn($factors, 'fund_distributions__value');
        $scaleFactors = array_values(array_filter($factors, static function (array $factor): bool {
            return ($factor['columns'] ?? []) === [] && ($factor['numericLiteral'] ?? null) === '0.01';
        }));
        $amountColumn = $amountFactors[0]['exactColumn'] ?? '';
        $percentageColumn = $percentageFactors[0]['exactColumn'] ?? '';
        $valid = ($multiplication['operator'] ?? null) === '*'
            && count($factors) === 3
            && count($amountFactors) === 1
            && count($percentageFactors) === 1
            && count($scaleFactors) === 1
            && self::columnQualifier($amountColumn) === self::columnQualifier($percentageColumn)
            && self::columnSource($spend, $amountColumn) === 'invoice.invoice_lines__t__fund_distributions';
        return $valid ? null : self::GUIDANCE['investment_cost_basis'];
    }

    private static function validateSpendBeforeItemJoin(array $analysis, array $requirement, array $contract): ?string
    {
        $spend = self::spendCte($analysis);
        if ($spend === null) {
            return self::GUIDANCE['spend_before_item_join'];
        }
        $purchaseCount = self::itemForAlias($spend['selectItems'] ?? [], 'purchase_count');
        $spending = self::itemForAlias($spend['selectItems'] ?? [], 'spend');
        if ($purchaseCount === null || empty($purchaseCount['aggregate'])
            || $spending === null || empty($spending['aggregate'])) {
            return self::GUIDANCE['spend_before_item_join'];
        }
        $spendName = self::spendCteName($analysis);
        if ($spendName === null || self::hasItemOrCirculationLineage($spendName, $analysis['ctes'] ?? [], [])) {
            return self::GUIDANCE['spend_before_item_join'];
        }
        return null;
    }

    private static function validateCirculationWindow(array $analysis, array $requirement, array $contract): ?string
    {
        if (($requirement['parameters']['value'] ?? null) !== 'same_as_purchase_window') {
            return self::GUIDANCE['circulation_window'];
        }
        $circulation = self::circulationItemCte($analysis);
        $spend = self::spendCte($analysis);
        if ($circulation === null || $spend === null) {
            return self::GUIDANCE['circulation_window'];
        }
        $purchaseBasis = self::purchaseDateBasis($contract);
        $purchaseWindow = $purchaseBasis === null ? null : self::qualifyingWindow($spend, $purchaseBasis);
        $circulationWindow = self::qualifyingWindow($circulation, 'created_date');
        return $purchaseWindow !== null && $circulationWindow !== null
            && $purchaseWindow['operator'] === $circulationWindow['operator']
            && $purchaseWindow['expression'] === $circulationWindow['expression']
            ? null : self::GUIDANCE['circulation_window'];
    }

    private static function validateCirculationItemGrain(array $analysis, array $requirement, array $contract): ?string
    {
        $circulation = self::circulationItemCte($analysis);
        if ($circulation === null) {
            return self::GUIDANCE['circulation_item_grain'];
        }
        $itemAlias = self::aliasForSource($circulation, 'inventory.item__t');
        $auditAlias = self::aliasForSource($circulation, 'circulation.audit_loan__t');
        $itemId = self::expressionForAlias($circulation['selectItems'] ?? [], 'item_id');
        $expectedGroup = $itemAlias === null ? [] : [$itemAlias . '.id', $itemAlias . '.holdings_record_id'];
        if ($itemAlias === null || $auditAlias === null
            || $itemId !== $itemAlias . '.id' || ($circulation['groupBy'] ?? []) !== $expectedGroup
            || !self::hasColumnEquality(
                $circulation['predicates']['columnComparisons'] ?? [],
                $auditAlias . '.loan__item_id',
                $itemAlias . '.id'
            )) {
            return self::GUIDANCE['circulation_item_grain'];
        }
        $approved = ['checkedout', 'checkedoutthroughoverride'];
        foreach (($circulation['predicates']['literalPredicates'] ?? []) as $predicate) {
            $values = array_map('strtolower', $predicate['values'] ?? []);
            if (($predicate['column'] ?? null) === $auditAlias . '.loan__action'
                && empty($predicate['negated'])
                && in_array($predicate['operator'] ?? null, ['=', 'IN'], true)
                && $values !== [] && array_diff($values, $approved) === []) {
                return null;
            }
        }
        return self::GUIDANCE['circulation_item_grain'];
    }

    private static function validateCallNumberGrouping(array $analysis, array $requirement, array $contract): ?string
    {
        if (($requirement['parameters']['value'] ?? null) !== 'primary_call_number_class') {
            return self::GUIDANCE['call_number_grouping'];
        }
        $groupBy = $analysis['groupBy'] ?? [];
        if (count($groupBy) !== 1) {
            return self::GUIDANCE['call_number_grouping'];
        }
        foreach (($analysis['selectItems'] ?? []) as $item) {
            $expression = strtolower((string)($item['expression'] ?? ''));
            $alias = strtolower((string)($item['alias'] ?? ''));
            if (self::expressionLeaf($expression) === 'call_number_class'
                && ($groupBy[0] === $expression || ($alias !== '' && $groupBy[0] === $alias))
                && self::hasCallNumberLineage($expression, $analysis['ctes'] ?? [])) {
                return null;
            }
        }
        return self::GUIDANCE['call_number_grouping'];
    }

    private static function validateRequiredOutputMeasures(array $analysis, array $requirement, array $contract): ?string
    {
        $aliases = array_column($analysis['selectItems'] ?? [], 'alias');
        $required = ['purchase_count', 'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout'];
        return array_diff($required, $aliases) === [] ? null : self::GUIDANCE['required_output_measures'];
    }

    private static function validateRoiFormula(array $analysis, array $requirement, array $contract): ?string
    {
        $basis = $requirement['parameters']['value'] ?? null;
        if (!in_array($basis, ['checkouts_per_dollar_with_cost_per_use', 'cost_per_checkout'], true)) {
            return self::GUIDANCE['roi_formula'];
        }
        $operands = [
            'checkouts_per_dollar' => ['circulation', 'spend'],
            'cost_per_checkout' => ['spend', 'circulation'],
        ];
        foreach ($operands as $alias => $expected) {
            $item = self::itemForAlias($analysis['selectItems'] ?? [], $alias);
            $division = $item['division'] ?? null;
            if ($division === null
                || !self::hasAggregateMeasureLineage($division['numeratorAggregate'] ?? null, $expected[0], $analysis)
                || !self::hasAggregateMeasureLineage($division['denominatorAggregate'] ?? null, $expected[1], $analysis)) {
                return self::GUIDANCE['roi_formula'];
            }
        }
        return null;
    }

    private static function validateDescendingPurchaseRanking(array $analysis, array $requirement, array $contract): ?string
    {
        $first = $analysis['orderBy'][0] ?? [];
        return ($first['expression'] ?? null) === 'purchase_count' && ($first['direction'] ?? null) === 'DESC'
            ? null : self::GUIDANCE['descending_purchase_ranking'];
    }

    private static function validateCampusScope(array $analysis, array $requirement, array $contract): ?string
    {
        if (empty($requirement['parameters']['required'])) {
            return null;
        }
        $expected = strtolower((string)($requirement['parameters']['value'] ?? ''));
        $campusPermission = $contract['permittedFilters']['campus'] ?? [];
        if (($campusPermission['provenance'] ?? null) !== 'selected_scope'
            || strtolower((string)($campusPermission['value'] ?? '')) !== $expected) {
            return self::GUIDANCE['campus_scope'];
        }
        foreach (self::allScopes($analysis) as $scope) {
            foreach (($scope['predicates']['literalPredicates'] ?? []) as $predicate) {
                $values = array_map('strtolower', $predicate['values'] ?? []);
                if (self::columnLeaf((string)($predicate['column'] ?? '')) === 'campus'
                    && empty($predicate['negated'])
                    && in_array($predicate['operator'] ?? null, ['=', 'IN'], true)
                    && $values === [$expected]) {
                    return null;
                }
            }
        }
        return self::GUIDANCE['campus_scope'];
    }

    private static function validateGovernedFilters(array $analysis, array $requirement, array $contract): ?string
    {
        $permitted = $contract['permittedFilters'] ?? [];
        foreach (self::allScopes($analysis) as $scope) {
            foreach (($scope['predicates']['governedFilters'] ?? []) as $column) {
                if (strpos($column, 'material_type') !== false
                    && !self::hasFilterProvenance($permitted, 'material_type', 'explicit_prompt')) {
                    return self::GUIDANCE['governed_filters'];
                }
                if ((strpos($column, 'acquisition_unit') !== false || strpos($column, 'acquisitions_unit') !== false)
                    && !self::hasFilterProvenance($permitted, 'acquisition_unit', 'explicit_prompt')) {
                    return self::GUIDANCE['governed_filters'];
                }
            }
        }
        return null;
    }

    private static function validateNumericOutputTypes(array $analysis, array $requirement, array $contract): ?string
    {
        $measures = ['purchase_count', 'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout'];
        if (array_intersect($measures, $analysis['formattedAliases'] ?? []) !== []) {
            return self::GUIDANCE['numeric_output_types'];
        }
        foreach ($measures as $measure) {
            $item = self::itemForAlias($analysis['selectItems'] ?? [], $measure);
            if ($item === null || empty($item['provenNumeric'])) {
                return self::GUIDANCE['numeric_output_types'];
            }
        }
        return null;
    }

    private static function spendCte(array $analysis): ?array
    {
        foreach (($analysis['ctes'] ?? []) as $cte) {
            if (self::containsTable($cte['tables'] ?? [], 'fund_distributions')
                && self::containsTable($cte['tables'] ?? [], 'po_line')) {
                return $cte;
            }
        }
        return null;
    }

    private static function spendCteName(array $analysis): ?string
    {
        foreach (($analysis['ctes'] ?? []) as $name => $cte) {
            if (self::containsTable($cte['tables'] ?? [], 'fund_distributions')
                && self::containsTable($cte['tables'] ?? [], 'po_line')) {
                return $name;
            }
        }
        return null;
    }

    private static function hasItemOrCirculationLineage(string $name, array $ctes, array $visited): bool
    {
        if (isset($visited[$name]) || !isset($ctes[$name])) {
            return !isset($ctes[$name]);
        }
        $visited[$name] = true;
        foreach (($ctes[$name]['tables'] ?? []) as $table) {
            if (strpos($table, 'inventory.') === 0 || strpos($table, 'circulation.') === 0) {
                return true;
            }
        }
        foreach (($ctes[$name]['dependencies'] ?? []) as $dependency) {
            if (self::hasItemOrCirculationLineage($dependency, $ctes, $visited)) {
                return true;
            }
        }
        return false;
    }

    private static function circulationItemCte(array $analysis): ?array
    {
        foreach (($analysis['ctes'] ?? []) as $cte) {
            if (self::containsTable($cte['tables'] ?? [], 'inventory.item')
                && self::containsTable($cte['tables'] ?? [], 'audit_loan')) {
                return $cte;
            }
        }
        return null;
    }

    private static function containsTable(array $tables, string $needle): bool
    {
        foreach ($tables as $table) {
            if (strpos($table, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function columnSource(array $scope, string $column): ?string
    {
        $qualifier = self::columnQualifier($column);
        $binding = $scope['sourceAliases'][$qualifier] ?? null;
        return ($binding['kind'] ?? null) === 'table' ? ($binding['source'] ?? null) : null;
    }

    private static function aliasForSource(array $scope, string $source): ?string
    {
        $aliases = [];
        foreach (($scope['sourceAliases'] ?? []) as $alias => $binding) {
            if (($binding['kind'] ?? null) === 'table' && ($binding['source'] ?? null) === $source) {
                $aliases[] = $alias;
            }
        }
        return count($aliases) === 1 ? $aliases[0] : null;
    }

    private static function hasColumnEquality(array $comparisons, string $left, string $right): bool
    {
        foreach ($comparisons as $comparison) {
            if (($comparison['operator'] ?? null) !== '=') {
                continue;
            }
            $actual = [$comparison['left'] ?? null, $comparison['right'] ?? null];
            if ($actual === [$left, $right] || $actual === [$right, $left]) {
                return true;
            }
        }
        return false;
    }

    private static function factorsForColumn(array $factors, string $leaf): array
    {
        return array_values(array_filter($factors, static function (array $factor) use ($leaf): bool {
            return isset($factor['exactColumn']) && self::columnLeaf($factor['exactColumn']) === $leaf;
        }));
    }

    private static function hasMeasureLineage(array $columns, string $measure, array $analysis): bool
    {
        if (count($columns) !== 1 || self::columnLeaf($columns[0]) !== $measure) {
            return false;
        }
        $qualifier = self::columnQualifier($columns[0]);
        $ctes = $analysis['ctes'] ?? [];
        if ($measure === 'spend') {
            return $qualifier === self::spendCteName($analysis);
        }
        return isset($ctes[$qualifier])
            && self::itemForAlias($ctes[$qualifier]['selectItems'] ?? [], 'circulation') !== null
            && self::hasTableLineage($qualifier, $ctes, 'audit_loan', []);
    }

    private static function hasAggregateMeasureLineage(?array $aggregate, string $measure, array $analysis): bool
    {
        return ($aggregate['function'] ?? null) === 'sum'
            && isset($aggregate['column'])
            && self::hasMeasureLineage([$aggregate['column']], $measure, $analysis);
    }

    private static function hasTableLineage(string $name, array $ctes, string $tableNeedle, array $visited): bool
    {
        if (isset($visited[$name]) || !isset($ctes[$name])) {
            return false;
        }
        $visited[$name] = true;
        if (self::containsTable($ctes[$name]['tables'] ?? [], $tableNeedle)) {
            return true;
        }
        foreach (($ctes[$name]['dependencies'] ?? []) as $dependency) {
            if (self::hasTableLineage($dependency, $ctes, $tableNeedle, $visited)) {
                return true;
            }
        }
        return false;
    }

    private static function columnLeaf(string $column): string
    {
        $parts = explode('.', strtolower($column));
        return (string)end($parts);
    }

    private static function columnQualifier(string $column): string
    {
        $parts = explode('.', strtolower($column));
        array_pop($parts);
        return implode('.', $parts);
    }

    private static function expressionLeaf(string $expression): ?string
    {
        if (preg_match('/^(?:[a-z_][a-z0-9_$-]*\.)?([a-z_][a-z0-9_$-]*)$/', $expression, $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    private static function hasCallNumberLineage(string $expression, array $ctes): bool
    {
        if (preg_match('/^([a-z_][a-z0-9_$-]*)\.call_number_class$/', $expression, $matches) !== 1
            || !isset($ctes[$matches[1]])) {
            return false;
        }
        $item = self::itemForAlias($ctes[$matches[1]]['selectItems'] ?? [], 'call_number_class');
        if (!in_array(
            $item['callNumberClassDerivation'] ?? null,
            ['substring_alpha_prefix', 'documented_lc_dewey_case'],
            true
        )) {
            return false;
        }
        $sources = [];
        foreach (($item['referencedColumns'] ?? []) as $column) {
            if (self::columnLeaf($column) === 'effective_call_number_components__call_number') {
                $sources[] = self::columnSource($ctes[$matches[1]], $column);
            }
        }
        return $sources !== []
            && array_diff($sources, ['inventory.item__t', 'inventory.holdings_record__t']) === [];
    }

    private static function expressionForAlias(array $items, string $alias): ?string
    {
        $item = self::itemForAlias($items, $alias);
        return $item === null ? null : strtolower((string)($item['expression'] ?? ''));
    }

    private static function itemForAlias(array $items, string $alias): ?array
    {
        foreach ($items as $item) {
            if (($item['alias'] ?? null) === $alias) {
                return $item;
            }
        }
        return null;
    }

    private static function hasFilterProvenance(array $permitted, string $filter, string $provenance): bool
    {
        return isset($permitted[$filter]) && ($permitted[$filter]['provenance'] ?? null) === $provenance;
    }

    private static function allScopes(array $analysis): array
    {
        return array_merge([$analysis], array_values($analysis['ctes'] ?? []));
    }

    private static function purchaseDateBasis(array $contract): ?string
    {
        foreach (($contract['requirements'] ?? []) as $requirement) {
            if (($requirement['key'] ?? null) === 'purchase_date_basis') {
                $basis = $requirement['parameters']['value'] ?? null;
                return in_array($basis, ['payment_date', 'invoice_date'], true) ? $basis : null;
            }
        }
        return null;
    }

    private static function qualifyingWindow(array $scope, string $expectedColumn): ?array
    {
        $facts = $scope['predicates']['dateWindows'] ?? [];
        if (count($facts) !== 1
            || self::columnLeaf((string)($facts[0]['column'] ?? '')) !== $expectedColumn
            || ($facts[0]['operator'] ?? null) !== '>='
            || ($facts[0]['expression'] ?? null) !== 'current_date - interval 5 years') {
            return null;
        }
        $expectedSource = $expectedColumn === 'created_date'
            ? 'circulation.audit_loan__t' : 'invoice.invoices__t';
        if (self::columnSource($scope, (string)$facts[0]['column']) !== $expectedSource) {
            return null;
        }
        return $facts[0];
    }

    private static function violation(array $requirement, string $category, string $guidance): array
    {
        return [
            'key' => (string)($requirement['key'] ?? ''),
            'category' => $category,
            'label' => (string)($requirement['label'] ?? ''),
            'guidance' => $guidance,
        ];
    }

    private static function categoryFor(string $key): string
    {
        $categories = [
            'purchase_date_basis' => 'assumption_mismatch',
            'investment_cost_basis' => 'assumption_mismatch',
            'spend_grain' => 'grain_mismatch',
            'circulation_window' => 'assumption_mismatch',
            'circulation_grain' => 'grain_mismatch',
            'call_number_grouping' => 'grain_mismatch',
            'required_measures' => 'semantic_coverage_gap',
            'roi_formula' => 'assumption_mismatch',
            'purchase_ranking' => 'missing_ordering',
            'campus_scope' => 'assumption_mismatch',
            'governed_filters' => 'unrequested_filter',
            'numeric_output_types' => 'output_type_mismatch',
        ];
        return $categories[$key] ?? 'semantic_coverage_gap';
    }
}
