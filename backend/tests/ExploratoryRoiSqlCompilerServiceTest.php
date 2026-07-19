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

$question = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$contract = ExploratorySemanticContractService::build(
    $question,
    'Smith College',
    ExploratoryQueryDefaultsService::resolve($question),
    'unsupported_query_family'
);

$compiled = ExploratoryRoiSqlCompilerService::compile($contract);
compilerAssertSame(true, is_array($compiled), 'The documented default ROI contract should compile deterministically.');
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
