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

function materialSelector(string $prompt)
{
    foreach (ReferenceIntentService::extract($prompt) as $intent) {
        if (($intent['dimension'] ?? null) === 'material_type') {
            return $intent['selector'] ?? null;
        }
    }

    return null;
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

$listRequest = ReferenceIntentService::extract(
    'show me a list of vhs and dvds at Hillyer library'
);
assertIntentSame(
    'Hillyer library',
    $listRequest[0]['span'] ?? null,
    'A list-of request must keep only the qualified library phrase as the library intent.'
);
assertIntentSame(
    ['vhs', 'dvd'],
    $listRequest[1]['terms'] ?? null,
    'A list-of request must preserve the explicit material terms before the library boundary.'
);
foreach ([
    'Please show me a list of VHS and DVDs at Hillyer library',
    'Could you show me a list of VHS and DVDs at Hillyer library',
    'Can I see a list of VHS and DVDs at Hillyer library',
    'I need a list of VHS and DVDs at Hillyer library',
] as $listRequestVariant) {
    assertIntentSame(
        ['Hillyer library'],
        spansForDimension($listRequestVariant, 'library'),
        'Command scaffolding variants must retain only the qualified library phrase.'
    );
    assertIntentSame(
        ['vhs', 'dvd'],
        materialTerms($listRequestVariant),
        'Command scaffolding variants must preserve the explicit video material terms.'
    );
}

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

assertIntentSame(
    ['Hillyer library'],
    spansForDimension('What is the Hillyer library?', 'library'),
    'An interrogative auxiliary and article must not pollute a named library.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Where is Hillyer library?', 'library'),
    'An interrogative auxiliary must not pollute a named library.'
);
assertIntentSame(
    ['Smith campus'],
    spansForDimension('Which is the Smith campus?', 'campus'),
    'Interrogative scaffolding must be removed from a named campus.'
);
assertIntentSame(
    ['Neilson service point'],
    spansForDimension('Who uses Neilson service point?', 'service_point'),
    'An interrogative subject and verb must not pollute a named service point.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Does Hillyer library have films?', 'library'),
    'An interrogative verb must not pollute a named library.'
);
assertIntentSame(
    ['film'],
    materialTerms('Does Hillyer library have films?'),
    'Interrogative hierarchy extraction must leave following materials available.'
);

assertIntentSame(
    ['HC DVD and SC Art Video'],
    spansForDimension('locations HC DVD and SC Art Video', 'location'),
    'An all-unknown conjunction after a shared prefix qualifier must remain atomic.'
);
assertIntentSame(
    ['HC DVD and SC Art Video'],
    spansForDimension('HC DVD and SC Art Video locations', 'location'),
    'An all-unknown conjunction before a shared suffix qualifier must remain atomic.'
);
assertIntentSame(
    [],
    materialTerms('HC DVD and SC Art Video locations'),
    'Every shared suffix location value must be consumed before material extraction.'
);
assertIntentSame(
    ['HC DVD', 'SC Art Video', 'SC Music'],
    spansForDimension(
        'HC DVD, SC Art Video, and SC Music locations',
        'location'
    ),
    'A shared suffix plural location qualifier must retain prompt order.'
);
assertIntentSame(
    [],
    materialTerms('HC DVD, SC Art Video, and SC Music locations'),
    'Comma-separated shared suffix locations must consume every value.'
);
assertIntentSame(
    ['Library Stacks'],
    spansForDimension('Show items in location Library Stacks.', 'location'),
    'A location name may contain the word Stacks.'
);
assertIntentSame(
    ['Room 101'],
    spansForDimension('Show items in Room 101 location.', 'location'),
    'A location name may contain the word Room.'
);
assertIntentSame(
    ['Archives and Special Collections'],
    spansForDimension('Archives and Special Collections location', 'location'),
    'A suffix-qualified location may contain another qualifier word.'
);
assertIntentSame(
    ['Archives and Special Collections'],
    spansForDimension(
        'Show items in location Archives and Special Collections.',
        'location'
    ),
    'A singular prefix qualifier must not split a location name containing and.'
);
assertIntentSame(
    ['Research and Instruction Center'],
    spansForDimension(
        'Research and Instruction Center location',
        'location'
    ),
    'A singular suffix qualifier must not split a location name containing and.'
);

