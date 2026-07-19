<?php

namespace app\services;

require_once __DIR__ . '/ExploratorySqlSemanticValidatorService.php';

class ExploratorySemanticContractService
{
    private const CONTRACT_VERSION = 1;

    private const EXPLORATORY_ROUTE_REASONS = [
        'unsupported_query_family',
        'user_requested_exploratory_generation',
        'canonical_path_unavailable_for_marc_source_records',
    ];

    private const ROI_RULES = [
        'purchase_date_basis' => 'purchase_date_basis',
        'investment_cost_basis' => 'investment_cost_basis',
        'spend_grain' => 'spend_before_item_join',
        'circulation_window' => 'circulation_window',
        'circulation_grain' => 'circulation_item_grain',
        'call_number_grouping' => 'call_number_grouping',
        'required_measures' => 'required_output_measures',
        'roi_formula' => 'roi_formula',
        'purchase_ranking' => 'descending_purchase_ranking',
        'campus_scope' => 'campus_scope',
        'physical_item_eligibility' => 'physical_item_eligibility',
        'acquisition_unit_scope' => 'acquisition_unit_scope',
        'currency_separation' => 'currency_separation',
        'governed_filters' => 'governed_filters',
        'numeric_output_types' => 'numeric_output_types',
    ];

    public static function build(
        string $question,
        ?string $campus,
        array $assumptions,
        string $routeReason,
        array $options = []
    ): array {
        if (!self::isExploratoryRouteReason($routeReason) || !self::isCrossDomainCallNumberRoi($question)) {
            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'applicable' => false,
                'concept' => null,
                'requirements' => [],
                'permittedFilters' => [],
                'coverageStatus' => 'not_applicable',
                'uncoveredRequirementKeys' => [],
            ];
        }

        $values = self::assumptionValues($assumptions);
        $policyVersion = ($options['physicalRoiPolicyVersion'] ?? 'v2') === 'legacy' ? 'legacy' : 'v2';
        $materialType = $policyVersion === 'v2' ? self::explicitPhysicalMaterialType($question) : null;
        $filters = self::permittedFilters($question, $campus, $policyVersion, $materialType);
        $requiredMeasures = $policyVersion === 'legacy'
            ? (($values['roi_formula'] ?? null) === 'cost_per_checkout'
                ? ['purchase_count', 'spend', 'circulation', 'cost_per_checkout']
                : ['purchase_count', 'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout'])
            : (($values['roi_formula'] ?? null) === 'cost_per_checkout'
                ? ['physical_copies_purchased', 'distinct_titles', 'spend', 'circulation', 'cost_per_checkout', 'exact_linked_copies', 'fallback_linked_copies', 'fallback_percentage']
                : ['physical_copies_purchased', 'distinct_titles', 'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout', 'exact_linked_copies', 'fallback_linked_copies', 'fallback_percentage']);
        $purchaseRankingMeasure = $policyVersion === 'legacy' ? 'purchase_count' : 'physical_copies_purchased';
        $requirements = [
            self::requirement('purchase_date_basis', self::requirementLabel('purchase_date_basis', $values), [
                'value' => $values['purchase_date_basis'] ?? null,
            ]),
            self::requirement('investment_cost_basis', self::requirementLabel('investment_cost_basis', $values), [
                'value' => $values['investment_cost_basis'] ?? null,
            ]),
            self::requirement('spend_grain', self::policyRequirementLabel('spend_grain', $values, $policyVersion)),
            self::requirement('circulation_window', self::requirementLabel('circulation_window', $values), [
                'value' => $values['circulation_window'] ?? null,
            ]),
            self::requirement('circulation_grain', self::requirementLabel('circulation_grain', $values)),
            self::requirement('call_number_grouping', self::requirementLabel('call_number_grouping', $values), [
                'value' => $values['call_number_grouping'] ?? null,
            ]),
            self::requirement('required_measures', self::policyRequirementLabel('required_measures', $values, $policyVersion), [
                'values' => $requiredMeasures,
            ]),
            self::requirement('roi_formula', self::requirementLabel('roi_formula', $values), [
                'value' => $values['roi_formula'] ?? null,
            ]),
            self::requirement('purchase_ranking', self::policyRequirementLabel('purchase_ranking', $values, $policyVersion), [
                'measure' => $purchaseRankingMeasure,
                'direction' => 'descending',
            ]),
            self::requirement('campus_scope', self::requirementLabel('campus_scope', $values, $campus !== null), [
                'required' => $campus !== null,
                'value' => $campus,
            ]),
        ];
        if ($policyVersion === 'v2') {
            $requirements[] = self::requirement('physical_item_eligibility', 'Purchases require positive physical quantity and a current item at the selected campus.', [
                'positivePhysicalQuantity' => true,
                'currentSelectedCampusItem' => true,
                'campus' => $campus,
            ]);
            $requirements[] = self::requirement('acquisition_unit_scope', 'Purchases are restricted to the Smith acquisitions unit.', [
                'code' => 'SC',
            ]);
            $requirements[] = self::requirement('currency_separation', 'Unlike invoice currencies remain in separate result groups.', [
                'value' => 'invoice_currency',
            ]);
        }
        $requirements = array_merge($requirements, [
            self::requirement('governed_filters', self::policyRequirementLabel('governed_filters', $values, $policyVersion), [
                'permitted' => array_keys($filters),
            ]),
            self::requirement('numeric_output_types', self::requirementLabel('numeric_output_types', $values)),
        ]);

