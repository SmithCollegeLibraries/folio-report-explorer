<?php

$initPath = __DIR__ . '/../../mysql/init.sql';
$migrationPath = __DIR__ . '/../../mysql/migrations/033_ai_query_feedback.sql';
$reuseTrustMigrationPath = __DIR__ . '/../../mysql/migrations/044_query_feedback_reuse_trust.sql';
$webConfigPath = __DIR__ . '/../config/web.php';
$controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';

foreach ([
    'mysql/init.sql' => $initPath,
    'feedback migration' => $migrationPath,
    'query reuse trust migration' => $reuseTrustMigrationPath,
    'backend/config/web.php' => $webConfigPath,
    'FolioQueryController' => $controllerPath,
] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Missing {$label} at {$path}\n");
        exit(1);
    }
}

$initSql = (string)file_get_contents($initPath);
$migrationSql = (string)file_get_contents($migrationPath);
$reuseTrustMigrationSql = (string)file_get_contents($reuseTrustMigrationPath);
$webConfig = (string)file_get_contents($webConfigPath);
$controller = (string)file_get_contents($controllerPath);

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

foreach ([$initSql, $migrationSql] as $sql) {
    assertContainsText('ai_query_feedback', $sql, 'Query feedback table should be defined in init and migration SQL.');
    assertContainsText('prompt_fingerprint', $sql, 'Feedback should retain the stable prompt fingerprint.');
    assertContainsText('sql_hash', $sql, 'Feedback should retain the generated SQL hash.');
    assertContainsText('route', $sql, 'Feedback should retain the generation route.');
    assertContainsText('route_reason', $sql, 'Feedback should retain the route reason.');
    assertContainsText('result_accuracy', $sql, 'Feedback should track whether users found the result accurate.');
    assertContainsText('feedback_note', $sql, 'Feedback should retain optional user notes.');
}

$feedbackColumns = [
    'generation_id', 'query_job_id', 'generation_provenance',
    'direct_reuse_schema_fingerprint', 'schema_version_fingerprint',
    'scope_fingerprint', 'reuse_suppressed',
    'admin_reuse_approved_at', 'admin_reuse_approved_by',
    'replacement_generation_id',
];
$generationColumns = [
    'saved_count', 'downloaded_count', 'rerun_count', 'follow_up_count',
];
foreach ([$initSql, $reuseTrustMigrationSql] as $sql) {
    foreach ($feedbackColumns as $column) {
        assertContainsText($column, $sql, "Query feedback trust storage must include {$column}.");
    }
    foreach ($generationColumns as $column) {
        assertContainsText($column, $sql, "Generation memory storage must include {$column}.");
    }
    foreach ([
        'idx_feedback_prompt_source_accuracy',
        'idx_feedback_generation',
        'idx_feedback_query_job',
        'idx_feedback_direct_schema',
        'idx_feedback_schema_version',
        'idx_feedback_scope',
        'idx_feedback_suppressed',
        'fk_query_feedback_generation',
        'fk_query_feedback_job',
        'fk_query_feedback_approver',
        'fk_query_feedback_replacement',
    ] as $schemaObject) {
        assertContainsText($schemaObject, $sql, "Query feedback trust storage must define {$schemaObject}.");
    }
}

foreach ([
    'generation_id',
    'query_job_id',
    'generation_provenance',
    'direct_reuse_schema_fingerprint',
    'schema_version_fingerprint',
    'scope_fingerprint',
    'admin_reuse_approved_at',
    'admin_reuse_approved_by',
    'replacement_generation_id',
] as $nullableTrustColumn) {
    if (preg_match('/\b' . preg_quote($nullableTrustColumn, '/') . '\b[^;\n]*\bNULL\b/i', $reuseTrustMigrationSql) !== 1) {
        fwrite(STDERR, "Legacy feedback trust field {$nullableTrustColumn} must remain nullable.\n");
        exit(1);
    }
}
if (preg_match('/\breuse_suppressed\b[^,;\n]*DEFAULT\s+0\b/i', $reuseTrustMigrationSql) !== 1) {
    fwrite(STDERR, "Legacy feedback must default to nonsuppressed neutral trust.\n");
    exit(1);
}

assertContainsText("'POST query-feedback' => 'folio-query/query-feedback'", $webConfig, 'Query feedback endpoint should be routed.');
assertContainsText('function actionQueryFeedback()', $controller, 'Query feedback action should exist.');
assertContainsText('ai_query_feedback', $controller, 'Query feedback action should persist feedback rows.');
assertContainsText('prompt_fingerprint', $controller, 'Query feedback action should compute prompt fingerprints server-side.');
assertContainsText('sql_hash', $controller, 'Query feedback action should compute SQL hashes server-side.');

fwrite(STDOUT, "Query feedback schema test passed\n");
