<?php

$servicePath = __DIR__ . '/../services/FolioSchemaService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "FolioSchemaService is missing at {$servicePath}\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
    }
}

Yii::$app = (object) [
    'cache' => null,
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
    ],
];

require_once $servicePath;

use app\services\FolioSchemaService;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertTrueValue($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$method = new ReflectionMethod(FolioSchemaService::class, 'applyBuiltInTypeSemantics');

$overridden = $method->invoke(null, [
    'tableDescriptions' => [],
    'vocabulary' => [
        'MARC source record access' => 'stale guidance with sr.state = \'ACTUAL\'',
    ],
    'examples' => [],
]);

$marcSourceRecordGuidance = (string)($overridden['vocabulary']['MARC source record access'] ?? '');
assertTrueValue(
    strpos($marcSourceRecordGuidance, 'external_ids_holder__instance_id') !== false,
    'Built-in MARC source-record guidance should join records__t via external_ids_holder__instance_id.'
);
assertTrueValue(
    strpos($marcSourceRecordGuidance, 'state') === false,
    'Built-in MARC source-record guidance should not mention the nonexistent records__t.state column.'
);
assertTrueValue(
    strpos($marcSourceRecordGuidance, 'marctab.mtNNN') !== false,
    'Built-in MARC source-record guidance should keep field-level checks on marctab.mtNNN tables.'
);

$exampleQuestions = array_map(static function (array $example): string {
    return (string)($example['question'] ?? '');
}, $overridden['examples'] ?? []);

assertSameValue(
    true,
    in_array('Show Smith instances missing MARC field 300', $exampleQuestions, true),
    'Built-in domain-hint overrides should seed a canonical missing-MARC-field example for semantic retrieval.'
);

fwrite(STDOUT, "FolioSchema domain hints override test passed\n");