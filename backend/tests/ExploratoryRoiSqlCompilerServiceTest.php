<?php

require_once __DIR__ . '/../services/ExploratoryQueryDefaultsService.php';
require_once __DIR__ . '/../services/ExploratorySemanticContractService.php';
require_once __DIR__ . '/../services/ExploratorySqlSemanticValidatorService.php';
require_once __DIR__ . '/../services/ExploratoryRoiSqlCompilerService.php';

use app\services\ExploratoryQueryDefaultsService;
use app\services\ExploratoryRoiSqlCompilerService;
use app\services\ExploratorySemanticContractService;
use app\services\ExploratorySqlSemanticValidatorService;

function compilerAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function compilerAssertPhysicalColumnsExist(string $sql): void
{
    $cache = json_decode((string)file_get_contents(__DIR__ . '/../data/column_cache.json'), true);
    $columnsByTable = $cache['columns'] ?? [];
    $subtableCache = json_decode((string)file_get_contents(__DIR__ . '/../data/subtable_cache.json'), true);
    foreach (($subtableCache['subtables'] ?? []) as $table => $definition) {
        $columnsByTable[strtolower($table)] = $definition['columns'] ?? [];
    }

    preg_match_all(
        '/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*)\s+([a-z_][a-z0-9_]*)\b/i',
        $sql,
        $bindings,
        PREG_SET_ORDER
    );

    $tableByAlias = [];
    $physicalTables = [];
    foreach ($bindings as $binding) {
        $table = strtolower($binding[1]);
        $tableByAlias[strtolower($binding[2])] = $table;
        $physicalTables[] = $table;
    }

    preg_match_all('/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $sql, $references, PREG_SET_ORDER);
    $missing = [];
    foreach ($references as $reference) {
        $alias = strtolower($reference[1]);
        $qualifiedReference = $alias . '.' . strtolower($reference[2]);
        if (in_array($qualifiedReference, $physicalTables, true)) {
            continue;
        }
        if (!isset($tableByAlias[$alias])) {
            continue;
        }

        $table = $tableByAlias[$alias];
        $availableColumns = array_column($columnsByTable[$table] ?? [], 'name');
        if (!in_array(strtolower($reference[2]), array_map('strtolower', $availableColumns), true)) {
            $missing[] = $table . '.' . strtolower($reference[2]);
        }
    }

    compilerAssertSame([], array_values(array_unique($missing)), 'Compiled ROI SQL must use only columns present in the discovered schema cache.');
}

$question = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$contract = ExploratorySemanticContractService::build(
    $question,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($question),
    'unsupported_query_family'
);

$compiled = ExploratoryRoiSqlCompilerService::compile($contract);
compilerAssertSame(true, is_array($compiled), 'The documented default ROI contract should compile deterministically.');
compilerAssertPhysicalColumnsExist($compiled['sql']);
compilerAssertSame(
    'validated',
    ExploratorySqlSemanticValidatorService::validate($compiled['sql'], $contract)['status'] ?? null,
    'Compiled ROI SQL must pass every semantic-contract rule.'
);
compilerAssertSame(true, strpos($compiled['sql'], "selected_scope.name = 'Smith College'") !== false, 'Compiled SQL must enforce the selected campus.');
compilerAssertSame(true, strpos($compiled['sql'], 'ORDER BY purchase_count DESC') !== false, 'Compiled SQL must rank purchases descending.');

$alternativeContract = $contract;
$alternativeContract['requirements'][0]['parameters']['value'] = 'invoice_date';
compilerAssertSame(null, ExploratoryRoiSqlCompilerService::compile($alternativeContract), 'Unsupported assumption variants must not be silently compiled with default semantics.');

fwrite(STDOUT, "Exploratory ROI SQL compiler test passed\n");
