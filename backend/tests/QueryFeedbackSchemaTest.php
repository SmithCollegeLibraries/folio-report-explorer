<?php

$initPath = __DIR__ . '/../../mysql/init.sql';
$migrationPath = __DIR__ . '/../../mysql/migrations/033_ai_query_feedback.sql';
$webConfigPath = __DIR__ . '/../config/web.php';
$controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';

foreach ([
    'mysql/init.sql' => $initPath,
    'feedback migration' => $migrationPath,
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

assertContainsText("'POST query-feedback' => 'folio-query/query-feedback'", $webConfig, 'Query feedback endpoint should be routed.');
assertContainsText('function actionQueryFeedback()', $controller, 'Query feedback action should exist.');
assertContainsText('ai_query_feedback', $controller, 'Query feedback action should persist feedback rows.');
assertContainsText('prompt_fingerprint', $controller, 'Query feedback action should compute prompt fingerprints server-side.');
assertContainsText('sql_hash', $controller, 'Query feedback action should compute SQL hashes server-side.');

fwrite(STDOUT, "Query feedback schema test passed\n");
