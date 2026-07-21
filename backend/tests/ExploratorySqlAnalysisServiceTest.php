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

function analysisItemsByAlias(array $items): array
{
    $byAlias = [];
    foreach ($items as $item) {
        if (($item['alias'] ?? null) !== null) {
            $byAlias[$item['alias']] = $item;
        }
    }
    return $byAlias;
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
analysisAssertSame(
    [
        'spend_by_instance' => ['kind' => 'cte', 'source' => 'spend_by_instance'],
        'class_by_instance' => ['kind' => 'cte', 'source' => 'class_by_instance'],
        'circulation_by_instance' => ['kind' => 'cte', 'source' => 'circulation_by_instance'],
    ],
    $analysis['sourceAliases'],
    'Final-scope aliases must bind to their actual CTE sources.'
);
analysisAssertSame(
    [
        'fd' => ['kind' => 'table', 'source' => 'invoice.invoice_lines__t__fund_distributions'],
        'invoice_line' => ['kind' => 'table', 'source' => 'invoice.invoice_lines__t'],
        'invoice' => ['kind' => 'table', 'source' => 'invoice.invoices__t'],
        'pol' => ['kind' => 'table', 'source' => 'orders.po_line__t'],
    ],
    $analysis['ctes']['spend_by_instance']['sourceAliases'],
    'Analysis must bind every spend-scope alias to its physical source.'
);
analysisAssertSame(
    [[
        'left' => 'audit_loan.loan__item_id',
        'operator' => '=',
        'right' => 'item.id',
        'origin' => 'join_on',
        'joinType' => 'LEFT',
        'joinedAlias' => 'audit_loan',
        'joinedSource' => 'circulation.audit_loan__t',
        'joinedSourceKind' => 'table',
        'joinPath' => [[
            'type' => 'LEFT',
            'alias' => 'audit_loan',
            'source' => 'circulation.audit_loan__t',
            'sourceKind' => 'table',
        ]],
    ]],
    $analysis['ctes']['circulation_by_item']['predicates']['columnComparisons'],
    'Exact column-comparison atoms must preserve checkout-to-item join evidence.'
);
$spendItems = analysisItemsByAlias($analysis['ctes']['spend_by_instance']['selectItems']);
analysisAssertSame(
    ['function' => 'count', 'column' => 'pol.id', 'distinct' => true],
    $spendItems['purchase_count']['exactAggregate'],
    'Purchase-count analysis must preserve exact DISTINCT aggregate evidence.'
);
$circulationItems = analysisItemsByAlias($analysis['ctes']['circulation_by_item']['selectItems']);
analysisAssertSame(
    ['function' => 'count', 'column' => 'audit_loan.created_date'],
    $circulationItems['checkouts']['exactAggregate'],
    'Checkout analysis must preserve its exact aggregate function and source column.'
);
analysisAssertSame(
    [
        'operator' => '*',
        'factors' => [
            ['columns' => ['fd.total'], 'exactColumn' => 'fd.total', 'numericLiteral' => null],
            ['columns' => ['fd.fund_distributions__value'], 'exactColumn' => 'fd.fund_distributions__value', 'numericLiteral' => null],
            ['columns' => [], 'exactColumn' => null, 'numericLiteral' => '0.01'],
        ],
    ],
    $spendItems['spend']['aggregateMultiplication'],
    'Spend analysis must preserve exact multiplication factors and scaling.'
);
analysisAssertSame(
    [[
        'column' => 'invoice.payment_date',
        'operator' => '>=',
        'expression' => 'current_date - interval 5 years',
        'origin' => 'where',
        'joinType' => null,
        'joinedAlias' => null,
        'joinedSource' => null,
        'joinedSourceKind' => null,
        'joinPath' => [],
    ]],
    $analysis['ctes']['spend_by_instance']['predicates']['dateWindows'],
    'Purchase window evidence must bind the exact date column, operator, and interval.'
);
$finalItems = analysisItemsByAlias($analysis['selectItems']);
analysisAssertSame(
    ['function' => 'sum', 'column' => 'circulation_by_instance.circulation'],
    $finalItems['checkouts_per_dollar']['division']['numeratorAggregate'],
    'ROI analysis must preserve the exact numerator aggregate.'
);
analysisAssertSame(
    ['function' => 'sum', 'column' => 'spend_by_instance.spend'],
    $finalItems['checkouts_per_dollar']['division']['denominatorAggregate'],
    'ROI analysis must preserve the exact zero-safe denominator aggregate.'
);
foreach (['purchase_count', 'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout'] as $numericAlias) {
    analysisAssertSame(true, $finalItems[$numericAlias]['provenNumeric'], 'Required measures must have positive numeric-expression proof.');
}
$classItems = analysisItemsByAlias($analysis['ctes']['class_by_instance']['selectItems']);
analysisAssertSame('substring_alpha_prefix', $classItems['call_number_class']['callNumberClassDerivation'], 'Substring class extraction must be registered structurally.');

$unsafeBoolean = ExploratorySqlAnalysisService::analyze(
    "SELECT invoice.id FROM invoice.invoices__t invoice WHERE invoice.campus = 'Smith College' OR 1 = 1"
);
analysisAssertSame(true, $unsafeBoolean['ambiguous'], 'OR in governed predicate context must fail closed.');
$supportedConjunction = ExploratorySqlAnalysisService::analyze(
    "SELECT invoice.id FROM invoice.invoices__t invoice WHERE invoice.campus = 'Smith College' AND invoice.status = 'paid'"
);
analysisAssertSame(false, $supportedConjunction['ambiguous'], 'A conjunction of supported simple predicates must remain deterministic.');
$embeddedCampus = ExploratorySqlAnalysisService::analyze(
    "SELECT invoice.id FROM invoice.invoices__t invoice WHERE CASE WHEN invoice.campus = 'Smith College' THEN TRUE ELSE TRUE END"
);
analysisAssertSame([], $embeddedCampus['predicates']['literalPredicates'], 'Embedded comparisons must not become eligible literal facts.');
analysisAssertSame(true, $embeddedCampus['ambiguous'], 'Unsupported Boolean atoms must fail closed.');
$unknownNumeric = ExploratorySqlAnalysisService::analyze(
    'SELECT CONCAT(SUM(invoice.total)) AS spend FROM invoice.invoices__t invoice'
);
analysisAssertSame(false, $unknownNumeric['selectItems'][0]['provenNumeric'], 'Unknown functions must not receive numeric-expression proof.');

$flawed = ExploratorySqlAnalysisService::analyze($capturedProductionSql);
analysisAssertSame(true, in_array('total_spent', $flawed['formattedAliases'], true), 'TO_CHAR spending must be marked text-formatted.');
analysisAssertSame(true, in_array('cost_per_checkout', $flawed['formattedAliases'], true), 'TO_CHAR cost per checkout must be marked text-formatted.');
analysisAssertSame('pot.date_ordered', $flawed['predicates']['dateColumns'][0], 'Order-date filtering must not be mistaken for payment date.');
analysisAssertSame(true, in_array('item.material_type_id', $flawed['predicates']['governedFilters'], true), 'Governed lookup filters must be inspectable.');

$literalPredicateAnalysis = ExploratorySqlAnalysisService::analyze(
    "SELECT invoice.id FROM invoice.invoices__t invoice WHERE invoice.campus = 'Smith College' AND invoice.status NOT IN ('cancelled')"
);
analysisAssertSame(
    [],
    $literalPredicateAnalysis['predicates']['literalPredicates'],
    'An unsupported NOT IN atom must invalidate all facts in its conjunction.'
);
analysisAssertSame(true, $literalPredicateAnalysis['ambiguous'], 'Unsupported negation must fail the predicate scope closed.');

$malformedClauseOrder = ExploratorySqlAnalysisService::analyze(
    'SELECT item.id FROM inventory.item__t item LIMIT 10 ORDER BY item.id DESC'
);
analysisAssertSame(true, $malformedClauseOrder['ambiguous'], 'LIMIT before ORDER BY must fail closed.');

foreach (['CAST(SUM(amount) AS TEXT)', 'SUM(amount)::text', 'SUM(amount)::varchar', 'SUM(amount)::char'] as $formattedExpression) {
    $formatted = ExploratorySqlAnalysisService::analyze('SELECT ' . $formattedExpression . ' AS spend FROM invoice.invoice_lines__t');
    analysisAssertSame(['spend'], $formatted['formattedAliases'], 'Text casts must mark required aliases as formatted.');
}

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
$duplicateInferredOutputs = ExploratorySqlAnalysisService::analyze(
    'SELECT item.id, holdings.id FROM inventory.item__t item '
    . 'JOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id'
);
analysisAssertSame(true, $duplicateInferredOutputs['ambiguous'], 'Duplicate unaliased output names must fail closed as ambiguous.');

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
analysisAssertSame([], $mixedIn['predicates']['governedFilters'], 'An unsupported atom must invalidate every fact in its conjunction.');
analysisAssertSame(true, $mixedIn['ambiguous'], 'A later unsupported IN must not be masked by an earlier valid IN.');

$aliasCases = [
    ['SELECT amount + tax FROM invoice.invoice_lines__t', 'amount + tax', null, false],
    ['SELECT CASE WHEN paid THEN amount ELSE total END FROM invoice.invoice_lines__t', 'case when paid then amount else total end', null, false],
    ['SELECT amount::numeric FROM invoice.invoice_lines__t', 'amount::numeric', null, false],
    ['SELECT amount FROM invoice.invoice_lines__t', 'amount', 'amount', false],
    ['SELECT funded_line.currency FROM funded_invoice_lines funded_line', 'funded_line.currency', 'currency', false],
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
analysisAssertSame(true, $quotedSourceAliases['ambiguous'], 'Duplicate quoted unaliased output names must fail closed as ambiguous.');
analysisAssertSame(
    [
        'where' => ['kind' => 'table', 'source' => 'inventory.item__t'],
        'order' => ['kind' => 'table', 'source' => 'inventory.holdings_record__t'],
    ],
    $quotedSourceAliases['ctes']['joined_scope']['sourceAliases'],
    'Quoted aliases must remain stable source bindings.'
);

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

$outerJoinFactAnalysis = ExploratorySqlAnalysisService::analyze(
    "SELECT item.id FROM inventory.item__t item LEFT JOIN inventory.loccampus__t \"where\" "
    . "ON \"where\".id = item.id AND \"where\".name = 'Smith College'"
);
$outerJoinCampusFact = $outerJoinFactAnalysis['predicates']['literalPredicates'][0];
analysisAssertSame('join_on', $outerJoinCampusFact['origin'], 'JOIN facts must retain their clause origin.');
analysisAssertSame('LEFT', $outerJoinCampusFact['joinType'], 'JOIN facts must retain nullable-side join type.');
analysisAssertSame('where', $outerJoinCampusFact['joinedAlias'], 'Quoted joined aliases must remain normalized in fact provenance.');
analysisAssertSame('inventory.loccampus__t', $outerJoinCampusFact['joinedSource'], 'JOIN facts must retain their physical joined source.');
analysisAssertSame(
    [['type' => 'LEFT', 'alias' => 'where', 'source' => 'inventory.loccampus__t', 'sourceKind' => 'table']],
    $outerJoinCampusFact['joinPath'],
    'JOIN facts must retain enough path metadata for enforcement decisions.'
);
analysisAssertSame('id', $ordinarySourceGrammar['orderBy'][0]['expression'], 'Unquoted ORDER BY must remain a clause boundary.');
analysisAssertSame('DESC', $ordinarySourceGrammar['orderBy'][0]['direction'], 'Unquoted ordering direction must remain unchanged.');

$structuralBase = 'SELECT i.title, COUNT(*) AS loans FROM inventory.instance__t i '
    . 'GROUP BY i.title ORDER BY loans DESC';
$structuralAliasOnly = 'select inst.title, count(*) as total_loans from inventory.instance__t inst '
    . 'group by inst.title order by total_loans desc';
analysisAssertSame(
    false,
    ExploratorySqlAnalysisService::materiallyDifferent($structuralBase, $structuralAliasOnly),
    'Formatting and alias-only changes must have the same structural signature.'
);
analysisAssertSame(
    true,
    ExploratorySqlAnalysisService::materiallyDifferent($structuralBase, $structuralBase . ' LIMIT 10'),
    'A LIMIT change must be material.'
);
$structuralNewJoin = 'SELECT i.title, COUNT(l.id) FROM inventory.instance__t i '
    . 'JOIN circulation.loan__t l ON l.item_id = i.id GROUP BY i.title';
analysisAssertSame(
    true,
    ExploratorySqlAnalysisService::materiallyDifferent($structuralBase, $structuralNewJoin),
    'A join change must be material.'
);

$structuralVariants = [
    [
        "SELECT i.title, COUNT(*) AS loans FROM inventory.instance__t i WHERE i.status = 'active' "
            . 'GROUP BY i.title ORDER BY loans DESC',
        'A predicate change must be material.',
    ],
    [
        'SELECT i.title, i.status, COUNT(*) AS loans FROM inventory.instance__t i '
            . 'GROUP BY i.title, i.status ORDER BY loans DESC',
        'A grouping-grain change must be material.',
    ],
    [
        'SELECT i.title, SUM(i.edition) AS loans FROM inventory.instance__t i '
            . 'GROUP BY i.title ORDER BY loans DESC',
        'A measure change must be material.',
    ],
    [
        'SELECT i.id, COUNT(*) AS loans FROM inventory.instance__t i '
            . 'GROUP BY i.title ORDER BY loans DESC',
        'An output change must be material.',
    ],
    [
        'SELECT i.title, COUNT(*) AS loans FROM inventory.instance__t i '
            . 'GROUP BY i.title ORDER BY loans ASC',
        'An ordering change must be material.',
    ],
];
foreach ($structuralVariants as [$variantSql, $variantMessage]) {
    analysisAssertSame(
        true,
        ExploratorySqlAnalysisService::materiallyDifferent($structuralBase, $variantSql),
        $variantMessage
    );
}

$structuralSignature = ExploratorySqlAnalysisService::structuralSignature($structuralBase);
analysisAssertSame(
    ['tables', 'joins', 'predicates', 'groupBy', 'measures', 'outputs', 'orderBy', 'limit', 'ambiguous'],
    array_keys($structuralSignature),
    'Structural signatures must expose every deterministic comparison dimension.'
);
analysisAssertSame(
    ['inventory.instance__t.title', 'count (*)'],
    $structuralSignature['outputs'],
    'Output expressions must replace source aliases with physical relation names.'
);
analysisAssertSame(
    [['expression' => 'count (*)', 'direction' => 'DESC']],
    $structuralSignature['orderBy'],
    'ORDER BY output aliases must resolve to their canonical expressions.'
);
analysisAssertSame(
    true,
    ExploratorySqlAnalysisService::structuralSignature('SELECT 1 UNION SELECT 2')['ambiguous'],
    'Unsupported analysis must stay explicitly ambiguous in its signature.'
);

fwrite(STDOUT, "ExploratorySqlAnalysisService test passed\n");
