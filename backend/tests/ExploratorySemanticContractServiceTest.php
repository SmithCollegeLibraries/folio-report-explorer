<?php
require_once __DIR__ . '/../services/ExploratorySemanticContractService.php';
require_once __DIR__ . '/../services/ExploratoryQueryDefaultsService.php';

use app\services\ExploratoryQueryDefaultsService;
use app\services\ExploratorySemanticContractService;

function contractAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$question = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$assumptions = ExploratoryQueryDefaultsService::resolve($question);
$legacyOptions = ['physicalRoiPolicyVersion' => 'legacy'];
$contract = ExploratorySemanticContractService::build($question, 'Smith College', $assumptions, 'unsupported_query_family', $legacyOptions);

contractAssertSame(1, $contract['contractVersion'], 'The contract must be versioned.');
contractAssertSame(true, $contract['applicable'], 'Cross-domain call-number ROI must receive semantic protection.');
contractAssertSame('cross_domain_call_number_roi', $contract['concept'], 'The motivating request must use the ROI contract.');
contractAssertSame('complete', $contract['coverageStatus'], 'Every ROI requirement must have a registered rule.');
contractAssertSame([
    'purchase_date_basis', 'investment_cost_basis', 'spend_grain', 'circulation_window',
    'circulation_grain', 'call_number_grouping', 'required_measures', 'roi_formula',
    'purchase_ranking', 'campus_scope', 'governed_filters', 'numeric_output_types',
], array_column($contract['requirements'], 'key'), 'ROI requirements must be stable and ordered.');
contractAssertSame('Smith College', $contract['permittedFilters']['campus']['value'], 'Selected campus must be required and permitted.');
contractAssertSame('selected_scope', $contract['permittedFilters']['campus']['provenance'], 'Campus permission must retain selected-scope provenance.');
$requirementsByKey = array_column($contract['requirements'], null, 'key');
contractAssertSame(
    [
        'purchase_date_basis' => 'Purchases use payment date for the five-year reporting period.',
        'investment_cost_basis' => 'Spending uses paid invoice fund-distribution amounts.',
        'spend_grain' => 'Purchase count and spending are aggregated before item-level circulation.',
        'circulation_window' => 'Circulation uses the same five-year reporting period as purchases.',
        'circulation_grain' => 'Checkouts are counted at item grain before final grouping.',
        'call_number_grouping' => 'Results group by primary call-number class.',
        'required_measures' => 'Results include purchase count, spending, circulation, checkouts per dollar, and cost per checkout.',
        'roi_formula' => 'ROI returns checkouts per dollar and cost per checkout.',
        'purchase_ranking' => 'Call-number groups rank by purchase count from highest to lowest.',
        'campus_scope' => 'The selected campus is applied through the inventory location hierarchy.',
        'governed_filters' => 'Material-type and acquisition-unit filters appear only when explicitly requested.',
        'numeric_output_types' => 'Purchase, spending, circulation, and ROI measures remain numeric.',
    ],
    array_map(static function (array $requirement): string {
        return $requirement['label'];
    }, $requirementsByKey),
    'Requirement labels must use concrete allowlisted wording.'
);
contractAssertSame(true, $requirementsByKey['campus_scope']['parameters']['required'], 'A separately selected campus must be required.');
contractAssertSame('Smith College', $requirementsByKey['campus_scope']['parameters']['value'], 'The campus requirement must retain the selected scope value.');
contractAssertSame(false, isset($contract['permittedFilters']['material_type']), 'Material type must not be silently permitted.');
contractAssertSame(false, isset($contract['permittedFilters']['acquisition_unit']), 'Acquisition unit must not be silently permitted.');
contractAssertSame(false, array_key_exists('reportPolicy', $contract), 'Legacy rollback contracts must retain their original keys.');

