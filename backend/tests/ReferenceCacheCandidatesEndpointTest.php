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

function assertReferenceCandidatesContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

assertReferenceCandidatesContains(
    "'GET reference-cache/candidates' => 'folio-query/reference-cache-candidates'",
    $webConfig,
    'Reference cache candidate review endpoint should be routed.'
);
assertReferenceCandidatesContains(
    "'POST reference-cache/candidates/review' => 'folio-query/reference-cache-candidate-review'",
    $webConfig,
    'Reference cache candidate review update endpoint should be routed.'
);
assertReferenceCandidatesContains(
    'function actionReferenceCacheCandidates()',
    $controller,
    'Reference cache candidate review action should exist.'
);
assertReferenceCandidatesContains(
    'function actionReferenceCacheCandidateReview()',
    $controller,
    'Reference cache candidate review update action should exist.'
);
assertReferenceCandidatesContains(
    "'enable' => [true, 'cacheable_reference']",
    $controller,
    'Candidate review update action should support enabling a candidate.'
);
assertReferenceCandidatesContains(
    "'reject' => [false, 'do_not_cache']",
    $controller,
    'Candidate review update action should support rejecting a candidate.'
);
assertReferenceCandidatesContains(
    'assertReferenceCandidateCanRefresh',
    $controller,
    'Candidate review update action should validate refreshability before enabling a candidate.'
);
assertReferenceCandidatesContains(
    'validateSourceTableCanRefresh',
    $controller,
    'Candidate review update action should delegate refreshability validation to ReferenceCacheRefreshService.'
);
if (strpos($controller, 'function splitSourceTableName') !== false) {
    fwrite(STDERR, "Candidate review update action should not duplicate source-table splitting logic.\n");
    exit(1);
}
assertReferenceCandidatesContains(
    "['name', 'label', 'value', 'display_name', 'description']",
    (string)file_get_contents(__DIR__ . '/../services/ReferenceCacheRefreshService.php'),
    'Candidate review update action should use the same safe label columns as refresh inference.'
);
assertReferenceCandidatesContains(
    'Cannot enable candidate because no safe refresh label column was found',
    (string)file_get_contents(__DIR__ . '/../services/ReferenceCacheRefreshService.php'),
    'Candidate review update action should reject candidates that cannot be safely refreshed.'
);
assertReferenceCandidatesContains(
    'summaryBySchema',
    $controller,
    'Candidate review response should include summaryBySchema.'
);
assertReferenceCandidatesContains(
    'candidates',
    $controller,
    'Candidate review response should include candidate examples.'
);

fwrite(STDOUT, "Reference cache candidates endpoint test passed\n");
