<?php

$modelPath = __DIR__ . '/../models/QueryJob.php';
$controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';
$initSqlPath = __DIR__ . '/../../mysql/init.sql';
$migrationPath = __DIR__ . '/../../mysql/migrations/029_query_job_name_text.sql';

foreach ([$modelPath, $controllerPath, $initSqlPath] as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Required file is missing at {$path}\n");
        exit(1);
    }
}

$modelSource = file_get_contents($modelPath);
$controllerSource = file_get_contents($controllerPath);
$initSql = file_get_contents($initSqlPath);

if ($modelSource === false || $controllerSource === false || $initSql === false) {
    fwrite(STDERR, "Failed to read query job schema files\n");
    exit(1);
}

function assertQueryJobLongName(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assertQueryJobLongName(
    strpos($modelSource, "[['name'], 'string', 'max' => 255]") === false,
    'QueryJob model should not cap name/title validation at 255 characters.'
);

assertQueryJobLongName(
    strpos($controllerSource, 'substr(trim($body[\'name\']), 0, 255)') === false,
    'FolioQueryController should not truncate query job names to 255 characters before saving.'
);

assertQueryJobLongName(
    preg_match('/name\s+TEXT\s+NULL/i', $initSql) === 1,
    'mysql/init.sql should bootstrap query_jobs.name as TEXT NULL.'
);

assertQueryJobLongName(
    file_exists($migrationPath),
    'Existing installs need migration 029_query_job_name_text.sql.'
);

$migrationSql = file_get_contents($migrationPath);
assertQueryJobLongName(
    $migrationSql !== false && preg_match('/MODIFY\s+COLUMN\s+name\s+TEXT\s+NULL/i', $migrationSql) === 1,
    'Migration 029 should convert query_jobs.name to TEXT NULL.'
);

fwrite(STDOUT, "QueryJob long name test passed\n");