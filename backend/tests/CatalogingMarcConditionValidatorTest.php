<?php

require_once __DIR__ . '/support/CatalogingMarcCombinationFinderFixture.php';
require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/../services/CatalogingMarcFieldFinderService.php';
if (is_file(__DIR__ . '/../services/CatalogingMarcConditionValidator.php')) {
    require_once __DIR__ . '/../services/CatalogingMarcConditionValidator.php';
}

$failures = [];

function marcConditionValidatorCase(string $name, callable $callback): void
{
    global $failures;
    $before = count($failures);
    try {
        $callback();
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . get_class($error) . ': ' . $error->getMessage();
        fwrite(STDERR, "FAIL: {$name}: " . $error->getMessage() . "\n");
    }
    if (count($failures) === $before) {
        fwrite(STDOUT, "PASS: {$name}\n");
    }
}

function marcConditionValidatorSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        global $failures;
        $failures[] = $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true);
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function marcConditionValidatorValues(array $overrides = []): array
{
    return array_merge([
        'marcTag' => '245',
        'occurrenceCondition' => 'has',
        'firstIndicator' => 'any',
        'secondIndicator' => 'any',
        'subfieldCode' => 'a',
        'contentRule' => 'any',
        'searchValue' => '',
        'caseExact' => 'false',
    ], $overrides);
}

function marcConditionValidatorExpectError(array $values, string $prefix, string $field, string $message): void
{
    if (!class_exists('app\\services\\CatalogingMarcConditionValidator')) {
        global $failures;
        $failures[] = 'CatalogingMarcConditionValidator is missing.';
        return;
    }
    try {
        \app\services\CatalogingMarcConditionValidator::validate($values, $prefix);
    } catch (\app\exceptions\ReportParameterValidationException $error) {
        marcConditionValidatorSame([$prefix . $field], array_keys($error->getFieldErrors()), 'Field errors must use the requested cN_ prefix.');
        marcConditionValidatorSame($message, $error->getFieldErrors()[$prefix . $field], 'The extracted validator must preserve the finder message.');
        return;
    }
    global $failures;
    $failures[] = 'Invalid MARC condition was accepted for ' . $prefix . $field . '.';
    fwrite(STDERR, 'FAIL: Invalid MARC condition was accepted for ' . $prefix . $field . ".\n");
}

marcConditionValidatorCase('the extracted validator rejects an invalid MARC tag with the prefixed field and finder message', function () {
    marcConditionValidatorExpectError(
        marcConditionValidatorValues(['marcTag' => '000']),
        'c2_',
        'marcTag',
        'MARC tag must be exactly three ASCII digits from 001 through 999.'
    );
});

marcConditionValidatorCase('the extracted validator maps occurrenceCondition to cN_occurrenceCondition', function () {
    marcConditionValidatorExpectError(
        marcConditionValidatorValues(['occurrenceCondition' => 'either']),
        'c3_',
        'occurrenceCondition',
        'Occurrence condition must be has or missing.'
    );
});

marcConditionValidatorCase('the extracted validator retains per-field subfield and search text messages', function () {
    marcConditionValidatorExpectError(
        marcConditionValidatorValues(['subfieldCode' => 'ab']),
        'c4_',
        'subfieldCode',
        'Subfield code must be blank or one ASCII alphanumeric character.'
    );
    marcConditionValidatorExpectError(
        marcConditionValidatorValues(['contentRule' => 'contains', 'searchValue' => '']),
        'c5_',
        'searchValue',
        'Search text is required for this content rule.'
    );
});

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " MARC condition validator cases failed.\n");
    exit(1);
}

echo "PASS: MARC condition validator contracts\n";
