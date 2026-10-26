<?php

$servicePath = __DIR__ . '/../services/AcrlQuestionImportValidatorService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "FAIL: ACRL import validator service is missing.\n");
    exit(1);
}

require_once $servicePath;

use app\services\AcrlQuestionImportValidatorService;

function acrlValidatorAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acrlValidatorRejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    acrlValidatorAssert(false, $message);
}

function acrlValidatorItem(array $overrides = []): array
{
    return array_merge([
        'sheet' => 'survey',
        'question_id' => ' 0A. ',
        'part' => '',
        'section' => 'Library characteristics',
        'prompt' => 'Does the data you are reporting include a law library?',
        'answer_type' => 'yes/no',
        'required' => 'no',
        'calculated_from' => null,
        'survey_order' => 1,
    ], $overrides);
}

$validator = new AcrlQuestionImportValidatorService();
$sha256 = str_repeat('a', 64);
$normalized = $validator->validateUpload(2026, '2026 ACRL Survey Worksheet.pdf', $sha256, [acrlValidatorItem()]);
acrlValidatorAssert($normalized[0]['question_id'] === '0A', 'Identifiers should trim whitespace and trailing periods without changing case.');
acrlValidatorAssert($normalized[0]['part'] === '', 'A whole question identifier should keep an empty answer part.');

$caseDistinct = $validator->validateUpload(2026, 'worksheet.pdf', $sha256, [
    acrlValidatorItem(['question_id' => '40a', 'survey_order' => 1]),
    acrlValidatorItem(['question_id' => '40A', 'survey_order' => 2]),
]);
acrlValidatorAssert(count($caseDistinct) === 2, 'Identifier case must remain significant when checking duplicate items.');

acrlValidatorRejects(function () use ($validator, $sha256) {
    $validator->validateUpload(2026, 'worksheet.pdf', $sha256, [
        acrlValidatorItem(['question_id' => '42A']),
        acrlValidatorItem(['question_id' => '42A', 'survey_order' => 2]),
    ]);
}, 'Duplicate year, sheet, identifier, and part combinations must be rejected.');

acrlValidatorRejects(function () use ($validator, $sha256) {
    $validator->validateUpload(2026, 'worksheet.pdf', $sha256, [acrlValidatorItem(['answer_type' => 'formula'])]);
}, 'Unknown answer types must be rejected.');

acrlValidatorRejects(function () use ($validator, $sha256) {
    $validator->validateUpload(2026, 'worksheet.pdf', $sha256, [acrlValidatorItem(['prompt' => str_repeat('x', 4001)])]);
}, 'Overlong prompts must be rejected before database writes.');

acrlValidatorRejects(function () use ($validator) {
    $validator->validateUpload(2026, '../worksheet.pdf', str_repeat('a', 64), [acrlValidatorItem()]);
}, 'A source file name must be a plain file name without path segments.');

acrlValidatorRejects(function () use ($validator, $sha256) {
    $validator->validateUpload(2026, 'worksheet.pdf', $sha256, [acrlValidatorItem(['part' => 'AA'])]);
}, 'A part must be empty or one uppercase letter.');

echo "PASS: ACRL worksheet upload validation and identifier normalization\n";
