<?php
require_once __DIR__ . '/support/VisualizationFixture.php';

use app\services\VisualizationDataService;

visualizationCase('category mapping covers every listed type plus unlisted and null material types', function () {
    foreach ([
        'Electronic' => ['Data File', 'Database', 'E-Book', 'E-Book Package', 'E-Journal', 'E-Journal Package', 'E-Newspaper', 'Streaming Video'],
        'Physical' => ['Audio CD', 'Book', 'DVD/Blu-ray', 'Journal', 'Newspaper', 'Score', 'Serial'],
        'Other' => ['Synthetic unlisted format', null],
    ] as $category => $materials) {
        foreach ($materials as $material) {
            visualizationSame($category, VisualizationDataService::categoryForMaterial($material), 'Map ' . var_export($material, true) . ' to ' . $category . '.');
            $f = new VisualizationFixture();
            $f->payment(10, 'amount', 100, $material);
            $point = visualizationByYear($f->service()->get('s', 5)['purchasesByCategory'], 2027);
            visualizationNear(10, $point[strtolower($category)], 'Generated SQL must use the same mapping for ' . var_export($material, true) . '.');
            visualizationNear(10, $point['total'], 'Every material payment contributes exactly once.');
        }
    }
});

visualizationCase('payment arithmetic in generated SQL covers amount, percentage, missing, mixed-case and NULL values', function () {
    foreach ([
        'amount' => [25, 'amount', 200, 25],
        'percentage' => [25, 'percentage', 200, 50],
        'missing type' => [25, null, 200, 50],
        'mixed-case amount' => [25, 'AmOuNt', 200, 25],
        'mixed-case percentage' => [25, 'PeRcEnTaGe', 200, 50],
        'unrecognized type uses percentage' => [25, 'legacy', 200, 50],
        'NULL amount value' => [null, 'amount', 200, 0],
        'NULL percentage value' => [null, 'percentage', 200, 0],
        'NULL total' => [25, 'percentage', null, 0],
        'amount ignores NULL total' => [25, 'amount', null, 25],
    ] as $name => $values) {
        $fixture = new VisualizationFixture();
        $fixture->payment($values[0], $values[1], $values[2]);
        // Keep the year observable when the tested distribution contributes zero.
        $fixture->payment(1);
        $point = visualizationByYear($fixture->service()->get('s', 5)['purchasesByCategory'], 2027);
        visualizationNear($values[3] + 1, $point['total'], 'SQL arithmetic: ' . $name);
        visualizationNear($values[3] + 1, $point['physical'], 'The paid Book distribution belongs to Physical.');
    }
});

visualizationCase('unit-fund scope excludes other funds, deduplicates memberships, and counts PO-less payments as Other', function () {
    $f = new VisualizationFixture();
    $f->payment(20, 'amount', 100, 'E-Book', 'fund-s', 2027, 'Paid', true, 'Book');
    $f->payment(30, 'amount', 100, null, 'fund-s', 2027, 'Paid', false);
    $f->payment(900, 'amount', 100, 'Book', 'fund-a');
    $f->payment(800, 'amount', 100, 'Book', 'fund-s', 2027, 'Open');
    $f->payment(700, 'amount', 100, 'Book', 'fund-s', 2027, 'Paid', true, null, 'AC');
    $point = visualizationByYear($f->service()->get('s', 5)['purchasesByCategory'], 2027);
    visualizationNear(50, $point['total'], 'Filter by invoice fiscal-year series and the unit funds, ignoring payment date and PO unit.');
    visualizationNear(20, $point['electronic'], 'E-resource material type takes precedence over physical material type.');
    visualizationNear(0, $point['physical'], 'The other-unit, unpaid and wrong-series payments are excluded.');
    visualizationNear(30, $point['other'], 'A distribution without a PO line still contributes.');
});

visualizationCase('budget SQL sums only the unit funds and fiscal-year series without join multiplication', function () {
    $f = new VisualizationFixture();
    $f->budget(2027, 60);
    $f->budget(2027, 40);
    $f->budget(2027, 900, 'fund-a');
    $f->budget(2027, 800, 'fund-s', 'AC');
    $point = visualizationByYear($f->service()->get('s', 5)['budget'], 2027);
    visualizationNear(100, $point['allocated'], 'Duplicate fund memberships cannot multiply allocations; both scope predicates apply.');
    visualizationSame('folio', $point['source'], 'FOLIO allocations carry provenance.');
});

