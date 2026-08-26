<?php

$schemaServicePath = __DIR__ . '/../services/FolioSchemaService.php';
$sqlBuilderPath = __DIR__ . '/../services/SqlBuilderService.php';
$graphBuilderPath = __DIR__ . '/../services/CanonicalQueryGraphArtifactBuilder.php';
$graphServicePath = __DIR__ . '/../services/CanonicalQueryGraphService.php';
$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$manifestServicePath = __DIR__ . '/../services/QueryFamilySchemaManifestService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$compilerServicePath = __DIR__ . '/../services/QueryFamilyCompilerService.php';
$queryIntentServicePath = __DIR__ . '/../services/QueryIntentService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
    'FolioSchemaService' => $schemaServicePath,
    'SqlBuilderService' => $sqlBuilderPath,
    'CanonicalQueryGraphArtifactBuilder' => $graphBuilderPath,
    'CanonicalQueryGraphService' => $graphServicePath,
    'QueryFamilyContractService' => $contractServicePath,
    'QueryFamilySchemaManifestService' => $manifestServicePath,
    'QueryFamilySlotService' => $slotServicePath,
    'QueryFamilyCompilerService' => $compilerServicePath,
    'QueryIntentService' => $queryIntentServicePath,
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
            if ($alias === '@app/data/query_family_schema_manifests.json') {
                return __DIR__ . '/../data/query_family_schema_manifests.json';
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
require_once $manifestServicePath;
require_once $slotServicePath;
require_once $compilerServicePath;
require_once $queryIntentServicePath;
require_once $geminiServicePath;

use app\services\FolioSchemaService;
use app\services\GeminiService;

$mapProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredMap');
$columnProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredColumns');
$subtableProperty = new ReflectionProperty(FolioSchemaService::class, 'discoveredSubtables');

$tableMapCache = json_decode((string) file_get_contents(__DIR__ . '/../data/table_mapping_cache.json'), true);
$columnCache = json_decode((string) file_get_contents(__DIR__ . '/../data/column_cache.json'), true);
$subtableCache = json_decode((string) file_get_contents(__DIR__ . '/../data/subtable_cache.json'), true);

$mapProperty->setValue(null, is_array($tableMapCache['mapping'] ?? null) ? $tableMapCache['mapping'] : []);
$columnProperty->setValue(null, is_array($columnCache['columns'] ?? null) ? $columnCache['columns'] : []);
$subtableProperty->setValue(null, is_array($subtableCache['subtables'] ?? null) ? $subtableCache['subtables'] : []);

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\nText: {$haystack}\n");
        exit(1);
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected text: {$needle}\nText: {$haystack}\n");
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

$requestContextBuilder = new ReflectionMethod(GeminiService::class, 'buildIntentRequestContext');

$familyContext = $requestContextBuilder->invoke(
    null,
    'List of materials in Neilson Library. Include title and barcode.',
    'Smith College',
    'TEST_SCHEMA_BLOCK'
);

assertSameValue(
    'inventory_library_location_listing',
    $familyContext['queryFamily']['familyKey'] ?? null,
    'Family-matched prompts should resolve a checked-in query family before request dispatch.'
);
assertSameValue(
    GeminiService::FAMILY_SLOT_PROMPT_VERSION,
    $familyContext['promptVersion'] ?? null,
    'Family-matched prompts should use the dedicated family slot prompt version in the request path.'
);
assertContainsText(
    '"familyKey": "inventory_library_location_listing"',
    $familyContext['systemPrompt'] ?? '',
    'Family-matched prompts should build the dedicated family slot system prompt instead of the generic intent prompt.'
);

$genericContext = $requestContextBuilder->invoke(
    null,
    'Show open purchase orders created this fiscal year.',
    'Smith College',
    'TEST_SCHEMA_BLOCK'
);

assertSameValue(
    null,
    $genericContext['queryFamily'] ?? null,
    'Non-family prompts should remain on the generic structured-intent request path.'
);
assertSameValue(
    GeminiService::INTENT_PROMPT_VERSION,
    $genericContext['promptVersion'] ?? null,
    'Non-family prompts should keep the generic intent prompt version.'
);
assertContainsText(
    'TEST_SCHEMA_BLOCK',
    $genericContext['systemPrompt'] ?? '',
    'Non-family prompts should continue using the generic schema-backed intent prompt.'
);

$familyRouteHelper = new ReflectionMethod(GeminiService::class, 'maybeRouteQueryFamilyIntentResponse');

$capturedFamilyDispatch = null;
$familyResponse = $familyRouteHelper->invoke(
    null,
    [
        'familyKey' => 'inventory_library_location_listing',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'requested_outputs' => ['title', 'barcode'],
        ],
    ],
    [
        'familyKey' => 'inventory_library_location_listing',
    ],
    'List of materials in Neilson Library. Include title and barcode.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => GeminiService::FAMILY_SLOT_PROMPT_VERSION,
        'promptFingerprint' => 'request-path-family-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (array $intent, array $queryFamily, $prompt, $campus, array $telemetryContext) use (&$capturedFamilyDispatch): array {
        $capturedFamilyDispatch = [
            'intent' => $intent,
            'queryFamily' => $queryFamily,
            'prompt' => $prompt,
            'campus' => $campus,
            'telemetryContext' => $telemetryContext,
        ];

        return [
            'sql' => 'SELECT family_request_path_stub',
            'route' => 'legacy_fallback',
            'routeReason' => 'family_compiler_failed',
        ];
    }
);

