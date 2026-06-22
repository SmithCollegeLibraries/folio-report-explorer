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
    fwrite(STDERR, "Failed to read query reuse telemetry files\n");
    exit(1);
}

function assertReuseTelemetry($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assertReuseTelemetry(
    strpos($webConfigSource, "'POST query/reuse-decision' => 'folio-query/query-reuse-decision'") !== false,
    'web.php should expose POST /api/query/reuse-decision for bypass/review telemetry.'
);

assertReuseTelemetry(
    strpos($controllerSource, 'public function actionQueryReuseDecision()') !== false,
    'FolioQueryController should define actionQueryReuseDecision.'
);

assertReuseTelemetry(
    substr_count($controllerSource, "'event' => 'nl2sql.query_reuse'") >= 1,
    'Query reuse decisions should emit nl2sql.query_reuse telemetry.'
);

assertReuseTelemetry(
    strpos($controllerSource, '$metadata[\'queryReuse\']') !== false,
    'Submitted reused SQL should be preserved in query job metadata.'
);

assertReuseTelemetry(
    strpos($controllerSource, "'decision' => 'accepted'") !== false
        && strpos($controllerSource, "'edited' =>") !== false
        && strpos($controllerSource, "'candidateJobId' =>") !== false,
    'Query job metadata should record accepted reuse candidate id and edited state.'
);

echo "QueryReuseOutcomeTelemetryTest passed\n";
