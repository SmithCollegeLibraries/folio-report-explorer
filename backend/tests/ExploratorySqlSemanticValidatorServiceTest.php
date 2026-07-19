<?php

require_once __DIR__ . '/../services/ExploratoryQueryDefaultsService.php';
require_once __DIR__ . '/../services/ExploratorySemanticContractService.php';
require_once __DIR__ . '/../services/ExploratorySqlSemanticValidatorService.php';

use app\services\ExploratoryQueryDefaultsService;
use app\services\ExploratorySemanticContractService;
use app\services\ExploratorySqlSemanticValidatorService;

function semanticAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function semanticAssertContainsAll(array $expected, array $actual, string $message): void
{
    $missing = array_values(array_diff($expected, $actual));
    if ($missing !== []) {
        fwrite(STDERR, $message . "\nMissing: " . var_export($missing, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function semanticAssertRejectedFor(string $sql, array $contract, string $key, string $message): void
{
    $result = ExploratorySqlSemanticValidatorService::validate($sql, $contract);
    semanticAssertSame('rejected', $result['status'], $message);
    semanticAssertContainsAll([$key], array_column($result['violations'], 'key'), $message);
}

function semanticAssertRejectedCategory(string $sql, array $contract, string $key, string $category, string $message): void
{
    $result = ExploratorySqlSemanticValidatorService::validate($sql, $contract);
    semanticAssertSame('rejected', $result['status'], $message);
    foreach ($result['violations'] as $violation) {
        if (($violation['key'] ?? null) === $key) {
            semanticAssertSame($category, $violation['category'] ?? null, $message);
            return;
        }
    }
    semanticAssertContainsAll([$key], array_column($result['violations'], 'key'), $message);
}

$question = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$contract = ExploratorySemanticContractService::build(
    $question,
    null,
    ExploratoryQueryDefaultsService::resolve($question),
    'unsupported_query_family',
    ['physicalRoiPolicyVersion' => 'legacy']
);

$correctedSql = <<<'SQL'
WITH spend_by_instance AS (
    SELECT pol.instance_id,
           COUNT(DISTINCT pol.id) AS purchase_count,
           SUM(fd.total * fd.fund_distributions__value * 0.01) AS spend
    FROM invoice.invoice_lines__t__fund_distributions fd
    JOIN invoice.invoice_lines__t invoice_line ON invoice_line.id = fd.id
    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id
    JOIN orders.po_line__t pol ON pol.id = fd.po_line_id
    WHERE invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'
    GROUP BY pol.instance_id
), circulation_by_item AS (
    SELECT item.id AS item_id,
           item.holdings_record_id,
           COUNT(audit_loan.created_date) AS checkouts
    FROM inventory.item__t item
    LEFT JOIN circulation.audit_loan__t audit_loan
      ON audit_loan.loan__item_id = item.id
     AND audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')
     AND audit_loan.created_date >= CURRENT_DATE - INTERVAL '5 years'
    GROUP BY item.id, item.holdings_record_id
), circulation_by_instance AS (
    SELECT holdings.instance_id,
           SUM(circulation_by_item.checkouts) AS circulation
    FROM circulation_by_item
    JOIN inventory.holdings_record__t holdings ON holdings.id = circulation_by_item.holdings_record_id
    GROUP BY holdings.instance_id
), class_by_instance AS (
    SELECT instance.id AS instance_id,
           MIN(SUBSTRING(holdings.effective_call_number_components__call_number FROM '^[A-Za-z]+')) AS call_number_class
    FROM inventory.instance__t instance
    JOIN inventory.holdings_record__t holdings ON holdings.instance_id = instance.id
    GROUP BY instance.id
)
SELECT class_by_instance.call_number_class,
       SUM(spend_by_instance.purchase_count) AS purchase_count,
       SUM(spend_by_instance.spend) AS spend,
       SUM(circulation_by_instance.circulation) AS circulation,
       SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0) AS checkouts_per_dollar,
       SUM(spend_by_instance.spend) / NULLIF(SUM(circulation_by_instance.circulation), 0) AS cost_per_checkout
FROM spend_by_instance
JOIN class_by_instance ON class_by_instance.instance_id = spend_by_instance.instance_id
LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = spend_by_instance.instance_id
GROUP BY class_by_instance.call_number_class
ORDER BY purchase_count DESC
SQL;

$capturedProductionSql = <<<'SQL'
SELECT pc.call_number_class,
       TO_CHAR(SUM(ilt.total), 'FM$999,999,990.00') AS total_spent,
       COUNT(DISTINCT pol.id) AS purchase_count,
       TO_CHAR(SUM(ilt.total) / NULLIF(COUNT(al.id), 0), 'FM$999,999,990.00') AS cost_per_checkout
FROM orders.po_line__t pol
JOIN orders.purchase_order__t pot ON pot.id = pol.purchase_order_id
JOIN invoice.invoice_lines__t ilt ON ilt.po_line_id = pol.id
JOIN inventory.item__t item ON item.material_type_id = 'book'
LEFT JOIN circulation.audit_loan__t al ON al.loan__item_id = item.id
JOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id
JOIN inventory.instance__t instance ON instance.id = holdings.instance_id
JOIN classification.classification__t pc ON pc.instance_id = instance.id
WHERE pot.date_ordered >= CURRENT_DATE - INTERVAL '5 years'
  AND item.material_type_id = 'book'
GROUP BY pc.call_number_class
SQL;

$valid = ExploratorySqlSemanticValidatorService::validate($correctedSql, $contract);
semanticAssertSame('validated', $valid['status'], 'Corrected ROI SQL must pass every rule.');
semanticAssertSame(array_column($contract['requirements'], 'key'), array_column($valid['checkedRequirements'], 'key'), 'Every contract requirement must be checked before validation.');

$estimatedQuestion = $question . ' Use estimated PO line price.';
$estimatedContract = ExploratorySemanticContractService::build($estimatedQuestion, null, ExploratoryQueryDefaultsService::resolve($estimatedQuestion), 'unsupported_query_family', ['physicalRoiPolicyVersion' => 'legacy']);
$estimatedBaseSql = str_replace("    WHERE invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'\n", '', $correctedSql);
$estimatedSql = str_replace(
    [
        'WITH spend_by_instance AS (',
        'SUM(fd.total * fd.fund_distributions__value * 0.01)',
        "    FROM invoice.invoice_lines__t__fund_distributions fd\n    JOIN invoice.invoice_lines__t invoice_line ON invoice_line.id = fd.id\n    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id\n    JOIN orders.po_line__t pol ON pol.id = fd.po_line_id",
    ],
    [
        "WITH eligible_po_lines AS (\n    SELECT invoice_line.po_line_id AS po_line_id\n    FROM invoice.invoice_lines__t invoice_line\n    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id\n    WHERE invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'\n    GROUP BY invoice_line.po_line_id\n), spend_by_instance AS (",
        'SUM(pol.cost__po_line_estimated_price)',
        "    FROM orders.po_line__t pol\n    JOIN eligible_po_lines eligible ON eligible.po_line_id = pol.id",
    ],
    $estimatedBaseSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($estimatedSql, $estimatedContract)['status'], 'The advertised estimated PO-line price alternative must validate.');
$quotedEligibilitySql = str_replace(
    ['eligible_po_lines eligible ON eligible.po_line_id', 'eligible.po_line_id = pol.id'],
    ['eligible_po_lines "where" ON "where".po_line_id', '"where".po_line_id = pol.id'],
    $estimatedSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($quotedEligibilitySql, $estimatedContract)['status'], 'A quoted arbitrary alias on the enforcing eligibility INNER JOIN must validate.');
semanticAssertRejectedFor(str_replace('pol.cost__po_line_estimated_price', 'invoice_line.total', $estimatedSql), $estimatedContract, 'investment_cost_basis', 'Estimated price must resolve to the PO-line estimated-price column.');
semanticAssertRejectedFor($correctedSql, $estimatedContract, 'investment_cost_basis', 'Paid fund distributions must not satisfy the estimated-price alternative.');
$directEstimatedSql = str_replace(
    ['SUM(fd.total * fd.fund_distributions__value * 0.01)', 'JOIN orders.po_line__t pol ON pol.id = fd.po_line_id'],
    ['SUM(pol.cost__po_line_estimated_price)', 'JOIN orders.po_line__t pol ON pol.id = fd.po_line_id'],
    $correctedSql
);
semanticAssertRejectedFor($directEstimatedSql, $estimatedContract, 'investment_cost_basis', 'Direct invoice-line grain must not multiply PO-line estimated prices.');
semanticAssertRejectedFor(str_replace("    GROUP BY invoice_line.po_line_id\n), spend_by_instance", '), spend_by_instance', $estimatedSql), $estimatedContract, 'investment_cost_basis', 'Estimated eligibility must deduplicate to one row per PO line.');
semanticAssertRejectedFor(str_replace('GROUP BY invoice_line.po_line_id', 'GROUP BY invoice_line.id', $estimatedSql), $estimatedContract, 'investment_cost_basis', 'Estimated eligibility must group by the selected PO-line id.');
semanticAssertRejectedFor(str_replace('invoice_line.po_line_id AS po_line_id', 'invoice_line.id AS po_line_id', $estimatedSql), $estimatedContract, 'investment_cost_basis', 'Estimated eligibility must select the invoice-line PO-line id.');
semanticAssertRejectedFor(str_replace('eligible.po_line_id = pol.id', 'eligible.po_line_id = pol.instance_id', $estimatedSql), $estimatedContract, 'investment_cost_basis', 'Estimated spending must join eligibility PO-line id to PO-line id.');
foreach (['LEFT', 'RIGHT', 'FULL'] as $nullableEligibilityJoin) {
    $nullableEligibilitySql = str_replace('JOIN eligible_po_lines eligible ON', $nullableEligibilityJoin . ' JOIN eligible_po_lines eligible ON', $estimatedSql);
    semanticAssertRejectedFor($nullableEligibilitySql, $estimatedContract, 'investment_cost_basis', 'Estimated eligibility must restrict spending through an enforcing INNER JOIN.');
}
$whereOnlyEligibilitySql = str_replace(
    'JOIN eligible_po_lines eligible ON eligible.po_line_id = pol.id',
    "LEFT JOIN eligible_po_lines eligible ON eligible.po_line_id = eligible.po_line_id\n    WHERE eligible.po_line_id = pol.id",
    $estimatedSql
);
semanticAssertRejectedFor($whereOnlyEligibilitySql, $estimatedContract, 'investment_cost_basis', 'A disconnected nullable eligibility join must not gain trust from a separate WHERE equality.');
$estimatedDecoySql = str_replace(
    'WITH eligible_po_lines AS (',
    'WITH eligibility_decoy AS (SELECT 1 AS po_line_id), eligible_po_lines AS (',
    $estimatedSql
);
$estimatedDecoySql = str_replace('JOIN eligible_po_lines eligible ON', 'JOIN eligibility_decoy eligible ON', $estimatedDecoySql);
semanticAssertRejectedFor($estimatedDecoySql, $estimatedContract, 'investment_cost_basis', 'An unused correct eligibility CTE must not lend trust to the joined decoy.');
$estimatedInvoiceQuestion = $estimatedQuestion . ' Use invoice date.';
$estimatedInvoiceContract = ExploratorySemanticContractService::build($estimatedInvoiceQuestion, null, ExploratoryQueryDefaultsService::resolve($estimatedInvoiceQuestion), 'unsupported_query_family', ['physicalRoiPolicyVersion' => 'legacy']);
$estimatedInvoiceSql = str_replace('invoice.payment_date', 'invoice.invoice_date', $estimatedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($estimatedInvoiceSql, $estimatedInvoiceContract)['status'], 'Estimated PO-line eligibility must support the advertised invoice-date basis.');

$lifetimeQuestion = $question . ' Use lifetime circulation.';
$lifetimeContract = ExploratorySemanticContractService::build($lifetimeQuestion, null, ExploratoryQueryDefaultsService::resolve($lifetimeQuestion), 'unsupported_query_family', ['physicalRoiPolicyVersion' => 'legacy']);
$lifetimeSql = str_replace("     AND audit_loan.created_date >= CURRENT_DATE - INTERVAL '5 years'\n", '', $correctedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($lifetimeSql, $lifetimeContract)['status'], 'The advertised lifetime-circulation alternative must validate without an audit window.');
semanticAssertRejectedFor($correctedSql, $lifetimeContract, 'circulation_window', 'A five-year audit restriction must not satisfy lifetime circulation.');
semanticAssertRejectedFor(str_replace('GROUP BY item.id, item.holdings_record_id', "WHERE item.updated_at >= CURRENT_DATE - INTERVAL '5 years'\n    GROUP BY item.id, item.holdings_record_id", $lifetimeSql), $lifetimeContract, 'circulation_window', 'Unrelated date-window facts must not satisfy lifetime circulation.');

$firstTwoQuestion = $question . ' Group by the first two call number letters.';
$firstTwoContract = ExploratorySemanticContractService::build($firstTwoQuestion, null, ExploratoryQueryDefaultsService::resolve($firstTwoQuestion), 'unsupported_query_family', ['physicalRoiPolicyVersion' => 'legacy']);
$firstTwoSql = str_replace("MIN(SUBSTRING(holdings.effective_call_number_components__call_number FROM '^[A-Za-z]+'))", 'MIN(SUBSTRING(holdings.effective_call_number_components__call_number FROM 1 FOR 2))', $correctedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($firstTwoSql, $firstTwoContract)['status'], 'The advertised first-two-call-number-letters alternative must validate.');
semanticAssertRejectedFor($correctedSql, $firstTwoContract, 'call_number_grouping', 'Primary-class extraction must not satisfy the first-two-letter alternative.');
semanticAssertRejectedFor(str_replace('MIN(SUBSTRING(holdings.effective_call_number_components__call_number FROM 1 FOR 2))', 'MIN(holdings.effective_call_number_components__call_number)', $firstTwoSql), $firstTwoContract, 'call_number_grouping', 'Raw call numbers must not satisfy first-two-letter grouping.');

$costOnlyQuestion = $question . ' Use cost per checkout.';
$costOnlyContract = ExploratorySemanticContractService::build($costOnlyQuestion, null, ExploratoryQueryDefaultsService::resolve($costOnlyQuestion), 'unsupported_query_family', ['physicalRoiPolicyVersion' => 'legacy']);
$costOnlySql = str_replace("       SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0) AS checkouts_per_dollar,\n", '', $correctedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($costOnlySql, $costOnlyContract)['status'], 'The advertised cost-per-checkout-only alternative must validate without checkouts per dollar.');
semanticAssertRejectedFor(str_replace('SUM(spend_by_instance.spend) / NULLIF(SUM(circulation_by_instance.circulation), 0)', 'SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0)', $costOnlySql), $costOnlyContract, 'roi_formula', 'Cost per checkout must remain spend over circulation.');
$formattedOptionalRoiSql = str_replace(
    '       SUM(spend_by_instance.spend) / NULLIF',
    "       TO_CHAR(SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0), 'FM999.00') AS checkouts_per_dollar,\n       SUM(spend_by_instance.spend) / NULLIF",
    $costOnlySql
);
semanticAssertRejectedFor($formattedOptionalRoiSql, $costOnlyContract, 'numeric_output_types', 'A returned optional analytical measure must remain numeric.');
$descriptiveOutputSql = str_replace('SELECT class_by_instance.call_number_class,', "SELECT class_by_instance.call_number_class,\n       CONCAT('ROI') AS note,", $costOnlySql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($descriptiveOutputSql, $costOnlyContract)['status'], 'Unknown descriptive outputs need not be numeric.');

$aliasedFinalSql = str_replace(
    [
        'spend_by_instance.purchase_count', 'spend_by_instance.spend',
        'circulation_by_instance.circulation', 'class_by_instance.call_number_class',
        'FROM spend_by_instance', 'JOIN class_by_instance ON', 'LEFT JOIN circulation_by_instance ON',
    ],
    [
        's.purchase_count', 's.spend',
        'c.circulation', 'cb.call_number_class',
        'FROM spend_by_instance s', 'JOIN class_by_instance cb ON', 'LEFT JOIN circulation_by_instance c ON',
    ],
    $correctedSql
);
$aliasedFinalSql = str_replace(
    ['class_by_instance.instance_id = spend_by_instance.instance_id', 'circulation_by_instance.instance_id = spend_by_instance.instance_id'],
    ['cb.instance_id = s.instance_id', 'c.instance_id = s.instance_id'],
    $aliasedFinalSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($aliasedFinalSql, $contract)['status'], 'Arbitrary final aliases bound to approved CTEs must validate.');

$decoySql = str_replace(
    ")\nSELECT class_by_instance.call_number_class,",
    "), decoy AS (SELECT 1 AS instance_id, 1 AS purchase_count, 1 AS spend, 1 AS circulation, 'X' AS call_number_class)\nSELECT class_by_instance.call_number_class,",
    $correctedSql
);
semanticAssertRejectedFor(str_replace('FROM spend_by_instance', 'FROM decoy spend_by_instance', $decoySql), $contract, 'roi_formula', 'Spend qualifier text must not override its final CTE binding.');
$unusedSpendSql = str_replace('FROM spend_by_instance', 'FROM decoy spend_by_instance', $decoySql);
semanticAssertRejectedFor($unusedSpendSql, $contract, 'purchase_date_basis', 'An unused valid spend CTE must not satisfy purchase-date proof.');
semanticAssertRejectedFor($unusedSpendSql, $contract, 'investment_cost_basis', 'An unused valid spend CTE must not satisfy cost-basis proof.');
semanticAssertRejectedFor($unusedSpendSql, $contract, 'spend_grain', 'An unused valid spend CTE must not satisfy spend-grain proof.');
$mixedPurchaseSql = str_replace(
    ['SUM(spend_by_instance.purchase_count) AS purchase_count', 'JOIN class_by_instance ON'],
    ['SUM(purchase_source.purchase_count) AS purchase_count', 'JOIN decoy purchase_source ON purchase_source.instance_id = spend_by_instance.instance_id' . "\n" . 'JOIN class_by_instance ON'],
    $decoySql
);
semanticAssertRejectedFor($mixedPurchaseSql, $contract, 'spend_grain', 'Final purchase count must resolve to the same validated spend CTE as final spending.');
semanticAssertRejectedFor(str_replace('LEFT JOIN circulation_by_instance ON', 'LEFT JOIN decoy circulation_by_instance ON', $decoySql), $contract, 'roi_formula', 'Circulation qualifier text must not override its final CTE binding.');
$unusedCirculationSql = str_replace('LEFT JOIN circulation_by_instance ON', 'LEFT JOIN decoy circulation_by_instance ON', $decoySql);
semanticAssertRejectedFor($unusedCirculationSql, $contract, 'circulation_window', 'An unused valid checkout chain must not satisfy circulation-window proof.');
semanticAssertRejectedFor($unusedCirculationSql, $contract, 'circulation_grain', 'An unused valid checkout chain must not satisfy circulation-grain proof.');
semanticAssertRejectedFor(str_replace('JOIN class_by_instance ON', 'JOIN decoy class_by_instance ON', $decoySql), $contract, 'call_number_grouping', 'Class qualifier text must not override its final CTE binding.');

$aliasedCheckoutDependencySql = str_replace(
    ['circulation_by_item.checkouts', 'circulation_by_item.holdings_record_id', 'FROM circulation_by_item'],
    ['checkout_source.checkouts', 'checkout_source.holdings_record_id', 'FROM circulation_by_item checkout_source'],
    $correctedSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($aliasedCheckoutDependencySql, $contract)['status'], 'An arbitrary alias bound to the validated checkout dependency must pass.');
$constantCirculationSql = str_replace(
    "SELECT holdings.instance_id,\n           SUM(circulation_by_item.checkouts) AS circulation\n    FROM circulation_by_item\n    JOIN inventory.holdings_record__t holdings ON holdings.id = circulation_by_item.holdings_record_id\n    GROUP BY holdings.instance_id",
    "SELECT audit_loan.loan__item_id AS instance_id,\n           1 AS circulation\n    FROM circulation.audit_loan__t audit_loan",
    $correctedSql
);
semanticAssertRejectedFor($constantCirculationSql, $contract, 'circulation_grain', 'Direct audit lineage with a constant circulation measure must not satisfy the validated checkout chain.');
semanticAssertRejectedFor($constantCirculationSql, $contract, 'roi_formula', 'A constant final circulation source must not satisfy ROI lineage.');
$checkoutDecoySql = str_replace(
    '), circulation_by_instance AS (',
    "), checkout_decoy AS (SELECT 1 AS holdings_record_id, 1 AS checkouts), circulation_by_instance AS (",
    $correctedSql
);
$checkoutDecoySql = str_replace('FROM circulation_by_item', 'FROM checkout_decoy circulation_by_item', $checkoutDecoySql);
semanticAssertRejectedFor($checkoutDecoySql, $contract, 'circulation_grain', 'A trusted dependency alias rebound to an unrelated CTE must not use an unused valid item-grain CTE.');
semanticAssertRejectedFor($checkoutDecoySql, $contract, 'roi_formula', 'A decoy checkout dependency must not satisfy circulation measure lineage.');

$captured = ExploratorySqlSemanticValidatorService::validate($capturedProductionSql, $contract);
semanticAssertSame('rejected', $captured['status'], 'Captured flawed production SQL must be blocked.');
semanticAssertContainsAll(
    ['purchase_date_basis', 'spend_grain', 'purchase_ranking', 'governed_filters', 'numeric_output_types'],
    array_column($captured['violations'], 'key'),
    'Known production defects must be detected together.'
);

$invoiceContract = $contract;
$invoiceContract['requirements'][0]['parameters']['value'] = 'invoice_date';
semanticAssertRejectedFor($correctedSql, $invoiceContract, 'purchase_date_basis', 'Payment date must not satisfy an invoice-date assumption.');
$invoiceSql = str_replace('invoice.payment_date', 'invoice.invoice_date', $correctedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($invoiceSql, $invoiceContract)['status'], 'Invoice date must satisfy the explicit invoice-date assumption.');
$alternativeMatrix = [
    ['purchase_date_basis', 'invoice_date', $invoiceContract, $invoiceSql],
    ['investment_cost_basis', 'estimated_po_line_price', $estimatedContract, $estimatedSql],
    ['circulation_window', 'lifetime_circulation', $lifetimeContract, $lifetimeSql],
    ['call_number_grouping', 'first_two_call_number_letters', $firstTwoContract, $firstTwoSql],
    ['roi_formula', 'cost_per_checkout', $costOnlyContract, $costOnlySql],
];
foreach ($alternativeMatrix as [$key, $expectedValue, $alternativeValueContract, $alternativeValueSql]) {
    $requirements = array_column($alternativeValueContract['requirements'], null, 'key');
    semanticAssertSame($expectedValue, $requirements[$key]['parameters']['value'], 'Defaults must emit the expected advertised alternative.');
    semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($alternativeValueSql, $alternativeValueContract)['status'], 'Every advertised alternative must have a deterministic validator-pass fixture.');
}
semanticAssertRejectedFor(str_replace('invoice.payment_date', 'pot.date_ordered', $correctedSql), $contract, 'purchase_date_basis', 'PO order date must not satisfy purchase-date basis.');
$leftInvoiceWindowSql = str_replace(
    [
        'JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id',
        "    WHERE invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'\n",
    ],
    [
        "LEFT JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id\n      AND invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'",
        '',
    ],
    $correctedSql
);
semanticAssertRejectedFor($leftInvoiceWindowSql, $contract, 'purchase_date_basis', 'A purchase window solely on the nullable side of a LEFT JOIN must not count as enforced.');
$innerInvoiceWindowSql = str_replace('LEFT JOIN invoice.invoices__t invoice', 'INNER JOIN invoice.invoices__t invoice', $leftInvoiceWindowSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($innerInvoiceWindowSql, $contract)['status'], 'A purchase window in the approved invoice INNER JOIN predicate must count as enforced.');
$leftInvoiceWhereSql = str_replace('JOIN invoice.invoices__t invoice', 'LEFT JOIN invoice.invoices__t invoice', $correctedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($leftInvoiceWhereSql, $contract)['status'], 'An approved invoice window in WHERE must enforce the nullable invoice side.');
$unknownDateContract = $contract;
$unknownDateContract['requirements'][0]['parameters']['value'] = 'future_date_basis';
semanticAssertRejectedFor($correctedSql, $unknownDateContract, 'purchase_date_basis', 'Unknown date assumptions must fail closed.');

semanticAssertRejectedFor(str_replace('fd.total * fd.fund_distributions__value * 0.01', 'fd.total', $correctedSql), $contract, 'investment_cost_basis', 'Paid fund distribution amount must include its percentage.');
semanticAssertRejectedFor(str_replace('fd.total * fd.fund_distributions__value * 0.01', 'fd.total + fd.fund_distributions__value * 0.01', $correctedSql), $contract, 'investment_cost_basis', 'Paid amount and percentage must be multiplication operands.');
semanticAssertRejectedFor(str_replace('fd.total * fd.fund_distributions__value * 0.01', 'fd.total * 0.01 + fd.fund_distributions__value', $correctedSql), $contract, 'investment_cost_basis', 'Disconnected percentage occurrences must not satisfy cost math.');
semanticAssertRejectedFor(str_replace('fd.total * fd.fund_distributions__value * 0.01', 'fd.total * fd.fund_distributions__value * 0.01 + fd.fund_distributions__value', $correctedSql), $contract, 'investment_cost_basis', 'A disconnected repeated percentage must fail exactly-once cost math.');
semanticAssertRejectedFor(str_replace('fd.total * fd.fund_distributions__value * 0.01', 'invoice_line.total * fd.fund_distributions__value * 0.01', $correctedSql), $contract, 'investment_cost_basis', 'Paid amount and percentage must come from the same fund distribution.');
semanticAssertRejectedFor(str_replace('fd.total * fd.fund_distributions__value * 0.01', 'fake_fd.total * fake_fd.fund_distributions__value * 0.01', $correctedSql), $contract, 'investment_cost_basis', 'Cost factors must resolve to the approved fund-distribution source alias.');
foreach ([
    'fd.total * fd.fund_distributions__value + 1 / 100',
    'fd.total * fd.fund_distributions__value',
    'fd.total * fd.fund_distributions__value * 0.01 * invoice_line.total',
    'fd.total * fd.total * fd.fund_distributions__value * 0.01',
    '(fd.total + 1) * fd.fund_distributions__value * 0.01',
    'fd.total * (fd.fund_distributions__value + 1) * 0.01',
] as $invalidCostExpression) {
    semanticAssertRejectedFor(str_replace('fd.total * fd.fund_distributions__value * 0.01', $invalidCostExpression, $correctedSql), $contract, 'investment_cost_basis', 'Percentage scaling must be an exact factor of the paid amount product.');
}
semanticAssertRejectedFor(str_replace('fd.total * fd.fund_distributions__value * 0.01', 'fd.total * fd.fund_distributions__value * fd.fund_distributions__value * 0.0001', $correctedSql), $contract, 'investment_cost_basis', 'Fund distribution percentage must be applied exactly once.');
$unknownCostContract = $contract;
$unknownCostContract['requirements'][1]['parameters']['value'] = 'future_cost_basis';
semanticAssertRejectedFor($correctedSql, $unknownCostContract, 'investment_cost_basis', 'Unknown cost assumptions must fail closed.');

$itemSpendSql = str_replace('JOIN orders.po_line__t pol ON pol.id = fd.po_line_id', "JOIN orders.po_line__t pol ON pol.id = fd.po_line_id\n    JOIN inventory.item__t spend_item ON spend_item.id = pol.id", $correctedSql);
semanticAssertRejectedFor($itemSpendSql, $contract, 'spend_grain', 'Spending must aggregate before item dependencies.');
$directDependencySql = str_replace(
    ['WITH spend_by_instance AS (', 'JOIN orders.po_line__t pol ON pol.id = fd.po_line_id'],
    ["WITH item_source AS (SELECT item.id FROM inventory.item__t item),\nspend_by_instance AS (", "JOIN orders.po_line__t pol ON pol.id = fd.po_line_id\n    JOIN item_source ON item_source.id = pol.id"],
    $correctedSql
);
semanticAssertRejectedFor($directDependencySql, $contract, 'spend_grain', 'A direct item-lineage CTE dependency must fail spend grain.');
$transitiveDependencySql = str_replace(
    ['WITH spend_by_instance AS (', 'JOIN orders.po_line__t pol ON pol.id = fd.po_line_id'],
    ["WITH item_source AS (SELECT item.id FROM inventory.item__t item),\nitem_wrapper AS (SELECT item_source.id FROM item_source),\nspend_by_instance AS (", "JOIN orders.po_line__t pol ON pol.id = fd.po_line_id\n    JOIN item_wrapper ON item_wrapper.id = pol.id"],
    $correctedSql
);
semanticAssertRejectedFor($transitiveDependencySql, $contract, 'spend_grain', 'Transitive item-lineage CTE dependencies must fail spend grain.');
semanticAssertRejectedFor(str_replace('SUM(fd.total * fd.fund_distributions__value * 0.01)', 'fd.total * fd.fund_distributions__value * 0.01', $correctedSql), $contract, 'spend_grain', 'Spending must be aggregated in its isolated CTE.');
semanticAssertRejectedFor(str_replace('COUNT(DISTINCT pol.id) AS purchase_count', 'SUM(pol.id) AS purchase_count', $correctedSql), $contract, 'spend_grain', 'Purchase count must use the approved distinct PO-line count.');
semanticAssertRejectedFor(str_replace('COUNT(DISTINCT pol.id) AS purchase_count', 'COUNT(pol.id) AS purchase_count', $correctedSql), $contract, 'spend_grain', 'Purchase count must retain DISTINCT.');
semanticAssertRejectedFor(str_replace('COUNT(DISTINCT pol.id) AS purchase_count', 'COUNT(DISTINCT pol.instance_id) AS purchase_count', $correctedSql), $contract, 'spend_grain', 'Purchase count must count the PO-line id.');
semanticAssertRejectedFor(str_replace('COUNT(DISTINCT pol.id) AS purchase_count', 'COUNT(DISTINCT invoice_line.id) AS purchase_count', $correctedSql), $contract, 'spend_grain', 'Purchase count must resolve to the PO-line source.');
$aliasedPoLineSql = str_replace(['pol.', 'orders.po_line__t pol'], ['purchase_line.', 'orders.po_line__t purchase_line'], $correctedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($aliasedPoLineSql, $contract)['status'], 'An arbitrary alias bound to PO line must satisfy purchase-count proof.');
semanticAssertRejectedFor(str_replace("     AND audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')\n", '', $correctedSql), $contract, 'circulation_grain', 'Checkout action must be enforced in the item-grain circulation CTE.');
semanticAssertRejectedFor(str_replace("audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')", "audit_loan.loan__action NOT IN ('checkedout', 'checkedOutThroughOverride')", $correctedSql), $contract, 'circulation_grain', 'NOT IN checkout actions must be rejected.');
semanticAssertRejectedFor(str_replace("audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')", "NOT (audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride'))", $correctedSql), $contract, 'circulation_grain', 'Parenthesized NOT checkout actions must be rejected.');
semanticAssertRejectedFor(str_replace("audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')", "audit_loan.loan__action <> 'returned' AND audit_loan.note = 'checkedout'", $correctedSql), $contract, 'circulation_grain', 'Unrelated checkout substrings must not satisfy action inclusion.');
semanticAssertRejectedFor(str_replace("audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')", "audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride') OR 1 = 1", $correctedSql), $contract, 'circulation_grain', 'OR must invalidate checkout-action proof.');
semanticAssertRejectedFor(str_replace("audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')", "COALESCE(audit_loan.loan__action = 'checkedout', FALSE)", $correctedSql), $contract, 'circulation_grain', 'Unsupported Boolean functions must fail closed.');
semanticAssertRejectedFor(str_replace("audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')", "CASE WHEN audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride') THEN TRUE ELSE TRUE END", $correctedSql), $contract, 'circulation_grain', 'Embedded CASE comparisons must not become checkout-action facts.');
$brokenItemJoinSql = str_replace('audit_loan.loan__item_id = item.id', 'audit_loan.loan__item_id = item.holdings_record_id', $correctedSql);
semanticAssertRejectedFor($brokenItemJoinSql, $contract, 'circulation_grain', 'Checkout item id must join to the inventory item id before aggregation.');
$eventGrainSql = str_replace('GROUP BY item.id, item.holdings_record_id', 'GROUP BY item.id, audit_loan.id, item.holdings_record_id', $correctedSql);
semanticAssertRejectedFor($eventGrainSql, $contract, 'circulation_grain', 'Event-grain grouping must not satisfy item-grain aggregation.');
$fakeItemSql = str_replace(
    ['item.id AS item_id', 'GROUP BY item.id, item.holdings_record_id'],
    ['fake_item.id AS item_id', 'GROUP BY fake_item.id, item.holdings_record_id'],
    $correctedSql
);
semanticAssertRejectedFor($fakeItemSql, $contract, 'circulation_grain', 'Unresolved item-like aliases must not satisfy item grain.');
$singleActionSql = str_replace("audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')", "audit_loan.loan__action = 'checkedout'", $correctedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($singleActionSql, $contract)['status'], 'Positive equality on an approved checkout action must be accepted.');
semanticAssertRejectedFor(str_replace('COUNT(audit_loan.created_date) AS checkouts', '1 AS checkouts', $correctedSql), $contract, 'circulation_grain', 'A constant must not satisfy checkout aggregate proof.');
semanticAssertRejectedFor(str_replace('COUNT(audit_loan.created_date) AS checkouts', 'SUM(item.id) AS checkouts', $correctedSql), $contract, 'circulation_grain', 'An unrelated SUM must not satisfy checkout aggregate proof.');
semanticAssertRejectedFor(str_replace('COUNT(audit_loan.created_date) AS checkouts', 'COUNT(item.id) AS checkouts', $correctedSql), $contract, 'circulation_grain', 'COUNT of inventory item must not satisfy checkout aggregate proof.');
$auditIdCountSql = str_replace('COUNT(audit_loan.created_date) AS checkouts', 'COUNT(audit_loan.id) AS checkouts', $correctedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($auditIdCountSql, $contract)['status'], 'COUNT of an audit-loan id must satisfy checkout aggregate proof.');
$unrelatedAuditPredicateSql = str_replace(
    [
        "     AND audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')\n     AND audit_loan.created_date >= CURRENT_DATE - INTERVAL '5 years'",
        '    GROUP BY item.id, item.holdings_record_id',
    ],
    [
        '',
        "    LEFT JOIN circulation.audit_loan__t other_audit\n      ON other_audit.loan__item_id = item.id\n     AND other_audit.loan__action IN ('checkedout', 'checkedOutThroughOverride')\n     AND other_audit.created_date >= CURRENT_DATE - INTERVAL '5 years'\n    GROUP BY item.id, item.holdings_record_id",
    ],
    $correctedSql
);
semanticAssertRejectedFor($unrelatedAuditPredicateSql, $contract, 'circulation_grain', 'Checkout action on an unrelated LEFT JOIN must not constrain the audit alias being counted.');
semanticAssertRejectedFor($unrelatedAuditPredicateSql, $contract, 'circulation_window', 'Checkout date on an unrelated LEFT JOIN must not constrain the audit alias being counted.');
semanticAssertRejectedFor(str_replace("     AND audit_loan.created_date >= CURRENT_DATE - INTERVAL '5 years'\n", '', $correctedSql), $contract, 'circulation_window', 'Circulation date window must be enforced in the item-grain circulation CTE.');
semanticAssertRejectedFor(str_replace("audit_loan.created_date >= CURRENT_DATE - INTERVAL '5 years'", "audit_loan.created_date >= CURRENT_DATE - INTERVAL '3 years'", $correctedSql), $contract, 'circulation_window', 'Circulation must use the same date window as purchases.');
$unknownWindowContract = $contract;
$unknownWindowContract['requirements'][3]['parameters']['value'] = 'future_window';
semanticAssertRejectedFor($correctedSql, $unknownWindowContract, 'circulation_window', 'Unknown circulation windows must fail closed.');
$incidentalPaymentDateSql = str_replace(
    "invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'",
    "invoice.invoice_date >= CURRENT_DATE - INTERVAL '5 years' AND invoice.payment_date IS NOT NULL",
    $correctedSql
);
semanticAssertRejectedFor($incidentalPaymentDateSql, $contract, 'purchase_date_basis', 'The expected date basis must own the qualifying purchase window.');
semanticAssertRejectedFor(str_replace('invoice.payment_date', 'fake_invoice.payment_date', $correctedSql), $contract, 'purchase_date_basis', 'Purchase date qualifiers must resolve to the approved invoice source.');
semanticAssertRejectedFor(str_replace('GROUP BY item.id, item.holdings_record_id', 'GROUP BY item.holdings_record_id', $correctedSql), $contract, 'circulation_grain', 'Circulation must aggregate at item grain.');
semanticAssertRejectedFor(str_replace('GROUP BY class_by_instance.call_number_class', 'GROUP BY class_by_instance.instance_id', $correctedSql), $contract, 'call_number_grouping', 'Call-number class must be the final grouping dimension.');
semanticAssertRejectedFor(str_replace('GROUP BY class_by_instance.call_number_class', 'GROUP BY class_by_instance.not_call_number_class', $correctedSql), $contract, 'call_number_grouping', 'Substring grouping aliases must be rejected.');
semanticAssertRejectedFor(str_replace('class_by_instance.call_number_class', 'spend_by_instance.call_number_class', $correctedSql), $contract, 'call_number_grouping', 'Call-number grouping must have proven call-number expression lineage.');
semanticAssertRejectedFor(str_replace('holdings.effective_call_number_components__call_number', 'fake_holdings.effective_call_number_components__call_number', $correctedSql), $contract, 'call_number_grouping', 'Call-number derivation qualifiers must resolve to an approved inventory source.');
semanticAssertRejectedFor(
    str_replace("MIN(SUBSTRING(holdings.effective_call_number_components__call_number FROM '^[A-Za-z]+'))", 'MIN(holdings.effective_call_number_components__call_number)', $correctedSql),
    $contract,
    'call_number_grouping',
    'Raw full call numbers must not satisfy class derivation.'
);
$documentedClassExpression = <<<'SQL'
MIN(CASE
    WHEN holdings.effective_call_number_components__call_number ~ '^[A-Z]{1,3}[0-9]'
    THEN REGEXP_REPLACE(holdings.effective_call_number_components__call_number, '^([A-Z]{1,3})[0-9].*', '\1')
    WHEN holdings.effective_call_number_components__call_number ~ '^[0-9]'
    THEN LPAD(
        CAST(FLOOR(CAST(REGEXP_REPLACE(holdings.effective_call_number_components__call_number, '^([0-9]+).*', '\1') AS NUMERIC) / 100) * 100 AS TEXT),
        3,
        '0'
    )
    ELSE 'Unknown'
END)
SQL;
$documentedClassSql = str_replace(
    "MIN(SUBSTRING(holdings.effective_call_number_components__call_number FROM '^[A-Za-z]+'))",
    $documentedClassExpression,
    $correctedSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($documentedClassSql, $contract)['status'], 'The documented CASE/REGEXP_REPLACE/LPAD class derivation must be accepted.');
$unknownGroupingContract = $contract;
$unknownGroupingContract['requirements'][5]['parameters']['value'] = 'future_grouping';
semanticAssertRejectedFor($correctedSql, $unknownGroupingContract, 'call_number_grouping', 'Unknown call-number grouping assumptions must fail closed.');

$missingMeasureSql = str_replace("       SUM(circulation_by_instance.circulation) AS circulation,\n", '', $correctedSql);
semanticAssertRejectedFor($missingMeasureSql, $contract, 'required_measures', 'Every required numeric alias must exist.');
semanticAssertRejectedFor(str_replace('SUM(spend_by_instance.spend) AS spend', "TO_CHAR(SUM(spend_by_instance.spend), 'FM999') AS spend", $correctedSql), $contract, 'numeric_output_types', 'TO_CHAR measures must be rejected.');
semanticAssertRejectedFor(str_replace('SUM(spend_by_instance.spend) AS spend', "'$' || SUM(spend_by_instance.spend) AS spend", $correctedSql), $contract, 'numeric_output_types', 'Concatenated currency measures must be rejected.');
semanticAssertRejectedFor(str_replace('SUM(spend_by_instance.spend) AS spend', "'USD' AS spend", $correctedSql), $contract, 'numeric_output_types', 'Currency-string constructions must be rejected.');
semanticAssertRejectedFor(str_replace('SUM(spend_by_instance.spend) AS spend', 'CONCAT(SUM(spend_by_instance.spend)) AS spend', $correctedSql), $contract, 'numeric_output_types', 'Unknown text-returning functions must fail numeric proof.');
foreach ([
    'CAST(SUM(spend_by_instance.spend) AS TEXT)',
    'SUM(spend_by_instance.spend)::text',
    'SUM(spend_by_instance.spend)::varchar',
    'SUM(spend_by_instance.spend)::char',
] as $textSpendExpression) {
    semanticAssertRejectedFor(str_replace('SUM(spend_by_instance.spend) AS spend', $textSpendExpression . ' AS spend', $correctedSql), $contract, 'numeric_output_types', 'Text casts of required numeric aliases must be rejected.');
}
semanticAssertRejectedFor(str_replace('NULLIF(SUM(spend_by_instance.spend), 0)', 'SUM(spend_by_instance.spend)', $correctedSql), $contract, 'roi_formula', 'Checkouts per dollar must be zero-safe.');
semanticAssertRejectedFor(str_replace('NULLIF(SUM(circulation_by_instance.circulation), 0)', 'SUM(circulation_by_instance.circulation)', $correctedSql), $contract, 'roi_formula', 'Cost per checkout must be zero-safe.');
semanticAssertRejectedFor(str_replace('SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0) AS checkouts_per_dollar', 'SUM(spend_by_instance.spend) / NULLIF(SUM(circulation_by_instance.circulation), 0) AS checkouts_per_dollar', $correctedSql), $contract, 'roi_formula', 'Checkouts per dollar must use circulation over spend.');
semanticAssertRejectedFor(str_replace('SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0) AS checkouts_per_dollar', 'NULLIF(SUM(circulation_by_instance.circulation), 0) / SUM(spend_by_instance.spend) AS checkouts_per_dollar', $correctedSql), $contract, 'roi_formula', 'Zero safety must protect the actual ROI denominator.');
semanticAssertRejectedFor(str_replace('NULLIF(SUM(spend_by_instance.spend), 0) AS checkouts_per_dollar', 'NULLIF(SUM(other.spend), 0) AS checkouts_per_dollar', $correctedSql), $contract, 'roi_formula', 'ROI denominator aliases must have proven spend lineage.');
$unsafeCaseSql = str_replace('NULLIF(SUM(spend_by_instance.spend), 0)', 'CASE WHEN SUM(spend_by_instance.spend) = 0 THEN NULL ELSE SUM(spend_by_instance.spend) + 1 END', $correctedSql);
semanticAssertRejectedFor($unsafeCaseSql, $contract, 'roi_formula', 'A CASE must test the exact denominator expression it returns.');
$multiBranchCaseSql = str_replace(
    'NULLIF(SUM(spend_by_instance.spend), 0)',
    'CASE WHEN SUM(spend_by_instance.spend) = 0 THEN NULL WHEN SUM(spend_by_instance.spend) < 0 THEN NULL ELSE SUM(spend_by_instance.spend) END',
    $correctedSql
);
semanticAssertRejectedFor($multiBranchCaseSql, $contract, 'roi_formula', 'Zero-safe CASE must contain exactly one WHEN branch.');
semanticAssertRejectedFor(str_replace('SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0)', '(SUM(circulation_by_instance.circulation) + 1) / NULLIF(SUM(spend_by_instance.spend), 0)', $correctedSql), $contract, 'roi_formula', 'ROI numerator must be exactly one permitted aggregate.');
semanticAssertRejectedFor(str_replace('SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0)', 'SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend) + 1, 0)', $correctedSql), $contract, 'roi_formula', 'ROI denominator must be exactly one permitted aggregate.');
$caseSafeSql = str_replace('NULLIF(SUM(spend_by_instance.spend), 0)', 'CASE WHEN SUM(spend_by_instance.spend) = 0 THEN NULL ELSE SUM(spend_by_instance.spend) END', $correctedSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($caseSafeSql, $contract)['status'], 'An equivalent zero-safe CASE must be accepted.');
$unknownRoiContract = $contract;
$unknownRoiContract['requirements'][7]['parameters']['value'] = 'future_roi';
semanticAssertRejectedFor($correctedSql, $unknownRoiContract, 'roi_formula', 'Unknown ROI assumptions must fail closed.');
semanticAssertRejectedFor(str_replace('ORDER BY purchase_count DESC', 'ORDER BY purchase_count ASC', $correctedSql), $contract, 'purchase_ranking', 'Purchase count must rank descending before LIMIT.');
semanticAssertRejectedFor(str_replace('ORDER BY purchase_count DESC', 'ORDER BY spend DESC, purchase_count DESC', $correctedSql), $contract, 'purchase_ranking', 'Purchase count must be the first ranking priority.');
semanticAssertRejectedFor(str_replace(['COUNT(DISTINCT pol.id) AS purchase_count', 'ORDER BY purchase_count DESC'], ['SUM(pol.id) AS purchase_count', 'ORDER BY purchase_count DESC'], $correctedSql), $contract, 'purchase_ranking', 'Descending ranking must trace to the exact approved purchase-count measure.');
semanticAssertRejectedFor(str_replace('ORDER BY purchase_count DESC', 'LIMIT 10', $correctedSql), $contract, 'purchase_ranking', 'LIMIT without descending purchase ranking must be rejected.');
semanticAssertRejectedFor(str_replace('ORDER BY purchase_count DESC', 'LIMIT 10 ORDER BY purchase_count DESC', $correctedSql), $contract, 'purchase_ranking', 'Malformed clause ordering must fail closed.');
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate(str_replace('ORDER BY purchase_count DESC', "ORDER BY purchase_count DESC\nLIMIT 10", $correctedSql), $contract)['status'], 'Descending purchase ranking before LIMIT must be accepted.');

$campusContract = $contract;
$campusIndex = array_search('campus_scope', array_column($campusContract['requirements'], 'key'), true);
$campusContract['requirements'][$campusIndex]['parameters'] = ['required' => true, 'value' => 'Smith College'];
$campusContract['permittedFilters']['campus'] = ['value' => 'Smith College', 'provenance' => 'selected_scope'];
semanticAssertRejectedFor($correctedSql, $campusContract, 'campus_scope', 'A supplied campus must be enforced.');
$unusedCampusSql = str_replace(
    'WITH spend_by_instance AS (',
    "WITH unused_campus AS (SELECT loccampus.id FROM inventory.loccampus__t loccampus WHERE loccampus.name = 'Smith College'),\nspend_by_instance AS (",
    $correctedSql
);
semanticAssertRejectedFor($unusedCampusSql, $campusContract, 'campus_scope', 'An unused campus-filter CTE must not satisfy campus scope.');
$campusSql = str_replace(
    '    GROUP BY item.id, item.holdings_record_id',
    "    JOIN inventory.location__t circ_location ON circ_location.id = item.effective_location_id\n    JOIN inventory.loclibrary__t circ_library ON circ_library.id = circ_location.library_id\n    JOIN inventory.loccampus__t selected_scope ON selected_scope.id = circ_library.campus_id\n    WHERE selected_scope.name = 'Smith College'\n    GROUP BY item.id, item.holdings_record_id",
    $correctedSql
);
$campusSql = str_replace(
    '), class_by_instance AS (',
    "), campus_instances AS (\n    SELECT scope_holdings.instance_id AS instance_id\n    FROM inventory.item__t scope_item\n    JOIN inventory.holdings_record__t scope_holdings ON scope_holdings.id = scope_item.holdings_record_id\n    JOIN inventory.location__t scope_location ON scope_location.id = scope_item.effective_location_id\n    JOIN inventory.loclibrary__t scope_library ON scope_library.id = scope_location.library_id\n    JOIN inventory.loccampus__t selected_scope ON selected_scope.id = scope_library.campus_id\n    WHERE selected_scope.name = 'Smith College'\n    GROUP BY scope_holdings.instance_id\n), class_by_instance AS (",
    $campusSql
);
$campusSql = str_replace(
    "JOIN class_by_instance ON class_by_instance.instance_id = spend_by_instance.instance_id\n",
    "JOIN class_by_instance ON class_by_instance.instance_id = spend_by_instance.instance_id\nJOIN campus_instances ON campus_instances.instance_id = spend_by_instance.instance_id\n",
    $campusSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($campusSql, $campusContract)['status'], 'Campus scope must use approved hierarchy paths in circulation and final acquisition/grouping selection.');
$leftCampusOnSql = str_replace(
    "LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = spend_by_instance.instance_id\nGROUP BY",
    "LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = spend_by_instance.instance_id\nLEFT JOIN inventory.loccampus__t selected_scope ON selected_scope.id = class_by_instance.instance_id AND selected_scope.name = 'Smith College'\nGROUP BY",
    $correctedSql
);
semanticAssertRejectedFor($leftCampusOnSql, $campusContract, 'campus_scope', 'Campus selection solely on the nullable side of a LEFT JOIN must not count as enforced.');
$quotedLeftCampusOnSql = str_replace('selected_scope', '"where"', $leftCampusOnSql);
semanticAssertRejectedFor($quotedLeftCampusOnSql, $campusContract, 'campus_scope', 'Quoted aliases must preserve nullable LEFT JOIN enforcement semantics.');
$outerCampusPathSql = str_replace('JOIN campus_instances ON', 'LEFT JOIN campus_instances ON', $campusSql);
semanticAssertRejectedFor($outerCampusPathSql, $campusContract, 'campus_scope', 'Campus acquisition/grouping lineage on a nullable final path must not count as enforced.');
$wrongCampusKeySql = str_replace('circ_location.id = item.effective_location_id', 'circ_location.id = item.holdings_record_id', $campusSql);
semanticAssertRejectedFor($wrongCampusKeySql, $campusContract, 'campus_scope', 'Wrong inventory hierarchy keys must not satisfy campus scope.');
$circulationOnlyCampusSql = str_replace("JOIN campus_instances ON campus_instances.instance_id = spend_by_instance.instance_id\n", '', $campusSql);
semanticAssertRejectedFor($circulationOnlyCampusSql, $campusContract, 'campus_scope', 'Circulation-only campus lineage must not constrain acquisition/grouping selection.');
$acquisitionOnlyCampusSql = str_replace("    WHERE selected_scope.name = 'Smith College'\n    GROUP BY item.id", '    GROUP BY item.id', $campusSql);
semanticAssertRejectedFor($acquisitionOnlyCampusSql, $campusContract, 'campus_scope', 'Acquisition/grouping-only campus lineage must not constrain circulation inputs.');
$campusJoinPredicateSql = str_replace(
    [
        "JOIN inventory.loccampus__t selected_scope ON selected_scope.id = circ_library.campus_id\n    WHERE selected_scope.name = 'Smith College'",
        "JOIN inventory.loccampus__t selected_scope ON selected_scope.id = scope_library.campus_id\n    WHERE selected_scope.name = 'Smith College'",
    ],
    [
        "JOIN inventory.loccampus__t selected_scope ON selected_scope.id = circ_library.campus_id AND selected_scope.name = 'Smith College'",
        "JOIN inventory.loccampus__t selected_scope ON selected_scope.id = scope_library.campus_id AND selected_scope.name = 'Smith College'",
    ],
    $campusSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($campusJoinPredicateSql, $campusContract)['status'], 'Campus name predicates on approved INNER hierarchy joins must validate.');
$campusInSql = str_replace("selected_scope.name = 'Smith College'", "selected_scope.name IN ('Smith College')", $campusSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($campusInSql, $campusContract)['status'], 'Positive campus IN inclusion must be accepted.');
semanticAssertRejectedFor(str_replace("selected_scope.name = 'Smith College'", "(selected_scope.name = 'Smith College' OR 1 = 1)", $campusSql), $campusContract, 'campus_scope', 'Parenthesized OR must invalidate campus proof.');
semanticAssertRejectedFor(str_replace("selected_scope.name = 'Smith College'", "COALESCE(selected_scope.name = 'Smith College', FALSE)", $campusSql), $campusContract, 'campus_scope', 'Unsupported Boolean wrappers must invalidate campus proof.');
semanticAssertRejectedFor(str_replace("selected_scope.name = 'Smith College'", "CASE WHEN selected_scope.name = 'Smith College' THEN TRUE ELSE TRUE END", $campusSql), $campusContract, 'campus_scope', 'Embedded CASE comparisons must not become campus facts.');
semanticAssertRejectedFor(str_replace("selected_scope.name = 'Smith College'", "(selected_scope.name = 'Smith College') = FALSE", $campusSql), $campusContract, 'campus_scope', 'Nested Boolean comparisons must not become campus facts.');
semanticAssertRejectedFor(str_replace("selected_scope.name = 'Smith College'", "selected_scope.name IN ('Smith College', 'Other College')", $campusSql), $campusContract, 'campus_scope', 'Campus IN must not widen the selected campus scope.');
foreach ([
    "selected_scope.name <> 'Smith College'",
    "selected_scope.name NOT IN ('Smith College')",
    "NOT selected_scope.name = 'Smith College'",
    "NOT (selected_scope.name = 'Smith College')",
    "selected_scope.not_name = 'Smith College'",
] as $invalidCampusPredicate) {
    $invalidCampusSql = str_replace("selected_scope.name = 'Smith College'", $invalidCampusPredicate, $campusSql);
    semanticAssertRejectedFor($invalidCampusSql, $campusContract, 'campus_scope', 'Only positive inclusion on the exact campus column may satisfy campus scope.');
}
$quotedCampusSql = str_replace('selected_scope', '"where"', $campusSql);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($quotedCampusSql, $campusContract)['status'], 'A quoted arbitrary alias bound to loccampus must validate.');
semanticAssertRejectedFor(str_replace('selected_scope.name', 'fake_scope.name', $campusSql), $campusContract, 'campus_scope', 'A fake campus-name qualifier must be rejected even with an unused approved source.');
semanticAssertRejectedFor(str_replace('selected_scope.name', 'fake_scope.campus', $campusSql), $campusContract, 'campus_scope', 'A fake campus-column qualifier must be rejected even with an unused approved source.');
semanticAssertRejectedFor(str_replace("selected_scope.name = 'Smith College'", "invoice.campus = 'Smith College'", $campusSql), $campusContract, 'campus_scope', 'An unapproved campus source must be rejected.');
$campusWithoutProvenance = $campusContract;
$campusWithoutProvenance['permittedFilters']['campus'] = ['value' => 'Smith College'];
semanticAssertRejectedFor($campusSql, $campusWithoutProvenance, 'campus_scope', 'Campus scope without selected-scope provenance must fail closed.');

