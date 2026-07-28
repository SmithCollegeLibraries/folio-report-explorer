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

$ordinaryDomainLanguage = ReferenceResolverService::resolvePromptAgainstReferences(
    'Compare circulation data to call numbers and show return on investment.',
    [
        ['source_table' => 'finance.fund__t', 'source_id' => 'fund-1', 'name' => 'HC Circulation', 'code' => 'HCCIR'],
        ['source_table' => 'finance.fund__t', 'source_id' => 'fund-2', 'name' => 'UM Data', 'code' => 'UMDATA'],
        ['source_table' => 'finance.expense_class__t', 'source_id' => 'expense-1', 'name' => 'UM Data', 'code' => 'UMDATA'],
        ['source_table' => 'inventory.location__t', 'source_id' => 'loc-1', 'name' => 'MH Circulation Equipment', 'code' => 'MHCIRCEQ'],
        ['source_table' => 'inventory.location__t', 'source_id' => 'loc-2', 'name' => 'MH Pratt Circulation Equipment', 'code' => 'MHPCIREQ'],
    ],
    []
);
assertResolverSame(false, $ordinaryDomainLanguage['needsClarification'] ?? null, 'Ordinary circulation-domain language must not be treated as an ambiguous physical location.');
assertResolverSame([], $ordinaryDomainLanguage['guidanceLines'] ?? null, 'Single generic words left after stripping a campus prefix must not activate non-location reference filters.');

$explicitFinanceName = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show spending for the HC Circulation fund.',
    [['source_table' => 'finance.fund__t', 'source_id' => 'fund-1', 'name' => 'HC Circulation', 'code' => 'HCCIR']],
    []
);
assertResolverContains('HC Circulation', implode("\n", $explicitFinanceName['guidanceLines'] ?? []), 'An explicit full finance reference name must still resolve.');

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

$videoReferences = [
    [
        'source_table' => 'inventory.location__t',
        'source_id' => 'hc-dvd',
        'name' => 'HC DVD',
        'code' => 'HCDVD',
        'metadata' => ['library_name' => 'HC Harold F. Johnson Library', 'campus_name' => 'Hampshire College'],
    ],
    [
        'source_table' => 'inventory.loclibrary__t',
        'source_id' => 'sc-hillyer',
        'name' => 'SC Hillyer Art Library',
        'code' => 'SCHIL',
        'metadata' => ['campus_name' => 'Smith College'],
    ],
    ['source_table' => 'inventory.material_type__t', 'source_id' => 'mt-vhs', 'name' => 'Videocassette', 'code' => ''],
    ['source_table' => 'inventory.material_type__t', 'source_id' => 'mt-dvd', 'name' => 'DVD/Blu-ray', 'code' => ''],
    ['source_table' => 'inventory.material_type__t', 'source_id' => 'mt-film', 'name' => 'Film', 'code' => ''],
];

$duplicateIdentityResolution = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show DVDs at Hillyer library.',
    array_merge($videoReferences, [
        $videoReferences[1],
        $videoReferences[3],
    ])
);
assertResolverSame(
    false,
    $duplicateIdentityResolution['needsClarification'] ?? null,
    'Repeated copies of the same authoritative library and canonical material identities must not be ambiguous.'
);
assertResolverSame(
    ['DVD/Blu-ray', 'SC Hillyer Art Library'],
    array_column($duplicateIdentityResolution['resolvedReferences'] ?? [], 'name'),
    'Same-ID typed candidates must deduplicate before named and canonical material cardinality checks.'
);

$duplicateUnknownMaterial = [
    'source_table' => 'inventory.material_type__t',
    'source_id' => 'mt-betamax',
    'name' => 'Betamax',
    'code' => '',
];
$duplicateUnknownResolution = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show Betamax format at Hillyer library.',
    array_merge($videoReferences, [
        $duplicateUnknownMaterial,
        $duplicateUnknownMaterial,
    ])
);
assertResolverSame(
    false,
    $duplicateUnknownResolution['needsClarification'] ?? null,
    'Repeated copies of the same authoritative unknown-material identity must not be ambiguous.'
);
assertResolverSame(
    ['Betamax'],
    $duplicateUnknownResolution['resolvedFilters'][1]['values'] ?? null,
    'Same-ID unknown-material candidates must deduplicate before cardinality checks.'
);

