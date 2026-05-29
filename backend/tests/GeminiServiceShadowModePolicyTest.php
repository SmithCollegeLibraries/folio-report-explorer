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
        'nl2sqlPrimaryMode' => 'legacy',
        'nl2sqlIntentMode' => false,
        'nl2sqlForceLegacy' => false,
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

$modeResolver = new ReflectionMethod(GeminiService::class, 'resolvePrimaryModeForPrompt');

assertSameValue(
    'intent',
    $modeResolver->invoke(null, 'What is the average age of items in the Neilson Reference collection?', 'Smith College'),
    'Covered-family prompts should prefer intent mode even when the configured primary mode is legacy.'
);

assertSameValue(
    'legacy',
    $modeResolver->invoke(null, 'Show me all purchase orders created yesterday.', 'Smith College'),
    'Unsupported prompts should preserve the configured legacy primary mode.'
);

Yii::$app->params['nl2sqlPrimaryMode'] = 'intent';
Yii::$app->params['nl2sqlIntentMode'] = true;

assertSameValue(
    'legacy',
    $modeResolver->invoke(null, 'Find records in SC Internet and summarize MARC field 035 9 subfield a.', 'Smith College'),
    'MARC field/source-record prompts should use legacy freeform SQL because structured intent columns cannot represent JSON extraction expressions.'
);

Yii::$app->params['nl2sqlPrimaryMode'] = 'auto';
Yii::$app->params['nl2sqlIntentMode'] = false;

assertSameValue(
    'intent',
    $modeResolver->invoke(null, 'What is the average age of items in the Neilson Reference collection?', 'Smith College'),
    'Covered-family prompts should still prefer intent mode when auto would otherwise resolve to legacy.'
);

Yii::$app->params['nl2sqlForceLegacy'] = true;

assertSameValue(
    'legacy',
    $modeResolver->invoke(null, 'What is the average age of items in the Neilson Reference collection?', 'Smith College'),
    'Emergency force-legacy mode should override the covered-family intent preference.'
);

fwrite(STDOUT, "GeminiService shadow mode policy test passed\n");