$physicalContract = ExploratorySemanticContractService::build(
    $question,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($question),
    'unsupported_query_family'
);
$physicalSql = str_replace(
    [
        'COUNT(DISTINCT pol.id) AS purchase_count,',
        'SUM(fd.total * fd.fund_distributions__value * 0.01) AS spend',
        'JOIN orders.po_line__t pol ON pol.id = fd.po_line_id',
        'WHERE invoice.payment_date',
        'audit_loan.created_date >=',
        'SUM(spend_by_instance.purchase_count) AS purchase_count,',
        'ORDER BY purchase_count DESC',
    ],
    [
        "SUM(paid_line.quantity) AS purchase_count,\n           SUM(paid_line.quantity) AS physical_copies_purchased,\n           COUNT(DISTINCT paid_line.instance_id) AS distinct_titles,",
        'SUM(fd.total * (fd.fund_distributions__value * 0.01)) AS spend',
        "JOIN orders.po_line__t paid_line ON paid_line.id = fd.po_line_id\n    JOIN orders.purchase_order__t purchase_order ON purchase_order.id = paid_line.purchase_order_id\n    JOIN orders.purchase_order__t__acq_unit_ids purchase_order_unit ON purchase_order_unit.id = purchase_order.id\n    JOIN orders.acquisitions_unit__t acquisition_unit ON acquisition_unit.id = purchase_order_unit.acq_unit_ids",
        "WHERE paid_line.cost__quantity_physical > 0\n      AND TRIM(acquisition_unit.name) = 'SC'\n      AND invoice.payment_date",
        'audit_loan.loan__loan_date >=',
        "SUM(spend_by_instance.purchase_count) AS purchase_count,\n       SUM(spend_by_instance.physical_copies_purchased) AS physical_copies_purchased,\n       SUM(spend_by_instance.distinct_titles) AS distinct_titles,",
        'ORDER BY physical_copies_purchased DESC',
    ],
    $campusSql
);
$poLineQuantitySql = $physicalSql;
$physicalSql = str_replace(
    'WITH spend_by_instance AS (',
    "WITH exact_paid_lines AS (\n    SELECT invoice_line.id AS invoice_line_id,\n           paid_line.instance_id AS instance_id,\n           invoice_line.quantity AS quantity\n    FROM invoice.invoice_lines__t invoice_line\n    JOIN orders.po_line__t paid_line ON paid_line.id = invoice_line.po_line_id\n    JOIN orders.purchase_order__t purchase_order ON purchase_order.id = paid_line.purchase_order_id\n    JOIN orders.purchase_order__t__acq_unit_ids purchase_order_unit ON purchase_order_unit.id = purchase_order.id\n    JOIN orders.acquisitions_unit__t acquisition_unit ON acquisition_unit.id = purchase_order_unit.acq_unit_ids\n    JOIN orders.pieces__t receiving_piece ON receiving_piece.po_line_id = paid_line.id\n    JOIN inventory.item__t linked_item ON linked_item.id = receiving_piece.item_id\n    JOIN inventory.location__t allocation_location ON allocation_location.id = linked_item.effective_location_id\n    JOIN inventory.loclibrary__t allocation_library ON allocation_library.id = allocation_location.library_id\n    JOIN inventory.loccampus__t allocation_campus ON allocation_campus.id = allocation_library.campus_id\n    WHERE paid_line.cost__quantity_physical > 0\n      AND TRIM(acquisition_unit.name) = 'SC'\n      AND allocation_campus.name = 'Smith College'\n    GROUP BY invoice_line.id, paid_line.instance_id, invoice_line.quantity\n), spend_by_instance AS (",
    $physicalSql
);
$physicalSql = str_replace(
    "JOIN orders.po_line__t paid_line ON paid_line.id = fd.po_line_id\n    JOIN orders.purchase_order__t purchase_order ON purchase_order.id = paid_line.purchase_order_id\n    JOIN orders.purchase_order__t__acq_unit_ids purchase_order_unit ON purchase_order_unit.id = purchase_order.id\n    JOIN orders.acquisitions_unit__t acquisition_unit ON acquisition_unit.id = purchase_order_unit.acq_unit_ids",
    'JOIN exact_paid_lines paid_line ON paid_line.invoice_line_id = invoice_line.id',
    $physicalSql
);
$physicalSql = str_replace(
    "WHERE paid_line.cost__quantity_physical > 0\n      AND TRIM(acquisition_unit.name) = 'SC'\n      AND invoice.payment_date",
    'WHERE invoice.payment_date',
    $physicalSql
);
$physicalSql = str_replace('COUNT(audit_loan.created_date) AS checkouts', 'COUNT(DISTINCT audit_loan.loan__id) AS checkouts', $physicalSql);
$poLineQuantityResult = ExploratorySqlSemanticValidatorService::validate($poLineQuantitySql, $physicalContract);
semanticAssertSame('rejected', $poLineQuantityResult['status'], 'Direct PO-line physical quantity must not satisfy invoiced-copy lineage.');
$rawFundDistributionSql = $physicalSql;
$physicalSql = str_replace(
    [
        "    SELECT invoice_line.id AS invoice_line_id,\n           paid_line.instance_id AS instance_id,\n           invoice_line.quantity AS quantity",
        '    FROM invoice.invoice_lines__t invoice_line',
        'paid_line.id = invoice_line.po_line_id',
        'GROUP BY invoice_line.id, paid_line.instance_id, invoice_line.quantity',
        'SUM(fd.total * (fd.fund_distributions__value * 0.01)) AS spend',
        "    FROM invoice.invoice_lines__t__fund_distributions fd\n    JOIN invoice.invoice_lines__t invoice_line ON invoice_line.id = fd.id\n    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id\n    JOIN exact_paid_lines paid_line ON paid_line.invoice_line_id = invoice_line.id\n    WHERE invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'",
    ],
    [
        "    SELECT funded_line.invoice_line_id AS invoice_line_id,\n           paid_line.instance_id AS instance_id,\n           funded_line.quantity AS quantity,\n           funded_line.spend AS spend",
        '    FROM funded_invoice_lines funded_line',
        'paid_line.id = funded_line.po_line_id',
        'GROUP BY funded_line.invoice_line_id, paid_line.instance_id, funded_line.quantity, funded_line.spend',
        'SUM(paid_line.spend) AS spend',
        '    FROM exact_paid_lines paid_line',
    ],
    $physicalSql
);
$physicalSql = str_replace(
    'WITH exact_paid_lines AS (',
    "WITH funded_invoice_lines AS (\n    SELECT invoice_line.id AS invoice_line_id,\n           invoice_line.po_line_id AS po_line_id,\n           invoice_line.quantity AS quantity,\n           invoice.currency AS currency,\n           SUM(fd.total * (fd.fund_distributions__value * 0.01)) AS spend\n    FROM invoice.invoice_lines__t invoice_line\n    JOIN invoice.invoice_lines__t__fund_distributions fd ON fd.id = invoice_line.id\n    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id\n    WHERE invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'\n    GROUP BY invoice_line.id, invoice_line.po_line_id, invoice_line.quantity, invoice.currency\n), exact_paid_lines AS (",
    $physicalSql
);
$physicalSql = str_replace(
    'WITH funded_invoice_lines AS (',
    "WITH eligible_current_smith_items AS (\n    SELECT eligible_item.id AS id,\n           eligible_item.purchase_order_line_identifier AS purchase_order_line_identifier\n    FROM inventory.item__t eligible_item\n    JOIN inventory.location__t eligible_location ON eligible_location.id = eligible_item.effective_location_id\n    JOIN inventory.loclibrary__t eligible_library ON eligible_library.id = eligible_location.library_id\n    JOIN inventory.loccampus__t eligible_campus ON eligible_campus.id = eligible_library.campus_id\n    WHERE eligible_campus.name = 'Smith College'\n    GROUP BY eligible_item.id, eligible_item.purchase_order_line_identifier\n), current_smith_instances AS (\n    SELECT fallback_holdings.instance_id AS instance_id\n    FROM inventory.item__t fallback_item\n    JOIN inventory.holdings_record__t fallback_holdings ON fallback_holdings.id = fallback_item.holdings_record_id\n    JOIN inventory.location__t fallback_location ON fallback_location.id = fallback_item.effective_location_id\n    JOIN inventory.loclibrary__t fallback_library ON fallback_library.id = fallback_location.library_id\n    JOIN inventory.loccampus__t fallback_campus ON fallback_campus.id = fallback_library.campus_id\n    WHERE fallback_campus.name = 'Smith College'\n    GROUP BY fallback_holdings.instance_id\n), funded_invoice_lines AS (",
    $physicalSql
);
$physicalSql = str_replace(
    "    JOIN orders.pieces__t receiving_piece ON receiving_piece.po_line_id = paid_line.id\n    JOIN inventory.item__t linked_item ON linked_item.id = receiving_piece.item_id\n    JOIN inventory.location__t allocation_location ON allocation_location.id = linked_item.effective_location_id\n    JOIN inventory.loclibrary__t allocation_library ON allocation_library.id = allocation_location.library_id\n    JOIN inventory.loccampus__t allocation_campus ON allocation_campus.id = allocation_library.campus_id",
    "    JOIN current_smith_instances fallback_eligible ON fallback_eligible.instance_id = paid_line.instance_id\n    LEFT JOIN orders.pieces__t receiving_piece ON receiving_piece.po_line_id = paid_line.id\n    LEFT JOIN eligible_current_smith_items eligible_exact_item ON eligible_exact_item.id = receiving_piece.item_id",
    $physicalSql
);
$physicalSql = str_replace("      AND allocation_campus.name = 'Smith College'\n", '', $physicalSql);
$physicalSql = str_replace(
    "           funded_line.spend AS spend\n    FROM funded_invoice_lines funded_line",
    "           funded_line.spend AS spend,\n           LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)) AS exact_linked_copies,\n           GREATEST(funded_line.quantity - LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)), 0) AS fallback_linked_copies,\n           LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)) + GREATEST(funded_line.quantity - LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)), 0) AS allocated_physical_copies\n    FROM funded_invoice_lines funded_line",
    $physicalSql
);
$physicalSql = str_replace(
    [
        "SUM(paid_line.quantity) AS purchase_count,\n           SUM(paid_line.quantity) AS physical_copies_purchased,",
        'SUM(paid_line.spend) AS spend',
        "SUM(spend_by_instance.distinct_titles) AS distinct_titles,\n       SUM(spend_by_instance.spend) AS spend,",
        "SUM(spend_by_instance.spend) / NULLIF(SUM(circulation_by_instance.circulation), 0) AS cost_per_checkout\nFROM",
    ],
    [
        "SUM(paid_line.allocated_physical_copies) AS purchase_count,\n           SUM(paid_line.allocated_physical_copies) AS physical_copies_purchased,",
        "SUM(paid_line.spend) AS spend,\n           SUM(paid_line.exact_linked_copies) AS exact_linked_copies,\n           SUM(paid_line.fallback_linked_copies) AS fallback_linked_copies",
        "SUM(spend_by_instance.distinct_titles) AS distinct_titles,\n       SUM(spend_by_instance.exact_linked_copies) AS exact_linked_copies,\n       SUM(spend_by_instance.fallback_linked_copies) AS fallback_linked_copies,\n       SUM(spend_by_instance.spend) AS spend,",
        "SUM(spend_by_instance.spend) / NULLIF(SUM(circulation_by_instance.circulation), 0) AS cost_per_checkout,\n       SUM(spend_by_instance.fallback_linked_copies) / NULLIF(SUM(spend_by_instance.physical_copies_purchased), 0) AS fallback_percentage\nFROM",
    ],
    $physicalSql
);
$physicalSql = str_replace(
    [
        "funded_line.quantity AS quantity,\n           funded_line.spend AS spend,",
        'GROUP BY funded_line.invoice_line_id, paid_line.instance_id, funded_line.quantity, funded_line.spend',
        "SELECT paid_line.instance_id,\n           SUM(paid_line.allocated_physical_copies)",
        'GROUP BY paid_line.instance_id',
        "SELECT pol.instance_id,\n           SUM(paid_line.allocated_physical_copies)",
        'GROUP BY pol.instance_id',
        "SELECT class_by_instance.call_number_class,\n       SUM(spend_by_instance.purchase_count)",
        'GROUP BY class_by_instance.call_number_class',
    ],
    [
        "funded_line.quantity AS quantity,\n           funded_line.currency AS currency,\n           funded_line.spend AS spend,",
        'GROUP BY funded_line.invoice_line_id, paid_line.instance_id, funded_line.quantity, funded_line.currency, funded_line.spend',
        "SELECT paid_line.instance_id,\n           paid_line.currency AS currency,\n           SUM(paid_line.allocated_physical_copies)",
        'GROUP BY paid_line.instance_id, paid_line.currency',
        "SELECT paid_line.instance_id AS instance_id,\n           paid_line.currency AS currency,\n           SUM(paid_line.allocated_physical_copies)",
        'GROUP BY paid_line.instance_id, paid_line.currency',
        "SELECT class_by_instance.call_number_class,\n       spend_by_instance.currency AS currency,\n       SUM(spend_by_instance.purchase_count)",
        'GROUP BY class_by_instance.call_number_class, spend_by_instance.currency',
    ],
    $physicalSql
);
semanticAssertRejectedFor($rawFundDistributionSql, $physicalContract, 'spend_grain', 'Raw fund-distribution joins must not multiply invoice-line physical quantity.');
$physicalResult = ExploratorySqlSemanticValidatorService::validate($physicalSql, $physicalContract);
semanticAssertSame('validated', $physicalResult['status'], 'An enforcing invoice join with invoice-header currency must validate.');
$wrongInvoiceLineCurrencySql = str_replace(
    ['invoice.currency AS currency', 'invoice_line.quantity, invoice.currency'],
    ['invoice_line.currency AS currency', 'invoice_line.quantity, invoice_line.currency'],
    $physicalSql
);
semanticAssertRejectedFor($wrongInvoiceLineCurrencySql, $physicalContract, 'spend_grain', 'Funded-line currency must not bind to the nonexistent invoice-line currency column.');
$nullableInvoiceJoinSql = str_replace(
    "    JOIN invoice.invoice_lines__t__fund_distributions fd ON fd.id = invoice_line.id\n    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id",
    "    JOIN invoice.invoice_lines__t__fund_distributions fd ON fd.id = invoice_line.id\n    LEFT JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id",
    $physicalSql
);
semanticAssertRejectedFor($nullableInvoiceJoinSql, $physicalContract, 'spend_grain', 'Invoice-header currency requires an enforcing invoice-line-to-invoice join.');
$intermediateFundedSql = preg_replace(
    '/funded_invoice_lines AS \(/',
    'funded_invoice_line_source AS (',
    $physicalSql,
    1
);
$intermediateFundedSql = str_replace(
    '), exact_paid_lines AS (',
    "), funded_invoice_lines AS (\n    SELECT funded_source.invoice_line_id,\n           funded_source.po_line_id,\n           funded_source.quantity,\n           funded_source.currency,\n           funded_source.spend\n    FROM funded_invoice_line_source funded_source\n), exact_paid_lines AS (",
    (string)$intermediateFundedSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($intermediateFundedSql, $physicalContract)['status'], 'A consumed intermediate funded-line CTE must retain recursive purchase and currency lineage.');
