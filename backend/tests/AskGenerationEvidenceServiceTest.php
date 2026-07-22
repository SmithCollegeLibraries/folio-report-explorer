<?php

require_once __DIR__ . '/../services/AskGenerationEvidenceService.php';

use app\services\AskGenerationEvidenceService;

function evidenceAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$canonical = AskGenerationEvidenceService::build([
    'route' => 'builder_intent',
    'routeReason' => 'family_contract_supported:inventory_collection_age',
    'mode' => 'canonical',
    'sql' => 'SELECT 1 AS total',
], ['prompt' => 'Count titles', 'queryFamily' => 'inventory_collection_age']);
evidenceAssertSame('canonical', $canonical['mode'], 'Canonical family mode must be retained.');
evidenceAssertSame('deterministic', $canonical['executionMode'], 'Canonical families must persist deterministic execution mode.');
evidenceAssertSame('validated', $canonical['validationStatus'], 'A surviving canonical SQL candidate must be validated.');

$trustedRejected = AskGenerationEvidenceService::build([
    'route' => 'exploratory_recovery',
    'mode' => 'exploratory',
    '_askEvidence' => [
        'finalSql' => 'SELECT 1 AS total',
        'validationStatus' => 'rejected',
    ],
], ['prompt' => 'Count titles']);
evidenceAssertSame('rejected', $trustedRejected['validationStatus'], 'Trusted internal rejection must override final-candidate validation inference.');
evidenceAssertSame(null, $trustedRejected['generatedSql'], 'A trusted rejected candidate must not persist as executable SQL.');
evidenceAssertSame(null, $trustedRejected['sqlHash'], 'A trusted rejected candidate must not persist an executable SQL hash.');
evidenceAssertSame(true, is_array($trustedRejected['finalStructure']), 'A trusted rejected candidate may retain structural evidence.');

$ordinary = AskGenerationEvidenceService::build([
    'route' => 'exploratory_legacy_freeform',
    'routeReason' => 'unsupported_query_family',
    'mode' => 'exploratory',
    'sql' => 'SELECT title FROM inventory.instance__t',
    'validationSummary' => ['status' => 'validated', 'repairAttempts' => 0],
], ['prompt' => 'Show unusual title data']);
evidenceAssertSame('exploratory', $ordinary['executionMode'], 'Exploratory results must persist exploratory execution mode.');
evidenceAssertSame(false, $ordinary['materialRepair'], 'An unrepaired candidate must not be materially repaired.');

$flagged = AskGenerationEvidenceService::build([
    'route' => 'exploratory_legacy_freeform',
    'mode' => 'exploratory',
    'sql' => 'SELECT title, COUNT(*) FROM inventory.instance__t GROUP BY title',
    'initialSql' => 'SELECT title FROM inventory.instance__t',
    'repairAttempts' => 1,
    'assumptions' => [
        ['key' => 'purchase_date_basis', 'source' => 'default'],
        ['key' => 'display_order', 'source' => 'default'],
    ],
    'semanticValidation' => [
        'status' => 'validated',
        'contractVersion' => 3,
        'coverageStatus' => 'limited',
        'checkedRequirements' => [['key' => 'campus_scope']],
    ],
    'crossDomain' => true,
], ['prompt' => 'Compare purchases and circulation']);
evidenceAssertSame(true, $flagged['materialRepair'], 'A structural analytical change must be material.');
evidenceAssertSame(['purchase_date_basis', 'display_order'], $flagged['defaultedAssumptionKeys'], 'Defaulted assumptions must retain stable input order.');
evidenceAssertSame(['purchase_date_basis'], $flagged['materialDefaultedAssumptionKeys'], 'Only known material defaults should be marked material.');
evidenceAssertSame(true, $flagged['limitedSemanticCoverage'], 'Limited passing semantic coverage must remain confidence evidence.');

$clarification = AskGenerationEvidenceService::build([
    'route' => 'clarification',
    'needsClarification' => true,
], ['prompt' => 'Show unused books']);
evidenceAssertSame(null, $clarification['executionMode'], 'Clarifications must not acquire an execution mode.');
evidenceAssertSame(null, $clarification['validationStatus'], 'Clarifications must not acquire validation status.');

$blocked = AskGenerationEvidenceService::build([
    'route' => 'blocked',
    'routeReason' => 'ask_policy_block',
    'error' => 'Blocked',
], ['prompt' => 'List patron email']);
evidenceAssertSame(true, $blocked['policyBlocked'], 'Policy responses must be recorded as blocked.');
evidenceAssertSame(null, $blocked['executionMode'], 'Policy responses must not acquire an execution mode.');

