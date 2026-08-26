<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$graphBuilderPath = __DIR__ . '/../services/CanonicalQueryGraphArtifactBuilder.php';
$graphServicePath = __DIR__ . '/../services/CanonicalQueryGraphService.php';
$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$compilerServicePath = __DIR__ . '/../services/QueryFamilyCompilerService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
    'FolioSchemaService' => $schemaServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
    'CanonicalQueryGraphArtifactBuilder' => $graphBuilderPath,
    'CanonicalQueryGraphService' => $graphServicePath,
    'QueryFamilyContractService' => $contractServicePath,
    'QueryFamilySlotService' => $slotServicePath,
    'QueryFamilyCompilerService' => $compilerServicePath,
    'GeminiService' => $geminiServicePath,
] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "{$label} is missing at {$path}\n");
        exit(1);
    }
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            if ($alias === '@app/data/canonical_query_graph.json') {
                return __DIR__ . '/../data/canonical_query_graph.json';
            }
            if ($alias === '@app/data/query_family_contracts.json') {
                return __DIR__ . '/../data/query_family_contracts.json';
            }
            if ($alias === '@app/data/table_mapping_cache.json') {
                return __DIR__ . '/../data/table_mapping_cache.json';
            }
            if ($alias === '@app/data/column_cache.json') {
                return __DIR__ . '/../data/column_cache.json';
            }
            if ($alias === '@app/data/subtable_cache.json') {
                return __DIR__ . '/../data/subtable_cache.json';
            }
            if ($alias === '@app/data/semantic_context.json') {
                return __DIR__ . '/../data/semantic_context.json';
            }

            return $alias;
        }

        public static function warning($message, $category = null)
        {
        }

        public static function info($message, $category = null)
        {
        }
    }
}

Yii::$app = (object) [
    'cache' => null,
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
        'defaultQueryLimit' => 100,
        'maxQueryRows' => 1000,
    ],
];

require_once $schemaServicePath;
require_once $sqlBuilderPath;
require_once $graphBuilderPath;
require_once $graphServicePath;
require_once $contractServicePath;
require_once $slotServicePath;
require_once $compilerServicePath;
require_once $geminiServicePath;

use app\services\GeminiService;
use app\services\QueryFamilySlotService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertThrowsRuntimeException(callable $callback, string $expectedText, string $message): void
{
    try {
        $callback();
        fwrite(STDERR, $message . "\nExpected RuntimeException containing: {$expectedText}\n");
        exit(1);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), $expectedText) === false) {
            fwrite(STDERR, $message . "\nExpected text: {$expectedText}\nActual: {$e->getMessage()}\n");
            exit(1);
        }
    }
}

$validation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_contributor_campus_item_barcode',
    'slots' => [
        'campus' => 'Smith College',
        'contributor_name' => 'Smith College. Department of Biological Sciences.',
        'contributor_name_type' => 'Corporate name',
        'material_type' => 'Theses',
        'requested_outputs' => ['barcode', 'title'],
        'match_policy' => 'exact_phrase',
    ],
]);

if (empty($validation['valid'])) {
    fwrite(STDERR, "Test setup produced an invalid family payload.\n");
    exit(1);
}

$fallbackInvocations = 0;
$compilerInvocations = 0;

$helper = new ReflectionMethod(GeminiService::class, 'buildCompiledQueryFamilyOrLegacyFallback');
Yii::$app->params['nl2sqlForceLegacy'] = false;
Yii::$app->params['nl2sqlTwoLaneEnabled'] = true;

try {
    $helper->invoke(
        null,
        $validation['normalizedPayload'],
        'family_contract_supported:inventory_contributor_campus_item_barcode',
        'Show me Smith College theses by this contributor with barcodes',
        'Smith College',
        [
            'model' => 'test-model',
            'promptVersion' => 'family_slot_prompt.v1',
            'promptFingerprint' => 'test-fingerprint',
            'finishReason' => 'STOP',
            'attempts' => 1,
            'elapsedMs' => 5,
        ],
        function () use (&$compilerInvocations) {
            $compilerInvocations++;
            throw new InvalidArgumentException('missing_holdings_item_branch');
        },
        function () use (&$fallbackInvocations) {
            $fallbackInvocations++;
            return ['sql' => 'SELECT legacy_fallback_stub'];
        }
    );
    fwrite(STDERR, "Expected canonical Lane 2 signal.\n");
    exit(1);
} catch (\app\exceptions\CanonicalLaneFallbackException $exception) {
    assertSameValue('canonical_compiler_failed', $exception->getSafeReason(), 'Compiler failure needs a safe routing reason.');
}

