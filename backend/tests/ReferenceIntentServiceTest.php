<?php

require_once __DIR__ . '/../services/ReferenceIntentService.php';

use app\services\ReferenceIntentService;

function assertIntentSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
            . "\n"
        );
        exit(1);
    }
}

function materialTerms(string $prompt): array
{
    foreach (ReferenceIntentService::extract($prompt) as $intent) {
        if (($intent['dimension'] ?? null) === 'material_type') {
            return $intent['terms'] ?? [];
        }
    }

    return [];
}

function firstDimension(string $prompt)
{
    $intents = ReferenceIntentService::extract($prompt);

    return $intents[0]['dimension'] ?? null;
}

$reported = ReferenceIntentService::extract(
    'Find all of the video formats at Hillyer library. This can be VHS or DVD.'
);
assertIntentSame('library', $reported[0]['dimension'] ?? null, 'Hillyer must be typed as a library.');
assertIntentSame('Hillyer library', $reported[0]['span'] ?? null, 'The original library span must be retained.');
assertIntentSame('material_type', $reported[1]['dimension'] ?? null, 'Formats must be typed as material types.');
assertIntentSame(['vhs', 'dvd'], $reported[1]['terms'] ?? null, 'Explicit formats must narrow the video group.');
assertIntentSame(null, $reported[1]['selector'] ?? null, 'Explicit terms must not retain the default group selector.');
assertIntentSame(true, $reported[1]['explicit'] ?? null, 'Explicit formats must retain provenance.');

$generic = ReferenceIntentService::extract('Show video materials at Hillyer library.');
assertIntentSame('physical_video', $generic[1]['selector'] ?? null, 'Generic video must select physical video.');
assertIntentSame(false, $generic[1]['explicit'] ?? null, 'The generic video group is a documented default.');

$location = ReferenceIntentService::extract('Show items in location HC DVD.');
assertIntentSame(1, count($location), 'An explicit location span must consume DVD.');
assertIntentSame('location', $location[0]['dimension'] ?? null, 'HC DVD must retain location semantics.');
assertIntentSame('HC DVD', $location[0]['span'] ?? null, 'The exact location phrase must be retained.');

$allMaterials = ReferenceIntentService::extract('Show all materials at Hillyer library.');
assertIntentSame(1, count($allMaterials), 'All materials must not add a material-type filter.');

assertIntentSame(['dvd'], materialTerms('DVDs at Hillyer library.'), 'DVD plural must normalize.');
assertIntentSame(['vhs'], materialTerms('VHS tapes at Hillyer library.'), 'VHS tape must normalize.');
assertIntentSame(['film'], materialTerms('Films at Hillyer library.'), 'Film plural must normalize.');
assertIntentSame('location', firstDimension('SC Art Video location'), 'Explicit location suffix must win.');
assertIntentSame(['betamax'], materialTerms('Show Betamax format at Hillyer library.'), 'Unknown explicit formats must remain material-type intents.');
assertIntentSame('institution', firstDimension('Five Colleges institution'), 'Institution qualifiers must produce institution intents.');
assertIntentSame('campus', firstDimension('Smith campus'), 'Campus qualifiers must produce campus intents.');
assertIntentSame('service_point', firstDimension('Neilson service point'), 'Service-point qualifiers must produce service-point intents.');
assertIntentSame(
    1,
    count(ReferenceIntentService::extract('SC Art Video location')),
    'A location containing video must consume the overlapping generic material word.'
);
assertIntentSame(
    ['dvd'],
    materialTerms('Blu-ray format at Hillyer library.'),
    'Blu-ray spelling and punctuation must select the DVD/Blu-ray vocabulary term.'
);

assertIntentSame(
    ['vhs', 'dvd', 'film'],
    materialTerms('FILMS, DVDs, and vHs tapes at Hillyer library!'),
    'Material terms must be case-insensitive, punctuation-tolerant, and returned in selector order.'
);
assertIntentSame(
    ['dvd'],
    materialTerms('Show DVD at Hillyer library, not the HC DVD location.'),
    'A consumed location occurrence must not hide an earlier explicit material occurrence.'
);

$ordered = ReferenceIntentService::extract(
    'DVD at Hillyer library in SC Art Video location for Neilson library.'
);
assertIntentSame(
    ['Hillyer library', 'Neilson library'],
    array_values(array_map(function (array $intent): string {
        return $intent['span'];
    }, array_filter($ordered, function (array $intent): bool {
        return $intent['dimension'] === 'library';
    }))),
    'Intents within a dimension must preserve prompt order.'
);
assertIntentSame(
    ['library', 'library', 'location', 'material_type'],
    array_column($ordered, 'dimension'),
    'Intent dimensions must have a stable order independent of prompt order.'
);

assertIntentSame(
    'inventory.loclibrary__t',
    ReferenceIntentService::tableForDimension('library'),
    'Library intents must select only the library reference table.'
);
assertIntentSame(
    null,
    ReferenceIntentService::tableForDimension('unknown'),
    'Unknown intent dimensions must not select a reference table.'
);
assertIntentSame(
    ['Videocassette', 'DVD/Blu-ray', 'Film'],
    ReferenceIntentService::canonicalNamesForMaterialIntent([
        'dimension' => 'material_type',
        'terms' => [],
        'selector' => 'physical_video',
    ]),
    'Physical video must map to the exact documented canonical set.'
);
assertIntentSame(
    [],
    ReferenceIntentService::canonicalNamesForMaterialIntent([
        'dimension' => 'material_type',
        'terms' => ['betamax'],
        'selector' => null,
    ]),
    'Unknown explicit material terms must not be mapped to similar canonical values.'
);

fwrite(STDOUT, "ReferenceIntentService test passed\n");
