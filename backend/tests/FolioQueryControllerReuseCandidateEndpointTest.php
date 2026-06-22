<?php

$controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';
$webConfigPath = __DIR__ . '/../config/web.php';

foreach ([$controllerPath, $webConfigPath] as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Required file is missing at {$path}\n");
        exit(1);
    }
}

$controllerSource = file_get_contents($controllerPath);
$webConfigSource = file_get_contents($webConfigPath);

if ($controllerSource === false || $webConfigSource === false) {
    fwrite(STDERR, "Failed to read controller reuse candidate files\n");
    exit(1);
}

function assertReuseEndpoint($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assertReuseEndpoint(
    strpos($webConfigSource, "'POST query/reuse-candidate' => 'folio-query/query-reuse-candidate'") !== false,
    'web.php should expose POST /api/query/reuse-candidate.'
);

assertReuseEndpoint(
    strpos($controllerSource, 'use app\\services\\PreviousSuccessfulQueryReuseService;') !== false,
    'FolioQueryController should import PreviousSuccessfulQueryReuseService.'
);

assertReuseEndpoint(
    strpos($controllerSource, 'public function actionQueryReuseCandidate()') !== false,
    'FolioQueryController should define actionQueryReuseCandidate.'
);

assertReuseEndpoint(
    strpos($controllerSource, 'PreviousSuccessfulQueryReuseService::findStrongMatch(') !== false,
    'actionQueryReuseCandidate should delegate matching to PreviousSuccessfulQueryReuseService.'
);

assertReuseEndpoint(
    strpos($controllerSource, 'SqlBuilderService::validateSafety($match[\'sql\'])') !== false
        && strpos($controllerSource, 'SqlBuilderService::validateTablePolicy($match[\'sql\'])') !== false,
    'actionQueryReuseCandidate should revalidate suggested SQL before returning it.'
);

echo "FolioQueryControllerReuseCandidateEndpointTest passed\n";
