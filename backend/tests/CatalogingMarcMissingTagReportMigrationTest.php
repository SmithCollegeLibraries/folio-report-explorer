<?php

function assertCatalogingMigrationContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message}\nMissing: {$needle}\n");
        exit(1);
    }
}

function assertCatalogingMigrationNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$message}\nForbidden: {$needle}\n");
        exit(1);
    }
}

function assertCatalogingMigrationSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$migration = file_get_contents(__DIR__ . '/../../mysql/migrations/040_cataloging_marc_missing_tag_report.sql');
$init = file_get_contents(__DIR__ . '/../../mysql/init.sql');

assertCatalogingMigrationContains("'cataloging'", $migration, 'Migration must add the Cataloging enum value.');
assertCatalogingMigrationContains('execution_config JSON NULL', $migration, 'Migration must add reusable execution metadata.');
assertCatalogingMigrationContains("'marc-bibliographic-records-missing-tag'", $migration, 'Migration must seed the fixed report.');
assertCatalogingMigrationSame(1, substr_count($migration, '{{location_from}}'), 'Seed SQL must contain one location token.');
assertCatalogingMigrationSame(1, substr_count($migration, '{{marc_table}}'), 'Seed SQL must contain one MARC-table token.');
assertCatalogingMigrationContains('marc_tag.instance_id = target_instances.instance_uuid', $migration, 'Presence must join UUID to UUID.');
assertCatalogingMigrationNotContains('instance_hrid = target_instances', $migration, 'Presence must not join on HRID.');
assertCatalogingMigrationNotContains('folio_source_record.marctab', $migration, 'Seed must not use the combined MARC view.');
assertCatalogingMigrationContains('LIMIT 100001', $migration, 'Sentinel must be static and reviewable.');
assertCatalogingMigrationContains('A missing tag is a factual finding', $migration, 'Help must not label every result an error.');
assertCatalogingMigrationContains('Export FOLIO UUID list', $migration, 'Help must document the batch-export workflow.');
assertCatalogingMigrationContains("ENUM('acquisitions', 'circulation', 'inventory', 'finance', 'users', 'cataloging', 'other')", $init, 'Fresh installs must accept Cataloging.');
assertCatalogingMigrationContains('execution_config JSON NULL', $init, 'Fresh installs must include execution metadata.');

fwrite(STDOUT, "Cataloging MARC missing-tag migration contract test passed\n");
