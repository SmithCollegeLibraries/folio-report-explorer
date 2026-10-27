<?php

require_once __DIR__ . '/../services/AcrlEntryRulesService.php';
require_once __DIR__ . '/../services/AcrlCalculationService.php';
require_once __DIR__ . '/../services/AcrlAnswerValueService.php';
require_once __DIR__ . '/../services/AcrlEntryService.php';

use app\services\AcrlEntryService;

class AcrlEntryTestThreshold
{
    public function get(): float { return 10.0; }
}

class AcrlEntryTestRepository
{
    public $status = ['section' => 'Circulation', 'status' => 'ready'];
    public $saveResult = ['conflict' => true, 'current' => ['value_text' => '175', 'updated_at' => '2026-01-01 00:00:00.000001']];
    public $statusWrites = [];
    public $savedCalculations = [];

    public function publishedYears(): array { return [2026, 2025]; }
    public function year(int $year): ?array { return in_array($year, [2025, 2026], true) ? ['survey_year' => $year, 'status' => 'published'] : null; }
    public function questions(int $year, string $sheet = 'survey'): array
    {
        if ($year === 2026 && $sheet === 'survey') {
            return [[
                'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A',
                'section' => 'Circulation', 'prompt' => 'Current value', 'answer_type' => 'number',
                'required_status' => 'yes', 'calculated_from' => null, 'survey_order' => 1,
            ], [
                'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '63', 'part' => '',
                'section' => 'Circulation', 'prompt' => 'Calculated total', 'answer_type' => 'number',
                'required_status' => 'yes', 'calculated_from' => 'sum of 60A', 'survey_order' => 2,
            ]];
        }
        if ($year === 2026 && $sheet === 'trends') {
            return [[
                'survey_year' => 2026, 'sheet' => 'trends', 'question_id' => 'T1', 'part' => '',
                'section' => 'Trends', 'prompt' => 'Trend question', 'answer_type' => 'text',
                'required_status' => 'no', 'calculated_from' => null, 'survey_order' => 1,
            ]];
        }
        return [];
    }
    public function question(int $year, string $sheet, string $questionId, string $part): ?array
    {
        foreach ($this->questions($year, $sheet) as $question) {
            if ($question['question_id'] === $questionId && $question['part'] === $part) return $question;
        }
        return null;
    }
    public function answers(int $year, string $sheet = 'survey'): array
    {
        if ($year === 2026 && $sheet === 'survey') {
            return [[
                'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'value_text' => '200', 'value_number' => '200.00',
                'note' => null, 'source' => 'manual', 'source_label' => null, 'updated_by' => 'user-2', 'updated_at' => '2026-02-01 00:00:00.000001',
            ]];
        }
        if ($year === 2025) {
            return [
                ['sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'value_text' => '100', 'value_number' => '100.00'],
                ['sheet' => 'survey', 'question_id' => '61', 'part' => '', 'value_text' => '50', 'value_number' => '50.00'],
            ];
        }
        return [];
    }
    public function approvedLinks(int $year): array
    {
        return $year === 2026 ? [
            ['sheet' => 'survey', 'previous_question_id' => '60', 'previous_part' => 'A', 'current_question_id' => '60', 'current_part' => 'A', 'match_kind' => 'merged'],
            ['sheet' => 'survey', 'previous_question_id' => '61', 'previous_part' => '', 'current_question_id' => '60', 'current_part' => 'A', 'match_kind' => 'merged'],
        ] : [];
    }
    public function sectionStatuses(int $year): array { return []; }
    public function sectionStatus(int $year, string $section): ?array { return $this->status; }
    public function saveAnswer(array $question, array $values, ?string $note, string $userId, ?string $expectedUpdatedAt, string $source = 'manual', ?string $sourceLabel = null, array $calculatedChanges = []): array
    {
        $this->savedCalculations = $calculatedChanges;
        return $this->saveResult;
    }
    public function setSectionStatus(int $year, string $section, string $status, string $userId): ?array
    {
        $this->statusWrites[] = [$year, $section, $status, $userId];
        return ['section' => $section, 'status' => $status];
    }
}

function acrlEntryServiceAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$repository = new AcrlEntryTestRepository();
$service = new AcrlEntryService($repository, null, null, new AcrlEntryTestThreshold());
$entry = $service->entry(2026);
$row = $entry['sections'][0]['questions'][0];
acrlEntryServiceAssert($row['previous']['value'] === 150.0 && $row['previous']['isSum'], 'Approved merged links should display the sum of previous values.');
acrlEntryServiceAssert($row['comparison']['changePercent'] === 33.33 && $row['comparison']['highlighted'], 'The entry response should include threshold-based advisory comparisons.');
acrlEntryServiceAssert($entry['sections'][0]['status'] === 'open' && $entry['sections'][0]['filled'] === 1, 'Entry sections report status and completed-part progress.');
$trend = $entry['sections'][1]['questions'][0];
acrlEntryServiceAssert($trend['comparison']['changePercent'] === null && $trend['previous']['label'] === 'No previous value', 'Trends parts do not show previous values or change.');

try {
    $service->saveAnswer(2026, 'survey', '60', 'A', '180', null, '2026-02-01 00:00:00.000001', 'user-1', false);
    acrlEntryServiceAssert(false, 'Non-admins cannot edit a ready section.');
} catch (DomainException $exception) {
    acrlEntryServiceAssert(strpos($exception->getMessage(), 'read-only') !== false, 'Ready sections report the lock to non-admins.');
}
$repository->status = ['section' => 'Circulation', 'status' => 'open'];
$conflict = $service->saveAnswer(2026, 'survey', '60', 'A', '180', null, '2026-02-01 00:00:00.000001', 'user-1', false);
acrlEntryServiceAssert($conflict['conflict'] && $conflict['current']['value_text'] === '175', 'Optimistic-lock conflicts return the current answer.');
acrlEntryServiceAssert($repository->savedCalculations[0]['question']['question_id'] === '63'
    && $repository->savedCalculations[0]['values']['value_number'] === '180.00', 'Saving an input should recompute derived values before repository persistence.');
try {
    $service->saveAnswer(2026, 'survey', '63', '', '99', null, null, 'user-1', false);
    acrlEntryServiceAssert(false, 'Calculated totals must reject direct saves.');
} catch (InvalidArgumentException $exception) {
    acrlEntryServiceAssert($exception->getMessage() === 'This total is calculated.', 'Direct total edits return the exact validation message.');
}

$service->setSectionStatus(2026, 'Circulation', 'ready', 'user-1', false);
try {
    $service->setSectionStatus(2026, 'Circulation', 'open', 'user-1', false);
    acrlEntryServiceAssert(false, 'Only administrators can reopen sections.');
} catch (DomainException $exception) {
}
$service->setSectionStatus(2026, 'Circulation', 'open', 'admin-1', true);
acrlEntryServiceAssert(count($repository->statusWrites) === 2, 'Any signed-in editor can set ready and administrators can reopen.');

echo "PASS: ACRL entry assembly, merged prior values, ready lock, and optimistic conflicts\n";
