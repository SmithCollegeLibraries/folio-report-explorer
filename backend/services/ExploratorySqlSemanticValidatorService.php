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
        'physical_item_eligibility' => 'validatePhysicalItemEligibility',
        'acquisition_unit_scope' => 'validateAcquisitionUnitScope',
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
        'physical_item_eligibility' => 'Require positive physical quantity and current items at the selected campus.',
        'acquisition_unit_scope' => 'Restrict purchase orders to the SC acquisitions unit.',
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
        $spend = !empty($contract['reportPolicy']['physicalOnly'])
            ? self::fundedInvoiceLineScope($analysis)
            : self::spendCte($analysis);
        $expected = strtolower((string)($requirement['parameters']['value'] ?? ''));
        if (!in_array($expected, ['payment_date', 'invoice_date'], true)) {
            return self::GUIDANCE['purchase_date_basis'];
        }
        if (self::investmentCostBasis($contract) === 'estimated_po_line_price') {
            return self::estimatedEligibilityCte($analysis, $expected) !== null
                ? null : self::GUIDANCE['purchase_date_basis'];
        }
        return $spend !== null && self::qualifyingWindow($spend, $expected) !== null
            ? null : self::GUIDANCE['purchase_date_basis'];
    }

    private static function validateInvestmentCostBasis(array $analysis, array $requirement, array $contract): ?string
    {
        $basis = $requirement['parameters']['value'] ?? null;
        $spend = !empty($contract['reportPolicy']['physicalOnly'])
            ? self::fundedInvoiceLineScope($analysis)
            : self::spendCte($analysis);
        $item = self::itemForAlias($spend['selectItems'] ?? [], 'spend');
        if ($basis === 'estimated_po_line_price') {
            $aggregate = $item['exactAggregate'] ?? null;
            return ($aggregate['function'] ?? null) === 'sum'
                && self::columnLeaf((string)($aggregate['column'] ?? '')) === 'cost__po_line_estimated_price'
                && self::columnSource($spend, (string)$aggregate['column']) === 'orders.po_line__t'
                && self::estimatedEligibilityCte($analysis, self::purchaseDateBasis($contract)) !== null
                ? null : self::GUIDANCE['investment_cost_basis'];
        }
        if ($basis !== 'actual_paid_fund_distribution') {
            return self::GUIDANCE['investment_cost_basis'];
        }
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
        $purchaseMeasure = self::purchaseRankingMeasure($contract);
        $purchaseCount = self::itemForAlias($spend['selectItems'] ?? [], $purchaseMeasure);
        $spending = self::itemForAlias($spend['selectItems'] ?? [], 'spend');
        if ($purchaseCount === null || empty($purchaseCount['aggregate'])
            || $spending === null || empty($spending['aggregate'])) {
            return self::GUIDANCE['spend_before_item_join'];
        }
        $spendName = self::spendCteName($analysis);
        if ($spendName === null
            || self::finalMeasureCteName($analysis, $purchaseMeasure) !== $spendName
            || !self::hasValidPurchaseMeasure($analysis, $purchaseMeasure)
            || ($purchaseMeasure === 'purchase_count'
                && self::hasItemOrCirculationLineage($spendName, $analysis['ctes'] ?? [], []))) {
            return self::GUIDANCE['spend_before_item_join'];
        }
        return null;
    }

    private static function validateCirculationWindow(array $analysis, array $requirement, array $contract): ?string
    {
        $window = $requirement['parameters']['value'] ?? null;
        $circulation = self::circulationItemCte($analysis);
        if ($window === 'lifetime_circulation') {
            return $circulation !== null && ($circulation['predicates']['dateWindows'] ?? []) === []
                ? null : self::GUIDANCE['circulation_window'];
        }
        if ($window !== 'same_as_purchase_window') {
            return self::GUIDANCE['circulation_window'];
        }
        $spend = !empty($contract['reportPolicy']['physicalOnly'])
            ? self::fundedInvoiceLineScope($analysis)
            : self::spendCte($analysis);
        if ($circulation === null || $spend === null) {
            return self::GUIDANCE['circulation_window'];
        }
        $purchaseBasis = self::purchaseDateBasis($contract);
        $purchaseScope = self::investmentCostBasis($contract) === 'estimated_po_line_price'
            ? self::estimatedEligibilityCte($analysis, $purchaseBasis)
            : $spend;
        $purchaseWindow = $purchaseBasis === null || $purchaseScope === null
            ? null : self::qualifyingWindow($purchaseScope, $purchaseBasis);
        $circulationWindow = self::qualifyingWindow($circulation, 'loan__loan_date')
            ?? self::qualifyingWindow($circulation, 'created_date');
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
        if (!empty($contract['reportPolicy']['physicalOnly'])
            && !self::hasDistinctAuditLoanCount($circulation)) {
            return self::GUIDANCE['circulation_item_grain'];
        }
        return null;
    }

    private static function hasDistinctAuditLoanCount(array $scope): bool
    {
        $aggregate = self::itemForAlias($scope['selectItems'] ?? [], 'checkouts')['exactAggregate'] ?? null;
        return ($aggregate['function'] ?? null) === 'count'
            && !empty($aggregate['distinct'])
            && self::columnLeaf((string)($aggregate['column'] ?? '')) === 'loan__id'
            && self::columnSource($scope, (string)($aggregate['column'] ?? '')) === 'circulation.audit_loan__t';
    }

    private static function validateCallNumberGrouping(array $analysis, array $requirement, array $contract): ?string
    {
        $basis = $requirement['parameters']['value'] ?? null;
        $expectedDerivations = [
            'primary_call_number_class' => ['substring_alpha_prefix', 'documented_lc_dewey_case'],
            'first_two_call_number_letters' => ['substring_first_two'],
        ];
        if (!isset($expectedDerivations[$basis])) {
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
                && self::hasCallNumberLineage($expression, $analysis, $expectedDerivations[$basis])) {
                return null;
            }
        }
        return self::GUIDANCE['call_number_grouping'];
    }

    private static function validateRequiredOutputMeasures(array $analysis, array $requirement, array $contract): ?string
    {
        $aliases = array_column($analysis['selectItems'] ?? [], 'alias');
        $required = $requirement['parameters']['values'] ?? [];
        return array_diff($required, $aliases) === [] ? null : self::GUIDANCE['required_output_measures'];
    }

    private static function validateRoiFormula(array $analysis, array $requirement, array $contract): ?string
    {
        $basis = $requirement['parameters']['value'] ?? null;
        if (!in_array($basis, ['checkouts_per_dollar_with_cost_per_use', 'cost_per_checkout'], true)) {
            return self::GUIDANCE['roi_formula'];
        }
        $operands = $basis === 'cost_per_checkout'
            ? ['cost_per_checkout' => ['spend', 'circulation']]
            : [
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
        $measure = (string)($requirement['parameters']['measure'] ?? '');
        $first = $analysis['orderBy'][0] ?? [];
        return in_array($measure, ['purchase_count', 'physical_copies_purchased'], true)
            && ($first['expression'] ?? null) === $measure
            && ($first['direction'] ?? null) === 'DESC'
            && self::hasValidPurchaseMeasure($analysis, $measure)
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
        $circulation = self::circulationItemCte($analysis);
        if ($circulation === null || !self::hasCampusHierarchy($circulation, $expected, false)) {
            return self::GUIDANCE['campus_scope'];
        }
        foreach (self::reachableCteEnforcement($analysis) as $name => $enforced) {
            $scope = $analysis['ctes'][$name] ?? null;
            if ($enforced && $scope !== null
                && self::hasCampusHierarchy($scope, $expected, true)
                && self::hasFinalCampusInstanceJoin($analysis, $name)) {
                return null;
            }
        }
        return self::GUIDANCE['campus_scope'];
    }

    private static function hasCampusHierarchy(array $scope, string $expected, bool $requireInstance): bool
    {
        $item = self::aliasForSource($scope, 'inventory.item__t');
        $location = self::aliasForSource($scope, 'inventory.location__t');
        $library = self::aliasForSource($scope, 'inventory.loclibrary__t');
        $campus = self::aliasForSource($scope, 'inventory.loccampus__t');
        if ($item === null || $location === null || $library === null || $campus === null
            || !self::hasExactColumnEquality($scope, $item . '.effective_location_id', $location . '.id')
            || !self::hasExactColumnEquality($scope, $location . '.library_id', $library . '.id')
            || !self::hasExactColumnEquality($scope, $library . '.campus_id', $campus . '.id')) {
            return false;
        }
        if ($requireInstance) {
            $holdings = self::aliasForSource($scope, 'inventory.holdings_record__t');
            if ($holdings === null
                || !self::hasExactColumnEquality($scope, $item . '.holdings_record_id', $holdings . '.id')
                || self::expressionForAlias($scope['selectItems'] ?? [], 'instance_id') !== $holdings . '.instance_id') {
                return false;
            }
        }
        foreach (($scope['predicates']['literalPredicates'] ?? []) as $predicate) {
            if (($predicate['column'] ?? null) === $campus . '.name'
                && self::isEnforcingFact($predicate)
                && empty($predicate['negated'])
                && in_array($predicate['operator'] ?? null, ['=', 'IN'], true)
                && array_map('strtolower', $predicate['values'] ?? []) === [$expected]) {
                return true;
            }
        }
        return false;
    }

    private static function hasExactColumnEquality(array $scope, string $left, string $right): bool
    {
        foreach (($scope['predicates']['columnComparisons'] ?? []) as $comparison) {
            $actual = [$comparison['left'] ?? null, $comparison['right'] ?? null];
            if (($comparison['operator'] ?? null) === '='
                && ($actual === [$left, $right] || $actual === [$right, $left])) {
                return true;
            }
        }
        return false;
    }

    private static function hasFinalCampusInstanceJoin(array $analysis, string $cteName): bool
    {
        $campusAlias = null;
        $spendAlias = null;
        $spendName = self::spendCteName($analysis);
        foreach (array_keys($analysis['sourceAliases'] ?? []) as $alias) {
            $binding = self::resolveQualifier($analysis, (string)$alias);
            if (($binding['kind'] ?? null) === 'cte' && ($binding['source'] ?? null) === $cteName) {
                $campusAlias = (string)$alias;
            }
            if (($binding['kind'] ?? null) === 'cte' && ($binding['source'] ?? null) === $spendName) {
                $spendAlias = (string)$alias;
            }
        }
        return $campusAlias !== null && $spendAlias !== null
            && self::hasExactColumnEquality($analysis, $campusAlias . '.instance_id', $spendAlias . '.instance_id');
    }

    private static function validatePhysicalItemEligibility(array $analysis, array $requirement, array $contract): ?string
    {
        $parameters = $requirement['parameters'] ?? [];
        $campus = strtolower((string)($parameters['campus'] ?? ''));
        if (empty($parameters['positivePhysicalQuantity'])
            || empty($parameters['currentSelectedCampusItem'])
            || $campus === '') {
            return self::GUIDANCE['physical_item_eligibility'];
        }
        $hasPositiveQuantity = false;
        foreach (self::purchaseMeasureScopes($analysis) as $scope) {
            $poLine = self::aliasForSource($scope, 'orders.po_line__t');
            if ($poLine !== null && self::hasPositivePhysicalQuantity($scope, $poLine)) {
                $hasPositiveQuantity = true;
                break;
            }
        }
        if (!$hasPositiveQuantity) {
            return self::GUIDANCE['physical_item_eligibility'];
        }
        foreach (self::reachableCteEnforcement($analysis) as $name => $enforced) {
            $scope = $analysis['ctes'][$name] ?? null;
            if ($enforced && $scope !== null
                && self::hasCampusHierarchy($scope, $campus, true)
                && self::hasFinalCampusInstanceJoin($analysis, $name)) {
                return null;
            }
        }
        return self::GUIDANCE['physical_item_eligibility'];
    }

    private static function hasPositivePhysicalQuantity(array $scope, string $poLineAlias): bool
    {
        foreach (($scope['predicates']['literalPredicates'] ?? []) as $predicate) {
            if (($predicate['column'] ?? null) === $poLineAlias . '.cost__quantity_physical'
                && ($predicate['operator'] ?? null) === '>'
                && ($predicate['values'] ?? []) === ['0']
                && empty($predicate['negated'])
                && self::isEnforcingFact($predicate)) {
                return true;
            }
        }
        return false;
    }

    private static function validateAcquisitionUnitScope(array $analysis, array $requirement, array $contract): ?string
    {
        $expected = strtolower(trim((string)($requirement['parameters']['code'] ?? '')));
        if ($expected !== 'sc') {
            return self::GUIDANCE['acquisition_unit_scope'];
        }
        foreach (self::purchaseMeasureScopes($analysis) as $scope) {
            $poLine = self::aliasForSource($scope, 'orders.po_line__t');
            $purchaseOrder = self::aliasForSource($scope, 'orders.purchase_order__t');
            $purchaseOrderUnit = self::aliasForSource($scope, 'orders.purchase_order__t__acq_unit_ids');
            $acquisitionUnit = self::aliasForSource($scope, 'orders.acquisitions_unit__t');
            if ($poLine === null || $purchaseOrder === null || $purchaseOrderUnit === null || $acquisitionUnit === null
                || !self::hasExactColumnEquality($scope, $poLine . '.purchase_order_id', $purchaseOrder . '.id')
                || !self::hasExactColumnEquality($scope, $purchaseOrderUnit . '.id', $purchaseOrder . '.id')
                || !self::hasExactColumnEquality($scope, $acquisitionUnit . '.id', $purchaseOrderUnit . '.acq_unit_ids')) {
                continue;
            }
            foreach (($scope['predicates']['literalPredicates'] ?? []) as $predicate) {
                $leaf = self::columnLeaf((string)($predicate['column'] ?? ''));
                $values = array_map(static function ($value): string {
                    return strtolower(trim((string)$value));
                }, $predicate['values'] ?? []);
                if (self::columnQualifier((string)($predicate['column'] ?? '')) === $acquisitionUnit
                    && in_array($leaf, ['name', 'code'], true)
                    && ($predicate['operator'] ?? null) === '='
                    && $values === [$expected]
                    && empty($predicate['negated'])
                    && self::isEnforcingFact($predicate)) {
                    return null;
                }
            }
        }
        return self::GUIDANCE['acquisition_unit_scope'];
    }

    private static function purchaseMeasureScopes(array $analysis): array
    {
        $spendName = self::spendCteName($analysis);
        if ($spendName === null) {
            return [];
        }
        $spend = $analysis['ctes'][$spendName] ?? null;
        $aggregate = self::itemForAlias($spend['selectItems'] ?? [], 'physical_copies_purchased')['exactAggregate'] ?? null;
        if ($spend === null || !isset($aggregate['column'])) {
            return [];
        }
        $scopes = [];
        self::collectColumnLineageScopes($spend, (string)$aggregate['column'], $analysis, [], $scopes);
        return $scopes;
    }

    private static function collectColumnLineageScopes(
        array $scope,
        string $column,
        array $analysis,
        array $visited,
        array &$scopes
    ): void {
        $scopes[] = $scope;
        $binding = self::resolveColumnBinding($scope, $column);
        $name = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
        if ($name === '' || isset($visited[$name]) || !isset($analysis['ctes'][$name])) {
            return;
        }
        $visited[$name] = true;
        $sourceScope = $analysis['ctes'][$name];
        $sourceColumn = self::expressionForAlias($sourceScope['selectItems'] ?? [], self::columnLeaf($column));
        if ($sourceColumn !== null) {
            self::collectColumnLineageScopes($sourceScope, $sourceColumn, $analysis, $visited, $scopes);
        }
    }

    private static function validateGovernedFilters(array $analysis, array $requirement, array $contract): ?string
    {
        $permitted = $contract['permittedFilters'] ?? [];
        $requiredMaterialValue = self::hasFilterProvenance($permitted, 'material_type', 'explicit_prompt')
            ? strtolower(trim((string)($permitted['material_type']['value'] ?? '')))
            : '';
        $materialValueEnforced = false;
        foreach (self::reachableContributingScopes($analysis) as $scopeName => $scope) {
            foreach (($scope['predicates']['literalPredicates'] ?? []) as $predicate) {
                $column = (string)($predicate['column'] ?? '');
                $columnSource = self::columnSource($scope, $column);
                $isMaterialFilter = strpos(self::columnLeaf($column), 'material_type') !== false
                    || $columnSource === 'inventory.material_type__t';
                if ($isMaterialFilter) {
                    $permission = $permitted['material_type'] ?? [];
                    $expected = strtolower((string)($permission['value'] ?? ''));
                    $actual = array_map('strtolower', $predicate['values'] ?? []);
                    if (!self::hasFilterProvenance($permitted, 'material_type', 'explicit_prompt')
                        || $expected !== '' && $actual !== [$expected]) {
                        return self::GUIDANCE['governed_filters'];
                    }
                    if ($requiredMaterialValue !== ''
                        && $columnSource === 'inventory.material_type__t'
                        && self::columnLeaf($column) === 'name'
                        && in_array($predicate['operator'] ?? null, ['=', 'IN'], true)
                        && empty($predicate['negated'])
                        && self::isEnforcingFact($predicate)
                        && $actual === [$requiredMaterialValue]
                        && self::isPurchaseMaterialCohort($scopeName, $scope, $analysis, $contract)) {
                        $materialValueEnforced = true;
                    }
                }
                if ((strpos($column, 'acquisition_unit') !== false || strpos($column, 'acquisitions_unit') !== false)
                    && !self::hasFilterProvenance($permitted, 'acquisition_unit', 'explicit_prompt')
                    && !self::hasFilterProvenance($permitted, 'acquisition_unit', 'reporting_policy')) {
                    return self::GUIDANCE['governed_filters'];
                }
                if (self::columnLeaf($column) === 'cost__quantity_physical'
                    && !self::hasFilterProvenance($permitted, 'physical_resource', 'reporting_policy')) {
                    return self::GUIDANCE['governed_filters'];
                }
            }
        }
        return $requiredMaterialValue === '' || $materialValueEnforced
            ? null : self::GUIDANCE['governed_filters'];
    }

    private static function isPurchaseMaterialCohort(
        string $scopeName,
        array $scope,
        array $analysis,
        array $contract
    ): bool {
        $campus = strtolower((string)($contract['permittedFilters']['campus']['value'] ?? ''));
        $enforcement = self::reachableCteEnforcement($analysis);
        return $scopeName !== ''
            && $campus !== ''
            && !empty($enforcement[$scopeName])
            && self::hasCampusHierarchy($scope, $campus, true)
            && self::hasFinalCampusInstanceJoin($analysis, $scopeName);
    }

    private static function validateNumericOutputTypes(array $analysis, array $requirement, array $contract): ?string
    {
        $recognized = [
            'purchase_count', 'physical_copies_purchased', 'distinct_titles',
            'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout',
            'exact_linked_copies', 'fallback_linked_copies', 'fallback_percentage',
        ];
        $returned = array_column($analysis['selectItems'] ?? [], 'alias');
        $measures = array_values(array_intersect($recognized, $returned));
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

    private static function estimatedEligibilityCte(array $analysis, ?string $dateBasis): ?array
    {
        if (!in_array($dateBasis, ['payment_date', 'invoice_date'], true)) {
            return null;
        }
        $spend = self::spendCte($analysis);
        $poLine = self::aliasForSource($spend ?? [], 'orders.po_line__t');
        if ($spend === null || $poLine === null || ($spend['tables'] ?? []) !== ['orders.po_line__t']) {
            return null;
        }
        $candidates = [];
        foreach (($spend['sourceAliases'] ?? []) as $alias => $binding) {
            if (($binding['kind'] ?? null) !== 'cte') {
                continue;
            }
            $name = (string)($binding['source'] ?? '');
            $eligibility = $analysis['ctes'][$name] ?? null;
            if ($eligibility === null
                || !self::hasEnforcingJoinedEquality(
                    $spend,
                    $alias . '.po_line_id',
                    $poLine . '.id',
                    (string)$alias,
                    $name
                )) {
                continue;
            }
            $invoiceLine = self::aliasForSource($eligibility, 'invoice.invoice_lines__t');
            $invoice = self::aliasForSource($eligibility, 'invoice.invoices__t');
            if ($invoiceLine === null || $invoice === null
                || self::expressionForAlias($eligibility['selectItems'] ?? [], 'po_line_id') !== $invoiceLine . '.po_line_id'
                || ($eligibility['groupBy'] ?? []) !== [$invoiceLine . '.po_line_id']
                || !self::hasExactColumnEquality($eligibility, $invoice . '.id', $invoiceLine . '.invoice_id')
                || self::qualifyingWindow($eligibility, $dateBasis) === null) {
                continue;
            }
            $candidates[] = $eligibility;
        }
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private static function hasEnforcingJoinedEquality(
        array $scope,
        string $left,
        string $right,
        string $joinedAlias,
        string $joinedSource
    ): bool {
        $registeredJoin = false;
        foreach (($scope['joins'] ?? []) as $join) {
            if (($join['type'] ?? null) === 'INNER'
                && ($join['sourceKind'] ?? null) === 'cte'
                && ($join['alias'] ?? null) === $joinedAlias
                && ($join['source'] ?? null) === $joinedSource) {
                $registeredJoin = true;
                break;
            }
        }
        if (!$registeredJoin) {
            return false;
        }
        foreach (($scope['predicates']['columnComparisons'] ?? []) as $comparison) {
            $actual = [$comparison['left'] ?? null, $comparison['right'] ?? null];
            if (($comparison['operator'] ?? null) === '='
                && ($comparison['origin'] ?? null) === 'join_on'
                && ($comparison['joinType'] ?? null) === 'INNER'
                && ($comparison['joinedAlias'] ?? null) === $joinedAlias
                && ($comparison['joinedSourceKind'] ?? null) === 'cte'
                && ($comparison['joinedSource'] ?? null) === $joinedSource
                && ($actual === [$left, $right] || $actual === [$right, $left])) {
                return true;
            }
        }
        return false;
    }

    private static function spendCte(array $analysis): ?array
    {
        $name = self::spendCteName($analysis);
        return $name === null ? null : ($analysis['ctes'][$name] ?? null);
    }

    private static function spendCteName(array $analysis): ?string
    {
        $name = self::finalMeasureCteName($analysis, 'spend');
        $cte = $name === null ? null : ($analysis['ctes'][$name] ?? null);
        return $cte !== null
            && (self::containsTable($cte['tables'] ?? [], 'po_line')
                || self::containsTable($cte['tables'] ?? [], 'invoice_lines')
                || self::hasTableLineage($name, $analysis['ctes'] ?? [], ['po_line', 'invoice_lines'], []))
            ? $name : null;
    }

    private static function hasTableLineage(string $name, array $ctes, array $needles, array $visited): bool
    {
        if (isset($visited[$name]) || !isset($ctes[$name])) {
            return false;
        }
        $visited[$name] = true;
        foreach ($needles as $needle) {
            if (self::containsTable($ctes[$name]['tables'] ?? [], $needle)) {
                return true;
            }
        }
        foreach (($ctes[$name]['dependencies'] ?? []) as $dependency) {
            if (self::hasTableLineage($dependency, $ctes, $needles, $visited)) {
                return true;
            }
        }
        return false;
    }

    private static function hasValidPurchaseMeasure(array $analysis, string $measure): bool
    {
        $spendName = self::spendCteName($analysis);
        $spend = $spendName === null ? null : ($analysis['ctes'][$spendName] ?? null);
        $aggregate = self::itemForAlias($spend['selectItems'] ?? [], $measure)['exactAggregate'] ?? null;
        if ($spend === null || self::finalMeasureCteName($analysis, $measure) !== $spendName) {
            return false;
        }
        if ($measure === 'physical_copies_purchased') {
            return ($aggregate['function'] ?? null) === 'sum'
                && isset($aggregate['column'])
                && self::columnLeaf((string)$aggregate['column']) === 'allocated_physical_copies'
                && self::hasPreaggregatedInvoiceQuantity($spend, (string)$aggregate['column'], $analysis)
                && self::hasTrustedPhysicalAllocation($spend, (string)$aggregate['column'], $analysis)
                && self::hasPhysicalAllocationPartition($spend, (string)$aggregate['column'], $analysis);
        }
        return $measure === 'purchase_count'
            && ($aggregate['function'] ?? null) === 'count'
            && !empty($aggregate['distinct'])
            && self::columnLeaf((string)($aggregate['column'] ?? '')) === 'id'
            && self::columnSource($spend, (string)$aggregate['column']) === 'orders.po_line__t';
    }

    private static function purchaseRankingMeasure(array $contract): string
    {
        foreach (($contract['requirements'] ?? []) as $requirement) {
            if (($requirement['key'] ?? null) === 'purchase_ranking') {
                return (string)($requirement['parameters']['measure'] ?? 'purchase_count');
            }
        }
        return 'purchase_count';
    }

    private static function hasTrustedPhysicalAllocation(array $scope, string $column, array $analysis): bool
    {
        $binding = self::resolveColumnBinding($scope, $column);
        $allocationName = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
        $allocation = $analysis['ctes'][$allocationName] ?? null;
        if ($allocation === null) {
            return false;
        }
        $poLine = self::aliasForSource($allocation, 'orders.po_line__t');
        $piece = self::aliasForSource($allocation, 'orders.pieces__t');
        $item = self::aliasForSource($allocation, 'inventory.item__t');
        $quantityExpression = self::expressionForAlias($allocation['selectItems'] ?? [], 'quantity');
        $fundedAlias = $quantityExpression === null ? '' : self::columnQualifier($quantityExpression);
        $fundedBinding = $fundedAlias === '' ? null : self::resolveQualifier($allocation, $fundedAlias);
        if ($poLine === null || ($fundedBinding['kind'] ?? null) !== 'cte'
            || !self::hasEnforcingColumnEquality($allocation, $fundedAlias . '.po_line_id', $poLine . '.id')) {
            return false;
        }
        $hasReceivingPieceLink = $piece !== null && $item !== null
            && self::hasEnforcingColumnEquality($allocation, $piece . '.po_line_id', $poLine . '.id')
            && self::hasEnforcingColumnEquality($allocation, $item . '.id', $piece . '.item_id');
        $hasDirectItemLink = $item !== null
            && self::hasEnforcingColumnEquality($allocation, $item . '.purchase_order_line_identifier', $poLine . '.id');
        return $hasReceivingPieceLink || $hasDirectItemLink;
    }

    private static function hasPreaggregatedInvoiceQuantity(array $scope, string $column, array $analysis): bool
    {
        $allocationBinding = self::resolveColumnBinding($scope, $column);
        $allocationName = ($allocationBinding['kind'] ?? null) === 'cte'
            ? (string)($allocationBinding['source'] ?? '') : '';
        $allocation = $analysis['ctes'][$allocationName] ?? null;
        $quantityExpression = self::expressionForAlias($allocation['selectItems'] ?? [], 'quantity');
        $fundedBinding = $quantityExpression === null ? null : self::resolveColumnBinding($allocation, $quantityExpression);
        $fundedName = ($fundedBinding['kind'] ?? null) === 'cte' ? (string)($fundedBinding['source'] ?? '') : '';
        $funded = $analysis['ctes'][$fundedName] ?? null;
        if ($funded === null) {
            return false;
        }
        return $funded !== null && self::isPreaggregatedInvoiceScope($funded);
    }

    private static function fundedInvoiceLineScope(array $analysis): ?array
    {
        $spend = self::spendCte($analysis);
        $aggregate = self::itemForAlias($spend['selectItems'] ?? [], 'physical_copies_purchased')['exactAggregate'] ?? null;
        $allocationBinding = isset($aggregate['column']) ? self::resolveColumnBinding($spend, (string)$aggregate['column']) : null;
        $allocationName = ($allocationBinding['kind'] ?? null) === 'cte'
            ? (string)($allocationBinding['source'] ?? '') : '';
        $allocation = $analysis['ctes'][$allocationName] ?? null;
        $quantity = self::expressionForAlias($allocation['selectItems'] ?? [], 'quantity');
        $fundedBinding = $quantity === null ? null : self::resolveColumnBinding($allocation, $quantity);
        $fundedName = ($fundedBinding['kind'] ?? null) === 'cte' ? (string)($fundedBinding['source'] ?? '') : '';
        $funded = $analysis['ctes'][$fundedName] ?? null;
        return $funded !== null && self::isPreaggregatedInvoiceScope($funded) ? $funded : null;
    }

    private static function hasPhysicalAllocationPartition(array $spend, string $column, array $analysis): bool
    {
        $binding = self::resolveColumnBinding($spend, $column);
        $allocationName = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
        $allocation = $analysis['ctes'][$allocationName] ?? null;
        $quantity = self::expressionForAlias($allocation['selectItems'] ?? [], 'quantity');
        $item = self::aliasForSource($allocation ?? [], 'inventory.item__t');
        if ($allocation === null || $quantity === null || $item === null
            || !self::hasCampusHierarchy($allocation, 'smith college', false)) {
            return false;
        }
        $eligibleCount = 'count(distinct' . $item . '.id)';
        $exact = 'least(' . self::compactExpression($quantity) . ',' . $eligibleCount . ')';
        $fallback = 'greatest(' . self::compactExpression($quantity) . '-' . $exact . ',0)';
        if (self::compactExpression((string)self::expressionForAlias($allocation['selectItems'] ?? [], 'exact_linked_copies')) !== $exact
            || self::compactExpression((string)self::expressionForAlias($allocation['selectItems'] ?? [], 'fallback_linked_copies')) !== $fallback
            || self::compactExpression((string)self::expressionForAlias($allocation['selectItems'] ?? [], 'allocated_physical_copies')) !== $exact . '+' . $fallback) {
            return false;
        }
        $spendName = self::spendCteName($analysis);
        if ($spendName === null
            || self::finalMeasureCteName($analysis, 'exact_linked_copies') !== $spendName
            || self::finalMeasureCteName($analysis, 'fallback_linked_copies') !== $spendName) {
            return false;
        }
        $percentage = self::itemForAlias($analysis['selectItems'] ?? [], 'fallback_percentage')['division'] ?? null;
        $numerator = $percentage['numeratorAggregate'] ?? null;
        $denominator = $percentage['denominatorAggregate'] ?? null;
        return ($numerator['function'] ?? null) === 'sum'
            && self::columnLeaf((string)($numerator['column'] ?? '')) === 'fallback_linked_copies'
            && ($denominator['function'] ?? null) === 'sum'
            && self::columnLeaf((string)($denominator['column'] ?? '')) === 'physical_copies_purchased'
            && self::resolveColumnBinding($analysis, (string)$numerator['column']) === ['kind' => 'cte', 'source' => $spendName]
            && self::resolveColumnBinding($analysis, (string)$denominator['column']) === ['kind' => 'cte', 'source' => $spendName];
    }

    private static function compactExpression(string $expression): string
    {
        return preg_replace('/\s+/', '', strtolower($expression));
    }

    private static function isPreaggregatedInvoiceScope(array $funded): bool
    {
        $invoiceLine = self::aliasForSource($funded, 'invoice.invoice_lines__t');
        $distribution = self::aliasForSource($funded, 'invoice.invoice_lines__t__fund_distributions');
        if ($invoiceLine === null || $distribution === null
            || self::expressionForAlias($funded['selectItems'] ?? [], 'invoice_line_id') !== $invoiceLine . '.id'
            || self::expressionForAlias($funded['selectItems'] ?? [], 'po_line_id') !== $invoiceLine . '.po_line_id'
            || self::expressionForAlias($funded['selectItems'] ?? [], 'quantity') !== $invoiceLine . '.quantity'
            || self::expressionForAlias($funded['selectItems'] ?? [], 'currency') !== $invoiceLine . '.currency'
            || ($funded['groupBy'] ?? []) !== [
                $invoiceLine . '.id',
                $invoiceLine . '.po_line_id',
                $invoiceLine . '.quantity',
                $invoiceLine . '.currency',
            ]
            || !self::hasEnforcingColumnEquality($funded, $distribution . '.id', $invoiceLine . '.id')) {
            return false;
        }
        $spend = self::itemForAlias($funded['selectItems'] ?? [], 'spend');
        return $spend !== null && !empty($spend['aggregate']);
    }

    private static function hasEnforcingColumnEquality(array $scope, string $left, string $right): bool
    {
        foreach (($scope['predicates']['columnComparisons'] ?? []) as $comparison) {
            $actual = [$comparison['left'] ?? null, $comparison['right'] ?? null];
            if (($comparison['operator'] ?? null) === '='
                && self::isEnforcingFact($comparison)
                && ($actual === [$left, $right] || $actual === [$right, $left])) {
                return true;
            }
        }
        return false;
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
        $measureCteName = self::finalMeasureCteName($analysis, 'circulation');
        $measureCte = $measureCteName === null ? null : ($analysis['ctes'][$measureCteName] ?? null);
        $aggregate = self::itemForAlias($measureCte['selectItems'] ?? [], 'circulation')['exactAggregate'] ?? null;
        if (($aggregate['function'] ?? null) !== 'sum'
            || self::columnLeaf((string)($aggregate['column'] ?? '')) !== 'checkouts') {
            return null;
        }
        $binding = self::resolveColumnBinding($measureCte, (string)$aggregate['column']);
        $itemCteName = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
        $itemCte = $analysis['ctes'][$itemCteName] ?? null;
        return $itemCte !== null && self::isValidCirculationItemCte($itemCte) ? $itemCte : null;
    }

    private static function isValidCirculationItemCte(array $cte): bool
    {
        $itemAlias = self::aliasForSource($cte, 'inventory.item__t');
        $itemId = self::expressionForAlias($cte['selectItems'] ?? [], 'item_id');
        $checkoutAggregate = self::itemForAlias($cte['selectItems'] ?? [], 'checkouts')['exactAggregate'] ?? null;
        $auditAlias = self::countedAuditAlias($cte);
        $expectedGroup = $itemAlias === null ? [] : [$itemAlias . '.id', $itemAlias . '.holdings_record_id'];
        if ($itemAlias === null || $auditAlias === null
            || $itemId !== $itemAlias . '.id' || ($cte['groupBy'] ?? []) !== $expectedGroup
            || ($checkoutAggregate['function'] ?? null) !== 'count'
            || !isset($checkoutAggregate['column'])
            || self::columnSource($cte, $checkoutAggregate['column']) !== 'circulation.audit_loan__t'
            || !self::hasJoinedColumnEquality(
                $cte['predicates']['columnComparisons'] ?? [],
                $auditAlias . '.loan__item_id',
                $itemAlias . '.id',
                $auditAlias
            )) {
            return false;
        }
        $approved = ['checkedout', 'checkedoutthroughoverride'];
        foreach (($cte['predicates']['literalPredicates'] ?? []) as $predicate) {
            $values = array_map('strtolower', $predicate['values'] ?? []);
            if (($predicate['column'] ?? null) === $auditAlias . '.loan__action'
                && self::isCountedAuditJoinFact($predicate, $auditAlias)
                && empty($predicate['negated'])
                && in_array($predicate['operator'] ?? null, ['=', 'IN'], true)
                && $values !== [] && array_diff($values, $approved) === []) {
                return true;
            }
        }
        return false;
    }

    private static function countedAuditAlias(array $scope): ?string
    {
        $aggregate = self::itemForAlias($scope['selectItems'] ?? [], 'checkouts')['exactAggregate'] ?? null;
        if (($aggregate['function'] ?? null) !== 'count' || !isset($aggregate['column'])) {
            return null;
        }
        $alias = self::columnQualifier($aggregate['column']);
        $binding = self::resolveQualifier($scope, $alias);
        return ($binding['kind'] ?? null) === 'table'
            && ($binding['source'] ?? null) === 'circulation.audit_loan__t'
            ? $alias : null;
    }

    private static function finalMeasureCteName(array $analysis, string $measure): ?string
    {
        $item = self::itemForAlias($analysis['selectItems'] ?? [], $measure);
        $aggregate = $item['exactAggregate'] ?? null;
        if (($aggregate['function'] ?? null) !== 'sum'
            || self::columnLeaf((string)($aggregate['column'] ?? '')) !== $measure) {
            return null;
        }
        $binding = self::resolveColumnBinding($analysis, (string)$aggregate['column']);
        $name = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
        return $name !== '' && isset($analysis['ctes'][$name]) ? $name : null;
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
        $binding = self::resolveColumnBinding($scope, $column);
        return ($binding['kind'] ?? null) === 'table' ? ($binding['source'] ?? null) : null;
    }

    private static function aliasForSource(array $scope, string $source): ?string
    {
        $aliases = [];
        foreach (array_keys($scope['sourceAliases'] ?? []) as $alias) {
            $binding = self::resolveQualifier($scope, (string)$alias);
            if (($binding['kind'] ?? null) === 'table' && ($binding['source'] ?? null) === $source) {
                $aliases[] = $alias;
            }
        }
        return count($aliases) === 1 ? $aliases[0] : null;
    }

    private static function hasJoinedColumnEquality(
        array $comparisons,
        string $left,
        string $right,
        string $joinedAlias
    ): bool
    {
        foreach ($comparisons as $comparison) {
            if (($comparison['operator'] ?? null) !== '='
                || !self::isCountedAuditJoinFact($comparison, $joinedAlias)) {
                continue;
            }
            $actual = [$comparison['left'] ?? null, $comparison['right'] ?? null];
            if ($actual === [$left, $right] || $actual === [$right, $left]) {
                return true;
            }
        }
        return false;
    }

    private static function isEnforcingFact(array $fact): bool
    {
        return ($fact['origin'] ?? null) === 'where'
            || (($fact['origin'] ?? null) === 'join_on' && ($fact['joinType'] ?? null) === 'INNER');
    }

    private static function isCountedAuditJoinFact(array $fact, string $auditAlias): bool
    {
        return ($fact['origin'] ?? null) === 'join_on'
            && in_array($fact['joinType'] ?? null, ['LEFT', 'INNER'], true)
            && ($fact['joinedAlias'] ?? null) === $auditAlias
            && ($fact['joinedSourceKind'] ?? null) === 'table'
            && ($fact['joinedSource'] ?? null) === 'circulation.audit_loan__t';
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
        $binding = self::resolveColumnBinding($analysis, $columns[0]);
        if (($binding['kind'] ?? null) !== 'cte') {
            return false;
        }
        $cteName = (string)($binding['source'] ?? '');
        $ctes = $analysis['ctes'] ?? [];
        if ($measure === 'spend') {
            return $cteName === self::spendCteName($analysis)
                && self::itemForAlias($ctes[$cteName]['selectItems'] ?? [], 'spend') !== null;
        }
        return $measure === 'circulation'
            && $cteName === self::finalMeasureCteName($analysis, 'circulation')
            && self::circulationItemCte($analysis) !== null;
    }

    private static function hasAggregateMeasureLineage(?array $aggregate, string $measure, array $analysis): bool
    {
        return ($aggregate['function'] ?? null) === 'sum'
            && isset($aggregate['column'])
            && self::hasMeasureLineage([$aggregate['column']], $measure, $analysis);
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

    private static function hasCallNumberLineage(string $expression, array $analysis, array $approvedDerivations): bool
    {
        if (preg_match('/^([a-z_][a-z0-9_$-]*)\.call_number_class$/', $expression, $matches) !== 1) {
            return false;
        }
        $binding = self::resolveQualifier($analysis, $matches[1]);
        $cteName = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
        $ctes = $analysis['ctes'] ?? [];
        if ($cteName === '' || !isset($ctes[$cteName])) {
            return false;
        }
        $item = self::itemForAlias($ctes[$cteName]['selectItems'] ?? [], 'call_number_class');
        if (!in_array($item['callNumberClassDerivation'] ?? null, $approvedDerivations, true)) {
            return false;
        }
        $sources = [];
        foreach (($item['referencedColumns'] ?? []) as $column) {
            if (self::columnLeaf($column) === 'effective_call_number_components__call_number') {
                $sources[] = self::columnSource($ctes[$cteName], $column);
            }
        }
        return $sources !== []
            && array_diff($sources, ['inventory.item__t', 'inventory.holdings_record__t']) === [];
    }

    private static function resolveColumnBinding(array $scope, string $column): ?array
    {
        return self::resolveQualifier($scope, self::columnQualifier($column));
    }

    private static function resolveQualifier(array $scope, string $qualifier): ?array
    {
        $binding = $scope['sourceAliases'][strtolower($qualifier)] ?? null;
        if (!is_array($binding)
            || !in_array($binding['kind'] ?? null, ['table', 'cte'], true)
            || !is_string($binding['source'] ?? null)
            || $binding['source'] === '') {
            return null;
        }
        return ['kind' => $binding['kind'], 'source' => $binding['source']];
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

    private static function reachableScopes(array $analysis): array
    {
        $scopes = [$analysis];
        foreach (self::reachableCteEnforcement($analysis) as $name => $enforced) {
            if ($enforced) {
                $scopes[] = $analysis['ctes'][$name];
            }
        }
        return $scopes;
    }

    private static function reachableContributingScopes(array $analysis): array
    {
        $scopes = ['' => $analysis];
        foreach (array_keys(self::reachableCteEnforcement($analysis)) as $name) {
            $scopes[$name] = $analysis['ctes'][$name];
        }
        return $scopes;
    }

    private static function reachableCteEnforcement(array $analysis): array
    {
        $ctes = $analysis['ctes'] ?? [];
        $pending = [];
        foreach (self::sourceEnforcement($analysis) as $alias => $enforced) {
            $binding = self::resolveQualifier($analysis, (string)$alias);
            if (($binding['kind'] ?? null) === 'cte' && isset($ctes[$binding['source'] ?? ''])) {
                $pending[] = [$binding['source'], $enforced];
            }
        }
        $reachable = [];
        while ($pending !== []) {
            [$name, $enforced] = array_shift($pending);
            if (!isset($ctes[$name]) || (($reachable[$name] ?? null) === true)) {
                continue;
            }
            $reachable[$name] = ($reachable[$name] ?? false) || $enforced;
            foreach (self::sourceEnforcement($ctes[$name]) as $alias => $sourceEnforced) {
                $binding = self::resolveQualifier($ctes[$name], (string)$alias);
                if (($binding['kind'] ?? null) === 'cte' && isset($ctes[$binding['source'] ?? ''])) {
                    $pending[] = [$binding['source'], $enforced && $sourceEnforced];
                }
            }
        }
        return $reachable;
    }

    private static function sourceEnforcement(array $scope): array
    {
        $enforcement = array_fill_keys(array_keys($scope['sourceAliases'] ?? []), true);
        foreach (($scope['joins'] ?? []) as $join) {
            $type = $join['type'] ?? null;
            if (in_array($type, ['RIGHT', 'FULL'], true)) {
                foreach (array_keys($enforcement) as $alias) {
                    $enforcement[$alias] = false;
                }
            }
            $enforcement[$join['alias']] = in_array($type, ['INNER', 'RIGHT'], true);
        }
        return $enforcement;
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

    private static function investmentCostBasis(array $contract): ?string
    {
        foreach (($contract['requirements'] ?? []) as $requirement) {
            if (($requirement['key'] ?? null) === 'investment_cost_basis') {
                $basis = $requirement['parameters']['value'] ?? null;
                return in_array($basis, ['actual_paid_fund_distribution', 'estimated_po_line_price'], true)
                    ? $basis : null;
            }
        }
        return null;
    }

    private static function qualifyingWindow(array $scope, string $expectedColumn): ?array
    {
        $isCheckoutWindow = in_array($expectedColumn, ['created_date', 'loan__loan_date'], true);
        $expectedSource = $isCheckoutWindow
            ? 'circulation.audit_loan__t' : 'invoice.invoices__t';
        $auditAlias = $isCheckoutWindow ? self::countedAuditAlias($scope) : null;
        $facts = array_values(array_filter(
            $scope['predicates']['dateWindows'] ?? [],
            static function (array $fact) use ($scope, $expectedColumn, $expectedSource, $auditAlias): bool {
                $column = (string)($fact['column'] ?? '');
                $enforced = in_array($expectedColumn, ['created_date', 'loan__loan_date'], true)
                    ? $auditAlias !== null
                        && self::columnQualifier($column) === $auditAlias
                        && self::isCountedAuditJoinFact($fact, $auditAlias)
                    : self::isEnforcingFact($fact);
                return self::columnLeaf($column) === $expectedColumn
                    && self::columnSource($scope, $column) === $expectedSource
                    && $enforced;
            }
        ));
        if (count($facts) !== 1
            || ($facts[0]['operator'] ?? null) !== '>='
            || ($facts[0]['expression'] ?? null) !== 'current_date - interval 5 years') {
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
            'physical_item_eligibility' => 'physical_cohort_mismatch',
            'acquisition_unit_scope' => 'scope_mismatch',
            'governed_filters' => 'unrequested_filter',
            'numeric_output_types' => 'output_type_mismatch',
        ];
        return $categories[$key] ?? 'semantic_coverage_gap';
    }
}