assertSameValue(1, $compilerInvocations, 'Canonical compilation should run once.');
assertSameValue(0, $fallbackInvocations, 'Deep compiler code must not invoke AI or legacy fallback itself.');

$inventoryListingValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'location' => 'SC Rare Book Collection Reference',
        'only_holding_location' => true,
        'requested_outputs' => ['title'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

if (empty($inventoryListingValidation['valid'])) {
    fwrite(STDERR, "Test setup produced an invalid inventory listing family payload.\n");
    exit(1);
}

$inventoryListingCompilerInvocations = 0;
$inventoryListingFallbackInvocations = 0;
try {
    $helper->invoke(
        null,
        $inventoryListingValidation['normalizedPayload'],
        'family_contract_supported:inventory_library_location_listing',
        'Please provide a list of titles with the location MRBC Reference Collection containing only records for which the MRBC Reference Collection is the only holding location in the 5 Colleges.',
        'Smith College',
        [
            'model' => 'test-model',
            'promptVersion' => 'family_slot_prompt.v1',
            'promptFingerprint' => 'inventory-listing-clarification-guard-fingerprint',
            'finishReason' => 'STOP',
            'attempts' => 1,
            'elapsedMs' => 5,
        ],
        function () use (&$inventoryListingCompilerInvocations) {
            $inventoryListingCompilerInvocations++;
            throw new InvalidArgumentException('missing_location_scope_anchor');
        },
        function () use (&$inventoryListingFallbackInvocations) {
            $inventoryListingFallbackInvocations++;
            return ['sql' => 'SELECT inventory_listing_legacy_fallback_stub'];
        }
    );
    fwrite(STDERR, "Expected inventory compiler Lane 2 signal.\n");
    exit(1);
} catch (\app\exceptions\CanonicalLaneFallbackException $exception) {
    assertSameValue('canonical_compiler_failed', $exception->getSafeReason(), 'Inventory compiler failure needs a safe routing reason.');
}

assertSameValue(1, $inventoryListingCompilerInvocations, 'Inventory listing compiler failures should still be observed once.');
assertSameValue(0, $inventoryListingFallbackInvocations, 'Inventory listing compiler failures should not invoke legacy fallback.');

$onlineInventoryValidation = QueryFamilySlotService::validateFamilyPayload([
    'familyKey' => 'inventory_library_location_listing',
    'slots' => [
        'campus' => 'Smith College',
        'library' => 'Smith College',
        'requested_outputs' => ['title', 'barcode', 'instance_number'],
        'match_policy' => 'case_insensitive_contains',
    ],
]);

if (empty($onlineInventoryValidation['valid'])) {
    fwrite(STDERR, "Test setup produced an invalid online inventory family payload.\n");
    exit(1);
}

try {
    $helper->invoke(
        null,
        $onlineInventoryValidation['normalizedPayload'],
        'family_contract_supported:inventory_library_location_listing',
        'List of items with material type "e-book" and item status of "in process". Include title, barcode and instance number at Smith College',
        'Smith College',
        [
            'model' => 'test-model',
            'promptVersion' => 'family_slot_prompt.v1',
            'promptFingerprint' => 'online-inventory-no-library-scope-fingerprint',
            'finishReason' => 'STOP',
            'attempts' => 1,
            'elapsedMs' => 5,
        ],
        function () {
            throw new InvalidArgumentException('missing_library_scope_anchor');
        },
        function () {
            return ['sql' => 'SELECT should_not_run'];
        }
    );
    fwrite(STDERR, "Expected online inventory compiler Lane 2 signal.\n");
    exit(1);
} catch (\app\exceptions\CanonicalLaneFallbackException $exception) {
    assertSameValue('canonical_compiler_failed', $exception->getSafeReason(), 'Online inventory compiler failure needs a safe routing reason.');
}

$runtimeFallbackInvocations = 0;
$runtimeCompilerInvocations = 0;

try {
        $helper->invoke(
            null,
            $validation['normalizedPayload'],
            'family_contract_supported:inventory_contributor_campus_item_barcode',
            'Show me Smith College theses by this contributor with barcodes',
            'Smith College',
            [
                'model' => 'test-model',
                'promptVersion' => 'family_slot_prompt.v1',
                'promptFingerprint' => 'runtime-test-fingerprint',
                'finishReason' => 'STOP',
                'attempts' => 1,
                'elapsedMs' => 5,
            ],
            function () use (&$runtimeCompilerInvocations) {
                $runtimeCompilerInvocations++;
                throw new RuntimeException('artifact missing');
            },
            function () use (&$runtimeFallbackInvocations) {
                $runtimeFallbackInvocations++;
                return [
                    'sql' => 'SELECT runtime_legacy_fallback_stub',
                    'explanation' => 'Runtime legacy fallback stub.',
                    'dataSource' => 'folio',
                ];
            }
        );
    fwrite(STDERR, "Expected runtime compiler Lane 2 signal.\n");
    exit(1);
} catch (\app\exceptions\CanonicalLaneFallbackException $exception) {
    assertSameValue('canonical_compiler_failed', $exception->getSafeReason(), 'Runtime compiler failure needs a safe routing reason.');
}

assertSameValue(1, $runtimeCompilerInvocations, 'Runtime compiler failures should still attempt compilation once before the guard blocks fallback.');
assertSameValue(0, $runtimeFallbackInvocations, 'Runtime compiler failures should not invoke legacy fallback while the family guard is active.');

$policyFallbackInvocations = 0;
try {
    $helper->invoke(
        null,
        $validation['normalizedPayload'],
        'family_contract_supported:inventory_contributor_campus_item_barcode',
        'Show me Smith College theses by this contributor with barcodes',
        'Smith College',
        ['model' => 'test-model', 'promptVersion' => 'family_slot_prompt.v1'],
        function () {
            throw new \app\exceptions\PolicyViolationException('Blocked by reporting policy.');
        },
        function () use (&$policyFallbackInvocations) {
            $policyFallbackInvocations++;
            return ['sql' => 'SELECT should_not_run'];
        }
    );
    fwrite(STDERR, "Expected canonical policy failure to remain blocked.\n");
    exit(1);
} catch (\app\exceptions\PolicyViolationException $exception) {
    assertSameValue('Blocked by reporting policy.', $exception->getMessage(), 'Canonical policy failures must propagate unchanged.');
}
assertSameValue(0, $policyFallbackInvocations, 'Canonical policy failures must not invoke any fallback lane.');

$hardFailureCases = [
    [InvalidArgumentException::class, 'Only a single SELECT statement is allowed.', 'SQL-safety'],
    [RuntimeException::class, 'SQLSTATE[42501] permission denied', 'authorization'],
    [RuntimeException::class, 'SQLSTATE[53200] out of memory', 'resource-limit'],
];
foreach ($hardFailureCases as $hardFailureCase) {
    $hardFallbackInvocations = 0;
    try {
        $helper->invoke(
            null,
            $validation['normalizedPayload'],
            'family_contract_supported:inventory_contributor_campus_item_barcode',
            'Show me Smith College theses by this contributor with barcodes',
            'Smith College',
            ['model' => 'test-model', 'promptVersion' => 'family_slot_prompt.v1'],
            function () use ($hardFailureCase) {
                $exceptionClass = $hardFailureCase[0];
                throw new $exceptionClass($hardFailureCase[1]);
            },
            function () use (&$hardFallbackInvocations) {
                $hardFallbackInvocations++;
                return ['sql' => 'SELECT should_not_run'];
            }
        );
        fwrite(STDERR, "Expected canonical {$hardFailureCase[2]} failure to remain blocked.\n");
        exit(1);
    } catch (\Throwable $exception) {
        assertSameValue($hardFailureCase[0], get_class($exception), "Canonical {$hardFailureCase[2]} failures must preserve their exception type.");
        assertSameValue($hardFailureCase[1], $exception->getMessage(), "Canonical {$hardFailureCase[2]} failures must propagate unchanged.");
    }
    assertSameValue(0, $hardFallbackInvocations, "Canonical {$hardFailureCase[2]} failures must not invoke any fallback lane.");
}

Yii::$app->params['nl2sqlTwoLaneEnabled'] = false;
assertThrowsRuntimeException(
    function () use ($helper, $validation): void {
        $helper->invoke(
            null,
            $validation['normalizedPayload'],
            'family_contract_supported:inventory_contributor_campus_item_barcode',
            'Show me Smith College theses by this contributor with barcodes',
            'Smith College',
            ['model' => 'test-model', 'promptVersion' => 'family_slot_prompt.v1'],
            function () {
                throw new InvalidArgumentException('missing_holdings_item_branch');
            },
            function () {
                return ['sql' => 'SELECT should_not_run'];
            }
        );
    },
    'legacy fallback is disabled for this route',
    'Disabling two-lane routing must preserve the strict canonical blocker.'
);

Yii::$app->params['nl2sqlForceLegacy'] = true;

$overrideFallbackInvocations = 0;
$overrideCompilerInvocations = 0;
$overrideResult = $helper->invoke(
    null,
    $validation['normalizedPayload'],
    'family_contract_supported:inventory_contributor_campus_item_barcode',
    'Show me Smith College theses by this contributor with barcodes',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => 'family_slot_prompt.v1',
        'promptFingerprint' => 'override-test-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function () use (&$overrideCompilerInvocations) {
        $overrideCompilerInvocations++;
        throw new InvalidArgumentException('missing_holdings_item_branch: Covered-family item outputs require holdings-to-items joins.');
    },
    function () use (&$overrideFallbackInvocations) {
        $overrideFallbackInvocations++;
        return [
            'sql' => 'SELECT legacy_fallback_stub',
            'explanation' => 'Legacy fallback stub.',
            'dataSource' => 'folio',
        ];
    }
);

