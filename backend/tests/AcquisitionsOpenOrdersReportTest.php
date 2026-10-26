<?php
require_once __DIR__ . '/support/AcquisitionsOpenOrdersReportFixture.php';
require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';
require_once __DIR__ . '/../services/ReportExecutionContractService.php';
require_once __DIR__ . '/../services/SqlBuilderService.php';
require_once __DIR__ . '/../services/FolioSchemaService.php';

use app\models\ReportTemplate;
use app\services\CatalogingReportCompilerService;
use app\services\ReportExecutionContractService;
use app\services\SqlBuilderService;

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
acquisitionsOpenOrdersAssert($defaults['params'][':acqUnit'] === 'SC', 'Default the acquisitions unit to Smith.');
acquisitionsOpenOrdersAssert($defaults['params'][':startDate'] === null, 'Leave the optional report start date blank by default.');
acquisitionsOpenOrdersAssert($defaults['params'][':endDate'] === null, 'Leave the optional report end date blank by default.');
acquisitionsOpenOrdersAssert($defaults['params'][':orderFormat'] === 'All', 'Default order format to all formats.');
acquisitionsOpenOrdersAssert($defaults['params'][':reEncumber'] === 'Any', 'Default re-encumber to any value.');
acquisitionsOpenOrdersAssert(strpos($defaults['sql'], '/*__') === false, 'Compile the default status filters before execution.');
acquisitionsOpenOrdersAssert(strpos($defaults['sql'], 'AND (TRUE)') !== false, 'Default all status selections to no narrowing predicate.');
acquisitionsOpenOrdersAssert(strpos($defaults['sql'], 'il.workflow_status AS "PO status"') !== false, 'Include the purchase-order workflow status column.');
acquisitionsOpenOrdersAssert(count($db->queries) === 4, 'Probe schema, acquisitions unit, material-type options, and expense-class options separately.');

$expandedDefaults = $compile();
acquisitionsOpenOrdersAssert($expandedDefaults['params'][':startDate'] === null, 'Leave the optional report start date blank by default.');
acquisitionsOpenOrdersAssert($expandedDefaults['params'][':endDate'] === null, 'Leave the optional report end date blank by default.');
acquisitionsOpenOrdersAssert(!array_key_exists(':workflowStatus0', $expandedDefaults['params'])
    && !array_key_exists(':orderType0', $expandedDefaults['params']), 'Do not narrow default all-status and all-order-type selections.');
acquisitionsOpenOrdersAssert($expandedDefaults['params'][':lineView'] === 'Not yet received or not yet paid', 'Keep the existing eligible-line view as the default.');
acquisitionsOpenOrdersAssert(strpos($expandedDefaults['sql'], '/*__') === false, 'Compile every report filter before execution.');

$chosen = $compile([
    'acqUnit' => 'SC', 'workflowStatuses' => 'Pending,Closed', 'orderTypes' => 'Ongoing',
    'lineView' => 'All lines', 'titleSearch' => '50%_\\', 'poLineNumberSearch' => 'SC_4%',
    'startDate' => '2025-07-01', 'endDate' => '',
    'orderFormat' => 'Electronic Resource', 'reEncumber' => 'Off',
    'receiptStatuses' => 'Receipt Not Required,Cancelled', 'paymentStatuses' => 'Payment Not Required',
    'materialTypes' => 'Book', 'expenseClasses' => 'expense-1,__none__',
]);
acquisitionsOpenOrdersAssert($chosen['params'][':acqUnit'] === 'SC'
    && $chosen['params'][':workflowStatus0'] === 'Pending'
    && $chosen['params'][':workflowStatus1'] === 'Closed'
    && $chosen['params'][':orderType0'] === 'Ongoing'
    && $chosen['params'][':lineView'] === 'All lines', 'Bind PO status, order type, and line view values.');
acquisitionsOpenOrdersAssert($chosen['params'][':startDate'] === '2025-07-01' && $chosen['params'][':endDate'] === null, 'Allow a start date without an end date.');
acquisitionsOpenOrdersAssert($chosen['params'][':titleSearch'] === '%50\\%\\_\\\\%' && $chosen['params'][':poLineNumberSearch'] === '%SC\\_4\\%%', 'Escape search wildcards and bind partial-match patterns.');
acquisitionsOpenOrdersAssert($chosen['params'][':receiptStatus0'] === 'Receipt Not Required'
    && $chosen['params'][':receiptStatus1'] === 'Cancelled'
    && $chosen['params'][':paymentStatus0'] === 'Payment Not Required', 'Bind every receipt and payment status value.');
acquisitionsOpenOrdersAssert($chosen['params'][':materialType0'] === 'Book'
    && $chosen['params'][':expenseClass0'] === 'expense-1'
    && strpos($chosen['sql'], 'no_class_fd.fund_distribution__expense_class_id IS NOT NULL') !== false, 'Bind material types and expense classes and compile the no-class choice safely.');
acquisitionsOpenOrdersAssert(strpos($chosen['sql'], "'SC'") === false, 'Do not interpolate the selected acquisitions unit.');
acquisitionsOpenOrdersAssert(strpos($chosen['sql'], 'pol.receipt_status IN (:receiptStatus0, :receiptStatus1)') !== false, 'Apply receipt status after the line-view rule.');
acquisitionsOpenOrdersAssert(strpos($chosen['sql'], 'pol.payment_status IN (:paymentStatus0)') !== false, 'Apply payment status after the existing line inclusion rule.');
acquisitionsOpenOrdersAssert(strpos($chosen['sql'], 'pol.title_or_package ILIKE so.title_search ESCAPE CHR(92)') !== false
    && strpos($chosen['sql'], 'pol.po_line_number ILIKE so.po_line_number_search ESCAPE CHR(92)') !== false, 'Apply case-insensitive escaped title and PO-line searches.');