visualizationCase('current-year and range anchors use containing period, latest-end fallback, and July-June for no series', function () {
    foreach ([['2026-11-02', 's', 2027], ['2040-01-02', 's', 2028], ['2026-06-30', 'h', 2026], ['2026-07-01', 'h', 2027]] as $case) {
        $f = new VisualizationFixture();
        $f->history($case[2], 'budget', 10, null, null, $case[1] === 'h' ? 'HX' : 'SC');
        $response = $f->service($case[0])->get($case[1], 5);
        visualizationSame($case[2], $response['currentFiscalYearEnd'], 'Anchor follows series periods, then fallback, then July-June.');
        visualizationSame(range($case[2] - 4, $case[2]), $response['fiscalYearEnds'], 'The range contains exactly N end years ending at the anchor.');
    }
});

visualizationCase('history wins independently per kind and year, derives residual Other, omits absent points and carries provenance', function () {
    $f = new VisualizationFixture();
    $f->payment(100, 'amount', 100, 'Book', 'fund-s', 2026);
    $f->payment(40, 'amount', 100, 'Book', 'fund-s', 2027);
    $f->budget(2026, 1000);
    $f->budget(2027, 2000);
    $f->history(2026, 'purchases', 90, 20, 30);
    $f->history(2025, 'purchases', 15, 5, 5);
    $f->history(2027, 'budget', 80);
    $response = $f->service()->get('s', 5);
    $p = visualizationByYear($response['purchasesByCategory'], 2026);
    visualizationNear(90, $p['total'], 'A stored purchases total replaces all FOLIO categories for that year.');
    visualizationNear(20, $p['electronic'], 'Use the complete stored category row.');
    visualizationNear(30, $p['physical'], 'Do not mix stored and FOLIO categories.');
    visualizationNear(40, $p['other'], 'Other is total minus electronic minus physical.');
    visualizationSame('history', $p['source'], 'Stored purchases have history provenance.');
    visualizationSame('folio', visualizationByYear($response['purchasesByCategory'], 2027)['source'], 'Later FOLIO purchases remain visible.');
    visualizationSame('folio', visualizationByYear($response['budget'], 2026)['source'], 'Purchases history does not override budget.');
    visualizationSame('history', visualizationByYear($response['budget'], 2027)['source'], 'Budget precedence is independent.');
    visualizationNear(80, visualizationByYear($response['budget'], 2027)['allocated'], 'Stored budget overrides FOLIO.');
    visualizationSame(3, count($response['purchasesByCategory']), 'Do not invent zero purchase points for absent years.');
    visualizationSame(2, count($response['budget']), 'Do not invent zero budget points for absent years.');
    visualizationNear(40, $response['purchasesByMaterial']['items'][0]['amount'], 'History never supplies material detail; current-year selection is tested separately.');
});

visualizationCase('percent change handles missing, zero and negative prior years, baseline fetch and range invariance', function () {
    $f = new VisualizationFixture();
    foreach ([2021 => 100, 2022 => 150, 2023 => 0, 2024 => -100, 2025 => 50, 2027 => 200] as $year => $amount) $f->budget($year, $amount);
    $service = $f->service();
    $short = $service->get('s', 5)['budget'];
    $long = $service->get('s', 10)['budget'];
    visualizationNear(50, visualizationByYear($long, 2022)['changePct'], 'Ordinary positive change is numeric.');
    visualizationNear(-100, visualizationByYear($short, 2023)['changePct'], 'The first displayed year uses FY2022 outside the five-year window.');
    visualizationSame(null, visualizationByYear($short, 2024)['changePct'], 'A zero prior total has no percentage change.');
    visualizationNear(-150, visualizationByYear($short, 2025)['changePct'], 'Use the signed previous-year denominator for negative totals.');
    visualizationSame(null, visualizationByYear($short, 2027)['changePct'], 'A missing immediately prior year cannot be replaced by the last existing point.');
    foreach ($short as $row) visualizationSame($row['changePct'], visualizationByYear($long, $row['fiscalYearEnd'])['changePct'], 'An overlapping year has identical change in both ranges.');
});

visualizationCase('largest-remainder rounding uses synthetic FY2009-equivalent proportions totaling exactly 100.0', function () {
    // Synthetic proportions expose the same independent-rounding 100.1% failure as FY2009.
    // The migration test separately verifies that the actual FY2009 seed exists.
    $p = VisualizationDataService::categoryPercentages(583.56, 390.84, 25.60);
    visualizationSame(['electronicPct' => 58.3, 'physicalPct' => 39.1, 'otherPct' => 2.6], $p, 'Allocate tenths by descending fractional remainder.');
    visualizationNear(100, array_sum($p), 'The synthetic shares must total 100.0, not 100.1.');
});