assertSameValue(1, $overrideCompilerInvocations, 'The compiler helper should still attempt compilation before the emergency override permits legacy fallback.');
assertSameValue(1, $overrideFallbackInvocations, 'The emergency override should restore the legacy fallback path for covered-family compiler failures.');
assertSameValue('legacy_fallback', $overrideResult['route'] ?? null, 'Emergency override should preserve the legacy_fallback route.');
assertSameValue('family_compiler_failed', $overrideResult['routeReason'] ?? null, 'Emergency override should preserve the family_compiler_failed route reason.');
assertSameValue('SELECT legacy_fallback_stub', $overrideResult['sql'] ?? null, 'Emergency override should return the injected legacy fallback SQL payload.');

$exploratoryCompilerFallback = $helper->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Available',
            'requested_outputs' => ['title'],
        ],
    ],
    'family_contract_supported:inventory_library_location_listing',
    'Show available Smith College items',
    'Smith College',
    ['model' => 'test-model', 'promptVersion' => 'family_slot_prompt.v1'],
    function (): array {
        throw new InvalidArgumentException('compiler rejected status-like library scope');
    },
    function (): array {
        return ['sql' => 'SELECT unused_legacy'];
    },
    function (): array {
        return [
            'mode' => 'exploratory',
            'route' => 'exploratory_legacy_freeform',
            'sql' => 'SELECT id FROM inventory.item__t',
        ];
    }
);
assertSameValue('exploratory', $exploratoryCompilerFallback['mode'] ?? null, 'A known-family compiler fallback must remain exploratory.');
assertSameValue('inventory_library_location_listing', $exploratoryCompilerFallback['_askEvidence']['queryFamily'] ?? null, 'A known-family compiler fallback must retain its validated family key.');

fwrite(STDOUT, "GeminiService family compiler fallback test passed\n");
