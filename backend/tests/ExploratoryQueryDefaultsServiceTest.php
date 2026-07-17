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

function assertThrowsRuntimeException(callable $callback, string $expectedText, string $message): void
{
    try {
        $callback();
        fwrite(STDERR, $message . "\nExpected RuntimeException containing: {$expectedText}\n");
        exit(1);
    } catch (RuntimeException $exception) {
        if (strpos($exception->getMessage(), $expectedText) === false) {
            fwrite(STDERR, $message . "\nExpected text: {$expectedText}\nActual: {$exception->getMessage()}\n");
            exit(1);
        }
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

$paymentDateAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Use payment date, not invoice date.'
);
$paymentDateByKey = array_column($paymentDateAssumptions, null, 'key');

assertSameValue('payment_date', $paymentDateByKey['purchase_date_basis']['value'], 'A rejected invoice-date alternative must not override an explicit payment-date request.');
assertSameValue('explicit', $paymentDateByKey['purchase_date_basis']['source'], 'An explicit payment-date request should be marked explicit.');

$negatedPaymentDateAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Do not use payment date; use invoice date.'
);
$negatedPaymentDateByKey = array_column($negatedPaymentDateAssumptions, null, 'key');

assertSameValue('invoice_date', $negatedPaymentDateByKey['purchase_date_basis']['value'], 'A negated payment-date phrase must not override the requested invoice date.');
assertSameValue('explicit', $negatedPaymentDateByKey['purchase_date_basis']['source'], 'The requested invoice date should remain explicit after a negated alternative.');

$costPerCheckoutAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Use cost per checkout as ROI.'
);
$costPerCheckoutByKey = array_column($costPerCheckoutAssumptions, null, 'key');

assertSameValue('cost_per_checkout', $costPerCheckoutByKey['roi_formula']['value'], 'Explicit cost-per-checkout language should replace the ROI default.');
assertSameValue('explicit', $costPerCheckoutByKey['roi_formula']['source'], 'Explicit ROI language should be marked explicit.');

$checkoutsPerDollarAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Use checkouts per dollar as ROI, not cost per checkout.'
);
$checkoutsPerDollarByKey = array_column($checkoutsPerDollarAssumptions, null, 'key');

assertSameValue('checkouts_per_dollar_with_cost_per_use', $checkoutsPerDollarByKey['roi_formula']['value'], 'A rejected cost-per-checkout alternative must not override an explicit checkouts-per-dollar request.');
assertSameValue('explicit', $checkoutsPerDollarByKey['roi_formula']['source'], 'An explicit checkouts-per-dollar request should be marked explicit.');

$negatedCheckoutsPerDollarAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Do not use checkouts per dollar as ROI; use cost per checkout.'
);
$negatedCheckoutsPerDollarByKey = array_column($negatedCheckoutsPerDollarAssumptions, null, 'key');

assertSameValue('cost_per_checkout', $negatedCheckoutsPerDollarByKey['roi_formula']['value'], 'A negated checkouts-per-dollar phrase must not override the requested cost per checkout.');
assertSameValue('explicit', $negatedCheckoutsPerDollarByKey['roi_formula']['source'], 'The requested cost-per-checkout formula should remain explicit after a negated alternative.');

$estimatedPriceAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Use estimated PO-line price as the investment amount.'
);
$estimatedPriceByKey = array_column($estimatedPriceAssumptions, null, 'key');

assertSameValue('estimated_po_line_price', $estimatedPriceByKey['investment_cost_basis']['value'], 'Explicit estimated PO-line price language should replace the paid-distribution default.');
assertSameValue('explicit', $estimatedPriceByKey['investment_cost_basis']['source'], 'Explicit investment language should be marked explicit.');

$lifetimeCirculationAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Use lifetime circulation instead of the purchase window.'
);
$lifetimeCirculationByKey = array_column($lifetimeCirculationAssumptions, null, 'key');

assertSameValue('lifetime_circulation', $lifetimeCirculationByKey['circulation_window']['value'], 'Explicit lifetime-circulation language should replace the reporting-window default.');
assertSameValue('explicit', $lifetimeCirculationByKey['circulation_window']['source'], 'Explicit circulation-window language should be marked explicit.');

$firstTwoLettersAssumptions = ExploratoryQueryDefaultsService::resolve(
    $prompt . ' Group by the first two call-number letters instead.'
);
$firstTwoLettersByKey = array_column($firstTwoLettersAssumptions, null, 'key');

assertSameValue('first_two_call_number_letters', $firstTwoLettersByKey['call_number_grouping']['value'], 'Explicit first-two-letter language should replace the primary-class default.');
assertSameValue('explicit', $firstTwoLettersByKey['call_number_grouping']['source'], 'Explicit call-number grouping language should be marked explicit.');

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

$malformedArtifact = json_decode(
    (string)file_get_contents(__DIR__ . '/../data/exploratory_query_defaults.json'),
    true
);
$malformedArtifact['defaults'][4]['key'] = 'purchase_date_basis';
$validateArtifact = new ReflectionMethod(ExploratoryQueryDefaultsService::class, 'validateArtifact');

assertThrowsRuntimeException(
    function () use ($validateArtifact, $malformedArtifact): void {
        $validateArtifact->invoke(null, $malformedArtifact);
    },
    'exactly the required unique keys',
    'Version-1 catalogs should reject duplicate keys and missing required defaults.'
);

fwrite(STDOUT, "ExploratoryQueryDefaultsService test passed\n");
