<?php

require_once __DIR__ . '/../services/AcrlStructuredAnswerService.php';

use app\services\AcrlStructuredAnswerService;

function acrlStructuredAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acrlStructuredRejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    acrlStructuredAssert(false, $message);
}

$service = new AcrlStructuredAnswerService();
$checkbox = ['kind' => 'checkbox', 'rows' => [], 'choices' => ['Policy', 'Other'], 'allowOther' => true];
$radio = ['kind' => 'radio', 'rows' => [], 'choices' => ['Yes', 'No'], 'allowOther' => false];
$grid = ['kind' => 'grid', 'rows' => ['Collection', 'Other (please specify)'], 'choices' => ['Yes', 'No', 'Don’t know'], 'allowOther' => true];

$validChecks = $service->normalize($checkbox, '{"selected":["Policy","Other"],"other":"local policy"}');
acrlStructuredAssert($validChecks['value_text'] === '{"selected":["Policy","Other"],"other":"local policy"}', 'Valid selected choices and Other text must remain structured JSON.');
acrlStructuredRejects(function () use ($service, $checkbox) {
    $service->normalize($checkbox, '{"selected":["Unlisted"],"other":""}');
}, 'Checkbox selections must be members of the stored choices.');
acrlStructuredRejects(function () use ($service, $checkbox) {
    $service->normalize($checkbox, '{"selected":["Policy","Policy"],"other":""}');
}, 'Checkbox selections must not repeat choices.');
acrlStructuredRejects(function () use ($service, $radio) {
    $service->normalize($radio, '{"value":"Maybe"}');
}, 'Single-choice answers must be members of the stored choices.');
$validRadio = $service->normalize($radio, '{"value":"Yes"}');
acrlStructuredAssert($validRadio['value_text'] === '{"value":"Yes"}' && $validRadio['value_number'] === null, 'Single-choice answers must persist as JSON text.');

$validGrid = $service->normalize($grid, '{"rows":{"Collection":"Don’t know","Other (please specify)":"local support"}}');
acrlStructuredAssert($validGrid['value_text'] === '{"rows":{"Collection":"Don’t know","Other (please specify)":"local support"}}', 'Grid answers must preserve valid choices and Other-row text as JSON.');
acrlStructuredRejects(function () use ($service, $grid) {
    $service->normalize($grid, '{"rows":{"Unknown row":"Yes"}}');
}, 'Grid answers must not contain rows absent from stored options.');
acrlStructuredRejects(function () use ($service, $grid) {
    $service->normalize($grid, '{"rows":{"Collection":"Maybe"}}');
}, 'Grid choices must be members of the stored column choices.');

acrlStructuredAssert($service->displayValue($checkbox, $validChecks['value_text']) === 'Policy; Other: local policy', 'Structured values must have a readable display for history and export.');
acrlStructuredAssert($service->displayValue($grid, $validGrid['value_text']) === 'Collection: Don’t know; Other (please specify): local support', 'Grid values must have a readable display for history and export.');

echo "PASS: ACRL structured answer validation, storage, and display\n";
