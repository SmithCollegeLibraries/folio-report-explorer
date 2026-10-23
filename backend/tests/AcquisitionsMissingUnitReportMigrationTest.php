<?php
require_once __DIR__ . '/support/AcquisitionsMissingUnitReportFixture.php';

$seed = acquisitionsMissingUnitReportSeed();
require_once __DIR__ . '/../services/AcquisitionsMissingUnitReportMigrationService.php';
require_once __DIR__ . '/../services/MigrationService.php';

function acquisitionsMissingUnitMigrationFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

final class AcquisitionsMissingUnitMigrationLookupDb
{
    public $definition;

    public function __construct(array $definition)
    {
        $this->definition = $definition;
    }

    public function createCommand(string $sql, array $params = [])
    {
        if (strpos($sql, 'FROM report_templates WHERE slug = :slug') === false
            || ($params[':slug'] ?? null) !== 'orders-missing-acquisitions-unit') {
            throw new RuntimeException('Unexpected missing-acquisitions-unit migration lookup.');
        }

        return new class($this) {
            private $db;

            public function __construct($db)
            {
                $this->db = $db;
            }

            public function queryOne(): array
            {
                return $this->db->definition;
            }
        };
    }
}

$recognize = new ReflectionMethod(app\services\MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80100) {
    $recognize->setAccessible(true);
}
$migrationServiceSource = (string) file_get_contents(__DIR__ . '/../services/MigrationService.php');
if (!preg_match('/function databaseAppearsCurrent.*?ordersMissingAcqUnitReportAppearsComplete/s', $migrationServiceSource)) {
    acquisitionsMissingUnitMigrationFail('Current database baselining must require the canonical RPT-004 report.');
}
if (strpos($migrationServiceSource, "case '050_orders_missing_acquisitions_unit.sql': return CatalogingReportMigrationRecognitionService::ordersMissingAcqUnitReportAppearsComplete(\$db);") === false) {
    acquisitionsMissingUnitMigrationFail('Migration 050 must use the shared cataloging report recognition service.');
}
$reportRecognition = new ReflectionMethod(app\services\CatalogingReportMigrationRecognitionService::class, 'ordersMissingAcqUnitReportAppearsComplete');
$db = new AcquisitionsMissingUnitMigrationLookupDb($seed);
if (!$reportRecognition->invoke(null, $db)) {
    acquisitionsMissingUnitMigrationFail('The shared cataloging report recognition service must recognize the canonical RPT-004 report.');
}
$db = new AcquisitionsMissingUnitMigrationLookupDb($seed);

if (!$recognize->invoke(null, $db, '050_orders_missing_acquisitions_unit.sql')) {
    acquisitionsMissingUnitMigrationFail('Migration 050 must recognize the canonical report definition.');
}

if ($recognize->invoke(null, $db, '051_unrelated_migration.sql')) {
    acquisitionsMissingUnitMigrationFail('The report seed must not recognize an unrelated migration.');
}

$db->definition['sql_template'] = 'SELECT 1';
if ($recognize->invoke(null, $db, '050_orders_missing_acquisitions_unit.sql')) {
    acquisitionsMissingUnitMigrationFail('Migration 050 must reject SQL drift.');
}

$db->definition = $seed;
$db->definition['help_text'] .= ' Changed';
if ($recognize->invoke(null, $db, '050_orders_missing_acquisitions_unit.sql')) {
    acquisitionsMissingUnitMigrationFail('Migration 050 must reject help-text drift.');
}

echo "PASS: Missing-acquisitions-unit migration recognition and drift tests\n";
