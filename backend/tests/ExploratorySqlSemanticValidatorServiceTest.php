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

$question = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$contract = ExploratorySemanticContractService::build(
    $question,
    null,
    ExploratoryQueryDefaultsService::resolve($question),
    'unsupported_query_family'
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
semanticAssertRejectedFor(str_replace('invoice.payment_date', 'pot.date_ordered', $correctedSql), $contract, 'purchase_date_basis', 'PO order date must not satisfy purchase-date basis.');
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
    "LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = spend_by_instance.instance_id\nGROUP BY",
    "LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = spend_by_instance.instance_id\nJOIN inventory.loccampus__t selected_scope ON selected_scope.id = class_by_instance.instance_id\nWHERE selected_scope.name = 'Smith College'\nGROUP BY",
    $correctedSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($campusSql, $campusContract)['status'], 'The selected campus filter must satisfy campus scope.');
$nestedCampusSql = str_replace(
    'WITH spend_by_instance AS (',
    "WITH selected_campus AS (SELECT loccampus.id FROM inventory.loccampus__t loccampus WHERE loccampus.name = 'Smith College'),\nspend_by_instance AS (",
    $correctedSql
);
$nestedCampusSql = str_replace(
    "LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = spend_by_instance.instance_id\nGROUP BY",
    "LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = spend_by_instance.instance_id\nJOIN selected_campus campus_scope ON campus_scope.id = class_by_instance.instance_id\nGROUP BY",
    $nestedCampusSql
);
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate($nestedCampusSql, $campusContract)['status'], 'Campus predicates in a reachable dependency scope must validate.');
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

semanticAssertRejectedFor(str_replace("WHERE invoice.payment_date", "WHERE pol.acquisition_unit_id = 'unit' AND invoice.payment_date", $correctedSql), $contract, 'governed_filters', 'Unrequested acquisition unit must be rejected.');
semanticAssertRejectedFor(str_replace("WHERE invoice.payment_date", "WHERE pol.material_type_id = 'book' AND invoice.payment_date", $correctedSql), $contract, 'governed_filters', 'Unrequested material type must be rejected.');
$permittedContract = $contract;
$permittedContract['permittedFilters']['material_type'] = ['provenance' => 'explicit_prompt'];
semanticAssertSame('validated', ExploratorySqlSemanticValidatorService::validate(str_replace("WHERE invoice.payment_date", "WHERE pol.material_type_id = 'book' AND invoice.payment_date", $correctedSql), $permittedContract)['status'], 'Explicit provenance must permit material type.');
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
