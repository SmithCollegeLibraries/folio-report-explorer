<?php

require_once __DIR__ . '/../services/SqlSelectStructureService.php';
require_once __DIR__ . '/../services/ExploratorySqlAnalysisService.php';

use app\services\ExploratorySqlAnalysisService;

function analysisAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

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

$analysis = ExploratorySqlAnalysisService::analyze($correctedSql);
analysisAssertSame(
    ['spend_by_instance', 'circulation_by_item', 'circulation_by_instance', 'class_by_instance'],
    array_keys($analysis['ctes']),
    'CTEs must retain dependency order.'
);
analysisAssertSame(
    ['invoice.invoice_lines__t', 'invoice.invoice_lines__t__fund_distributions', 'invoice.invoices__t', 'orders.po_line__t'],
    $analysis['ctes']['spend_by_instance']['tables'],
    'Spend CTE table references must be inspectable.'
);
analysisAssertSame(['circulation_by_item'], $analysis['ctes']['circulation_by_instance']['dependencies'], 'CTE dependencies must be separated from physical tables.');
analysisAssertSame('purchase_count', $analysis['orderBy'][0]['expression'], 'Final ranking alias must be captured.');
analysisAssertSame('DESC', $analysis['orderBy'][0]['direction'], 'Final ranking direction must be captured.');
analysisAssertSame(6, count($analysis['selectItems']), 'Final selected expressions must be inspectable.');
analysisAssertSame(false, $analysis['ambiguous'], 'Supported CTE SQL must analyze deterministically.');

$flawed = ExploratorySqlAnalysisService::analyze($capturedProductionSql);
analysisAssertSame(true, in_array('total_spent', $flawed['formattedAliases'], true), 'TO_CHAR spending must be marked text-formatted.');
analysisAssertSame(true, in_array('cost_per_checkout', $flawed['formattedAliases'], true), 'TO_CHAR cost per checkout must be marked text-formatted.');
analysisAssertSame('pot.date_ordered', $flawed['predicates']['dateColumns'][0], 'Order-date filtering must not be mistaken for payment date.');
analysisAssertSame(true, in_array('item.material_type_id', $flawed['predicates']['governedFilters'], true), 'Governed lookup filters must be inspectable.');

foreach ([
    'SELECT 1 UNION SELECT 2',
    'WITH RECURSIVE tree AS (SELECT 1) SELECT * FROM tree',
    'SELECT * FROM LATERAL unnest(items.values) value',
    'SELECT one AS duplicate, two AS duplicate FROM inventory.item__t',
    'SELECT * FROM inventory.item__t item JOIN inventory.holdings_record__t holdings USING (holdings_record_id)',
    'SELECT * FROM inventory.item__t WHERE status = 1 WHERE active = 1',
] as $ambiguousSql) {
    analysisAssertSame(true, ExploratorySqlAnalysisService::analyze($ambiguousSql)['ambiguous'], 'Unsupported structures must fail closed as ambiguous.');
}

analysisAssertSame(
    [],
    ExploratorySqlAnalysisService::analyze("SELECT 'FROM hidden.table WHERE pot.fake_date > 1' AS note -- JOIN secret.table\n")['tables'],
    'Strings and comments must not create table evidence.'
);

$governedByAlias = ExploratorySqlAnalysisService::analyze(
    "SELECT au.name FROM orders.acquisitions_unit__t au WHERE au.name = 'SC'"
);
analysisAssertSame(
    ['au.name'],
    $governedByAlias['predicates']['governedFilters'],
    'Literal lookup filters must remain inspectable even when the governed dimension is known through its table alias.'
);
$governedThroughTrim = ExploratorySqlAnalysisService::analyze(
    "SELECT au.name FROM orders.acquisitions_unit__t au WHERE TRIM(au.name) = 'SC'"
);
analysisAssertSame(
    ['au.name'],
    $governedThroughTrim['predicates']['governedFilters'],
    'A lookup filter wrapped in a normalization function must remain inspectable.'
);

fwrite(STDOUT, "ExploratorySqlAnalysisService test passed\n");
