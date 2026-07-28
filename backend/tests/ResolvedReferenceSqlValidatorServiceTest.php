<?php

require_once __DIR__ . '/../services/ResolvedReferenceSqlValidatorService.php';

use app\services\ResolvedReferenceSqlValidatorService;

function resolvedReferenceAssertValid(string $sql, array $resolvedFilters, string $message): void
{
    try {
        ResolvedReferenceSqlValidatorService::validate($sql, $resolvedFilters);
    } catch (\Throwable $exception) {
        fwrite(STDERR, $message . "\nUnexpected exception: " . $exception->getMessage() . "\n");
        exit(1);
    }
}

function resolvedReferenceAssertMismatch(string $sql, array $resolvedFilters, string $message): void
{
    try {
        ResolvedReferenceSqlValidatorService::validate($sql, $resolvedFilters);
    } catch (\InvalidArgumentException $exception) {
        if (strpos($exception->getMessage(), 'resolved_reference_filter_mismatch') === false) {
            fwrite(STDERR, $message . "\nException did not contain the stable mismatch category.\n");
            exit(1);
        }

        return;
    } catch (\Throwable $exception) {
        fwrite(STDERR, $message . "\nUnexpected exception type: " . get_class($exception) . "\n");
        exit(1);
    }

    fwrite(STDERR, $message . "\nExpected resolved_reference_filter_mismatch, but validation returned normally.\n");
    exit(1);
}

function resolvedReferenceCompleteSql(string $where): string
{
    return <<<'SQL'
SELECT item.id
FROM inventory.item__t item
JOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id
JOIN inventory.location__t loc ON loc.id = item.effective_location_id
JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id
JOIN inventory.loccampus__t camp ON camp.id = lib.campus_id
JOIN inventory.material_type__t mt ON mt.id = item.material_type_id
WHERE
SQL
        . ' ' . $where . "\nLIMIT 100";
}

$resolvedFilters = [
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
];

$validSql = <<<'SQL'
SELECT item.id, instance.title, material_type.name
FROM inventory.item__t item
JOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id
JOIN inventory.instance__t instance ON instance.id = holdings.instance_id
JOIN inventory.material_type__t material_type ON material_type.id = item.material_type_id
JOIN inventory.location__t location ON location.id = item.effective_location_id
JOIN inventory.loclibrary__t library ON library.id = location.library_id
WHERE library.name = 'SC Hillyer Art Library'
  AND material_type.name IN ('Videocassette', 'DVD/Blu-ray')
LIMIT 100
SQL;

resolvedReferenceAssertValid(
    $validSql,
    $resolvedFilters,
    'The exact accepted Task 4 SQL must preserve both resolved filters.'
);

$lowerSql = resolvedReferenceCompleteSql(
    "LOWER(lib.name) = LOWER('SC Hillyer Art Library')"
    . " AND (LOWER(mt.name) = LOWER('Videocassette')"
    . " OR LOWER(mt.name) = LOWER('DVD/Blu-ray'))"
);
resolvedReferenceAssertValid(
    $lowerSql,
    $resolvedFilters,
    'LOWER equality must preserve full canonical values.'
);

$likeSql = resolvedReferenceCompleteSql(
    "lib.name ILIKE 'sc hillyer art library'"
    . " AND mt.name LIKE 'Videocassette'"
    . " AND mt.name ILIKE 'dvd/blu-ray'"
);
resolvedReferenceAssertValid(
    $likeSql,
    $resolvedFilters,
    'Positive LIKE and ILIKE must accept only complete canonical values.'
);

$joinVariantSql = <<<'SQL'
SELECT "item".id
FROM inventory.item__t AS "item"
LEFT OUTER JOIN "inventory"."location__t" AS "loc" ON "loc".id = "item".effective_location_id
INNER JOIN "inventory"."loclibrary__t" "lib" ON "lib".id = "loc".library_id
RIGHT JOIN "inventory"."material_type__t" "mt" ON "mt".id = "item".material_type_id
WHERE "lib"."name" = 'SC Hillyer Art Library'
  AND "mt"."name" IN ('Videocassette', 'DVD/Blu-ray')
SQL;
resolvedReferenceAssertValid(
    $joinVariantSql,
    $resolvedFilters,
    'Quoted aliases, optional AS, and JOIN variants must remain alias-aware.'
);

