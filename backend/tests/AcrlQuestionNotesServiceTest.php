<?php

$servicePath = __DIR__ . '/../services/AcrlQuestionNotesService.php';
if (!file_exists($servicePath)) {
    fwrite(STDERR, "FAIL: ACRL question notes service must validate, import, edit, and follow approved links.\n");
    exit(1);
}
require_once $servicePath;

function acrlQuestionNotesAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class AcrlQuestionNotesFakeRepository
{
    public $years = [2024 => ['survey_year' => 2024, 'status' => 'published'], 2025 => ['survey_year' => 2025, 'status' => 'published'], 2026 => ['survey_year' => 2026, 'status' => 'published']];
    public $questions = [];
    public $notes = [];
    public $predecessors = [];
    public $nextId = 1;

    public function year(int $year): ?array { return $this->years[$year] ?? null; }

    public function question(int $year, string $sheet, string $questionId, string $part): ?array
    {
        return $this->questions[$this->key($year, $sheet, $questionId, $part)] ?? null;
    }

    public function hasQuestionNote(int $year, string $sheet, string $questionId, string $part, string $note, string $source, int $originYear): bool
    {
        foreach ($this->notes as $item) {
            if ($item['survey_year'] === $year && $item['sheet'] === $sheet && $item['question_id'] === $questionId
                && $item['part'] === $part && $item['note'] === $note && $item['source'] === $source && $item['origin_year'] === $originYear) return true;
        }
        return false;
    }

    public function createQuestionNote(array $row): array
    {
        $row['id'] = $this->nextId++;
        $row['created_at'] = '2026-10-27 12:00:00';
        $row['edited_at'] = null;
        $row['edited_by'] = null;
        $this->notes[$row['id']] = $row;
        return $row;
    }

    public function findQuestionNote(int $id): ?array { return $this->notes[$id] ?? null; }

    public function updateQuestionNote(int $id, array $changes, string $userId): ?array
    {
        if (!isset($this->notes[$id])) return null;
        $this->notes[$id] = array_merge($this->notes[$id], $changes, ['edited_by' => $userId, 'edited_at' => '2026-10-27 13:00:00']);
        return $this->notes[$id];
    }

    public function deleteQuestionNote(int $id): bool
    {
        if (!isset($this->notes[$id])) return false;
        unset($this->notes[$id]);
        return true;
    }

    public function questionNotesForKey(int $year, string $sheet, string $questionId, string $part): array
    {
        return array_values(array_filter($this->notes, function ($item) use ($year, $sheet, $questionId, $part) {
            return $item['survey_year'] === $year && $item['sheet'] === $sheet && $item['question_id'] === $questionId && $item['part'] === $part;
        }));
    }

    public function approvedPreviousQuestions(int $year, string $sheet, string $questionId, string $part): array
    {
        return $this->predecessors[$this->key($year, $sheet, $questionId, $part)] ?? [];
    }

    public function questionNoteKeysAndLinksThroughYear(int $year): array
    {
        $notes = [];
        foreach ($this->notes as $item) {
            if ((int)$item['survey_year'] <= $year) $notes[] = ['survey_year' => $item['survey_year'], 'sheet' => $item['sheet'], 'question_id' => $item['question_id'], 'part' => $item['part']];
        }
        $links = [];
        foreach ($this->predecessors as $state => $previousItems) {
            list($currentYear, $sheet, $questionId, $part) = explode(':', $state, 4);
            foreach ($previousItems as $previous) {
                $links[] = [
                    'previous_year' => $previous['previous_year'], 'current_year' => (int)$currentYear, 'sheet' => $sheet,
                    'previous_question_id' => $previous['question_id'], 'previous_part' => $previous['part'],
                    'current_question_id' => $questionId, 'current_part' => $part,
                ];
            }
        }
        return ['notes' => $notes, 'links' => $links];
    }

    private function key(int $year, string $sheet, string $questionId, string $part): string
    {
        return $year . ':' . $sheet . ':' . $questionId . ':' . $part;
    }
}