acquisitionsOpenOrdersAssert(strpos($chosen['sql'], 'invoice.invoice_lines__t') !== false
    && strpos($chosen['sql'], "invoice_line_status = 'Paid'") !== false
    && strpos($chosen['sql'], 'paid_totals') !== false, 'Aggregate all-time paid invoice lines separately from report filters.');
acquisitionsOpenOrdersAssert($chosen['marcTag'] === 'open-orders', 'Use the governed open-orders report filename context.');
acquisitionsOpenOrdersAssert($chosen['location']['name'] === 'SC' && $chosen['location']['code'] === 'SC', 'Return the selected acquisitions unit as export context.');
ReportExecutionContractService::assertStaticExportSql($chosen['sql'], ['fetchRowLimit' => 100001]);
SqlBuilderService::validateSafety($chosen['sql']);
SqlBuilderService::validateTablePolicy($chosen['sql']);
$contract = ReportExecutionContractService::fromReport($report, [
    'exportKind' => 'worklist', 'marcTag' => $chosen['marcTag'],
    'locationName' => $chosen['location']['name'], 'locationCode' => $chosen['location']['code'],
]);
acquisitionsOpenOrdersAssert($contract['identifierExport']['sourceColumn'] === 'PO Line UUID', 'Configure the existing one-column UUID export for PO line IDs.');

$parameters = $report->getDecodedParameters();
$parameterNames = array_column($parameters, 'name');
acquisitionsOpenOrdersAssert($parameterNames === ['acqUnit', 'workflowStatuses', 'orderTypes', 'lineView', 'titleSearch', 'poLineNumberSearch', 'startDate', 'endDate', 'receiptStatuses', 'paymentStatuses', 'materialTypes', 'expenseClasses', 'orderFormat', 'reEncumber'], 'Declare report parameters in ticket order.');
acquisitionsOpenOrdersAssert(strpos($report->parameters, 'orders.acquisitions_unit__t') !== false, 'Load acquisitions-unit choices from FOLIO.');
acquisitionsOpenOrdersAssert($parameters[1]['default'] === 'Pending,Open,Closed', 'Default PO status to every workflow status.');
acquisitionsOpenOrdersAssert($parameters[2]['default'] === 'One-Time,Ongoing', 'Default order type to every PO type.');
acquisitionsOpenOrdersAssert($parameters[3]['default'] === 'Not yet received or not yet paid', 'Default line view to the previous inclusion rule.');
acquisitionsOpenOrdersAssert($parameters[6]['default'] === '' && $parameters[7]['default'] === '', 'Leave both report dates optional and blank by default.');
acquisitionsOpenOrdersAssert($parameters[8]['default'] === 'Pending,Awaiting Receipt,Partially Received,Fully Received,Receipt Not Required,Ongoing,Cancelled', 'Default receipt statuses to every stored value.');
acquisitionsOpenOrdersAssert($parameters[9]['default'] === 'Pending,Awaiting Payment,Partially Paid,Fully Paid,Ongoing,Payment Not Required,Cancelled', 'Default payment statuses to every stored value.');
acquisitionsOpenOrdersAssert($parameters[10]['default'] === '' && $parameters[11]['default'] === '', 'Default dynamic material and expense selections to all options.');

acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['acqUnit' => 'UNKNOWN']); }, 'acqUnit', 'Reject units outside the FOLIO unit whitelist.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['startDate' => '2026-02-30']); }, 'startDate', 'Reject invalid calendar dates.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['startDate' => '2026-07-02', 'endDate' => '2026-07-01']); }, 'endDate', 'Reject an end date earlier than the start date.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['orderFormat' => 'Unknown']); }, 'orderFormat', 'Reject order formats outside the fixed choices.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['reEncumber' => 'Maybe']); }, 'reEncumber', 'Reject re-encumber values outside Any, On, and Off.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['receiptStatuses' => 'Unknown']); }, 'receiptStatuses', 'Reject receipt statuses outside the fixed choices.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['paymentStatuses' => 'Pending,Pending']); }, 'paymentStatuses', 'Reject repeated payment-status values.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['workflowStatuses' => 'Deleted']); }, 'workflowStatuses', 'Reject unknown PO statuses.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['orderTypes' => 'Subscription']); }, 'orderTypes', 'Reject unknown PO order types.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['lineView' => 'Everything']); }, 'lineView', 'Reject unknown line views.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['receiptStatuses' => 'Receipt not required']); }, 'receiptStatuses', 'Reject status values with noncanonical spelling.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['materialTypes' => 'Unknown']); }, 'materialTypes', 'Reject material types outside the selected unit options.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['expenseClasses' => 'unknown-id']); }, 'expenseClasses', 'Reject expense classes outside the FOLIO options.');
acquisitionsOpenOrdersThrows(function () use ($compile) { $compile(['titleSearch' => ['A', 'B']]); }, 'titleSearch', 'Reject non-string title-search values.');

$originalDefinition = [];
foreach (['slug', 'name', 'description', 'help_text', 'category', 'data_source', 'created_by', 'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config'] as $key) {
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
