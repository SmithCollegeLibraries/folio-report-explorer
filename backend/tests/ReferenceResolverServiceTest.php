<?php

$servicePath = __DIR__ . '/../services/ReferenceResolverService.php';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "ReferenceResolverService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\ReferenceResolverService;

function assertResolverSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertResolverContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

$candidate = ReferenceResolverService::classifyDiscoveryCandidate([
    'schema' => 'inventory',
    'table' => 'material_type__t',
    'estimated_rows' => 25,
    'total_bytes' => 8192,
    'columns' => ['id', 'name', 'code'],
]);

assertResolverSame('cacheable_reference', $candidate['classification'] ?? null, 'Small lookup table with name/code should be cacheable.');

$large = ReferenceResolverService::classifyDiscoveryCandidate([
    'schema' => 'inventory',
    'table' => 'item__t',
    'estimated_rows' => 12000000,
    'total_bytes' => 2000000000,
    'columns' => ['id', 'barcode', 'effective_location_id'],
]);

assertResolverSame('do_not_cache', $large['classification'] ?? null, 'Large item table should never be cached by discovery.');

$blocked = ReferenceResolverService::classifyDiscoveryCandidate([
    'schema' => 'users',
    'table' => 'users__t',
    'estimated_rows' => 10,
    'total_bytes' => 4096,
    'columns' => ['id', 'username', 'email'],
]);

assertResolverSame('do_not_cache', $blocked['classification'] ?? null, 'Blocked patron/user table should not be cacheable even when small.');

$references = [
    [
        'source_table' => 'inventory.location__t',
        'source_id' => 'loc-1',
        'name' => 'SC Neilson Reference',
        'code' => 'SCNREF',
        'metadata' => ['library_name' => 'SC Neilson Library', 'campus_name' => 'Smith College'],
    ],
    [
        'source_table' => 'inventory.loclibrary__t',
        'source_id' => 'lib-1',
        'name' => 'SC Neilson Library',
        'code' => 'SCNLS',
        'metadata' => ['campus_name' => 'Smith College'],
    ],
];

$resolved = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show items in Neilson Reference',
    $references,
    []
);

assertResolverSame(false, $resolved['needsClarification'] ?? null, 'Exact local reference should not ask for clarification.');
assertResolverContains('inventory.location__t.name', implode("\n", $resolved['guidanceLines'] ?? []), 'Resolved guidance should name exact table/column target.');
assertResolverContains('SC Neilson Reference', implode("\n", $resolved['guidanceLines'] ?? []), 'Resolved guidance should include exact source value.');

$specific = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show items with contributor type author and instance note type General note',
    [
        ['source_table' => 'inventory.contributor_type__t', 'source_id' => 'aut', 'name' => 'Author', 'code' => 'aut'],
        ['source_table' => 'inventory.contributor_type__t', 'source_id' => 'ctb', 'name' => 'Contributor', 'code' => 'ctb'],
        ['source_table' => 'inventory.instance_note_type__t', 'source_id' => 'general', 'name' => 'General note', 'code' => ''],
        ['source_table' => 'inventory.item_note_type__t', 'source_id' => 'note', 'name' => 'Note', 'code' => ''],
    ],
    []
);
$specificGuidance = implode("\n", $specific['guidanceLines'] ?? []);
assertResolverContains('Author', $specificGuidance, 'Specific contributor type should be retained.');
assertResolverContains('General note', $specificGuidance, 'Specific note type should be retained.');
if (strpos($specificGuidance, "name = 'Contributor'") !== false || strpos($specificGuidance, "name = 'Note'") !== false) {
    fwrite(STDERR, "Generic reference matches should be suppressed when specific matches are available.\nActual guidance:\n{$specificGuidance}\n");
    exit(1);
}

$ambiguous = ReferenceResolverService::resolvePromptAgainstReferences(
    'Compare MRBC and Josten collection holdings',
    [],
    [
        [
            'alias' => 'MRBC',
            'clarification_key' => 'location_alias.mrbc',
            'options' => [
                ['id' => 'rare', 'label' => 'SC Rare Book Collection'],
                ['id' => 'rare_ref', 'label' => 'SC Rare Book Collection Reference'],
            ],
        ],
        [
            'alias' => 'Josten collection',
            'clarification_key' => 'collection_alias.josten',
            'options' => [
                ['id' => 'josten_library', 'label' => 'SC Josten Library'],
                ['id' => 'notes', 'label' => 'Search notes for Josten collection'],
            ],
        ],
    ]
);