preg_match('/funded_invoice_lines AS \((.*?)\), exact_paid_lines AS \(/s', $physicalSql, $fundedMatch);
$wrongConsumedWindowSql = str_replace('invoice.payment_date >=', 'invoice.invoice_date >=', $physicalSql);
$deadCorrectFundedSql = preg_replace(
    '/^WITH /',
    "WITH dead_funded_invoice_lines AS (" . ($fundedMatch[1] ?? '') . "), ",
    $wrongConsumedWindowSql,
    1
);
semanticAssertRejectedFor((string)$deadCorrectFundedSql, $physicalContract, 'purchase_date_basis', 'A dead correct-looking funded CTE must not repair the consumed purchase-date lineage.');
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($physicalSql, $physicalContract)['status'], 'Row-preserving exact links must allow fully unmatched invoice lines to use instance fallback.');
semanticAssertRejectedFor(str_replace('LEFT JOIN orders.pieces__t receiving_piece', 'INNER JOIN orders.pieces__t receiving_piece', $physicalSql), $physicalContract, 'spend_grain', 'Receiving-piece exact linkage must remain row-preserving.');
semanticAssertRejectedFor(str_replace('LEFT JOIN eligible_current_smith_items eligible_exact_item', 'INNER JOIN eligible_current_smith_items eligible_exact_item', $physicalSql), $physicalContract, 'spend_grain', 'Eligible-item exact linkage must remain row-preserving.');
$rawLinkedExactCountSql = str_replace(
    'LEFT JOIN eligible_current_smith_items eligible_exact_item ON eligible_exact_item.id = receiving_piece.item_id',
    "LEFT JOIN inventory.item__t linked_item ON linked_item.id = receiving_piece.item_id\n    LEFT JOIN eligible_current_smith_items eligible_exact_item ON eligible_exact_item.id = receiving_piece.item_id",
    $physicalSql
);
$rawLinkedExactCountSql = str_replace('COUNT(DISTINCT eligible_exact_item.id)', 'COUNT(DISTINCT linked_item.id)', $rawLinkedExactCountSql);
semanticAssertRejectedFor($rawLinkedExactCountSql, $physicalContract, 'spend_grain', 'Exact copies must count only linked items with eligible current Smith-item lineage.');
$lostCurrencySql = str_replace("       spend_by_instance.currency AS currency,\n", '', $physicalSql);
$lostCurrencySql = str_replace(', spend_by_instance.currency', '', $lostCurrencySql);
semanticAssertRejectedFor($lostCurrencySql, $physicalContract, 'currency_separation', 'Final ROI output must retain invoice currency grouping.');
$mixedCurrencySql = str_replace(', paid_line.currency', '', $physicalSql);
semanticAssertRejectedFor($mixedCurrencySql, $physicalContract, 'currency_separation', 'Acquisition aggregation must not mix invoice currencies.');
$nullableFallbackEligibilitySql = str_replace('JOIN current_smith_instances fallback_eligible ON', 'LEFT JOIN current_smith_instances fallback_eligible ON', $physicalSql);
semanticAssertRejectedFor($nullableFallbackEligibilitySql, $physicalContract, 'spend_grain', 'Fallback requires an enforcing current Smith instance cohort.');
$unusedFallbackMarkerSql = str_replace(
    "           funded_line.quantity AS quantity,\n",
    "           funded_line.quantity AS quantity,\n           funded_line.quantity AS fallback_allocated_quantity,\n",
    $physicalSql
);
$unusedFallbackMarkerSql = str_replace(
    "    LEFT JOIN orders.pieces__t receiving_piece ON receiving_piece.po_line_id = paid_line.id\n    LEFT JOIN eligible_current_smith_items eligible_exact_item ON eligible_exact_item.id = receiving_piece.item_id\n",
    '',
    $unusedFallbackMarkerSql
);
semanticAssertRejectedFor($unusedFallbackMarkerSql, $physicalContract, 'spend_grain', 'An unused fallback marker must not substitute for structural allocation proof.');
$directItemAllocationSql = str_replace(
    "    LEFT JOIN orders.pieces__t receiving_piece ON receiving_piece.po_line_id = paid_line.id\n    LEFT JOIN eligible_current_smith_items eligible_exact_item ON eligible_exact_item.id = receiving_piece.item_id",
    '    LEFT JOIN eligible_current_smith_items eligible_exact_item ON eligible_exact_item.purchase_order_line_identifier = paid_line.id',
    $physicalSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($directItemAllocationSql, $physicalContract)['status'], 'Direct item PO-line linkage must be accepted as trusted exact allocation.');
