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
foreach (['slug', 'name', 'description', 'help_text', 'category', 'data_source', 'created_by', 'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config'] as $key) {
    $definition[$key] = $report->$key;
}
$db = new class($definition) {
    public $definition;
    public $sql;
    public function __construct(array $definition) { $this->definition = $definition; }
    public function createCommand($sql, $params = []) { $this->sql = $sql; return new class($this) {
        private $db;
        public function __construct($db) { $this->db = $db; }
        public function queryOne() { return $this->db->definition; }
    }; }
};

$recognize = new ReflectionMethod(app\services\MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80500) { $recognize->setAccessible(true); }
acquisitionsMigrationAssert($recognize->invoke(null, $db, '048_quarterly_open_orders_report.sql'), 'Recognize migration 048 when its canonical report is installed.');
acquisitionsMigrationAssert($recognize->invoke(null, $db, '056_open_order_report_expansion.sql'), 'Recognize migration 056 when its canonical report is installed.');
acquisitionsMigrationAssert(
    app\services\CatalogingReportMigrationRecognitionService::acquisitionsOpenOrdersReportAppearsComplete($db),
    'Recognize the canonical report through the shared migration helper.'
);
acquisitionsMigrationAssert(
    strpos($db->sql, 'SELECT slug, name, description, help_text, category') !== false,
    'The shared migration lookup must fetch description and help text for canonical report checks.'
);

$db->definition['sql_template'] = 'SELECT 1';
acquisitionsMigrationAssert(!$recognize->invoke(null, $db, '048_quarterly_open_orders_report.sql'), 'Reject an installed report whose SQL has drifted.');
acquisitionsMigrationAssert(
    !app\services\CatalogingReportMigrationRecognitionService::acquisitionsOpenOrdersReportAppearsComplete($db),
    'The shared migration helper must reject report drift.'
);

$files = app\services\MigrationService::discoverMigrationFiles();
$last = end($files);
acquisitionsMigrationAssert($last['filename'] === '057_acrl_calculated_answer_source.sql', 'Migration 057 is the newest migration.');

echo "Acquisitions open-orders migration recognition checks passed\n";
