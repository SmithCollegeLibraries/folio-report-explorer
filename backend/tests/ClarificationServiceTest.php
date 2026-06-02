<?php

$servicePath = __DIR__ . '/../services/ClarificationService.php';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "ClarificationService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\ClarificationService;

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
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

$plainMrbc = ClarificationService::detectPromptAmbiguity(
    'Show me every bibliographic record in MRBC with a Dewey classification number.'
);

assertSameValue(true, $plainMrbc['needsClarification'] ?? null, 'Plain MRBC prompts should request clarification.');
assertSameValue('location_alias.mrbc', $plainMrbc['clarificationKey'] ?? null, 'Plain MRBC clarification should use a stable learning key.');
assertSameValue('single_choice', $plainMrbc['inputType'] ?? null, 'Plain MRBC clarification should be a single-choice prompt.');
assertSameValue(true, $plainMrbc['freeTextAllowed'] ?? null, 'Plain MRBC clarification should allow free-text fallback.');
assertSameValue('sc_rare_book_collection', $plainMrbc['options'][0]['id'] ?? null, 'MRBC clarification should recommend the base rare book collection first.');
assertSameValue(true, $plainMrbc['options'][0]['recommended'] ?? null, 'MRBC base rare book collection option should be marked recommended.');
assertSameValue('SC Rare Book Collection Reference', $plainMrbc['options'][1]['label'] ?? null, 'MRBC clarification should include the reference collection option.');

$referenceMrbc = ClarificationService::detectPromptAmbiguity(
    'List holdings in the MRBC Reference collection.'
);

assertSameValue(true, $referenceMrbc['needsClarification'] ?? null, 'MRBC Reference prompts should ask users to confirm the resolved alias.');
assertSameValue('confirm_resolved_alias', $referenceMrbc['clarificationType'] ?? null, 'MRBC Reference prompts should use soft confirmation.');
assertSameValue('location_alias.mrbc_reference', $referenceMrbc['clarificationKey'] ?? null, 'MRBC Reference confirmations should use a stable learning key.');
assertSameValue('Continue with SC Rare Book Collection Reference', $referenceMrbc['options'][0]['label'] ?? null, 'MRBC Reference confirmation should expose the resolved location.');

$learnedReferenceMrbc = ClarificationService::detectPromptAmbiguity(
    'List holdings in the MRBC Reference collection.',
    ['location_alias.mrbc_reference']
);

assertSameValue(null, $learnedReferenceMrbc, 'Previously accepted MRBC Reference confirmations should not ask the same user again.');

$collectionMrbc = ClarificationService::detectPromptAmbiguity(
    'List holdings in the MRBC Collection.'
);

assertSameValue(true, $collectionMrbc['needsClarification'] ?? null, 'MRBC Collection prompts should ask users to confirm the resolved alias.');
assertSameValue('location_alias.mrbc_collection', $collectionMrbc['clarificationKey'] ?? null, 'MRBC Collection confirmations should use a stable learning key.');
assertSameValue('Continue with SC Rare Book Collection', $collectionMrbc['options'][0]['label'] ?? null, 'MRBC Collection confirmation should expose the resolved location.');

$alreadyClarified = ClarificationService::detectPromptAmbiguity(
    'List holdings in MRBC Reference Collection. Clarification: Use inventory.location__t.name = SC Rare Book Collection Reference for MRBC.'
);

assertSameValue(null, $alreadyClarified, 'Clarified prompts should not loop back into another confirmation.');

$fiveColleges = ClarificationService::buildPromptGuidance(
    'Which other institutions in the 5 Collegse hold this same title?'
);

assertContainsText(
    'Treat "5 Collegse" as "Five Colleges"',
    implode("\n", $fiveColleges),
    'Five Colleges typo guidance should normalize 5 Collegse without requiring clarification.'
);

fwrite(STDOUT, "ClarificationService test passed\n");
