<?php

require_once __DIR__ . '/../services/AcrlEntryRulesService.php';
require_once __DIR__ . '/../services/AcrlHistoryService.php';
require_once __DIR__ . '/../services/AcrlCalculationService.php';
require_once __DIR__ . '/../services/AcrlAnswerValueService.php';
require_once __DIR__ . '/../services/AcrlStructuredAnswerService.php';
require_once __DIR__ . '/../services/AcrlQuestionNotesService.php';
require_once __DIR__ . '/../services/AcrlEntryService.php';

use app\services\AcrlEntryService;

class AcrlEntryTestThreshold
{
    public function get(): float { return 10.0; }
}

class AcrlEntryTestAssignments
{
    public function forEntry(int $year, ?string $userId): array
    {
        return [
            'Circulation' => [
                'assignees' => [['id' => 8, 'displayName' => 'Assigned Editor']],
                'assignedToCurrentUser' => (string)$userId === '8',
            ],
        ];
    }
}

class AcrlEntryTestRepository
{
    public $status = ['section' => 'Circulation', 'status' => 'ready'];
    public $saveResult = ['conflict' => true, 'current' => ['value_text' => '175', 'updated_at' => '2026-01-01 00:00:00.000001']];
    public $statusWrites = [];
    public $savedCalculations = [];
    public $suggestionWrites = [];
    public $answerWrites = [];

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
            ], [
                'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '0A', 'part' => '',
                'section' => 'Circulation', 'prompt' => 'Yes or no answer', 'answer_type' => 'yes/no',
                'required_status' => 'yes', 'calculated_from' => null, 'survey_order' => 3,
            ], [
                'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '0B', 'part' => '',
                'section' => 'Circulation', 'prompt' => 'Choice answer', 'answer_type' => 'choice',
                'required_status' => 'no', 'calculated_from' => null, 'survey_order' => 4,
            ], [
                'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '1A', 'part' => '',
                'section' => 'Circulation', 'prompt' => 'Numeric answer', 'answer_type' => 'number',
                'required_status' => 'no', 'calculated_from' => null, 'survey_order' => 5,
            ]];
        }
        if ($year === 2026 && $sheet === 'trends') {
            return [[
                'survey_year' => 2026, 'sheet' => 'trends', 'question_id' => 'T1', 'part' => '',
                'section' => 'Trends', 'prompt' => 'Trend question', 'answer_type' => 'choice',
                'answer_options' => '{"kind":"radio","rows":[],"choices":["Yes","No"],"allowOther":false}',
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
        if ($year === 2026 && $sheet === 'trends') {
            return [[
                'sheet' => 'trends', 'question_id' => 'T1', 'part' => '', 'value_text' => '{"value":"No"}', 'value_number' => null,
                'note' => null, 'source' => 'manual', 'source_label' => null, 'updated_by' => 'user-2', 'updated_at' => '2026-02-01 00:00:00.000003',
            ]];
        }
        if ($year === 2026 && $sheet === 'survey') {
            return [[
                'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'value_text' => '200', 'value_number' => '200.00',
                'note' => null, 'source' => 'manual', 'source_label' => null, 'updated_by' => 'user-2', 'updated_at' => '2026-02-01 00:00:00.000001',
            ], [
                'sheet' => 'survey', 'question_id' => '0B', 'part' => '', 'value_text' => 'selected', 'value_number' => null,
                'note' => null, 'source' => 'manual', 'source_label' => null, 'updated_by' => 'user-2', 'updated_at' => '2026-02-01 00:00:00.000002',
            ]];
        }
        if ($year === 2025) {
            return [
                ['sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'value_text' => '100', 'value_number' => '100.00'],
                ['sheet' => 'survey', 'question_id' => '61', 'part' => '', 'value_text' => '50', 'value_number' => '50.00'],
                ['sheet' => 'survey', 'question_id' => '0A', 'part' => '', 'value_text' => 'no', 'value_number' => null],
                ['sheet' => 'survey', 'question_id' => '0B', 'part' => '', 'value_text' => 'previous-choice', 'value_number' => null],
                ['sheet' => 'survey', 'question_id' => '1A', 'part' => '', 'value_text' => '17', 'value_number' => '17.00'],
            ];
        }
        return [];
    }
    public function approvedLinks(int $year): array
    {
        if ($year === 2026) {
            return [
            ['sheet' => 'survey', 'previous_question_id' => '60', 'previous_part' => 'A', 'current_question_id' => '60', 'current_part' => 'A', 'match_kind' => 'merged'],
            ['sheet' => 'survey', 'previous_question_id' => '61', 'previous_part' => '', 'current_question_id' => '60', 'current_part' => 'A', 'match_kind' => 'merged'],
            ['sheet' => 'survey', 'previous_question_id' => '0A', 'previous_part' => '', 'current_question_id' => '0A', 'current_part' => '', 'match_kind' => 'same'],
            ['sheet' => 'survey', 'previous_question_id' => '0B', 'previous_part' => '', 'current_question_id' => '0B', 'current_part' => '', 'match_kind' => 'same'],
            ['sheet' => 'survey', 'previous_question_id' => '1A', 'previous_part' => '', 'current_question_id' => '1A', 'current_part' => '', 'match_kind' => 'same'],
            ];
        }
        return [];
    }
    public function sectionStatuses(int $year): array { return []; }
    public function questionNoteKeysAndLinksThroughYear(int $year): array { return ['notes' => [], 'links' => []]; }
    public function sectionStatus(int $year, string $section): ?array { return $this->status; }
    public function saveAnswer(array $question, array $values, ?string $note, string $userId, ?string $expectedUpdatedAt, string $source = 'manual', ?string $sourceLabel = null, array $calculatedChanges = []): array
    {
        $this->savedCalculations = $calculatedChanges;
        $this->answerWrites[] = ['question' => $question, 'values' => $values];
        return $this->saveResult;
    }
    public function saveSuggestions(array $suggestions, string $userId): array
    {
        $this->suggestionWrites[] = [$suggestions, $userId];
        return ['conflict' => false, 'accepted' => count($suggestions)];
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
$service = new AcrlEntryService($repository, null, null, new AcrlEntryTestThreshold(), null, null, new AcrlEntryTestAssignments());
$entry = $service->entry(2026, '7');
$row = $entry['sections'][0]['questions'][0];
acrlEntryServiceAssert($row['previous']['value'] === 150.0 && $row['previous']['isSum'], 'Approved merged links should display the sum of previous values.');
acrlEntryServiceAssert($row['comparison']['changePercent'] === 33.33 && $row['comparison']['highlighted'], 'The entry response should include threshold-based advisory comparisons.');
acrlEntryServiceAssert($entry['sections'][0]['status'] === 'open' && $entry['sections'][0]['filled'] === 2, 'Entry sections report status and do not count unaccepted suggestions as filled.');
acrlEntryServiceAssert($entry['sections'][0]['assignees'] === [['id' => 8, 'displayName' => 'Assigned Editor']] && !$entry['sections'][0]['assignedToCurrentUser'], 'Entry responses expose section assignees and whether the signed-in user is assigned.');
$questionsById = [];
foreach ($entry['sections'][0]['questions'] as $entryQuestion) {
    $questionsById[$entryQuestion['questionId']] = $entryQuestion;
}
acrlEntryServiceAssert($questionsById['0A']['suggestion'] === ['value' => 'no', 'label' => 'No', 'year' => 2025], 'A blank yes/no answer should suggest the approved prior-year answer.');
acrlEntryServiceAssert($questionsById['0B']['suggestion'] === null, 'An already answered choice question must not be suggested.');
acrlEntryServiceAssert($questionsById['1A']['suggestion'] === null && $questionsById['60']['suggestion'] === null, 'Numeric answers must never be suggested.');
$trend = $entry['sections'][1]['questions'][0];
acrlEntryServiceAssert($trend['comparison']['changePercent'] === null && $trend['previous']['label'] === 'No previous value' && $trend['history'] === [] && $trend['suggestion'] === null, 'Trends parts do not show history, suggestions, or change.');
acrlEntryServiceAssert($trend['answerOptions']['kind'] === 'radio' && $trend['answerOptions']['choices'] === ['Yes', 'No'], 'Entry responses expose stored answer options for structured controls.');
acrlEntryServiceAssert($trend['answerDisplay'] === 'No', 'Structured answers expose readable text alongside their stored JSON.');

try {
    $service->saveAnswer(2026, 'survey', '60', 'A', '180', null, '2026-02-01 00:00:00.000001', 'user-1', false);
    acrlEntryServiceAssert(false, 'Non-admins cannot edit a ready section.');
} catch (DomainException $exception) {
    acrlEntryServiceAssert(strpos($exception->getMessage(), 'read-only') !== false, 'Ready sections report the lock to non-admins.');
}
$repository->status = ['section' => 'Trends', 'status' => 'open'];
try {
    $service->saveAnswer(2026, 'trends', 'T1', '', '{"value":"Maybe"}', null, null, 'user-1', false);
    acrlEntryServiceAssert(false, 'A submitted radio value outside the stored options must be rejected.');
} catch (InvalidArgumentException $exception) {
    acrlEntryServiceAssert(count($repository->answerWrites) === 0, 'Invalid structured answers must not reach repository persistence.');
}
$service->saveAnswer(2026, 'trends', 'T1', '', '{"value":"Yes"}', null, null, 'user-1', false);
acrlEntryServiceAssert($repository->answerWrites[0]['values']['value_text'] === '{"value":"Yes"}', 'A valid structured answer is stored as JSON text after server-side option validation.');
$repository->status = ['section' => 'Circulation', 'status' => 'open'];
$conflict = $service->saveAnswer(2026, 'survey', '60', 'A', '180', null, '2026-02-01 00:00:00.000001', 'user-1', false);
acrlEntryServiceAssert($conflict['conflict'] && $conflict['current']['value_text'] === '175', 'An unassigned signed-in user can still save an answer and receives the normal optimistic-lock conflict.');
acrlEntryServiceAssert($repository->savedCalculations[0]['question']['question_id'] === '63'
    && $repository->savedCalculations[0]['values']['value_number'] === '180.00', 'Saving an input should recompute derived values before repository persistence.');
$accepted = $service->acceptSuggestions(2026, 'Circulation', [['question_id' => '0A', 'part' => '']], 'user-1', false);
acrlEntryServiceAssert(!$accepted['conflict'] && $accepted['accepted'] === 1, 'A valid prior yes/no suggestion can be accepted as one manual save.');
acrlEntryServiceAssert($repository->suggestionWrites[0][0][0]['values'] === ['value_text' => 'no', 'value_number' => null]
    && $repository->suggestionWrites[0][1] === 'user-1', 'Suggestion acceptance must send the normalized prior value and signed-in editor to persistence.');
try {
    $service->acceptSuggestions(2026, 'Circulation', [['question_id' => '1A', 'part' => '']], 'user-1', false);
    acrlEntryServiceAssert(false, 'Numeric answers cannot be accepted as suggestions.');
} catch (InvalidArgumentException $exception) {
}
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
