<?php

// Regression test: buildReferenceGuidanceLine() appended "Do not apply this
// value to library or campus name columns" to EVERY resolved reference. When
// the match itself comes from a campus/library/location table that guidance is
// self-contradicting (it says use loccampus__t.name AND don't use campus name
// columns) and steers the model away from the correct column.

$servicePath = __DIR__ . '/../services/ReferenceResolverService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "ReferenceResolverService is missing at {$servicePath}\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
        public static function getAlias($alias) { return $alias; }
        public static function info($m, $c = null) {}
        public static function warning($m, $c = null) {}
        public static function error($m, $c = null) {}
    }
}
Yii::$app = (object) ['params' => []];

require_once $servicePath;

use app\services\ReferenceResolverService;

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: {$needle}\nLine: {$haystack}\n");
        exit(1);
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected: {$needle}\nLine: {$haystack}\n");
        exit(1);
    }
}

$build = new ReflectionMethod(ReferenceResolverService::class, 'buildReferenceGuidanceLine');
$build->setAccessible(true);

// Non-location reference (material type): keep the guard against applying the
// value to library/campus columns.
$materialLine = $build->invoke(null, [
    'source_table' => 'inventory.material_type__t',
    'name' => 'E-Book',
    'code' => '',
    'metadata' => [],
]);
assertContainsText('inventory.material_type__t.name', $materialLine, 'Material-type guidance should target the material_type name column.');
assertContainsText('Do not apply this value to library or campus name columns', $materialLine, 'Non-location references should keep the library/campus guard.');

// Campus reference: the guard contradicts itself; it must NOT be emitted.
$campusLine = $build->invoke(null, [
    'source_table' => 'inventory.loccampus__t',
    'name' => 'Smith College',
    'code' => 'SC',
    'metadata' => [],
]);
assertContainsText('inventory.loccampus__t.name', $campusLine, 'Campus guidance should target the loccampus name column.');
assertNotContainsText('Do not apply this value to library or campus name columns', $campusLine, 'A campus match must not carry the self-contradicting library/campus guard.');
assertNotContainsText('Do not add code filters', $campusLine, 'A campus match must not suppress code filters — camp.code is the canonical campus filter.');

// Library reference: same — no contradictory guard.
$libraryLine = $build->invoke(null, [
    'source_table' => 'inventory.loclibrary__t',
    'name' => 'SC Neilson Library',
    'code' => '',
    'metadata' => [],
]);
assertNotContainsText('Do not apply this value to library or campus name columns', $libraryLine, 'A library match must not carry the self-contradicting library/campus guard.');

fwrite(STDOUT, "ReferenceResolver guidance line test passed\n");
