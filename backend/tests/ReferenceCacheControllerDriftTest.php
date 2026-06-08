<?php

$controllerPath = __DIR__ . '/../commands/ReferenceCacheController.php';
$servicePath = __DIR__ . '/../services/ReferenceCacheRefreshService.php';

if (!file_exists($controllerPath)) {
    fwrite(STDERR, "ReferenceCacheController is missing at {$controllerPath}\n");
    exit(1);
}

$controller = (string)file_get_contents($controllerPath);
$refreshImplementation = $controller . (file_exists($servicePath) ? (string)file_get_contents($servicePath) : '');

function assertReferenceControllerContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

assertReferenceControllerContains(
    'loadExistingColumns',
    $refreshImplementation,
    'Refresh should inspect live table columns before building SELECT SQL.'
);
assertReferenceControllerContains(
    'filterExistingColumns',
    $refreshImplementation,
    'Refresh should filter optional configured columns that are missing in the live backend.'
);
assertReferenceControllerContains(
    'Skipping configured optional columns',
    $refreshImplementation,
    'Refresh should tell operators when optional configured columns are absent.'
);
assertReferenceControllerContains(
    'actionReviewCandidates',
    $controller,
    'Reference cache should expose a compact candidate review command.'
);
assertReferenceControllerContains(
    'inferRefreshMapping',
    $refreshImplementation,
    'Refresh should infer mappings for reviewed cache candidates outside the hard-coded allowlist.'
);
assertReferenceControllerContains(
    "['name', 'label', 'value', 'display_name', 'description']",
    $refreshImplementation,
    'Inferred refresh mappings should prefer stable human-readable label columns.'
);
assertReferenceControllerContains(
    "['code', 'key', 'slug']",
    $refreshImplementation,
    'Inferred refresh mappings should use only stable short code columns when present.'
);
assertReferenceControllerContains(
    'No safe refresh mapping could be inferred',
    $refreshImplementation,
    'Refresh should fail explicitly when a reviewed table has no safe label column.'
);

fwrite(STDOUT, "Reference cache controller drift test passed\n");
