<?php

$servicePath = __DIR__ . '/../services/AcrlPublicationPolicyService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "FAIL: ACRL publication policy service is missing.\n");
    exit(1);
}

require_once $servicePath;

use app\services\AcrlPublicationPolicyService;

function acrlPublicationAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$policy = new AcrlPublicationPolicyService();
$pending = $policy->evaluate([
    ['status' => 'unchanged', 'decision' => null],
    ['status' => 'reworded', 'decision' => 'approved'],
    ['status' => 'renumbered', 'decision' => 'rejected'],
    ['status' => 'new', 'decision' => null],
    ['status' => 'removed', 'decision' => null],
]);
acrlPublicationAssert($pending['can_publish'] === false, 'A year cannot publish while a matched part has no decision.');
acrlPublicationAssert($pending['unresolved_count'] === 1, 'New and removed items do not require link decisions.');

$complete = $policy->evaluate([
    ['status' => 'unchanged', 'decision' => 'approved'],
    ['status' => 'reworded', 'decision' => 'rejected'],
    ['status' => 'new', 'decision' => null],
    ['status' => 'removed', 'decision' => null],
]);
acrlPublicationAssert($complete['can_publish'] === true, 'Approved or rejected matches count as reviewed decisions.');
acrlPublicationAssert($complete['unresolved_count'] === 0, 'A complete review has no unresolved links.');

$topicalOnly = $policy->evaluate([
    ['status' => 'unchanged', 'decision' => null, 'topical' => true],
]);
acrlPublicationAssert($topicalOnly['can_publish'] === true, 'An undecided matched Trends part does not block publication.');
acrlPublicationAssert($topicalOnly['unresolved_count'] === 0, 'Undecided matched Trends parts are not unresolved.');

$surveyOnly = $policy->evaluate([
    ['status' => 'unchanged', 'decision' => null, 'topical' => false],
]);
acrlPublicationAssert($surveyOnly['can_publish'] === false, 'An undecided matched Survey part still blocks publication.');
acrlPublicationAssert($surveyOnly['unresolved_count'] === 1, 'An undecided matched Survey part remains unresolved.');

$mixed = $policy->evaluate([
    ['status' => 'reworded', 'decision' => null, 'topical' => true],
    ['status' => 'unchanged', 'decision' => null, 'topical' => false],
]);
acrlPublicationAssert($mixed['can_publish'] === false, 'A mixed review waits only for the undecided Survey part.');
acrlPublicationAssert($mixed['unresolved_count'] === 1, 'A mixed review counts only the unresolved Survey part.');

echo "PASS: ACRL publication only blocks undecided matched question parts\n";
