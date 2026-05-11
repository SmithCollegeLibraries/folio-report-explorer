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
$result = $helper->invoke(
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

assertSameValue(1, $compilerInvocations, 'The covered-family compiler helper should attempt compilation once before falling back.');
assertSameValue(1, $fallbackInvocations, 'The covered-family compiler helper should invoke the legacy fallback exactly once on compiler failure.');
assertSameValue('legacy_fallback', $result['route'] ?? null, 'Compiler failures should return the legacy_fallback route.');
assertSameValue('family_compiler_failed', $result['routeReason'] ?? null, 'Compiler failures should report the family_compiler_failed route reason.');
assertSameValue('SELECT legacy_fallback_stub', $result['sql'] ?? null, 'Compiler failures should return the legacy fallback SQL payload.');
assertSameValue('Legacy fallback stub.', $result['explanation'] ?? null, 'Compiler failures should preserve the legacy fallback explanation.');

$runtimeFallbackInvocations = 0;
$runtimeCompilerInvocations = 0;

$runtimeResult = $helper->invoke(
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

assertSameValue(1, $runtimeCompilerInvocations, 'Runtime compiler failures should still attempt compilation once before falling back.');
assertSameValue(1, $runtimeFallbackInvocations, 'Runtime compiler failures should invoke the legacy fallback exactly once.');
assertSameValue('legacy_fallback', $runtimeResult['route'] ?? null, 'Runtime compiler failures should return the legacy_fallback route.');
assertSameValue('family_compiler_failed', $runtimeResult['routeReason'] ?? null, 'Runtime compiler failures should preserve the family_compiler_failed route reason.');
assertSameValue('SELECT runtime_legacy_fallback_stub', $runtimeResult['sql'] ?? null, 'Runtime compiler failures should return the runtime fallback SQL payload.');
assertSameValue('Runtime legacy fallback stub.', $runtimeResult['explanation'] ?? null, 'Runtime compiler failures should preserve the runtime fallback explanation.');

fwrite(STDOUT, "GeminiService family compiler fallback test passed\n");