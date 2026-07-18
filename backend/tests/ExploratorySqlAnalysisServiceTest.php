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

foreach ([
    'SELECT (SELECT MAX(item.id) FROM inventory.item__t item) AS maximum_id FROM inventory.instance__t instance',
    'SELECT instance.id FROM inventory.instance__t instance WHERE EXISTS (SELECT 1 FROM inventory.item__t item)',
    'SELECT instance.id FROM inventory.instance__t instance WHERE instance.id IN (SELECT item.id FROM inventory.item__t item)',
] as $nestedSelectSql) {
    analysisAssertSame(
        true,
        ExploratorySqlAnalysisService::analyze($nestedSelectSql)['ambiguous'],
        'Nested scalar and predicate SELECTs must fail closed.'
    );
}

$forwardCte = ExploratorySqlAnalysisService::analyze(
    'WITH first_cte AS (SELECT * FROM second_cte), second_cte AS (SELECT 1) SELECT * FROM first_cte'
);
analysisAssertSame([], $forwardCte['ctes']['first_cte']['tables'], 'A forward CTE dependency must never be reported as a physical table.');
analysisAssertSame(['second_cte'], $forwardCte['ctes']['first_cte']['dependencies'], 'Forward CTE references must remain dependencies.');
analysisAssertSame(true, $forwardCte['ambiguous'], 'Forward CTE references must fail closed.');

$selfCte = ExploratorySqlAnalysisService::analyze(
    'WITH self_cte AS (SELECT * FROM self_cte) SELECT * FROM self_cte'
);
analysisAssertSame([], $selfCte['ctes']['self_cte']['tables'], 'A self CTE dependency must never be reported as a physical table.');
analysisAssertSame(['self_cte'], $selfCte['ctes']['self_cte']['dependencies'], 'Self CTE references must remain dependencies.');
analysisAssertSame(true, $selfCte['ambiguous'], 'Self CTE references must fail closed.');

$literalIn = ExploratorySqlAnalysisService::analyze(
    "SELECT item.id FROM inventory.item__t item WHERE item.material_type_id IN ('book', 'dvd')"
);
analysisAssertSame(['item.material_type_id'], $literalIn['predicates']['governedFilters'], 'A non-empty literal-only IN list must be governed-filter evidence.');
analysisAssertSame(false, $literalIn['ambiguous'], 'A literal-only IN list must remain deterministic.');
foreach ([
    'SELECT item.id FROM inventory.item__t item WHERE item.material_type_id IN ()',
    "SELECT item.id FROM inventory.item__t item WHERE item.material_type_id IN (LOWER('book'))",
    'SELECT item.id FROM inventory.item__t item WHERE item.material_type_id IN (SELECT mt.id FROM inventory.material_type__t mt)',
] as $unsupportedInSql) {
    $unsupportedIn = ExploratorySqlAnalysisService::analyze($unsupportedInSql);
    analysisAssertSame([], $unsupportedIn['predicates']['governedFilters'], 'Unsupported IN contents must not become governed-filter evidence.');
    analysisAssertSame(true, $unsupportedIn['ambiguous'], 'Unsupported governed IN contents must fail closed.');
}
$mixedIn = ExploratorySqlAnalysisService::analyze(
    "SELECT item.id FROM inventory.item__t item WHERE item.status IN ('active') AND item.material_type_id IN (LOWER('book'))"
);
analysisAssertSame(['item.status'], $mixedIn['predicates']['governedFilters'], 'Valid IN evidence must not cause invalid IN evidence to be accepted.');
analysisAssertSame(true, $mixedIn['ambiguous'], 'A later unsupported IN must not be masked by an earlier valid IN.');

$aliasCases = [
    ['SELECT amount + tax FROM invoice.invoice_lines__t', 'amount + tax', null, false],
    ['SELECT CASE WHEN paid THEN amount ELSE total END FROM invoice.invoice_lines__t', 'case when paid then amount else total end', null, false],
    ['SELECT amount::numeric FROM invoice.invoice_lines__t', 'amount::numeric', null, false],
    ['SELECT amount FROM invoice.invoice_lines__t', 'amount', null, false],
    ['SELECT amount + tax AS total FROM invoice.invoice_lines__t', 'amount + tax', 'total', false],
    ['SELECT COUNT(*) purchase_count FROM invoice.invoice_lines__t', 'count (*)', 'purchase_count', false],
];
foreach ($aliasCases as [$aliasSql, $expectedExpression, $expectedAlias, $expectedAmbiguous]) {
    $aliasAnalysis = ExploratorySqlAnalysisService::analyze($aliasSql);
    analysisAssertSame($expectedExpression, $aliasAnalysis['selectItems'][0]['expression'], 'Alias analysis must preserve the complete expression.');
    analysisAssertSame($expectedAlias, $aliasAnalysis['selectItems'][0]['alias'], 'Alias analysis must only return a proven alias.');
    analysisAssertSame($expectedAmbiguous, $aliasAnalysis['ambiguous'], 'Supported alias boundaries must analyze deterministically.');
}
foreach ([
    ['SELECT amount total extra FROM invoice.invoice_lines__t', 'amount total extra'],
    ['SELECT amount AS total extra FROM invoice.invoice_lines__t', 'amount as total extra'],
    ['SELECT amount AS total + extra FROM invoice.invoice_lines__t', 'amount as total + extra'],
] as [$ambiguousAliasSql, $ambiguousExpression]) {
    $ambiguousAlias = ExploratorySqlAnalysisService::analyze($ambiguousAliasSql);
    analysisAssertSame($ambiguousExpression, $ambiguousAlias['selectItems'][0]['expression'], 'Ambiguous alias syntax must preserve the full expression evidence.');
    analysisAssertSame(null, $ambiguousAlias['selectItems'][0]['alias'], 'Ambiguous alias syntax must not invent an output alias.');
    analysisAssertSame(true, $ambiguousAlias['ambiguous'], 'Multiple plausible alias boundaries must fail closed.');
}

