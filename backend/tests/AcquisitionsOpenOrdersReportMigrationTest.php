<?php
require_once __DIR__ . '/support/AcquisitionsOpenOrdersReportFixture.php';
require_once __DIR__ . '/../services/MigrationService.php';

function acquisitionsMigrationAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$report = acquisitionsOpenOrdersReportFixture();
$definition = [];
foreach (['slug', 'name', 'category', 'data_source', 'created_by', 'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config'] as $key) {
    $definition[$key] = $report->$key;
}
$db = new class($definition) {
    public $definition;
    public function __construct(array $definition) { $this->definition = $definition; }
    public function createCommand($sql, $params = []) { return new class($this) {
        private $db;
        public function __construct($db) { $this->db = $db; }
        public function queryOne() { return $this->db->definition; }
    }; }
};

$recognize = new ReflectionMethod(app\services\MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80500) { $recognize->setAccessible(true); }
acquisitionsMigrationAssert($recognize->invoke(null, $db, '048_quarterly_open_orders_report.sql'), 'Recognize migration 048 when its canonical report is installed.');
acquisitionsMigrationAssert(
    app\services\CatalogingReportMigrationRecognitionService::acquisitionsOpenOrdersReportAppearsComplete($db),
    'Recognize the canonical report through the shared migration helper.'
);

$db->definition['sql_template'] = 'SELECT 1';
acquisitionsMigrationAssert(!$recognize->invoke(null, $db, '048_quarterly_open_orders_report.sql'), 'Reject an installed report whose SQL has drifted.');
acquisitionsMigrationAssert(
    !app\services\CatalogingReportMigrationRecognitionService::acquisitionsOpenOrdersReportAppearsComplete($db),
    'The shared migration helper must reject report drift.'
);

$files = app\services\MigrationService::discoverMigrationFiles();
$last = end($files);
acquisitionsMigrationAssert($last['filename'] === '050_orders_missing_acquisitions_unit.sql', 'Migration 050 is the newest migration.');

echo "Acquisitions open-orders migration recognition checks passed\n";
