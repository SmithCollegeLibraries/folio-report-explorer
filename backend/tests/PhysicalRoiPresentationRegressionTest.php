<?php

require_once __DIR__ . '/../services/ExploratoryQueryDefaultsService.php';
require_once __DIR__ . '/../services/ExploratorySemanticContractService.php';
require_once __DIR__ . '/../services/ExploratorySqlSemanticValidatorService.php';
require_once __DIR__ . '/../services/HardenedPhysicalRoiSqlCompilerService.php';

use app\services\ExploratoryQueryDefaultsService;
use app\services\ExploratorySemanticContractService;
use app\services\HardenedPhysicalRoiSqlCompilerService;

function presentationAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function presentationAssertContains(string $needle, string $haystack, string $message): void
{
    presentationAssertSame(true, strpos($haystack, $needle) !== false, $message);
}

function presentationAssertNotContains(string $needle, string $haystack, string $message): void
{
    presentationAssertSame(false, strpos($haystack, $needle) !== false, $message);
}

function presentationBuildContract(string $prompt): array
{
    return ExploratorySemanticContractService::build(
        $prompt,
        'Smith College',
        ExploratoryQueryDefaultsService::resolve($prompt),
        'unsupported_query_family'
    );
}

function presentationCompile(string $prompt): ?array
{
    return HardenedPhysicalRoiSqlCompilerService::compile(presentationBuildContract($prompt));
}

function presentationOutputAliases(string $sql): array
{
    preg_match('/\nSELECT (.*?)\nFROM acquisitions_by_instance/s', $sql, $select);
    preg_match_all('/\sAS ([a-z_]+)/', $select[1] ?? '', $aliases);
    return array_merge(['call_number_class'], $aliases[1] ?? []);
}

$prompts = [
    'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.',
    'For the past five years, compare Smith physical purchases and checkouts by primary call-number class and include ROI.',
    'Rank primary call-number classes by physical copies bought in five years, with paid spending, circulation, checkouts per dollar, and cost per checkout.',
];

$contracts = array_map('presentationBuildContract', $prompts);
$compiledReports = array_map('presentationCompile', $prompts);
foreach ($compiledReports as $index => $compiled) {
    presentationAssertSame(true, is_array($compiled), 'Equivalent ROI prompt ' . ($index + 1) . ' must compile.');
}

$baseline = $compiledReports[0];
$baselineContract = $contracts[0];
$expectedAliases = [
    'call_number_class',
    'currency',
    'purchase_count',
    'physical_copies_purchased',
    'distinct_titles',
    'exact_linked_copies',
    'fallback_linked_copies',
    'spend',
    'circulation',
    'checkouts_per_dollar',
    'cost_per_checkout',
    'fallback_percentage',
];

foreach ($compiledReports as $index => $compiled) {
    $promptNumber = $index + 1;
    presentationAssertSame('physical_roi_v2', $compiled['compilerVersion'], "Prompt {$promptNumber} must use the v2 compiler.");
    presentationAssertSame($baselineContract['reportPolicy'], $contracts[$index]['reportPolicy'], "Prompt {$promptNumber} must use the same physical ROI policy.");
    presentationAssertSame($baselineContract['requirements'], $contracts[$index]['requirements'], "Prompt {$promptNumber} must use the same semantic requirements.");
    presentationAssertSame($baselineContract['permittedFilters'], $contracts[$index]['permittedFilters'], "Prompt {$promptNumber} must use the same governed filters.");
    presentationAssertSame($baseline['sql'], $compiled['sql'], "Prompt {$promptNumber} must compile to the same semantic SQL shape.");
    presentationAssertSame($expectedAliases, presentationOutputAliases($compiled['sql']), "Prompt {$promptNumber} must return the stable output aliases.");
    presentationAssertContains("TRIM(acquisition_unit.name) = 'SC'", $compiled['sql'], "Prompt {$promptNumber} must retain Smith acquisitions scope.");
    presentationAssertContains("INTERVAL '5 years'", $compiled['sql'], "Prompt {$promptNumber} must use the five-year window.");
    presentationAssertContains('cost__quantity_physical > 0', $compiled['sql'], "Prompt {$promptNumber} must retain the physical-only cohort.");
    presentationAssertContains('ORDER BY physical_copies_purchased DESC, spend DESC, call_number_class ASC', $compiled['sql'], "Prompt {$promptNumber} must use stable purchase ordering.");
    presentationAssertNotContains("LOWER(material_type.name) = 'dvd'", $compiled['sql'], "Prompt {$promptNumber} must not infer a DVD filter.");
}

$nonRoi = presentationCompile('Show circulation and checkout totals by primary call-number class for the past five years.');
presentationAssertSame(null, $nonRoi, 'A circulation-only prompt must not use the physical ROI compiler.');

foreach (['checkouts per dollar', 'cost per checkout', 'cost per use'] as $roiFormula) {
    $formulaReport = presentationCompile(
        "Compare purchased physical copies, circulation, and {$roiFormula} by primary call-number class."
    );
    presentationAssertSame(true, is_array($formulaReport), "The governed {$roiFormula} formula must activate the ROI compiler.");
    presentationAssertSame('physical_roi_v2', $formulaReport['compilerVersion'], "The governed {$roiFormula} formula must use the v2 compiler.");
}

$missingRoiIntent = presentationCompile(
    'Compare purchased physical copies and circulation by primary call-number class for the past five years.'
);
presentationAssertSame(null, $missingRoiIntent, 'All other cross-domain concepts without ROI intent must not activate the ROI compiler.');

$dvd = presentationCompile('For DVDs, show call numbers purchased most in five years with circulation and ROI.');
presentationAssertSame(true, is_array($dvd), 'An explicit DVD ROI prompt must compile.');
presentationAssertContains("LOWER(material_type.name) = 'dvd'", $dvd['sql'], 'Only an explicit DVD prompt may contain the DVD predicate.');

$disclosures = $baseline['reportDisclosures'] ?? [];
presentationAssertSame(4, count($disclosures), 'The report must contain four plain-language disclosures.');
foreach ($disclosures as $disclosure) {
    presentationAssertSame(true, is_string($disclosure) && trim($disclosure) !== '', 'Every disclosure must be non-empty plain language.');
    presentationAssertSame(
        0,
        preg_match('/\b(?:cte|join grain|schema cache|sql|sqlstate|postgres(?:ql)?|database|exception|error)\b/i', $disclosure),
        'Disclosures must not expose implementation or database-error terminology.'
    );
}

fwrite(STDOUT, "Physical ROI presentation regression test passed\n");
