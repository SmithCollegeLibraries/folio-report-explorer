<?php
require_once __DIR__ . '/support/HoldingslessReportFixture.php';
require_once __DIR__ . '/../services/MigrationService.php';
$report = holdingslessReportFixture();
$definition = [];
foreach (['slug','name','category','data_source','created_by','default_limit','is_active','sql_template','parameters','execution_config'] as $key) { $definition[$key] = $report->$key; }
$db = new class($definition) {
    public $definition;
    public function __construct($definition) { $this->definition = $definition; }
    public function createCommand($sql, $params = []) { return new class($this) {
        private $db;
        public function __construct($db) { $this->db = $db; }
        public function queryOne() { return $this->db->definition; }
    }; }
};
$recognize = new ReflectionMethod(app\services\MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80500) { $recognize->setAccessible(true); }
if (!$recognize->invoke(null, $db, '045_cataloging_instances_without_holdings.sql')) {
    fwrite(STDERR, "FAIL: Migration 045 must recognize its installed report.\n"); exit(1);
}
if (!$recognize->invoke(null, $db, '046_cataloging_holdingsless_marker_patterns.sql')) {
    fwrite(STDERR, "FAIL: Migration 046 must recognize its installed marker-pattern report.\n"); exit(1);
}
if (!$recognize->invoke(null, $db, '047_cataloging_holdingsless_exclusion_match.sql')) {
    fwrite(STDERR, "FAIL: Migration 047 must recognize its installed exclusion-rule report.\n"); exit(1);
}
$db->definition['sql_template'] = 'SELECT 1';
if ($recognize->invoke(null, $db, '045_cataloging_instances_without_holdings.sql')) {
    fwrite(STDERR, "FAIL: Migration 045 must reject report drift.\n"); exit(1);
}
if ($recognize->invoke(null, $db, '046_cataloging_holdingsless_marker_patterns.sql')) {
    fwrite(STDERR, "FAIL: Migration 046 must reject report drift.\n"); exit(1);
}
if ($recognize->invoke(null, $db, '047_cataloging_holdingsless_exclusion_match.sql')) {
    fwrite(STDERR, "FAIL: Migration 047 must reject report drift.\n"); exit(1);
}
$source = file_get_contents(__DIR__ . '/../services/MigrationService.php');
if (!preg_match('/function databaseAppearsCurrent.*?holdingslessReportAppearsComplete/s', $source)) {
    fwrite(STDERR, "FAIL: A database without the new report must not baseline migration 045.\n"); exit(1);
}
echo "Holdings-less migration recognition tests passed\n";
