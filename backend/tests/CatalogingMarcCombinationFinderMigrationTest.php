<?php

require_once __DIR__ . '/support/CatalogingMarcCombinationFinderFixture.php';

$failures = [];

function marcCombinationMigrationCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$migrationPath = __DIR__ . '/../../mysql/migrations/068_marc_field_combination_finder.sql';
$migration = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
marcCombinationMigrationCheck($migration !== '', 'Migration 068 must exist at its reserved filename.');
marcCombinationMigrationCheck(strpos($migration, 'START TRANSACTION;') === 0, 'Migration 068 must start a transaction.');
marcCombinationMigrationCheck(strpos($migration, 'COMMIT;') !== false, 'Migration 068 must commit its transaction.');
foreach (['DROP ', 'RENAME ', 'TRUNCATE '] as $forbidden) {
    marcCombinationMigrationCheck(stripos($migration, $forbidden) === false, 'Migration 068 must not contain ' . trim($forbidden) . '.');
}
foreach ([
    'INSERT INTO `report_templates`',
    'SELECT',
    'FROM DUAL',
    'WHERE NOT EXISTS',
    "'marc-field-combination-finder'",
    "'MARC Field Combination Finder'",
    "'cataloging'",
    "'folio'",
    '100000',
    'LIMIT 100001',
    '"Instance UUID"',
    '{{location_from}}',
    '{{active_condition_predicates}}',
    '{{active_condition_columns}}',
    'ON DUPLICATE KEY UPDATE',
] as $forbidden) {
    $present = strpos($migration, $forbidden) !== false;
    if ($forbidden === 'ON DUPLICATE KEY UPDATE') {
        marcCombinationMigrationCheck(!$present, 'Migration 068 must never overwrite an existing report row.');
    } elseif ($forbidden === '100000') {
        marcCombinationMigrationCheck(strpos($migration, '  100000,') !== false, 'The report default_limit must be exactly 100000.');
    } else {
        marcCombinationMigrationCheck($present, 'Migration 068 must contain the reviewed seed contract item: ' . $forbidden . '.');
    }
}

