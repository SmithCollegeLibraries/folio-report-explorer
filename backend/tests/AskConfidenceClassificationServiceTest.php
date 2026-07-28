<?php

require_once __DIR__ . '/../services/AskConfidenceClassificationService.php';

use app\services\AskConfidenceClassificationService;

function confidenceAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$unreviewed = ['reviewRequired' => false, 'reviewReasons' => []];

confidenceAssertSame(
    $unreviewed,
    AskConfidenceClassificationService::classify([
        'mode' => 'canonical',
        'validationStatus' => 'validated',
    ]),
    'Validated canonical query-family results must not be reviewed.'
);

$flagged = AskConfidenceClassificationService::classify([
    'mode' => 'exploratory',
    'validationStatus' => 'validated',
    'crossDomain' => true,
    'materialRepair' => true,
]);
confidenceAssertSame(true, $flagged['reviewRequired'], 'Deterministic analytical-risk evidence should require review.');
confidenceAssertSame(
    ['cross_domain_analysis', 'material_repair'],
    $flagged['reviewReasons'],
    'Review reasons must follow stable rule order.'
);

confidenceAssertSame(
    $unreviewed,
    AskConfidenceClassificationService::classify([
        'policyBlocked' => true,
        'validationStatus' => null,
        'crossDomain' => true,
    ]),
    'Policy blocks must not create review items.'
);
confidenceAssertSame(
    $unreviewed,
    AskConfidenceClassificationService::classify([
        'route' => 'clarification',
        'validationStatus' => null,
        'knownDataLimitations' => true,
    ]),
    'Clarifications must not create review items.'
);

foreach (['exhausted', 'rejected'] as $validationStatus) {
    confidenceAssertSame(
        ['reviewRequired' => true, 'reviewReasons' => ['unable_to_validate']],
        AskConfidenceClassificationService::classify([
            'mode' => 'exploratory',
            'validationStatus' => $validationStatus,
        ]),
        'Unvalidated generated candidates must be flagged for review.'
    );
}

$ordinary = [
    'mode' => 'exploratory',
    'validationStatus' => 'validated',
];
confidenceAssertSame(
    AskConfidenceClassificationService::classify($ordinary),
    AskConfidenceClassificationService::classify($ordinary + ['modelConfidence' => 'high']),
    'Model self-confidence must have no effect on classification.'
);

confidenceAssertSame(
    ['reviewRequired' => true, 'reviewReasons' => ['documented_default']],
    AskConfidenceClassificationService::classify($ordinary + [
        'defaultedAssumptionKeys' => ['purchase_date_basis'],
        'materialDefaultedAssumptionKeys' => ['purchase_date_basis'],
    ]),
    'A documented default marked material by the evidence builder must require review.'
);
confidenceAssertSame(
    $unreviewed,
    AskConfidenceClassificationService::classify($ordinary + [
        'defaultedAssumptionKeys' => ['display_order'],
        'materialDefaultedAssumptionKeys' => [],
    ]),
    'A nonmaterial documented default must not require review.'
);

$allRules = AskConfidenceClassificationService::classify($ordinary + [
    'crossDomain' => true,
    'materialRepair' => true,
    'limitedSemanticCoverage' => true,
    'proxyLinkage' => true,
    'knownDataLimitations' => true,
    'unresolvedDomainAmbiguity' => true,
]);
confidenceAssertSame(
    [
        'cross_domain_analysis',
        'material_repair',
        'limited_semantic_coverage',
        'proxy_linkage',
        'known_data_limitation',
        'unresolved_domain_ambiguity',
    ],
    $allRules['reviewReasons'],
    'Every deterministic evidence rule must retain fixed ordering.'
);

fwrite(STDOUT, "Ask confidence classification service test passed\n");