$physical = ExploratorySemanticContractService::build(
    $question,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($question),
    'unsupported_query_family'
);
contractAssertSame(true, $physical['reportPolicy']['physicalOnly'] ?? null, 'ROI policy must require physical purchases.');
contractAssertSame('SC', $physical['reportPolicy']['acquisitionUnitCode'] ?? null, 'ROI policy must require Smith acquisitions.');
contractAssertSame(null, $physical['reportPolicy']['materialType'] ?? null, 'Generic ROI wording must not default to books.');
contractAssertSame('reporting_policy', $physical['permittedFilters']['physical_resource']['provenance'] ?? null, 'Physical eligibility is policy-backed.');
contractAssertSame('reporting_policy', $physical['permittedFilters']['acquisition_unit']['provenance'] ?? null, 'SC acquisitions are policy-backed.');
contractAssertSame('complete', $physical['coverageStatus'], 'Every v2 policy requirement must have a registered rule.');
$physicalRequirements = array_column($physical['requirements'], null, 'key');
contractAssertSame('Physical copies purchased and spending are aggregated before item-level circulation.', $physicalRequirements['spend_grain']['label'] ?? null, 'V2 spend label must describe physical copies.');
contractAssertSame('Results include physical copies purchased, distinct titles, spending, circulation, ROI, and exact-versus-fallback linkage diagnostics.', $physicalRequirements['required_measures']['label'] ?? null, 'V2 required-measure label must describe copy, title, and linkage outputs.');
contractAssertSame('Call-number groups rank by physical copies purchased from highest to lowest.', $physicalRequirements['purchase_ranking']['label'] ?? null, 'V2 ranking label must describe physical copies.');
contractAssertSame('Physical-resource and SC acquisitions filters are required by reporting policy; material type appears only when explicitly requested.', $physicalRequirements['governed_filters']['label'] ?? null, 'V2 governance label must disclose SC reporting policy.');
contractAssertSame(
    ['physical_copies_purchased', 'distinct_titles', 'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout', 'exact_linked_copies', 'fallback_linked_copies', 'fallback_percentage'],
    $physicalRequirements['required_measures']['parameters']['values'] ?? null,
    'Physical ROI must expose copy and title measures instead of legacy PO-line counts.'
);
contractAssertSame('physical_copies_purchased', $physicalRequirements['purchase_ranking']['parameters']['measure'] ?? null, 'Physical ROI ranking must use physical copies purchased.');
contractAssertSame(true, isset($physicalRequirements['physical_item_eligibility']), 'V2 contracts must block on physical item eligibility.');
contractAssertSame(true, isset($physicalRequirements['acquisition_unit_scope']), 'V2 contracts must block on acquisition-unit scope.');
contractAssertSame(false, isset($requirementsByKey['physical_item_eligibility']), 'Legacy contracts must omit physical item eligibility.');
contractAssertSame(false, isset($requirementsByKey['acquisition_unit_scope']), 'Legacy contracts must omit acquisition-unit scope.');

$dvdQuestion = 'For DVDs, show call numbers purchased most in five years with circulation and ROI.';
$dvd = ExploratorySemanticContractService::build(
    $dvdQuestion,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($dvdQuestion),
    'unsupported_query_family'
);
contractAssertSame('dvd', $dvd['reportPolicy']['materialType'] ?? null, 'Explicit DVD scope must be retained.');
contractAssertSame('explicit_prompt', $dvd['permittedFilters']['material_type']['provenance'] ?? null, 'DVD is an explicit material filter.');

$physicalCostOnlyQuestion = $question . ' Use cost per checkout.';
$physicalCostOnly = ExploratorySemanticContractService::build(
    $physicalCostOnlyQuestion,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($physicalCostOnlyQuestion),
    'unsupported_query_family'
);
contractAssertSame(
    ['physical_copies_purchased', 'distinct_titles', 'spend', 'circulation', 'cost_per_checkout', 'exact_linked_copies', 'fallback_linked_copies', 'fallback_percentage'],
    array_column($physicalCostOnly['requirements'], null, 'key')['required_measures']['parameters']['values'] ?? null,
    'Cost-per-checkout-only v2 contracts must omit checkouts per dollar.'
);

$noCampusRequirements = array_column(
    ExploratorySemanticContractService::build($question, null, $assumptions, 'unsupported_query_family', $legacyOptions)['requirements'],
    null,
    'key'
);
contractAssertSame(false, $noCampusRequirements['campus_scope']['parameters']['required'], 'Null campus must remain optional.');
contractAssertSame('No campus restriction was requested.', $noCampusRequirements['campus_scope']['label'], 'Null campus must not claim a selected campus was applied.');

$canonicalContract = ExploratorySemanticContractService::build(
    $question,
    'Smith College',
    $assumptions,
    'family_contract_supported:inventory_listing',
    $legacyOptions
);
contractAssertSame(false, $canonicalContract['applicable'], 'Canonical-family routing must bypass the exploratory semantic contract.');

