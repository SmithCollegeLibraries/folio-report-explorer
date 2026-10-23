<?php
require_once __DIR__ . '/support/AcquisitionsOpenOrdersReportFixture.php';
require_once __DIR__ . '/support/AcquisitionsMissingUnitReportFixture.php';
require_once __DIR__ . '/../services/MigrationService.php';

function acquisitionsFeedbackMigrationAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class AcquisitionsFeedbackMigrationDb
{
    public $definitions;
    public $polActive;

    public function __construct(array $definitions, int $polActive)
    {
        $this->definitions = $definitions;
        $this->polActive = $polActive;
    }

    public function createCommand(string $sql, array $params = [])
    {
        return new class($this, $sql, $params) {
            private $db;
            private $sql;
            private $params;

            public function __construct($db, string $sql, array $params)
            {
                $this->db = $db;
                $this->sql = $sql;
                $this->params = $params;
            }

            public function queryOne()
            {
                if (strpos($this->sql, 'WHERE slug = :slug') !== false) {
                    return $this->db->definitions[$this->params[':slug']] ?? false;
                }
                if (strpos($this->sql, 'WHERE id = 11') !== false) {
                    return ['is_active' => $this->db->polActive];
                }
                throw new RuntimeException('Unexpected RPT-010 migration recognition query.');
            }
        };
    }
}

$openOrders = acquisitionsOpenOrdersReportFixture();
$openDefinition = [];
foreach (['slug', 'name', 'description', 'help_text', 'category', 'data_source', 'created_by', 'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config'] as $key) {
    $openDefinition[$key] = $openOrders->$key;
}
$missingDefinition = acquisitionsMissingUnitReportSeed();
$db = new AcquisitionsFeedbackMigrationDb([
    'quarterly-open-orders' => $openDefinition,
    'orders-missing-acquisitions-unit' => $missingDefinition,
], 0);

$recognize = new ReflectionMethod(app\services\MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80100) {
    $recognize->setAccessible(true);
}
acquisitionsFeedbackMigrationAssert(
    $recognize->invoke(null, $db, '053_acquisitions_feedback_report_filters.sql'),
    'Recognize migration 053 when both report definitions and the retired POL state are canonical.'
);

$db->polActive = 1;
acquisitionsFeedbackMigrationAssert(
    !$recognize->invoke(null, $db, '053_acquisitions_feedback_report_filters.sql'),
    'Reject migration 053 recognition when the POL report is still active.'
);
$db->polActive = 0;
$db->definitions['quarterly-open-orders']['sql_template'] = 'SELECT 1';
acquisitionsFeedbackMigrationAssert(
    !$recognize->invoke(null, $db, '053_acquisitions_feedback_report_filters.sql'),
    'Reject migration 053 recognition when the open-order definition has drifted.'
);
$db->definitions['quarterly-open-orders'] = $openDefinition;
$db->definitions['orders-missing-acquisitions-unit']['help_text'] .= ' Changed';
acquisitionsFeedbackMigrationAssert(
    !$recognize->invoke(null, $db, '053_acquisitions_feedback_report_filters.sql'),
    'Reject migration 053 recognition when the missing-unit definition has drifted.'
);

$recognitionSource = (string) file_get_contents(__DIR__ . '/../services/CatalogingReportMigrationRecognitionService.php');
acquisitionsFeedbackMigrationAssert(
    strpos($recognitionSource, 'acquisitionsFeedbackReportsAppearComplete($db)') !== false,
    'Current-database recognition must include migration 053 report state.'
);
$migration = (string) file_get_contents(__DIR__ . '/../../mysql/migrations/053_acquisitions_feedback_report_filters.sql');
acquisitionsFeedbackMigrationAssert(
    strpos($migration, "UPDATE report_templates SET is_active = 0 WHERE id = 11 AND slug = 'pol-report'") !== false,
    'Migration 053 must deactivate the existing POL report row by ID and slug.'
);
acquisitionsFeedbackMigrationAssert(
    strpos(strtoupper($migration), 'DELETE FROM REPORT_TEMPLATES') === false,
    'Migration 053 must retain the POL report row.'
);

echo "PASS: Acquisitions feedback migration recognition and drift checks\n";
