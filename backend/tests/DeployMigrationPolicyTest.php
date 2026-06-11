<?php

$deployPath = __DIR__ . '/../../deploy.sh';

if (!file_exists($deployPath)) {
    fwrite(STDERR, "deploy.sh is missing at {$deployPath}\n");
    exit(1);
}

$deploy = (string)file_get_contents($deployPath);

function assertDeployPolicyContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

function assertDeployPolicyNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nForbidden text: {$needle}\n");
        exit(1);
    }
}

assertDeployPolicyContains('set -e', $deploy, 'Deploy should run with set -e behavior.');
assertDeployPolicyContains('php backend/yii migration/audit', $deploy, 'Deploy should audit migrations before applying them.');
assertDeployPolicyContains('php backend/yii migration/run', $deploy, 'Deploy should use the ledger-backed migration runner.');
assertDeployPolicyNotContains('for migration in mysql/migrations/*.sql', $deploy, 'Deploy should not apply migrations with an untracked shell loop.');
assertDeployPolicyNotContains('$MYSQL_CMD < "$migration" 2>/dev/null || true', $deploy, 'Deploy should not mask migration failures.');

fwrite(STDOUT, "Deploy migration policy test passed\n");
