<?php

$initPath = __DIR__ . '/../../mysql/init.sql';
$migrationPath = __DIR__ . '/../../mysql/migrations/032_folio_reference_cache.sql';
$controllerPath = __DIR__ . '/../commands/ReferenceCacheController.php';
$resolverPath = __DIR__ . '/../services/ReferenceResolverService.php';

foreach ([$initPath, $migrationPath, $controllerPath, $resolverPath] as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$initSql = (string)file_get_contents($initPath);
$migrationSql = (string)file_get_contents($migrationPath);
$controller = (string)file_get_contents($controllerPath);
$resolver = (string)file_get_contents($resolverPath);

function assertContainsReferenceText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

foreach ([$initSql, $migrationSql] as $sql) {
    assertContainsReferenceText('folio_reference_tables', $sql, 'Reference table registry should be defined.');
    assertContainsReferenceText('folio_reference_values', $sql, 'Reference values table should be defined.');
    assertContainsReferenceText('folio_reference_aliases', $sql, 'Reference aliases table should be defined.');
    assertContainsReferenceText('folio_reference_refresh_log', $sql, 'Reference refresh log table should be defined.');
    assertContainsReferenceText('source_table', $sql, 'Reference records should track source FOLIO table names.');
    assertContainsReferenceText('normalized_name', $sql, 'Reference values should support normalized name lookup.');
    assertContainsReferenceText('alias_scope', $sql, 'Aliases should support user/org/global scopes.');
    assertContainsReferenceText('clarification_batch_id', $sql, 'Clarification events should support batched clarification ids.');
    assertContainsReferenceText('promotion_status', $sql, 'Clarification events should track alias promotion status.');
}

assertContainsReferenceText('class ReferenceCacheController', $controller, 'Reference cache console controller should exist.');
assertContainsReferenceText('actionDiscoverCandidates', $controller, 'Reference cache discovery command should exist.');
assertContainsReferenceText('actionRefresh', $controller, 'Reference cache refresh command should exist.');
assertContainsReferenceText('actionStatus', $controller, 'Reference cache status command should exist.');

assertContainsReferenceText('class ReferenceResolverService', $resolver, 'Reference resolver service should exist.');

fwrite(STDOUT, "Reference cache schema test passed\n");
