<?php

function marcCombinationControllerScopeWorkerCases(\app\controllers\FolioQueryController $controller, \app\commands\ExportWorkerController $worker, int $reportId): void
{
    marcCombinationControllerCase('new scopes ignore stale locations through controller and export worker', function () use ($controller, $worker, $reportId) {
        $expected = [
            'holdings_no_items' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'no_holdings' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        ];
        $allJobs = [];
        $historyKeys = [];
        foreach ($expected as $basis => $instanceId) {
            $sameScope = [];
            foreach (['stale-one', 'not-a-location'] as $staleIds) {
                $result = marcCombinationControllerRun($controller, $reportId, marcCombinationControllerParameters([
                    'locationBasis' => $basis,
                    'locationIds' => $staleIds,
                    'c1_contentRule' => 'contains',
                    'c1_searchValue' => 'Scope',
                ]), $basis === 'no_holdings' ? 'identifier' : 'worklist');
                marcCombinationControllerSame(202, $result['status'], 'Location-free scope queues without valid location IDs.');
                $job = marcCombinationControllerJob((string) ($result['body']['jobId'] ?? ''));
                marcCombinationControllerAssertBindings($job);
                $params = json_decode((string) $job['params'], true);
                marcCombinationControllerSame(false, array_key_exists(':locationIds', $params), 'Location-free SQL has no location binding.');
                $metadata = json_decode((string) $job['metadata'], true);
                $filename = $metadata['reportExecution']['downloadFilename'] ?? '';
                marcCombinationControllerSame(true, strpos($filename, $basis === 'holdings_no_items' ? 'sm-holdings-no-items' : 'sm-no-holdings') !== false, 'Export filename uses the fixed Smith scope context.');
                if ($basis === 'no_holdings') marcCombinationControllerSame(true, substr($filename, -strlen('folio-uuids.csv')) === 'folio-uuids.csv', 'No-holdings scope supports identifier export.');
                $sameScope[] = $job;
                $allJobs[] = [$job, $instanceId, $basis === 'no_holdings' ? 'identifier' : 'worklist'];
            }
            foreach (['sql_text', 'params', 'name', 'history_group_key'] as $field) {
                marcCombinationControllerSame($sameScope[0][$field], $sameScope[1][$field], 'Stale location IDs do not change ' . $field . '.');
            }
            $historyKeys[] = $sameScope[0]['history_group_key'];
        }
        marcCombinationControllerSame(false, $historyKeys[0] === $historyKeys[1], 'Different scopes have different history identities.');
        foreach ($allJobs as [$job]) marcCombinationControllerTranslateQueuedSql((string) $job['id']);
        foreach ($allJobs as $_) $worker->actionProcessOnce();
        foreach ($allJobs as [$queued, $instanceId, $exportKind]) {
            $job = marcCombinationControllerJob((string) $queued['id']);
            marcCombinationControllerSame('completed', $job['status'], 'The location-free query completes in the export worker.');
            if ($exportKind === 'worklist') {
                $rows = json_decode((string) $job['result_rows'], true);
                marcCombinationControllerSame($instanceId, $rows[0]['Instance UUID'] ?? null, 'The export worker returns the synthetic instance for the scope.');
            } else {
                $csv = fopen((string) $job['export_file_path'], 'r');
                marcCombinationControllerSame(['UUID'], fgetcsv($csv), 'No-holdings identifier export uses the UUID header.');
                marcCombinationControllerSame([$instanceId], fgetcsv($csv), 'No-holdings identifier export contains its instance.');
                fclose($csv);
            }
        }
        $invalid = marcCombinationControllerRun($controller, $reportId, marcCombinationControllerParameters(['locationBasis' => 'unsupported']));
        marcCombinationControllerSame(400, $invalid['status'], 'Unknown location bases are client field errors on the server.');
        marcCombinationControllerSame('Location basis must be one of the four supported scopes.', $invalid['body']['fieldErrors']['locationBasis'] ?? null, 'Unknown basis errors are attached to locationBasis.');
        $permanent = marcCombinationControllerRun($controller, $reportId, marcCombinationControllerParameters([
            'locationBasis' => 'permanent_item', 'c1_contentRule' => 'any', 'c1_searchValue' => '',
        ]));
        marcCombinationControllerSame(202, $permanent['status'], 'Permanent-item scope passes report safety and export-contract validation.');
        $permanentJob = marcCombinationControllerJob((string) $permanent['body']['jobId']);
        marcCombinationControllerAssertBindings($permanentJob);
        marcCombinationControllerTranslateQueuedSql((string) $permanentJob['id']);
        $worker->actionProcessOnce();
        marcCombinationControllerSame('completed', marcCombinationControllerJob((string) $permanentJob['id'])['status'], 'Permanent-item SQL executes through the export contract.');
    });
}