$fullJoinSql = <<<'SQL'
SELECT lib.id
FROM inventory.loclibrary__t lib
FULL OUTER JOIN inventory.material_type__t mt ON TRUE
WHERE 'SC Hillyer Art Library' = lib.name
  AND mt.name IN ('Videocassette', 'DVD/Blu-ray')
SQL;
resolvedReferenceAssertValid(
    $fullJoinSql,
    $resolvedFilters,
    'FULL OUTER JOIN and literal-first equality must remain alias-aware.'
);

$noAliasSql = <<<'SQL'
SELECT inventory.loclibrary__t.name
FROM inventory.loclibrary__t
CROSS JOIN inventory.material_type__t
WHERE inventory.loclibrary__t.name = 'SC Hillyer Art Library'
  AND inventory.material_type__t.name IN ('Videocassette', 'DVD/Blu-ray')
SQL;
resolvedReferenceAssertValid(
    $noAliasSql,
    $resolvedFilters,
    'Unaliased authoritative tables must be recognized.'
);

$apostropheFilters = [[
    'dimension' => 'library',
    'source_table' => 'inventory.loclibrary__t',
    'column' => 'name',
    'values' => ["SC O'Neill Library"],
    'value_metadata' => ["SC O'Neill Library" => ['campus_name' => 'Smith College']],
    'provenance' => 'explicit_prompt',
    'vocabulary_terms' => [],
]];
$apostropheSql = <<<'SQL'
SELECT "lib".id
FROM "inventory"."loclibrary__t" AS "lib"
WHERE "lib"."name" = 'SC O''Neill Library'
SQL;
resolvedReferenceAssertValid(
    $apostropheSql,
    $apostropheFilters,
    'Doubled SQL apostrophes must unescape to the canonical value.'
);

$libraryAndCampusSql = resolvedReferenceCompleteSql(
    "lib.name = 'SC Hillyer Art Library'"
    . " AND camp.name = 'Smith College'"
    . " AND mt.name IN ('Videocassette', 'DVD/Blu-ray')"
);
resolvedReferenceAssertValid(
    $libraryAndCampusSql,
    $resolvedFilters,
    'Hierarchy predicates matching resolved metadata must be accepted.'
);

$positiveWithNegatedDecoysSql = resolvedReferenceCompleteSql(
    "lib.name = 'SC Hillyer Art Library'"
    . " AND mt.name IN ('Videocassette', 'DVD/Blu-ray')"
    . " AND loc.name <> 'SC Hillyer Art Library'"
    . " AND mt.name NOT LIKE '%Film%'"
);
resolvedReferenceAssertValid(
    $positiveWithNegatedDecoysSql,
    $resolvedFilters,
    'Negated predicates must not create false positive hierarchy values.'
);

$invalidCases = [
    'wrong location' => "loc.name = 'HC DVD'",
    'library on location' => "loc.name = 'SC Hillyer Art Library'",
    'material on location' => "loc.name IN ('Videocassette', 'DVD/Blu-ray')",
    'missing VHS' => "lib.name = 'SC Hillyer Art Library' AND mt.name = 'DVD/Blu-ray'",
    'extra Film after narrowing' => "lib.name = 'SC Hillyer Art Library' AND mt.name IN ('Videocassette', 'DVD/Blu-ray', 'Film')",
    'cross-campus' => "lib.name = 'SC Hillyer Art Library' AND camp.name = 'Hampshire College'",
];
foreach ($invalidCases as $case => $where) {
    resolvedReferenceAssertMismatch(
        resolvedReferenceCompleteSql($where),
        $resolvedFilters,
        'The complete-SQL rejection matrix case must fail: ' . $case
    );
}

$libraryOnlyFilters = [$resolvedFilters[0]];
resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql("loc.name = 'SC Hillyer Art Library'"),
    $libraryOnlyFilters,
    'A library value on a location column must fail independently of other filters.'
);
resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql(
        "lib.name = 'SC Hillyer Art Library' AND camp.name = 'Hampshire College'"
    ),
    $libraryOnlyFilters,
    'Resolved Smith metadata must reject a positive Hampshire campus predicate.'
);
resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql(
        "lib.name IN ('SC Hillyer Art Library', 'SC Neilson Library')"
    ),
    $libraryOnlyFilters,
    'An extra same-dimension value must not broaden a resolved library filter.'
);

