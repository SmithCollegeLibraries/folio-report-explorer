<?php

$schemaPath = __DIR__ . '/../services/FolioSchemaService.php';
if (!file_exists($schemaPath)) {
    fwrite(STDERR, "FolioSchemaService is missing at {$schemaPath}\n");
    exit(1);
}

require_once $schemaPath;

use app\services\FolioSchemaService;

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

$sanitizer = new ReflectionMethod(FolioSchemaService::class, 'sanitizePromptContextRetrievalPlan');

$plan = [
    'tableDescriptions' => [
        'inventory.instance__t' => 'Bibliographic instances.',
        'marctab.mt245' => 'Blocked helper table.',
    ],
    'vocabulary' => [
        'title' => 'inventory.instance__t.title - instance title.',
        'marctab' => 'Use marctab.mt245 for MARC title lookups.',
    ],
    'examples' => [
        [
            'question' => 'List instance titles',
            'sql' => 'SELECT title FROM inventory.instance__t;',
        ],
        [
            'question' => 'Show MARC 245 values',
            'sql' => 'SELECT content FROM marctab.mt245;',
        ],
    ],
    'patternCards' => [
        'inventory_titles' => [
            'title' => 'Inventory Titles',
            'summary' => 'Use instance titles.',
            'promptSignals' => ['title'],
            'tableRefs' => ['inventory.instance__t'],
            'guidance' => ['Select inventory.instance__t.title directly.'],
            'exampleQuestions' => ['List instance titles'],
        ],
        'marc_missing_field_check' => [
            'title' => 'MARC Missing Field Check',
            'summary' => 'Avoid marctab helper tables.',
            'promptSignals' => ['marc', 'field'],
            'tableRefs' => ['folio_source_record.records__t', 'marctab.mt300'],
            'guidance' => ['Use marctab.mt300 for missing field checks.', 'Use folio_source_record.records__t for source records.'],
            'exampleQuestions' => ['Show MARC field 300'],
        ],
    ],
    'dataPatterns' => [
        'folio_source_record.records__t' => [
            'columnWarnings' => [
                'parsed_record__content' => 'TEXT JSON payload.',
                'helper' => 'Do not use marctab for this workflow.',
            ],
            'sampleValues' => [
                'record_type' => ['MARC_BIB'],
            ],
            'valueSemantics' => [
                'record_type' => ['kind' => 'compact'],
            ],
            'preferredApproach' => [
                'Use folio_source_record.records__t for raw MARC JSON.',
                'Prefer marctab.mt245 for field lookups.',
            ],
        ],
    ],
];

$sanitized = $sanitizer->invoke(null, $plan);

assertSameValue(
    ['inventory.instance__t' => 'Bibliographic instances.'],
    $sanitized['tableDescriptions'] ?? null,
    'Blocked table descriptions should be removed from prompt context.'
);

assertSameValue(
    ['title' => 'inventory.instance__t.title - instance title.'],
    $sanitized['vocabulary'] ?? null,
    'Blocked vocabulary terms should be removed from prompt context.'
);

assertSameValue(
    ['List instance titles'],
    array_column($sanitized['examples'] ?? [], 'question'),
    'Blocked examples should not survive prompt-context sanitization.'
);

assertTrueValue(
    !isset(($sanitized['patternCards'] ?? [])['marc_missing_field_check']),
    'Pattern cards whose summary still exposes marctab should be removed entirely.'
);

assertSameValue(
    ['Use folio_source_record.records__t for raw MARC JSON.'],
    $sanitized['dataPatterns']['folio_source_record.records__t']['preferredApproach'] ?? null,
    'Blocked preferred-approach guidance should be removed while safe source-record guidance remains.'
);

assertSameValue(
    ['parsed_record__content' => 'TEXT JSON payload.'],
    $sanitized['dataPatterns']['folio_source_record.records__t']['columnWarnings'] ?? null,
    'Blocked warnings should be removed from data-pattern prompt context.'
);

fwrite(STDOUT, "FolioSchema prompt context sanitization test passed\n");