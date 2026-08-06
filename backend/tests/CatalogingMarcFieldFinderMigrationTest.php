<?php

function finderMigrationFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function finderMigrationContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        finderMigrationFail($message . "\nMissing: {$needle}");
    }
}

function finderMigrationNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        finderMigrationFail($message . "\nForbidden: {$needle}");
    }
}

function finderMigrationSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        finderMigrationFail($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

$migration = (string) file_get_contents(__DIR__ . '/../../mysql/migrations/043_cataloging_marc_field_finder.sql');

finderMigrationSame(1, substr_count($migration, '{{location_from}}'), 'Finder SQL needs one location token.');
finderMigrationSame(2, substr_count($migration, '{{marc_table}}'), 'Finder SQL needs two occurrences of the same MARC table token.');
finderMigrationContains("'marc-field-indicator-content-finder'", $migration, 'The approved slug must be seeded.');
finderMigrationContains("instance.source = ''MARC''", $migration, 'Only MARC Inventory instances qualify.');
finderMigrationContains('marc_row.instance_id = target_instances.instance_uuid', $migration, 'MARC access must join UUID to UUID.');
finderMigrationContains('marc_row.ord AS "Field Occurrence"', $migration, 'Occurrence must use ord.');
finderMigrationNotContains('marc_row.line AS "Field Occurrence"', $migration, 'Occurrence must not use line.');
finderMigrationContains('CHR(92)', $migration, 'Backslash indicators must normalize as blank.');
finderMigrationContains('LIMIT 100001', $migration, 'The sentinel must be static.');
finderMigrationNotContains('folio_source_record.marctab', $migration, 'The union view is forbidden.');
finderMigrationNotContains('parsed_record__content', $migration, 'Full SRS JSON is forbidden.');

if (preg_match("/\\n  '(\\[.*?\\])',\\n  'folio',/s", $migration, $matches) !== 1) {
    finderMigrationFail('Could not extract finder parameter JSON.');
}
$parameters = json_decode(str_replace("''", "'", $matches[1]), true);
if (!is_array($parameters)) {
    finderMigrationFail('Finder parameter JSON must decode.');
}
$names = array_map(static fn (array $parameter): ?string => $parameter['name'] ?? null, $parameters);
$expectedNames = [
    'locationIds', 'locationBasis', 'marcTag', 'occurrenceCondition',
    'firstIndicator', 'secondIndicator', 'subfieldCode', 'contentRule',
    'searchValue', 'caseExact',
];
finderMigrationSame($expectedNames, $names, 'Finder parameters must declare the exact ordered names.');
foreach ($names as $name) {
    foreach ($names as $otherName) {
        if ($name !== $otherName && strpos((string) $name, (string) $otherName) === 0) {
            finderMigrationFail("Finder parameter names must not prefix-collide: {$name} / {$otherName}");
        }
    }
}

fwrite(STDOUT, "Cataloging MARC field finder migration contract test passed\n");