assertIntentSame(
    ['HC DVD'],
    spansForDimension(
        'Show items in location HC DVD and Betamax format.',
        'location'
    ),
    'A prefix location must stop before a conjoined qualified material.'
);
assertIntentSame(
    ['betamax'],
    materialTerms('Show items in location HC DVD and Betamax format.'),
    'A qualified unknown material after a prefix location must remain available.'
);
assertIntentSame(
    ['HC DVD'],
    spansForDimension('Show items in location HC DVD and VHS.', 'location'),
    'A prefix location must stop before a conjoined known material.'
);
assertIntentSame(
    ['vhs'],
    materialTerms('Show items in location HC DVD and VHS.'),
    'A known material after a prefix location must remain available.'
);
assertIntentSame(
    ['HC DVD'],
    spansForDimension(
        'Show items in location HC DVD and video materials.',
        'location'
    ),
    'A prefix location must stop before conjoined generic video material wording.'
);
assertIntentSame(
    'physical_video',
    ReferenceIntentService::extract(
        'Show items in location HC DVD and video materials.'
    )[1]['selector'] ?? null,
    'Generic video after a prefix location must retain physical-video behavior.'
);

assertIntentSame(
    ['betamax and laser disc'],
    materialTerms('Show Betamax and laser disc formats at Hillyer library.'),
    'An all-unknown material conjunction must remain atomic rather than fabricate terms.'
);
assertIntentSame(
    ['betamax', 'laser disc', 'type ii'],
    materialTerms(
        'Show Betamax, laser disc, and Type II formats at Hillyer library.'
    ),
    'A shared plural format qualifier must retain numbered and multiword terms.'
);
assertIntentSame(
    ['vhs', 'betamax'],
    materialTerms('Show Betamax and VHS formats at Hillyer library.'),
    'Shared known and unknown formats must retain canonical known-term order.'
);
assertIntentSame(
    ['dvd', 'laser disc'],
    materialTerms('Show laser disc and DVD formats at Hillyer library.'),
    'A trailing known format must not discard an earlier unknown format.'
);
assertIntentSame(
    ['type ii and u-matic 3/4-inch'],
    materialTerms(
        'Show Type II and U-matic 3/4-inch material types at Hillyer library.'
    ),
    'An ambiguous all-unknown material-type conjunction must remain atomic.'
);
assertIntentSame(
    ['rock and roll'],
    materialTerms('Show Rock and Roll format at Hillyer library.'),
    'A singular format qualifier must not split a material name containing and.'
);

assertIntentSame(
    ['HC DVD'],
    spansForDimension('Show items in location HC DVD, VHS.', 'location'),
    'A comma must end a singular prefix location before a known material.'
);
assertIntentSame(
    ['vhs'],
    materialTerms('Show items in location HC DVD, VHS.'),
    'A known material after a comma-delimited location must remain available.'
);
assertIntentSame(
    ['HC DVD'],
    spansForDimension('Show items in location HC DVD, Hillyer library.', 'location'),
    'A comma must end a singular prefix location before a hierarchy reference.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Show items in location HC DVD, Hillyer library.', 'library'),
    'A hierarchy reference after a comma-delimited location must remain available.'
);
assertIntentSame(
    ['HC DVD'],
    spansForDimension('Show items in location HC DVD, Betamax format.', 'location'),
    'A comma must end a singular prefix location before a qualified material.'
);
assertIntentSame(
    ['betamax'],
    materialTerms('Show items in location HC DVD, Betamax format.'),
    'A qualified material after a comma-delimited location must remain available.'
);
assertIntentSame(
    ['HC DVD'],
    spansForDimension('HC DVD location and Hillyer library', 'location'),
    'A suffix location must retain its value before a hierarchy reference.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('HC DVD location and Hillyer library', 'library'),
    'A suffix location qualifier must not leak into a following hierarchy span.'
);
assertIntentSame(
    ['HC DVD'],
    spansForDimension('HC DVD location and Betamax format', 'location'),
    'A suffix location must retain its value before a qualified material.'
);
assertIntentSame(
    ['betamax'],
    materialTerms('HC DVD location and Betamax format'),
    'A suffix location qualifier must not leak into a following material span.'
);
assertIntentSame(
    ['HC DVD and SC Art Video'],
    spansForDimension(
        'HC DVD and SC Art Video locations and Smith campus',
        'location'
    ),
    'An ambiguous shared suffix location conjunction must remain one qualified name.'
);
assertIntentSame(
    ['Smith campus'],
    spansForDimension(
        'HC DVD and SC Art Video locations and Smith campus',
        'campus'
    ),
    'A shared suffix location qualifier must not leak into a following campus span.'
);

