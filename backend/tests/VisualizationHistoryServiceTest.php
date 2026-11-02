<?php
require_once __DIR__ . '/support/VisualizationFixture.php';

function visualizationHistoryInput(array $overrides = []): array
{
    return array_merge(['acqUnitCode' => 'SC', 'kind' => 'purchases', 'fiscalYearEnd' => 2025, 'totalAmount' => 100, 'electronicAmount' => 40, 'physicalAmount' => 50, 'note' => 'Synthetic history'], $overrides);
}

$visualizationHistoryChecks[] = function () {
    $f = new VisualizationFixture();
    $service = $f->historyService();
    $version = $service->version();
    foreach ([
        ['fiscalYearEnd', 1989], ['fiscalYearEnd', 2101], ['fiscalYearEnd', 2025.5],
        ['totalAmount', null], ['totalAmount', -0.01], ['totalAmount', 1000000000000], ['totalAmount', 'invalid'],
        ['electronicAmount', null], ['electronicAmount', -1], ['electronicAmount', 1000000000000],
        ['physicalAmount', null], ['physicalAmount', -1], ['physicalAmount', 1000000000000],
        ['electronicAmount', 51], ['note', str_repeat('x', 256)], ['acqUnitCode', 'NOT_A_UNIT'], ['kind', 'unknown'],
    ] as $invalid) {
        $result = $service->upsert(visualizationHistoryInput([$invalid[0] => $invalid[1]]), 7);
        visualizationAssert(!empty($result['errors'][$invalid[0]]), 'Return a field error for invalid ' . $invalid[0] . '.');
        visualizationSame(0, (int)$f->db->createCommand('SELECT COUNT(*) FROM visualization_history')->queryScalar(), 'Invalid input writes no rows.');
        visualizationSame($version, $service->version(), 'Rejected input must not invalidate valid cached charts.');
    }
};

$visualizationHistoryChecks[] = function () {
    $f = new VisualizationFixture();
    $service = $f->historyService();
    foreach ([
        visualizationHistoryInput(['fiscalYearEnd' => 1990, 'totalAmount' => 0, 'electronicAmount' => 0, 'physicalAmount' => 0, 'note' => str_repeat('x', 255)]),
        visualizationHistoryInput(['fiscalYearEnd' => 2100, 'totalAmount' => '999999999999.99', 'electronicAmount' => '999999999999.99', 'physicalAmount' => 0]),
        ['acqUnitCode' => 'SC', 'kind' => 'budget', 'fiscalYearEnd' => 2025, 'totalAmount' => 50],
    ] as $valid) {
        $result = $service->upsert($valid, 7);
        visualizationAssert(empty($result['errors']), 'Inclusive valid boundary must save successfully.');
    }
    visualizationSame(3, (int)$f->db->createCommand('SELECT COUNT(*) FROM visualization_history')->queryScalar(), 'Persist all three valid boundary rows.');
    $budget = $f->db->createCommand("SELECT * FROM visualization_history WHERE kind = 'budget'")->queryOne();
    visualizationSame(null, $budget['electronic_amount'], 'Budget categories are nullable.');
    visualizationSame(null, $budget['physical_amount'], 'Budget categories are nullable.');
};

