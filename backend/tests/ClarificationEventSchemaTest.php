<?php

$initPath = __DIR__ . '/../../mysql/init.sql';
$migrationPath = __DIR__ . '/../../mysql/migrations/032_ai_clarification_events.sql';
$batchMigrationPath = __DIR__ . '/../../mysql/migrations/034_folio_reference_cache.sql';
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

if (!file_exists($batchMigrationPath)) {
    fwrite(STDERR, "Missing batch clarification migration at {$batchMigrationPath}\n");
    exit(1);
}

$initSql = (string)file_get_contents($initPath);
$migrationSql = (string)file_get_contents($migrationPath) . "\n" . (string)file_get_contents($batchMigrationPath);
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
    assertContainsText('clarification_batch_id', $sql, 'Clarification event table should group batched clarification rows.');
    assertContainsText('term', $sql, 'Clarification event table should track the local term being clarified.');
    assertContainsText('selected_source_table', $sql, 'Clarification event table should retain selected source table.');
    assertContainsText('selected_source_id', $sql, 'Clarification event table should retain selected source id.');
    assertContainsText('selected_value', $sql, 'Clarification event table should retain selected resolved value.');
    assertContainsText('confidence', $sql, 'Clarification event table should retain resolver confidence.');
    assertContainsText('promotion_status', $sql, 'Clarification event table should track promotion off-ramp status.');
    assertContainsText('promoted_hint_id', $sql, 'Clarification event table should link promoted learning hints.');
}

assertContainsText('CREATE PROCEDURE fre_add_clarification_reference_columns', $migrationSql, 'Batch clarification migration should safely patch clarification columns when prior migrations are missing or partially applied.');
assertContainsText('information_schema.COLUMNS', $migrationSql, 'Batch clarification migration should check for existing columns before adding them.');
assertContainsText('information_schema.STATISTICS', $migrationSql, 'Batch clarification migration should check for existing indexes before adding them.');

assertContainsText("'POST clarifications/resolve' => 'folio-query/clarification-resolve'", $webConfig, 'Clarification resolve endpoint should be routed.');
assertContainsText('function actionClarificationResolve()', $controller, 'Clarification resolve action should exist.');
assertContainsText('ai_clarification_events', $controller, 'Clarification resolve action should persist events.');
assertContainsText('clarificationBatchId', $controller, 'Clarification resolve action should accept batch ids.');
assertContainsText("'items'", $controller, 'Clarification resolve action should accept batched clarification items.');

fwrite(STDOUT, "Clarification event schema test passed\n");