        $coverage = self::auditCoverage($requirements, ExploratorySqlSemanticValidatorService::supportedRuleKeys());

        $contract = [
            'contractVersion' => self::CONTRACT_VERSION,
            'applicable' => true,
            'concept' => 'cross_domain_call_number_roi',
            'requirements' => $coverage['requirements'],
            'permittedFilters' => $filters,
            'coverageStatus' => $coverage['coverageStatus'],
            'uncoveredRequirementKeys' => $coverage['uncoveredRequirementKeys'],
        ];
        if ($policyVersion === 'v2') {
            $contract['reportPolicy'] = [
                'physicalOnly' => true,
                'acquisitionUnitCode' => 'SC',
                'materialType' => $materialType,
            ];
        }
        return $contract;
    }

    public static function auditCoverage(array $requirements, array $supportedRuleKeys): array
    {
        $supported = array_flip($supportedRuleKeys);
        $uncovered = [];
        foreach ($requirements as $requirement) {
            if (!isset($supported[$requirement['rule']])) {
                $uncovered[] = $requirement['key'];
            }
        }

        return [
            'requirements' => $requirements,
            'coverageStatus' => $uncovered === [] ? 'complete' : 'gap',
            'uncoveredRequirementKeys' => $uncovered,
        ];
    }

    private static function isExploratoryRouteReason(string $routeReason): bool
    {
        return in_array($routeReason, self::EXPLORATORY_ROUTE_REASONS, true);
    }

    private static function isCrossDomainCallNumberRoi(string $question): bool
    {
        $normalized = strtolower($question);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', trim((string)$normalized));

        return preg_match('/\b(?:purchas[a-z]*|acquisitions?)\b/', (string)$normalized) === 1
            && preg_match('/\b(?:circulation|checkouts?)\b/', (string)$normalized) === 1
            && preg_match('/\bcall numbers?\b/', (string)$normalized) === 1
            && preg_match('/\b(?:roi|return on investment)\b/', (string)$normalized) === 1;
    }

    private static function assumptionValues(array $assumptions): array
    {
        $values = [];
        foreach ($assumptions as $assumption) {
            if (!is_array($assumption)) {
                continue;
            }

            $key = $assumption['key'] ?? null;
            $value = $assumption['value'] ?? null;
            if (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private static function requirement(string $key, string $label, array $parameters = []): array
    {
        return [
            'key' => $key,
            'rule' => self::ROI_RULES[$key],
            'label' => $label,
            'parameters' => $parameters,
        ];
    }

    private static function requirementLabel(string $key, array $values, bool $campusSupplied = false): string
    {
        $labels = [
            'spend_grain' => 'Purchase count and spending are aggregated before item-level circulation.',
            'circulation_grain' => 'Checkouts are counted at item grain before final grouping.',
            'required_measures' => 'Results include purchase count, spending, circulation, checkouts per dollar, and cost per checkout.',
            'purchase_ranking' => 'Call-number groups rank by purchase count from highest to lowest.',
            'governed_filters' => 'Material-type and acquisition-unit filters appear only when explicitly requested.',
            'numeric_output_types' => 'Purchase, spending, circulation, and ROI measures remain numeric.',
        ];
        if ($key === 'purchase_date_basis') {
            return ($values['purchase_date_basis'] ?? null) === 'invoice_date'
                ? 'Purchases use invoice date for the five-year reporting period.'
                : 'Purchases use payment date for the five-year reporting period.';
        }
        if ($key === 'investment_cost_basis') {
            return ($values['investment_cost_basis'] ?? null) === 'estimated_po_line_price'
                ? 'Spending uses estimated PO-line prices.'
                : 'Spending uses paid invoice fund-distribution amounts.';
        }
        if ($key === 'circulation_window') {
            return ($values['circulation_window'] ?? null) === 'lifetime_circulation'
                ? 'Circulation uses lifetime checkout history.'
                : 'Circulation uses the same five-year reporting period as purchases.';
        }
        if ($key === 'call_number_grouping') {
            return ($values['call_number_grouping'] ?? null) === 'first_two_call_number_letters'
                ? 'Results group by the first two call-number letters.'
                : 'Results group by primary call-number class.';
        }
        if ($key === 'roi_formula') {
            return ($values['roi_formula'] ?? null) === 'cost_per_checkout'
                ? 'ROI returns cost per checkout.'
                : 'ROI returns checkouts per dollar and cost per checkout.';
        }
        if ($key === 'required_measures') {
            return ($values['roi_formula'] ?? null) === 'cost_per_checkout'
                ? 'Results include purchase count, spending, circulation, and cost per checkout.'
                : 'Results include purchase count, spending, circulation, checkouts per dollar, and cost per checkout.';
        }
        if ($key === 'campus_scope') {
            return $campusSupplied
                ? 'The selected campus is applied through the inventory location hierarchy.'
                : 'No campus restriction was requested.';
        }
        return $labels[$key] ?? 'The report satisfies an approved semantic requirement.';
    }

    private static function policyRequirementLabel(string $key, array $values, string $policyVersion): string
    {
        if ($policyVersion !== 'v2') {
            return self::requirementLabel($key, $values);
        }
        $labels = [
            'spend_grain' => 'Physical copies purchased and spending are aggregated before item-level circulation.',
            'purchase_ranking' => 'Call-number groups rank by physical copies purchased from highest to lowest.',
            'governed_filters' => 'Physical-resource and SC acquisitions filters are required by reporting policy; material type appears only when explicitly requested.',
        ];
        if ($key === 'required_measures') {
            return 'Results include physical copies purchased, distinct titles, spending, circulation, ROI, and exact-versus-fallback linkage diagnostics.';
        }
        return $labels[$key] ?? self::requirementLabel($key, $values);
    }

    private static function permittedFilters(
        string $question,
        ?string $campus,
        string $policyVersion,
        ?string $materialType
    ): array
    {
        $filters = [];
        if ($campus !== null) {
            $filters['campus'] = [
                'value' => $campus,
                'provenance' => 'selected_scope',
            ];
        }

        $normalized = strtolower($question);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', trim((string)$normalized));

        if ($policyVersion === 'v2') {
            $filters['physical_resource'] = ['provenance' => 'reporting_policy'];
            $filters['acquisition_unit'] = [
                'value' => 'SC',
                'provenance' => 'reporting_policy',
            ];
            if ($materialType !== null) {
                $filters['material_type'] = [
                    'value' => $materialType,
                    'provenance' => 'explicit_prompt',
                ];
            }
            return $filters;
        }

        if (preg_match('/\b(?:use (?:the )?material types?(?: filters?)?|(?:filter|limit|restrict)(?:ed)? (?:the (?:results|report) )?(?:by|to) material types?)\b/', (string)$normalized) === 1) {
            $filters['material_type'] = ['provenance' => 'explicit_prompt'];
        }
        if (preg_match('/\b(?:use (?:the )?acquisition units?(?: filters?)?|(?:filter|limit|restrict)(?:ed)? (?:the (?:results|report) )?(?:by|to) acquisition units?)\b/', (string)$normalized) === 1) {
            $filters['acquisition_unit'] = ['provenance' => 'explicit_prompt'];
        }

        return $filters;
    }

    private static function explicitPhysicalMaterialType(string $question): ?string
    {
        $normalized = strtolower($question);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', trim((string)$normalized));

        return preg_match('/\bdvds?\b/', (string)$normalized) === 1 ? 'dvd' : null;
    }
}
