<?php

require_once __DIR__ . '/../services/ReferenceIntentService.php';

use app\services\ReferenceIntentService;

function assertIntentSame($expected, $actual, string $message): void
{
    global $intentTestFailures;

    if ($expected !== $actual) {
        $intentTestFailures[] = $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
            . "\n";
    }
}

$intentTestFailures = [];

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

function spansForDimension(string $prompt, string $dimension): array
{
    $spans = [];
    foreach (ReferenceIntentService::extract($prompt) as $intent) {
        if (($intent['dimension'] ?? null) === $dimension) {
            $spans[] = $intent['span'] ?? null;
        }
    }

    return $spans;
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

assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Show films held by Hillyer library.', 'library'),
    'Held-by wording must not become part of the library span.'
);
assertIntentSame(
    ['film'],
    materialTerms('Show films held by Hillyer library.'),
    'A named library must not consume an earlier explicit material term.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Show DVDs at Hillyer library on Smith campus.', 'library'),
    'A library followed by a campus must retain only its own qualified phrase.'
);
assertIntentSame(
    ['Smith campus'],
    spansForDimension('Show DVDs at Hillyer library on Smith campus.', 'campus'),
    'On must delimit the following campus phrase.'
);
assertIntentSame(
    ['dvd'],
    materialTerms('Show DVDs at Hillyer library on Smith campus.'),
    'Adjacent named dimensions must not consume an explicit material term.'
);
assertIntentSame(
    [],
    spansForDimension('What library has films?', 'library'),
    'An interrogative category noun must not become a named library.'
);
assertIntentSame(
    ['film'],
    materialTerms('What library has films?'),
    'An interrogative library question must retain its explicit material term.'
);
assertIntentSame(
    [],
    spansForDimension('Which campus has DVDs?', 'campus'),
    'An interrogative category noun must not become a named campus.'
);
assertIntentSame(
    ['dvd'],
    materialTerms('Which campus has DVDs?'),
    'An interrogative campus question must retain its explicit material term.'
);
assertIntentSame(
    ['Hillyer library', 'Neilson library'],
    spansForDimension('Hillyer library and Neilson library', 'library'),
    'Conjoined named libraries must produce clean non-overlapping spans.'
);
assertIntentSame(
    ['Hillyer library', 'Neilson library', 'Smith College library'],
    spansForDimension(
        'Show items at Hillyer library, Neilson library, or Smith College library.',
        'library'
    ),
    'Comma and or-separated named libraries must retain one span per qualifier.'
);

assertIntentSame(
    ['HC DVD', 'SC Art Video'],
    spansForDimension(
        'Show items in location HC DVD and location SC Art Video.',
        'location'
    ),
    'Repeated prefix-qualified locations must be split at and.'
);
assertIntentSame(
    ['HC DVD', 'SC Art Video'],
    spansForDimension(
        'location HC DVD and location SC Art Video',
        'location'
    ),
    'A prefix-qualified location list must preserve prompt order.'
);
assertIntentSame(
    ['HC DVD', 'SC Art Video', 'SC Music'],
    spansForDimension(
        'Show items in location HC DVD, location SC Art Video, and location SC Music.',
        'location'
    ),
    'Comma-separated prefix locations must not create conjunction-only spans.'
);
assertIntentSame(
    ['HC DVD', 'SC Art Video'],
    spansForDimension(
        'Show items at location HC DVD or location SC Art Video.',
        'location'
    ),
    'Or-separated prefix locations must produce one intent per qualifier.'
);
assertIntentSame(
    ['HC DVD'],
    spansForDimension(
        'Show items in location HC DVD and Hillyer library.',
        'location'
    ),
    'A prefix location must stop at a conjoined named dimension.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension(
        'Show items in location HC DVD and Hillyer library.',
        'library'
    ),
    'A named dimension after a prefix location must remain unconsumed.'
);
assertIntentSame(
    ['HC DVD', 'SC Art Video'],
    spansForDimension(
        'HC DVD location and SC Art Video location',
        'location'
    ),
    'Repeated suffix-qualified locations must be split at and.'
);
assertIntentSame(
    ['HC DVD', 'SC Art Video', 'SC Music'],
    spansForDimension(
        'HC DVD location, SC Art Video location, or SC Music location',
        'location'
    ),
    'Comma-separated suffix locations must not create phantom spans.'
);

assertIntentSame(
    ['8 mm'],
    materialTerms('Show 8 mm format at Hillyer library.'),
    'A numeric multiword qualified format must retain its full phrase.'
);
assertIntentSame(
    ['8 mm'],
    spansForDimension('Show 8 mm format at Hillyer library.', 'material_type'),
    'A numeric multiword format must retain its full raw provenance span.'
);
assertIntentSame(
    ['laser disc'],
    materialTerms('Show laser disc format at Hillyer library.'),
    'A multiword qualified format must retain all resolvable words.'
);
assertIntentSame(
    ['laser disc'],
    spansForDimension('Show laser disc format at Hillyer library.', 'material_type'),
    'A multiword format must retain its full raw provenance span.'
);
assertIntentSame(
    ['video recording'],
    materialTerms('Show video recording material type at Hillyer library.'),
    'A multiword material type must not collapse to its final token.'
);
assertIntentSame(
    ['video recording'],
    spansForDimension(
        'Show video recording material type at Hillyer library.',
        'material_type'
    ),
    'A multiword material type must retain its full raw provenance span.'
);
assertIntentSame(
    ['8 mm', 'laser disc', 'video recording'],
    materialTerms(
        'Show 8 mm format, laser disc format, and video recording material type '
        . 'at Hillyer library.'
    ),
    'Qualified format lists must use clause and list boundaries.'
);

if ($intentTestFailures !== []) {
    fwrite(STDERR, implode("\n", $intentTestFailures));
    exit(1);
}

fwrite(STDOUT, "ReferenceIntentService test passed\n");
