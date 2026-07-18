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
$contract = ExploratorySemanticContractService::build($question, 'Smith College', $assumptions, 'unsupported_query_family');

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

$noCampusRequirements = array_column(
    ExploratorySemanticContractService::build($question, null, $assumptions, 'unsupported_query_family')['requirements'],
    null,
    'key'
);
contractAssertSame(false, $noCampusRequirements['campus_scope']['parameters']['required'], 'Null campus must remain optional.');
contractAssertSame('No campus restriction was requested.', $noCampusRequirements['campus_scope']['label'], 'Null campus must not claim a selected campus was applied.');

$canonicalContract = ExploratorySemanticContractService::build(
    $question,
    'Smith College',
    $assumptions,
    'family_contract_supported:inventory_listing'
);
contractAssertSame(false, $canonicalContract['applicable'], 'Canonical-family routing must bypass the exploratory semantic contract.');

$supportedVocabularyQuestion = 'Compare acquisitions and checkout data by call number and show ROI.';
contractAssertSame(
    true,
    ExploratorySemanticContractService::build(
        $supportedVocabularyQuestion,
        null,
        ExploratoryQueryDefaultsService::resolve($supportedVocabularyQuestion),
        'unsupported_query_family'
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
        'unsupported_query_family'
    )['applicable'],
    'Concept detection must not broaden beyond documented-default vocabulary.'
);

$filteredQuestion = $question . ' Use material type filters. Use acquisition unit filters.';
$filteredContract = ExploratorySemanticContractService::build(
    $filteredQuestion,
    null,
    ExploratoryQueryDefaultsService::resolve($filteredQuestion),
    'unsupported_query_family'
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
    'unsupported_query_family'
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
    'unsupported_query_family'
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

$simple = ExploratorySemanticContractService::build('List item barcodes', null, [], 'unsupported_query_family');
contractAssertSame(false, $simple['applicable'], 'An unrelated simple request must not receive an ROI checklist.');

fwrite(STDOUT, "Exploratory semantic contract service test passed\n");
