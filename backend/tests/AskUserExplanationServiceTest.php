<?php

require_once __DIR__ . '/../services/AskUserExplanationService.php';

use app\services\AskUserExplanationService;

function explanationAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function explanationAssertNotContains(string $needle, string $actual, string $message): void
{
    if (strpos($actual, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected text: " . $needle . "\nActual: " . $actual . "\n");
        exit(1);
    }
}

explanationAssertSame(
    null,
    AskUserExplanationService::notice('canonical', false, [], []),
    'Canonical reports must not show an AI-assisted notice.'
);

$ordinary = AskUserExplanationService::notice('exploratory', false, [], []);
explanationAssertSame('AI-assisted report', $ordinary['title'] ?? null, 'Ordinary exploratory reports need the standard title.');

$flagged = AskUserExplanationService::notice(
    'exploratory',
    true,
    ['cross_domain_analysis', 'material_repair'],
    []
);
explanationAssertSame(
    'AI-assisted report — review flagged',
    $flagged['title'] ?? null,
    'Flagged exploratory reports need the stronger title.'
);
explanationAssertSame(
    'This report combines information from more than one reporting area. '
        . 'The report needed a substantial automatic correction before it could run.',
    $flagged['message'] ?? null,
    'Internal review keys must be replaced by allowlisted reporting language.'
);

$bounded = AskUserExplanationService::notice(
    'exploratory',
    true,
    [
        'cross_domain_analysis',
        'cross_domain_analysis',
        'material_repair',
        'limited_semantic_coverage',
        'proxy_linkage',
        'not_an_allowlisted_reason',
    ],
    [['key' => 'internal_assumption_key', 'value' => 'internal_assumption_value']]
);
explanationAssertSame(
    'This report combines information from more than one reporting area. '
        . 'The report needed a substantial automatic correction before it could run. '
        . 'The checked requirements passed, but this is still an exploratory analysis.',
    $bounded['message'] ?? null,
    'Notices must contain at most three unique allowlisted sentences.'
);
explanationAssertNotContains('cross_domain_analysis', $bounded['message'] ?? '', 'Review-reason identifiers must never be interpolated.');
explanationAssertNotContains('not_an_allowlisted_reason', $bounded['message'] ?? '', 'Unknown identifiers must never be interpolated.');
explanationAssertNotContains('internal_assumption_key', $bounded['message'] ?? '', 'Internal assumption identifiers must never be interpolated.');
explanationAssertNotContains('internal_assumption_value', $bounded['message'] ?? '', 'Unallowlisted assumption values must never be interpolated.');

fwrite(STDOUT, "Ask user explanation service test passed\n");