$distinctNamedIdentity = $videoReferences[1];
$distinctNamedIdentity['source_id'] = 'sc-hillyer-distinct';
$ambiguousDistinctNamed = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show DVDs at Hillyer library.',
    array_merge($videoReferences, [$distinctNamedIdentity])
);
assertResolverSame(
    true,
    $ambiguousDistinctNamed['needsClarification'] ?? null,
    'Named candidates with distinct authoritative IDs must remain ambiguous.'
);

$distinctCanonicalIdentity = $videoReferences[3];
$distinctCanonicalIdentity['source_id'] = 'mt-dvd-distinct';
$ambiguousDistinctCanonical = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show DVDs at Hillyer library.',
    array_merge($videoReferences, [$distinctCanonicalIdentity])
);
assertResolverSame(
    true,
    $ambiguousDistinctCanonical['needsClarification'] ?? null,
    'Canonical material candidates with distinct authoritative IDs must remain ambiguous.'
);

$distinctUnknownIdentity = $duplicateUnknownMaterial;
$distinctUnknownIdentity['source_id'] = 'mt-betamax-distinct';
$ambiguousDistinctUnknown = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show Betamax format at Hillyer library.',
    array_merge($videoReferences, [
        $duplicateUnknownMaterial,
        $distinctUnknownIdentity,
    ])
);
assertResolverSame(
    true,
    $ambiguousDistinctUnknown['needsClarification'] ?? null,
    'Unknown-material candidates with distinct authoritative IDs must remain ambiguous.'
);

$repeatedTypedLibrary = ReferenceResolverService::resolvePromptAgainstReferences(
    'Hillyer library and Hillyer library',
    [
        $videoReferences[1],
        [
            'source_table' => 'inventory.location__t',
            'source_id' => 'hillyer-location',
            'name' => 'Hillyer Library',
            'code' => '',
        ],
    ]
);
assertResolverSame(
    ['SC Hillyer Art Library'],
    array_column($repeatedTypedLibrary['resolvedReferences'] ?? [], 'name'),
    'Every repeated typed library span must be consumed before legacy matching can activate a colliding location.'
);
assertResolverSame(
    ['library'],
    array_column($repeatedTypedLibrary['resolvedFilters'] ?? [], 'dimension'),
    'Repeated typed library spans must remain scoped to the authoritative library table.'
);

$unresolvedLibraryWithMaterial = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show DVDs at Riverside library.',
    array_values(array_filter($videoReferences, function (array $reference): bool {
        return ($reference['source_table'] ?? '') === 'inventory.material_type__t';
    }))
);
assertResolverSame(
    ['DVD/Blu-ray'],
    $unresolvedLibraryWithMaterial['resolvedFilters'][0]['values'] ?? null,
    'A resolved material sibling must remain available while a named library intent is unresolved.'
);
assertResolverSame(
    ['Riverside library'],
    array_column($unresolvedLibraryWithMaterial['unresolvedNamedIntents'] ?? [], 'span'),
    'An unresolved typed library intent must survive resolver output so runtime safe probing cannot be skipped.'
);

$riversideLibraryReference = [
    'source_table' => 'inventory.loclibrary__t',
    'source_id' => 'lib-neilson',
    'name' => 'SC Neilson Library',
    'code' => 'SCNLS',
    'metadata' => ['campus_name' => 'Smith College'],
];
$riversideLocationReference = [
    'source_table' => 'inventory.location__t',
    'source_id' => 'loc-neilson-main',
    'name' => 'SC Neilson Main Stacks',
    'code' => 'SCNMAIN',
    'metadata' => [
        'library_name' => 'SC Neilson Library',
        'campus_name' => 'Smith College',
    ],
];

$acceptedTypedLibraryAlias = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show DVDs at Riverside library.',
    array_merge(
        array_values(array_filter($videoReferences, function (array $reference): bool {
            return ($reference['source_table'] ?? '') === 'inventory.material_type__t';
        })),
        [$riversideLibraryReference]
    ),
    [[
        'alias' => 'Riverside',
        'clarification_key' => 'reference_alias.riverside',
        'resolved_filter' => [
            'table' => 'inventory.loclibrary__t',
            'column' => 'name',
            'operator' => '=',
            'value' => 'SC Neilson Library',
            'sourceId' => 'lib-neilson',
        ],
    ]],
    ['reference_alias.riverside']
);
assertResolverSame(
    [],
    $acceptedTypedLibraryAlias['unresolvedNamedIntents'] ?? null,
    'An accepted learned alias must satisfy its typed named scope without triggering a second safe-probe clarification.'
);
assertResolverContains(
    'SC Neilson Library',
    implode("\n", $acceptedTypedLibraryAlias['guidanceLines'] ?? []),
    'Accepted learned-alias guidance must remain available beside typed material guidance.'
);
assertResolverSame(
    ['material_type', 'library'],
    array_column($acceptedTypedLibraryAlias['resolvedFilters'] ?? [], 'dimension'),
    'An accepted learned alias must add an authoritative structured filter beside sibling filters.'
);
assertResolverSame(
    ['SC Neilson Library'],
    $acceptedTypedLibraryAlias['resolvedFilters'][1]['values'] ?? null,
    'The learned-alias filter must use the active canonical row value.'
);
assertResolverSame(
    ['campus_name' => 'Smith College'],
    $acceptedTypedLibraryAlias['resolvedFilters'][1]['value_metadata']['SC Neilson Library'] ?? null,
    'The learned-alias filter must carry current canonical hierarchy metadata.'
);

