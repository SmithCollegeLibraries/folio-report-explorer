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
    'params' => [
        'aiProvider' => 'gemini',
        'geminiApiKey' => '',
        'openaiApiKey' => '',
        'openaiModel' => 'gpt-4.1-mini',
    ],
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

$providerResolver = new ReflectionMethod(GeminiService::class, 'getAiProvider');
$keyResolver = new ReflectionMethod(GeminiService::class, 'getAiApiKey');

assertSameValue(
    'none',
    $providerResolver->invoke(null),
    'When no AI keys are configured, the provider resolver should report that no AI provider is configured instead of pinning a missing provider.'
);
assertSameValue(
    '',
    $keyResolver->invoke(null),
    'When no AI keys are configured, the API key resolver should return an empty string.'
);

Yii::$app->params['openaiApiKey'] = 'openai-test-key';

assertSameValue(
    'openai',
    $providerResolver->invoke(null),
    'When the preferred provider has no key but OpenAI does, the provider resolver should fall back to OpenAI.'
);
assertSameValue(
    'openai-test-key',
    $keyResolver->invoke(null),
    'The API key resolver should return the fallback provider key when falling back to OpenAI.'
);

Yii::$app->params['aiProvider'] = 'openai';
Yii::$app->params['geminiApiKey'] = 'gemini-test-key';
Yii::$app->params['openaiApiKey'] = '';

assertSameValue(
    'gemini',
    $providerResolver->invoke(null),
    'When OpenAI is selected but only Gemini has a key, the provider resolver should fall back to Gemini.'
);
assertSameValue(
    'gemini-test-key',
    $keyResolver->invoke(null),
    'The API key resolver should return the fallback provider key when falling back to Gemini.'
);

fwrite(STDOUT, "GeminiService AI config resolution test passed\n");