$repository = new AcrlQuestionNotesFakeRepository();
foreach ([2024 => ['40', ''], 2025 => ['41', ''], 2026 => ['42', '']] as $year => $key) {
    $repository->questions[$year . ':survey:' . $key[0] . ':' . $key[1]] = ['survey_year' => $year, 'sheet' => 'survey', 'question_id' => $key[0], 'part' => $key[1]];
}
$repository->questions['2026:survey:40:'] = ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '40', 'part' => ''];
$repository->questions['2026:survey:41:B'] = ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '41', 'part' => 'B'];
$repository->predecessors['2026:survey:42:'] = [['previous_year' => 2025, 'question_id' => '41', 'part' => '']];
$repository->predecessors['2025:survey:41:'] = [['previous_year' => 2024, 'question_id' => '40', 'part' => '']];

$service = new \app\services\AcrlQuestionNotesService($repository);
$rows = [
    ['question_id' => '40', 'part' => '', 'note' => 'Checked titles against the catalogue, and sampled records.', 'source' => '2025 worksheet', 'origin_year' => 2025],
    ['question_id' => '41', 'part' => 'B', 'note' => 'Verified supplier holdings.', 'source' => 'FY2024 worksheet', 'origin_year' => 2024],
];
$preview = $service->previewImport(2026, $rows);
acrlQuestionNotesAssert($preview['matchedCount'] === 2 && $preview['unknownCount'] === 0 && $preview['invalidCount'] === 0, 'CSV preview must match both valid question notes.');
$confirmed = $service->confirmImport(2026, $rows, 'admin-1');
acrlQuestionNotesAssert($confirmed['result']['imported'] === 2, 'CSV confirmation must import each matched note.');
$again = $service->confirmImport(2026, $rows, 'admin-1');
acrlQuestionNotesAssert($again['result']['unchanged'] === 2, 'A repeated import must leave identical notes unchanged.');

$badRows = array_merge($rows, [$rows[0], ['question_id' => '999', 'part' => '', 'note' => 'Unknown key', 'source' => '2026', 'origin_year' => 2026]]);
$badPreview = $service->previewImport(2026, $badRows);
acrlQuestionNotesAssert($badPreview['unknownCount'] === 1 && $badPreview['invalidCount'] === 1, 'Preview must flag unknown question keys and duplicate rows.');

$added = $service->addNote(2026, 'survey', '40', '', 'How I verified the holdings.', 'How I collected this', 2026, 'user-2');
acrlQuestionNotesAssert($repository->notes[$added['id']]['created_by'] === 'user-2' && $added['origin_year'] === 2026, 'New notes must keep the signed-in actor and original year.');
$edited = $service->editNote((int)$added['id'], 'Checked a second sample.', 'How I collected this', 2026, 'admin-1');
acrlQuestionNotesAssert($edited['note'] === 'Checked a second sample.' && $repository->notes[$added['id']]['edited_by'] === 'admin-1', 'Administrator edits must record their actor.');
acrlQuestionNotesAssert($service->deleteNote((int)$added['id']), 'Administrators must be able to remove a note.');

$repository->notes[90] = ['id' => 90, 'survey_year' => 2024, 'sheet' => 'survey', 'question_id' => '40', 'part' => '', 'note' => 'Original 2024 note', 'source' => '2024 worksheet', 'origin_year' => 2024, 'created_at' => '2024-01-01 00:00:00'];
$repository->notes[91] = ['id' => 91, 'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => '41', 'part' => '', 'note' => 'More recent 2025 note', 'source' => '2025 worksheet', 'origin_year' => 2025, 'created_at' => '2025-01-01 00:00:00'];
$forwarded = $service->notesForQuestion(2026, 'survey', '42', '');
acrlQuestionNotesAssert(count($forwarded) === 2, 'Notes must follow only approved links from prior years.');
acrlQuestionNotesAssert($forwarded[0]['origin_year'] === 2025 && $forwarded[0]['note'] === 'More recent 2025 note', 'Forwarded notes must remain newest-first and retain their origin year.');
$noteKeys = $service->questionKeysWithNotes(2026);
acrlQuestionNotesAssert(isset($noteKeys['survey:42:']), 'The entry response must mark questions reached through approved note links.');

echo "PASS: ACRL question notes import, edits, and approved-link history\n";
