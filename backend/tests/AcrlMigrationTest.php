<?php

require_once __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;

function acrlMigrationAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$migrationDir = __DIR__ . '/../../mysql/migrations';
$migrationPath = $migrationDir . '/054_acrl_question_catalogue.sql';
acrlMigrationAssert(file_exists($migrationPath), 'Migration 054 must create the ACRL catalogue schema and historical seed.');

$migration = (string)file_get_contents($migrationPath);
$upper = strtoupper($migration);
foreach (['ACRL_SURVEY_YEARS', 'ACRL_QUESTIONS', 'ACRL_QUESTION_LINKS'] as $table) {
    acrlMigrationAssert(strpos($upper, 'CREATE TABLE IF NOT EXISTS ' . $table) !== false, $table . ' must be created idempotently.');
}
acrlMigrationAssert(strpos($upper, 'INSERT INTO ACRL_QUESTIONS') !== false, 'Question catalogue rows must be seeded by migration 054.');
acrlMigrationAssert(strpos($upper, 'INSERT INTO ACRL_QUESTION_LINKS') !== false, 'Reviewed historical links must be seeded by migration 054.');
acrlMigrationAssert(strpos($upper, 'FROM DUAL WHERE NOT EXISTS') !== false, 'Seed inserts must use guarded MariaDB/MySQL-compatible inserts.');
acrlMigrationAssert(strpos($upper, 'JSON_TABLE') === false, 'Migration 054 must not depend on MySQL 8-only JSON_TABLE.');
acrlMigrationAssert(strpos($upper, 'ADD COLUMN IF NOT EXISTS') === false, 'Migration 054 must remain compatible with MySQL 8 and MariaDB 10.3.');
acrlMigrationAssert(strpos($upper, 'INSERT IGNORE') === false, 'Seed rows should use explicit unique-key guards.');

$audit = MigrationService::auditDirectory($migrationDir);
acrlMigrationAssert(empty($audit['duplicateNumbers']), 'Migration 054 must have the reserved unique migration number.');
$migrationRisks = [];
foreach ($audit['nonIdempotentRisks'] as $risk) {
    if ($risk['filename'] === '054_acrl_question_catalogue.sql') {
        $migrationRisks = $risk['reasons'];
    }
}
acrlMigrationAssert(empty($migrationRisks), 'Migration 054 must not contain audit-detected non-idempotent operations.');

echo "PASS: ACRL migration 054 is guarded and migration-audit clean\n";
