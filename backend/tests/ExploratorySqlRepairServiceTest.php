<?php

$validationExceptionPath = __DIR__ . '/../exceptions/ExploratorySqlValidationException.php';
$policyExceptionPath = __DIR__ . '/../exceptions/PolicyViolationException.php';
$servicePath = __DIR__ . '/../services/ExploratorySqlRepairService.php';

foreach ([$validationExceptionPath, $policyExceptionPath, $servicePath] as $requiredPath) {
    if (!file_exists($requiredPath)) {
        fwrite(STDERR, "Required repair type is missing at {$requiredPath}\n");
        exit(1);
    }
}

require_once $validationExceptionPath;
require_once $policyExceptionPath;
require_once $servicePath;

use app\exceptions\ExploratorySqlValidationException;
use app\exceptions\PolicyViolationException;
use app\services\ExploratorySqlRepairService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertFalseValue($actual, string $message): void
{
    assertSameValue(false, $actual, $message);
}

function failTest(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$calls = 0;
$contexts = [];
$outcome = ExploratorySqlRepairService::run(
    function (array $context) use (&$calls, &$contexts): array {
        $calls++;
        $contexts[] = $context;
        if ($calls === 1) {
            throw new ExploratorySqlValidationException(
                'schema_reference',
                'unknown_column',
                'SELECT bad_column FROM inventory.item__t',
                true,
                'column does not exist: secret_internal_detail'
            );
        }
        return ['sql' => 'SELECT id FROM inventory.item__t'];
    },
    [
        'originalQuestion' => 'Show items',
        'campus' => 'main',
        'assumptions' => [['key' => 'period', 'value' => 'current_year']],
        'attemptedPlan' => 'Read inventory items.',
        'semanticContract' => ['contractVersion' => 1, 'applicable' => false],
        'unsafeExtra' => 'must not reach the attempt',
    ]
);

assertSameValue('validated', $outcome['status'], 'A repairable first failure should be repaired.');
assertSameValue('SELECT id FROM inventory.item__t', $outcome['result']['sql'] ?? null, 'The validated attempt result should be returned.');
assertSameValue(1, $outcome['repairAttempts'], 'One repair should be recorded.');
assertSameValue(
    [
        'initialSql' => 'SELECT bad_column FROM inventory.item__t',
        'finalSql' => 'SELECT id FROM inventory.item__t',
        'repairAttempts' => 1,
    ],
    $outcome['result']['_askEvidence'] ?? null,
    'A repaired result must retain the genuine initial candidate, final candidate, and repair count as trusted internal evidence.'
);
assertSameValue(2, $calls, 'Only initial generation and one repair should run.');
assertSameValue(
    [
        'originalQuestion' => 'Show items',
        'generationPrompt' => '',
        'campus' => 'main',
        'assumptions' => [['key' => 'period', 'value' => 'current_year']],
        'attemptedPlan' => 'Read inventory items.',
        'semanticContract' => ['contractVersion' => 1, 'applicable' => false],
        'safeViolations' => [],
        'repairNumber' => 0,
        'previousCandidate' => null,
        'validatorStage' => null,
        'safeCategory' => null,
    ],
    $contexts[0],
    'The initial call should receive only the structured context contract.'
);
assertSameValue(1, $contexts[1]['repairNumber'], 'The first repair should be numbered one.');
assertSameValue('SELECT bad_column FROM inventory.item__t', $contexts[1]['previousCandidate'], 'A repair should receive the previous candidate.');
assertSameValue('schema_reference', $contexts[1]['validatorStage'], 'A repair should receive the validator stage.');
assertSameValue('unknown_column', $contexts[1]['safeCategory'], 'A repair should receive the safe failure category.');
assertFalseValue(
    in_array('column does not exist: secret_internal_detail', $contexts[1], true),
    'Raw exception messages must not be passed to attempts.'
);

$failures = 0;
$exhausted = ExploratorySqlRepairService::run(
    function (array $context) use (&$failures): array {
        $failures++;
        throw new ExploratorySqlValidationException(
            'database_preflight',
            'invalid_join',
            'SELECT internal_candidate_' . $failures,
            true,
            'raw database failure ' . $failures
        );
    },
    ['originalQuestion' => 'Compare inventory and circulation', 'attemptedPlan' => 'Join item usage.']
);

assertSameValue(3, $failures, 'The coordinator should make one initial call and at most two repairs.');
assertSameValue('exhausted', $exhausted['status'], 'Three repairable failures should exhaust the repair budget.');
assertSameValue(2, $exhausted['repairAttempts'], 'Exhaustion should report both repair calls.');
assertSameValue('database_preflight', $exhausted['validatorStage'], 'Exhaustion should expose the final validator stage.');
assertSameValue('invalid_join', $exhausted['failureCategory'], 'Exhaustion should expose only the safe failure category.');
assertSameValue(
    ['Retry the request.', 'Correct an assumption.', 'Narrow the period or output.'],
    $exhausted['suggestions'],
    'Exhaustion should provide the required recovery suggestions.'
);
assertFalseValue(isset($exhausted['result']), 'Exhaustion must not expose an unvalidated result.');
assertFalseValue(isset($exhausted['candidateSql']), 'Exhaustion must not expose candidate SQL.');
assertFalseValue(isset($exhausted['message']), 'Exhaustion must not expose an internal exception message.');
assertSameValue('SELECT internal_candidate_1', $exhausted['_askEvidence']['initialSql'] ?? null, 'Exhausted trusted evidence must retain the initial rejected candidate.');
assertSameValue('SELECT internal_candidate_3', $exhausted['_askEvidence']['finalSql'] ?? null, 'Exhausted trusted evidence must retain the last rejected candidate.');
assertSameValue(2, $exhausted['_askEvidence']['repairAttempts'] ?? null, 'Exhausted trusted evidence must retain the bounded repair count.');

$policyCalls = 0;
try {
    ExploratorySqlRepairService::run(
        function (array $context) use (&$policyCalls): array {
            $policyCalls++;
            throw new PolicyViolationException('blocked schema');
        },
        ['originalQuestion' => 'Show restricted data']
    );
    failTest('Policy violations should be rethrown.');
} catch (PolicyViolationException $exception) {
    assertSameValue(1, $policyCalls, 'A policy violation should stop after one call.');
}

$nonrepairableCalls = 0;
$nonrepairable = new ExploratorySqlValidationException(
    'safety',
    'non_select',
    'DELETE FROM inventory.item__t',
    false,
    'destructive SQL'
);
try {
    ExploratorySqlRepairService::run(
        function (array $context) use (&$nonrepairableCalls, $nonrepairable): array {
            $nonrepairableCalls++;
            throw $nonrepairable;
        },
        ['originalQuestion' => 'Delete items']
    );
    failTest('Nonrepairable validation failures should be rethrown.');
} catch (ExploratorySqlValidationException $exception) {
    assertSameValue($nonrepairable, $exception, 'The original nonrepairable exception should propagate.');
    assertSameValue(1, $nonrepairableCalls, 'A nonrepairable validation failure should stop after one call.');
}

$preflightCalls = 0;
$preflightContexts = [];
$preflightFailure = new ExploratorySqlValidationException(
    'database_preflight',
    'unknown_column',
    'SELECT missing FROM inventory.item__t',
    true,
    'database detail must stay private'
);
$preflightOutcome = ExploratorySqlRepairService::run(
    function (array $context) use (&$preflightCalls, &$preflightContexts): array {
        $preflightCalls++;
        $preflightContexts[] = $context;
        return ['sql' => 'SELECT id FROM inventory.item__t'];
    },
    ['originalQuestion' => 'Show item ids', 'campus' => 'main'],
    1,
    $preflightFailure
);

assertSameValue('validated', $preflightOutcome['status'], 'A preflight failure should use the remaining shared repair budget.');
assertSameValue(2, $preflightOutcome['repairAttempts'], 'A successful remaining repair should increment the shared repair count.');
assertSameValue(1, $preflightCalls, 'One used repair should leave only one repair call available.');
assertSameValue(2, $preflightContexts[0]['repairNumber'], 'The remaining repair should preserve shared repair numbering.');
assertSameValue('database_preflight', $preflightContexts[0]['validatorStage'], 'The remaining repair should receive the preflight stage.');
assertSameValue('SELECT missing FROM inventory.item__t', $preflightOutcome['result']['_askEvidence']['initialSql'] ?? null, 'A supplied preflight failure candidate must become the initial trusted candidate when no older candidate is available.');
assertSameValue('SELECT id FROM inventory.item__t', $preflightOutcome['result']['_askEvidence']['finalSql'] ?? null, 'A successful preflight repair must retain its final candidate.');

$semanticViolations = [[
    'key' => 'purchase_date_basis',
    'category' => 'assumption_mismatch',
    'label' => 'Purchases use payment date for the last five years.',
    'guidance' => 'Filter the approved invoice payment-date column with the requested five-year window.',
]];
$semanticContexts = [];
$semanticCalls = 0;
$semanticOutcome = ExploratorySqlRepairService::run(
    function (array $context) use (&$semanticContexts, &$semanticCalls, $semanticViolations): array {
        $semanticContexts[] = $context;
        $semanticCalls++;
        throw new ExploratorySqlValidationException(
            'semantic_conformance',
            'assumption_mismatch',
            'SELECT private_candidate_' . $semanticCalls,
            true,
            'raw semantic evidence must stay private',
            null,
            $semanticViolations
        );
    },
    ['originalQuestion' => 'Compare purchases and circulation ROI']
);

assertSameValue(3, $semanticCalls, 'Semantic failures should share the initial-plus-two-repair budget.');
assertSameValue($semanticViolations, $semanticContexts[1]['safeViolations'], 'Repairs should receive only safe semantic violations.');
assertSameValue(
    [['key' => 'purchase_date_basis', 'label' => 'Purchases use payment date for the last five years.']],
    $semanticOutcome['unmetRequirements'],
    'Recovery should contain stable keys and user-readable labels only.'
);
$ordinarySemanticOutcome = $semanticOutcome;
unset($ordinarySemanticOutcome['_askEvidence']);
assertFalseValue(
    strpos(json_encode($ordinarySemanticOutcome), 'private_candidate_') !== false,
    'Recovery fields outside the trusted envelope must not expose rejected SQL.'
);
assertSameValue('SELECT private_candidate_1', $semanticOutcome['_askEvidence']['initialSql'] ?? null, 'Trusted semantic exhaustion evidence must retain the first candidate.');
assertSameValue('SELECT private_candidate_3', $semanticOutcome['_askEvidence']['finalSql'] ?? null, 'Trusted semantic exhaustion evidence must retain the last candidate.');
assertFalseValue(
    strpos(json_encode($semanticOutcome), 'raw semantic evidence') !== false,
    'Recovery must not expose internal evidence.'
);

assertSameValue('schema_reference', (new ExploratorySqlValidationException('schema_reference', 'unknown_table', 'SELECT 1', true, 'internal'))->getStage(), 'The validation exception should expose its stage.');
assertSameValue('unknown_table', (new ExploratorySqlValidationException('schema_reference', 'unknown_table', 'SELECT 1', true, 'internal'))->getSafeCategory(), 'The validation exception should expose its safe category.');
assertSameValue('SELECT 1', (new ExploratorySqlValidationException('schema_reference', 'unknown_table', 'SELECT 1', true, 'internal'))->getCandidateSql(), 'The validation exception should expose its candidate SQL.');
assertSameValue(true, (new ExploratorySqlValidationException('schema_reference', 'unknown_table', 'SELECT 1', true, 'internal'))->isRepairable(), 'The validation exception should expose repairability.');

fwrite(STDOUT, "ExploratorySqlRepairService test passed\n");
