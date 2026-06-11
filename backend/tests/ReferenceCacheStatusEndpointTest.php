<?php

$webConfigPath = __DIR__ . '/../config/web.php';
$controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';

foreach ([$webConfigPath, $controllerPath] as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$webConfig = (string)file_get_contents($webConfigPath);
$controller = (string)file_get_contents($controllerPath);

function assertReferenceStatusContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

assertReferenceStatusContains(
    "'GET reference-cache/status' => 'folio-query/reference-cache-status'",
    $webConfig,
    'Reference cache status endpoint should be routed.'
);
assertReferenceStatusContains(
    'function actionReferenceCacheStatus()',
    $controller,
    'Reference cache status action should exist.'
);
assertReferenceStatusContains(
    'folio_reference_tables',
    $controller,
    'Reference cache status action should read reference table registry.'
);
assertReferenceStatusContains(
    'folio_reference_values',
    $controller,
    'Reference cache status action should report local reference row count.'
);
assertReferenceStatusContains(
    'ReferenceJsonBundleService::bundleStatus()',
    $controller,
    'Reference cache status action should report JSON bundle freshness.'
);
assertReferenceStatusContains(
    "'jsonBundle' =>",
    $controller,
    'Reference cache status response should include JSON bundle status.'
);
assertReferenceStatusContains(
    "'approvedTableCount' => count(ReferenceJsonBundleService::approvedTables())",
    $controller,
    'Reference cache status response should expose the approved JSON table count.'
);

fwrite(STDOUT, "Reference cache status endpoint test passed\n");
