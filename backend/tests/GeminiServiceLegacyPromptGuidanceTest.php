<?php

$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$slotServicePath = __DIR__ . '/../services/QueryFamilySlotService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
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
    }
}

Yii::$app = (object) [
    'params' => [],
];

require_once $contractServicePath;
require_once $slotServicePath;
require_once $geminiServicePath;

use app\services\GeminiService;

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\nActual text:\n{$haystack}\n");
        exit(1);
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected text: {$needle}\nActual text:\n{$haystack}\n");
        exit(1);
    }
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$guidanceBuilder = new ReflectionMethod(GeminiService::class, 'buildLegacyPromptFamilyGuidance');
if (PHP_VERSION_ID < 80500) {
    $guidanceBuilder->setAccessible(true);
}

$collectionAgeGuidance = $guidanceBuilder->invoke(
    null,
    'What is the average age of items in the Neilson Reference collection?',
    'Smith College'
);

assertContainsText(
    'inventory.instance__t__publication',
    $collectionAgeGuidance,
    'Collection-age legacy guidance should anchor age calculations on the instance publication subtable.'
);
assertContainsText(
    'publication__date_of_publication',
    $collectionAgeGuidance,
    'Collection-age legacy guidance should explicitly require publication-year-based age logic.'
);
assertContainsText(
    'metadata__created_date',
    $collectionAgeGuidance,
    'Collection-age legacy guidance should explicitly forbid record-created-date age calculations.'
);
assertContainsText(
    'inventory.location__t',
    $collectionAgeGuidance,
    'Collection-age legacy guidance should require the standard item location join path.'
);
assertContainsText(
    'inventory.loclibrary__t',
    $collectionAgeGuidance,
    'Collection-age legacy guidance should require a separate library scope join.'
);

$genericGuidance = $guidanceBuilder->invoke(
    null,
    'Show me all purchase orders created yesterday.',
    'Smith College'
);

assertSameValue(
    '',
    $genericGuidance,
    'Uncovered prompts should not receive extra legacy prompt-family guidance.'
);

$promptInputBuilder = new ReflectionMethod(GeminiService::class, 'buildLegacyPromptUserInput');
if (PHP_VERSION_ID < 80500) {
    $promptInputBuilder->setAccessible(true);
}

$collectionAgePromptInput = $promptInputBuilder->invoke(
    null,
    'What is the average age of items in the Neilson Reference collection?',
    'Smith College'
);

assertContainsText(
    'What is the average age of items in the Neilson Reference collection?',
    $collectionAgePromptInput,
    'Collection-age legacy prompt input should preserve the original user prompt.'
);
assertContainsText(
    "Library scope: Neilson Library",
    $collectionAgePromptInput,
    'Collection-age legacy prompt input should inject the recovered library scope explicitly.'
);
assertContainsText(
    "Use a library predicate that preserves the full phrase '%Neilson Library%'",
    $collectionAgePromptInput,
    'Collection-age legacy prompt input should explicitly require preserving the full Neilson Library phrase in the library predicate.'
);
assertContainsText(
    "Location scope: Neilson Reference",
    $collectionAgePromptInput,
    'Collection-age legacy prompt input should inject the recovered location scope explicitly.'
);
assertContainsText(
    "Use a location predicate that preserves the full phrase '%Neilson Reference%'",
    $collectionAgePromptInput,
    'Collection-age legacy prompt input should explicitly require preserving the full Neilson Reference phrase in the location predicate.'
);
assertContainsText(
    'Do not use metadata__created_date',
    $collectionAgePromptInput,
    'Collection-age legacy prompt input should explicitly forbid metadata-created-date age calculations.'
);

$genericPromptInput = $promptInputBuilder->invoke(
    null,
    'Show me all purchase orders created yesterday.',
    'Smith College'
);

$libraryOnlyCollectionAgePromptInput = $promptInputBuilder->invoke(
    null,
    'What is the average age of items in Neilson Library?',
    'Smith College'
);

assertContainsText(
    'What is the average age of items in Neilson Library?',
    $libraryOnlyCollectionAgePromptInput,
    'Library-only collection-age legacy prompt input should preserve the original user prompt.'
);
assertContainsText(
    'Library scope: Neilson Library',
    $libraryOnlyCollectionAgePromptInput,
    'Library-only collection-age legacy prompt input should inject the recovered library scope explicitly.'
);
assertNotContainsText(
    'Location scope:',
    $libraryOnlyCollectionAgePromptInput,
    'Library-only collection-age legacy prompt input should not invent a location scope when the prompt only asks for Neilson Library.'
);
assertNotContainsText(
    'Use a location predicate that preserves the full phrase',
    $libraryOnlyCollectionAgePromptInput,
    'Library-only collection-age legacy prompt input should not add location predicate guidance when the prompt never requested a location.'
);

assertSameValue(
    'Show me all purchase orders created yesterday.',
    $genericPromptInput,
    'Uncovered prompts should preserve the original legacy prompt input without extra rewrite text.'
);

fwrite(STDOUT, "GeminiService legacy prompt guidance test passed\n");