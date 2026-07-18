<?php

require_once __DIR__ . '/../services/SqlSelectStructureService.php';

use app\services\SqlSelectStructureService;

function expectStructure(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$trusted = SqlSelectStructureService::analyzeCanonical(
    "SELECT ii.id\nFROM inventory.item__t ii\nJOIN inventory.location__t il\n  ON il.id = ii.effective_location_id"
);
$formatted = SqlSelectStructureService::analyzeCanonical(
    "SELECT item_alias.id\nFROM inventory.item__t AS item_alias\nINNER JOIN inventory.location__t AS location_alias\n  ON ( location_alias.id= item_alias.effective_location_id )\nWHERE item_alias.status = 'Available'\nORDER BY item_alias.id"
);

expectStructure($trusted['tables'] === $formatted['tables'], 'Alias and formatting changes must retain the same resolved tables.');
expectStructure($trusted['joins'] === $formatted['joins'], 'Alias and formatting changes must retain the same semantic relationship.');
expectStructure($trusted['joins'][0]['join_type'] === 'INNER', 'JOIN and INNER JOIN must normalize to INNER.');

$quotedCanonical = SqlSelectStructureService::analyzeCanonical(
    'SELECT "ii"."id" FROM "inventory"."item__t" AS "ii" '
    . 'JOIN "inventory"."location__t" AS "il" ON "il"."id" = "ii"."effective_location_id"'
);
expectStructure($trusted === $quotedCanonical, 'Quote provenance must not change existing canonical table and join analysis.');

$left = SqlSelectStructureService::analyzeCanonical(
    "SELECT ii.id FROM inventory.item__t ii LEFT JOIN inventory.location__t il ON il.id = ii.effective_location_id"
);
expectStructure($left['joins'][0]['join_type'] === 'LEFT', 'LEFT JOIN must remain distinct from INNER JOIN.');

foreach ([
    "SELECT * FROM inventory.item__t ii, inventory.location__t il WHERE il.id = ii.effective_location_id",
    "SELECT * FROM inventory.item__t ii JOIN inventory.location__t il ON il.id = ii.effective_location_id AND il.code = 'x'",
    "SELECT * FROM (SELECT * FROM inventory.item__t) ii",
    "SELECT * FROM inventory.item__t ii RIGHT JOIN inventory.location__t il ON il.id = ii.effective_location_id",
    "SELECT (SELECT h.id FROM inventory.holdings_record__t h LIMIT 1) FROM inventory.item__t ii JOIN inventory.location__t il ON il.id = ii.effective_location_id",
    "SELECT * FROM inventory.item__t ii JOIN inventory.location__t il ON il.id = ii.effective_location_id WHERE EXISTS (SELECT 1 FROM inventory.holdings_record__t h WHERE h.id = ii.holdings_record_id)",
    "SELECT * FROM inventory.item__t ii JOIN inventory.location__t il ON il.id = ii.effective_location_id WHERE ii.id IN (SELECT nested.id FROM (SELECT id FROM inventory.holdings_record__t) nested)",
] as $unsupportedSql) {
    $rejected = false;
    try {
        SqlSelectStructureService::analyzeCanonical($unsupportedSql);
    } catch (\InvalidArgumentException $e) {
        $rejected = true;
    }
    expectStructure($rejected, 'Unsupported canonical SQL structure must fail closed: ' . $unsupportedSql);
}

$references = SqlSelectStructureService::extractTableReferences(
    'SELECT * FROM inventory.item__t ii, users.users__t u WHERE u.id = ii.id'
);
expectStructure(
    $references === ['inventory.item__t', 'users.users__t'],
    'Policy extraction must enumerate comma-separated tables, including blocked tables.'
);

$mixedReferences = SqlSelectStructureService::extractTableReferences(
    'SELECT * FROM inventory.item__t ii JOIN inventory.location__t il ON il.id = ii.effective_location_id, users.users__t u'
);
expectStructure(
    $mixedReferences === ['inventory.item__t', 'inventory.location__t', 'users.users__t'],
    'Policy extraction must continue across explicit joins followed by comma-separated tables.'
);

$onlyReferences = SqlSelectStructureService::extractTableReferences(
    'SELECT * FROM ONLY users.users__t u'
);
expectStructure(
    $onlyReferences === ['users.users__t'],
    'Policy extraction must treat PostgreSQL ONLY as a table-source modifier, not a table name.'
);

$parenthesizedOnlyReferences = SqlSelectStructureService::extractTableReferences(
    'SELECT * FROM ONLY (users.users__t) u'
);
expectStructure(
    $parenthesizedOnlyReferences === ['users.users__t'],
    'Policy extraction must support PostgreSQL parenthesized ONLY table syntax.'
);

$analysisTokens = SqlSelectStructureService::tokenizeForAnalysis(
    "SELECT 'DO SELECT' AS \"DO\", (nested.value) -- SELECT ignored\n"
    . "FROM inventory.item__t nested /* DO ignored */"
);
expectStructure(
    $analysisTokens[0] === ['kind' => 'identifier', 'value' => 'select', 'depth' => 0],
    'Analysis tokens must expose top-level keywords with depth.'
);
expectStructure(
    $analysisTokens[1] === ['kind' => 'string', 'value' => "'DO SELECT'", 'depth' => 0],
    'Keywords inside strings must remain string tokens.'
);
expectStructure(
    $analysisTokens[3] === ['kind' => 'identifier', 'value' => 'do', 'quoted' => true, 'depth' => 0],
    'Quoted identifiers must retain quote provenance rather than becoming keywords.'
);
expectStructure(
    $analysisTokens[6] === ['kind' => 'identifier', 'value' => 'nested', 'depth' => 1]
        && $analysisTokens[8] === ['kind' => 'identifier', 'value' => 'value', 'depth' => 1],
    'Nested analysis tokens must expose their parenthesis depth.'
);
expectStructure(
    count(array_filter($analysisTokens, static function (array $token): bool {
        return $token['value'] === 'ignored';
    })) === 0,
    'Line and block comments must be absent from analysis tokens.'
);

$quotedKeywordTokens = SqlSelectStructureService::tokenizeForAnalysis(
    'SELECT "where", "order", "Ordinary Identifier" FROM inventory.item__t'
);
expectStructure(
    $quotedKeywordTokens[1]['quoted'] === true
        && $quotedKeywordTokens[3]['quoted'] === true
        && $quotedKeywordTokens[5]['quoted'] === true,
    'Quoted keyword and ordinary identifiers must all retain quote provenance.'
);

fwrite(STDOUT, "SqlSelectStructureService test passed\n");
