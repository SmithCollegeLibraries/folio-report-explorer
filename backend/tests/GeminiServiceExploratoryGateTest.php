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

        public static function info($message, $category = null)
        {
        }

        public static function warning($message, $category = null)
        {
        }
    }
}

Yii::$app = (object) [
    'params' => [
        'nl2sqlForceLegacy' => false,
    ],
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

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\nActual: {$haystack}\n");
        exit(1);
    }
}

$builder = new ReflectionMethod(GeminiService::class, 'buildExploratoryApprovalResponse');
$unsupported = $builder->invoke(
    null,
    'Show vendors with the highest invoice spend last fiscal year.',
    'Smith College',
    'unsupported_query_family'
);

assertSameValue(true, $unsupported['needsExploratoryApproval'] ?? null, 'Unsupported prompts should require explicit exploratory approval before SQL generation.');
assertSameValue('exploratory_approval_required', $unsupported['route'] ?? null, 'Unsupported prompts should use the exploratory approval route.');
assertSameValue('unsupported_query_family', $unsupported['routeReason'] ?? null, 'Unsupported prompts should preserve the blocking reason.');
assertSameValue('exploratory', $unsupported['mode'] ?? null, 'Unsupported prompts should be labeled as exploratory.');
assertSameValue(null, $unsupported['sql'] ?? null, 'Unsupported exploratory approval responses should not include SQL.');
assertSameValue('Smith College', $unsupported['exploratoryPlan']['campus'] ?? null, 'The exploratory approval plan should include campus context.');
assertContainsText(
    'outside the report types',
    $unsupported['message'] ?? '',
    'Unsupported prompts should explain the limitation in user-facing language.'
);
assertContainsText(
    'similar wording may not always produce the same query',
    $unsupported['message'] ?? '',
    'Unsupported prompts should clearly disclose repeatability risk.'
);

fwrite(STDOUT, "GeminiService exploratory gate test passed\n");
