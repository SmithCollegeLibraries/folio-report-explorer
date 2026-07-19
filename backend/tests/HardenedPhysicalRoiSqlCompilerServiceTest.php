<?php

require_once __DIR__ . '/../services/ExploratoryQueryDefaultsService.php';
require_once __DIR__ . '/../services/ExploratorySemanticContractService.php';
require_once __DIR__ . '/../services/ExploratorySqlSemanticValidatorService.php';
require_once __DIR__ . '/../services/HardenedPhysicalRoiSqlCompilerService.php';

use app\services\ExploratoryQueryDefaultsService;
use app\services\ExploratorySemanticContractService;
use app\services\ExploratorySqlSemanticValidatorService;
use app\services\HardenedPhysicalRoiSqlCompilerService;

function compilerAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function compilerAssertContains(string $needle, string $haystack, string $message): void
{
    compilerAssertSame(true, strpos($haystack, $needle) !== false, $message);
}

function compilerAssertNotContains(string $needle, string $haystack, string $message): void
{
    compilerAssertSame(false, strpos($haystack, $needle) !== false, $message);
}

function compilerAssertMatches(string $pattern, string $actual, string $message): void
{
    compilerAssertSame(1, preg_match($pattern, $actual), $message);
}

function evaluatePhysicalAllocation(array $invoiceLines, array $pieceLinks, array $directLinks, array $currentItems): array
{
    $eligibleIds = array_fill_keys(array_column($currentItems, 'item_id'), true);
    $currentInstances = array_fill_keys(array_column($currentItems, 'instance_id'), true);
    $instanceByItem = array_column($currentItems, 'instance_id', 'item_id');
    $exactByPoLine = [];
    foreach (array_merge($pieceLinks, $directLinks) as $link) {
        if (isset($eligibleIds[$link['item_id']])) {
            $exactByPoLine[$link['po_line_id']][$link['item_id']] = true;
        }
    }

    $paidPoLines = [];
    foreach ($invoiceLines as $invoiceLine) {
        $key = $invoiceLine['po_line_id'] . '|' . $invoiceLine['currency'];
        $paidPoLines[$key] ??= [
            'po_line_id' => $invoiceLine['po_line_id'],
            'instance_id' => $invoiceLine['instance_id'],
            'currency' => $invoiceLine['currency'],
            'quantity' => 0,
            'spend' => 0.0,
        ];
        $paidPoLines[$key]['quantity'] += $invoiceLine['quantity'];
        foreach ($invoiceLine['fund_distributions'] as $distribution) {
            $paidPoLines[$key]['spend'] += $distribution['total'] * $distribution['percentage'] * 0.01;
        }
    }

    $allocations = [];
    foreach ($paidPoLines as $paidPoLine) {
        $exactItems = $exactByPoLine[$paidPoLine['po_line_id']] ?? [];
        $exact = min($paidPoLine['quantity'], count($exactItems));
        $instanceCounts = [];
        foreach (array_keys($exactItems) as $itemId) {
            $instanceId = $instanceByItem[$itemId];
            $instanceCounts[$instanceId] = ($instanceCounts[$instanceId] ?? 0) + 1;
        }
        uksort($instanceCounts, static function (string $left, string $right) use ($instanceCounts): int {
            return $instanceCounts[$right] <=> $instanceCounts[$left] ?: $left <=> $right;
        });
        $preferredExactInstance = array_key_first($instanceCounts);
        $fallback = isset($currentInstances[$paidPoLine['instance_id']])
            ? max($paidPoLine['quantity'] - $exact, 0)
            : 0;
        if ($exact === 0 && $fallback === 0) {
            continue;
        }
        $allocations[$paidPoLine['po_line_id']] = [
            'exact' => $exact,
            'fallback' => $fallback,
            'allocated' => $exact + $fallback,
            'spend' => $paidPoLine['spend'],
            'resolved_instance_id' => $preferredExactInstance ?? $paidPoLine['instance_id'],
        ];
    }
    return $allocations;
}

