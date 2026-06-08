<?php

// Regression test: refreshTable() deactivated all rows for a source_table
// (is_active=0) and then re-inserted row-by-row with NO transaction. A
// concurrent resolver query (WHERE is_active=1) sees an empty/partial window,
// and a failure mid-reinsert leaves the cache wiped. The deactivate + reinsert
// must run inside a single DB transaction (commit on success, rollBack on
// failure) so the swap is atomic.
//
// This service performs cross-connection DB work that cannot be exercised
// without a live MySQL + FOLIO Postgres; following the repo convention for the
// reference-cache services (see ReferenceCacheRefreshEndpointTest), this asserts
// the transactional structure in the source.

$servicePath = __DIR__ . '/../services/ReferenceCacheRefreshService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "ReferenceCacheRefreshService is missing at {$servicePath}\n");
    exit(1);
}
$source = (string)file_get_contents($servicePath);

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

function assertOrder(string $first, string $second, string $haystack, string $message): void
{
    $a = strpos($haystack, $first);
    $b = strpos($haystack, $second);
    if ($a === false || $b === false || $a >= $b) {
        fwrite(STDERR, $message . "\nExpected '{$first}' to appear before '{$second}'.\n");
        exit(1);
    }
}

assertContainsText('beginTransaction()', $source, 'Refresh must open a DB transaction so the deactivate+reinsert swap is atomic.');
assertContainsText('->commit()', $source, 'Refresh must commit the transaction on success.');
assertContainsText('->rollBack()', $source, 'Refresh must roll back the transaction on failure to avoid wiping the cache.');

// The deactivation (is_active => 0) must happen INSIDE the transaction, i.e.
// after beginTransaction(), so readers never see a zero-active window.
assertOrder('beginTransaction()', "'is_active' => 0", $source, 'The is_active=0 deactivation must be inside the transaction.');
assertOrder("'is_active' => 0", '->commit()', $source, 'The reinsert + commit must follow the deactivation within the transaction.');

fwrite(STDOUT, "ReferenceCacheRefresh atomicity test passed\n");