$untrustedAllocationSql = str_replace(
    "    LEFT JOIN orders.pieces__t receiving_piece ON receiving_piece.po_line_id = paid_line.id\n    LEFT JOIN eligible_current_smith_items eligible_exact_item ON eligible_exact_item.id = receiving_piece.item_id\n",
    '',
    $physicalSql
);
semanticAssertRejectedFor($untrustedAllocationSql, $physicalContract, 'spend_grain', 'Invoice quantity without exact linkage or a fallback allocation shape must be rejected.');
semanticAssertRejectedFor(str_replace('SUM(paid_line.allocated_physical_copies) AS physical_copies_purchased', 'COUNT(DISTINCT paid_line.instance_id) AS physical_copies_purchased', $physicalSql), $physicalContract, 'spend_grain', 'Instance or PO-line counts must not satisfy physical-copy lineage.');
semanticAssertRejectedFor(str_replace('LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)) AS exact_linked_copies', 'funded_line.quantity AS exact_linked_copies', $physicalSql), $physicalContract, 'spend_grain', 'Exact-linked copies must be capped by distinct eligible linked items.');
semanticAssertRejectedFor(str_replace('GREATEST(funded_line.quantity - LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)), 0) AS fallback_linked_copies', 'funded_line.quantity AS fallback_linked_copies', $physicalSql), $physicalContract, 'spend_grain', 'Fallback copies must be the nonnegative invoiced-minus-exact remainder.');
semanticAssertRejectedFor(str_replace('LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)) + GREATEST(funded_line.quantity - LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)), 0) AS allocated_physical_copies', 'funded_line.quantity AS allocated_physical_copies', $physicalSql), $physicalContract, 'spend_grain', 'Physical copies must be the exact-plus-fallback partition.');
semanticAssertRejectedFor(str_replace('SUM(spend_by_instance.fallback_linked_copies) / NULLIF(SUM(spend_by_instance.physical_copies_purchased), 0) AS fallback_percentage', 'SUM(spend_by_instance.exact_linked_copies) / NULLIF(SUM(spend_by_instance.physical_copies_purchased), 0) AS fallback_percentage', $physicalSql), $physicalContract, 'spend_grain', 'Fallback percentage must disclose fallback copies over total physical copies.');
$swappedDiagnosticsSql = str_replace(
    ['SUM(paid_line.exact_linked_copies) AS exact_linked_copies', 'SUM(paid_line.fallback_linked_copies) AS fallback_linked_copies'],
    ['SUM(paid_line.fallback_linked_copies) AS exact_linked_copies', 'SUM(paid_line.exact_linked_copies) AS fallback_linked_copies'],
    $physicalSql
);
semanticAssertRejectedFor($swappedDiagnosticsSql, $physicalContract, 'spend_grain', 'Exact and fallback diagnostic bindings must not be swapped.');
semanticAssertRejectedFor(str_replace('SUM(paid_line.fallback_linked_copies) AS fallback_linked_copies', 'SUM(paid_line.allocated_physical_copies) AS fallback_linked_copies', $physicalSql), $physicalContract, 'spend_grain', 'Allocated totals must not masquerade as fallback diagnostics.');
$arbitraryDiagnosticSql = str_replace(
    'LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)) AS exact_linked_copies,',
    "LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)) AS exact_linked_copies,\n           LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)) AS arbitrary_exact_copies,",
    $physicalSql
);
$arbitraryDiagnosticSql = str_replace('SUM(paid_line.exact_linked_copies) AS exact_linked_copies', 'SUM(paid_line.arbitrary_exact_copies) AS exact_linked_copies', $arbitraryDiagnosticSql);
semanticAssertRejectedFor($arbitraryDiagnosticSql, $physicalContract, 'spend_grain', 'Arbitrary intermediate aliases must not satisfy exact-link diagnostics.');
$missingDiagnosticsSql = preg_replace('/^\s*SUM\(spend_by_instance\.(?:exact_linked_copies|fallback_linked_copies)\) AS (?:exact_linked_copies|fallback_linked_copies),\n/m', '', $physicalSql);
$missingDiagnosticsSql = preg_replace('/,\n\s*SUM\(spend_by_instance\.fallback_linked_copies\) \/ NULLIF\(SUM\(spend_by_instance\.physical_copies_purchased\), 0\) AS fallback_percentage/', '', (string)$missingDiagnosticsSql);
semanticAssertRejectedFor((string)$missingDiagnosticsSql, $physicalContract, 'required_measures', 'V2 reports must return exact, fallback, and fallback-percentage diagnostics.');
$decoyPolicySql = str_replace(
    'WITH exact_paid_lines AS (',
    "WITH policy_decoy AS (\n    SELECT decoy_invoice_line.id AS invoice_line_id\n    FROM invoice.invoice_lines__t decoy_invoice_line\n    JOIN orders.po_line__t decoy_line ON decoy_line.id = decoy_invoice_line.po_line_id\n    JOIN orders.purchase_order__t decoy_order ON decoy_order.id = decoy_line.purchase_order_id\n    JOIN orders.purchase_order__t__acq_unit_ids decoy_order_unit ON decoy_order_unit.id = decoy_order.id\n    JOIN orders.acquisitions_unit__t decoy_unit ON decoy_unit.id = decoy_order_unit.acq_unit_ids\n    WHERE decoy_line.cost__quantity_physical > 0\n      AND TRIM(decoy_unit.name) = 'SC'\n    GROUP BY decoy_invoice_line.id\n), exact_paid_lines AS (",
    $physicalSql
);
$decoyPolicySql = str_replace(
    "    WHERE paid_line.cost__quantity_physical > 0\n      AND TRIM(acquisition_unit.name) = 'SC'\n",
    '',
    $decoyPolicySql
);
$decoyPolicySql = str_replace(
    'JOIN exact_paid_lines paid_line ON paid_line.invoice_line_id = invoice_line.id',
    "JOIN exact_paid_lines paid_line ON paid_line.invoice_line_id = invoice_line.id\n    LEFT JOIN policy_decoy policy_decoy ON policy_decoy.invoice_line_id = invoice_line.id",
    $decoyPolicySql
);
semanticAssertRejectedFor($decoyPolicySql, $physicalContract, 'physical_item_eligibility', 'A nullable side-policy dependency must not lend physical eligibility to the purchase measure.');
semanticAssertRejectedFor($decoyPolicySql, $physicalContract, 'acquisition_unit_scope', 'A nullable side-policy dependency must not lend SC scope to the purchase measure.');
foreach (['audit_loan.loan__id', 'audit_loan.loan__action', 'audit_loan.loan__item_id', 'audit_loan.loan__loan_date'] as $nonDistinctCheckoutExpression) {
    $nonDistinctCheckoutSql = str_replace('DISTINCT audit_loan.loan__id', $nonDistinctCheckoutExpression, $physicalSql);
    semanticAssertRejectedFor($nonDistinctCheckoutSql, $physicalContract, 'circulation_grain', 'V2 circulation must count distinct audit loan ids.');
}

