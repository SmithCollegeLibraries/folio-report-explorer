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

$verified = AskResponseContractService::withGenerationProvenance(
    ['sql' => 'SELECT 1', 'mode' => 'canonical'],
    AskResponseContractService::PROVENANCE_VERIFIED_PATTERN
);
askResponseAssertSame('verified_pattern', $verified['generationProvenance'], 'Canonical success needs stable provenance.');
askResponseAssertSame('Verified pattern', $verified['provenanceLabel'], 'Canonical success needs the public label.');

$aiBuilt = AskResponseContractService::withGenerationProvenance(
    ['sql' => 'SELECT 1', 'mode' => 'exploratory'],
    AskResponseContractService::PROVENANCE_AI_BUILT
);
askResponseAssertSame('ai_built', $aiBuilt['generationProvenance'], 'AI success needs stable provenance.');
askResponseAssertSame('AI-built', $aiBuilt['provenanceLabel'], 'AI success needs the public label.');

$failure = AskResponseContractService::withGenerationProvenance(
    [
        'errorType' => 'sql_generation_failed',
        'generationProvenance' => 'verified_pattern',
        'provenanceLabel' => 'Verified pattern',
    ],
    AskResponseContractService::PROVENANCE_AI_BUILT
);
askResponseAssertSame(false, isset($failure['generationProvenance']), 'No-SQL failures must not claim successful provenance.');
askResponseAssertSame(false, isset($failure['provenanceLabel']), 'No-SQL failures must not retain a stale provenance label.');

$requestBlocked = AskResponseContractService::toUserResponse([
    'errorType' => 'request_blocked',
    'message' => 'Report Explorer runs read-only reports and cannot modify database data.',
    'route' => 'request_blocked',
    'routeReason' => 'explicit_write_intent',
]);
askResponseAssertSame('request_blocked', $requestBlocked['errorType'] ?? null, 'Response normalization must preserve request-level write blocks.');
askResponseAssertSame('request_blocked', $requestBlocked['route'] ?? null, 'A request block must not become a generation failure.');

$finalizedSuccessCases = [
    [
        'name' => 'trusted canonical success',
        'result' => [
            'sql' => 'SELECT 1',
            'route' => 'builder_intent',
            'routeReason' => 'family_contract_supported:inventory_collection_age',
            'generationProvenance' => 'verified_pattern',
            'provenanceLabel' => 'AI-built',
        ],
        'provenance' => 'verified_pattern',
        'label' => 'Verified pattern',
    ],
    [
        'name' => 'missing provenance',
        'result' => ['sql' => 'SELECT 1'],
        'provenance' => 'ai_built',
        'label' => 'AI-built',
    ],
    [
        'name' => 'invalid provenance',
        'result' => [
            'sql' => 'SELECT 1',
            'generationProvenance' => 'legacy_generated',
            'provenanceLabel' => 'Verified pattern',
        ],
        'provenance' => 'ai_built',
        'label' => 'AI-built',
    ],
    [
        'name' => 'exploratory response claiming verified provenance',
        'result' => [
            'sql' => 'SELECT 1',
            'mode' => 'exploratory',
            'route' => 'legacy_freeform',
            'generationProvenance' => 'verified_pattern',
            'provenanceLabel' => 'Verified pattern',
        ],
        'provenance' => 'ai_built',
        'label' => 'AI-built',
    ],
];
foreach ($finalizedSuccessCases as $case) {
    $finalized = AskResponseContractService::toUserResponse($case['result']);
    askResponseAssertSame(
        $case['provenance'],
        $finalized['generationProvenance'] ?? null,
        $case['name'] . ' must have one trusted provenance enum.'
    );
    askResponseAssertSame(
        $case['label'],
        $finalized['provenanceLabel'] ?? null,
        $case['name'] . ' must derive its matching public label.'
    );
}

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
    'attemptedPlan' => "Plan: filter with inventory.material_type__t.name = 'E-Book'.",
    'referenceResolver' => [
        'resolved' => true,
        'guidanceLines' => [
            "- Resolved local reference: use exactly inventory.material_type__t.name = 'E-Book'.",
        ],
    ],
    '_askEvidence' => [
        'explicitReportRequest' => ['identifiers' => ['instance_hrid' => ['in0001']]],
        'explicitReportRequestProvenance' => 'server_extracted',
    ],
]);
askResponseAssertSame(false, isset($ordinaryRecovery['_askEvidence']), 'Ordinary responses must not expose internal explicit-value evidence.');
askResponseAssertSame(false, isset($ordinaryRecovery['referenceResolver']), 'Ordinary responses must not expose internal resolver guidance.');
askResponseAssertSame(false, isset($ordinaryRecovery['attemptedPlan']), 'Ordinary recovery must omit an attempted plan without server-authored provenance.');
askResponseAssertSame(
    false,
    strpos(json_encode($ordinaryRecovery), "inventory.material_type__t.name = 'E-Book'") !== false,
    'Ordinary responses must not expose distinctive resolver schema predicates.'
);
askResponseAssertSame('Show title for instance number in0001.', $ordinaryRecovery['recoveryContext']['originalQuestion'] ?? null, 'Ordinary recovery context must retain the raw user question.');

$trustedRecovery = AskResponseContractService::toUserResponse([
    'mode' => 'exploratory',
    'route' => 'exploratory_recovery',
    'attemptedPlan' => 'Use the documented default reporting period.',
    '_attemptedPlanProvenance' => 'server_defaults',
]);
askResponseAssertSame(
    'Use the documented default reporting period.',
    $trustedRecovery['attemptedPlan'] ?? null,
    'Ordinary recovery may retain a server-authored attempted plan with explicit provenance.'
);
askResponseAssertSame(false, isset($trustedRecovery['_attemptedPlanProvenance']), 'Attempted-plan provenance must remain internal.');

fwrite(STDOUT, "Ask response contract service test passed\n");
