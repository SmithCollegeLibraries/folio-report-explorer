<?php

// Regression test: matchReference() matched a reference's `code` against the
// lowercased/normalized prompt with only a length>=3 guard, so a code like
// 'ART' or 'GEN' matched the ordinary word in "art history materials" and
// produced a spurious reference filter. Code matching must be case-sensitive
// against the raw prompt (codes are distinct tokens like 'ART'/'SC', not
// lowercase prose words).

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

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$match = new ReflectionMethod(ReferenceResolverService::class, 'matchReference');
$match->setAccessible(true);

$normalize = new ReflectionMethod(ReferenceResolverService::class, 'normalizeText');
$normalize->setAccessible(true);

$reference = [
    'source_table' => 'inventory.material_type__t',
    'name' => 'Art Reproduction',
    'code' => 'ART',
    'id' => '42',
    'metadata' => [],
];

// Ordinary lowercase word "art" must NOT trigger the uppercase 'ART' code.
$prosePrompt = 'show me art history materials';
$proseResult = $match->invoke(null, $normalize->invoke(null, $prosePrompt), $prosePrompt, $reference);
assertSameValue(null, $proseResult, 'Lowercase prose word "art" must not match the uppercase reference code "ART".');

// An explicit uppercase code token DOES match by code.
$codePrompt = 'list items with material type ART';
$codeResult = $match->invoke(null, $normalize->invoke(null, $codePrompt), $codePrompt, $reference);
assertSameValue('code', $codeResult['matched_by'] ?? null, 'An explicit uppercase code token must match by code.');

// Name matching is unaffected (case-insensitive on the name).
$namePrompt = 'show me art reproduction items';
$nameResult = $match->invoke(null, $normalize->invoke(null, $namePrompt), $namePrompt, $reference);
assertSameValue('name', $nameResult['matched_by'] ?? null, 'A prompt containing the reference name must still match by name.');

fwrite(STDOUT, "ReferenceResolver code match test passed\n");