assertIntentSame(
    ['Hillyer and Neilson'],
    spansForDimension('Hillyer and Neilson libraries', 'library'),
    'An all-unknown shared suffix library conjunction must remain atomic.'
);
assertIntentSame(
    ['Hillyer', 'Neilson', 'Smith College'],
    spansForDimension(
        'Hillyer, Neilson, and Smith College libraries',
        'library'
    ),
    'A comma-structured shared library list must preserve every value in prompt order.'
);
assertIntentSame(
    ['Smith and Mount Holyoke'],
    spansForDimension('Smith and Mount Holyoke campuses', 'campus'),
    'An all-unknown shared suffix campus conjunction must remain atomic.'
);
assertIntentSame(
    ['Hillyer and Neilson'],
    spansForDimension('libraries Hillyer and Neilson', 'library'),
    'An all-unknown shared prefix library conjunction must remain atomic.'
);
assertIntentSame(
    ['Campus Center library'],
    spansForDimension('Campus Center library', 'library'),
    'A hierarchy word inside a library name must remain part of the value.'
);
assertIntentSame(
    ['Library Annex library'],
    spansForDimension('Library Annex library', 'library'),
    'A leading hierarchy word inside a library name must remain part of the value.'
);
assertIntentSame(
    [],
    spansForDimension('Smith College Campus Center library', 'campus'),
    'An embedded campus word must not manufacture a campus intent.'
);
assertIntentSame(
    ['Smith College Campus Center library'],
    spansForDimension('Smith College Campus Center library', 'library'),
    'A rightmost qualified hierarchy name must claim embedded qualifier words.'
);

assertIntentSame(
    ['Research and Instruction Center', 'HC DVD', 'SC Music'],
    spansForDimension(
        'locations Research and Instruction Center, HC DVD, and SC Music',
        'location'
    ),
    'Internal and must remain atomic inside a comma-delimited prefix location chunk.'
);
assertIntentSame(
    ['Research and Instruction Center', 'HC DVD', 'SC Music'],
    spansForDimension(
        'Research and Instruction Center, HC DVD, and SC Music locations',
        'location'
    ),
    'Internal and must remain atomic inside a comma-delimited suffix location chunk.'
);
assertIntentSame(
    ['vhs', 'rock and roll', 'laser disc'],
    materialTerms('Rock and Roll, laser disc, and VHS formats'),
    'Internal and must remain atomic inside a comma-delimited material chunk.'
);
assertIntentSame(
    ['HC DVD', 'SC Art Video'],
    spansForDimension('locations HC DVD and/or SC Art Video', 'location'),
    'And/or must split an explicit shared location list.'
);
assertIntentSame(
    ['vhs', 'betamax'],
    materialTerms('VHS and/or Betamax formats'),
    'And/or must split an explicit shared material list.'
);

assertIntentSame(
    ['vhs', 'betamax'],
    materialTerms('VHS and Betamax format at Hillyer library'),
    'An unambiguous singular qualified material list must retain every term.'
);
assertIntentSame(
    ['vhs', 'betamax'],
    materialTerms('Betamax and VHS format at Hillyer library'),
    'Known-word position must not discard another singular-list material.'
);
assertIntentSame(
    ['vhs', 'betamax', 'laser disc'],
    materialTerms('VHS, Betamax, and laser disc format at Hillyer library'),
    'A comma-structured singular qualified material list must retain every term.'
);
assertIntentSame(
    ['super vhs'],
    materialTerms('Super VHS format at Hillyer library'),
    'A fully qualified compound must override its embedded VHS token.'
);
assertIntentSame(
    ['vhs-c'],
    materialTerms('VHS-C format at Hillyer library'),
    'A fully qualified hyphenated compound must override its embedded VHS token.'
);
assertIntentSame(
    ['dvd audio'],
    materialTerms('DVD Audio format at Hillyer library'),
    'A fully qualified compound must override its embedded DVD token.'
);

assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Tell me about Hillyer library', 'library'),
    'Imperative scaffolding must not pollute a library value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Can you show me Hillyer library?', 'library'),
    'Modal question scaffolding must not pollute a library value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('How many items does Hillyer library have?', 'library'),
    'Count-question scaffolding must not pollute a library value.'
);
assertIntentSame(
    [],
    spansForDimension('Show me libraries with films', 'library'),
    'A generic plural hierarchy category must not become an explicit reference.'
);
assertIntentSame(
    ['film'],
    materialTerms('Show me libraries with films'),
    'A generic hierarchy question must retain its explicit material term.'
);

$showVideoFormats = ReferenceIntentService::extract(
    'Show me video formats at Hillyer library'
);
assertIntentSame(
    [],
    materialTerms('Show me video formats at Hillyer library'),
    'Generic video formats must not become an explicit unknown material.'
);
assertIntentSame(
    'physical_video',
    $showVideoFormats[1]['selector'] ?? null,
    'Generic video formats after imperative scaffolding must select physical video.'
);
$countVideoFormats = ReferenceIntentService::extract(
    'How many video formats are at Hillyer library?'
);
assertIntentSame(
    [],
    materialTerms('How many video formats are at Hillyer library?'),
    'Generic video formats in a count question must not become an explicit unknown material.'
);
assertIntentSame(
    'physical_video',
    $countVideoFormats[1]['selector'] ?? null,
    'Generic video formats in a count question must select physical video.'
);
assertIntentSame(
    ['All Saints library'],
    spansForDimension('All Saints library', 'library'),
    'A legitimate leading All must remain part of a library name.'
);
assertIntentSame(
    ['The Library and Learning Commons'],
    spansForDimension(
        'The Library and Learning Commons location',
        'location'
    ),
    'A legitimate leading The must remain part of a location name.'
);

assertIntentSame(
    ['bétamax'],
    materialTerms('Élodie library and BÉTAMAX format'),
    'Unknown Unicode material terms must normalize case consistently.'
);

assertIntentSame(
    [],
    spansForDimension('Which libraries have films?', 'library'),
    'A plural library category followed by a predicate must not become a named library.'
);
assertIntentSame(
    ['film'],
    materialTerms('Which libraries have films?'),
    'A library category predicate must leave its material term available.'
);
assertIntentSame(
    [],
    spansForDimension('Which libraries circulate films?', 'library'),
    'Interrogative category structure must reject unenumerated predicate verbs.'
);
assertIntentSame(
    ['film'],
    materialTerms('Which libraries circulate films?'),
    'An unenumerated category predicate must leave its material term available.'
);
assertIntentSame(
    [],
    spansForDimension('What locations offer DVDs?', 'location'),
    'A location category followed by a predicate must not become a named location.'
);
assertIntentSame(
    ['dvd'],
    materialTerms('What locations offer DVDs?'),
    'A location category predicate must leave its material term available.'
);
assertIntentSame(
    [],
    spansForDimension('Which service points are open?', 'service_point'),
    'A service-point category followed by a predicate must not become a named service point.'
);
assertIntentSame(
    ['HC DVD'],
    spansForDimension('HC DVD location is closed.', 'location'),
    'A suffix location followed by a predicate must retain the value on its left.'
);
assertIntentSame(
    [],
    materialTerms('HC DVD location is closed.'),
    'A suffix location must consume material-looking words in its own name.'
);
assertIntentSame(
    ['SC Art Video'],
    spansForDimension('SC Art Video location has films.', 'location'),
    'A suffix location before a material predicate must retain the value on its left.'
);
assertIntentSame(
    ['film'],
    materialTerms('SC Art Video location has films.'),
    'A suffix location predicate must leave following explicit materials available.'
);
assertIntentSame(
    ['HC DVD'],
    spansForDimension('location: HC DVD', 'location'),
    'A colon after a location qualifier must introduce a prefix value.'
);
assertIntentSame(
    [],
    materialTerms('location: HC DVD'),
    'A colon-introduced location must consume material-looking words in its name.'
);
assertIntentSame(
    ['Hillyer'],
    spansForDimension('library: Hillyer', 'library'),
    'A colon after a library qualifier must introduce a prefix value.'
);
assertIntentSame(
    ['betamax'],
    materialTerms('material type: Betamax'),
    'A colon after a material-type qualifier must introduce a prefix value.'
);