$supportedVocabularyQuestion = 'Compare acquisitions and checkout data by call number and show ROI.';
contractAssertSame(
    true,
    ExploratorySemanticContractService::build(
        $supportedVocabularyQuestion,
        null,
        ExploratoryQueryDefaultsService::resolve($supportedVocabularyQuestion),
        'unsupported_query_family',
        $legacyOptions
    )['applicable'],
    'Concept detection must support the same representative vocabulary as documented-default detection.'
);
$outsideVocabularyQuestion = 'Compare spending and usage by classification and show value for money.';
contractAssertSame(
    false,
    ExploratorySemanticContractService::build(
        $outsideVocabularyQuestion,
        null,
        ExploratoryQueryDefaultsService::resolve($outsideVocabularyQuestion),
        'unsupported_query_family',
        $legacyOptions
    )['applicable'],
    'Concept detection must not broaden beyond documented-default vocabulary.'
);

$filteredQuestion = $question . ' Use material type filters. Use acquisition unit filters.';
$filteredContract = ExploratorySemanticContractService::build(
    $filteredQuestion,
    null,
    ExploratoryQueryDefaultsService::resolve($filteredQuestion),
    'unsupported_query_family',
    $legacyOptions
);
contractAssertSame(
    'explicit_prompt',
    $filteredContract['permittedFilters']['material_type']['provenance'] ?? null,
    'An explicitly requested material-type filter must be permitted with prompt provenance.'
);
contractAssertSame(
    'explicit_prompt',
    $filteredContract['permittedFilters']['acquisition_unit']['provenance'] ?? null,
    'An explicitly requested acquisition-unit filter must be permitted with prompt provenance.'
);

$invoiceQuestion = $question . ' Use invoice date.';
$invoiceContract = ExploratorySemanticContractService::build(
    $invoiceQuestion,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($invoiceQuestion),
    'unsupported_query_family',
    $legacyOptions
);
contractAssertSame(
    'invoice_date',
    $invoiceContract['requirements'][0]['parameters']['value'],
    'An explicit correction must replace the default date basis.'
);
contractAssertSame(
    'Purchases use invoice date for the five-year reporting period.',
    $invoiceContract['requirements'][0]['label'],
    'The purchase label must reflect the allowlisted resolved date basis.'
);
$alternativeQuestion = $question . ' Use estimated PO line price. Use lifetime circulation. Group by the first two call number letters. Use cost per checkout.';
$alternativeContract = ExploratorySemanticContractService::build(
    $alternativeQuestion,
    null,
    ExploratoryQueryDefaultsService::resolve($alternativeQuestion),
    'unsupported_query_family',
    $legacyOptions
);
$alternativeLabels = array_column($alternativeContract['requirements'], 'label', 'key');
contractAssertSame('Spending uses estimated PO-line prices.', $alternativeLabels['investment_cost_basis'], 'Investment labels must reflect the allowlisted alternative.');
contractAssertSame('Circulation uses lifetime checkout history.', $alternativeLabels['circulation_window'], 'Circulation labels must reflect the allowlisted alternative.');
contractAssertSame('Results group by the first two call-number letters.', $alternativeLabels['call_number_grouping'], 'Grouping labels must reflect the allowlisted alternative.');
contractAssertSame('ROI returns cost per checkout.', $alternativeLabels['roi_formula'], 'ROI labels must reflect the allowlisted alternative.');
contractAssertSame('Results include purchase count, spending, circulation, and cost per checkout.', $alternativeLabels['required_measures'], 'Required-measure labels must match the selected ROI variant.');
contractAssertSame(
    ['purchase_count', 'spend', 'circulation', 'cost_per_checkout'],
    array_column($alternativeContract['requirements'], null, 'key')['required_measures']['parameters']['values'],
    'Cost-per-checkout contracts must require only the selected ROI output.'
);

$unsupportedRequirement = [
    'key' => 'future_blocking_requirement',
    'rule' => 'future_unsupported_rule',
    'label' => 'Satisfy the future blocking requirement.',
    'parameters' => [],
];
$coverageGap = ExploratorySemanticContractService::auditCoverage(
    [$unsupportedRequirement],
    ['purchase_date_basis']
);
contractAssertSame('gap', $coverageGap['coverageStatus'], 'An unsupported rule must create a coverage gap.');
contractAssertSame(
    [$unsupportedRequirement],
    $coverageGap['requirements'],
    'Coverage auditing must preserve the original blocking requirement.'
);
contractAssertSame(
    ['future_blocking_requirement'],
    $coverageGap['uncoveredRequirementKeys'],
    'Coverage auditing must identify the uncovered requirement key.'
);

$simple = ExploratorySemanticContractService::build('List item barcodes', null, [], 'unsupported_query_family', $legacyOptions);
contractAssertSame(false, $simple['applicable'], 'An unrelated simple request must not receive an ROI checklist.');

fwrite(STDOUT, "Exploratory semantic contract service test passed\n");