assertSameValue(
    'SELECT family_request_path_stub',
    $familyResponse['sql'] ?? null,
    'Family-matched parsed JSON should be dispatched directly to the family intent branch before generic QueryIntent validation.'
);
assertSameValue(
    'inventory_library_location_listing',
    $capturedFamilyDispatch['queryFamily']['familyKey'] ?? null,
    'The request-path family router should pass the matched family metadata through unchanged.'
);
assertSameValue(
    GeminiService::FAMILY_SLOT_PROMPT_VERSION,
    $capturedFamilyDispatch['telemetryContext']['promptVersion'] ?? null,
    'The request-path family router should preserve the family prompt-version telemetry.'
);

$mismatchCompilerCalled = false;
$mismatchFallbackCalls = 0;
Yii::$app->params['nl2sqlForceLegacy'] = false;
Yii::$app->params['nl2sqlTwoLaneEnabled'] = true;

$mismatchInvocation = function () use ($familyRouteHelper, &$mismatchCompilerCalled, &$mismatchFallbackCalls): array {
    return $familyRouteHelper->invoke(
        null,
        [
            'familyKey' => 'circulation_top_items',
            'slots' => [
                'campus' => 'Smith College',
                'library' => 'Neilson Library',
                'material_type' => 'book',
                'requested_outputs' => ['ranked_circulation_items'],
            ],
        ],
        [
            'familyKey' => 'inventory_library_location_listing',
        ],
        'List of materials in Neilson Library. Include title and barcode.',
        'Smith College',
        [
            'model' => 'test-model',
            'promptVersion' => GeminiService::FAMILY_SLOT_PROMPT_VERSION,
            'promptFingerprint' => 'request-path-mismatch-fingerprint',
            'finishReason' => 'STOP',
            'attempts' => 1,
            'elapsedMs' => 5,
        ],
        function () use (&$mismatchCompilerCalled): array {
            $mismatchCompilerCalled = true;
            return [
                'sql' => 'SELECT should_not_run',
            ];
        },
        function () use (&$mismatchFallbackCalls): array {
            $mismatchFallbackCalls++;
            return [
                'sql' => 'SELECT mismatch_fallback_stub',
                'explanation' => 'Family mismatch fallback stub.',
                'dataSource' => 'folio',
            ];
        }
    );
};

try {
    $mismatchInvocation();
    fwrite(STDERR, "Expected enabled family mismatch Lane 2 signal.\n");
    exit(1);
} catch (\app\exceptions\CanonicalLaneFallbackException $exception) {
    assertSameValue(
        'canonical_family_contract_mismatch',
        $exception->getSafeReason(),
        'Enabled covered-family mismatches must emit the typed AI-lane signal.'
    );
}

assertSameValue(
    false,
    $mismatchCompilerCalled,
    'The request-path family router should not dispatch a model-returned family when it differs from the detected family.'
);
assertSameValue(
    0,
    $mismatchFallbackCalls,
    'The request-path family router should not invoke legacy fallback before the top-level AI lane.'
);

Yii::$app->params['nl2sqlTwoLaneEnabled'] = false;
assertThrowsRuntimeException(
    function () use ($mismatchInvocation): void {
        $mismatchInvocation();
    },
    'legacy fallback is disabled for this route',
    'Disabling two-lane routing must retain the strict covered-family mismatch blocker.'
);

assertSameValue(
    false,
    $mismatchCompilerCalled,
    'Rollback mismatch handling must not dispatch the wrong model-returned family.'
);
assertSameValue(
    0,
    $mismatchFallbackCalls,
    'Rollback mismatch handling must keep legacy fallback blocked without the emergency override.'
);

Yii::$app->params['nl2sqlForceLegacy'] = true;