$values = marcCombinationFixtureSqlValues($migration);
marcCombinationMigrationCheck(count($values) === 12, 'Migration 068 must insert one complete report_templates definition.');
if (count($values) === 12) {
    marcCombinationMigrationCheck($values[0] === 'marc-field-combination-finder', 'The report slug must be canonical.');
    marcCombinationMigrationCheck($values[1] === 'MARC Field Combination Finder', 'The report name must be canonical.');
    marcCombinationMigrationCheck($values[4] === 'cataloging', 'The report category must be cataloging.');
    marcCombinationMigrationCheck($values[7] === 'folio', 'The report data_source must be folio.');
    marcCombinationMigrationCheck((int) $values[9] === 100000, 'The report default_limit must be 100000.');

    $sqlTemplate = $values[5];
    marcCombinationMigrationCheck(
        strpos($sqlTemplate, '{{marc_table_') === false,
        'The report template must not contain fixed per-slot MARC table tokens.'
    );
    marcCombinationMigrationCheck(
        strpos($sqlTemplate, 'condition_results') === false,
        'The report template must not aggregate all target instances in a joined condition_results CTE.'
    );
    preg_match('/\nSELECT\n(.*?)\nFROM matching_instances/s', $sqlTemplate, $finalSelectMatch);
    $finalSelectAliases = [];
    if (isset($finalSelectMatch[1])) {
        preg_match_all('/\bAS\s+"([^"]+)"/', $finalSelectMatch[1], $aliasMatches);
        $finalSelectAliases = $aliasMatches[1] ?? [];
    }
    foreach (['Instance UUID', 'Instance HRID', 'Title', 'Selected Location(s)', 'Location Basis', 'Match'] as $alias) {
        marcCombinationMigrationCheck(
            in_array($alias, $finalSelectAliases, true),
            'The final report SELECT must project the "' . $alias . '" output alias.'
        );
    }

    $parameters = json_decode($values[6], true);
    marcCombinationMigrationCheck(is_array($parameters), 'The 43 parameter definitions must be valid JSON.');
    if (is_array($parameters)) {
        $names = array_column($parameters, 'name');
        marcCombinationMigrationCheck($names === marcCombinationFixtureParameterNames(), 'The report must declare locationIds, locationBasis, combine, and all 40 cN_ fields in order.');
        marcCombinationMigrationCheck(count($parameters) === 43, 'The report must declare exactly 43 parameter definitions.');
        foreach ($parameters as $parameter) {
            marcCombinationMigrationCheck(
                is_array($parameter)
                    && is_string($parameter['default'] ?? null)
                    && is_string($parameter['name'] ?? null)
                    && is_string($parameter['type'] ?? null),
                'Every parameter name, type, and default must be a string.'
            );
        }
        $required = [];
        foreach ($parameters as $parameter) {
            if (!empty($parameter['required'])) {
                $required[] = $parameter['name'];
            }
        }
        marcCombinationMigrationCheck($required === ['locationIds', 'locationBasis', 'combine', 'c1_marcTag'], 'Only locationIds, locationBasis, combine, and c1_marcTag may be declared required.');
        $locations = $parameters[0] ?? [];
        marcCombinationMigrationCheck(($locations['type'] ?? null) === 'multiselect', 'locationIds must be a multiselect.');
        marcCombinationMigrationCheck((int) ($locations['max_selections'] ?? 0) === 100, 'locationIds must keep the 100-location maximum.');
        marcCombinationMigrationCheck(($locations['groupSelect'] ?? null) === true, 'locationIds must support grouped selection.');
        $expectedLocationOptions = "SELECT loc.id AS value, COALESCE(campus.name || ' — ', '') || COALESCE(lib.name || ' — ', '') || loc.name || COALESCE(' [' || loc.code || ']', '') AS label FROM inventory.location__t loc LEFT JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id LEFT JOIN inventory.loccampus__t campus ON campus.id = lib.campus_id WHERE COALESCE(loc.is_active, true) ORDER BY campus.name, lib.name, loc.name, loc.code";
        marcCombinationMigrationCheck(($locations['options_sql'] ?? null) === $expectedLocationOptions, 'locationIds must retain the MARC finder grouped location options query.');
        marcCombinationMigrationCheck(($parameters[1]['default'] ?? null) === 'effective_item', 'locationBasis defaults to effective_item.');
        marcCombinationMigrationCheck(($parameters[1]['options_sql'] ?? null) === "SELECT value, label FROM (VALUES ('effective_item', 'Effective item'), ('permanent_item', 'Permanent item')) AS basis(value, label)", 'locationBasis must offer only the two item location bases.');
        marcCombinationMigrationCheck(($parameters[2]['default'] ?? null) === 'all', 'combine defaults to all (AND).');
        marcCombinationMigrationCheck(array_column($parameters[2]['options'] ?? [], 'value') === ['all', 'any'], 'combine supports all (AND) and any (OR).');
        $parameterByName = [];
        foreach ($parameters as $parameter) {
            $parameterByName[$parameter['name']] = $parameter;
        }
        for ($slot = 1; $slot <= 5; $slot++) {
            $tag = $parameterByName['c' . $slot . '_marcTag'] ?? [];
            marcCombinationMigrationCheck(($tag['default'] ?? null) === '', 'Condition ' . $slot . ' starts inactive with an empty tag.');
            $requiredSlot = $slot === 1;
            marcCombinationMigrationCheck((bool) ($tag['required'] ?? false) === $requiredSlot, 'Only condition 1 MARC tag is declared required.');
            foreach ([
                'occurrenceCondition' => 'has',
                'firstIndicator' => 'any',
                'secondIndicator' => 'any',
                'subfieldCode' => '',
                'contentRule' => 'any',
                'searchValue' => '',
                'caseExact' => 'false',
            ] as $field => $default) {
                $definition = $parameterByName['c' . $slot . '_' . $field] ?? [];
                marcCombinationMigrationCheck(($definition['default'] ?? null) === $default, 'Condition ' . $slot . ' ' . $field . ' retains its MARC finder default.');
                marcCombinationMigrationCheck(empty($definition['required']), 'Condition ' . $slot . ' ' . $field . ' is enforced only when its slot is active.');
            }
        }
        $execution = json_decode($values[8], true);
        marcCombinationMigrationCheck(is_array($execution) && ($execution['public_row_cap'] ?? null) === 100000, 'The execution config must enforce the 100,000-row public cap.');
        marcCombinationMigrationCheck(is_array($execution) && ($execution['fetch_row_limit'] ?? null) === 100001, 'The execution config must fetch one sentinel row.');
        marcCombinationMigrationCheck(is_array($execution) && ($execution['preserve_export_order'] ?? null) === true, 'The execution config must preserve the report ordering.');
        marcCombinationMigrationCheck(is_array($execution) && ($execution['identifier_export']['source_column'] ?? null) === 'Instance UUID', 'Identifier export must use the Instance UUID column.');
        marcCombinationMigrationCheck(is_array($execution) && ($execution['identifier_export']['header'] ?? null) === 'UUID', 'Identifier export uses the UUID header.');
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " MARC combination migration contract checks failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

echo "PASS: MARC combination finder migration contract\n";
