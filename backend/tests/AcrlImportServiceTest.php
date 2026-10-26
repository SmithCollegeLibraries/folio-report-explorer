<?php

require_once __DIR__ . '/../services/AcrlAnswerValueService.php';
require_once __DIR__ . '/../services/AcrlImportService.php';

use app\services\AcrlImportService;

class AcrlImportTestRepository
{
    public $importCalls = [];
    public $historyRows = [];

    public function year(int $year): ?array
    {
        return $year === 2025 ? ['survey_year' => 2025, 'status' => 'published'] : null;
    }

    public function questions(int $year, string $sheet = 'survey'): array
    {
        if ($year !== 2025 || $sheet === 'trends') {
            return [];
        }
        return [
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'answer_type' => 'number'],
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => '61', 'part' => '', 'answer_type' => 'yes/no'],
        ];
    }

    public function answers(int $year, string $sheet = 'survey'): array
    {
        return $year === 2025 && $sheet === 'survey' ? [[
            'question_id' => '60', 'part' => 'A', 'value_text' => '100', 'value_number' => '100.00', 'note' => null,
        ]] : [];
    }

    public function importAnswers(array $items, string $userId, string $sourceLabel, bool $overwrite): array
    {
        $this->importCalls[] = compact('items', 'userId', 'sourceLabel', 'overwrite');
        foreach ($items as $item) {
            if ($item['question_id'] === '60' && !$overwrite) {
                $this->historyRows[] = 'inserted';
            } else {
                $this->historyRows[] = 'changed';
            }
        }
        return ['imported' => count($items), 'unchanged' => 0, 'skipped' => 0];
    }
}

function acrlImportAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acrlImportRejects(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    acrlImportAssert(false, $message);
}

$repository = new AcrlImportTestRepository();
$service = new AcrlImportService($repository);
$rows = [
    ['question_id' => '60', 'part' => 'A', 'value' => '150'],
    ['question_id' => '999', 'part' => '', 'value' => '5'],
    ['question_id' => '61', 'part' => '', 'value' => 'maybe'],
];
$preview = $service->preview(2025, $rows, 'FY2025 worksheet', false);
acrlImportAssert($preview['matchedCount'] === 1, 'Preview should count valid catalogue rows.');
acrlImportAssert($preview['unknownCount'] === 1 && $preview['typeErrorCount'] === 1, 'Preview should report unknown IDs and answer type errors separately.');
acrlImportAssert($preview['existingChanges'] === 1, 'Preview should count existing values that would change.');
acrlImportRejects(function () use ($service, $rows) {
    $service->confirm(2025, $rows, 'FY2025 worksheet', false, 'user-1');
}, 'Confirm must stop while unknown IDs or type errors remain.');

$clean = [
    ['sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'value' => '150', 'note' => 'reviewed'],
    ['question_id' => '61', 'part' => '', 'value' => 'yes'],
];
$default = $service->confirm(2025, $clean, 'FY2025 worksheet', false, 'user-1');
acrlImportAssert($default['preview']['items'][0]['status'] === 'will_skip_existing', 'Existing non-empty answers are protected by default.');
acrlImportAssert($repository->importCalls[0]['overwrite'] === false, 'Default import must not overwrite existing values.');
$service->confirm(2025, $clean, 'FY2025 worksheet', true, 'user-1');
acrlImportAssert($repository->importCalls[1]['overwrite'] === true, 'The explicit overwrite option should reach the persistence layer.');
acrlImportAssert(count($repository->historyRows) === 4, 'Both confirmed imports should hand every matched row to history-writing persistence.');

echo "PASS: ACRL import preview, validation, overwrite policy, and confirmation audit handoff\n";