assertIntentSame(
    ['Smith campus'],
    spansForDimension('Smith campus, Hillyer and Neilson libraries', 'campus'),
    'A dimension before a shared suffix library list must remain independent.'
);
assertIntentSame(
    ['Hillyer and Neilson'],
    spansForDimension('Smith campus, Hillyer and Neilson libraries', 'library'),
    'An ambiguous final library conjunction after a campus must remain atomic.'
);
assertIntentSame(
    ['Hillyer library', 'Neilson and Josten'],
    spansForDimension(
        'Show films at Hillyer library, Neilson and Josten libraries.',
        'library'
    ),
    'An ambiguous non-Oxford final library chunk must remain atomic.'
);
assertIntentSame(
    ['film'],
    materialTerms('Show films at Hillyer library, Neilson and Josten libraries.'),
    'A suffix library list must not consume an earlier material clause.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('At Hillyer library, show Betamax and VHS formats.', 'library'),
    'A library before a shared material list must remain independent.'
);
assertIntentSame(
    ['vhs', 'betamax'],
    materialTerms('At Hillyer library, show Betamax and VHS formats.'),
    'A shared suffix material list after a library must retain every value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension(
        'Hillyer library, HC DVD and SC Art Video locations',
        'library'
    ),
    'A library before plural suffix locations must not be retyped as a location.'
);
assertIntentSame(
    ['HC DVD and SC Art Video'],
    spansForDimension(
        'Hillyer library, HC DVD and SC Art Video locations',
        'location'
    ),
    'An ambiguous final location conjunction after a library must remain atomic.'
);
assertIntentSame(
    ['Smith campus'],
    spansForDimension(
        'Smith campus, HC DVD and SC Art Video locations',
        'campus'
    ),
    'A campus before plural suffix locations must not be retyped as a location.'
);
assertIntentSame(
    ['HC DVD and SC Art Video'],
    spansForDimension(
        'Smith campus, HC DVD and SC Art Video locations',
        'location'
    ),
    'An ambiguous final location conjunction after a campus must remain atomic.'
);
assertIntentSame(
    ['Hillyer and Neilson'],
    spansForDimension('Show films, Hillyer and Neilson libraries.', 'library'),
    'An ambiguous library conjunction after a material clause must remain atomic.'
);
assertIntentSame(
    ['film'],
    materialTerms('Show films, Hillyer and Neilson libraries.'),
    'An earlier material clause must survive suffix library extraction.'
);

assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Please show Hillyer library.', 'library'),
    'Please-show request scaffolding must not pollute a library value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('I need Hillyer library.', 'library'),
    'I-need request scaffolding must not pollute a library value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Could I see Hillyer library?', 'library'),
    'Could-I-see request scaffolding must not pollute a library value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Search Hillyer library for DVDs.', 'library'),
    'Search request scaffolding must not pollute a library value.'
);
assertIntentSame(
    ['dvd'],
    materialTerms('Search Hillyer library for DVDs.'),
    'Search request scaffolding must leave following materials available.'
);
assertIntentSame(
    ['Books for All library'],
    spansForDimension('Books for All library', 'library'),
    'A legitimate library name containing for must remain intact.'
);
assertIntentSame(
    ['Center for Media library'],
    spansForDimension('Center for Media library', 'library'),
    'A second legitimate library name containing for must remain intact.'
);
assertIntentSame(
    ['Made in America'],
    spansForDimension('Made in America location', 'location'),
    'A legitimate location name containing in must remain intact.'
);
assertIntentSame(
    ['Service for Students service point'],
    spansForDimension('Service for Students service point', 'service_point'),
    'A legitimate service-point name containing for must remain intact.'
);

assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Hillyer library — Smith campus', 'library'),
    'An em dash must separate a library from a following campus.'
);
assertIntentSame(
    ['Smith campus'],
    spansForDimension('Hillyer library — Smith campus', 'campus'),
    'An em dash must bound a following campus value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Hillyer library – Smith campus', 'library'),
    'An en dash must separate a library from a following campus.'
);
assertIntentSame(
    ['Smith campus'],
    spansForDimension('Hillyer library – Smith campus', 'campus'),
    'An en dash must bound a following campus value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Hillyer library / Smith campus', 'library'),
    'A visually separated slash must separate a library from a following campus.'
);
assertIntentSame(
    ['Smith campus'],
    spansForDimension('Hillyer library / Smith campus', 'campus'),
    'A visually separated slash must bound a following campus value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('Hillyer library • Smith campus', 'library'),
    'A bullet must separate a library from a following campus.'
);
assertIntentSame(
    ['Smith campus'],
    spansForDimension('Hillyer library • Smith campus', 'campus'),
    'A bullet must bound a following campus value.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('DVD — Hillyer library', 'library'),
    'An em dash must prevent a library from consuming an earlier material.'
);
assertIntentSame(
    ['dvd'],
    materialTerms('DVD — Hillyer library'),
    'An em dash must leave the earlier material available.'
);

$coordinatedCategoryPrompt = 'Tell me which campuses and libraries have films?';
assertIntentSame(
    [],
    spansForDimension($coordinatedCategoryPrompt, 'campus'),
    'Scaffolded coordinated campus categories must not become named campuses.'
);
assertIntentSame(
    [],
    spansForDimension($coordinatedCategoryPrompt, 'library'),
    'Scaffolded coordinated library categories must not become named libraries.'
);
assertIntentSame(
    ['film'],
    materialTerms($coordinatedCategoryPrompt),
    'Scaffolded coordinated category questions must leave material terms available.'
);

$punctuatedCategoryPrompts = [
    ['Which locations, libraries, and campuses have DVDs?', ['dvd']],
    ['Which campuses, libraries and locations have films?', ['film']],
];
foreach ($punctuatedCategoryPrompts as $punctuatedCategoryPrompt) {
    foreach (['campus', 'library', 'location'] as $categoryDimension) {
        assertIntentSame(
            [],
            spansForDimension($punctuatedCategoryPrompt[0], $categoryDimension),
            'A punctuated coordinated category prefix must not become a named hierarchy intent.'
        );
    }
    assertIntentSame(
        $punctuatedCategoryPrompt[1],
        materialTerms($punctuatedCategoryPrompt[0]),
        'A punctuated coordinated category prefix must leave its predicate material available.'
    );
}

assertIntentSame(
    [],
    materialTerms('Which campuses and libraries have video formats?'),
    'A coordinated category prefix must not be folded into a generic video qualifier.'
);
assertIntentSame(
    'physical_video',
    materialSelector('Which campuses and libraries have video formats?'),
    'A coordinated category predicate must retain the generic physical-video selector.'
);
assertIntentSame(
    ['betamax'],
    materialTerms('Which campuses and libraries have Betamax format?'),
    'A coordinated category predicate must retain one immediately qualified unknown format.'
);
assertIntentSame(
    ['vhs', 'betamax'],
    materialTerms('Which campuses and libraries have Betamax and VHS formats?'),
    'A coordinated category predicate must retain known and unknown qualified formats.'
);

$nonOxfordMaterialPrompt =
    'At Hillyer library, Rock and Roll, Betamax, VHS and DVD formats.';
assertIntentSame(
    ['Hillyer library'],
    spansForDimension($nonOxfordMaterialPrompt, 'library'),
    'A non-Oxford material list must not consume an earlier qualified library.'
);
assertIntentSame(
    ['vhs', 'dvd', 'rock and roll', 'betamax'],
    materialTerms($nonOxfordMaterialPrompt),
    'A non-Oxford trailing pair must split while earlier conjunction-bearing chunks remain atomic.'
);

$nonOxfordAtomicValues = [
    [
        'Hillyer, Lewis and Clark libraries',
        'library',
        ['Hillyer', 'Lewis and Clark'],
    ],
    [
        'HC DVD, Research and Instruction Center locations',
        'location',
        ['HC DVD', 'Research and Instruction Center'],
    ],
];
foreach ($nonOxfordAtomicValues as $nonOxfordAtomicValue) {
    assertIntentSame(
        $nonOxfordAtomicValue[2],
        spansForDimension($nonOxfordAtomicValue[0], $nonOxfordAtomicValue[1]),
        'A conjunction-bearing non-Oxford final name must not be split into fabricated fragments.'
    );
}
assertIntentSame(
    ['betamax', 'rock and roll'],
    materialTerms('Betamax, Rock and Roll formats'),
    'A conjunction-bearing non-Oxford final material name must remain atomic.'
);

