<?php
defined('YII_ENV') or define('YII_ENV', 'dev');
require_once __DIR__ . '/support/VisualizationControllerTestSupport.php';

visualizationCase('dev mode allows every visualization route without a bearer token and records the dev actor', function () {
    list($f, $controller) = visualizationControllerFixture();
    Yii::$app->user->setIdentity(null);
    Yii::$app->request->headers->remove('Authorization');

    list($status, $options) = visualizationInvoke($controller, 'options');
    visualizationSame(200, $status, 'GET visualizations/options succeeds without a token in dev.');
    visualizationAssert(isset($options['units']), 'Options returns its unit list.');

    list($status, $chart) = visualizationInvoke($controller, 'index');
    visualizationSame(200, $status, 'GET visualizations succeeds without a token in dev.');
    visualizationAssert(isset($chart['purchasesByCategory']), 'The chart route returns visualization data.');

    list($status, $pace) = visualizationInvoke($controller, 'spending-pace');
    visualizationSame(200, $status, 'GET visualizations/spending-pace succeeds without a token in dev.');
    visualizationAssert(array_key_exists('available', $pace), 'The pace route returns its availability state.');

    list($status, $history) = visualizationInvoke($controller, 'history');
    visualizationSame(200, $status, 'GET visualizations/history succeeds without a token in dev.');
    visualizationSame([], $history, 'The synthetic unit starts with no historical rows.');

    Yii::$app->request->setBodyParams([
        'acqUnitCode' => 'SC', 'kind' => 'budget', 'fiscalYearEnd' => 2025, 'totalAmount' => 123.45,
    ]);
    list($status, $saved) = visualizationInvoke($controller, 'history-upsert');
    visualizationSame(200, $status, 'PUT visualizations/history succeeds without a token in dev.');
    visualizationSame(1, $saved['updatedBy'], 'The saved history row records the dev identity id supplied by DummyIdentity.');
    $row = $f->db->createCommand('SELECT * FROM visualization_history')->queryOne();
    visualizationSame(1, (int)$row['updated_by'], 'updated_by comes from the dev identity.');

    list($status, $deleted) = visualizationInvoke($controller, 'history-delete', ['id' => $row['id']]);
    visualizationSame(200, $status, 'DELETE visualizations/history/<id> succeeds without a token in dev.');
    visualizationSame(true, $deleted['deleted'], 'Delete reports that the row was soft deleted.');
    $row = $f->db->createCommand('SELECT * FROM visualization_history')->queryOne();
    visualizationSame(1, (int)$row['deleted_by'], 'deleted_by comes from the dev identity.');
    visualizationAssert(!empty($row['deleted_at']), 'The dev history delete is soft.');
});

visualizationFinish();
