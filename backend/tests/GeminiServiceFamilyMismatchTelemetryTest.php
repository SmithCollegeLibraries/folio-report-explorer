<?php

$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
    'QueryFamilyContractService' => $contractServicePath,
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
        public static $infos = [];
        public static $warnings = [];

        public static function getAlias($alias)
        {
            if ($alias === '@app/data/query_family_contracts.json') {
                return __DIR__ . '/../data/query_family_contracts.json';
            }

            return $alias;
        }

        public static function info($message, $category = null)
        {
            self::$infos[] = [
                'message' => $message,
                'category' => $category,
            ];
        }

        public static function warning($message, $category = null)
        {
            self::$warnings[] = [
                'message' => $message,
                'category' => $category,
            ];
        }
    }
}

Yii::$app = (object) [
    'params' => [],
];

require_once $contractServicePath;
require_once $geminiServicePath;

use app\services\GeminiService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertCountValue(int $expected, array $actual, string $message): void
{
    if (count($actual) !== $expected) {
        fwrite(STDERR, $message . "\nExpected count: {$expected}\nActual count: " . count($actual) . "\n");
        exit(1);
    }
}

function assertThrowsRuntimeException(callable $callback, string $expectedMessageFragment, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), $expectedMessageFragment) === false) {
            fwrite(STDERR, $message . "\nExpected exception message to contain: {$expectedMessageFragment}\nActual message: {$e->getMessage()}\n");
            exit(1);
        }

        return;
    }

    fwrite(STDERR, $message . "\nExpected RuntimeException was not thrown.\n");
    exit(1);
}

function decodeTelemetryRecord(string $message): array
{
    $prefix = 'NL2SQL telemetry: ';
    if (strpos($message, $prefix) !== 0) {
        fwrite(STDERR, "Telemetry message did not start with the expected prefix.\nMessage: {$message}\n");
        exit(1);
    }

    $decoded = json_decode(substr($message, strlen($prefix)), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Telemetry payload was not valid JSON.\nMessage: {$message}\n");
        exit(1);
    }

    return $decoded;
}

$familyRouteHelper = new ReflectionMethod(GeminiService::class, 'maybeRouteQueryFamilyIntentResponse');
if (PHP_VERSION_ID < 80500) {
    $familyRouteHelper->setAccessible(true);
}

Yii::$warnings = [];
Yii::$infos = [];
Yii::$app->params['nl2sqlForceLegacy'] = true;

$overrideResponse = $familyRouteHelper->invoke(
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
        'promptFingerprint' => 'family-mismatch-telemetry-override-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    null,
    function (): array {
        return [
            'sql' => 'SELECT mismatch_fallback_stub',
            'explanation' => 'Mismatch fallback stub.',
            'dataSource' => 'folio',
        ];
    }
);

assertSameValue('legacy_fallback', $overrideResponse['route'] ?? null, 'Emergency override should preserve the family mismatch legacy fallback route.');

$telemetryWarnings = array_values(array_filter(
    Yii::$warnings,
    static function (array $entry): bool {
        return ($entry['category'] ?? null) === 'nl2sql.telemetry';
    }
));

$telemetryInfos = array_values(array_filter(
    Yii::$infos,
    static function (array $entry): bool {
        return ($entry['category'] ?? null) === 'nl2sql.telemetry';
    }
));

assertCountValue(1, $telemetryWarnings, 'Mismatch override should emit one structured validation warning before using the fallback.');
assertCountValue(1, $telemetryInfos, 'Mismatch override should emit one structured generated telemetry event for the legacy fallback response.');

$warningTelemetry = decodeTelemetryRecord((string)($telemetryWarnings[0]['message'] ?? ''));
$generatedTelemetry = decodeTelemetryRecord((string)($telemetryInfos[0]['message'] ?? ''));

assertSameValue('nl2sql.validation_failure', $warningTelemetry['event'] ?? null, 'Mismatch override should log a validation_failure telemetry warning.');
assertSameValue('family_contract_mismatch', $warningTelemetry['stage'] ?? null, 'Mismatch override should classify the validation warning as a family contract mismatch.');
assertSameValue('model_output', $warningTelemetry['slotProvenance']['library'] ?? null, 'Mismatch override warnings should preserve model-output provenance for returned family slots.');
assertSameValue('model_output', $generatedTelemetry['slotProvenance']['library'] ?? null, 'Mismatch override generated telemetry should preserve model-output provenance for returned family slots.');
assertSameValue('legacy_fallback', $generatedTelemetry['route'] ?? null, 'Mismatch override generated telemetry should preserve the legacy_fallback route.');

Yii::$warnings = [];
Yii::$infos = [];
Yii::$app->params['nl2sqlForceLegacy'] = false;

assertThrowsRuntimeException(
    function () use ($familyRouteHelper): void {
        $familyRouteHelper->invoke(
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
                'promptFingerprint' => 'family-mismatch-telemetry-guard-fingerprint',
                'finishReason' => 'STOP',
                'attempts' => 1,
                'elapsedMs' => 5,
            ],
            null,
            function (): array {
                return [
                    'sql' => 'SELECT should_not_run',
                    'dataSource' => 'folio',
                ];
            }
        );
    },
    'legacy fallback is disabled for this route',
    'Covered-family mismatches should still fail safe when the legacy override is disabled.'
);

$guardWarnings = array_values(array_filter(
    Yii::$warnings,
    static function (array $entry): bool {
        return ($entry['category'] ?? null) === 'nl2sql.telemetry';
    }
));

assertCountValue(2, $guardWarnings, 'Guarded mismatches should emit both the mismatch warning and the guarded-failure warning.');

$guardTelemetry = decodeTelemetryRecord((string)($guardWarnings[1]['message'] ?? ''));

assertSameValue('nl2sql.validation_failure', $guardTelemetry['event'] ?? null, 'Guarded mismatches should log validation_failure telemetry.');
assertSameValue('family_fallback_guard', $guardTelemetry['stage'] ?? null, 'Guarded mismatches should classify the second warning as the fallback guard stage.');
assertSameValue('model_output', $guardTelemetry['slotProvenance']['library'] ?? null, 'Guarded mismatch warnings should preserve model-output slot provenance.');

fwrite(STDOUT, "GeminiService family mismatch telemetry test passed\n");