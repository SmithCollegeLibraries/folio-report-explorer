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

if (!method_exists(GeminiService::class, 'buildOrganizationAcquisitionUnitGuidance')) {
    fwrite(STDERR, "The shared organization acquisition-unit guidance builder is missing.\n");
    exit(1);
}
$organizationGuidanceBuilder = new ReflectionMethod(
    GeminiService::class,
    'buildOrganizationAcquisitionUnitGuidance'
);
if (PHP_VERSION_ID < 80500) {
    $organizationGuidanceBuilder->setAccessible(true);
}
$organizationGuidance = $organizationGuidanceBuilder->invoke(null);
foreach ([
    'organizations.organizations__t__interfaces',
    'organizations.organizations__t__acq_unit_ids',
    'orders.acquisitions_unit__t',
    'purchase_order__t__acq_unit_ids.id is the purchase order ID',
    'acquisition-unit codes use exact equality',
    'Organization and interface reference-data listings do not require an artificial purchase-order campus path',
] as $guidanceAnchor) {
    assertContainsText(
        $guidanceAnchor,
        $organizationGuidance,
        'The shared organization guidance must describe every authoritative relationship and carve-out.'
    );
}

if (!method_exists(GeminiService::class, 'semanticContractQuestion')) {
    fwrite(STDERR, "The raw-question semantic contract selector is missing.\n");
    exit(1);
}
$semanticQuestionSelector = new ReflectionMethod(GeminiService::class, 'semanticContractQuestion');
if (PHP_VERSION_ID < 80500) {
    $semanticQuestionSelector->setAccessible(true);
}
$rawOrganizationQuestion = 'List organization interfaces limited to the AC acquisition unit';
$resolverAugmentedQuestion = $rawOrganizationQuestion
    . "\n\nReference resolver guidance: purchase orders are transaction records.";
assertSameValue(
    $rawOrganizationQuestion,
    $semanticQuestionSelector->invoke(null, $rawOrganizationQuestion, $resolverAugmentedQuestion),
    'Resolver-augmented transactional vocabulary must not change contract applicability.'
);
$trustedFollowUpEnvelope = "This is a follow-up request to a previously generated library report.\n\n"
    . "Previous request: Show ROI for purchases and circulation by call number.\n"
    . "Follow-up request: Use invoice date instead.";
assertSameValue(
    $trustedFollowUpEnvelope,
    $semanticQuestionSelector->invoke(null, 'Use invoice date instead.', $trustedFollowUpEnvelope),
    'A trusted follow-up envelope must retain the prior request semantics for contract classification.'
);

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

if (!method_exists(GeminiService::class, 'buildReferenceNameMatchingGuidance')) {
    fwrite(STDERR, "The reference name-matching guidance builder is missing.\n");
    exit(1);
}
$nameMatchingBuilder = new ReflectionMethod(GeminiService::class, 'buildReferenceNameMatchingGuidance');
if (PHP_VERSION_ID < 80500) {
    $nameMatchingBuilder->setAccessible(true);
}

$unresolvedNameGuidance = $nameMatchingBuilder->invoke(null, []);
assertContainsText(
    'ILIKE with wildcards',
    $unresolvedNameGuidance,
    'Without resolved reference filters the prompt must keep the wildcard matching guidance.'
);

$resolvedNameGuidance = $nameMatchingBuilder->invoke(null, [
    [
        'dimension' => 'library',
        'source_table' => 'inventory.loclibrary__t',
        'column' => 'name',
        'values' => ['SC Hillyer Art Library'],
    ],
]);
assertNotContainsText(
    'ILIKE with wildcards',
    $resolvedNameGuidance,
    'Resolved reference filters must suppress the wildcard matching guidance that the resolved-reference validator rejects.'
);
assertContainsText(
    'exactly as supplied',
    $resolvedNameGuidance,
    'Resolved reference filters must instruct exact-value matching.'
);
foreach ([
    'single SELECT',
    'WITH',
    'GROUP BY',
    'JOIN inventory.material_type__t',
] as $shapeAnchor) {
    assertContainsText(
        $shapeAnchor,
        $resolvedNameGuidance,
        'Resolved reference filters must instruct the flat query shape the validator can verify.'
    );
}

fwrite(STDOUT, "GeminiService legacy prompt guidance test passed\n");