foreach (['exhausted', 'rejected'] as $status) {
    $failed = AskGenerationEvidenceService::build([
        'mode' => 'exploratory',
        'route' => 'exploratory_recovery',
        'validationSummary' => [
            'status' => $status,
            'validatorStage' => 'semantic_conformance',
            'failureCategory' => 'semantic_coverage_gap',
        ],
        'unmetRequirements' => [['key' => 'campus_scope', 'label' => 'Use campus scope.']],
    ], ['prompt' => 'Build an ROI report']);
    evidenceAssertSame($status, $failed['validationStatus'], 'Unable-to-run validation status must be recorded.');
    evidenceAssertSame('semantic_conformance', $failed['confidenceEvidence']['validatorStage'], 'Internal validator stage must reach persistence evidence.');
    evidenceAssertSame(['campus_scope'], $failed['confidenceEvidence']['unmetRequirementKeys'], 'Internal requirement keys must reach persistence evidence.');
}

$physicalRoi = AskGenerationEvidenceService::build([
    'mode' => 'exploratory',
    'route' => 'exploratory_legacy_freeform',
    'compilerVersion' => 'physical_roi_v2',
    'sql' => 'SELECT 1',
], ['prompt' => 'Show physical ROI']);
evidenceAssertSame('exploratory', $physicalRoi['mode'], 'physical_roi_v2 must remain exploratory.');
evidenceAssertSame('exploratory', $physicalRoi['executionMode'], 'physical_roi_v2 must never be promoted to deterministic.');
evidenceAssertSame('physical_roi_v2', $physicalRoi['provenance']['compilerVersion'], 'Compiler provenance must be retained.');

$trustedInternal = AskGenerationEvidenceService::build([
    'mode' => 'exploratory',
    'route' => 'exploratory_legacy_freeform',
    'sql' => 'SELECT title, COUNT(*) FROM inventory.instance__t GROUP BY title',
    '_askEvidence' => [
        'initialSql' => 'SELECT title FROM inventory.instance__t',
        'finalSql' => 'SELECT title, COUNT(*) FROM inventory.instance__t GROUP BY title',
        'repairAttempts' => 1,
        'queryFamily' => 'trusted_family',
        'modelName' => 'trusted-model',
        'promptVersion' => 'trusted-prompt.v1',
        'schemaMetadata' => ['version' => 'schema-v1'],
        'referenceBundleMetadata' => ['version' => 'bundle-v1', 'hash' => 'bundle-hash'],
    ],
], [
    'prompt' => 'Show grouped titles',
    'initialSql' => 'SELECT title, COUNT(*) FROM inventory.instance__t GROUP BY title',
    'queryFamily' => 'untrusted_request_family',
]);
evidenceAssertSame(true, $trustedInternal['materialRepair'], 'Trusted Gemini repair history must take precedence over the controller-observed final candidate.');
evidenceAssertSame('trusted_family', $trustedInternal['queryFamily'], 'Trusted generation family must take precedence over request context.');
evidenceAssertSame('trusted-model', $trustedInternal['provenance']['modelName'], 'Trusted model provenance must reach persistence evidence.');
evidenceAssertSame(['version' => 'schema-v1'], $trustedInternal['provenance']['schemaMetadata'], 'Trusted schema metadata must reach persistence evidence.');
evidenceAssertSame(['version' => 'bundle-v1', 'hash' => 'bundle-hash'], $trustedInternal['provenance']['referenceBundleMetadata'], 'Trusted reference bundle metadata must reach persistence evidence.');

$explicitEvidence = AskGenerationEvidenceService::build([
    'mode' => 'exploratory',
    'route' => 'exploratory_legacy_freeform',
    'sql' => 'SELECT title FROM inventory.instance__t',
    '_askEvidence' => [
        'explicitReportRequest' => [
            'applicable' => true,
            'identifiers' => ['instance_hrid' => ['in0001']],
            'requestedFields' => ['title'],
            'limit' => 20,
        ],
        'explicitReportRequestProvenance' => 'server_extracted',
    ],
], ['prompt' => 'Show title for instance number in0001. Limit 20.']);
evidenceAssertSame('server_extracted', $explicitEvidence['provenance']['explicitReportRequestProvenance'] ?? null, 'Only server-created explicit-value provenance may reach review evidence.');
evidenceAssertSame(['in0001'], $explicitEvidence['confidenceEvidence']['explicitReportRequest']['identifiers']['instance_hrid'] ?? null, 'Server-extracted explicit identifiers must reach confidence evidence.');

evidenceAssertSame(
    [
        'compilerVersion' => null,
        'modelName' => null,
        'promptVersion' => null,
        'referenceBundleMetadata' => null,
        'schemaMetadata' => null,
        'semanticContractVersion' => null,
    ],
    $ordinary['provenance'],
    'Unavailable provenance must be represented by explicit nulls.'
);
evidenceAssertSame(false, array_key_exists('modelConfidence', $flagged), 'Model confidence must never enter trusted evidence.');

fwrite(STDOUT, "Ask generation evidence service test passed\n");
