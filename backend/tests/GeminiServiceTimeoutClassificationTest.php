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

foreach ([
    'AI API key not configured. Set GEMINI_API_KEY or OPENAI_API_KEY in .env.',
    'AI API error: quota exceeded',
    'AI request failed: provider unavailable',
    'OpenAI fallback request failed: connection reset',
    'MAX_TOKENS',
    'The AI response was truncated because the query is too complex. Try simplifying your request or asking for fewer fields.',
] as $providerFailure) {
    assertSameValue(
        true,
        GeminiService::isAiProviderFailureMessage($providerFailure),
        'GeminiService should classify its provider exception messages for hard controller handling.'
    );
}

assertSameValue(
    false,
    GeminiService::isAiProviderFailureMessage('connection does not exist'),
    'Database connectivity messages must not be mislabeled as AI provider failures.'
);

assertSameValue(
    false,
    GeminiService::isAiProviderFailureMessage('The report must include the provider failure rate.'),
    'Business-language mentions of a provider failure rate must not be mistaken for an AI transport failure.'
);

foreach ([
    'The report must include billing details.',
    'Show quota usage by department.',
    'Include the rate limit column.',
    'Include HTTP 403 response counts.',
] as $businessProviderVocabulary) {
    assertSameValue(
        false,
        GeminiService::isAiProviderFailureMessage($businessProviderVocabulary),
        'Business report vocabulary must not be mistaken for an AI provider failure.'
    );
}

$hardCanonicalFailure = new ReflectionMethod(GeminiService::class, 'isHardCanonicalFailure');
if (PHP_VERSION_ID < 80100) {
    $hardCanonicalFailure->setAccessible(true);
}
assertSameValue(
    false,
    $hardCanonicalFailure->invoke(null, new RuntimeException('The report must include the provider failure rate.')),
    'Canonical compiler diagnostics containing ordinary business language must remain eligible for Lane 2.'
);

fwrite(STDOUT, "GeminiService timeout classification test passed\n");
