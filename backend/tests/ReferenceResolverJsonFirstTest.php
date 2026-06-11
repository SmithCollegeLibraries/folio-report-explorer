<?php

$bundlePath = __DIR__ . '/../services/ReferenceJsonBundleService.php';
$resolverPath = __DIR__ . '/../services/ReferenceResolverService.php';

foreach ([$bundlePath, $resolverPath] as $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Required service missing at {$path}\n");
        exit(1);
    }
}

$tempBundle = sys_get_temp_dir() . '/folio-reference-json-first-test.json';

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            global $tempBundle;
            if ($alias === '@app/data/reference_cache.json') {
                return $tempBundle;
            }
            return $alias;
        }

        public static function info($m, $c = null) {}
        public static function warning($m, $c = null) {}
    }
}

Yii::$app = (object) ['params' => []];

require_once $bundlePath;
require_once $resolverPath;

use app\services\ReferenceJsonBundleService;
use app\services\ReferenceResolverService;

function assertJsonFirstTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertJsonFirstContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: {$needle}\nActual:\n{$haystack}\n");
        exit(1);
    }
}

$bundle = [
    'generated_at' => date('c'),
    'approved_tables' => ReferenceJsonBundleService::approvedTables(),
    'excluded_tables' => ReferenceJsonBundleService::excludedTables(),
    'tables' => [
        'inventory.location__t' => [
            [
                'id' => 'loc-josten-treasure',
                'name' => 'SC Josten Treasure',
                'code' => 'SJTR',
                'normalized_name' => 'sc josten treasure',
                'normalized_name_without_prefix' => 'josten treasure',
                'normalized_code' => 'sjtr',
                'search_tokens' => ['josten', 'sc', 'sjtr', 'treasure'],
                'metadata' => ['library_name' => 'SC Josten Library', 'campus_name' => 'Smith College'],
            ],
            [
                'id' => 'loc-josten-treasure-folio',
                'name' => 'SC Josten Treasure Folio',
                'code' => 'SJTF',
                'normalized_name' => 'sc josten treasure folio',
                'normalized_name_without_prefix' => 'josten treasure folio',
                'normalized_code' => 'sjtf',
                'search_tokens' => ['folio', 'josten', 'sc', 'sjtf', 'treasure'],
                'metadata' => ['library_name' => 'SC Josten Library', 'campus_name' => 'Smith College'],
            ],
            [
                'id' => 'loc-josten-video',
                'name' => 'SC Josten Video',
                'code' => 'SJVD',
                'normalized_name' => 'sc josten video',
                'normalized_name_without_prefix' => 'josten video',
                'normalized_code' => 'sjvd',
                'search_tokens' => ['josten', 'sc', 'sjvd', 'video'],
                'metadata' => ['library_name' => 'SC Josten Library', 'campus_name' => 'Smith College'],
            ],
        ],
        'inventory.loclibrary__t' => [
            [
                'id' => 'lib-josten',
                'name' => 'SC Josten Library',
                'code' => 'SCJOS',
                'normalized_name' => 'sc josten library',
                'normalized_name_without_prefix' => 'josten library',
                'normalized_code' => 'scjos',
                'search_tokens' => ['josten', 'library', 'sc', 'scjos'],
                'metadata' => ['campus_name' => 'Smith College'],
            ],
        ],
        'inventory.loccampus__t' => [
            [
                'id' => 'campus-smith',
                'name' => 'Smith College',
                'code' => 'SC',
                'normalized_name' => 'smith college',
                'normalized_name_without_prefix' => 'smith college',
                'normalized_code' => 'sc',
                'search_tokens' => ['college', 'sc', 'smith'],
                'metadata' => [],
            ],
        ],
        'inventory.item__t' => [
            [
                'id' => 'forbidden',
                'name' => 'Forbidden item row',
                'normalized_name' => 'forbidden item row',
                'metadata' => [],
            ],
        ],
    ],
];