$quotedKeywords = ExploratorySqlAnalysisService::analyze(
    'SELECT "where", "order" AS "ordinary" FROM "inventory"."item__t"'
);
analysisAssertSame(false, $quotedKeywords['ambiguous'], 'Quoted keyword identifiers must not be treated as clauses.');
analysisAssertSame(['inventory.item__t'], $quotedKeywords['tables'], 'Quoted table identifiers must remain table evidence.');
analysisAssertSame('where', $quotedKeywords['selectItems'][0]['expression'], 'Quoted WHERE must remain an expression identifier.');
analysisAssertSame('order', $quotedKeywords['selectItems'][1]['expression'], 'Quoted ORDER must remain an expression identifier.');
analysisAssertSame('ordinary', $quotedKeywords['selectItems'][1]['alias'], 'Ordinary quoted aliases must remain inspectable.');

$quotedSourceAliases = ExploratorySqlAnalysisService::analyze(
    'WITH joined_scope AS (SELECT "where".id, "order".id '
    . 'FROM inventory.item__t "where" '
    . 'JOIN inventory.holdings_record__t "order" ON "order".id = "where".holdings_record_id) '
    . 'SELECT * FROM joined_scope'
);
analysisAssertSame(
    ['inventory.holdings_record__t', 'inventory.item__t'],
    $quotedSourceAliases['ctes']['joined_scope']['tables'],
    'Quoted keyword source aliases must not truncate physical table discovery.'
);
analysisAssertSame(1, count($quotedSourceAliases['ctes']['joined_scope']['joins']), 'Quoted keyword aliases must retain join evidence.');
analysisAssertSame('order', $quotedSourceAliases['ctes']['joined_scope']['joins'][0]['alias'], 'Quoted ORDER must remain a source alias.');
analysisAssertSame('INNER', $quotedSourceAliases['ctes']['joined_scope']['joins'][0]['type'], 'Quoted aliases must not change INNER join classification.');
analysisAssertSame(false, $quotedSourceAliases['ambiguous'], 'A supported join with quoted keyword aliases must remain deterministic.');

$quotedLeftAlias = ExploratorySqlAnalysisService::analyze(
    'WITH joined_scope AS (SELECT "left".id FROM inventory.item__t "left" '
    . 'JOIN inventory.holdings_record__t holdings ON holdings.id = "left".holdings_record_id) '
    . 'SELECT * FROM joined_scope'
);
analysisAssertSame('INNER', $quotedLeftAlias['ctes']['joined_scope']['joins'][0]['type'], 'Quoted LEFT aliases must not change INNER join classification.');
analysisAssertSame(false, $quotedLeftAlias['ambiguous'], 'Quoted LEFT aliases must remain ordinary identifiers.');

$ordinarySourceGrammar = ExploratorySqlAnalysisService::analyze(
    "WITH joined_scope AS (SELECT item.id FROM inventory.item__t item LEFT JOIN inventory.holdings_record__t holdings "
    . "ON holdings.id = item.holdings_record_id WHERE item.status = 'active') "
    . "SELECT * FROM joined_scope ORDER BY id DESC"
);
analysisAssertSame('LEFT', $ordinarySourceGrammar['ctes']['joined_scope']['joins'][0]['type'], 'Unquoted LEFT JOIN classification must remain unchanged.');
analysisAssertSame(['item.status'], $ordinarySourceGrammar['ctes']['joined_scope']['predicates']['governedFilters'], 'Unquoted WHERE must remain a clause boundary.');
analysisAssertSame('id', $ordinarySourceGrammar['orderBy'][0]['expression'], 'Unquoted ORDER BY must remain a clause boundary.');
analysisAssertSame('DESC', $ordinarySourceGrammar['orderBy'][0]['direction'], 'Unquoted ordering direction must remain unchanged.');

fwrite(STDOUT, "ExploratorySqlAnalysisService test passed\n");
