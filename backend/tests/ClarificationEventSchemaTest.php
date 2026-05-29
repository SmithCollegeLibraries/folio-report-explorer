<?php

$initPath = __DIR__ . '/../../mysql/init.sql';
$migrationPath = __DIR__ . '/../../mysql/migrations/030_ai_clarification_events.sql';
$webConfigPath = __DIR__ . '/../config/web.php';
$controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';

if (!file_exists($initPath)) {
    fwrite(STDERR, "Missing mysql/init.sql\n");
    exit(1);
}

if (!file_exists($migrationPath)) {
    fwrite(STDERR, "Missing clarification event migration at {$migrationPath}\n");
    exit(1);
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
    assertContainsText('ai_clarification_events', $sql, 'Clarification event table should be defined in init and migration SQL.');
    assertContainsText('clarification_key', $sql, 'Clarification event table should track stable clarification keys.');
    assertContainsText('options_json', $sql, 'Clarification event table should retain presented options.');
    assertContainsText('selected_option_ids_json', $sql, 'Clarification event table should retain selected recommendations.');
    assertContainsText('free_text_response', $sql, 'Clarification event table should retain free-text responses.');
    assertContainsText('resolved_filter_json', $sql, 'Clarification event table should retain resolved filters.');
    assertContainsText('promoted_hint_id', $sql, 'Clarification event table should link promoted learning hints.');
}

assertContainsText("'POST clarifications/resolve' => 'folio-query/clarification-resolve'", $webConfig, 'Clarification resolve endpoint should be routed.');
assertContainsText('function actionClarificationResolve()', $controller, 'Clarification resolve action should exist.');
assertContainsText('ai_clarification_events', $controller, 'Clarification resolve action should persist events.');

fwrite(STDOUT, "Clarification event schema test passed\n");
