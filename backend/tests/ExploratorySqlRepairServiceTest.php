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
        'unsafeExtra' => 'must not reach the attempt',
    ]
);

assertSameValue('validated', $outcome['status'], 'A repairable first failure should be repaired.');
assertSameValue(['sql' => 'SELECT id FROM inventory.item__t'], $outcome['result'], 'The validated attempt result should be returned.');
assertSameValue(1, $outcome['repairAttempts'], 'One repair should be recorded.');
assertSameValue(2, $calls, 'Only initial generation and one repair should run.');
assertSameValue(
    [
        'originalQuestion' => 'Show items',
        'campus' => 'main',
        'assumptions' => [['key' => 'period', 'value' => 'current_year']],
        'attemptedPlan' => 'Read inventory items.',
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

assertSameValue('schema_reference', (new ExploratorySqlValidationException('schema_reference', 'unknown_table', 'SELECT 1', true, 'internal'))->getStage(), 'The validation exception should expose its stage.');
assertSameValue('unknown_table', (new ExploratorySqlValidationException('schema_reference', 'unknown_table', 'SELECT 1', true, 'internal'))->getSafeCategory(), 'The validation exception should expose its safe category.');
assertSameValue('SELECT 1', (new ExploratorySqlValidationException('schema_reference', 'unknown_table', 'SELECT 1', true, 'internal'))->getCandidateSql(), 'The validation exception should expose its candidate SQL.');
assertSameValue(true, (new ExploratorySqlValidationException('schema_reference', 'unknown_table', 'SELECT 1', true, 'internal'))->isRepairable(), 'The validation exception should expose repairability.');

fwrite(STDOUT, "ExploratorySqlRepairService test passed\n");
