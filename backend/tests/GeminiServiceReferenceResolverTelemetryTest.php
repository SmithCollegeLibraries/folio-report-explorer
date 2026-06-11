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
            self::$infos[] = ['message' => $message, 'category' => $category];
        }

        public static function warning($message, $category = null)
        {
            self::$warnings[] = ['message' => $message, 'category' => $category];
        }
    }
}

Yii::$app = (object) ['params' => []];

require_once $geminiServicePath;

use app\services\GeminiService;

function assertTelemetrySame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTelemetryTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function decodeResolverTelemetry(string $message): array
{
    $prefix = 'NL2SQL telemetry: ';
    if (strpos($message, $prefix) !== 0) {
        fwrite(STDERR, "Telemetry message did not start with expected prefix.\nMessage: {$message}\n");
        exit(1);
    }

    $decoded = json_decode(substr($message, strlen($prefix)), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Telemetry payload was not valid JSON.\nMessage: {$message}\n");
        exit(1);
    }

    return $decoded;
}

$logger = new ReflectionMethod(GeminiService::class, 'logReferenceResolverTelemetry');
$logger->setAccessible(true);

Yii::$infos = [];
Yii::$warnings = [];

$logger->invoke(null, [
    'needsClarification' => false,
    'routeReason' => 'reference_resolver_guidance',
    'resolvedReferences' => [
        ['source_table' => 'inventory.location__t', 'name' => 'SC Josten Treasure', 'matched_by' => 'code'],
        ['source_table' => 'inventory.location__t', 'name' => 'SC Josten Treasure Folio', 'matched_by' => 'location_hierarchy_partial'],
    ],
], 'prompt-fingerprint');

assertTelemetrySame(1, count(Yii::$infos), 'Resolved reference telemetry should emit one info event.');
$matchTelemetry = decodeResolverTelemetry(Yii::$infos[0]['message'] ?? '');
assertTelemetrySame('nl2sql.reference_resolver_match', $matchTelemetry['event'] ?? null, 'Resolved references should emit a match event.');
assertTelemetrySame('prompt-fingerprint', $matchTelemetry['promptFingerprint'] ?? null, 'Resolved reference telemetry should include prompt fingerprint.');
assertTelemetrySame(2, $matchTelemetry['resolvedCount'] ?? null, 'Resolved reference telemetry should include resolved count.');
assertTelemetryTrue(in_array('inventory.location__t', $matchTelemetry['sourceTables'] ?? [], true), 'Resolved reference telemetry should include source tables.');

$logger->invoke(null, [
    'needsClarification' => true,
    'routeReason' => 'reference_resolver_ambiguous_reference',
    'clarificationType' => 'batch_local_reference_resolution',
    'clarificationItems' => [
        ['term' => 'Josten'],
    ],
], 'clarification-fingerprint');

assertTelemetrySame(1, count(Yii::$warnings), 'Clarification telemetry should emit one warning event.');
$clarificationTelemetry = decodeResolverTelemetry(Yii::$warnings[0]['message'] ?? '');
assertTelemetrySame('nl2sql.reference_resolver_clarification', $clarificationTelemetry['event'] ?? null, 'Clarifications should emit a resolver clarification event.');
assertTelemetrySame('reference_resolver_ambiguous_reference', $clarificationTelemetry['routeReason'] ?? null, 'Clarification telemetry should include route reason.');
assertTelemetrySame(1, $clarificationTelemetry['clarificationItemCount'] ?? null, 'Clarification telemetry should include item count.');

fwrite(STDOUT, "GeminiService reference resolver telemetry test passed\n");