$acceptedLibraryBesideUnresolvedLocation = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show DVDs at Riverside library and Riverside location.',
    array_merge(
        array_values(array_filter($videoReferences, function (array $reference): bool {
            return ($reference['source_table'] ?? '') === 'inventory.material_type__t';
        })),
        [$riversideLibraryReference]
    ),
    [[
        'alias' => 'Riverside',
        'clarification_key' => 'reference_alias.riverside_library',
        'resolved_filter' => [
            'table' => 'inventory.loclibrary__t',
            'column' => 'name',
            'operator' => '=',
            'value' => 'SC Neilson Library',
            'sourceId' => 'lib-neilson',
        ],
    ]],
    ['reference_alias.riverside_library']
);
assertResolverSame(
    ['location'],
    array_column($acceptedLibraryBesideUnresolvedLocation['unresolvedNamedIntents'] ?? [], 'dimension'),
    'An accepted library alias must not silently satisfy a same-word unresolved location intent.'
);

$acceptedQualifiedLocationAlias = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show items at Riverside location.',
    [$riversideLocationReference],
    [[
        'alias' => 'Riverside location',
        'clarification_key' => 'reference_alias.riverside_location',
        'resolved_filter' => [
            'table' => 'inventory.location__t',
            'column' => 'name',
            'operator' => '=',
            'value' => 'SC Neilson Main Stacks',
            'sourceId' => 'loc-neilson-main',
        ],
    ]],
    ['reference_alias.riverside_location']
);
assertResolverSame(
    [],
    $acceptedQualifiedLocationAlias['unresolvedNamedIntents'] ?? null,
    'An accepted qualified location alias must satisfy its suffix-extracted location intent.'
);
assertResolverSame(
    ['SC Neilson Main Stacks'],
    $acceptedQualifiedLocationAlias['resolvedFilters'][0]['values'] ?? null,
    'A qualified location alias must become an authoritative structured location filter.'
);

$missingCanonicalAlias = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show DVDs at Riverside library.',
    array_values(array_filter($videoReferences, function (array $reference): bool {
        return ($reference['source_table'] ?? '') === 'inventory.material_type__t';
    })),
    [[
        'alias' => 'Riverside',
        'clarification_key' => 'reference_alias.riverside',
        'resolved_filter' => [
            'table' => 'inventory.loclibrary__t',
            'column' => 'name',
            'operator' => '=',
            'value' => 'SC Neilson Library',
            'sourceId' => 'lib-neilson',
        ],
    ]],
    ['reference_alias.riverside']
);
assertResolverSame(true, $missingCanonicalAlias['needsClarification'] ?? null, 'A learned alias with no active canonical row must fail closed.');
assertResolverSame('reference_value_unavailable', $missingCanonicalAlias['routeReason'] ?? null, 'A stale learned alias needs a stable unavailable route reason.');
assertResolverSame(false, strpos($missingCanonicalAlias['question'] ?? '', 'inventory.') !== false, 'A stale learned alias response must not expose schema names.');

$wrongCanonicalIdentityAlias = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show DVDs at Riverside library.',
    array_merge(
        array_values(array_filter($videoReferences, function (array $reference): bool {
            return ($reference['source_table'] ?? '') === 'inventory.material_type__t';
        })),
        [$riversideLibraryReference]
    ),
    [[
        'alias' => 'Riverside',
        'clarification_key' => 'reference_alias.riverside',
        'resolved_filter' => [
            'table' => 'inventory.loclibrary__t',
            'column' => 'name',
            'operator' => '=',
            'value' => 'SC Neilson Library',
            'sourceId' => 'lib-stale',
        ],
    ]],
    ['reference_alias.riverside']
);
assertResolverSame(true, $wrongCanonicalIdentityAlias['needsClarification'] ?? null, 'A learned alias may not rebind by name when its authoritative source ID is stale.');
assertResolverSame('reference_value_unavailable', $wrongCanonicalIdentityAlias['routeReason'] ?? null, 'A stale learned-alias identity must fail with the unavailable category.');

