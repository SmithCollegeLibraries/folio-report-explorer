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

fwrite(STDOUT, "GeminiService family compiler fallback test passed\n");