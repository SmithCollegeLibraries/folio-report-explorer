<?php

$webConfigPath = __DIR__ . '/../config/web.php';
$controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';
$servicePath = __DIR__ . '/../services/ReferenceCacheRefreshService.php';
$commandPath = __DIR__ . '/../commands/ReferenceCacheController.php';

foreach ([$webConfigPath, $controllerPath, $commandPath] as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$webConfig = (string)file_get_contents($webConfigPath);
$controller = (string)file_get_contents($controllerPath);
$command = (string)file_get_contents($commandPath);
$service = file_exists($servicePath) ? (string)file_get_contents($servicePath) : '';

function assertReferenceRefreshContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

assertReferenceRefreshContains(
    "'POST reference-cache/refresh' => 'folio-query/reference-cache-refresh'",
    $webConfig,
    'Reference cache single-table refresh endpoint should be routed.'
);
assertReferenceRefreshContains(
    'function actionReferenceCacheRefresh()',
    $controller,
    'Reference cache single-table refresh action should exist.'
);
assertReferenceRefreshContains(
    'ReferenceCacheRefreshService',
    $controller,
    'Reference cache refresh action should use the shared refresh service.'
);
assertReferenceRefreshContains(
    'class ReferenceCacheRefreshService',
    $service,
    'Shared reference cache refresh service should exist.'
);
assertReferenceRefreshContains(
    'function refreshTableBySourceTable',
    $service,
    'Shared refresh service should refresh one enabled table by source table.'
);
assertReferenceRefreshContains(
    'ReferenceCacheRefreshService',
    $command,
    'Console refresh command should use the shared refresh service.'
);

fwrite(STDOUT, "Reference cache refresh endpoint test passed\n");
