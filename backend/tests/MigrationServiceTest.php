<?php

$servicePath = __DIR__ . '/../services/MigrationService.php';
$migrationDir = __DIR__ . '/../../mysql/migrations';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "MigrationService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\MigrationService;

function assertMigrationTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertMigrationSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$schemaSql = MigrationService::schemaMigrationsTableSql();
assertMigrationTrue(strpos($schemaSql, 'CREATE TABLE IF NOT EXISTS schema_migrations') !== false, 'Migration runner should own schema_migrations table creation.');
assertMigrationTrue(strpos($schemaSql, 'checksum') !== false, 'schema_migrations should store migration checksums.');
assertMigrationTrue(strpos($schemaSql, 'filename') !== false, 'schema_migrations should store migration filenames.');

$repoAudit = MigrationService::auditDirectory($migrationDir);
assertMigrationSame([], $repoAudit['duplicateNumbers'], 'Checked-in migrations should not have duplicate numeric prefixes.');

$tempDir = sys_get_temp_dir() . '/migration-service-test-' . uniqid('', true);
mkdir($tempDir, 0775, true);
file_put_contents($tempDir . '/001_create_table.sql', 'CREATE TABLE sample (id INT);');
file_put_contents($tempDir . '/002_add_name.sql', 'ALTER TABLE sample ADD COLUMN name VARCHAR(255);');
file_put_contents($tempDir . '/002_add_code.sql', 'ALTER TABLE sample ADD COLUMN code VARCHAR(20);');

$audit = MigrationService::auditDirectory($tempDir);
assertMigrationSame(['002' => ['002_add_code.sql', '002_add_name.sql']], $audit['duplicateNumbers'], 'Audit should detect duplicate migration numbers.');
assertMigrationTrue(count($audit['nonIdempotentRisks']) >= 2, 'Audit should flag non-idempotent CREATE/ALTER statements.');
assertMigrationSame(['001_create_table.sql', '002_add_code.sql', '002_add_name.sql'], $audit['unapplied'], 'Audit should report unapplied migrations when no ledger rows are supplied.');

$checksumAudit = MigrationService::auditDirectory($tempDir, [
    ['filename' => '001_create_table.sql', 'checksum' => str_repeat('0', 64)],
]);
assertMigrationSame(1, count($checksumAudit['changedChecksums']), 'Audit should detect changed checksums for applied migrations.');
assertMigrationSame('001_create_table.sql', $checksumAudit['changedChecksums'][0]['filename'] ?? null, 'Changed checksum report should identify the changed migration filename.');

$files = MigrationService::discoverMigrationFiles($tempDir);
assertMigrationSame(['001_create_table.sql', '002_add_code.sql', '002_add_name.sql'], array_map(function ($file) {
    return $file['filename'];
}, $files), 'Migration discovery should sort deterministically by filename.');
assertMigrationTrue(strlen($files[0]['checksum']) === 64, 'Migration discovery should compute SHA-256 checksums.');

@unlink($tempDir . '/001_create_table.sql');
@unlink($tempDir . '/002_add_name.sql');
@unlink($tempDir . '/002_add_code.sql');
@rmdir($tempDir);

fwrite(STDOUT, "MigrationService test passed\n");
