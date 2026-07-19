<?php

require_once __DIR__ . '/../services/ExploratoryQueryDefaultsService.php';
require_once __DIR__ . '/../services/ExploratorySemanticContractService.php';
require_once __DIR__ . '/../services/ExploratorySqlSemanticValidatorService.php';
require_once __DIR__ . '/../services/HardenedPhysicalRoiSqlCompilerService.php';

use app\services\ExploratoryQueryDefaultsService;
use app\services\ExploratorySemanticContractService;
use app\services\ExploratorySqlSemanticValidatorService;
use app\services\HardenedPhysicalRoiSqlCompilerService;

function compilerAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function compilerAssertContains(string $needle, string $haystack, string $message): void
{
    compilerAssertSame(true, strpos($haystack, $needle) !== false, $message);
}

function compilerAssertNotContains(string $needle, string $haystack, string $message): void
{
    compilerAssertSame(false, strpos($haystack, $needle) !== false, $message);
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
        if (in_array($alias . '.' . strtolower($reference[2]), $physicalTables, true)
            || !isset($tableByAlias[$alias])) {
            continue;
        }

        $table = $tableByAlias[$alias];
        $availableColumns = array_map('strtolower', array_column($columnsByTable[$table] ?? [], 'name'));
        if (!in_array(strtolower($reference[2]), $availableColumns, true)) {
            $missing[] = $table . '.' . strtolower($reference[2]);
        }
    }

    compilerAssertSame([], array_values(array_unique($missing)), 'Compiled ROI SQL must use discovered physical columns only.');
}

function buildPhysicalRoiContract(string $question): array
{
    return ExploratorySemanticContractService::build(
        $question,
        'Smith College',
        ExploratoryQueryDefaultsService::resolve($question),
        'unsupported_query_family'
    );
}

$question = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$contract = buildPhysicalRoiContract($question);
$compiled = HardenedPhysicalRoiSqlCompilerService::compile($contract);

compilerAssertSame(true, is_array($compiled), 'The documented physical ROI contract must compile.');
compilerAssertSame('physical_roi_v2', $compiled['compilerVersion'] ?? null, 'Compiler version must be disclosed.');
compilerAssertSame('folio', $compiled['dataSource'] ?? null, 'The compiler must disclose its FOLIO source.');
compilerAssertContains('orders.pieces__t', $compiled['sql'], 'Exact receiving linkage is required.');
compilerAssertContains('purchase_order_line_identifier', $compiled['sql'], 'Direct item PO-line linkage is required.');
compilerAssertContains("TRIM(acquisition_unit.name) = 'SC'", $compiled['sql'], 'Smith acquisitions are required.');
compilerAssertContains('cost__quantity_physical > 0', $compiled['sql'], 'Electronic-only lines must be excluded.');
compilerAssertNotContains("LOWER(material_type.name) = 'book'", $compiled['sql'], 'Generic ROI must not force books.');
compilerAssertContains('COUNT(DISTINCT audit_loan.loan__id)', $compiled['sql'], 'Distinct loans must be counted.');
compilerAssertContains('audit_loan.loan__loan_date', $compiled['sql'], 'Checkout date must use loan date.');
compilerAssertContains('ROW_NUMBER() OVER', $compiled['sql'], 'Dominant class must be deterministic.');
compilerAssertContains("ELSE 'Local/Other'", $compiled['sql'], 'Arbitrary text must not become a class.');
compilerAssertContains("'Unclassified'", $compiled['sql'], 'Blank call numbers need a stable class.');
compilerAssertContains('physical_copies_purchased', $compiled['sql'], 'Physical copy output is required.');
compilerAssertContains('distinct_titles', $compiled['sql'], 'Distinct title output is required.');
compilerAssertContains('fallback_percentage', $compiled['sql'], 'Linkage coverage is required.');
compilerAssertPhysicalColumnsExist($compiled['sql']);
compilerAssertSame(
    'validated',
    ExploratorySqlSemanticValidatorService::validate($compiled['sql'], $contract)['status'] ?? null,
    'Compiled physical ROI SQL must pass semantic validation.'
);

$dvdQuestion = 'Show me which DVD call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$dvdContract = buildPhysicalRoiContract($dvdQuestion);
$dvdCompiled = HardenedPhysicalRoiSqlCompilerService::compile($dvdContract);
compilerAssertSame(true, is_array($dvdCompiled), 'The explicitly requested DVD report must compile.');
compilerAssertContains("LOWER(material_type.name) = 'dvd'", $dvdCompiled['sql'], 'DVD reports must enforce the requested material type.');
compilerAssertNotContains("LOWER(material_type.name) = 'dvd'", $compiled['sql'], 'Generic ROI must not add a DVD predicate.');
compilerAssertSame(
    'validated',
    ExploratorySqlSemanticValidatorService::validate($dvdCompiled['sql'], $dvdContract)['status'] ?? null,
    'Compiled DVD ROI SQL must pass semantic validation.'
);

$supportedDefaults = [
    'purchase_date_basis',
    'investment_cost_basis',
    'circulation_window',
    'call_number_grouping',
    'roi_formula',
];
foreach ($supportedDefaults as $key) {
    $variant = $contract;
    foreach ($variant['requirements'] as &$requirement) {
        if (($requirement['key'] ?? null) === $key) {
            $requirement['parameters']['value'] = 'unsupported_variant';
        }
    }
    unset($requirement);
    compilerAssertSame(null, HardenedPhysicalRoiSqlCompilerService::compile($variant), "Unsupported {$key} variants must return null.");
}

$notApplicable = $contract;
$notApplicable['applicable'] = false;
compilerAssertSame(null, HardenedPhysicalRoiSqlCompilerService::compile($notApplicable), 'Non-applicable contracts must return null.');

fwrite(STDOUT, "Hardened physical ROI SQL compiler test passed\n");