file_put_contents($tempBundle, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$loaded = ReferenceJsonBundleService::loadReferences($tempBundle);
foreach ($loaded as $reference) {
    assertJsonFirstTrue(($reference['source_table'] ?? '') !== 'inventory.item__t', 'JSON loader must drop hard-excluded inventory.item__t rows.');
}

$resolution = ReferenceResolverService::resolvePrompt('Show me all of the items in josten treasure and treasure folio.');
$guidance = implode("\n", $resolution['guidanceLines'] ?? []);

assertJsonFirstTrue(empty($resolution['needsClarification']), 'Confident Josten location matches should not ask for clarification.');
assertJsonFirstContains("inventory.location__t.name = 'SC Josten Treasure'", $guidance, 'Resolver must resolve Josten Treasure as an inventory.location__t row.');
assertJsonFirstContains("inventory.location__t.name = 'SC Josten Treasure Folio'", $guidance, 'Resolver must resolve Treasure Folio as an inventory.location__t row.');

$codeResolution = ReferenceResolverService::resolvePrompt('Show me items in location code SJTR.');
$codeGuidance = implode("\n", $codeResolution['guidanceLines'] ?? []);
assertJsonFirstContains("inventory.location__t.name = 'SC Josten Treasure'", $codeGuidance, 'Resolver must resolve SJTR as the Josten Treasure location row.');

$libraryResolution = ReferenceResolverService::resolvePrompt('Show me items in SC Josten Library.');
$libraryGuidance = implode("\n", $libraryResolution['guidanceLines'] ?? []);
assertJsonFirstContains("inventory.loclibrary__t.name = 'SC Josten Library'", $libraryGuidance, 'Resolver must preserve library scope as inventory.loclibrary__t.');

$campusResolution = ReferenceResolverService::resolvePrompt('Show me items at Smith College.');
$campusGuidance = implode("\n", $campusResolution['guidanceLines'] ?? []);
assertJsonFirstContains("inventory.loccampus__t.name = 'Smith College'", $campusGuidance, 'Resolver must preserve campus scope as inventory.loccampus__t.');

$ambiguousResolution = ReferenceResolverService::resolvePrompt('Show me items in Josten.');
assertJsonFirstTrue(!empty($ambiguousResolution['needsClarification']), 'Ambiguous location hierarchy prompts should stop for clarification.');
assertJsonFirstTrue(count($ambiguousResolution['clarificationItems'] ?? []) >= 1, 'Ambiguous location clarification should include at least one clarification item.');

$normalizeRows = new ReflectionMethod(ReferenceJsonBundleService::class, 'normalizeRows');
$normalizeRows->setAccessible(true);
$bundleService = new ReferenceJsonBundleService();
$generatedLocationRows = $normalizeRows->invoke($bundleService, [
    [
        'id' => 'loc-generated-josten-treasure',
        'name' => 'SC Josten Treasure',
        'code' => 'SJTR',
        'library_name' => 'SC Josten Library',
        'campus_name' => 'Smith College',
    ],
    [
        'id' => 'loc-generated-josten-treasure-folio',
        'name' => 'SC Josten Treasure Folio',
        'code' => 'SJTRF',
        'library_name' => 'SC Josten Library',
        'campus_name' => 'Smith College',
    ],
]);
$generatedLibraryRows = $normalizeRows->invoke($bundleService, [
    [
        'id' => 'lib-generated-josten',
        'name' => 'SC Josten Library',
        'code' => 'SCJOS',
        'campus_name' => 'Smith College',
    ],
]);

file_put_contents($tempBundle, json_encode([
    'generated_at' => date('c'),
    'approved_tables' => ReferenceJsonBundleService::approvedTables(),
    'excluded_tables' => ReferenceJsonBundleService::excludedTables(),
    'tables' => [
        'inventory.location__t' => $generatedLocationRows,
        'inventory.loclibrary__t' => $generatedLibraryRows,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$generatedResolution = ReferenceResolverService::resolvePrompt('Show me all of the items in josten treasure and treasure folio.');
$generatedGuidance = implode("\n", $generatedResolution['guidanceLines'] ?? []);
assertJsonFirstTrue(empty($generatedResolution['needsClarification']), 'Generated bundle-shaped Josten location matches should not ask for clarification.');
assertJsonFirstContains("inventory.location__t.name = 'SC Josten Treasure'", $generatedGuidance, 'Generated bundle-shaped resolver input must resolve Josten Treasure as inventory.location__t.');
assertJsonFirstContains("inventory.location__t.name = 'SC Josten Treasure Folio'", $generatedGuidance, 'Generated bundle-shaped resolver input must resolve Josten Treasure Folio as inventory.location__t.');

$fallbackBundle = [
    'generated_at' => date('c'),
    'approved_tables' => ReferenceJsonBundleService::approvedTables(),
    'excluded_tables' => ReferenceJsonBundleService::excludedTables(),
    'tables' => [
        'inventory.location__t' => [
            [
                'id' => 'loc-fallback-josten-treasure',
                'name' => 'SC Josten Treasure',
                'code' => 'SJTR',
                'metadata' => ['library_name' => 'SC Josten Library', 'campus_name' => 'Smith College'],
            ],
            [
                'id' => 'loc-fallback-josten-treasure-folio',
                'name' => 'SC Josten Treasure Folio',
                'code' => 'SJTRF',
                'metadata' => ['library_name' => 'SC Josten Library', 'campus_name' => 'Smith College'],
            ],
        ],
    ],
];
file_put_contents($tempBundle, json_encode($fallbackBundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$fallbackResolution = ReferenceResolverService::resolvePrompt('Show me all of the items in josten treasure and treasure folio.');
$fallbackGuidance = implode("\n", $fallbackResolution['guidanceLines'] ?? []);
assertJsonFirstContains("inventory.location__t.name = 'SC Josten Treasure'", $fallbackGuidance, 'Resolver fallback normalization must resolve Josten Treasure when normalized fields are absent.');
assertJsonFirstContains("inventory.location__t.name = 'SC Josten Treasure Folio'", $fallbackGuidance, 'Resolver fallback normalization must resolve Josten Treasure Folio when normalized fields are absent.');

@unlink($tempBundle);

fwrite(STDOUT, "ReferenceResolver JSON-first test passed\n");
