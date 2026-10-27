<?php

require_once __DIR__ . '/../services/AcrlCalculationService.php';

use app\services\AcrlCalculationService;

function acrlCalculationAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$questions = [
    ['sheet' => 'survey', 'question_id' => '1', 'part' => 'A', 'calculated_from' => null],
    ['sheet' => 'survey', 'question_id' => '2', 'part' => 'A', 'calculated_from' => null],
    ['sheet' => 'survey', 'question_id' => '3', 'part' => 'A', 'calculated_from' => 'sum of 1A, 2A'],
    ['sheet' => 'survey', 'question_id' => '4', 'part' => 'A', 'calculated_from' => null],
    ['sheet' => 'survey', 'question_id' => '5', 'part' => 'A', 'calculated_from' => null],
    ['sheet' => 'survey', 'question_id' => '6', 'part' => 'A', 'calculated_from' => 'sum of 3A, 4A, 5A'],
    ['sheet' => 'survey', 'question_id' => '40', 'part' => 'A', 'calculated_from' => null],
    ['sheet' => 'survey', 'question_id' => '42', 'part' => 'A', 'calculated_from' => null],
    ['sheet' => 'survey', 'question_id' => '43', 'part' => 'A', 'calculated_from' => null],
    ['sheet' => 'survey', 'question_id' => '44', 'part' => 'A', 'calculated_from' => 'sum of 40, 42A, 43A'],
    ['sheet' => 'survey', 'question_id' => '50', 'part' => '', 'calculated_from' => 'sum of 1A, 99'],
    ['sheet' => 'survey', 'question_id' => '80', 'part' => '', 'calculated_from' => 'sum of 81'],
    ['sheet' => 'survey', 'question_id' => '81', 'part' => '', 'calculated_from' => 'sum of 80'],
];
$answers = [
    ['sheet' => 'survey', 'question_id' => '1', 'part' => 'A', 'value_number' => '1.00'],
    ['sheet' => 'survey', 'question_id' => '2', 'part' => 'A', 'value_number' => '2.00'],
    ['sheet' => 'survey', 'question_id' => '4', 'part' => 'A', 'value_number' => '4.00'],
    ['sheet' => 'survey', 'question_id' => '5', 'part' => 'A', 'value_number' => '5.00'],
    ['sheet' => 'survey', 'question_id' => '40', 'part' => 'A', 'value_number' => '40.00'],
    ['sheet' => 'survey', 'question_id' => '42', 'part' => 'A', 'value_number' => '42.00'],
    ['sheet' => 'survey', 'question_id' => '43', 'part' => 'A', 'value_number' => '43.00'],
];
$results = (new AcrlCalculationService())->evaluate($questions, $answers);
$calculations = new AcrlCalculationService();
$dependent = $calculations->dependentCalculations($questions, $questions[0]);

acrlCalculationAssert($results['survey|3|A']['value'] === 3.0, 'Simple calculation rules should sum values from exact question parts.');
acrlCalculationAssert($results['survey|6|A']['value'] === 12.0, 'Calculated totals should resolve nested totals in dependency order.');
acrlCalculationAssert($results['survey|44|A']['value'] === 125.0, 'A bare numeric reference should resolve to the target total part when no whole question exists.');
acrlCalculationAssert($results['survey|50|']['value'] === null && $results['survey|50|']['waitingFor'] === ['99'], 'Missing inputs should remain blank and identify the unresolved dependency.');
acrlCalculationAssert($results['survey|80|']['value'] === null && $results['survey|81|']['value'] === null, 'Calculation cycles should terminate with blank values.');
acrlCalculationAssert(isset($dependent['survey|3|A']) && isset($dependent['survey|6|A'])
    && !isset($dependent['survey|44|A']), 'An input save should select only its direct and transitive calculated dependents.');

echo "PASS: ACRL normalized dependency calculations, missing inputs, and cycle guard\n";
