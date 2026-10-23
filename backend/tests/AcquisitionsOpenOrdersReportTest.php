<?php
require_once __DIR__ . '/support/AcquisitionsOpenOrdersReportFixture.php';
require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';
require_once __DIR__ . '/../services/ReportExecutionContractService.php';

use app\models\ReportTemplate;
use app\services\CatalogingReportCompilerService;
use app\services\ReportExecutionContractService;

function acquisitionsOpenOrdersAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acquisitionsOpenOrdersThrows(callable $callback, string $field, string $message): void
{
    try {
        $callback();
    } catch (\app\exceptions\ReportParameterValidationException $exception) {
        acquisitionsOpenOrdersAssert(isset($exception->getFieldErrors()[$field]), $message);
        return;
    }
    acquisitionsOpenOrdersAssert(false, $message);
}

$probe = new ReportTemplate();
$probe->slug = 'quarterly-open-orders';
acquisitionsOpenOrdersAssert(CatalogingReportCompilerService::supports($probe), 'The governed compiler supports the acquisitions report.');

$report = acquisitionsOpenOrdersReportFixture();
$db = new AcquisitionsOpenOrdersLookupDb();
$compile = static function (array $overrides = []) use ($report, $db): array {
    return CatalogingReportCompilerService::build($report, $overrides, $db);
};

$defaults = $compile();
$today = new \DateTime('today');
$year = (int) $today->format('Y');
$expectedStart = (int) $today->format('n') >= 7 ? sprintf('%04d-07-01', $year) : sprintf('%04d-07-01', $year - 1);
acquisitionsOpenOrdersAssert($defaults['params'][':acqUnit'] === 'SC', 'Default the acquisitions unit to Smith.');
acquisitionsOpenOrdersAssert($defaults['params'][':startDate'] === $expectedStart, 'Default start date to the current Smith fiscal-year start.');
acquisitionsOpenOrdersAssert($defaults['params'][':endDate'] === $today->format('Y-m-d'), 'Default end date to today.');
acquisitionsOpenOrdersAssert($defaults['params'][':orderFormat'] === 'All', 'Default order format to all formats.');
acquisitionsOpenOrdersAssert($defaults['params'][':reEncumber'] === 'Any', 'Default re-encumber to any value.');
acquisitionsOpenOrdersAssert(count($db->queries) === 2, 'Probe the schema in one lookup and validate the selected unit in one lookup.');

$chosen = $compile([
    'acqUnit' => 'SC', 'startDate' => '2025-07-01', 'endDate' => '2026-06-30',
    'orderFormat' => 'Electronic Resource', 'reEncumber' => 'Off',
]);
acquisitionsOpenOrdersAssert($chosen['params'] === [
    ':acqUnit' => 'SC', ':startDate' => '2025-07-01', ':endDate' => '2026-06-30',
    ':orderFormat' => 'Electronic Resource', ':reEncumber' => 'Off',
], 'Bind every report filter as a parameter.');
acquisitionsOpenOrdersAssert(strpos($chosen['sql'], "'SC'") === false, 'Do not interpolate the selected acquisitions unit.');
acquisitionsOpenOrdersAssert($chosen['marcTag'] === 'open-orders', 'Use the governed open-orders report filename context.');
acquisitionsOpenOrdersAssert($chosen['location']['name'] === 'SC' && $chosen['location']['code'] === 'SC', 'Return the selected acquisitions unit as export context.');
ReportExecutionContractService::assertStaticExportSql($chosen['sql'], ['fetchRowLimit' => 100001]);
$contract = ReportExecutionContractService::fromReport($report, [
    'exportKind' => 'worklist', 'marcTag' => $chosen['marcTag'],
    'locationName' => $chosen['location']['name'], 'locationCode' => $chosen['location']['code'],
]);
acquisitionsOpenOrdersAssert($contract['identifierExport']['sourceColumn'] === 'PO Line UUID', 'Configure the existing one-column UUID export for PO line IDs.');

$parameters = $report->getDecodedParameters();
$parameterNames = array_column($parameters, 'name');
acquisitionsOpenOrdersAssert($parameterNames === ['acqUnit', 'startDate', 'endDate', 'orderFormat', 'reEncumber'], 'Declare the five fixed report parameters in ticket order.');
acquisitionsOpenOrdersAssert(strpos($report->parameters, 'orders.acquisitions_unit__t') !== false, 'Load acquisitions-unit choices from FOLIO.');

acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['acqUnit' => 'UNKNOWN']); }, 'acqUnit', 'Reject units outside the FOLIO unit whitelist.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['startDate' => '2026-02-30']); }, 'startDate', 'Reject invalid calendar dates.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['startDate' => '2026-07-02', 'endDate' => '2026-07-01']); }, 'endDate', 'Reject an end date earlier than the start date.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['orderFormat' => 'Unknown']); }, 'orderFormat', 'Reject order formats outside the fixed choices.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['reEncumber' => 'Maybe']); }, 'reEncumber', 'Reject re-encumber values outside Any, On, and Off.');

$originalDefinition = [];
foreach (['slug', 'name', 'category', 'data_source', 'created_by', 'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config'] as $key) {
    $originalDefinition[$key] = $report->$key;
}
acquisitionsOpenOrdersAssert(\app\services\AcquisitionsOpenOrdersReportService::isCanonicalSeedDefinition($originalDefinition), 'Accept the canonical migration definition.');
$drifted = $originalDefinition;
$drifted['sql_template'] = str_replace('EXISTS (', 'NOT EXISTS (', $drifted['sql_template']);
acquisitionsOpenOrdersAssert(!\app\services\AcquisitionsOpenOrdersReportService::isCanonicalSeedDefinition($drifted), 'Reject SQL drift from the canonical report definition.');
$drifted = $originalDefinition;
$driftedParameters = json_decode($drifted['parameters'], true);
$driftedParameters[0]['default'] = 'UM';
$drifted['parameters'] = json_encode($driftedParameters);
acquisitionsOpenOrdersAssert(!\app\services\AcquisitionsOpenOrdersReportService::isCanonicalSeedDefinition($drifted), 'Reject parameter drift from the canonical report definition.');
$drifted = $originalDefinition;
$driftedConfig = json_decode($drifted['execution_config'], true);
$driftedConfig['fetch_row_limit'] = 100000;
$drifted['execution_config'] = json_encode($driftedConfig);
acquisitionsOpenOrdersAssert(!\app\services\AcquisitionsOpenOrdersReportService::isCanonicalSeedDefinition($drifted), 'Reject execution-contract drift.');

$db->missingTable = 'orders.po_line__t';
try {
    $compile();
    acquisitionsOpenOrdersAssert(false, 'Reject a report when a required FOLIO table is missing.');
} catch (\InvalidArgumentException $exception) {
    acquisitionsOpenOrdersAssert(strpos($exception->getMessage(), 'orders.po_line__t') !== false, 'Name the missing required table.');
}

echo "Acquisitions open-orders compiler and execution-contract checks passed\n";