$dvdQuestion = 'For DVDs, show call numbers purchased most in five years with circulation and ROI.';
$dvdContract = ExploratorySemanticContractService::build(
    $dvdQuestion,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($dvdQuestion),
    'unsupported_query_family'
);
$dvdSql = str_replace(
    [
        'JOIN inventory.location__t scope_location ON',
        "WHERE selected_scope.name = 'Smith College'\n    GROUP BY scope_holdings.instance_id",
    ],
    [
        "JOIN inventory.material_type__t material_type ON material_type.id = scope_item.material_type_id\n    JOIN inventory.location__t scope_location ON",
        "WHERE selected_scope.name = 'Smith College'\n      AND LOWER(material_type.name) = 'dvd'\n    GROUP BY scope_holdings.instance_id",
    ],
    $physicalSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($dvdSql, $dvdContract)['status'], 'Explicit DVD physical ROI must validate.');
semanticAssertRejectedFor($physicalSql, $dvdContract, 'governed_filters', 'An explicit DVD contract must enforce its material cohort.');
$circulationOnlyDvdSql = str_replace(
    'JOIN inventory.location__t circ_location ON',
    "JOIN inventory.material_type__t circulation_material ON circulation_material.id = item.material_type_id\n    JOIN inventory.location__t circ_location ON",
    $physicalSql
);
$circulationOnlyDvdSql = str_replace(
    "WHERE selected_scope.name = 'Smith College'\n    GROUP BY item.id",
    "WHERE selected_scope.name = 'Smith College'\n      AND LOWER(circulation_material.name) = 'dvd'\n    GROUP BY item.id",
    $circulationOnlyDvdSql
);
semanticAssertRejectedFor($circulationOnlyDvdSql, $dvdContract, 'governed_filters', 'A circulation-only DVD predicate must not satisfy the purchase material cohort.');
semanticAssertRejectedFor(str_replace("LOWER(material_type.name) = 'dvd'", "LOWER(material_type.name) = 'book'", $dvdSql), $dvdContract, 'governed_filters', 'An unrequested book filter must fail governance.');
$arbitraryMaterialAliasSql = str_replace(
    ['inventory.material_type__t material_type', 'material_type.id', 'material_type.name'],
    ['inventory.material_type__t format_dimension', 'format_dimension.id', 'format_dimension.name'],
    $dvdSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($arbitraryMaterialAliasSql, $dvdContract)['status'], 'Material validation must resolve arbitrary aliases to the material-type table.');