$visualizationHistoryChecks[] = function () {
    $f = new VisualizationFixture();
    $service = $f->historyService();
    $input = visualizationHistoryInput(['updated_by' => 999, 'updatedBy' => 999, 'deleted_by' => 999]);
    $service->upsert($input, 7);
    $first = $f->db->createCommand('SELECT * FROM visualization_history')->queryOne();
    visualizationSame(7, (int)$first['updated_by'], 'Client-supplied actors cannot override the session actor.');
    visualizationAssert(!empty($first['updated_at']), 'Saving records a server timestamp.');
    $f->db->createCommand()->update('visualization_history', ['deleted_at' => '2026-01-01 00:00:00', 'deleted_by' => 8], ['id' => $first['id']])->execute();
    $service->upsert(visualizationHistoryInput(['totalAmount' => 120]), 8);
    $row = $f->db->createCommand('SELECT * FROM visualization_history')->queryOne();
    visualizationSame($first['id'], $row['id'], 'PUT reuses the same unique key and id.');
    visualizationSame(1, (int)$f->db->createCommand('SELECT COUNT(*) FROM visualization_history')->queryScalar(), 'An edit cannot duplicate the historical year.');
    visualizationNear(120, $row['total_amount'], 'The edited amount is saved.');
    visualizationSame(null, $row['deleted_at'], 'An explicit upsert undeletes the row.');
    visualizationSame(null, $row['deleted_by'], 'Undeleting clears the delete actor.');
    visualizationSame(8, (int)$row['updated_by'], 'An update records the current server actor.');
};

$visualizationHistoryChecks[] = function () {
    $f = new VisualizationFixture();
    $f->payment(40);
    $f->history(2027, 'purchases', 100, 30, 60);
    $f->history(2027, 'budget', 200, null, null, 'AC');
    $service = $f->historyService();
    $id = (int)$f->db->createCommand("SELECT id FROM visualization_history WHERE acq_unit_code = 'SC'")->queryScalar();
    $service->delete($id, 7);
    $row = $f->db->createCommand('SELECT * FROM visualization_history WHERE id = :id', [':id' => $id])->queryOne();
    visualizationAssert($row !== false && !empty($row['deleted_at']), 'DELETE preserves the unique row and sets a timestamp.');
    visualizationSame(7, (int)$row['deleted_by'], 'The delete actor is server supplied.');
    visualizationSame([], $service->list('SC'), 'Active history lists omit deleted rows and other units.');
    $point = visualizationByYear($f->service()->get('s', 5)['purchasesByCategory'], 2027);
    visualizationSame('folio', $point['source'], 'Deleting stored history allows the FOLIO year to show.');
    visualizationNear(40, $point['total'], 'Charts ignore deleted historical values.');
};

$visualizationHistoryChecks[] = function () {
    $f = new VisualizationFixture();
    $f->payment(30);
    $history = $f->historyService();
    $data = $f->service();
    $variants = [[5, null], [10, 2027], [20, 'all']];
    foreach ($variants as $variant) $data->get('s', $variant[0], $variant[1]);
    $version = $history->version();
    $history->upsert(visualizationHistoryInput(['fiscalYearEnd' => 2027]), 7);
    visualizationAssert($history->version() > $version, 'Insert increments the shared history version.');
    foreach ($variants as $variant) {
        $point = visualizationByYear($data->get('s', $variant[0], $variant[1])['purchasesByCategory'], 2027);
        visualizationNear(100, $point['total'], 'A write invalidates each warm variant.');
        visualizationSame('history', $point['source'], 'Every variant uses fresh precedence.');
    }
    $version = $history->version();
    $history->upsert(visualizationHistoryInput(['fiscalYearEnd' => 2027, 'totalAmount' => 120]), 8);
    visualizationAssert($history->version() > $version, 'An edit increments the version.');
    $id = (int)$f->db->createCommand('SELECT id FROM visualization_history')->queryScalar();
    $version = $history->version();
    $history->delete($id, 7);
    visualizationAssert($history->version() > $version, 'Soft delete increments the version.');
    foreach ($variants as $variant) visualizationNear(30, visualizationByYear($data->get('s', $variant[0], $variant[1])['purchasesByCategory'], 2027)['total'], 'Delete invalidates every warm variant.');
    $version = $history->version();
    $history->upsert(visualizationHistoryInput(['fiscalYearEnd' => 2027]), 7);
    visualizationAssert($history->version() > $version, 'Undelete increments the version.');
};

visualizationCase('history validation, unique upsert/undelete, soft delete, server actors and write-version bump', function () use ($visualizationHistoryChecks) {
    foreach ($visualizationHistoryChecks as $check) $check();
});

visualizationFinish();
