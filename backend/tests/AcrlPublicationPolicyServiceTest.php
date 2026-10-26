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

echo "PASS: ACRL publication only blocks undecided matched question parts\n";
