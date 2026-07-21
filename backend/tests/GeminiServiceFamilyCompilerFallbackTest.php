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

assertThrowsRuntimeException(
    function () use ($helper, $validation, &$compilerInvocations, &$fallbackInvocations): void {
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
                throw new InvalidArgumentException('missing_holdings_item_branch: Covered-family item outputs require holdings-to-items joins.');
            },
            function () use (&$fallbackInvocations) {
                $fallbackInvocations++;
                return [
                    'sql' => 'SELECT legacy_fallback_stub',
                    'explanation' => 'Legacy fallback stub.',
                    'dataSource' => 'folio',
                ];
            }
        );
    },
    'legacy fallback is disabled for this route',
    'Covered-family compiler failures should fail safe instead of silently downgrading to legacy freeform SQL.'
);

assertSameValue(1, $compilerInvocations, 'The covered-family compiler helper should attempt compilation once before blocking the unsafe fallback.');
assertSameValue(0, $fallbackInvocations, 'The covered-family compiler helper should not invoke the legacy fallback when the family guard is active.');

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
$inventoryListingResult = $helper->invoke(
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
        throw new InvalidArgumentException('missing_location_scope_anchor: Library/location listing prompts require a location lookup filter when explicit location scope is present.');
    },
    function () use (&$inventoryListingFallbackInvocations) {
        $inventoryListingFallbackInvocations++;
        return [
            'sql' => 'SELECT inventory_listing_legacy_fallback_stub',
            'explanation' => 'Legacy fallback stub.',
            'dataSource' => 'folio',
        ];
    }
);

assertSameValue(1, $inventoryListingCompilerInvocations, 'Inventory listing compiler failures should still be observed once.');
assertSameValue(0, $inventoryListingFallbackInvocations, 'Inventory listing compiler failures should not invoke legacy fallback.');
assertSameValue(true, $inventoryListingResult['needsClarification'] ?? null, 'Inventory listing compiler failures should return a clarification instead of throwing an AI error.');
assertSameValue('clarification', $inventoryListingResult['route'] ?? null, 'Inventory listing compiler failures should use the clarification route.');
assertSameValue('inventory_listing_compiler_failed', $inventoryListingResult['routeReason'] ?? null, 'Inventory listing compiler failures should expose a stable clarification route reason.');
assertSameValue('inventory_listing_scope', $inventoryListingResult['clarificationKey'] ?? null, 'Inventory listing compiler clarifications should expose a key so users can submit free-text responses.');
assertSameValue(true, $inventoryListingResult['freeTextAllowed'] ?? null, 'Inventory listing compiler clarifications should allow users to type the library, location, or location code.');

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

$onlineInventoryResult = $helper->invoke(
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
        throw new InvalidArgumentException('missing_library_scope_anchor: Library/location listing prompts require a library lookup filter.');
    },
    function () {
        return [
            'sql' => 'SELECT should_not_run',
            'explanation' => 'Legacy fallback stub.',
            'dataSource' => 'folio',
        ];
    }
);

assertSameValue(true, $onlineInventoryResult['needsClarification'] ?? null, 'Covered online material/status listing compiler failures should fail safe instead of calling unvalidated AI generation.');
assertSameValue('inventory_listing_compiler_failed', $onlineInventoryResult['routeReason'] ?? null, 'Covered online material/status listings should expose the stable compiler-failure clarification reason.');
assertSameValue(false, strpos((string)($onlineInventoryResult['question'] ?? ''), 'exact library') !== false, 'Online material/status listings should not ask for an exact library.');

$runtimeFallbackInvocations = 0;
$runtimeCompilerInvocations = 0;

assertThrowsRuntimeException(
    function () use ($helper, $validation, &$runtimeCompilerInvocations, &$runtimeFallbackInvocations): void {
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
    },
    'legacy fallback is disabled for this route',
    'Runtime compiler failures should fail safe instead of silently downgrading to legacy freeform SQL.'
);

assertSameValue(1, $runtimeCompilerInvocations, 'Runtime compiler failures should still attempt compilation once before the guard blocks fallback.');
assertSameValue(0, $runtimeFallbackInvocations, 'Runtime compiler failures should not invoke legacy fallback while the family guard is active.');

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
