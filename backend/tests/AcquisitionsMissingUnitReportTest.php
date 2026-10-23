<?php
require_once __DIR__ . '/support/AcquisitionsMissingUnitReportFixture.php';
require_once __DIR__ . '/support/AcquisitionsOpenOrdersReportFixture.php';
require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';
require_once __DIR__ . '/../services/ReportExecutionContractService.php';
require_once __DIR__ . '/../services/SqlBuilderService.php';
require_once __DIR__ . '/../services/FolioSchemaService.php';

use app\models\ReportTemplate;
use app\services\CatalogingReportCompilerService;
use app\services\ReportExecutionContractService;
use app\services\SqlBuilderService;

function acquisitionsMissingUnitAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acquisitionsMissingUnitThrows(callable $callback, string $field, string $message): void
{
    try {
        $callback();
    } catch (\app\exceptions\ReportParameterValidationException $exception) {
        acquisitionsMissingUnitAssert(isset($exception->getFieldErrors()[$field]), $message);
        return;
    }
    acquisitionsMissingUnitAssert(false, $message);
}

final class AcquisitionsMissingUnitCompilerDb
{
    public $missingTable = null;
    public $queries = [];

    public function createCommand(string $sql, array $params = [])
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        return new class($this, $params) {
            private $db;
            private $params;

            public function __construct($db, array $params)
            {
                $this->db = $db;
                $this->params = $params;
            }

            public function queryOne(): array
            {
                $row = [];
                foreach ($this->params as $key => $table) {
                    preg_match('/\A:requiredTable(\d+)\z/', $key, $matches);
                    $row['required_table_' . ($matches[1] ?? '')] = $table === $this->db->missingTable ? null : $table;
                }
                return $row;
            }
        };
    }
}

$probe = new ReportTemplate();
$probe->slug = 'orders-missing-acquisitions-unit';
acquisitionsMissingUnitAssert(CatalogingReportCompilerService::supports($probe), 'The governed compiler supports the missing-acquisitions-unit report.');

$seed = acquisitionsMissingUnitReportSeed();
$report = new ReportTemplate();
foreach ($seed as $key => $value) {
    $report->$key = $value;
}
$db = new AcquisitionsMissingUnitCompilerDb();
$compile = static function (array $overrides = []) use ($report, $db): array {
    return CatalogingReportCompilerService::build($report, $overrides, $db);
};

$defaults = $compile();
acquisitionsMissingUnitAssert($defaults['params'][':createdStartDate'] === null, 'Leave the created-date start optional by default.');
acquisitionsMissingUnitAssert($defaults['params'][':createdEndDate'] === null, 'Leave the created-date end optional by default.');
acquisitionsMissingUnitAssert(strpos($defaults['sql'], '/*__') === false, 'Compile default status filters before execution.');
acquisitionsMissingUnitAssert(strpos($defaults['sql'], 'workflow_status IN (') === false, 'All workflow statuses preserve the previous result set.');
acquisitionsMissingUnitAssert(strpos($defaults['sql'], 'order_type IN (') === false, 'All order types preserve the previous result set.');
acquisitionsMissingUnitAssert(count($db->queries) === 1, 'Probe the required reporting tables in one lookup.');
ReportExecutionContractService::assertStaticExportSql($defaults['sql'], ['fetchRowLimit' => 100001]);
SqlBuilderService::validateSafety($defaults['sql']);
SqlBuilderService::validateTablePolicy($defaults['sql']);

$filtered = $compile([
    'createdStartDate' => '2026-10-01', 'createdEndDate' => '2026-10-31',
    'workflowStatuses' => 'Pending', 'orderTypes' => 'One-Time',
]);
acquisitionsMissingUnitAssert($filtered['params'] === [
    ':createdStartDate' => '2026-10-01', ':createdEndDate' => '2026-10-31',
    ':workflowStatus0' => 'Pending', ':orderType0' => 'One-Time',
], 'Bind optional dates and selected status/type values.');
acquisitionsMissingUnitAssert(strpos($filtered['sql'], 'workflow_status IN (:workflowStatus0)') !== false, 'Apply the selected workflow status as a bound filter.');
acquisitionsMissingUnitAssert(strpos($filtered['sql'], 'order_type IN (:orderType0)') !== false, 'Apply the selected order type as a bound filter.');
acquisitionsMissingUnitAssert(strpos($filtered['sql'], '2026-10-01') === false, 'Do not interpolate the selected created-date bounds.');
ReportExecutionContractService::assertStaticExportSql($filtered['sql'], ['fetchRowLimit' => 100001]);
SqlBuilderService::validateSafety($filtered['sql']);
SqlBuilderService::validateTablePolicy($filtered['sql']);

acquisitionsMissingUnitThrows(function () use ($compile) { $compile(['createdStartDate' => '2026-02-30']); }, 'createdStartDate', 'Reject invalid created-date values.');
acquisitionsMissingUnitThrows(function () use ($compile) { $compile(['createdStartDate' => '2026-11-01', 'createdEndDate' => '2026-10-31']); }, 'createdEndDate', 'Reject a start date after the end date.');
acquisitionsMissingUnitThrows(function () use ($compile) { $compile(['workflowStatuses' => 'Approved']); }, 'workflowStatuses', 'Reject workflow statuses outside the fixed choices.');
acquisitionsMissingUnitThrows(function () use ($compile) { $compile(['orderTypes' => 'Standing Order']); }, 'orderTypes', 'Reject order types outside One-Time and Ongoing.');

$db->missingTable = 'organizations.organizations__t';
try {
    $compile();
    acquisitionsMissingUnitAssert(false, 'Reject the report when a required FOLIO table is missing.');
} catch (\InvalidArgumentException $exception) {
    acquisitionsMissingUnitAssert(strpos($exception->getMessage(), 'organizations.organizations__t') !== false, 'Name the missing required table.');
}

echo "PASS: Missing-acquisitions-unit compiler and filter validation checks\n";