if (!class_exists('ReferenceResolverRuntimeTestCommand')) {
    class ReferenceResolverRuntimeTestCommand
    {
        public function queryAll(): array
        {
            return [];
        }

        public function queryColumn(): array
        {
            return [];
        }
    }
}

if (!class_exists('ReferenceResolverRuntimeTestDb')) {
    class ReferenceResolverRuntimeTestDb
    {
        public function createCommand($sql, array $params = []): ReferenceResolverRuntimeTestCommand
        {
            return new ReferenceResolverRuntimeTestCommand();
        }
    }
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias(string $alias): string
        {
            throw new RuntimeException('Use the resolver bundle fallback path in this test.');
        }

        public static function warning($message, $category = null): void
        {
        }
    }
}

Yii::$app = (object)[
    'db' => new ReferenceResolverRuntimeTestDb(),
    'folioDb' => new ReferenceResolverRuntimeTestDb(),
];
$runtimeUnresolvedLibrary = ReferenceResolverService::resolvePrompt(
    'Show DVDs at Riverside library.'
);
assertResolverSame(
    true,
    $runtimeUnresolvedLibrary['needsClarification'] ?? null,
    'Runtime must safe-probe an unresolved typed library even when the DVD sibling produced guidance.'
);
assertResolverSame(
    ['Riverside'],
    array_column($runtimeUnresolvedLibrary['clarificationItems'] ?? [], 'term'),
    'Runtime safe-probe clarification must retain the unresolved library term.'
);
assertResolverSame(
    ['DVD/Blu-ray'],
    $runtimeUnresolvedLibrary['resolvedFilters'][0]['values'] ?? null,
    'Runtime safe-probe clarification must retain resolved sibling material filters.'
);

$video = ReferenceResolverService::resolvePromptAgainstReferences(
    'Find all of the video formats at Hillyer library. This can be VHS or DVD.',
    $videoReferences
);
assertResolverSame(false, $video['needsClarification'] ?? null, 'Known library and formats must resolve directly.');
assertResolverSame([
    [
        'dimension' => 'library',
        'source_table' => 'inventory.loclibrary__t',
        'column' => 'name',
        'values' => ['SC Hillyer Art Library'],
        'value_metadata' => [
            'SC Hillyer Art Library' => ['campus_name' => 'Smith College'],
        ],
        'provenance' => 'explicit_prompt',
        'vocabulary_terms' => [],
    ],
    [
        'dimension' => 'material_type',
        'source_table' => 'inventory.material_type__t',
        'column' => 'name',
        'values' => ['Videocassette', 'DVD/Blu-ray'],
        'value_metadata' => [
            'Videocassette' => [],
            'DVD/Blu-ray' => [],
        ],
        'provenance' => 'explicit_prompt',
        'vocabulary_terms' => ['vhs', 'dvd'],
    ],
], $video['resolvedFilters'] ?? null, 'Resolver must return table-scoped structured filters.');
assertResolverSame(
    false,
    in_array('HC DVD', array_column($video['resolvedReferences'] ?? [], 'name'), true),
    'DVD vocabulary must not activate a location row.'
);
assertResolverSame(
    false,
    strpos(implode("\n", $video['guidanceLines'] ?? []), 'HC DVD') !== false,
    'DVD vocabulary must not add HC DVD guidance.'
);

$genericVideo = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show video materials at Hillyer library.',
    $videoReferences
);
assertResolverSame(
    ['Videocassette', 'DVD/Blu-ray', 'Film'],
    $genericVideo['resolvedFilters'][1]['values'] ?? null,
    'Generic video must select the complete physical-video group in selector order.'
);
assertResolverSame(
    'documented_default',
    $genericVideo['resolvedFilters'][1]['provenance'] ?? null,
    'Generic video must retain its documented-default provenance.'
);

