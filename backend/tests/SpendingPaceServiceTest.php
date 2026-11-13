<?php
require_once __DIR__ . '/support/VisualizationFixture.php';

use app\services\SpendingPaceService;

function paceAssert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

function paceSame($expected, $actual, $message)
{
    paceAssert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

function paceMonth(array $pace, int $month): array
{
    foreach ($pace['months'] as $row) if ($row['fiscalMonth'] === $month) return $row;
    throw new RuntimeException('Missing fiscal month ' . $month);
}

visualizationCase('spending pace follows payment dates, carries signed month totals, scopes encumbrances, and caches safely', function () {
    $f = new VisualizationFixture();
    $f->payment(10, 'amount', 100, 'Book', 'fund-s', 2021, 'Paid', true, null, 'SC', '2025-07-01');
    $f->payment(25, 'PeRcEnTaGe', 40, 'Book', 'fund-s', 2028, 'Paid', true, null, 'SC', '2025-12-01');
    $f->payment(-3, 'amount', 100, 'Book', 'fund-s', 2021, 'Paid', true, null, 'SC', '2026-06-30');
    $f->payment(4, 'amount', 100, 'Book', 'fund-s', 2028, 'Paid', true, null, 'SC', '2026-07-01');
    $f->payment(-2, 'amount', 100, 'Book', 'fund-s', 2021, 'Paid', true, null, 'SC', '2026-09-01');
    $f->payment(500, 'amount', 100, 'Book', 'fund-s', 2027, 'Paid', true, null, 'SC', '2026-11-14');
    $f->payment(200, 'amount', 100, 'Book', 'fund-s', 2027, 'Paid', true, null, 'SC', null);
    $f->payment(99, 'amount', 100, 'Book', 'fund-s', 2027, 'Paid', true, null, 'SC', '2025-06-30');
    $f->insert('finance.fiscal_year__t', [
        'id' => 'SC2027-duplicate', 'series' => 'SCFY', 'name' => 'Synthetic duplicate FY2027',
        'period_start' => '2027-01-01', 'period_end' => '2027-06-30',
    ]);

    $f->budget(2027, 900);
    $f->history(2027, 'budget', 0);
    foreach ([
        ['active', 'SC2027', 'fund-s', 'Active', 100, 20, 30],
        ['unreleased', 'SC2027', 'fund-s', 'Unreleased', 40, null, null],
        ['released', 'SC2027', 'fund-s', 'Released', 999, 0, 0],
        ['wrong-transaction-type', 'SC2027', 'fund-s', 'Active', 600, 0, 0],
        ['stale', 'SC2026', 'fund-s', 'Active', 800, 0, 0],
        ['other-current-year-id', 'SC2027-duplicate', 'fund-s', 'Active', 1000, 0, 0],
        ['other-unit', 'SC2027', 'fund-a', 'Active', 700, 0, 0],
    ] as $row) {
        $f->insert('finance.transaction__t', [
            'id' => $row[0], 'fiscal_year_id' => $row[1], 'from_fund_id' => $row[2],
            'transaction_type' => 'Encumbrance', 'encumbrance__status' => $row[3],
            'encumbrance__initial_amount_encumbered' => $row[4], 'encumbrance__amount_expended' => $row[5],
            'encumbrance__amount_awaiting_payment' => $row[6],
        ]);
    }
    $f->db->createCommand()->update('finance.transaction__t', ['transaction_type' => 'Adjustment'], ['id' => 'wrong-transaction-type'])->execute();
    $f->insert('finance.fund__t', ['id' => 'fund-s-no-budget', 'code' => 'fund-s-no-budget', 'name' => 'Synthetic fund without a budget']);
    $f->insert('finance.fund__t__acq_unit_ids', ['id' => 'fund-s-no-budget', 'acq_unit_ids' => 's']);
    $f->insert('finance.transaction__t', [
        'id' => 'unbudgeted-fund-encumbrance', 'fiscal_year_id' => 'SC2027', 'from_fund_id' => 'fund-s-no-budget',
        'transaction_type' => 'Encumbrance', 'encumbrance__status' => 'Active',
        'encumbrance__initial_amount_encumbered' => 25, 'encumbrance__amount_expended' => 5,
        'encumbrance__amount_awaiting_payment' => 0,
    ]);

    $service = new SpendingPaceService($f->folio, $f->db, $f->cache, $f->validator, '2026-11-13');
    $pace = $service->get('s');
    paceSame(true, $pace['available'], 'The series and unit funds make the pace available.');
    paceSame(2027, $pace['currentFiscalYearEnd'], 'The current FY comes from the series row covering today.');
    paceSame(2026, $pace['previousFiscalYearEnd'], 'Use only the immediately previous series year.');
    paceSame(12, count($pace['months']), 'Return the fixed July-to-June calendar.');
    paceSame(4.0, paceMonth($pace, 1)['currentCumulative'], 'Payments are counted by payment date even on a different invoice FY.');
    paceSame(2.0, paceMonth($pace, 5)['currentCumulative'], 'Exclude post-today and undated payments; credits reduce cumulative spending.');
    paceSame(null, paceMonth($pace, 6)['currentCumulative'], 'Future current-FY months are null.');
    paceSame(2.0, paceMonth($pace, 4)['currentCumulative'], 'Elapsed months without payments carry the cumulative amount.');
    paceSame(10.0, paceMonth($pace, 1)['previousCumulative'], 'The previous year includes the full July-to-June line.');
    paceSame(17.0, paceMonth($pace, 12)['previousCumulative'], 'Percentage arithmetic, negative credits, and the June boundary accumulate.');
    paceSame(110.0, $pace['openEncumbrances'], 'Only current-year Active and Unreleased unit-fund encumbrances count, including a fund without a budget.');
    paceSame(['amount' => 0.0, 'source' => 'history', 'fiscalYearEnd' => 2027], $pace['allocated'], 'Stored zero allocation wins over FOLIO budget.');
    $coldQueries = count($f->folio->queries);
    paceAssert($coldQueries <= 4, 'A cold available request uses no more than four FOLIO SELECTs.');
    paceSame($coldQueries, count($f->validator->safety), 'Each FOLIO query passes the SQL safety validator.');
    paceSame($coldQueries, count($f->validator->policy), 'Each FOLIO query passes the table policy validator.');
    paceSame(3600, $f->cache->writes[0]['duration'], 'Cache pace data for one hour.');
    paceSame($coldQueries, count($f->folio->queries), 'A warm pace lookup does not query FOLIO.');
    $service->get('s');
    paceSame($coldQueries, count($f->folio->queries), 'The warm hit is still at zero additional FOLIO queries.');

    $f->historyService()->upsert([
        'acqUnitCode' => 'SC', 'kind' => 'budget', 'fiscalYearEnd' => 2027,
        'totalAmount' => 23.45, 'electronicAmount' => null, 'physicalAmount' => null, 'note' => null,
    ], 7);
    $edited = $service->get('s');
    paceSame(23.45, $edited['allocated']['amount'], 'A history edit bumps the version and replaces the cached allocation.');
    paceSame($coldQueries * 2, count($f->folio->queries), 'The version change causes one new cold aggregate set.');

    $nextDay = new SpendingPaceService($f->folio, $f->db, $f->cache, $f->validator, '2026-11-14');
    paceSame(502.0, paceMonth($nextDay->get('s'), 5)['currentCumulative'], 'A reporting-date change uses a fresh cache key and includes that day.');
    paceSame($coldQueries * 3, count($f->folio->queries), 'The date-rollover cache miss runs the bounded query set again.');
});

visualizationCase('spending pace handles unavailable units, null bounds, history allocation, and an absent prior year', function () {
    $f = new VisualizationFixture();
    $f->history(2027, 'budget', 0, null, null, 'HX');
    $noSeries = new SpendingPaceService($f->folio, $f->db, $f->cache, $f->validator, '2026-11-13')->get('h');
    paceSame(false, $noSeries['available'], 'A unit without a series has an unavailable response.');
    paceSame('no_series', $noSeries['reason'], 'Use the no-series reason.');
    paceSame(2027, $noSeries['allocated']['fiscalYearEnd'], 'No-series allocation uses the July-to-June anchor year.');
    paceSame(0.0, $noSeries['allocated']['amount'], 'A historical zero remains a real allocation.');
    paceSame(1, count($f->folio->queries), 'No-series stops after context.');

    $f = new VisualizationFixture();
    $f->history(2027, 'budget', 0, null, null, 'NF');
    $f->db->createCommand()->update('finance.fiscal_year__t', [
        'period_start' => '2026-11-01', 'period_end' => '2026-12-31',
    ], ['id' => 'NF2027'])->execute();
    $noFunds = new SpendingPaceService($f->folio, $f->db, $f->cache, $f->validator, '2026-11-13')->get('n');
    paceSame('no_funds', $noFunds['reason'], 'A series without unit funds is unavailable.');
    paceSame(['amount' => 0.0, 'source' => 'history', 'fiscalYearEnd' => 2027], $noFunds['allocated'], 'No-funds allocation uses the July-to-June anchor year even when FOLIO has an unusual period end.');
    paceSame(2, count($f->folio->queries), 'No-funds stops after the context and fund-scope query.');

    $f = new VisualizationFixture();
    $f->db->createCommand()->update('finance.fiscal_year__t', ['period_start' => '2099-01-01'], ['series' => 'SCFY'])->execute();
    $f->db->createCommand()->update('finance.fiscal_year__t', ['period_start' => null], ['id' => 'SC2027'])->execute();
    $f->budget(2027, 45.67);
    $noCurrent = new SpendingPaceService($f->folio, $f->db, $f->cache, $f->validator, '2026-11-13')->get('s');
    paceSame('no_current_fy', $noCurrent['reason'], 'A null start bound cannot cover today.');
    paceSame(null, $noCurrent['currentFiscalYearEnd'], 'Unavailable responses do not invent a current year.');
    paceSame(45.67, $noCurrent['allocated']['amount'], 'The anchor year may still use FOLIO allocation.');
    paceSame('folio', $noCurrent['allocated']['source'], 'Return FOLIO allocation provenance.');
    paceSame(2027, $noCurrent['allocated']['fiscalYearEnd'], 'Keep the allocation year explicit.');
    paceSame([], $noCurrent['months'], 'Unavailable responses have no spending months.');
    paceSame(null, $noCurrent['openEncumbrances'], 'Unavailable responses have no encumbrance total.');

    $f = new VisualizationFixture();
    $f->db->createCommand()->update('finance.fiscal_year__t', ['period_start' => '2099-01-01'], ['series' => 'SCFY'])->execute();
    $f->db->createCommand()->update('finance.fiscal_year__t', ['period_end' => null], ['id' => 'SC2027'])->execute();
    $missingEnd = new SpendingPaceService($f->folio, $f->db, $f->cache, $f->validator, '2026-11-13')->get('s');
    paceSame('no_current_fy', $missingEnd['reason'], 'A series with a null end bound does not cover today.');
    paceSame(null, $missingEnd['allocated']['amount'], 'A null end bound cannot select an anchor-year FOLIO allocation.');

    $f = new VisualizationFixture();
    $f->payment(0, 'amount', 0, 'Book', 'fund-a', 2027, 'Paid', true, null, 'AC', '2026-07-01');
    $noPrevious = new SpendingPaceService($f->folio, $f->db, $f->cache, $f->validator, '2026-11-13')->get('a');
    paceSame(null, $noPrevious['previousFiscalYearEnd'], 'Do not substitute an older series year.');
    paceSame(null, paceMonth($noPrevious, 1)['previousCumulative'], 'An absent previous year has null comparison values.');
    paceSame(0.0, paceMonth($noPrevious, 1)['currentCumulative'], 'An elapsed month with a real zero is numeric zero.');
});

visualizationCase('spending pace accumulates raw monthly values before rounding cumulative output', function () {
    $f = new VisualizationFixture();
    $f->payment(0.004, 'amount', 0, 'Book', 'fund-s', 2021, 'Paid', true, null, 'SC', '2026-07-15');
    $f->payment(0.004, 'amount', 0, 'Book', 'fund-s', 2021, 'Paid', true, null, 'SC', '2026-08-15');
    $pace = new SpendingPaceService($f->folio, $f->db, $f->cache, $f->validator, '2026-08-20')->get('s');
    paceSame(0.0, paceMonth($pace, 1)['currentCumulative'], 'A real elapsed sub-cent value is represented as zero, not null.');
    paceSame(0.01, paceMonth($pace, 2)['currentCumulative'], 'Two unrounded monthly values accumulate before display rounding.');
});

visualizationCase('rejected spending pace SQL rolls back without executing or caching', function () {
    $f = new VisualizationFixture();
    $f->validator->reject = 'safety';
    $service = new SpendingPaceService($f->folio, $f->db, $f->cache, $f->validator, '2026-11-13');
    try {
        $service->get('s');
        throw new RuntimeException('Expected SQL validation to reject the query.');
    } catch (InvalidArgumentException $error) {
        paceSame('synthetic safety rejection', $error->getMessage(), 'Preserve the validator error.');
    }
    paceSame([], $f->folio->queries, 'Rejected SQL never executes.');
    paceAssert(in_array('rollback', $f->folio->events, true), 'Validation errors roll back the READ ONLY transaction.');
    paceSame([], $f->cache->writes, 'Failures never create a successful pace cache entry.');
});

visualizationFinish();