semanticAssertRejectedCategory(str_replace("    WHERE paid_line.cost__quantity_physical > 0\n      AND", '    WHERE', $physicalSql), $physicalContract, 'physical_item_eligibility', 'physical_cohort_mismatch', 'Positive physical quantity is mandatory.');
semanticAssertRejectedCategory(str_replace("      AND TRIM(acquisition_unit.name) = 'SC'\n", '', $physicalSql), $physicalContract, 'acquisition_unit_scope', 'scope_mismatch', 'Smith acquisition-unit scope is mandatory.');

semanticAssertRejectedFor(str_replace("WHERE invoice.payment_date", "WHERE pol.acquisition_unit_id = 'unit' AND invoice.payment_date", $correctedSql), $contract, 'governed_filters', 'Unrequested acquisition unit must be rejected.');
semanticAssertRejectedFor(str_replace("WHERE invoice.payment_date", "WHERE pol.material_type_id = 'book' AND invoice.payment_date", $correctedSql), $contract, 'governed_filters', 'Unrequested material type must be rejected.');
$governedOnlyContract = $contract;
$governedOnlyContract['requirements'] = array_values(array_filter(
    $contract['requirements'],
    static function (array $requirement): bool {
        return ($requirement['rule'] ?? null) === 'governed_filters';
    }
));
$leftGovernedSql = "SELECT pol.id FROM orders.po_line__t pol LEFT JOIN inventory.item__t filtered_item ON filtered_item.id = pol.id AND filtered_item.material_type_id = 'book'";
semanticAssertRejectedFor($leftGovernedSql, $governedOnlyContract, 'governed_filters', 'A governed predicate on a reachable nullable input can still influence outputs and must be rejected.');
$innerGovernedSql = str_replace('LEFT JOIN', 'INNER JOIN', $leftGovernedSql);
semanticAssertRejectedFor($innerGovernedSql, $governedOnlyContract, 'governed_filters', 'An unrequested governed predicate in an INNER JOIN must be rejected.');
$checkoutGovernedSql = str_replace(
    'ON audit_loan.loan__item_id = item.id',
    "ON audit_loan.loan__item_id = item.id\n     AND item.material_type_id = 'book'",
    $correctedSql
);
semanticAssertRejectedFor($checkoutGovernedSql, $contract, 'governed_filters', 'A material-type predicate in the checkout LEFT JOIN can change aggregate inputs and must be governed.');
$nullableAcquisitionSql = str_replace(
    'JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id',
    "LEFT JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id\n      AND pol.acquisition_unit_id = 'unit'",
    $correctedSql
);
semanticAssertRejectedFor($nullableAcquisitionSql, $contract, 'governed_filters', 'A nullable-path acquisition-unit predicate in a contributing scope must be governed.');
$unusedGovernedSql = str_replace(
    'WITH spend_by_instance AS (',
    "WITH unused_governed AS (SELECT item.id FROM inventory.item__t item WHERE item.material_type_id = 'book'),\nspend_by_instance AS (",
    $correctedSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($unusedGovernedSql, $contract)['status'], 'A governed predicate in an unused CTE must remain irrelevant.');
