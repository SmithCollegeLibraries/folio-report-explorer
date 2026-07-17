<?php

$servicePath = __DIR__ . '/../services/ExploratoryQueryDefaultsService.php';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "ExploratoryQueryDefaultsService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\ExploratoryQueryDefaultsService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

$prompt = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$assumptions = ExploratoryQueryDefaultsService::resolve($prompt);
$byKey = array_column($assumptions, null, 'key');

assertSameValue(
    ['call_number_grouping', 'circulation_window', 'investment_cost_basis', 'purchase_date_basis', 'roi_formula'],
    array_keys($byKey),
    'Cross-domain ROI prompts should receive every documented default in stable order.'
);
assertSameValue('payment_date', $byKey['purchase_date_basis']['value'], 'Payment date should be the default.');
assertSameValue('checkouts_per_dollar_with_cost_per_use', $byKey['roi_formula']['value'], 'ROI should include both usage-per-dollar and cost-per-use.');
assertSameValue('default', $byKey['purchase_date_basis']['source'], 'Unspecified interpretations should be marked as defaults.');

$invoiceDateAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Use invoice date instead of payment date.'
);
$invoiceDateByKey = array_column($invoiceDateAssumptions, null, 'key');

assertSameValue('invoice_date', $invoiceDateByKey['purchase_date_basis']['value'], 'Explicit invoice-date language should replace the payment-date default.');
assertSameValue('explicit', $invoiceDateByKey['purchase_date_basis']['source'], 'Explicit invoice-date language should be marked explicit.');

$costPerCheckoutAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Use cost per checkout as ROI.'
);
$costPerCheckoutByKey = array_column($costPerCheckoutAssumptions, null, 'key');

assertSameValue('cost_per_checkout', $costPerCheckoutByKey['roi_formula']['value'], 'Explicit cost-per-checkout language should replace the ROI default.');
assertSameValue('explicit', $costPerCheckoutByKey['roi_formula']['source'], 'Explicit ROI language should be marked explicit.');

assertSameValue(
    [],
    ExploratoryQueryDefaultsService::resolve('Compare circulation and checkout totals by patron group for the last year.'),
    'Circulation-only prompts should not receive cross-domain ROI assumptions.'
);

$guidance = ExploratoryQueryDefaultsService::buildPromptGuidance($assumptions);
assertContainsText('DOCUMENTED INTERPRETATIONS', $guidance, 'Guidance should identify documented interpretations.');
assertContainsText('purchase_date_basis', $guidance, 'Guidance should include resolved assumption keys.');
assertContainsText('payment_date', $guidance, 'Guidance should include resolved assumption values.');
assertContainsText('Aggregate spend before joining item-level circulation', $guidance, 'Guidance should include the documented ROI plan.');
assertSameValue('', ExploratoryQueryDefaultsService::buildPromptGuidance([]), 'Guidance should be empty when no assumptions apply.');

fwrite(STDOUT, "ExploratoryQueryDefaultsService test passed\n");