assertResolverSame(true, $ambiguous['needsClarification'] ?? null, 'Multiple ambiguous local terms should return one batched clarification.');
assertResolverSame(2, count($ambiguous['clarificationItems'] ?? []), 'Batched clarification should include one item per ambiguous term.');
assertResolverSame('batch_local_reference_resolution', $ambiguous['clarificationType'] ?? null, 'Batched resolver clarification should use a stable type.');

$probeTerms = ReferenceResolverService::extractSafeProbeTerms('Find me all of the items in the Riverside collection.');
assertResolverSame('Riverside', $probeTerms[0]['term'] ?? null, 'Safe probe extraction should capture the local collection term.');
assertResolverSame('collection', $probeTerms[0]['trigger'] ?? null, 'Safe probe extraction should retain the trigger word.');

$namedTermClarification = ReferenceResolverService::buildSafeProbeClarificationFromOptions(
    'Generate a list of all titles and instance numbers for the Riverside collection.',
    [
        [
            'term' => 'Riverside',
            'trigger' => 'collection',
            'options' => [
                [
                    'id' => 'inventory_contributor_name_riverside',
                    'label' => 'Search inventory.contributor__t.name for "Riverside"',
                    'description' => 'Riverside Press',
                    'resolvedFilter' => [
                        'table' => 'inventory.contributor__t',
                        'column' => 'name',
                        'operator' => 'ILIKE',
                        'value' => '%Riverside%',
                    ],
                ],
            ],
        ],
    ]
);

assertResolverSame(true, $namedTermClarification['needsClarification'] ?? null, 'Named collection term found in another approved context should ask before generating SQL.');
assertResolverSame('no_match', $namedTermClarification['resolverTrace'][0]['status'] ?? null, 'Safe probe clarification should include visible resolver trace entries.');
assertResolverContains('locations, libraries, campuses, funds, material types', $namedTermClarification['resolverTrace'][0]['label'] ?? '', 'Resolver trace should explain checked lookup areas in user-facing language.');
assertResolverSame('Found possible match in contributor/author fields', $namedTermClarification['resolverTrace'][1]['label'] ?? '', 'Resolver trace should describe bibliographic search areas before table names.');
assertResolverSame('inventory.contributor__t.name', $namedTermClarification['resolverTrace'][1]['technicalDetail'] ?? '', 'Resolver trace should keep exact table/column as secondary detail.');
assertResolverContains('contributor/author fields', $namedTermClarification['clarificationItems'][0]['options'][0]['label'] ?? '', 'Clarification should expose approved probe options in user-facing language.');
assertResolverSame('Riverside Press', $namedTermClarification['clarificationItems'][0]['options'][0]['description'] ?? '', 'Resolver should not append hard-coded bibliographic meaning to probe options.');

$unresolvedClarification = ReferenceResolverService::buildSafeProbeClarificationFromOptions(
    'Generate a list of all titles and instance numbers for the Riverside collection.',
    [
        [
            'term' => 'Riverside',
            'trigger' => 'collection',
            'options' => [],
        ],
    ]
);

assertResolverSame(true, $unresolvedClarification['needsClarification'] ?? null, 'Unresolved named collection terms should stop for clarification even when probes find no match.');
assertResolverSame(0, count($unresolvedClarification['clarificationItems'][0]['options'] ?? []), 'No-match clarification should not invent candidate options.');
assertResolverContains('could not find', strtolower($unresolvedClarification['clarificationItems'][0]['question'] ?? ''), 'No-match clarification should explain that the term was not found.');

$acceptedSafeProbe = ReferenceResolverService::buildSafeProbeClarificationFromOptions(
    "Generate a list of all titles and instance numbers for the Riverside collection.\n\nClarifications:\n- Riverside: Search inventory.instance__t__contributors.contributors__name for Riverside.",
    [
        [
            'term' => 'Riverside',
            'trigger' => 'collection',
            'options' => [
                [
                    'id' => 'inventory_instance_t_contributors_name_riverside',
                    'label' => 'Search contributor/author fields for "Riverside"',
                    'description' => 'Riverside Press',
                    'resolvedFilter' => [
                        'table' => 'inventory.instance__t__contributors',
                        'column' => 'contributors__name',
                        'operator' => 'ILIKE',
                        'value' => '%Riverside%',
                    ],
                ],
            ],
        ],
    ]
);

assertResolverSame(false, $acceptedSafeProbe['needsClarification'] ?? null, 'Accepted safe-probe clarification should not ask the same question again.');
assertResolverContains('inventory.instance__t__contributors.contributors__name', implode("\n", $acceptedSafeProbe['guidanceLines'] ?? []), 'Accepted safe-probe clarification should become SQL guidance.');

fwrite(STDOUT, "ReferenceResolverService test passed\n");
