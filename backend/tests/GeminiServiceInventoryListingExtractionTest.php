<?php

// Regression test for prompt slot extraction used by the inventory listing
// family. The unquoted material-type pattern lacked scope-word terminators
// (at/in/for/from), so "material type book at Smith College" over-captured
// 'book at smith college' into an unvalidated material_type slot, which then
// drove a subquery matching no row -> silent zero results.
// Also: item-status extraction returned the raw value (e.g. 'checked-out')
// while the validator normalized it, so the hyphenated value never matched the
// stored 'Checked out'.

$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';
foreach (['QueryFamilyContractService' => $contractServicePath, 'GeminiService' => $geminiServicePath] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "{$label} is missing at {$path}\n");
        exit(1);
    }
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
        public static function getAlias($alias) { return $alias; }
        public static function info($m, $c = null) {}
        public static function warning($m, $c = null) {}
    }
}
Yii::$app = (object) ['params' => []];

require_once $contractServicePath;
require_once $geminiServicePath;

use app\services\GeminiService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$materialType = new ReflectionMethod(GeminiService::class, 'extractInventoryListingMaterialTypeFromPrompt');
$materialType->setAccessible(true);
$itemStatus = new ReflectionMethod(GeminiService::class, 'extractInventoryListingItemStatusFromPrompt');
$itemStatus->setAccessible(true);

// Quoted values are unaffected.
assertSameValue('e-book', $materialType->invoke(null, 'List items with material type "e-book". Include title.'), 'Quoted material type must be extracted verbatim.');

// Unquoted value must stop at a scope preposition, not swallow campus/location text.
assertSameValue('book', $materialType->invoke(null, 'List items with material type of book at Smith College. Include title.'), 'Unquoted material type must stop at "at" and not capture trailing campus text.');
assertSameValue('book', $materialType->invoke(null, 'list items with material type of book in Neilson Library'), 'Unquoted material type must stop at "in".');

// Existing terminators still work.
assertSameValue('book', $materialType->invoke(null, 'material type of book and item status in process'), 'Material type must still stop at "and".');

// Item status is normalized so hyphen/case variants match the stored value.
assertSameValue('checked out', $itemStatus->invoke(null, 'items with item status "checked-out"'), 'Hyphenated item status must normalize to the canonical spaced form.');
assertSameValue('in process', $itemStatus->invoke(null, 'item status of "In Process"'), 'Item status must be lowercased/normalized.');

fwrite(STDOUT, "GeminiService inventory listing extraction test passed\n");
