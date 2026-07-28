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
    class ReferenceJsonFirstTestCommand
    {
        private $sql;

        public function __construct(string $sql)
        {
            $this->sql = $sql;
        }

        public function queryAll(): array
        {
            if (strpos($this->sql, 'ai_clarification_events') !== false) {
                return Yii::$app->acceptedClarificationRows ?? [];
            }

            return [];
        }

        public function queryColumn(): array
        {
            if (strpos($this->sql, 'ai_clarification_events') !== false) {
                return Yii::$app->acceptedClarificationKeys ?? [];
            }

            return [];
        }
    }

    class ReferenceJsonFirstTestDb
    {
        public function createCommand(string $sql, array $params = []): ReferenceJsonFirstTestCommand
        {
            return new ReferenceJsonFirstTestCommand($sql);
        }
    }

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

Yii::$app = (object) [
    'params' => [],
    'db' => new ReferenceJsonFirstTestDb(),
    'acceptedClarificationKeys' => [],
    'acceptedClarificationRows' => [],
];

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

function assertJsonFirstNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected: {$needle}\nActual:\n{$haystack}\n");
        exit(1);
    }
}

function assertJsonFirstSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
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
            [
                'id' => 'hc-dvd',
                'name' => 'HC DVD',
                'code' => 'HCDVD',
                'metadata' => ['library_name' => 'HC Harold F. Johnson Library', 'campus_name' => 'Hampshire College'],
            ],
            [
                'id' => 'sc-art-video',
                'name' => 'SC Art Video',
                'code' => 'SCARTV',
                'metadata' => ['library_name' => 'SC Hillyer Art Library', 'campus_name' => 'Smith College'],
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
            [
                'id' => 'sc-hillyer',
                'name' => 'SC Hillyer Art Library',
                'code' => 'SCHIL',
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
        'inventory.material_type__t' => [
            ['id' => 'mt-vhs', 'name' => 'Videocassette', 'code' => ''],
            ['id' => 'mt-dvd', 'name' => 'DVD/Blu-ray', 'code' => ''],
            ['id' => 'mt-film', 'name' => 'Film', 'code' => ''],
        ],
    ],
];