$locationFilters = [[
    'dimension' => 'location',
    'source_table' => 'inventory.location__t',
    'column' => 'name',
    'values' => ['HC DVD'],
    'value_metadata' => [
        'HC DVD' => [
            'library_name' => 'HC Harold F. Johnson Library',
            'campus_name' => 'Hampshire College',
        ],
    ],
    'provenance' => 'explicit_prompt',
    'vocabulary_terms' => [],
]];
resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql("lib.name = 'HC DVD'"),
    $locationFilters,
    'An explicitly resolved location value must not be used on a library column.'
);
resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql(
        "loc.name = 'HC DVD' AND lib.name = 'SC Hillyer Art Library'"
    ),
    $locationFilters,
    'Location hierarchy metadata must reject a conflicting positive library.'
);
resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql(
        "loc.name = 'HC DVD' AND lib.name = 'SC Hillyer Art Library'"
    ),
    [$resolvedFilters[0], $locationFilters[0]],
    'Conflicting Smith and Hampshire metadata in resolved hierarchy filters must fail closed.'
);

$materialOnlyFilters = [$resolvedFilters[1]];
resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql("loc.name IN ('Videocassette', 'DVD/Blu-ray')"),
    $materialOnlyFilters,
    'Material values must not be accepted from a location column.'
);

$negationBypasses = [
    "NOT (lib.name = 'SC Hillyer Art Library')"
        . " AND NOT (mt.name IN ('Videocassette', 'DVD/Blu-ray'))",
    "lib.name NOT ILIKE 'SC Hillyer Art Library'"
        . " AND mt.name NOT IN ('Videocassette', 'DVD/Blu-ray')",
    "NOT LOWER(lib.name) = LOWER('SC Hillyer Art Library')"
        . " AND NOT LOWER(mt.name) = LOWER('Videocassette')"
        . " AND NOT LOWER(mt.name) = LOWER('DVD/Blu-ray')",
    "NOT (TRUE AND lib.name = 'SC Hillyer Art Library')"
        . " AND mt.name IN ('Videocassette', 'DVD/Blu-ray')",
    "-- lib.name = 'SC Hillyer Art Library'\n"
        . "mt.name IN ('Videocassette', 'DVD/Blu-ray')",
];
foreach ($negationBypasses as $index => $where) {
    resolvedReferenceAssertMismatch(
        resolvedReferenceCompleteSql($where),
        $resolvedFilters,
        'Negated or commented predicates must not satisfy resolved filters: ' . (string) $index
    );
}

resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql(
        "lib.name ILIKE '%Hillyer%'"
        . " AND mt.name IN ('Videocassette', 'DVD/Blu-ray')"
    ),
    $resolvedFilters,
    'Substring ILIKE must not preserve the full canonical library value.'
);
resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql(
        "lib.name = 'SC Hillyer Art Library'"
        . " AND mt.name LIKE 'Video_as%ette'"
        . " AND mt.name = 'DVD/Blu-ray'"
    ),
    $resolvedFilters,
    'LIKE wildcard characters must not satisfy canonical material values.'
);

$underscoreFilters = [[
    'dimension' => 'library',
    'source_table' => 'inventory.loclibrary__t',
    'column' => 'name',
    'values' => ['SC Under_score Library'],
    'value_metadata' => ['SC Under_score Library' => ['campus_name' => 'Smith College']],
    'provenance' => 'explicit_prompt',
    'vocabulary_terms' => [],
]];
resolvedReferenceAssertMismatch(
    resolvedReferenceCompleteSql("lib.name LIKE 'SC Under_score Library'"),
    $underscoreFilters,
    'LIKE underscore wildcards must not be mistaken for literal canonical characters.'
);

resolvedReferenceAssertMismatch(
    "SELECT 'SC Hillyer Art Library', 'Videocassette', 'DVD/Blu-ray'",
    $resolvedFilters,
    'Canonical literals without authoritative FROM or JOIN tables must fail closed.'
);

fwrite(STDOUT, "ResolvedReferenceSqlValidatorService test passed\n");