function evaluateFinalAggregation(array $allocations, array $dominantClasses, array $circulation): array
{
    $results = [];
    foreach ($allocations as $allocation) {
        $instanceId = $allocation['resolved_instance_id'];
        $class = $dominantClasses[$instanceId];
        $results[$class] ??= ['exact' => 0, 'fallback' => 0, 'copies' => 0, 'spend' => 0.0, 'circulation' => 0];
        $results[$class]['exact'] += $allocation['exact'];
        $results[$class]['fallback'] += $allocation['fallback'];
        $results[$class]['copies'] += $allocation['allocated'];
        $results[$class]['spend'] += $allocation['spend'];
        $results[$class]['circulation'] += $circulation[$instanceId] ?? 0;
    }
    return $results;
}

function evaluateDominantClass(array $items): array
{
    $counts = [];
    foreach ($items as $item) {
        $callNumber = trim((string)($item['call_number'] ?? ''));
        if ($callNumber === '') {
            $class = 'Unclassified';
        } elseif (preg_match('/^([A-Z]{1,3})[0-9]/i', $callNumber, $matches) === 1) {
            $class = strtoupper($matches[1]);
        } elseif (preg_match('/^([0-9]+)/', $callNumber, $matches) === 1) {
            $class = str_pad((string)(intdiv((int)$matches[1], 100) * 100), 3, '0', STR_PAD_LEFT);
        } else {
            $class = 'Local/Other';
        }
        $counts[$item['instance_id']][$class] = ($counts[$item['instance_id']][$class] ?? 0) + 1;
    }

    $dominant = [];
    foreach ($counts as $instanceId => $classCounts) {
        uksort($classCounts, static function (string $left, string $right) use ($classCounts): int {
            return $classCounts[$right] <=> $classCounts[$left] ?: $left <=> $right;
        });
        $dominant[$instanceId] = array_key_first($classCounts);
    }
    return $dominant;
}

function evaluateDistinctCheckouts(array $loans, array $eligibleItemIds): int
{
    $eligible = array_fill_keys($eligibleItemIds, true);
    $loanIds = [];
    foreach ($loans as $loan) {
        if (isset($eligible[$loan['item_id']])
            && in_array(strtolower($loan['action']), ['checkedout', 'checkedoutthroughoverride'], true)) {
            $loanIds[$loan['loan_id']] = true;
        }
    }
    return count($loanIds);
}

