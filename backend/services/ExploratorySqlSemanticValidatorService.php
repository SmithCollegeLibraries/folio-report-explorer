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
        'currency_separation' => 'validateCurrencySeparation',
        'governed_filters' => 'validateGovernedFilters',
        'numeric_output_types' => 'validateNumericOutputTypes',
        'organization_interface_relationship' => 'validateOrganizationInterfaceRelationship',
        'organization_acquisition_unit_relationship' => 'validateOrganizationAcquisitionUnitRelationship',
        'organization_acquisition_unit_code' => 'validateOrganizationAcquisitionUnitCode',
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
        'currency_separation' => 'Keep unlike invoice currencies in separate ROI groups.',
        'governed_filters' => 'Remove filters that were not permitted by the request contract.',
        'numeric_output_types' => 'Return analytical measures as numeric values without display formatting.',
        'organization_interface_relationship' => 'Join organization interfaces through organizations.organizations__t__interfaces.interfaces = organizations.interfaces__t.id.',
        'organization_acquisition_unit_relationship' => 'Scope organizations through organizations.organizations__t__acq_unit_ids.acq_unit_ids = orders.acquisitions_unit__t.id.',
        'organization_acquisition_unit_code' => 'Match the requested acquisition-unit code exactly in WHERE or an INNER JOIN.',
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
        $unsupportedOrganizationCteShape =
            ($contract['concept'] ?? null) === 'organization_acquisition_unit_scope'
            && ($analysis['ctes'] ?? []) !== [];
        $checked = [];
        $violations = [];
        foreach (($contract['requirements'] ?? []) as $requirement) {
            $rule = (string)($requirement['rule'] ?? '');
            if (!isset(self::RULE_METHODS[$rule])
                || !empty($analysis['ambiguous'])
                || $unsupportedOrganizationCteShape) {
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

    private static function validateOrganizationInterfaceRelationship(
        array $analysis,
        array $requirement,
        array $contract
    ): ?string {
        foreach (self::reachableScopes($analysis) as $scope) {
            if (self::hasOrganizationInterfaceRelationship($scope)) {
                return null;
            }
        }
        return self::GUIDANCE['organization_interface_relationship'];
    }

    private static function validateOrganizationAcquisitionUnitRelationship(
        array $analysis,
        array $requirement,
        array $contract
    ): ?string {
        $requiresInterface = self::contractHasRequirement(
            $contract,
            'organization_interface_relationship'
        );
        foreach (self::reachableScopes($analysis) as $scope) {
            if (self::hasOrganizationAcquisitionRelationship(
                $scope,
                $requiresInterface
            )) {
                return null;
            }
        }
        return self::GUIDANCE['organization_acquisition_unit_relationship'];
    }

    private static function validateOrganizationAcquisitionUnitCode(
        array $analysis,
        array $requirement,
        array $contract
    ): ?string {
        $expected = (string)($requirement['parameters']['code'] ?? '');
        if ($expected === '') {
            return self::GUIDANCE['organization_acquisition_unit_code'];
        }
        $requiresInterface = self::contractHasRequirement(
            $contract,
            'organization_interface_relationship'
        );
        foreach (self::reachableScopes($analysis) as $scope) {
            if (!self::hasOrganizationAcquisitionRelationship(
                $scope,
                $requiresInterface
            )) {
                continue;
            }
            $unit = self::aliasForSource($scope, 'orders.acquisitions_unit__t');
            foreach (($scope['predicates']['literalPredicates'] ?? []) as $predicate) {
                if (($predicate['column'] ?? null) === $unit . '.name'
                    && ($predicate['operator'] ?? null) === '='
                    && ($predicate['values'] ?? []) === [$expected]
                    && empty($predicate['negated'])
                    && self::isEnforcingFact($predicate)) {
                    return null;
                }
            }
        }
        return self::GUIDANCE['organization_acquisition_unit_code'];
    }

    private static function contractHasRequirement(array $contract, string $key): bool
    {
        foreach (($contract['requirements'] ?? []) as $requirement) {
            if (($requirement['key'] ?? null) === $key) {
                return true;
            }
        }
        return false;
    }

    private static function hasOrganizationInterfaceRelationship(array $scope): bool
    {
        $organization = self::aliasForSource(
            $scope,
            'organizations.organizations__t'
        );
        $interface = self::aliasForSource($scope, 'organizations.interfaces__t');
        $bridge = self::aliasForSource(
            $scope,
            'organizations.organizations__t__interfaces'
        );
        if ($interface === null || $bridge === null
            || !self::hasEnforcingColumnEquality(
                $scope,
                $interface . '.id',
                $bridge . '.interfaces'
            )) {
            return false;
        }
        return $organization === null
            || !self::hasEnforcingColumnEquality(
                $scope,
                $interface . '.id',
                $organization . '.id'
            );
    }

    private static function hasOrganizationAcquisitionRelationship(
        array $scope,
        bool $requiresInterface
    ): bool {
        $organization = self::aliasForSource(
            $scope,
            'organizations.organizations__t'
        );
        $interfaceBridge = self::aliasForSource(
            $scope,
            'organizations.organizations__t__interfaces'
        );
        $unitBridge = self::aliasForSource(
            $scope,
            'organizations.organizations__t__acq_unit_ids'
        );
        $unit = self::aliasForSource($scope, 'orders.acquisitions_unit__t');
        if ($unitBridge === null || $unit === null
            || ($requiresInterface
                && !self::hasOrganizationInterfaceRelationship($scope))
            || !self::hasEnforcingColumnEquality(
                $scope,
                $unit . '.id',
                $unitBridge . '.acq_unit_ids'
            )) {
            return false;
        }

        $connectedToOrganization = $organization !== null
            && self::hasEnforcingColumnEquality(
                $scope,
                $unitBridge . '.id',
                $organization . '.id'
            );
        $connectedToInterfaceBridge = $interfaceBridge !== null
            && self::hasEnforcingColumnEquality(
                $scope,
                $unitBridge . '.id',
                $interfaceBridge . '.id'
            );
        $bothConnectedToOrganization = $organization !== null
            && $interfaceBridge !== null
            && $connectedToOrganization
            && self::hasEnforcingColumnEquality(
                $scope,
                $interfaceBridge . '.id',
                $organization . '.id'
            );
        if ($requiresInterface) {
            return $connectedToInterfaceBridge || $bothConnectedToOrganization;
        }
        return $connectedToOrganization;
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
        $expectedGroupCount = !empty($contract['reportPolicy']['physicalOnly']) ? 2 : 1;
        if (count($groupBy) !== $expectedGroupCount) {
            return self::GUIDANCE['call_number_grouping'];
        }
        foreach (($analysis['selectItems'] ?? []) as $item) {
            $expression = strtolower((string)($item['expression'] ?? ''));
            $alias = strtolower((string)($item['alias'] ?? ''));
            if (self::expressionLeaf($expression) === 'call_number_class'
                && ($groupBy[0] === $expression || ($alias !== '' && $groupBy[0] === $alias))
                && (self::hasCallNumberLineage($expression, $analysis, $expectedDerivations[$basis])
                    || self::hasRecursiveCallNumberLineage($expression, $analysis, $expectedDerivations[$basis]))) {
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
        if ($circulation === null) {
            return self::GUIDANCE['campus_scope'];
        }
        if ($expected === 'smith college' && self::hasRecursivePhysicalAllocation($analysis)) {
            return null;
        }
        if (!self::hasCampusHierarchy($circulation, $expected, false)) {
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
            if ($campus === 'smith college' && self::hasRecursivePhysicalAllocation($analysis)) {
                return null;
            }
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
        return $campus === 'smith college' && self::hasRecursivePhysicalAllocation($analysis)
            ? null : self::GUIDANCE['physical_item_eligibility'];
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
        if (self::hasRecursivePhysicalAllocation($analysis)) {
            return null;
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

    private static function validateCurrencySeparation(array $analysis, array $requirement, array $contract): ?string
    {
        if (($requirement['parameters']['value'] ?? null) !== 'invoice_currency') {
            return self::GUIDANCE['currency_separation'];
        }
        if (self::hasRecursiveCurrencySeparation($analysis)) {
            return null;
        }
        $spendName = self::spendCteName($analysis);
        $spend = $spendName === null ? null : ($analysis['ctes'][$spendName] ?? null);
        $physical = self::itemForAlias($spend['selectItems'] ?? [], 'physical_copies_purchased')['exactAggregate'] ?? null;
        $allocationBinding = isset($physical['column']) ? self::resolveColumnBinding($spend, (string)$physical['column']) : null;
        $allocationName = ($allocationBinding['kind'] ?? null) === 'cte' ? (string)($allocationBinding['source'] ?? '') : '';
        $allocation = $analysis['ctes'][$allocationName] ?? null;
        $allocationAlias = self::columnQualifier((string)($physical['column'] ?? ''));
        $quantity = self::expressionForAlias($allocation['selectItems'] ?? [], 'quantity');
        $fundedAlias = $quantity === null ? '' : self::columnQualifier($quantity);
        if ($spend === null || $allocation === null || self::fundedInvoiceLineScope($analysis) === null
            || self::expressionForAlias($allocation['selectItems'] ?? [], 'currency') !== $fundedAlias . '.currency'
            || !in_array($fundedAlias . '.currency', $allocation['groupBy'] ?? [], true)
            || self::expressionForAlias($spend['selectItems'] ?? [], 'currency') !== $allocationAlias . '.currency'
            || !in_array($allocationAlias . '.currency', $spend['groupBy'] ?? [], true)) {
            return self::GUIDANCE['currency_separation'];
        }
        $finalPhysical = self::itemForAlias($analysis['selectItems'] ?? [], 'physical_copies_purchased')['exactAggregate'] ?? null;
        $spendAlias = self::columnQualifier((string)($finalPhysical['column'] ?? ''));
        return self::expressionForAlias($analysis['selectItems'] ?? [], 'currency') === $spendAlias . '.currency'
            && in_array($spendAlias . '.currency', $analysis['groupBy'] ?? [], true)
            ? null : self::GUIDANCE['currency_separation'];
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
            && (self::hasFinalCampusInstanceJoin($analysis, $scopeName)
                || self::hasRecursivePhysicalAllocation($analysis));
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
            $shallow = ($aggregate['function'] ?? null) === 'sum'
                && isset($aggregate['column'])
                && self::columnLeaf((string)$aggregate['column']) === 'allocated_physical_copies'
                && self::hasPreaggregatedInvoiceQuantity($spend, (string)$aggregate['column'], $analysis)
                && self::hasTrustedPhysicalAllocation($spend, (string)$aggregate['column'], $analysis)
                && self::hasPhysicalAllocationPartition($spend, (string)$aggregate['column'], $analysis);
            return $shallow || self::hasRecursivePhysicalAllocation($analysis);
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

    private static function hasRecursivePhysicalAllocation(array $analysis): bool
    {
        $steps = self::measureColumnLineage($analysis, 'physical_copies_purchased');
        if (count($steps) < 3) {
            return false;
        }
        $spendStep = $steps[0];
        $allocationStep = $steps[count($steps) - 1];
        $spend = $spendStep['scope'];
        $allocation = $allocationStep['scope'];
        $allocatedItem = self::itemForAlias($spend['selectItems'] ?? [], 'physical_copies_purchased');
        $allocatedAggregate = $allocatedItem['exactAggregate'] ?? null;
        $allocatedAlias = self::columnQualifier((string)($allocatedAggregate['column'] ?? ''));
        $instanceExpression = self::expressionForAlias($spend['selectItems'] ?? [], 'instance_id');
        $instanceLeaf = $instanceExpression === null ? null : self::columnLeaf($instanceExpression);
        if (($allocatedAggregate['function'] ?? null) !== 'sum'
            || self::columnLeaf((string)($allocatedAggregate['column'] ?? '')) !== 'allocated_physical_copies'
            || !in_array($instanceLeaf, ['instance_id', 'resolved_instance_id'], true)
            || $instanceExpression !== $allocatedAlias . '.' . $instanceLeaf
            || self::expressionForAlias($spend['selectItems'] ?? [], 'currency') !== $allocatedAlias . '.currency'
            || ($spend['groupBy'] ?? []) !== [$instanceExpression, $allocatedAlias . '.currency']) {
            return false;
        }
        foreach (['spend', 'exact_linked_copies', 'fallback_linked_copies'] as $measure) {
            $aggregate = self::itemForAlias($spend['selectItems'] ?? [], $measure)['exactAggregate'] ?? null;
            if (($aggregate['function'] ?? null) !== 'sum'
                || ($aggregate['column'] ?? null) !== $allocatedAlias . '.' . $measure) {
                return false;
            }
        }

        $quantity = self::expressionForAlias($allocation['selectItems'] ?? [], 'quantity');
        $paidAlias = $quantity === null ? '' : self::columnQualifier($quantity);
        $paidBinding = $paidAlias === '' ? null : self::resolveQualifier($allocation, $paidAlias);
        $paidName = ($paidBinding['kind'] ?? null) === 'cte' ? (string)($paidBinding['source'] ?? '') : '';
        $paid = $analysis['ctes'][$paidName] ?? null;
        if ($paid === null || !self::isPaidPhysicalPoLineScope($paid, $analysis)) {
            return false;
        }

        $exactExpression = self::compactExpression((string)self::expressionForAlias(
            $allocation['selectItems'] ?? [],
            'exact_linked_copies'
        ));
        if (preg_match('/^least\(' . preg_quote(self::compactExpression($quantity), '/')
            . ',coalesce\(([a-z_][a-z0-9_$-]*)\.exact_item_count,0\)\)$/', $exactExpression, $matches) !== 1) {
            return false;
        }
        $exactAlias = $matches[1];
        $exactBinding = self::resolveQualifier($allocation, $exactAlias);
        $exactName = ($exactBinding['kind'] ?? null) === 'cte' ? (string)($exactBinding['source'] ?? '') : '';
        if (!self::hasExactLinkCountLineage($analysis, $exactName, $paidName)) {
            return false;
        }
        if ($instanceLeaf === 'resolved_instance_id'
            && !self::hasResolvedExactInstanceLineage(
                $analysis,
                $spend,
                $allocatedAlias,
                $allocation,
                $paidAlias,
                $exactName
            )) {
            return false;
        }
        $exactScope = $analysis['ctes'][$exactName] ?? [];
        if (!self::hasRowPreservingJoinedEquality(
            $allocation,
            $exactAlias . '.po_line_id',
            $paidAlias . '.po_line_id',
            $exactAlias,
            'cte',
            $exactName
        )) {
            return false;
        }
        if (in_array($exactAlias . '.currency', $exactScope['groupBy'] ?? [], true)
            && !self::hasRowPreservingJoinedEquality(
                $allocation,
                $exactAlias . '.currency',
                $paidAlias . '.currency',
                $exactAlias,
                'cte',
                $exactName
            )) {
            return false;
        }

        $fallbackAlias = '';
        foreach (($allocation['sourceAliases'] ?? []) as $alias => $binding) {
            $name = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
            if ($name !== '' && self::isCurrentSmithInstanceScope($analysis['ctes'][$name] ?? [], $analysis)) {
                $fallbackAlias = (string)$alias;
                break;
            }
        }
        if ($fallbackAlias === '' || !self::hasRowPreservingJoinedEquality(
            $allocation,
            $fallbackAlias . '.instance_id',
            $paidAlias . '.instance_id',
            $fallbackAlias,
            'cte',
            (string)(self::resolveQualifier($allocation, $fallbackAlias)['source'] ?? '')
        )) {
            return false;
        }
        $fallback = 'casewhen' . $fallbackAlias . '.instance_idisnotnullthengreatest('
            . self::compactExpression($quantity) . '-' . $exactExpression . ',0)else0end';
        return self::compactExpression((string)self::expressionForAlias(
            $allocation['selectItems'] ?? [],
            'fallback_linked_copies'
        )) === $fallback
            && self::compactExpression((string)self::expressionForAlias(
                $allocation['selectItems'] ?? [],
                'allocated_physical_copies'
            )) === $exactExpression . '+' . $fallback
            && self::hasFinalAllocationDiagnostics($analysis, (string)$spendStep['name']);
    }

    private static function isPaidPhysicalPoLineScope(array $scope, array $analysis): bool
    {
        $poLine = self::aliasForSource($scope, 'orders.po_line__t');
        if ($poLine === null || !self::hasPositivePhysicalQuantity($scope, $poLine)) {
            return false;
        }
        $quantity = self::itemForAlias($scope['selectItems'] ?? [], 'quantity')['exactAggregate'] ?? null;
        $spend = self::itemForAlias($scope['selectItems'] ?? [], 'spend')['exactAggregate'] ?? null;
        $currency = self::expressionForAlias($scope['selectItems'] ?? [], 'currency');
        $fundedAlias = $currency === null ? '' : self::columnQualifier($currency);
        $fundedBinding = $fundedAlias === '' ? null : self::resolveQualifier($scope, $fundedAlias);
        $fundedName = ($fundedBinding['kind'] ?? null) === 'cte' ? (string)($fundedBinding['source'] ?? '') : '';
        return isset($analysis['ctes'][$fundedName])
            && self::isPreaggregatedInvoiceScope($analysis['ctes'][$fundedName])
            && self::expressionForAlias($scope['selectItems'] ?? [], 'po_line_id') === $poLine . '.id'
            && ($quantity['function'] ?? null) === 'sum'
            && ($quantity['column'] ?? null) === $fundedAlias . '.quantity'
            && ($spend['function'] ?? null) === 'sum'
            && ($spend['column'] ?? null) === $fundedAlias . '.spend'
            && self::hasEnforcingColumnEquality($scope, $poLine . '.id', $fundedAlias . '.po_line_id')
            && self::hasAcquisitionUnitPolicy($scope, $poLine, 'sc');
    }

    private static function hasAcquisitionUnitPolicy(array $scope, string $poLine, string $expected): bool
    {
        $purchaseOrder = self::aliasForSource($scope, 'orders.purchase_order__t');
        $purchaseOrderUnit = self::aliasForSource($scope, 'orders.purchase_order__t__acq_unit_ids');
        $acquisitionUnit = self::aliasForSource($scope, 'orders.acquisitions_unit__t');
        if ($purchaseOrder === null || $purchaseOrderUnit === null || $acquisitionUnit === null
            || !self::hasEnforcingColumnEquality($scope, $poLine . '.purchase_order_id', $purchaseOrder . '.id')
            || !self::hasEnforcingColumnEquality($scope, $purchaseOrderUnit . '.id', $purchaseOrder . '.id')
            || !self::hasEnforcingColumnEquality($scope, $acquisitionUnit . '.id', $purchaseOrderUnit . '.acq_unit_ids')) {
            return false;
        }
        foreach (($scope['predicates']['literalPredicates'] ?? []) as $predicate) {
            if (self::columnQualifier((string)($predicate['column'] ?? '')) === $acquisitionUnit
                && in_array(self::columnLeaf((string)($predicate['column'] ?? '')), ['name', 'code'], true)
                && ($predicate['operator'] ?? null) === '='
                && array_map('strtolower', $predicate['values'] ?? []) === [$expected]
                && empty($predicate['negated'])
                && self::isEnforcingFact($predicate)) {
                return true;
            }
        }
        return false;
    }

    private static function hasExactLinkCountLineage(array $analysis, string $countName, string $paidName): bool
    {
        $countScope = $analysis['ctes'][$countName] ?? null;
        $aggregate = self::itemForAlias($countScope['selectItems'] ?? [], 'exact_item_count')['exactAggregate'] ?? null;
        $linkAlias = self::columnQualifier((string)($aggregate['column'] ?? ''));
        $linkBinding = $linkAlias === '' ? null : self::resolveQualifier($countScope ?? [], $linkAlias);
        $linkName = ($linkBinding['kind'] ?? null) === 'cte' ? (string)($linkBinding['source'] ?? '') : '';
        $linkScope = $analysis['ctes'][$linkName] ?? null;
        if ($countScope === null || $linkScope === null
            || ($aggregate['function'] ?? null) !== 'count'
            || empty($aggregate['distinct'])
            || ($aggregate['column'] ?? null) !== $linkAlias . '.item_id') {
            return false;
        }
        $groupBy = $countScope['groupBy'] ?? [];
        if ($groupBy === [$linkAlias . '.po_line_id', $linkAlias . '.currency']) {
            return self::hasIndexedExactLinkBranches($analysis, $linkScope, $paidName);
        }
        if ($groupBy !== [$linkAlias . '.po_line_id']) {
            return false;
        }
        $paidAlias = '';
        $eligibleAlias = '';
        $eligibleName = '';
        $piece = self::aliasForSource($linkScope, 'orders.pieces__t');
        foreach (($linkScope['sourceAliases'] ?? []) as $alias => $binding) {
            if (($binding['kind'] ?? null) !== 'cte') {
                continue;
            }
            $name = (string)($binding['source'] ?? '');
            if ($name === $paidName) {
                $paidAlias = (string)$alias;
            } elseif (self::hasCampusHierarchy($analysis['ctes'][$name] ?? [], 'smith college', true)) {
                $eligibleAlias = (string)$alias;
                $eligibleName = $name;
            }
        }
        if ($paidAlias === '' || $eligibleAlias === '' || $piece === null
            || !self::hasRegisteredJoin($linkScope, 'INNER', $eligibleAlias, 'cte', $eligibleName)
            || !self::hasRowPreservingJoinedEquality(
                $linkScope,
                $piece . '.po_line_id',
                $paidAlias . '.po_line_id',
                $piece,
                'table',
                'orders.pieces__t'
            )
            || !self::hasRowPreservingJoinedEquality(
                $linkScope,
                $piece . '.item_id',
                $eligibleAlias . '.item_id',
                $piece,
                'table',
                'orders.pieces__t'
            )) {
            return false;
        }
        $expectedItem = 'casewhen' . $piece . '.item_idisnotnullthen' . $eligibleAlias
            . '.item_idwhen' . $eligibleAlias . '.purchase_order_line_identifier=' . $paidAlias
            . '.po_line_id::textthen' . $eligibleAlias . '.item_idelsenullend';
        return self::compactExpression((string)self::expressionForAlias(
            $linkScope['selectItems'] ?? [],
            'po_line_id'
        )) === 'distinct' . $paidAlias . '.po_line_id'
            && self::compactExpression((string)self::expressionForAlias(
                $linkScope['selectItems'] ?? [],
                'item_id'
            )) === $expectedItem;
    }

    private static function hasResolvedExactInstanceLineage(
        array $analysis,
        array $spend,
        string $allocatedAlias,
        array $allocation,
        string $paidAlias,
        string $exactCountName
    ): bool {
        $allocatedBinding = self::resolveQualifier($spend, $allocatedAlias);
        $allocatedName = ($allocatedBinding['kind'] ?? null) === 'cte'
            ? (string)($allocatedBinding['source'] ?? '') : '';
        $allocated = $analysis['ctes'][$allocatedName] ?? null;
        $resolved = self::compactExpression((string)self::expressionForAlias(
            $allocated['selectItems'] ?? [],
            'resolved_instance_id'
        ));
        if ($allocated === null
            || preg_match('/^coalesce\(([a-z_][a-z0-9_$-]*)\.preferred_exact_instance_id,\1\.fallback_instance_id\)$/', $resolved, $matches) !== 1) {
            return false;
        }
        $allocationAlias = $matches[1];
        $allocationBinding = self::resolveQualifier($allocated, $allocationAlias);
        if (($allocationBinding['kind'] ?? null) !== 'cte'
            || ($allocationBinding['source'] ?? null) !== self::sourceNameForScope($analysis, $allocation)) {
            return false;
        }
        $preferredExpression = self::expressionForAlias($allocation['selectItems'] ?? [], 'preferred_exact_instance_id');
        $preferredAlias = $preferredExpression === null ? '' : self::columnQualifier($preferredExpression);
        $preferredBinding = $preferredAlias === '' ? null : self::resolveQualifier($allocation, $preferredAlias);
        $preferredName = ($preferredBinding['kind'] ?? null) === 'cte' ? (string)($preferredBinding['source'] ?? '') : '';
        $preferred = $analysis['ctes'][$preferredName] ?? null;
        if ($preferred === null
            || !self::hasRowPreservingJoinedEquality(
                $allocation,
                $preferredAlias . '.po_line_id',
                $paidAlias . '.po_line_id',
                $preferredAlias,
                'cte',
                $preferredName
            )
            || !self::hasRowPreservingJoinedEquality(
                $allocation,
                $preferredAlias . '.currency',
                $paidAlias . '.currency',
                $preferredAlias,
                'cte',
                $preferredName
            )) {
            return false;
        }
        return self::isPreferredExactInstanceScope($analysis, $preferred, $exactCountName);
    }

    private static function sourceNameForScope(array $analysis, array $target): ?string
    {
        foreach (($analysis['ctes'] ?? []) as $name => $scope) {
            if ($scope === $target) {
                return (string)$name;
            }
        }
        return null;
    }

    private static function isPreferredExactInstanceScope(array $analysis, array $preferred, string $exactCountName): bool
    {
        $rankExpression = null;
        foreach (($preferred['predicates']['literalPredicates'] ?? []) as $predicate) {
            if (self::columnLeaf((string)($predicate['column'] ?? '')) === 'instance_rank'
                && ($predicate['operator'] ?? null) === '='
                && ($predicate['values'] ?? []) === ['1']
                && self::isEnforcingFact($predicate)) {
                $rankExpression = (string)$predicate['column'];
                break;
            }
        }
        $rankAlias = $rankExpression === null ? '' : self::columnQualifier($rankExpression);
        $rankBinding = $rankAlias === '' ? null : self::resolveQualifier($preferred, $rankAlias);
        $rankName = ($rankBinding['kind'] ?? null) === 'cte' ? (string)($rankBinding['source'] ?? '') : '';
        $rank = $analysis['ctes'][$rankName] ?? null;
        if ($rank === null
            || self::expressionForAlias($preferred['selectItems'] ?? [], 'po_line_id') !== $rankAlias . '.po_line_id'
            || self::expressionForAlias($preferred['selectItems'] ?? [], 'currency') !== $rankAlias . '.currency'
            || self::expressionForAlias($preferred['selectItems'] ?? [], 'instance_id') !== $rankAlias . '.instance_id') {
            return false;
        }
        $ranked = $rank === null ? null : self::expressionForAlias($rank['selectItems'] ?? [], 'instance_rank');
        $countAlias = $rank === null ? '' : self::columnQualifier((string)self::expressionForAlias(
            $rank['selectItems'] ?? [],
            'po_line_id'
        ));
        $countBinding = $countAlias === '' ? null : self::resolveQualifier($rank ?? [], $countAlias);
        $countName = ($countBinding['kind'] ?? null) === 'cte' ? (string)($countBinding['source'] ?? '') : '';
        $count = $analysis['ctes'][$countName] ?? null;
        $aggregate = self::itemForAlias($count['selectItems'] ?? [], 'linked_item_count')['exactAggregate'] ?? null;
        $linkAlias = self::columnQualifier((string)($aggregate['column'] ?? ''));
        $linkBinding = $linkAlias === '' ? null : self::resolveQualifier($count ?? [], $linkAlias);
        $linkName = ($linkBinding['kind'] ?? null) === 'cte' ? (string)($linkBinding['source'] ?? '') : '';
        $exactCount = $analysis['ctes'][$exactCountName] ?? null;
        $exactAggregate = self::itemForAlias($exactCount['selectItems'] ?? [], 'exact_item_count')['exactAggregate'] ?? null;
        $exactLinkBinding = isset($exactAggregate['column'])
            ? self::resolveColumnBinding($exactCount, (string)$exactAggregate['column']) : null;
        return $rank !== null && $count !== null
            && self::compactExpression((string)$ranked) === 'row_number()over(partitionby' . $countAlias
                . '.po_line_id,' . $countAlias . '.currencyorderby' . $countAlias
                . '.linked_item_countdesc,' . $countAlias . '.instance_idasc)'
            && ($aggregate['function'] ?? null) === 'count'
            && !empty($aggregate['distinct'])
            && ($aggregate['column'] ?? null) === $linkAlias . '.item_id'
            && ($count['groupBy'] ?? []) === [
                $linkAlias . '.po_line_id',
                $linkAlias . '.currency',
                $linkAlias . '.instance_id',
            ]
            && ($exactLinkBinding['kind'] ?? null) === 'cte'
            && ($exactLinkBinding['source'] ?? null) === $linkName;
    }

    private static function hasIndexedExactLinkBranches(array $analysis, array $dedupe, string $paidName): bool
    {
        if (count($dedupe['sourceAliases'] ?? []) !== 2 || count($dedupe['joins'] ?? []) !== 1) {
            return false;
        }
        $aliases = array_keys($dedupe['sourceAliases']);
        $leftAlias = (string)$aliases[0];
        $rightAlias = (string)$aliases[1];
        $leftBinding = self::resolveQualifier($dedupe, $leftAlias);
        $rightBinding = self::resolveQualifier($dedupe, $rightAlias);
        $leftName = ($leftBinding['kind'] ?? null) === 'cte' ? (string)($leftBinding['source'] ?? '') : '';
        $rightName = ($rightBinding['kind'] ?? null) === 'cte' ? (string)($rightBinding['source'] ?? '') : '';
        $left = $analysis['ctes'][$leftName] ?? null;
        $right = $analysis['ctes'][$rightName] ?? null;
        if ($left === null || $right === null
            || !self::hasRegisteredJoin($dedupe, 'FULL', $rightAlias, 'cte', $rightName)) {
            return false;
        }
        foreach (['po_line_id', 'currency', 'item_id'] as $key) {
            if (!self::hasJoinedEqualityOfType(
                $dedupe,
                $rightAlias . '.' . $key,
                $leftAlias . '.' . $key,
                'FULL',
                $rightAlias,
                'cte',
                $rightName
            )
                || self::compactExpression((string)self::expressionForAlias(
                    $dedupe['selectItems'] ?? [],
                    $key
                )) !== 'coalesce(' . $leftAlias . '.' . $key . ',' . $rightAlias . '.' . $key . ')') {
                return false;
            }
        }
        if (self::compactExpression((string)self::expressionForAlias(
            $dedupe['selectItems'] ?? [],
            'instance_id'
        )) !== 'coalesce(' . $leftAlias . '.instance_id,' . $rightAlias . '.instance_id)') {
            return false;
        }
        return self::isIndexedPieceExactBranch($analysis, $left, $paidName)
                && self::isIndexedDirectExactBranch($analysis, $right, $paidName)
            || self::isIndexedPieceExactBranch($analysis, $right, $paidName)
                && self::isIndexedDirectExactBranch($analysis, $left, $paidName);
    }

    private static function isIndexedPieceExactBranch(array $analysis, array $scope, string $paidName): bool
    {
        $piece = self::aliasForSource($scope, 'orders.pieces__t');
        $bindings = self::indexedExactBranchBindings($analysis, $scope, $paidName);
        $paid = (string)($bindings['paid'] ?? '');
        $eligible = (string)($bindings['eligible'] ?? '');
        $eligibleName = (string)($bindings['eligibleName'] ?? '');
        return $piece !== null && $paid !== '' && $eligible !== ''
            && self::hasJoinedEqualityOfType(
                $scope,
                $piece . '.po_line_id',
                $paid . '.po_line_id',
                'INNER',
                $piece,
                'table',
                'orders.pieces__t'
            )
            && self::hasJoinedEqualityOfType(
                $scope,
                $eligible . '.item_id',
                $piece . '.item_id',
                'INNER',
                $eligible,
                'cte',
                $eligibleName
            )
            && self::hasIndexedBranchOutputs($scope, $paid, $eligible);
    }

    private static function isIndexedDirectExactBranch(array $analysis, array $scope, string $paidName): bool
    {
        if (self::aliasForSource($scope, 'orders.pieces__t') !== null) {
            return false;
        }
        $bindings = self::indexedExactBranchBindings($analysis, $scope, $paidName);
        $paid = (string)($bindings['paid'] ?? '');
        $eligible = (string)($bindings['eligible'] ?? '');
        $eligibleName = (string)($bindings['eligibleName'] ?? '');
        return $paid !== '' && $eligible !== ''
            && self::hasJoinedEqualityOfType(
                $scope,
                $eligible . '.purchase_order_line_identifier',
                $paid . '.po_line_id',
                'INNER',
                $eligible,
                'cte',
                $eligibleName
            )
            && self::hasTextSafeJoinedEquality(
                $scope,
                $eligible . '.purchase_order_line_identifier',
                $paid . '.po_line_id'
            )
            && self::hasIndexedBranchOutputs($scope, $paid, $eligible);
    }

    private static function hasTextSafeJoinedEquality(array $scope, string $textColumn, string $uuidColumn): bool
    {
        $expected = [
            $textColumn . '=' . $uuidColumn . '::text',
            $uuidColumn . '::text=' . $textColumn,
            $textColumn . '=cast(' . $uuidColumn . 'astext)',
            'cast(' . $uuidColumn . 'astext)=' . $textColumn,
        ];
        foreach (($scope['joins'] ?? []) as $join) {
            if (in_array(self::compactExpression((string)($join['predicate'] ?? '')), $expected, true)) {
                return true;
            }
        }
        return false;
    }

    private static function indexedExactBranchBindings(array $analysis, array $scope, string $paidName): array
    {
        $result = [];
        foreach (($scope['sourceAliases'] ?? []) as $alias => $binding) {
            if (($binding['kind'] ?? null) !== 'cte') {
                continue;
            }
            $name = (string)($binding['source'] ?? '');
            if ($name === $paidName) {
                $result['paid'] = (string)$alias;
            } elseif (self::hasCampusHierarchy($analysis['ctes'][$name] ?? [], 'smith college', true)) {
                $result['eligible'] = (string)$alias;
                $result['eligibleName'] = $name;
            }
        }
        return $result;
    }

    private static function hasIndexedBranchOutputs(array $scope, string $paid, string $eligible): bool
    {
        $expectedPoLine = 'distinct' . $paid . '.po_line_id';
        $poLineOutput = self::itemForAlias($scope['selectItems'] ?? [], 'po_line_id');
        if ($poLineOutput !== null) {
            $hasDistinctPoLine = self::compactExpression((string)($poLineOutput['expression'] ?? ''))
                === $expectedPoLine;
        } else {
            $hasDistinctPoLine = false;
            foreach (($scope['selectItems'] ?? []) as $item) {
                if (($item['alias'] ?? null) === null
                    && self::compactExpression((string)($item['expression'] ?? '')) === $expectedPoLine) {
                    $hasDistinctPoLine = true;
                    break;
                }
            }
        }
        return $hasDistinctPoLine
            && self::expressionForAlias($scope['selectItems'] ?? [], 'currency') === $paid . '.currency'
            && self::expressionForAlias($scope['selectItems'] ?? [], 'item_id') === $eligible . '.item_id'
            && self::expressionForAlias($scope['selectItems'] ?? [], 'instance_id') === $eligible . '.instance_id';
    }

    private static function hasJoinedEqualityOfType(
        array $scope,
        string $left,
        string $right,
        string $type,
        string $joinedAlias,
        string $sourceKind,
        string $source
    ): bool {
        foreach (($scope['predicates']['columnComparisons'] ?? []) as $comparison) {
            $actual = [$comparison['left'] ?? null, $comparison['right'] ?? null];
            if (($comparison['operator'] ?? null) === '='
                && ($comparison['origin'] ?? null) === 'join_on'
                && ($comparison['joinType'] ?? null) === $type
                && ($comparison['joinedAlias'] ?? null) === $joinedAlias
                && ($comparison['joinedSourceKind'] ?? null) === $sourceKind
                && ($comparison['joinedSource'] ?? null) === $source
                && ($actual === [$left, $right] || $actual === [$right, $left])) {
                return true;
            }
        }
        return false;
    }

    private static function hasRegisteredJoin(
        array $scope,
        string $type,
        string $alias,
        string $sourceKind,
        string $source
    ): bool {
        foreach (($scope['joins'] ?? []) as $join) {
            if (($join['type'] ?? null) === $type
                && ($join['alias'] ?? null) === $alias
                && ($join['sourceKind'] ?? null) === $sourceKind
                && ($join['source'] ?? null) === $source) {
                return true;
            }
        }
        return false;
    }

    private static function isCurrentSmithInstanceScope(array $scope, array $analysis): bool
    {
        $instance = self::expressionForAlias($scope['selectItems'] ?? [], 'instance_id');
        $alias = $instance === null ? '' : self::columnQualifier($instance);
        $binding = $alias === '' ? null : self::resolveQualifier($scope, $alias);
        $name = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
        return $instance === $alias . '.instance_id'
            && ($scope['groupBy'] ?? []) === [$alias . '.instance_id']
            && isset($analysis['ctes'][$name])
            && self::hasCampusHierarchy($analysis['ctes'][$name], 'smith college', true);
    }

    private static function hasFinalAllocationDiagnostics(array $analysis, string $spendName): bool
    {
        foreach (['exact_linked_copies', 'fallback_linked_copies'] as $measure) {
            if (self::finalMeasureCteName($analysis, $measure) !== $spendName) {
                return false;
            }
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
        $eligibleItem = self::eligibleExactItemBinding($allocation, $analysis);
        $eligibleAlias = (string)($eligibleItem['alias'] ?? '');
        $quantityExpression = self::expressionForAlias($allocation['selectItems'] ?? [], 'quantity');
        $fundedAlias = $quantityExpression === null ? '' : self::columnQualifier($quantityExpression);
        $fundedBinding = $fundedAlias === '' ? null : self::resolveQualifier($allocation, $fundedAlias);
        if ($poLine === null || ($fundedBinding['kind'] ?? null) !== 'cte'
            || !self::hasEnforcingColumnEquality($allocation, $fundedAlias . '.po_line_id', $poLine . '.id')) {
            return false;
        }
        $hasReceivingPieceLink = $piece !== null && $eligibleAlias !== ''
            && self::hasRowPreservingJoinedEquality(
                $allocation,
                $piece . '.po_line_id',
                $poLine . '.id',
                $piece,
                'table',
                'orders.pieces__t'
            )
            && self::hasRowPreservingJoinedEquality(
                $allocation,
                $eligibleAlias . '.id',
                $piece . '.item_id',
                $eligibleAlias,
                'cte',
                (string)($eligibleItem['source'] ?? '')
            );
        $hasDirectItemLink = $eligibleAlias !== ''
            && !empty($eligibleItem['hasPurchaseOrderLine'])
            && self::hasRowPreservingJoinedEquality(
                $allocation,
                $eligibleAlias . '.purchase_order_line_identifier',
                $poLine . '.id',
                $eligibleAlias,
                'cte',
                (string)($eligibleItem['source'] ?? '')
            )
            && self::hasTextSafeJoinedEquality(
                $allocation,
                $eligibleAlias . '.purchase_order_line_identifier',
                $poLine . '.id'
            );
        return self::hasFallbackEligibilityCohort($allocation, $poLine, $analysis)
            && ($hasReceivingPieceLink || $hasDirectItemLink);
    }

    private static function eligibleExactItemBinding(array $allocation, array $analysis): ?array
    {
        foreach (($allocation['sourceAliases'] ?? []) as $alias => $binding) {
            $name = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
            $scope = $analysis['ctes'][$name] ?? null;
            $item = $scope === null ? null : self::aliasForSource($scope, 'inventory.item__t');
            if ($scope === null || $item === null
                || !self::hasCampusHierarchy($scope, 'smith college', false)
                || self::expressionForAlias($scope['selectItems'] ?? [], 'id') !== $item . '.id') {
                continue;
            }
            return [
                'alias' => (string)$alias,
                'source' => $name,
                'hasPurchaseOrderLine' => self::expressionForAlias(
                    $scope['selectItems'] ?? [],
                    'purchase_order_line_identifier'
                ) === $item . '.purchase_order_line_identifier',
            ];
        }
        return null;
    }

    private static function hasFallbackEligibilityCohort(array $allocation, string $poLine, array $analysis): bool
    {
        foreach (($allocation['sourceAliases'] ?? []) as $alias => $binding) {
            $name = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
            $scope = $analysis['ctes'][$name] ?? null;
            if ($scope !== null
                && self::hasCampusHierarchy($scope, 'smith college', true)
                && self::hasEnforcingJoinedEquality(
                    $allocation,
                    $alias . '.instance_id',
                    $poLine . '.instance_id',
                    (string)$alias,
                    $name
                )) {
                return true;
            }
        }
        return false;
    }

    private static function hasRowPreservingJoinedEquality(
        array $scope,
        string $left,
        string $right,
        string $joinedAlias,
        string $joinedSourceKind,
        string $joinedSource
    ): bool {
        foreach (($scope['predicates']['columnComparisons'] ?? []) as $comparison) {
            $actual = [$comparison['left'] ?? null, $comparison['right'] ?? null];
            if (($comparison['operator'] ?? null) === '='
                && ($comparison['origin'] ?? null) === 'join_on'
                && ($comparison['joinType'] ?? null) === 'LEFT'
                && ($comparison['joinedAlias'] ?? null) === $joinedAlias
                && ($comparison['joinedSourceKind'] ?? null) === $joinedSourceKind
                && ($comparison['joinedSource'] ?? null) === $joinedSource
                && ($actual === [$left, $right] || $actual === [$right, $left])) {
                return true;
            }
        }
        return false;
    }

    private static function hasPreaggregatedInvoiceQuantity(array $scope, string $column, array $analysis): bool
    {
        $allocationBinding = self::resolveColumnBinding($scope, $column);
        $allocationName = ($allocationBinding['kind'] ?? null) === 'cte'
            ? (string)($allocationBinding['source'] ?? '') : '';
        $allocation = $analysis['ctes'][$allocationName] ?? null;
        $quantityExpression = self::expressionForAlias($allocation['selectItems'] ?? [], 'quantity');
        if ($allocation !== null && $quantityExpression !== null) {
            $steps = [];
            self::collectColumnLineage($allocation, $quantityExpression, $analysis, [], $steps);
            foreach ($steps as $step) {
                if (self::isPreaggregatedInvoiceScope($step['scope'])) {
                    return true;
                }
            }
        }
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
        foreach (self::measureColumnLineage($analysis, 'spend') as $step) {
            if (self::isPreaggregatedInvoiceScope($step['scope'])) {
                return $step['scope'];
            }
        }
        return null;
    }

    private static function hasPhysicalAllocationPartition(array $spend, string $column, array $analysis): bool
    {
        $binding = self::resolveColumnBinding($spend, $column);
        $allocationName = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
        $allocation = $analysis['ctes'][$allocationName] ?? null;
        $quantity = self::expressionForAlias($allocation['selectItems'] ?? [], 'quantity');
        $eligibleItem = $allocation === null ? null : self::eligibleExactItemBinding($allocation, $analysis);
        $eligibleAlias = (string)($eligibleItem['alias'] ?? '');
        if ($allocation === null || $quantity === null || $eligibleAlias === '') {
            return false;
        }
        $eligibleCount = 'count(distinct' . $eligibleAlias . '.id)';
        $exact = 'least(' . self::compactExpression($quantity) . ',' . $eligibleCount . ')';
        $fallback = 'greatest(' . self::compactExpression($quantity) . '-' . $exact . ',0)';
        if (self::compactExpression((string)self::expressionForAlias($allocation['selectItems'] ?? [], 'exact_linked_copies')) !== $exact
            || self::compactExpression((string)self::expressionForAlias($allocation['selectItems'] ?? [], 'fallback_linked_copies')) !== $fallback
            || self::compactExpression((string)self::expressionForAlias($allocation['selectItems'] ?? [], 'allocated_physical_copies')) !== $exact . '+' . $fallback) {
            return false;
        }
        $spendName = self::spendCteName($analysis);
        $spendExact = self::itemForAlias($spend['selectItems'] ?? [], 'exact_linked_copies')['exactAggregate'] ?? null;
        $spendFallback = self::itemForAlias($spend['selectItems'] ?? [], 'fallback_linked_copies')['exactAggregate'] ?? null;
        if ($spendName === null
            || ($spendExact['function'] ?? null) !== 'sum'
            || ($spendExact['column'] ?? null) !== self::columnQualifier($column) . '.exact_linked_copies'
            || ($spendFallback['function'] ?? null) !== 'sum'
            || ($spendFallback['column'] ?? null) !== self::columnQualifier($column) . '.fallback_linked_copies'
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
        $invoice = self::aliasForSource($funded, 'invoice.invoices__t');
        $distribution = self::aliasForSource($funded, 'invoice.invoice_lines__t__fund_distributions');
        if ($invoiceLine === null || $invoice === null || $distribution === null
            || self::expressionForAlias($funded['selectItems'] ?? [], 'invoice_line_id') !== $invoiceLine . '.id'
            || self::expressionForAlias($funded['selectItems'] ?? [], 'po_line_id') !== $invoiceLine . '.po_line_id'
            || self::expressionForAlias($funded['selectItems'] ?? [], 'quantity') !== $invoiceLine . '.quantity'
            || self::expressionForAlias($funded['selectItems'] ?? [], 'currency') !== $invoice . '.currency'
            || ($funded['groupBy'] ?? []) !== [
                $invoiceLine . '.id',
                $invoiceLine . '.po_line_id',
                $invoiceLine . '.quantity',
                $invoice . '.currency',
            ]
            || !self::hasEnforcingColumnEquality($funded, $distribution . '.id', $invoiceLine . '.id')
            || !self::hasEnforcingColumnEquality($funded, $invoice . '.id', $invoiceLine . '.invoice_id')) {
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
        foreach (self::measureColumnLineage($analysis, 'circulation') as $step) {
            if (self::isValidCirculationItemCte($step['scope'], $analysis)) {
                return $step['scope'];
            }
        }
        return null;
    }

    private static function isValidCirculationItemCte(array $cte, array $analysis): bool
    {
        $itemAlias = self::aliasForSource($cte, 'inventory.item__t');
        $itemId = self::expressionForAlias($cte['selectItems'] ?? [], 'item_id');
        $itemColumn = $itemAlias === null ? null : $itemAlias . '.id';
        if ($itemAlias === null && $itemId !== null) {
            $itemAlias = self::columnQualifier($itemId);
            $binding = self::resolveQualifier($cte, $itemAlias);
            $source = ($binding['kind'] ?? null) === 'cte'
                ? ($analysis['ctes'][(string)($binding['source'] ?? '')] ?? null) : null;
            $sourceItem = $source === null ? null : self::aliasForSource($source, 'inventory.item__t');
            if ($source === null || $sourceItem === null
                || !self::hasCampusHierarchy($source, 'smith college', true)
                || self::expressionForAlias($source['selectItems'] ?? [], 'item_id') !== $sourceItem . '.id') {
                $itemAlias = null;
            } else {
                $itemColumn = $itemAlias . '.item_id';
            }
        }
        $checkoutAggregate = self::itemForAlias($cte['selectItems'] ?? [], 'checkouts')['exactAggregate'] ?? null;
        $auditAlias = self::countedAuditAlias($cte);
        $expectedGroup = $itemAlias === null || $itemColumn === null
            ? [] : [$itemColumn, $itemAlias . '.holdings_record_id'];
        if ($itemAlias === null || $itemColumn === null || $auditAlias === null
            || $itemId !== $itemColumn || ($cte['groupBy'] ?? []) !== $expectedGroup
            || ($checkoutAggregate['function'] ?? null) !== 'count'
            || !isset($checkoutAggregate['column'])
            || self::columnSource($cte, $checkoutAggregate['column']) !== 'circulation.audit_loan__t'
            || !self::hasJoinedColumnEquality(
                $cte['predicates']['columnComparisons'] ?? [],
                $auditAlias . '.loan__item_id',
                $itemColumn,
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

    private static function measureColumnLineage(array $analysis, string $measure): array
    {
        $item = self::itemForAlias($analysis['selectItems'] ?? [], $measure);
        $aggregate = $item['exactAggregate'] ?? null;
        if (($aggregate['function'] ?? null) !== 'sum' || !isset($aggregate['column'])) {
            return [];
        }
        $steps = [];
        self::collectColumnLineage($analysis, (string)$aggregate['column'], $analysis, [], $steps);
        return $steps;
    }

    private static function collectColumnLineage(
        array $scope,
        string $column,
        array $analysis,
        array $visited,
        array &$steps
    ): void {
        $binding = self::resolveColumnBinding($scope, $column);
        $name = ($binding['kind'] ?? null) === 'cte' ? (string)($binding['source'] ?? '') : '';
        $leaf = self::columnLeaf($column);
        if ($name === '' || isset($visited[$name . '.' . $leaf]) || !isset($analysis['ctes'][$name])) {
            return;
        }
        $visited[$name . '.' . $leaf] = true;
        $sourceScope = $analysis['ctes'][$name];
        $item = self::itemForAlias($sourceScope['selectItems'] ?? [], $leaf);
        if ($item === null) {
            return;
        }
        $steps[] = ['name' => $name, 'scope' => $sourceScope, 'item' => $item];
        $aggregate = $item['exactAggregate'] ?? null;
        $next = isset($aggregate['column'])
            ? (string)$aggregate['column']
            : (self::expressionLeaf((string)($item['expression'] ?? '')) === null
                ? null : (string)$item['expression']);
        if ($next !== null) {
            self::collectColumnLineage($sourceScope, $next, $analysis, $visited, $steps);
        }
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

    private static function hasRecursiveCallNumberLineage(
        string $expression,
        array $analysis,
        array $approvedDerivations
    ): bool {
        $steps = [];
        self::collectColumnLineage($analysis, $expression, $analysis, [], $steps);
        if (count($steps) < 4) {
            return false;
        }
        $endpoint = $steps[count($steps) - 1];
        $item = $endpoint['item'];
        if (!in_array($item['callNumberClassDerivation'] ?? null, $approvedDerivations, true)
            && !self::isRecursiveDocumentedClassItem($item)) {
            return false;
        }
        $sourceColumn = null;
        foreach (($item['referencedColumns'] ?? []) as $column) {
            if (self::columnLeaf((string)$column) === 'call_number') {
                $sourceColumn = (string)$column;
                break;
            }
        }
        $sourceBinding = $sourceColumn === null ? null : self::resolveColumnBinding($endpoint['scope'], $sourceColumn);
        $sourceName = ($sourceBinding['kind'] ?? null) === 'cte' ? (string)($sourceBinding['source'] ?? '') : '';
        $source = $analysis['ctes'][$sourceName] ?? null;
        $sourceItem = $source === null ? null : self::aliasForSource($source, 'inventory.item__t');
        if ($source === null || $sourceItem === null
            || !self::hasCampusHierarchy($source, 'smith college', true)
            || self::expressionForAlias($source['selectItems'] ?? [], 'call_number')
                !== $sourceItem . '.effective_call_number_components__call_number') {
            return false;
        }
        $countStep = $steps[count($steps) - 2];
        $count = self::itemForAlias($countStep['scope']['selectItems'] ?? [], 'eligible_item_count')['exactAggregate'] ?? null;
        $countAlias = self::columnQualifier((string)($count['column'] ?? ''));
        if (($count['function'] ?? null) !== 'count' || empty($count['distinct'])
            || ($count['column'] ?? null) !== $countAlias . '.item_id'
            || ($countStep['scope']['groupBy'] ?? []) !== [
                $countAlias . '.instance_id',
                $countAlias . '.call_number_class',
            ]) {
            return false;
        }
        $rankStep = $steps[count($steps) - 3];
        $rankExpression = self::compactExpression((string)self::expressionForAlias(
            $rankStep['scope']['selectItems'] ?? [],
            'class_rank'
        ));
        $rankAlias = self::columnQualifier((string)self::expressionForAlias(
            $rankStep['scope']['selectItems'] ?? [],
            'instance_id'
        ));
        if ($rankExpression !== 'row_number()over(partitionby' . $rankAlias
            . '.instance_idorderby' . $rankAlias . '.eligible_item_countdesc,'
            . $rankAlias . '.call_number_classasc)') {
            return false;
        }
        $dominant = $steps[0]['scope'];
        foreach (($dominant['predicates']['literalPredicates'] ?? []) as $predicate) {
            if (self::columnLeaf((string)($predicate['column'] ?? '')) === 'class_rank'
                && ($predicate['operator'] ?? null) === '='
                && ($predicate['values'] ?? []) === ['1']
                && self::isEnforcingFact($predicate)) {
                return true;
            }
        }
        return false;
    }

    private static function isRecursiveDocumentedClassItem(array $item): bool
    {
        $expression = self::compactExpression((string)($item['expression'] ?? ''));
        return ($item['functions'] ?? []) === ['trim', 'coalesce', 'upper', 'regexp_replace', 'lpad', 'cast', 'floor']
            && count($item['referencedColumns'] ?? []) === 1
            && self::columnLeaf((string)$item['referencedColumns'][0]) === 'call_number'
            && strpos($expression, "casewhentrim(coalesce(") === 0
            && strpos($expression, "then'unclassified'") !== false
            && strpos($expression, "~*'^[a-z]{1,3}[0-9]'") !== false
            && strpos($expression, 'thenupper(regexp_replace(') !== false
            && strpos($expression, "~'^[0-9]'") !== false
            && strpos($expression, 'thenlpad(cast(floor(cast(regexp_replace(') !== false
            && substr($expression, -strlen("else'local/other'end")) === "else'local/other'end";
    }

    private static function hasRecursiveCurrencySeparation(array $analysis): bool
    {
        $currency = self::expressionForAlias($analysis['selectItems'] ?? [], 'currency');
        if ($currency === null || !in_array($currency, $analysis['groupBy'] ?? [], true)) {
            return false;
        }
        $steps = [];
        self::collectColumnLineage($analysis, $currency, $analysis, [], $steps);
        if (count($steps) < 4) {
            return false;
        }
        foreach ($steps as $step) {
            $expression = self::expressionForAlias($step['scope']['selectItems'] ?? [], 'currency');
            if ($expression === null || self::expressionLeaf($expression) !== 'currency') {
                return false;
            }
            if (($step['scope']['groupBy'] ?? []) !== []
                && !in_array($expression, $step['scope']['groupBy'], true)) {
                return false;
            }
        }
        return self::isPreaggregatedInvoiceScope($steps[count($steps) - 1]['scope']);
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
            'currency_separation' => 'grain_mismatch',
            'governed_filters' => 'unrequested_filter',
            'numeric_output_types' => 'output_type_mismatch',
            'organization_interface_relationship' => 'relationship_mismatch',
            'organization_acquisition_unit_relationship' => 'scope_mismatch',
            'organization_acquisition_unit_code' => 'scope_mismatch',
        ];
        return $categories[$key] ?? 'semantic_coverage_gap';
    }
}