$prepositionBearingNames = [
    ['Video for All library', 'library', 'Video for All library'],
    ['Materials for the Arts library', 'library', 'Materials for the Arts library'],
    ['Items for Everyone library', 'library', 'Items for Everyone library'],
    ['Records on the Run location', 'location', 'Records on the Run'],
];
foreach ($prepositionBearingNames as $prepositionBearingName) {
    assertIntentSame(
        [$prepositionBearingName[2]],
        spansForDimension(
            $prepositionBearingName[0],
            $prepositionBearingName[1]
        ),
        'Lexical request or material words must not truncate a qualified name.'
    );
}

$structuralPrepositionNames = [
    ['Art at Work library', 'library', 'Art at Work library'],
    ['Research at Home location', 'location', 'Research at Home'],
    ['Held by Design library', 'library', 'Held by Design library'],
    ['Science at Work service point', 'service_point', 'Science at Work service point'],
];
foreach ($structuralPrepositionNames as $structuralPrepositionName) {
    assertIntentSame(
        [$structuralPrepositionName[2]],
        spansForDimension(
            $structuralPrepositionName[0],
            $structuralPrepositionName[1]
        ),
        'At and held-by text inside a qualified name must remain lexical.'
    );
}
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('DVD at Hillyer library', 'library'),
    'At after a material atom must remain a structural request boundary.'
);
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('films held by Hillyer library', 'library'),
    'Held by after a material atom must remain a structural request boundary.'
);
foreach (
    [
        'VHS and DVD at Hillyer library',
        'VHS and DVD held by Hillyer library',
    ] as $knownMaterialBoundaryPrompt
) {
    assertIntentSame(
        ['Hillyer library'],
        spansForDimension($knownMaterialBoundaryPrompt, 'library'),
        'A conjunction of known materials must remain structural boundary evidence.'
    );
    assertIntentSame(
        ['vhs', 'dvd'],
        materialTerms($knownMaterialBoundaryPrompt),
        'Known materials before a structural boundary must remain material intents.'
    );
}

$ampersandCategoryPrompt = 'Which locations, libraries & campuses have DVDs?';
foreach (['location', 'library', 'campus'] as $ampersandCategoryDimension) {
    assertIntentSame(
        [],
        spansForDimension($ampersandCategoryPrompt, $ampersandCategoryDimension),
        'Ampersand-coordinated categories must not become named intents.'
    );
}
assertIntentSame(
    ['dvd'],
    materialTerms($ampersandCategoryPrompt),
    'Ampersand-coordinated category questions must retain material terms.'
);

$visualBoundaryPrompts = [
    "Hillyer library\nSmith campus",
    'Hillyer library | Smith campus',
    'Hillyer library · Smith campus',
];
foreach ($visualBoundaryPrompts as $visualBoundaryPrompt) {
    assertIntentSame(
        ['Hillyer library'],
        spansForDimension($visualBoundaryPrompt, 'library'),
        'A visual separator must preserve the library on its left.'
    );
    assertIntentSame(
        ['Smith campus'],
        spansForDimension($visualBoundaryPrompt, 'campus'),
        'A visual separator must bound the campus on its right.'
    );
}
assertIntentSame(
    ['Hillyer library'],
    spansForDimension('DVD | Hillyer library', 'library'),
    'A pipe must prevent a library from consuming an earlier material.'
);
assertIntentSame(
    ['dvd'],
    materialTerms('DVD | Hillyer library'),
    'A pipe must leave the earlier material available.'
);

$materialBoundaryWarnings = [];
set_error_handler(function (
    int $severity,
    string $message
) use (&$materialBoundaryWarnings): bool {
    $materialBoundaryWarnings[] = [$severity, $message];

    return true;
});
$malformedMaterialNames = ReferenceIntentService::canonicalNamesForMaterialIntent([
    'dimension' => 'material_type',
    'terms' => 'vhs',
]);
restore_error_handler();
assertIntentSame(
    [],
    $malformedMaterialNames,
    'Malformed scalar material terms must fail closed.'
);
assertIntentSame(
    [],
    $materialBoundaryWarnings,
    'Malformed scalar material terms must not emit a public-boundary warning.'
);

if ($intentTestFailures !== []) {
    fwrite(STDERR, implode("\n", $intentTestFailures));
    exit(1);
}

fwrite(STDOUT, "ReferenceIntentService test passed\n");
