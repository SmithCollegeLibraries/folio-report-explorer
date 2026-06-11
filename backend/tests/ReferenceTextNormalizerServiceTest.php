<?php

$normalizerPath = __DIR__ . '/../services/ReferenceTextNormalizerService.php';
$bundlePath = __DIR__ . '/../services/ReferenceJsonBundleService.php';
$resolverPath = __DIR__ . '/../services/ReferenceResolverService.php';
$refreshPath = __DIR__ . '/../services/ReferenceCacheRefreshService.php';

foreach ([
    'ReferenceTextNormalizerService' => $normalizerPath,
    'ReferenceJsonBundleService' => $bundlePath,
    'ReferenceResolverService' => $resolverPath,
    'ReferenceCacheRefreshService' => $refreshPath,
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

require_once $normalizerPath;
require_once $bundlePath;
require_once $resolverPath;
require_once $refreshPath;

use app\services\ReferenceCacheRefreshService;
use app\services\ReferenceJsonBundleService;
use app\services\ReferenceResolverService;
use app\services\ReferenceTextNormalizerService;

function assertNormalizerSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$input = " SC Josten--Treasure Folio! \t\n";
$normalized = ReferenceTextNormalizerService::normalize($input);
$withoutCampusPrefix = ReferenceTextNormalizerService::normalizeWithoutCampusPrefix($input);
$tokens = ReferenceTextNormalizerService::tokens($input);
$key = ReferenceTextNormalizerService::key($input);

assertNormalizerSame('sc josten treasure folio', $normalized, 'Shared normalizer should lowercase, remove punctuation, and collapse whitespace.');
assertNormalizerSame('josten treasure folio', $withoutCampusPrefix, 'Shared normalizer should strip known campus prefixes on request.');
assertNormalizerSame('zz josten treasure folio', ReferenceTextNormalizerService::normalizeWithoutCampusPrefix('ZZ Josten Treasure Folio'), 'Shared normalizer should not strip unknown two-letter prefixes.');
assertNormalizerSame(['folio', 'josten', 'sc', 'treasure'], $tokens, 'Shared normalizer should generate sorted unique tokens.');
assertNormalizerSame('sc_josten_treasure_folio', $key, 'Shared normalizer should produce stable underscore keys.');

assertNormalizerSame(
    $normalized,
    ReferenceJsonBundleService::normalizeText($input),
    'ReferenceJsonBundleService should delegate text normalization to the shared service.'
);
assertNormalizerSame(
    $withoutCampusPrefix,
    ReferenceJsonBundleService::normalizeText($input, true),
    'ReferenceJsonBundleService should delegate campus-prefix stripping to the shared service.'
);

$resolverNormalize = new ReflectionMethod(ReferenceResolverService::class, 'normalizeText');
$resolverNormalize->setAccessible(true);
$resolverNormalizeKey = new ReflectionMethod(ReferenceResolverService::class, 'normalizeKey');
$resolverNormalizeKey->setAccessible(true);
$resolverStripPrefix = new ReflectionMethod(ReferenceResolverService::class, 'stripCampusPrefix');
$resolverStripPrefix->setAccessible(true);

assertNormalizerSame(
    $normalized,
    $resolverNormalize->invoke(null, $input),
    'ReferenceResolverService should delegate text normalization to the shared service.'
);
assertNormalizerSame(
    $key,
    $resolverNormalizeKey->invoke(null, $input),
    'ReferenceResolverService should delegate key normalization to the shared service.'
);
assertNormalizerSame(
    $withoutCampusPrefix,
    $resolverStripPrefix->invoke(null, $normalized),
    'ReferenceResolverService should delegate campus-prefix stripping to the shared service.'
);

$refresh = new ReferenceCacheRefreshService();
$refreshNormalize = new ReflectionMethod(ReferenceCacheRefreshService::class, 'normalizeText');
$refreshNormalize->setAccessible(true);
assertNormalizerSame(
    $normalized,
    $refreshNormalize->invoke($refresh, $input),
    'ReferenceCacheRefreshService should delegate text normalization to the shared service.'
);

fwrite(STDOUT, "ReferenceTextNormalizerService test passed\n");
