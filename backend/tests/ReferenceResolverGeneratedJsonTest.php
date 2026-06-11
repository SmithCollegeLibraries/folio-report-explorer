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

fwrite(STDOUT, "ReferenceResolver generated JSON test passed\n");
