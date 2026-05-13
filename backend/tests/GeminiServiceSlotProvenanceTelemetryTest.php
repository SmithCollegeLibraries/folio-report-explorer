<?php

$queryIntentServicePath = __DIR__ . '/../services/QueryIntentService.php';
$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
    'QueryIntentService' => $queryIntentServicePath,
    'QueryFamilyContractService' => $contractServicePath,
    'QueryFamilySlotService' => $slotServicePath,
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

require_once $queryIntentServicePath;
require_once $contractServicePath;
require_once $slotServicePath;
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

$responseBuilder = new ReflectionMethod(GeminiService::class, 'buildQueryFamilyIntentResponse');
if (PHP_VERSION_ID < 80500) {
    $responseBuilder->setAccessible(true);
}

Yii::$infos = [];

$result = $responseBuilder->invoke(
    null,
    [
        'familyKey' => 'inventory_collection_age',
        'slots' => [
            'location' => 'Neilson Reference',
            'requested_outputs' => ['average_age_years'],
            'match_policy' => 'case_insensitive_contains',
        ],
    ],
    [
        'familyKey' => 'inventory_collection_age',
    ],
    'What is the average age of items in Neilson Library?',
    'Smith College',
    [
        'model' => 'test-model',
        'promptVersion' => GeminiService::FAMILY_SLOT_PROMPT_VERSION,
        'promptFingerprint' => 'slot-provenance-library-only-fingerprint',
        'finishReason' => 'STOP',
        'attempts' => 1,
        'elapsedMs' => 5,
    ],
    function (): array {
        return [
            'sql' => 'SELECT slot_provenance_stub',
            'dataSource' => 'folio',
            'route' => 'builder_intent',
            'queryDefinition' => [
                'tables' => ['inventory_items'],
                'columns' => [],
                'filters' => [],
                'joins' => [],
            ],
        ];
    }
);

assertSameValue('builder_intent', $result['route'] ?? null, 'The slot provenance telemetry test should stay on the builder_intent success path.');

$telemetryEntries = array_values(array_filter(
    Yii::$infos,
    static function (array $entry): bool {
        return ($entry['category'] ?? null) === 'nl2sql.telemetry';
    }
));

assertCountValue(1, $telemetryEntries, 'Successful builder_intent family responses should emit one structured nl2sql.generated telemetry record.');

$telemetry = decodeTelemetryRecord((string)($telemetryEntries[0]['message'] ?? ''));

assertSameValue('nl2sql.generated', $telemetry['event'] ?? null, 'Successful family responses should emit the nl2sql.generated telemetry event.');
assertSameValue('builder_intent', $telemetry['route'] ?? null, 'Successful family responses should record the builder_intent route in telemetry.');
assertSameValue(
    'prompt_explicit',
    $telemetry['slotProvenance']['library'] ?? null,
    'Library-only collection-age prompts should record that the recovered library scope came from explicit prompt text.'
);
assertSameValue(
    'default_context',
    $telemetry['slotProvenance']['campus'] ?? null,
    'Library-only collection-age prompts should record when campus scope came from the home-campus default.'
);
assertSameValue(
    'policy_omitted_explicit_prompt_only',
    $telemetry['slotProvenance']['location'] ?? null,
    'Library-only collection-age prompts should record when an implicit location slot was stripped by the explicit-only policy.'
);

fwrite(STDOUT, "GeminiService slot provenance telemetry test passed\n");