function compilerAssertPhysicalColumnsExist(string $sql): void
{
    $cache = json_decode((string)file_get_contents(__DIR__ . '/../data/column_cache.json'), true);
    $columnsByTable = $cache['columns'] ?? [];
    $subtableCache = json_decode((string)file_get_contents(__DIR__ . '/../data/subtable_cache.json'), true);
    foreach (($subtableCache['subtables'] ?? []) as $table => $definition) {
        $columnsByTable[strtolower($table)] = $definition['columns'] ?? [];
    }

    preg_match_all(
        '/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*)\s+([a-z_][a-z0-9_]*)\b/i',
        $sql,
        $bindings,
        PREG_SET_ORDER
    );

    $tableByAlias = [];
    $physicalTables = [];
    foreach ($bindings as $binding) {
        $table = strtolower($binding[1]);
        $tableByAlias[strtolower($binding[2])] = $table;
        $physicalTables[] = $table;
    }

    preg_match_all('/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $sql, $references, PREG_SET_ORDER);
    $missing = [];
    foreach ($references as $reference) {
        $alias = strtolower($reference[1]);
        if (in_array($alias . '.' . strtolower($reference[2]), $physicalTables, true)
            || !isset($tableByAlias[$alias])) {
            continue;
        }

        $table = $tableByAlias[$alias];
        $availableColumns = array_map('strtolower', array_column($columnsByTable[$table] ?? [], 'name'));
        if (!in_array(strtolower($reference[2]), $availableColumns, true)) {
            $missing[] = $table . '.' . strtolower($reference[2]);
        }
    }

    compilerAssertSame([], array_values(array_unique($missing)), 'Compiled ROI SQL must use discovered physical columns only.');
}

function buildPhysicalRoiContract(string $question): array
{
    return ExploratorySemanticContractService::build(
        $question,
        'Smith College',
        ExploratoryQueryDefaultsService::resolve($question),
        'unsupported_query_family'
    );
}

$question = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$contract = buildPhysicalRoiContract($question);
$compiled = HardenedPhysicalRoiSqlCompilerService::compile($contract);

compilerAssertSame(true, is_array($compiled), 'The documented physical ROI contract must compile.');
compilerAssertSame('physical_roi_v2', $compiled['compilerVersion'] ?? null, 'Compiler version must be disclosed.');
compilerAssertSame('folio', $compiled['dataSource'] ?? null, 'The compiler must disclose its FOLIO source.');
compilerAssertContains('orders.pieces__t', $compiled['sql'], 'Exact receiving linkage is required.');
compilerAssertContains('purchase_order_line_identifier', $compiled['sql'], 'Direct item PO-line linkage is required.');
compilerAssertContains("TRIM(acquisition_unit.name) = 'SC'", $compiled['sql'], 'Smith acquisitions are required.');
compilerAssertContains('cost__quantity_physical > 0', $compiled['sql'], 'Electronic-only lines must be excluded.');
compilerAssertNotContains("LOWER(material_type.name) = 'book'", $compiled['sql'], 'Generic ROI must not force books.');
compilerAssertContains('COUNT(DISTINCT audit_loan.loan__id)', $compiled['sql'], 'Distinct loans must be counted.');
compilerAssertContains('audit_loan.loan__loan_date', $compiled['sql'], 'Checkout date must use loan date.');
compilerAssertContains('ROW_NUMBER() OVER', $compiled['sql'], 'Dominant class must be deterministic.');
compilerAssertContains("ELSE 'Local/Other'", $compiled['sql'], 'Arbitrary text must not become a class.');
compilerAssertContains("'Unclassified'", $compiled['sql'], 'Blank call numbers need a stable class.');
compilerAssertContains('physical_copies_purchased', $compiled['sql'], 'Physical copy output is required.');
compilerAssertContains('distinct_titles', $compiled['sql'], 'Distinct title output is required.');
compilerAssertContains('fallback_percentage', $compiled['sql'], 'Linkage coverage is required.');
compilerAssertContains('exact_item_links AS', $compiled['sql'], 'Exact piece and direct links must be unified.');
compilerAssertContains('piece_exact_links AS', $compiled['sql'], 'Receiving-piece exact links must use an indexed branch.');
compilerAssertContains('direct_exact_links AS', $compiled['sql'], 'Direct exact links must use an indexed branch.');
compilerAssertContains('receiving_piece.po_line_id = piece_paid_line.po_line_id', $compiled['sql'], 'Piece links must bind by PO-line ID.');
compilerAssertContains('eligible_piece_item.item_id = receiving_piece.item_id', $compiled['sql'], 'Piece links must bind by eligible item ID.');
compilerAssertContains('eligible_direct_item.purchase_order_line_identifier = direct_paid_line.po_line_id', $compiled['sql'], 'Direct links must bind by PO-line identifier.');
compilerAssertContains('FULL OUTER JOIN direct_exact_links', $compiled['sql'], 'Overlapping exact branches must de-duplicate without UNION or Cartesian expansion.');
compilerAssertNotContains('eligible_item.instance_id = eligible_item.instance_id', $compiled['sql'], 'Exact links must not use a tautological Cartesian join.');
compilerAssertNotContains(' ON 1 = 1', $compiled['sql'], 'Exact links must not use a numeric tautological join.');
compilerAssertContains('FROM exact_item_links', $compiled['sql'], 'Exact-link counts must consume exact item links.');
compilerAssertContains('preferred_exact_instance AS', $compiled['sql'], 'Exact item instances must be ranked deterministically.');
compilerAssertContains('ORDER BY exact_instance_counts.linked_item_count DESC, exact_instance_counts.instance_id ASC', $compiled['sql'], 'Preferred exact instance must have a deterministic tie break.');
compilerAssertContains('preferred_exact_instance.instance_id AS preferred_exact_instance_id', $compiled['sql'], 'Allocation must carry exact instance attribution.');
compilerAssertContains('COALESCE(linkage_by_po_line.preferred_exact_instance_id, linkage_by_po_line.fallback_instance_id) AS resolved_instance_id', $compiled['sql'], 'Eligible acquisitions must prefer exact instance attribution.');
compilerAssertContains('FROM paid_po_lines paid_line', $compiled['sql'], 'Allocation must consume one row per PO line and currency.');
compilerAssertContains('LEFT JOIN exact_link_counts exact_links', $compiled['sql'], 'Allocation must consume aggregated exact-link counts.');
compilerAssertContains('LEFT JOIN current_smith_instances fallback_eligible', $compiled['sql'], 'Fallback eligibility must not exclude exact-linked PO lines.');
compilerAssertContains('JOIN dominant_class ON dominant_class.instance_id = acquisitions_by_instance.instance_id', $compiled['sql'], 'Final aggregation must consume the deterministic dominant class.');
compilerAssertContains('FROM current_smith_items item', $compiled['sql'], 'Circulation must use the governed current-item cohort.');
compilerAssertContains('FROM current_smith_items' . "\n" . '    GROUP BY current_smith_items.instance_id', $compiled['sql'], 'Fallback eligibility must derive from the governed current-item cohort.');
compilerAssertContains('FROM current_smith_items' . "\n" . '), class_counts AS', $compiled['sql'], 'Classing must use only the governed current-item cohort.');
compilerAssertNotContains('class_by_instance', $compiled['sql'], 'The raw minimum-substring class path must be removed.');
compilerAssertNotContains('paid_invoice_lines AS', $compiled['sql'], 'The unused paid-invoice-line CTE must be removed.');

$allocationFixture = evaluatePhysicalAllocation(
    [
        ['po_line_id' => 'po-1', 'instance_id' => 'instance-1', 'currency' => 'USD', 'quantity' => 1, 'fund_distributions' => [['total' => 30, 'percentage' => 50], ['total' => 30, 'percentage' => 50]]],
        ['po_line_id' => 'po-1', 'instance_id' => 'instance-1', 'currency' => 'USD', 'quantity' => 1, 'fund_distributions' => [['total' => 20, 'percentage' => 100]]],
        ['po_line_id' => 'po-2', 'instance_id' => 'instance-2', 'currency' => 'USD', 'quantity' => 1, 'fund_distributions' => [['total' => 10, 'percentage' => 100]]],
        ['po_line_id' => 'po-3', 'instance_id' => 'instance-3', 'currency' => 'USD', 'quantity' => 1, 'fund_distributions' => [['total' => 12, 'percentage' => 100]]],
    ],
    [['po_line_id' => 'po-1', 'item_id' => 'item-1']],
    [['po_line_id' => 'po-1', 'item_id' => 'item-1'], ['po_line_id' => 'po-2', 'item_id' => 'item-2']],
    [['item_id' => 'item-1', 'instance_id' => 'instance-1'], ['item_id' => 'item-2', 'instance_id' => 'unrelated-instance'], ['item_id' => 'item-3', 'instance_id' => 'instance-3']]
);
compilerAssertSame(['exact' => 1, 'fallback' => 1, 'allocated' => 2, 'spend' => 50.0, 'resolved_instance_id' => 'instance-1'], $allocationFixture['po-1'], 'Exact links must be counted once after multiple invoice lines and fund distributions are collapsed.');
compilerAssertSame(['exact' => 1, 'fallback' => 0, 'allocated' => 1, 'spend' => 10.0, 'resolved_instance_id' => 'unrelated-instance'], $allocationFixture['po-2'], 'An exact eligible link must survive and use its linked-item instance without an unrelated fallback match.');
compilerAssertSame(['exact' => 0, 'fallback' => 1, 'allocated' => 1, 'spend' => 12.0, 'resolved_instance_id' => 'instance-3'], $allocationFixture['po-3'], 'A fully unmatched PO line must use its eligible PO-line instance fallback.');

$dominantFixture = evaluateDominantClass([
    ['instance_id' => 'a', 'call_number' => 'qa76'],
    ['instance_id' => 'a', 'call_number' => 'QA77'],
    ['instance_id' => 'a', 'call_number' => '500.2'],
    ['instance_id' => 'a', 'call_number' => ''],
    ['instance_id' => 'b', 'call_number' => 'local shelf'],
    ['instance_id' => 'b', 'call_number' => null],
]);
compilerAssertSame('QA', $dominantFixture['a'], 'LC classes must be uppercase and win by eligible-item count.');
compilerAssertSame('Local/Other', $dominantFixture['b'], 'Dominant-class ties must resolve by class name ascending.');
$finalFixture = evaluateFinalAggregation(
    $allocationFixture,
    ['instance-1' => 'QA', 'unrelated-instance' => 'DVD', 'instance-3' => 'Local/Other'],
    ['instance-1' => 4, 'unrelated-instance' => 7, 'instance-3' => 1]
);
compilerAssertSame(['exact' => 1, 'fallback' => 0, 'copies' => 1, 'spend' => 10.0, 'circulation' => 7], $finalFixture['DVD'], 'Exact-only acquisitions must reach the linked-item instance class and circulation.');
compilerAssertSame(['exact' => 0, 'fallback' => 1, 'copies' => 1, 'spend' => 12.0, 'circulation' => 1], $finalFixture['Local/Other'], 'Fallback-only acquisitions must reach the PO-line instance class and circulation.');
compilerAssertSame(1, evaluateDistinctCheckouts([
    ['loan_id' => 'loan-1', 'item_id' => 'dvd-1', 'action' => 'checkedout'],
    ['loan_id' => 'loan-1', 'item_id' => 'dvd-1', 'action' => 'checkedOutThroughOverride'],
    ['loan_id' => 'loan-2', 'item_id' => 'book-1', 'action' => 'checkedout'],
], ['dvd-1']), 'Duplicate loan actions and non-cohort items must not inflate circulation.');
compilerAssertPhysicalColumnsExist($compiled['sql']);
compilerAssertSame(
    'validated',
    ExploratorySqlSemanticValidatorService::validate($compiled['sql'], $contract)['status'] ?? null,
    'Compiled physical ROI SQL must pass semantic validation.'
);

$dvdQuestion = 'Show me which DVD call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$dvdContract = buildPhysicalRoiContract($dvdQuestion);
$dvdCompiled = HardenedPhysicalRoiSqlCompilerService::compile($dvdContract);
compilerAssertSame(true, is_array($dvdCompiled), 'The explicitly requested DVD report must compile.');
compilerAssertContains("LOWER(material_type.name) = 'dvd'", $dvdCompiled['sql'], 'DVD reports must enforce the requested material type.');
compilerAssertSame(1, substr_count($dvdCompiled['sql'], 'FROM inventory.item__t'), 'DVD reports must define one physical item cohort for every downstream measure.');
compilerAssertNotContains("LOWER(material_type.name) = 'dvd'", $compiled['sql'], 'Generic ROI must not add a DVD predicate.');
compilerAssertSame(
    'validated',
    ExploratorySqlSemanticValidatorService::validate($dvdCompiled['sql'], $dvdContract)['status'] ?? null,
    'Compiled DVD ROI SQL must pass semantic validation.'
);

$supportedDefaults = [
    'purchase_date_basis',
    'investment_cost_basis',
    'circulation_window',
    'call_number_grouping',
    'roi_formula',
];
foreach ($supportedDefaults as $key) {
    $variant = $contract;
    foreach ($variant['requirements'] as &$requirement) {
        if (($requirement['key'] ?? null) === $key) {
            $requirement['parameters']['value'] = 'unsupported_variant';
        }
    }
    unset($requirement);
    compilerAssertSame(null, HardenedPhysicalRoiSqlCompilerService::compile($variant), "Unsupported {$key} variants must return null.");
}

$notApplicable = $contract;
$notApplicable['applicable'] = false;
compilerAssertSame(null, HardenedPhysicalRoiSqlCompilerService::compile($notApplicable), 'Non-applicable contracts must return null.');

fwrite(STDOUT, "Hardened physical ROI SQL compiler test passed\n");
