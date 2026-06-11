<?php

$servicePath = __DIR__ . '/../services/ReferenceJsonBundleService.php';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "ReferenceJsonBundleService is missing at {$servicePath}\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            if ($alias === '@app/data/reference_cache.json') {
                return __DIR__ . '/../data/reference_cache.json';
            }
            return $alias;
        }
    }
}

Yii::$app = (object) ['params' => []];

require_once $servicePath;

use app\services\ReferenceJsonBundleService;

function assertReferenceJsonTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertReferenceJsonSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$approved = ReferenceJsonBundleService::approvedTables();

foreach ([
    'inventory.location__t',
    'inventory.loclibrary__t',
    'inventory.loccampus__t',
    'inventory.locinstitution__t',
    'inventory.service_point__t',
    'inventory.material_type__t',
    'finance.fund__t',
    'orders.acquisitions_unit__t',
    'invoice.batch_groups__t',
    'circulation.loan_policy__t',
    'courses.coursereserves_terms__t',
    'feesfines.waives__t',
] as $table) {
    assertReferenceJsonTrue(in_array($table, $approved, true), "{$table} should be in the approved JSON reference table list.");
    assertReferenceJsonTrue(ReferenceJsonBundleService::isApprovedTable($table), "{$table} should be approved by lookup.");
}

foreach ([
    'inventory.item__t',
    'inventory.instance__t',
    'inventory.holdings_record__t',
] as $table) {
    assertReferenceJsonTrue(!in_array($table, $approved, true), "{$table} must never be in the approved JSON reference table list.");
    assertReferenceJsonTrue(!ReferenceJsonBundleService::isApprovedTable($table), "{$table} must be rejected by lookup.");
    assertReferenceJsonTrue(in_array($table, ReferenceJsonBundleService::excludedTables(), true), "{$table} should be listed as a hard exclusion.");
}

assertReferenceJsonSame('josten treasure folio', ReferenceJsonBundleService::normalizeText('SC Josten Treasure Folio', true), 'Normalization should be able to strip campus prefixes for matching.');

$freshBundle = sys_get_temp_dir() . '/reference-cache-fresh-test.json';
$staleBundle = sys_get_temp_dir() . '/reference-cache-stale-test.json';
$missingBundle = sys_get_temp_dir() . '/reference-cache-missing-test.json';
@unlink($missingBundle);

file_put_contents($freshBundle, json_encode([
    'generated_at' => date('c'),
    'approved_tables' => ReferenceJsonBundleService::approvedTables(),
    'excluded_tables' => ReferenceJsonBundleService::excludedTables(),
    'tables' => [
        'inventory.location__t' => [
            ['id' => 'loc-1', 'name' => 'SC Josten Treasure'],
        ],
    ],
]));

file_put_contents($staleBundle, json_encode([
    'generated_at' => '2020-01-01T00:00:00+00:00',
    'approved_tables' => ReferenceJsonBundleService::approvedTables(),
    'excluded_tables' => ReferenceJsonBundleService::excludedTables(),
    'tables' => [
        'inventory.location__t' => [
            ['id' => 'loc-1', 'name' => 'SC Josten Treasure'],
        ],
    ],
]));

$freshStatus = ReferenceJsonBundleService::bundleStatus($freshBundle);
assertReferenceJsonSame(true, $freshStatus['usable'] ?? null, 'Fresh reference bundle should be usable.');
assertReferenceJsonSame(false, $freshStatus['stale'] ?? null, 'Fresh reference bundle should not be stale.');

$staleStatus = ReferenceJsonBundleService::bundleStatus($staleBundle);
assertReferenceJsonSame(true, $staleStatus['usable'] ?? null, 'Stale reference bundle should still be readable.');
assertReferenceJsonSame(true, $staleStatus['stale'] ?? null, 'Old reference bundle should be marked stale.');

$missingStatus = ReferenceJsonBundleService::bundleStatus($missingBundle);
assertReferenceJsonSame(false, $missingStatus['usable'] ?? null, 'Missing reference bundle should not be usable.');
assertReferenceJsonSame('missing', $missingStatus['status'] ?? null, 'Missing reference bundle should report a missing status.');

@unlink($freshBundle);
@unlink($staleBundle);

fwrite(STDOUT, "ReferenceJsonBundleService test passed\n");
