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

assertSameValue(
    true,
    GeminiService::isAiTimeoutMessage('AI request failed: Operation timed out after 60 seconds'),
    'GeminiService should classify transport timeout messages as AI timeouts.'
);

assertSameValue(
    true,
    GeminiService::isAiTimeoutMessage('AI API error: deadline exceeded'),
    'GeminiService should classify provider deadline failures as AI timeouts.'
);

assertSameValue(
    false,
    GeminiService::isAiTimeoutMessage('AI API error: invalid API key'),
    'GeminiService should not classify unrelated provider failures as AI timeouts.'
);

fwrite(STDOUT, "GeminiService timeout classification test passed\n");
