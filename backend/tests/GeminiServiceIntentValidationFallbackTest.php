<?php

$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

if (!file_exists($geminiServicePath)) {
    fwrite(STDERR, "GeminiService is missing at {$geminiServicePath}\n");
    exit(1);
}

$source = (string)file_get_contents($geminiServicePath);

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

assertContainsText(
    "\$reason = 'intent_contract_failed';",
    $source,
    'Invalid structured intent responses should use a stable legacy fallback reason.'
);
assertContainsText(
    "self::logRouteSelection('legacy_fallback', \$reason",
    $source,
    'Invalid structured intent responses should log a legacy fallback route instead of surfacing a builder error.'
);
assertContainsText(
    '$fallback = self::generateSql($prompt, $campus, true);',
    $source,
    'Invalid structured intent responses should retry through legacy SQL generation.'
);
assertContainsText(
    '$fallback[\'routeReason\'] = $reason;',
    $source,
    'Legacy fallback responses should expose the intent validation fallback reason.'
);

fwrite(STDOUT, "GeminiService intent validation fallback test passed\n");
