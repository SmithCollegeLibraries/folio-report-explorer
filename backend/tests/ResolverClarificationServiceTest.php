<?php

$servicePath = __DIR__ . '/../services/ResolverClarificationService.php';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "ResolverClarificationService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\ResolverClarificationService;

function assertResolverClarificationSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertResolverClarificationContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

$deterministic = [
    'needsClarification' => true,
    'clarificationType' => 'batch_local_reference_resolution',
    'clarificationBatchId' => 'batch-1',
    'question' => 'I checked the local reference cache and approved lookup fields before generating SQL.',
    'message' => 'These terms were not exact local reference matches.',
    'resolverTrace' => [
        ['label' => 'Checked cached reference tables for "Riverside"', 'status' => 'no_match'],
        ['label' => 'Found possible match in inventory.contributor__t.name', 'status' => 'found', 'detail' => 'Riverside Press'],
    ],
    'clarificationItems' => [
        [
            'term' => 'Riverside',
            'clarificationKey' => 'safe_probe.riverside.collection',
            'question' => 'I did not find "Riverside" in the local reference cache, but found possible matches in approved fields. Where should I search?',
            'confidence' => 'safe_probe_match',
            'inputType' => 'single_choice',
            'freeTextAllowed' => true,
            'options' => [
                [
                    'id' => 'inventory_contributor_name_riverside',
                    'label' => 'Search inventory.contributor__t.name for "Riverside"',
                    'resolvedFilter' => [
                        'table' => 'inventory.contributor__t',
                        'column' => 'name',
                        'operator' => 'ILIKE',
                        'value' => '%Riverside%',
                    ],
                ],
            ],
        ],
    ],
    'route' => 'clarification',
    'routeReason' => 'reference_resolver_safe_probe_clarification',
];

$service = new ResolverClarificationService(function (string $prompt, array $resolverResponse): array {
    return [
        'question' => 'I could not resolve "Riverside" as a cached collection. Should I search the contributor match that the resolver found?',
        'message' => 'The resolver found one approved candidate outside the reference cache.',
        'clarificationItems' => [
            [
                'clarificationKey' => 'safe_probe.riverside.collection',
                'question' => 'Use the contributor match for this report?',
                'options' => [
                    ['id' => 'inventory_contributor_name_riverside'],
                ],
            ],
        ],
    ];
});

$modelResult = $service->buildClarification('Show Riverside collection titles', $deterministic);
assertResolverClarificationSame('resolver_model_clarification', $modelResult['routeReason'] ?? null, 'Valid model clarification should mark model route reason.');
assertResolverClarificationSame('model', $modelResult['clarificationSource'] ?? null, 'Valid model clarification should identify model source.');
assertResolverClarificationContains('Should I search', $modelResult['question'] ?? '', 'Valid model question should replace deterministic wording.');
assertResolverClarificationSame(
    'inventory.contributor__t',
    $modelResult['clarificationItems'][0]['options'][0]['resolvedFilter']['table'] ?? null,
    'Model clarification should preserve resolver-provided filters instead of trusting model filters.'
);

$inventingService = new ResolverClarificationService(function (string $prompt, array $resolverResponse): array {
    return [
        'question' => 'Should I search the invented table?',
        'clarificationItems' => [
            [
                'clarificationKey' => 'safe_probe.riverside.collection',
                'options' => [
                    [
                        'id' => 'made_up_option',
                        'resolvedFilter' => ['table' => 'inventory.item__t', 'column' => 'barcode'],
                    ],
                ],
            ],
        ],
    ];
});

$fallbackResult = $inventingService->buildClarification('Show Riverside collection titles', $deterministic);
assertResolverClarificationSame('resolver_deterministic_fallback', $fallbackResult['routeReason'] ?? null, 'Invalid model options should fall back deterministically.');
assertResolverClarificationSame('model_invalid_option', $fallbackResult['modelClarificationFallbackReason'] ?? null, 'Fallback should expose validation reason.');
assertResolverClarificationSame('deterministic', $fallbackResult['clarificationSource'] ?? null, 'Fallback should identify deterministic source.');
assertResolverClarificationSame(
    'inventory_contributor_name_riverside',
    $fallbackResult['clarificationItems'][0]['options'][0]['id'] ?? null,
    'Fallback should retain resolver-provided options.'
);

$sqlService = new ResolverClarificationService(function (string $prompt, array $resolverResponse): array {
    return [
        'question' => 'SELECT * FROM inventory.instance__t',
        'sql' => 'SELECT * FROM inventory.instance__t',
        'clarificationItems' => [],
    ];
});

$sqlFallback = $sqlService->buildClarification('Show Riverside collection titles', $deterministic);
assertResolverClarificationSame('resolver_deterministic_fallback', $sqlFallback['routeReason'] ?? null, 'Model SQL should be rejected.');
assertResolverClarificationSame('model_returned_sql', $sqlFallback['modelClarificationFallbackReason'] ?? null, 'SQL rejection should be explicit.');

$throwingService = new ResolverClarificationService(function (string $prompt, array $resolverResponse): array {
    throw new RuntimeException('AI timeout');
});

$throwFallback = $throwingService->buildClarification('Show Riverside collection titles', $deterministic);
assertResolverClarificationSame('resolver_deterministic_fallback', $throwFallback['routeReason'] ?? null, 'Model failures should fall back deterministically.');
assertResolverClarificationContains('AI timeout', $throwFallback['modelClarificationFallbackReason'] ?? '', 'Fallback should include model failure reason.');

$twoItemDeterministic = $deterministic;
$twoItemDeterministic['clarificationItems'][] = [
    'term' => 'Josten',
    'clarificationKey' => 'safe_probe.josten.collection',
    'question' => 'What should "Josten" mean for this report?',
    'options' => [],
    'freeTextAllowed' => true,
];

$omittingService = new ResolverClarificationService(function (string $prompt, array $resolverResponse): array {
    return [
        'question' => 'Should I use the Riverside contributor match?',
        'clarificationItems' => [
            [
                'clarificationKey' => 'safe_probe.riverside.collection',
                'options' => [['id' => 'inventory_contributor_name_riverside']],
            ],
        ],
    ];
});

$omittedFallback = $omittingService->buildClarification('Compare Riverside and Josten collections', $twoItemDeterministic);
assertResolverClarificationSame('resolver_deterministic_fallback', $omittedFallback['routeReason'] ?? null, 'Model must not omit one resolver clarification item.');
assertResolverClarificationSame('model_missing_clarification_item', $omittedFallback['modelClarificationFallbackReason'] ?? null, 'Omitted model clarification item should be explicit.');

fwrite(STDOUT, "ResolverClarificationService test passed\n");