$permittedContract = $contract;
$permittedContract['permittedFilters']['material_type'] = ['provenance' => 'explicit_prompt'];
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate(str_replace("WHERE invoice.payment_date", "WHERE pol.material_type_id = 'book' AND invoice.payment_date", $correctedSql), $permittedContract)['status'], 'Explicit provenance must permit material type.');
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($checkoutGovernedSql, $permittedContract)['status'], 'Explicit permission must allow a reachable nullable-input material filter.');
$unprovenContract = $contract;
$unprovenContract['permittedFilters']['material_type'] = [];
semanticAssertRejectedFor(str_replace("WHERE invoice.payment_date", "WHERE pol.material_type_id = 'book' AND invoice.payment_date", $correctedSql), $unprovenContract, 'governed_filters', 'A filter permission without provenance must fail closed.');

$ambiguous = ExploratorySqlSemanticValidatorService::validate('SELECT 1 UNION SELECT 2', $contract);
semanticAssertSame('rejected', $ambiguous['status'], 'Ambiguous SQL must fail closed.');
semanticAssertSame(['semantic_coverage_gap'], array_values(array_unique(array_column($ambiguous['violations'], 'category'))), 'Ambiguity must produce only coverage-gap violations.');

$unsupported = ['key' => 'future_requirement', 'rule' => 'future_rule', 'label' => 'Future requirement.', 'parameters' => []];
$gapContract = $contract;
$gapContract['requirements'] = [$unsupported];
$gapContract = array_merge($gapContract, ExploratorySemanticContractService::auditCoverage([$unsupported], ExploratorySqlSemanticValidatorService::supportedRuleKeys()));
$gap = ExploratorySqlSemanticValidatorService::validate($correctedSql, $gapContract);
semanticAssertSame(['semantic_coverage_gap'], array_column($gap['violations'], 'category'), 'Unsupported audited rules must fail closed before rule dispatch.');

foreach ($captured['violations'] as $violation) {
    semanticAssertSame(['key', 'category', 'label', 'guidance'], array_keys($violation), 'Violation payloads must contain only stable safe fields.');
}

$notApplicable = $contract;
$notApplicable['applicable'] = false;
semanticAssertSame('not_applicable', ExploratorySqlSemanticValidatorService::validate('not sql', $notApplicable)['status'], 'Non-applicable contracts must bypass analysis.');

fwrite(STDOUT, "Exploratory SQL semantic validator service test passed\n");
