<?php

require_once __DIR__ . '/../services/AskResponseContractService.php';

use app\services\AskResponseContractService;

function askResponseAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n"
        );
        exit(1);
    }
}

$canonical = AskResponseContractService::normalizeMode([
    'route' => 'builder_intent',
    'routeReason' => 'family_contract_supported:inventory_collection_age',
    'sql' => 'SELECT 1',
]);
askResponseAssertSame('canonical', $canonical['mode'], 'Supported family-contract results should be canonical.');

$exploratory = AskResponseContractService::normalizeMode([
    'route' => 'exploratory_legacy_freeform',
    'mode' => 'exploratory',
]);
askResponseAssertSame('exploratory', $exploratory['mode'], 'Exploratory results should retain their mode.');

$user = AskResponseContractService::toUserResponse([
    'mode' => 'exploratory',
    'validationSummary' => [
        'status' => 'exhausted',
        'failureCategory' => 'missing_table',
        'validatorStage' => 'semantic_conformance',
    ],
    'unmetRequirements' => [
        ['key' => 'campus_scope', 'label' => 'The report uses the requested campus scope.'],
    ],
]);
askResponseAssertSame(false, isset($user['validationSummary']['failureCategory']), 'User responses should omit failure categories.');
askResponseAssertSame(false, isset($user['validationSummary']['validatorStage']), 'User responses should omit validator stages.');
askResponseAssertSame(false, isset($user['unmetRequirements']), 'User responses should omit keyed internal requirements.');
askResponseAssertSame(
    ['The report uses the requested campus scope.'],
    $user['recoveryItems'],
    'User responses should expose requirement labels as recovery items.'
);
askResponseAssertSame(false, isset($user['needsExploratoryApproval']), 'User responses should omit obsolete approval state.');

$ordinaryRecovery = AskResponseContractService::toUserResponse([
    'mode' => 'exploratory',
    'route' => 'exploratory_recovery',
    'recoveryContext' => ['originalQuestion' => 'Show title for instance number in0001.'],
    'suggestions' => ['Keep each requested identifier exactly as supplied.'],
    '_askEvidence' => [
        'explicitReportRequest' => ['identifiers' => ['instance_hrid' => ['in0001']]],
        'explicitReportRequestProvenance' => 'server_extracted',
    ],
]);
askResponseAssertSame(false, isset($ordinaryRecovery['_askEvidence']), 'Ordinary responses must not expose internal explicit-value evidence.');
askResponseAssertSame('Show title for instance number in0001.', $ordinaryRecovery['recoveryContext']['originalQuestion'] ?? null, 'Ordinary recovery context must retain the raw user question.');

fwrite(STDOUT, "Ask response contract service test passed\n");
