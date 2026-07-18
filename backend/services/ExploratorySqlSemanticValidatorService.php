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
        $column = $expected;
        return $spend !== null && self::containsColumn($spend['predicates']['dateColumns'] ?? [], $column)
            ? null : self::GUIDANCE['purchase_date_basis'];
    }

    private static function validateInvestmentCostBasis(array $analysis, array $requirement, array $contract): ?string
    {
        if (($requirement['parameters']['value'] ?? null) !== 'actual_paid_fund_distribution') {
            return self::GUIDANCE['investment_cost_basis'];
        }
        $spend = self::spendCte($analysis);
        $expression = self::expressionForAlias($spend['selectItems'] ?? [], 'spend');
        $valid = $expression !== null
            && substr_count($expression, 'fund_distributions__value') === 1
            && strpos($expression, '.total') !== false
            && (strpos($expression, '0.01') !== false || strpos($expression, '/ 100') !== false);
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
        foreach (($spend['tables'] ?? []) as $table) {
            if (strpos($table, 'inventory.') === 0 || strpos($table, 'circulation.') === 0) {
                return self::GUIDANCE['spend_before_item_join'];
            }
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
        if ($circulation === null || $spend === null
            || !self::containsColumn($circulation['predicates']['dateColumns'] ?? [], 'created_date')) {
            return self::GUIDANCE['circulation_window'];
        }
        $purchaseWindow = self::dateWindowSignature(self::predicateText($spend), ['payment_date', 'invoice_date']);
        $circulationWindow = self::dateWindowSignature(self::predicateText($circulation), ['created_date']);
        return $purchaseWindow !== null && $purchaseWindow === $circulationWindow
            ? null : self::GUIDANCE['circulation_window'];
    }

    private static function validateCirculationItemGrain(array $analysis, array $requirement, array $contract): ?string
    {
        $circulation = self::circulationItemCte($analysis);
        if ($circulation === null || self::expressionForAlias($circulation['selectItems'] ?? [], 'item_id') === null
            || !self::containsExpression($circulation['groupBy'] ?? [], 'item.id')) {
            return self::GUIDANCE['circulation_item_grain'];
        }
        $predicates = self::predicateText($circulation);
        return strpos($predicates, 'loan__action') !== false && strpos($predicates, 'checkedout') !== false
            ? null : self::GUIDANCE['circulation_item_grain'];
    }

    private static function validateCallNumberGrouping(array $analysis, array $requirement, array $contract): ?string
    {
        if (($requirement['parameters']['value'] ?? null) !== 'primary_call_number_class') {
            return self::GUIDANCE['call_number_grouping'];
        }
        $groupBy = $analysis['groupBy'] ?? [];
        return count($groupBy) === 1 && strpos($groupBy[0], 'call_number_class') !== false
            ? null : self::GUIDANCE['call_number_grouping'];
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
            $expression = self::expressionForAlias($analysis['selectItems'] ?? [], $alias);
            $division = $expression === null ? false : strpos($expression, '/');
            if ($division === false || !self::isZeroSafe($expression)
                || strpos(substr($expression, 0, $division), $expected[0]) === false
                || strpos(substr($expression, $division + 1), $expected[1]) === false) {
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
            $text = self::predicateText($scope);
            if (strpos($text, 'campus') !== false && $expected !== '' && strpos($text, $expected) !== false) {
                return null;
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
        return array_intersect($measures, $analysis['formattedAliases'] ?? []) === []
            ? null : self::GUIDANCE['numeric_output_types'];
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

    private static function containsColumn(array $columns, string $suffix): bool
    {
        foreach ($columns as $column) {
            if (substr($column, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    private static function containsExpression(array $expressions, string $needle): bool
    {
        foreach ($expressions as $expression) {
            if (strpos($expression, $needle) !== false) {
                return true;
            }
        }
        return false;
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

    private static function isZeroSafe(string $expression): bool
    {
        return strpos($expression, 'nullif (') !== false
            || (strpos($expression, 'case when ') !== false && strpos($expression, '= 0 then null') !== false);
    }

    private static function allScopes(array $analysis): array
    {
        return array_merge([$analysis], array_values($analysis['ctes'] ?? []));
    }

    private static function predicateText(array $scope): string
    {
        $predicates = $scope['predicates'] ?? [];
        return strtolower(trim((string)($predicates['where'] ?? '') . ' ' . implode(' ', $predicates['joins'] ?? [])));
    }

    private static function dateWindowSignature(string $predicates, array $dateColumns): ?string
    {
        foreach ($dateColumns as $column) {
            $pattern = '/(?:^|\.)' . preg_quote($column, '/') . '\s*(>=|>|=)\s*(current_date\s*-\s*interval\s+\'?[0-9]+\s+[a-z]+\'?)/';
            if (preg_match($pattern, $predicates, $matches) === 1) {
                return $matches[1] . ' ' . $matches[2];
            }
        }
        return null;
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
