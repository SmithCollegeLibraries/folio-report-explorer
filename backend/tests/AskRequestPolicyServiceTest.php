<?php

$servicePath = __DIR__ . '/../services/AskRequestPolicyService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "Missing AskRequestPolicyService at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\AskRequestPolicyService;

function askPolicyAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message . "\nExpected: " . var_export($expected, true)
                . "\nActual: " . var_export($actual, true) . "\n"
        );
        exit(1);
    }
}

$cases = [
    ['Delete every row from query_jobs.', 'request_blocked'],
    ['Please update the inventory item records.', 'request_blocked'],
    ['Can you insert a new vendor into the database?', 'request_blocked'],
    ['Drop the users table.', 'request_blocked'],
    ['Update me on circulation trends.', 'read_only'],
    ['Count records updated last month.', 'read_only'],
    ['Show deleted inventory records.', 'read_only'],
    ['Include the purchase-order create date.', 'read_only'],
    ['Summarize update activity by month.', 'read_only'],
    ['Create a report of receipts by vendor.', 'read_only'],
    ['Create a database table for vendors.', 'request_blocked'],
    ['I need you to delete all rows from query_jobs.', 'read_only'],
];

foreach ($cases as [$question, $expected]) {
    $actual = AskRequestPolicyService::classify($question);
    askPolicyAssertSame($expected, $actual['state'] ?? null, $question);
}

fwrite(STDOUT, "AskRequestPolicyService test passed\n");