$overrideMismatchFallbackCalls = 0;
$overrideMismatchResponse = $familyRouteHelper->invoke(
    null,
    [
        'familyKey' => 'circulation_top_items',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'material_type' => 'book',
            'requested_outputs' => ['ranked_circulation_items'],
        ],
    ],
    [
        'familyKey' => 'inventory_library_location_listing',
    ],
    'List of materials in Neilson Library. Include title and barcode.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => GeminiService::FAMILY_SLOT_PROMPT_VERSION,
        'promptFingerprint' => 'request-path-mismatch-override-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function () use (&$mismatchCompilerCalled): array {
        $mismatchCompilerCalled = true;
        return [
            'sql' => 'SELECT should_not_run',
        ];
    },
    function () use (&$overrideMismatchFallbackCalls): array {
        $overrideMismatchFallbackCalls++;
        return [
            'sql' => 'SELECT mismatch_fallback_stub',
            'explanation' => 'Family mismatch fallback stub.',
            'dataSource' => 'folio',
        ];
    }
);

assertSameValue(
    1,
    $overrideMismatchFallbackCalls,
    'Emergency override should restore legacy fallback for covered-family mismatch responses.'
);
assertSameValue(
    'legacy_fallback',
    $overrideMismatchResponse['route'] ?? null,
    'Emergency override should preserve the legacy route for covered-family mismatches.'
);
assertSameValue(
    'family_contract_mismatch',
    $overrideMismatchResponse['routeReason'] ?? null,
    'Emergency override should preserve the family_contract_mismatch route reason.'
);

$collectionAgeMismatchFallbackCalls = 0;
Yii::$app->params['nl2sqlForceLegacy'] = false;
$collectionAgeMismatchResponse = $familyRouteHelper->invoke(
    null,
    [
        'familyKey' => 'circulation_top_items',
        'slots' => [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'material_type' => 'book',
            'requested_outputs' => ['ranked_circulation_items'],
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'What is the average age of items in the Neilson Reference collection?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => GeminiService::FAMILY_SLOT_PROMPT_VERSION,
        'promptFingerprint' => 'request-path-collection-age-mismatch-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    null,
    function () use (&$collectionAgeMismatchFallbackCalls): array {
        $collectionAgeMismatchFallbackCalls++;
        return [
            'sql' => 'SELECT AVG(EXTRACT(EPOCH FROM (CURRENT_DATE - ii.metadata__created_date::date))/86400) AS average_age_days FROM inventory.item__t ii',
            'explanation' => 'Bad collection-age legacy fallback stub.',
            'dataSource' => 'folio',
        ];
    }
);

assertSameValue(
    0,
    $collectionAgeMismatchFallbackCalls,
    'Collection-age family mismatches should recover from prompt scope instead of dropping to the legacy freeform fallback.'
);
assertSameValue(
    'builder_intent',
    $collectionAgeMismatchResponse['route'] ?? null,
    'Collection-age family mismatches should stay on the deterministic builder route after prompt recovery.'
);
assertSameValue(
    'family_contract_supported:inventory_collection_age',
    $collectionAgeMismatchResponse['routeReason'] ?? null,
    'Collection-age family mismatches should recover back onto the supported collection-age family route.'
);
assertContainsText(
    'LEFT JOIN inventory.instance__t__publication',
    $collectionAgeMismatchResponse['sql'] ?? '',
    'Recovered collection-age mismatches should compile against the instance publication subtable.'
);
assertContainsText(
    'publication__date_of_publication',
    $collectionAgeMismatchResponse['sql'] ?? '',
    'Recovered collection-age mismatches should anchor age calculations on bibliographic publication year.'
);
assertNotContainsText(
    'metadata__created_date',
    $collectionAgeMismatchResponse['sql'] ?? '',
    'Recovered collection-age mismatches should not fall back to metadata__created_date age arithmetic.'
);

$genericResponse = $familyRouteHelper->invoke(
    null,
    [
        'intentVersion' => 1,
        'query' => [
            'tables' => ['orders_purchase_orders'],
            'select' => [
                [
                    'table' => 'orders_purchase_orders',
                    'column' => 'id',
                ],
            ],
        ],
    ],
    null,
    'Show open purchase orders created this fiscal year.',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => GeminiService::INTENT_PROMPT_VERSION,
        'promptFingerprint' => 'request-path-generic-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ]
);

assertSameValue(
    null,
    $genericResponse,
    'Non-family parsed JSON should continue down the generic QueryIntent validation path instead of short-circuiting.'
);

fwrite(STDOUT, "GeminiService intent request path test passed\n");