foreach ([
    'DVDs at Hillyer library.' => ['DVD/Blu-ray'],
    'VHS at Hillyer library.' => ['Videocassette'],
    'Films at Hillyer library.' => ['Film'],
] as $materialPrompt => $expectedNames) {
    $materialResolution = ReferenceResolverService::resolvePromptAgainstReferences(
        $materialPrompt,
        $videoReferences
    );
    assertResolverSame(
        $expectedNames,
        $materialResolution['resolvedFilters'][1]['values'] ?? null,
        'Explicit material vocabulary must narrow the physical-video group.'
    );
}

$allMaterials = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show all materials at Hillyer library.',
    $videoReferences
);
assertResolverSame(
    ['library'],
    array_column($allMaterials['resolvedFilters'] ?? [], 'dimension'),
    'All materials must preserve only the library filter.'
);

$missingCanonical = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show video materials at Hillyer library.',
    array_merge(
        array_values(array_filter($videoReferences, function (array $reference): bool {
            return ($reference['name'] ?? '') !== 'Videocassette';
        })),
        [[
            'source_table' => 'inventory.material_type__t',
            'source_id' => 'mt-similar-vhs',
            'name' => 'Video Cassette',
            'code' => '',
        ]]
    )
);
assertResolverSame(true, $missingCanonical['needsClarification'] ?? null, 'A missing canonical material row must stop resolution.');
assertResolverSame('reference_value_unavailable', $missingCanonical['routeReason'] ?? null, 'Missing canonical rows need a stable unavailable reason.');
assertResolverContains('video format', strtolower($missingCanonical['question'] ?? ''), 'Unavailable material responses must use domain language.');
assertResolverSame(false, strpos($missingCanonical['question'] ?? '', 'inventory.') !== false, 'Unavailable material responses must not expose schema names.');

$hcDvdLocation = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show items in location HC DVD.',
    $videoReferences
);
assertResolverSame([
    [
        'dimension' => 'location',
        'source_table' => 'inventory.location__t',
        'column' => 'name',
        'values' => ['HC DVD'],
        'value_metadata' => [
            'HC DVD' => ['library_name' => 'HC Harold F. Johnson Library', 'campus_name' => 'Hampshire College'],
        ],
        'provenance' => 'explicit_prompt',
        'vocabulary_terms' => [],
    ],
], $hcDvdLocation['resolvedFilters'] ?? null, 'Explicit HC DVD location intent must stay in the location dimension.');
assertResolverSame(
    ['HC DVD'],
    array_column($hcDvdLocation['resolvedReferences'] ?? [], 'name'),
    'An explicit location span must consume overlapping DVD material vocabulary.'
);

$artVideoReferences = array_merge($videoReferences, [[
    'source_table' => 'inventory.location__t',
    'source_id' => 'sc-art-video',
    'name' => 'SC Art Video',
    'code' => 'SCARTV',
    'metadata' => ['library_name' => 'SC Hillyer Art Library', 'campus_name' => 'Smith College'],
]]);
$artVideoLocation = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show items in SC Art Video location.',
    $artVideoReferences
);
assertResolverSame(
    ['SC Art Video'],
    $artVideoLocation['resolvedFilters'][0]['values'] ?? null,
    'An explicit SC Art Video location must resolve only against the location table.'
);
assertResolverSame(
    ['location'],
    array_column($artVideoLocation['resolvedFilters'] ?? [], 'dimension'),
    'Video inside an explicit location span must not add a material filter.'
);

$unqualifiedDvd = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show DVD holdings.',
    $videoReferences
);
assertResolverSame(
    false,
    in_array('HC DVD', array_column($unqualifiedDvd['resolvedReferences'] ?? [], 'name'), true),
    'One-word campus-prefix-free location vocabulary must not activate without location intent.'
);

$unqualifiedLocationRemainders = ReferenceResolverService::resolvePromptAgainstReferences(
    'Compare archives and reference holdings.',
    [
        [
            'source_table' => 'inventory.location__t',
            'source_id' => 'sc-archives',
            'name' => 'SC Archives',
            'code' => 'SCARCH',
        ],
        [
            'source_table' => 'inventory.location__t',
            'source_id' => 'sc-neilson-reference',
            'name' => 'SC Neilson Reference',
            'code' => 'SCNREF',
        ],
    ]
);
assertResolverSame(
    [],
    $unqualifiedLocationRemainders['resolvedReferences'] ?? null,
    'One-word archives and reference remainders must not activate location rows without typed context.'
);