visualizationCase('zero and negative category totals retain amounts and expose no spending percentages', function () {
    foreach ([0, -10] as $total) {
        $f = new VisualizationFixture();
        $f->payment($total);
        $point = visualizationByYear($f->service()->get('s', 5)['purchasesByCategory'], 2027);
        visualizationNear($total, $point['total'], 'A real zero/negative paid total remains a table point.');
        foreach (['electronicPct', 'physicalPct', 'otherPct'] as $field) visualizationSame(null, $point[$field], 'A nonpositive total has no spending shares.');
    }
});

visualizationCase('cache key varies with unit, years, material year and history version and expires after one hour', function () {
    $keys = [];
    foreach ([['s', 5, 2027, 1], ['a', 5, 2027, 1], ['s', 10, 2027, 1], ['s', 5, 'all', 1], ['s', 5, 2027, 2]] as $args) $keys[] = call_user_func_array([VisualizationDataService::class, 'cacheKey'], $args);
    visualizationSame(5, count(array_unique($keys)), 'Every cache dimension including history version must affect the key.');
    $f = new VisualizationFixture();
    $f->payment(30);
    $service = $f->service();
    $first = $service->get('s', 5);
    $reads = count($f->folio->queries);
    visualizationSame($first, $service->get('s', 5), 'A warm cache returns the same payload.');
    visualizationSame($reads, count($f->folio->queries), 'A cache hit performs no FOLIO queries.');
    visualizationAssert(in_array(3600, array_column($f->cache->writes, 'duration'), true), 'Chart cache entries have a 3600-second TTL.');
});

visualizationCase('all generated FOLIO SQL passes both validators and transaction wrapper commits or rolls back with a 30s timeout', function () {
    $f = new VisualizationFixture();
    $f->payment(25);
    $f->service()->get('s', 20, 'all');
    visualizationAssert(count($f->folio->queries) > 0 && count($f->folio->queries) <= 4, 'An uncached request issues one to four FOLIO SELECTs.');
    foreach ($f->folio->queries as $query) {
        visualizationAssert(in_array($query['sql'], $f->validator->safety, true), 'Every executed query passed validateSafety.');
        visualizationAssert(in_array($query['sql'], $f->validator->policy, true), 'Every executed query passed validateTablePolicy.');
        visualizationAssert($query['params'] !== [], 'Bind the selected unit/series/year filters in each scoped query.');
        visualizationAssert(preg_match("/'(?:s|SCFY)'/", $query['sql']) !== 1, 'Unit and series values cannot be interpolated into SQL.');
    }
    visualizationAssert(in_array('commit', $f->folio->events, true) && !$f->folio->active, 'Successful reads close the transaction.');
    visualizationAssert(count(array_filter($f->folio->queries, function ($q) { return in_array('s', $q['params'], true); })) > 0, 'The selected unit id is bound as a parameter.');
    foreach (['query', 'safety', 'policy'] as $failure) {
        $f = new VisualizationFixture();
        if ($failure === 'query') $f->folio->failAt = 1; else $f->validator->reject = $failure;
        try { $f->service()->get('s', 5); throw new LogicException('The failure was swallowed.'); }
        catch (Throwable $e) { visualizationAssert(strpos($e->getMessage(), 'synthetic') !== false, 'The original query or validator failure must propagate.'); }
        visualizationAssert(!$f->folio->active, 'Any failure leaves no transaction open.');
        if (in_array('begin', $f->folio->events, true)) visualizationAssert(in_array('rollback', $f->folio->events, true), 'An error inside the transaction rolls it back.');
        visualizationAssert(!in_array('commit', $f->folio->events, true), 'No failing request commits.');
        if ($failure !== 'query') visualizationSame([], $f->folio->queries, 'Rejected SQL must not execute.');
    }
});

visualizationCase('material picker offers current and All FOLIO years, sorts paid totals, and disables history-only/no-funds/empty units', function () {
    $f = new VisualizationFixture();
    $f->payment(10, 'amount', 100, 'Book', 'fund-s', 2026);
    $f->payment(30, 'amount', 100, 'Book');
    $f->payment(20, 'amount', 100, 'E-Book');
    $f->payment(999, 'amount', 100, 'Book', 'fund-h');
    $service = $f->service();
    $current = $service->get('s', 5);
    visualizationSame([2026, 2027], $current['materialFiscalYearEnds'], 'Only years with FOLIO payments appear.');
    visualizationSame(2027, $current['purchasesByMaterial']['fiscalYearEnd'], 'Default material year is the current fiscal year.');
    visualizationSame([['materialType' => 'Book', 'amount' => 30.0], ['materialType' => 'E-Book', 'amount' => 20.0]], $current['purchasesByMaterial']['items'], 'Materials are numeric and sorted by descending amount.');
    $all = $service->get('s', 5, 'all')['purchasesByMaterial'];
    visualizationSame('all', $all['fiscalYearEnd'], 'All FOLIO years is an explicit API selection.');
    visualizationNear(40, $all['items'][0]['amount'], 'All-years material totals include payments from both years.');
    visualizationNear(10, $service->get('s', 5, 2026)['purchasesByMaterial']['items'][0]['amount'], 'An explicit fiscal end year filters invoice fiscal year.');
    foreach (['h' => 'HX', 'n' => 'NF', 'e' => 'EP'] as $id => $code) {
        $f->history(2027, 'budget', 75, null, null, $code);
        foreach ([null, 'all'] as $selection) {
        $response = $service->get($id, 5, $selection);
        visualizationSame([], $response['materialFiscalYearEnds'], 'No-series, no-funds and no-payment units disable the material picker.');
        visualizationSame(['fiscalYearEnd' => null, 'items' => []], $response['purchasesByMaterial'], 'Disabled material view has a nullable selection and no items.');
        visualizationNear(75, visualizationByYear($response['budget'], 2027)['allocated'], 'Stored history remains visible while the material picker is disabled.');
        }
    }
});

