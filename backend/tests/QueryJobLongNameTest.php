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
    'FolioQueryController should not use an inline 255-character substring for query job names.'
);

assertQueryJobLongName(
    strpos($controllerSource, 'buildQueryJobMetadata($body, $source)') !== false
        && strpos($controllerSource, 'QueryJob::createJob($sql, $params, $source, $dataSource, $metadata)') !== false,
    'FolioQueryController should preserve long NL prompts in job metadata when submitting jobs.'
);

assertQueryJobLongName(
    strpos($controllerSource, 'normalizeQueryJobName(') !== false
        && strpos($controllerSource, 'QUERY_JOB_NAME_MAX_LENGTH') !== false,
    'FolioQueryController should normalize job display names to a schema-safe length.'
);

assertQueryJobLongName(
    strpos($controllerSource, '$job->name    = $this->normalizeQueryJobName($sq->name);') !== false,
    'actionDashboardRefresh should normalize saved-query refreshed job names before write.'
);

assertQueryJobLongName(
    strpos($controllerSource, '$job->name = $this->normalizeQueryJobName($name);') !== false,
    'actionRenameHistoryJob should normalize names before persisting history-renamed jobs.'
);

assertQueryJobLongName(
    strpos($controllerSource, 'getQueryJobOriginalPrompt(') !== false,
    'FolioQueryController should recover original NL prompts from job metadata for history follow-ups.'
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
