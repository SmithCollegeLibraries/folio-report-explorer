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
    strpos($controllerSource, 'use app\\services\\QueryMemoryService;') !== false,
    'FolioQueryController should import QueryMemoryService.'
);

assertReuseEndpoint(
    strpos($controllerSource, 'PreviousSuccessfulQueryReuseService::findStrongMatches(') !== false
        && strpos($controllerSource, 'QueryMemoryService::findDirectReuse(') !== false,
    'actionQueryReuseCandidate should shape candidates and delegate trust to QueryMemoryService.'
);

assertReuseEndpoint(
    strpos($controllerSource, 'QueryMemoryService::currentDirectReuseSchemaFingerprint(') !== false
        && strpos($controllerSource, 'QueryMemoryService::scopeFingerprint(') !== false,
    'Reuse compatibility fingerprints must be computed from current server-owned context.'
);

assertReuseEndpoint(
    strpos($controllerSource, 'GeminiService::validateTableReferences(') !== false
        && strpos($controllerSource, 'estimateQueryComplexity(') !== false,
    'A trusted candidate must repeat live schema validation and database preflight.'
);

assertReuseEndpoint(
    strpos($controllerSource, "'sourceGenerationId'") !== false
        && strpos($controllerSource, "'reuseTrust'") !== false,
    'Accepted reuse must return immutable server-owned lineage and trust.'
);

echo "FolioQueryControllerReuseCandidateEndpointTest passed\n";