file_put_contents($tempBundle, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$loaded = ReferenceJsonBundleService::loadReferences($tempBundle);
foreach ($loaded as $reference) {
    assertJsonFirstTrue(($reference['source_table'] ?? '') !== 'inventory.item__t', 'JSON loader must drop hard-excluded inventory.item__t rows.');
}

$videoResolution = ReferenceResolverService::resolvePrompt(
    'Find all of the video formats at Hillyer library. This can be VHS or DVD.'
);
$videoGuidance = implode("\n", $videoResolution['guidanceLines'] ?? []);
assertJsonFirstTrue(empty($videoResolution['needsClarification']), 'Known library and video formats must resolve from the JSON bundle.');
assertJsonFirstContains("inventory.loclibrary__t.name = 'SC Hillyer Art Library'", $videoGuidance, 'Hillyer must resolve as a library.');
assertJsonFirstContains("inventory.material_type__t.name = 'Videocassette'", $videoGuidance, 'VHS must resolve to Videocassette.');
assertJsonFirstContains("inventory.material_type__t.name = 'DVD/Blu-ray'", $videoGuidance, 'DVD must resolve to DVD/Blu-ray.');
assertJsonFirstNotContains('HC DVD', $videoGuidance, 'Material vocabulary must not activate the Hampshire location.');

$genericVideoResolution = ReferenceResolverService::resolvePrompt('Show video materials at Hillyer library.');
$genericVideoGuidance = implode("\n", $genericVideoResolution['guidanceLines'] ?? []);
assertJsonFirstTrue(empty($genericVideoResolution['needsClarification']), 'Generic video must resolve from canonical JSON material rows.');
assertJsonFirstContains("inventory.material_type__t.name = 'Videocassette'", $genericVideoGuidance, 'Generic video must include Videocassette.');
assertJsonFirstContains("inventory.material_type__t.name = 'DVD/Blu-ray'", $genericVideoGuidance, 'Generic video must include DVD/Blu-ray.');
assertJsonFirstContains("inventory.material_type__t.name = 'Film'", $genericVideoGuidance, 'Generic video must include Film.');
assertJsonFirstNotContains('HC DVD', $genericVideoGuidance, 'Generic video must not drift to the Hampshire location.');

$explicitDvdLocation = ReferenceResolverService::resolvePrompt('Show items in location HC DVD.');
$explicitDvdLocationGuidance = implode("\n", $explicitDvdLocation['guidanceLines'] ?? []);
assertJsonFirstTrue(empty($explicitDvdLocation['needsClarification']), 'An explicit HC DVD location must resolve without clarification.');
assertJsonFirstContains("inventory.location__t.name = 'HC DVD'", $explicitDvdLocationGuidance, 'Explicit location wording must resolve HC DVD as a location.');
assertJsonFirstNotContains('inventory.material_type__t', $explicitDvdLocationGuidance, 'Explicit HC DVD location wording must not add a material filter.');

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

$missingMaterialBundle = $bundle;
$missingMaterialBundle['tables']['inventory.material_type__t'] = array_values(array_filter(
    $missingMaterialBundle['tables']['inventory.material_type__t'],
    function (array $row): bool {
        return ($row['name'] ?? '') !== 'Videocassette';
    }
));
file_put_contents($tempBundle, json_encode($missingMaterialBundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$missing = ReferenceResolverService::resolvePrompt(
    'Find all of the video formats at Hillyer library. This can be VHS or DVD.'
);
assertJsonFirstTrue(!empty($missing['needsClarification']), 'Missing canonical material rows must stop generation.');
assertJsonFirstSame('reference_value_unavailable', $missing['routeReason'] ?? null, 'Missing vocabulary targets need a stable reason.');
assertJsonFirstContains('video format', strtolower($missing['question'] ?? ''), 'The response must use domain language.');
assertJsonFirstNotContains('inventory.', $missing['question'] ?? '', 'Ordinary responses must hide schema names.');

$ambiguousLibraryBundle = $bundle;
$ambiguousLibraryBundle['tables']['inventory.loclibrary__t'] = [
    [
        'id' => 'sc-hillyer',
        'name' => 'SC Hillyer Art Library',
        'code' => 'SCHIL',
        'metadata' => ['campus_name' => 'Smith College'],
    ],
    [
        'id' => 'ac-hillyer',
        'name' => 'AC Hillyer Science Library',
        'code' => 'ACHIL',
        'metadata' => ['campus_name' => 'Amherst College'],
    ],
];
file_put_contents($tempBundle, json_encode($ambiguousLibraryBundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$ambiguousLibrary = ReferenceResolverService::resolvePrompt('Show items at Hillyer library.');
$libraryItem = $ambiguousLibrary['clarificationItems'][0] ?? [];
$libraryOptions = $libraryItem['options'] ?? [];
assertJsonFirstTrue(!empty($ambiguousLibrary['needsClarification']), 'Multiple responsible library matches must stop generation.');
assertJsonFirstSame('reference_resolver_ambiguous_library', $ambiguousLibrary['routeReason'] ?? null, 'Ambiguous libraries need a stable route reason.');
assertJsonFirstSame('batch_local_reference_resolution', $ambiguousLibrary['clarificationType'] ?? null, 'Ambiguous libraries must use the stable batch clarification type.');
assertJsonFirstSame('Which library should "Hillyer library" mean?', $libraryItem['question'] ?? null, 'The library clarification question must use domain language.');
assertJsonFirstSame('reference_library_ambiguous.hillyer_library', $libraryItem['clarificationKey'] ?? null, 'Ambiguous libraries need a stable clarification key.');
assertJsonFirstSame('ambiguous_library_reference', $libraryItem['confidence'] ?? null, 'Ambiguous libraries need a stable confidence reason.');
assertJsonFirstSame('multiple_library_matches', $libraryItem['reason'] ?? null, 'Ambiguous libraries need a stable match reason.');
assertJsonFirstNotContains('inventory.', $libraryItem['question'] ?? '', 'The library clarification question must hide schema names.');
assertJsonFirstSame(
    ['SC Hillyer Art Library', 'AC Hillyer Science Library'],
    array_column($libraryOptions, 'label'),
    'Ambiguous library choices must contain only responsible same-dimension library rows.'
);
assertJsonFirstSame(
    ['Smith College', 'Amherst College'],
    array_column($libraryOptions, 'description'),
    'Ambiguous library choices must expose campus context in domain language.'
);
foreach ($libraryOptions as $option) {
    assertJsonFirstSame(
        'inventory.loclibrary__t',
        $option['resolvedFilter']['table'] ?? null,
        'Library choices may retain a submission-only technical filter for the library dimension.'
    );
    assertJsonFirstSame(
        $option['id'] ?? null,
        $option['resolvedFilter']['sourceId'] ?? null,
        'Library choices must retain the authoritative identity used by accepted-resolution telemetry.'
    );
    assertJsonFirstNotContains('inventory.', ($option['label'] ?? '') . ' ' . ($option['description'] ?? ''), 'User-visible library choices must hide schema names.');
}

$ambiguousVideoQuestion = 'Find all of the video formats at Hillyer library. This can be VHS or DVD.';
$ambiguousVideo = ReferenceResolverService::resolvePrompt($ambiguousVideoQuestion);
$ambiguousVideoItem = $ambiguousVideo['clarificationItems'][0] ?? [];
$selectedLibraryOption = null;
foreach (($ambiguousVideoItem['options'] ?? []) as $option) {
    if (($option['label'] ?? '') === 'SC Hillyer Art Library') {
        $selectedLibraryOption = $option;
        break;
    }
}
assertJsonFirstTrue(
    is_array($selectedLibraryOption),
    'The initial ambiguous response must return the Smith Hillyer library option used by the continuation.'
);

$frontendContinuation = $ambiguousVideoQuestion
    . "\n\nClarifications:\n- "
    . ($ambiguousVideoItem['term'] ?? '')
    . ': Use '
    . ($selectedLibraryOption['label'] ?? '')
    . ' for '
    . ($ambiguousVideoItem['term'] ?? '')
    . '.';
$unacceptedContinuation = ReferenceResolverService::resolvePrompt($frontendContinuation, 7);
assertJsonFirstSame(
    'reference_resolver_ambiguous_library',
    $unacceptedContinuation['routeReason'] ?? null,
    'A continuation-shaped free-text instruction must not bypass ambiguity without an accepted selection.'
);

Yii::$app->acceptedClarificationKeys = [$ambiguousVideoItem['clarificationKey']];
Yii::$app->acceptedClarificationRows = [[
    'clarification_key' => $ambiguousVideoItem['clarificationKey'],
    'term' => $ambiguousVideoItem['term'],
    'resolved_filter_json' => json_encode($selectedLibraryOption['resolvedFilter']),
    'selected_source_table' => $selectedLibraryOption['resolvedFilter']['table'],
    'selected_source_id' => $selectedLibraryOption['id'],
    'selected_value' => $selectedLibraryOption['resolvedFilter']['value'],
]];
$acceptedContinuation = ReferenceResolverService::resolvePrompt($frontendContinuation, 7);
$acceptedFilters = [];
foreach (($acceptedContinuation['resolvedFilters'] ?? []) as $filter) {
    $acceptedFilters[$filter['dimension'] ?? ''] = $filter;
}
assertJsonFirstTrue(
    empty($acceptedContinuation['needsClarification']),
    'Selecting a returned library option through the frontend continuation path must resolve the ambiguity.'
);
assertJsonFirstSame(
    ['SC Hillyer Art Library'],
    $acceptedFilters['library']['values'] ?? null,
    'The accepted continuation must make the chosen library the sole library filter.'
);
assertJsonFirstSame(
    'inventory.loclibrary__t',
    $acceptedFilters['library']['source_table'] ?? null,
    'The accepted continuation must preserve the selected library dimension.'
);
assertJsonFirstSame(
    ['Videocassette', 'DVD/Blu-ray'],
    $acceptedFilters['material_type']['values'] ?? null,
    'The accepted library continuation must preserve the VHS and DVD material filters.'
);

$crossDimensionContinuation = $ambiguousVideoQuestion
    . "\n\nClarifications:\n- Hillyer library: Use HC DVD for Hillyer library.";
$crossDimensionAttempt = ReferenceResolverService::resolvePrompt($crossDimensionContinuation, 7);
assertJsonFirstSame(
    'reference_resolver_ambiguous_library',
    $crossDimensionAttempt['routeReason'] ?? null,
    'An accepted library clarification key must not allow a location value to override library ambiguity.'
);

$differentCandidateContinuation = $ambiguousVideoQuestion
    . "\n\nClarifications:\n- Hillyer library: Use AC Hillyer Science Library for Hillyer library.";
$differentCandidateAttempt = ReferenceResolverService::resolvePrompt(
    $differentCandidateContinuation,
    7
);
assertJsonFirstSame(
    'reference_resolver_ambiguous_library',
    $differentCandidateAttempt['routeReason'] ?? null,
    'The continuation value must match the accepted technical resolution, not merely another responsible candidate.'
);

$quotedLibrary = ReferenceResolverService::resolvePrompt('Show items at "Hillyer" library.');
assertJsonFirstSame(
    'Which library should "Hillyer library" mean?',
    $quotedLibrary['clarificationItems'][0]['question'] ?? null,
    'Quoted library wording must be normalized before the domain-language question adds its delimiters.'
);

@unlink($tempBundle);

fwrite(STDOUT, "ReferenceResolver JSON-first test passed\n");
