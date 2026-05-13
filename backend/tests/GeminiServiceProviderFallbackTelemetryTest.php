<?php

$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

if (!file_exists($geminiServicePath)) {
    fwrite(STDERR, "GeminiService is missing at {$geminiServicePath}\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
        public static $infos = [];
        public static $warnings = [];

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

$reasonClassifier = new ReflectionMethod(GeminiService::class, 'classifyProviderFallbackReason');
if (PHP_VERSION_ID < 80500) {
    $reasonClassifier->setAccessible(true);
}

assertSameValue(
    'quota_exhausted',
    $reasonClassifier->invoke(null, 429, 'RESOURCE_EXHAUSTED: free tier quota exceeded'),
    'Quota-exhausted Gemini failures should be classified with an explicit quota_exhausted provider-fallback reason code.'
);

assertSameValue(
    'provider_failure',
    $reasonClassifier->invoke(null, 500, 'backend error'),
    'Non-quota provider fallbacks should still collapse into a stable provider_failure reason code instead of reusing the human error text.'
);

$fallbackLogger = new ReflectionMethod(GeminiService::class, 'logProviderFallback');
if (PHP_VERSION_ID < 80500) {
    $fallbackLogger->setAccessible(true);
}

Yii::$warnings = [];

$fallbackLogger->invoke(
    null,
    'gemini',
    'openai',
    'generateSql',
    429,
    'RESOURCE_EXHAUSTED: free tier quota exceeded'
);

assertCountValue(
    1,
    Yii::$warnings,
    'Provider fallback logging should emit exactly one structured warning event.'
);

$warningEntry = Yii::$warnings[0];
assertSameValue(
    'nl2sql.telemetry',
    $warningEntry['category'] ?? null,
    'Provider fallback telemetry should use the structured nl2sql.telemetry log category so the shadow metrics report can parse it reliably.'
);

$telemetry = decodeTelemetryRecord($warningEntry['message'] ?? '');

assertSameValue(
    'nl2sql.provider_fallback',
    $telemetry['event'] ?? null,
    'Provider fallback telemetry should declare the nl2sql.provider_fallback event name.'
);
assertSameValue(
    'gemini',
    $telemetry['sourceProvider'] ?? null,
    'Provider fallback telemetry should record the source provider.'
);
assertSameValue(
    'openai',
    $telemetry['targetProvider'] ?? null,
    'Provider fallback telemetry should record the fallback provider.'
);
assertSameValue(
    'quota_exhausted',
    $telemetry['reasonCode'] ?? null,
    'Provider fallback telemetry should expose a normalized reason code for report bucketing.'
);
assertSameValue(
    'generateSql',
    $telemetry['context'] ?? null,
    'Provider fallback telemetry should preserve the request metric context.'
);
assertSameValue(
    429,
    $telemetry['statusCode'] ?? null,
    'Provider fallback telemetry should preserve the source-provider HTTP status when one exists.'
);

fwrite(STDOUT, "GeminiService provider fallback telemetry test passed\n");