$uniqueUnknownFormat = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show Betamax format at Hillyer library.',
    array_merge($videoReferences, [[
        'source_table' => 'inventory.material_type__t',
        'source_id' => 'mt-betamax',
        'name' => 'Betamax',
        'code' => '',
    ]])
);
assertResolverSame(
    ['Betamax'],
    $uniqueUnknownFormat['resolvedFilters'][1]['values'] ?? null,
    'A uniquely matching unknown qualified format must resolve within the material-type table.'
);
assertResolverSame(
    ['betamax'],
    $uniqueUnknownFormat['resolvedFilters'][1]['vocabulary_terms'] ?? null,
    'Unknown qualified format vocabulary must retain normalized provenance.'
);

$missingUnknownFormat = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show Betamax format at Hillyer library.',
    array_merge($videoReferences, [[
        'source_table' => 'inventory.location__t',
        'source_id' => 'loc-betamax',
        'name' => 'SC Betamax',
        'code' => 'SCBETA',
    ]])
);
assertResolverSame(true, $missingUnknownFormat['needsClarification'] ?? null, 'An unknown qualified format with no responsible material match must clarify.');
assertResolverSame('reference_resolver_ambiguous_material_type', $missingUnknownFormat['routeReason'] ?? null, 'Unknown-format clarification needs a stable route reason.');
assertResolverSame([], $missingUnknownFormat['clarificationItems'][0]['options'] ?? null, 'Unknown-format no-match clarification must not invent options.');

$ambiguousUnknownFormat = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show Betamax format at Hillyer library.',
    array_merge($videoReferences, [
        [
            'source_table' => 'inventory.material_type__t',
            'source_id' => 'mt-betamax-standard',
            'name' => 'Betamax Standard',
            'code' => '',
        ],
        [
            'source_table' => 'inventory.material_type__t',
            'source_id' => 'mt-betamax-professional',
            'name' => 'Professional Betamax',
            'code' => '',
        ],
    ])
);
assertResolverSame(true, $ambiguousUnknownFormat['needsClarification'] ?? null, 'Multiple responsible unknown-format matches must clarify.');
assertResolverSame(
    ['Betamax Standard', 'Professional Betamax'],
    array_column($ambiguousUnknownFormat['clarificationItems'][0]['options'] ?? [], 'label'),
    'Unknown-format clarification options must come only from the material-type table in source order.'
);

$coordinatedLegacyLocations = ReferenceResolverService::resolvePromptAgainstReferences(
    'Show me all of the items in josten treasure and treasure folio.',
    [
        [
            'source_table' => 'inventory.location__t',
            'source_id' => 'loc-josten-treasure',
            'name' => 'SC Josten Treasure',
            'code' => 'SJTR',
            'search_tokens' => ['josten', 'sc', 'sjtr', 'treasure'],
        ],
        [
            'source_table' => 'inventory.location__t',
            'source_id' => 'loc-josten-treasure-folio',
            'name' => 'SC Josten Treasure Folio',
            'code' => 'SJTF',
            'search_tokens' => ['folio', 'josten', 'sc', 'sjtf', 'treasure'],
        ],
    ]
);
assertResolverSame(
    ['SC Josten Treasure', 'SC Josten Treasure Folio'],
    array_column($coordinatedLegacyLocations['resolvedReferences'] ?? [], 'name'),
    'Legacy multi-token location matching must retain coordinated Josten and Treasure Folio behavior.'
);

// "title", "barcode", and similar words name report outputs far more often than
// they name a reference row, so they must not become filters alongside a real
// reference match.
$outputWordReferences = array_merge($videoReferences, [
    [
        'source_table' => 'inventory.call_number_type__t',
        'source_id' => 'cnt-title',
        'name' => 'Title',
        'code' => '',
    ],
]);
$outputWordResolution = ReferenceResolverService::resolvePromptAgainstReferences(
    'show me all of the vhs and dvds in hillyer library. include the title, call number, barcode, and location',
    $outputWordReferences
);
assertResolverSame(
    false,
    in_array('Title', array_column($outputWordResolution['resolvedReferences'] ?? [], 'name'), true),
    'A requested output column named "title" must not resolve to a call number type reference.'
);
assertResolverSame(
    false,
    strpos(implode("\n", $outputWordResolution['guidanceLines'] ?? []), 'call_number_type') !== false,
    'A requested output column must not add a call number type filter instruction.'
);
assertResolverSame(
    ['SC Hillyer Art Library'],
    $outputWordResolution['resolvedFilters'][0]['values'] ?? null,
    'Suppressing an output-word reference must leave the real library filter intact.'
);

fwrite(STDOUT, "ReferenceResolverService test passed\n");
