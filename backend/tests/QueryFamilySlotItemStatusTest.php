<?php

// Regression test: the library/location inventory listing path filters
// item_status with ILIKE on the raw value, so 'checked-out' (hyphen) never
// matches the stored 'Checked out' (space). The value must be normalized.

$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
if (!file_exists($slotServicePath)) {
    fwrite(STDERR, "QueryFamilySlotService is missing at {$slotServicePath}\n");
    exit(1);
}
require_once $slotServicePath;

use app\services\QueryFamilySlotService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$resolved = QueryFamilySlotService::resolveSlotMatch('item_status', 'Checked-Out', QueryFamilySlotService::DEFAULT_MATCH_POLICY);
assertSameValue('checked out', $resolved['value'], 'item_status must normalize hyphen/case to the spaced canonical form for ILIKE matching.');

$resolvedSpaced = QueryFamilySlotService::resolveSlotMatch('item_status', 'In Process', QueryFamilySlotService::DEFAULT_MATCH_POLICY);
assertSameValue('in process', $resolvedSpaced['value'], 'Already-spaced item_status must remain a clean lowercased value.');

fwrite(STDOUT, "QueryFamily slot item_status test passed\n");