visualizationCase('current fiscal year stays selectable when it has no payments but earlier years do', function () {
    $f = new VisualizationFixture();
    $f->payment(12, 'amount', 100, 'Book', 'fund-s', 2026);
    $service = $f->service();
    visualizationSame(2026, $service->get('s', 5, 2026)['purchasesByMaterial']['fiscalYearEnd'], 'An earlier paid year remains selectable.');
    visualizationSame(['fiscalYearEnd' => 2027, 'items' => []], $service->get('s', 5, 2027)['purchasesByMaterial'], 'The current year is valid with an empty material result.');
});

visualizationCase('All FOLIO years rounds only after combining full-precision material totals', function () {
    $f = new VisualizationFixture();
    $f->payment(0.004, 'amount', 100, 'Book', 'fund-s', 2026);
    $f->payment(0.004, 'amount', 100, 'Book', 'fund-s', 2027);
    $all = $f->service()->get('s', 5, 'all')['purchasesByMaterial'];
    visualizationSame([['materialType' => 'Book', 'amount' => 0.01]], $all['items'], 'Two sub-cent years sum before rounding to cents.');
});

visualizationCase('purchase shares use raw category aggregates when displayed subtotals round to zero', function () {
    $f = new VisualizationFixture();
    $f->payment(0.004, 'amount', 100, 'E-Book');
    $f->payment(0.004, 'amount', 100, 'Book');
    $f->payment(0.004, 'amount', 100, null);
    $point = visualizationByYear($f->service()->get('s', 5)['purchasesByCategory'], 2027);
    visualizationSame(0.01, $point['total'], 'The positive total remains visible at cent precision.');
    visualizationSame([0.0, 0.0, 0.0], [$point['electronic'], $point['physical'], $point['other']], 'Each displayed subtotal rounds independently to zero.');
    visualizationAssert($point['electronicPct'] !== null && $point['physicalPct'] !== null && $point['otherPct'] !== null, 'Shares use the positive raw aggregate.');
    visualizationNear(100, $point['electronicPct'] + $point['physicalPct'] + $point['otherPct'], 'Raw-aggregate shares still total 100.0%.');
});

visualizationCase('paid lines without a material type are labelled separately but remain Other purchases', function () {
    $f = new VisualizationFixture();
    $f->payment(12, 'amount', 100, null, 'fund-s', 2027, 'Paid', true, null);
    $data = $f->service()->get('s', 5);
    $point = visualizationByYear($data['purchasesByCategory'], 2027);
    visualizationNear(12, $point['other'], 'No-material purchases remain in the Other category share.');
    visualizationSame([['materialType' => 'No material type', 'amount' => 12.0]], $data['purchasesByMaterial']['items'], 'Material detail names the missing value explicitly.');
});

visualizationCase('active acquisitions-unit filters use PostgreSQL boolean syntax without an integer deleted parameter', function () {
    $f = new VisualizationFixture();
    $f->payment(5);
    $service = $f->service();
    $service->options();
    $service->get('s', 5);
    foreach ($f->folio->queries as $query) {
        visualizationAssert(strpos($query['sql'], 'is_deleted = :deleted') === false, 'Do not compare PostgreSQL booleans with integer parameters.');
        if (stripos($query['sql'], 'is_deleted') !== false) visualizationAssert(preg_match('/NOT\s+(?:[a-z_]+\.)?is_deleted/i', $query['sql']) === 1, 'Use NOT for the FOLIO boolean column.');
        visualizationAssert(!array_key_exists(':deleted', $query['params']), 'No integer deleted flag is bound.');
    }
});

visualizationFinish();
