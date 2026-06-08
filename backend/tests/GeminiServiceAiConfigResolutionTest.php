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
        'aiProvider' => 'openai',
        'geminiApiKey' => '',
        'openaiApiKey' => '',
        'openaiModel' => 'gpt-5.4',
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

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$providerResolver = new ReflectionMethod(GeminiService::class, 'getAiProvider');
$keyResolver = new ReflectionMethod(GeminiService::class, 'getAiApiKey');
$modelResolver = new ReflectionMethod(GeminiService::class, 'getAiModel');
$openAiPayloadBuilder = new ReflectionMethod(GeminiService::class, 'buildOpenAiPayloadFromGeminiShape');

assertSameValue(
    'none',
    $providerResolver->invoke(null),
    'When the pinned provider has no API key, the provider resolver should report no usable provider instead of switching providers.'
);
assertSameValue(
    '',
    $keyResolver->invoke(null),
    'When the pinned provider has no API key, the API key resolver should return an empty string.'
);
assertSameValue(
    'gpt-5.4',
    $modelResolver->invoke(null),
    'The OpenAI model resolver should use the pinned GPT-5.4 default for NL2SQL.'
);

$openAiPayload = $openAiPayloadBuilder->invoke(null, [
    'system_instruction' => [
        'parts' => [['text' => 'Return JSON.']],
    ],
    'contents' => [
        [
            'parts' => [['text' => 'Count records.']],
        ],
    ],
    'generationConfig' => [
        'temperature' => 0.1,
        'maxOutputTokens' => 8192,
        'responseMimeType' => 'application/json',
    ],
]);
assertSameValue(
    'gpt-5.4',
    $openAiPayload['model'] ?? null,
    'OpenAI payload translation should keep the configured GPT-5.4 model.'
);
assertSameValue(
    8192,
    $openAiPayload['max_completion_tokens'] ?? null,
    'GPT-5.x Chat Completions payloads should use max_completion_tokens.'
);
assertTrueValue(
    !array_key_exists('max_tokens', $openAiPayload),
    'GPT-5.x Chat Completions payloads should not include unsupported max_tokens.'
);
assertSameValue(
    ['type' => 'json_object'],
    $openAiPayload['response_format'] ?? null,
    'OpenAI payload translation should preserve JSON response format requests.'
);

Yii::$app->params['openaiModel'] = 'gpt-4.1';
$openAiPayloadGpt4 = $openAiPayloadBuilder->invoke(null, [
    'system_instruction' => [
        'parts' => [['text' => 'Return JSON.']],
    ],
    'contents' => [
        [
            'parts' => [['text' => 'Count records.']],
        ],
    ],
    'generationConfig' => [
        'temperature' => 0.1,
        'maxOutputTokens' => 8192,
        'responseMimeType' => 'application/json',
    ],
]);
assertSameValue(
    'gpt-4.1',
    $openAiPayloadGpt4['model'] ?? null,
    'OpenAI payload translation should propagate a configured gpt-4.1 model.'
);
assertSameValue(
    8192,
    $openAiPayloadGpt4['max_completion_tokens'] ?? null,
    'gpt-4.1 payloads should use max_completion_tokens.'
);
assertTrueValue(
    !array_key_exists('max_tokens', $openAiPayloadGpt4),
    'gpt-4.1 payloads should not include max_tokens.'
);

Yii::$app->params['openaiApiKey'] = 'openai-test-key';

assertSameValue(
    'openai',
    $providerResolver->invoke(null),
    'When OpenAI is pinned and configured, the provider resolver should use OpenAI.'
);
assertSameValue(
    'openai-test-key',
    $keyResolver->invoke(null),
    'The API key resolver should return the pinned OpenAI provider key.'
);

Yii::$app->params['geminiApiKey'] = 'gemini-test-key';
Yii::$app->params['openaiApiKey'] = '';

assertSameValue(
    'none',
    $providerResolver->invoke(null),
    'When OpenAI is pinned but only Gemini has a key, the provider resolver should not silently switch to Gemini.'
);
assertSameValue(
    '',
    $keyResolver->invoke(null),
    'When OpenAI is pinned but only Gemini has a key, the API key resolver should return an empty string.'
);

Yii::$app->params['aiProvider'] = 'gemini';
Yii::$app->params['geminiApiKey'] = 'gemini-test-key';
Yii::$app->params['openaiApiKey'] = 'openai-test-key';

assertSameValue(
    'gemini',
    $providerResolver->invoke(null),
    'When Gemini is explicitly pinned and configured, the provider resolver should use Gemini.'
);

fwrite(STDOUT, "GeminiService AI config resolution test passed\n");
