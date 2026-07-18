<?php

namespace app\services;

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
        'governed_filters' => 'governed_filters',
        'numeric_output_types' => 'numeric_output_types',
    ];

    /**
     * Temporary coverage registry. The semantic validator replaces this registry
     * when its rule implementations are introduced.
     */
    private const SUPPORTED_RULE_KEYS = [
        'purchase_date_basis',
        'investment_cost_basis',
        'spend_before_item_join',
        'circulation_window',
        'circulation_item_grain',
        'call_number_grouping',
        'required_output_measures',
        'roi_formula',
        'descending_purchase_ranking',
        'campus_scope',
        'governed_filters',
        'numeric_output_types',
    ];

    public static function build(
        string $question,
        ?string $campus,
        array $assumptions,
        string $routeReason
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
        $filters = self::permittedFilters($question, $campus);
        $requirements = [
            self::requirement('purchase_date_basis', 'Use the resolved purchase date basis.', [
                'value' => $values['purchase_date_basis'] ?? null,
            ]),
            self::requirement('investment_cost_basis', 'Use the resolved investment cost basis.', [
                'value' => $values['investment_cost_basis'] ?? null,
            ]),
            self::requirement('spend_grain', 'Aggregate spending before joining item-level data.'),
            self::requirement('circulation_window', 'Use the resolved circulation window.', [
                'value' => $values['circulation_window'] ?? null,
            ]),
            self::requirement('circulation_grain', 'Aggregate circulation at item grain before final grouping.'),
            self::requirement('call_number_grouping', 'Use the resolved call-number grouping.', [
                'value' => $values['call_number_grouping'] ?? null,
            ]),
            self::requirement('required_measures', 'Return every required purchase, spend, circulation, and ROI measure.', [
                'values' => [
                    'purchase_count',
                    'spend',
                    'circulation',
                    'checkouts_per_dollar',
                    'cost_per_checkout',
                ],
            ]),
            self::requirement('roi_formula', 'Use the resolved zero-safe ROI formula.', [
                'value' => $values['roi_formula'] ?? null,
            ]),
            self::requirement('purchase_ranking', 'Rank call-number groups by purchase count in descending order.', [
                'measure' => 'purchase_count',
                'direction' => 'descending',
            ]),
            self::requirement('campus_scope', 'Apply the separately selected campus scope when supplied.', [
                'required' => $campus !== null,
                'value' => $campus,
            ]),
            self::requirement('governed_filters', 'Use only filters permitted by the request contract.', [
                'permitted' => array_keys($filters),
            ]),
            self::requirement('numeric_output_types', 'Keep analytical measures numeric.'),
        ];

        $coverage = self::auditCoverage($requirements, self::SUPPORTED_RULE_KEYS);

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'applicable' => true,
            'concept' => 'cross_domain_call_number_roi',
            'requirements' => $coverage['requirements'],
            'permittedFilters' => $filters,
            'coverageStatus' => $coverage['coverageStatus'],
            'uncoveredRequirementKeys' => $coverage['uncoveredRequirementKeys'],
        ];
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

    private static function permittedFilters(string $question, ?string $campus): array
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

        if (preg_match('/\b(?:use (?:the )?material types?(?: filters?)?|(?:filter|limit|restrict)(?:ed)? (?:the (?:results|report) )?(?:by|to) material types?)\b/', (string)$normalized) === 1) {
            $filters['material_type'] = ['provenance' => 'explicit_prompt'];
        }
        if (preg_match('/\b(?:use (?:the )?acquisition units?(?: filters?)?|(?:filter|limit|restrict)(?:ed)? (?:the (?:results|report) )?(?:by|to) acquisition units?)\b/', (string)$normalized) === 1) {
            $filters['acquisition_unit'] = ['provenance' => 'explicit_prompt'];
        }

        return $filters;
    }
}
