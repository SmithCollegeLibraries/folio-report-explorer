<?php

// Regression test for the campus-code resolution used by the campus-scoped
// inventory item-filter listing. Previously an unmapped campus name fell back
// to strtoupper(substr(name,0,2)), emitting a phantom code (e.g. 'FO') that
// matched no loccampus row -> silent zero results with no error/clarification.

$compilerServicePath = __DIR__ . '/../services/QueryFamilyCompilerService.php';
if (!file_exists($compilerServicePath)) {
    fwrite(STDERR, "QueryFamilyCompilerService is missing at {$compilerServicePath}\n");
    exit(1);
}
require_once $compilerServicePath;

use app\services\QueryFamilyCompilerService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$campusCodeForName = new ReflectionMethod(QueryFamilyCompilerService::class, 'campusCodeForName');
$campusCodeForName->setAccessible(true);

// Known Five Colleges campuses resolve to their canonical acquisitions codes.
assertSameValue('SC', $campusCodeForName->invoke(null, 'Smith College'), 'Smith College must resolve to SC.');
assertSameValue('AC', $campusCodeForName->invoke(null, 'Amherst College'), 'Amherst College must resolve to AC.');
assertSameValue('MH', $campusCodeForName->invoke(null, 'Mount Holyoke College'), 'Mount Holyoke must resolve to MH.');
assertSameValue('RP', $campusCodeForName->invoke(null, 'Five Colleges'), 'Five Colleges (shared) must resolve to RP rather than a guessed 2-char code.');

// An unmapped campus must NOT silently produce a phantom 2-char code; it must
// raise so the caller surfaces a recovery/clarification instead of zero rows.
$threw = false;
try {
    $campusCodeForName->invoke(null, 'Nonexistent University');
} catch (\ReflectionException $e) {
    // Unwrap the real exception thrown inside the invoked method.
    $threw = $e->getPrevious() instanceof \InvalidArgumentException;
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
assertSameValue(true, $threw, 'An unmapped campus name must throw InvalidArgumentException instead of emitting a guessed camp.code.');

// item_status arriving from the model (not prompt extraction) must still be
// normalized to the canonical spaced/lowercased form so LOWER(status__name)
// comparisons match the stored value.
$buildCampusScoped = new ReflectionMethod(QueryFamilyCompilerService::class, 'buildCampusScopedItemFilterListingSql');
$buildCampusScoped->setAccessible(true);
$built = $buildCampusScoped->invoke(null, [
    'campus' => 'Smith College',
    'item_status' => 'Checked-Out',
    'requested_outputs' => ['title'],
]);
$paramValues = array_values($built['params'] ?? []);
$hasNormalizedStatus = in_array('checked out', $paramValues, true);
$hasRawStatus = in_array('checked-out', $paramValues, true) || in_array('Checked-Out', $paramValues, true);
assertSameValue(true, $hasNormalizedStatus, 'Campus-scoped item_status param must be normalized to "checked out".');
assertSameValue(false, $hasRawStatus, 'Campus-scoped item_status param must not retain the hyphenated/raw form.');

fwrite(STDOUT, "QueryFamily campus code test passed\n");
