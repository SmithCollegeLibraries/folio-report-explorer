<?php

$bundlePath = __DIR__ . '/../services/ReferenceJsonBundleService.php';
$normalizerPath = __DIR__ . '/../services/ReferenceTextNormalizerService.php';
$resolverPath = __DIR__ . '/../services/ReferenceResolverService.php';
$generatedBundlePath = __DIR__ . '/../data/reference_cache.json';

foreach ([
    'ReferenceJsonBundleService' => $bundlePath,
    'ReferenceTextNormalizerService' => $normalizerPath,
    'ReferenceResolverService' => $resolverPath,
    'Generated reference_cache.json' => $generatedBundlePath,
] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "{$label} is missing at {$path}\n");
        exit(1);
    }
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            if ($alias === '@app/data/reference_cache.json') {
                return __DIR__ . '/../data/reference_cache.json';
            }
            return $alias;
        }

        public static function info($message, $category = null) {}
        public static function warning($message, $category = null) {}
    }
}

Yii::$app = (object) ['params' => []];

require_once $bundlePath;
require_once $normalizerPath;
require_once $resolverPath;

use app\services\ReferenceResolverService;

function assertGeneratedJsonTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertGeneratedJsonContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: {$needle}\nActual:\n{$haystack}\n");
        exit(1);
    }
}

function assertGeneratedJsonNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected: {$needle}\nActual:\n{$haystack}\n");
        exit(1);
    }
}

function assertGeneratedJsonSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$resolution = ReferenceResolverService::resolvePrompt('Show me all of the items in josten treasure and treasure folio.');
$guidance = implode("\n", $resolution['guidanceLines'] ?? []);
assertGeneratedJsonTrue(empty($resolution['needsClarification']), 'Generated JSON should confidently resolve Josten Treasure and Treasure Folio.');
assertGeneratedJsonContains("inventory.location__t.name = 'SC Josten Treasure'", $guidance, 'Generated JSON should resolve Josten Treasure as inventory.location__t.');
assertGeneratedJsonContains("inventory.location__t.name = 'SC Josten Treasure Folio'", $guidance, 'Generated JSON should resolve Treasure Folio as inventory.location__t.');

$libraryResolution = ReferenceResolverService::resolvePrompt('Show me items in SC Josten Library.');
$libraryGuidance = implode("\n", $libraryResolution['guidanceLines'] ?? []);
assertGeneratedJsonContains("inventory.loclibrary__t.name = 'SC Josten Library'", $libraryGuidance, 'Generated JSON should resolve SC Josten Library as inventory.loclibrary__t.');

$codeResolution = ReferenceResolverService::resolvePrompt('Show me items in location code SJTR.');
$codeGuidance = implode("\n", $codeResolution['guidanceLines'] ?? []);
assertGeneratedJsonContains("inventory.location__t.name = 'SC Josten Treasure'", $codeGuidance, 'Generated JSON should resolve SJTR as inventory.location__t.');

$videoResolution = ReferenceResolverService::resolvePrompt(
    'Find all of the video formats at Hillyer library. This can be VHS or DVD.'
);
$videoGuidance = implode("\n", $videoResolution['guidanceLines'] ?? []);
assertGeneratedJsonTrue(empty($videoResolution['needsClarification']), 'The reported prompt must resolve from the active generated JSON bundle.');
assertGeneratedJsonContains("inventory.loclibrary__t.name = 'SC Hillyer Art Library'", $videoGuidance, 'Hillyer must resolve as a library.');
assertGeneratedJsonContains("'Videocassette'", $videoGuidance, 'VHS must resolve through the material-type cache.');
assertGeneratedJsonContains("'DVD/Blu-ray'", $videoGuidance, 'DVD must resolve through the material-type cache.');
assertGeneratedJsonNotContains('HC DVD', $videoGuidance, 'Material vocabulary must not drift to the Hampshire location.');

$videoFilters = [];
foreach (($videoResolution['resolvedFilters'] ?? []) as $filter) {
    $videoFilters[$filter['dimension'] ?? ''] = $filter;
}
assertGeneratedJsonSame(
    'inventory.loclibrary__t',
    $videoFilters['library']['source_table'] ?? null,
    'The structured Hillyer filter must retain the canonical library table.'
);
assertGeneratedJsonSame(
    ['SC Hillyer Art Library'],
    $videoFilters['library']['values'] ?? null,
    'The structured Hillyer filter must retain the exact canonical library value.'
);
assertGeneratedJsonSame(
    'Smith College',
    $videoFilters['library']['value_metadata']['SC Hillyer Art Library']['campus_name'] ?? null,
    'The structured Hillyer filter must retain Smith College campus context.'
);
assertGeneratedJsonSame(
    'inventory.material_type__t',
    $videoFilters['material_type']['source_table'] ?? null,
    'The structured video filter must retain the canonical material-type table.'
);
assertGeneratedJsonSame(
    ['Videocassette', 'DVD/Blu-ray'],
    $videoFilters['material_type']['values'] ?? null,
    'The structured video filter must retain exact canonical material values in selector order.'
);
assertGeneratedJsonSame(
    ['vhs', 'dvd'],
    $videoFilters['material_type']['vocabulary_terms'] ?? null,
    'The structured video filter must retain the explicit vocabulary terms.'
);
assertGeneratedJsonTrue(
    !in_array('HC DVD', $videoFilters['material_type']['values'] ?? [], true),
    'The structured material filter must exclude the HC DVD location.'
);

fwrite(STDOUT, "ReferenceResolver generated JSON test passed\n");
