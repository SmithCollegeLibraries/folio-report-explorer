<?php

namespace app\services;

/** Imports and manages collection notes while resolving approved question history. */
class AcrlQuestionNotesService
{
    private $repository;
    private $catalogue;

    public function __construct($repository = null, $catalogue = null)
    {
        $this->repository = $repository ?: new AcrlDetailsRepository();
        $this->catalogue = $catalogue ?: ($repository ?: new AcrlAnswerRepository());
    }

    public function previewImport(int $year, $rows): array
    {
        return $this->prepareImport($year, $rows)['response'];
    }

    public function confirmImport(int $year, $rows, string $userId): array
    {
        $prepared = $this->prepareImport($year, $rows);
        $preview = $prepared['response'];
        if ($preview['unknownCount'] > 0 || $preview['invalidCount'] > 0) {
            throw new \InvalidArgumentException('Resolve unknown question keys and invalid rows before confirming this import.');
        }

        $imported = 0;
        $unchanged = 0;
        foreach ($prepared['items'] as $item) {
            if ($item['status'] === 'unchanged' || $this->repository->hasQuestionNote(
                $year,
                $item['sheet'],
                $item['question_id'],
                $item['part'],
                $item['note'],
                $item['source'],
                $item['origin_year']
            )) {
                $unchanged += 1;
                continue;
            }
            $this->repository->createQuestionNote([
                'survey_year' => $year,
                'sheet' => $item['sheet'],
                'question_id' => $item['question_id'],
                'part' => $item['part'],
                'note' => $item['note'],
                'source' => $item['source'],
                'origin_year' => $item['origin_year'],
                'created_by' => $userId,
            ]);
            $imported += 1;
        }

        return ['preview' => $preview, 'result' => ['imported' => $imported, 'unchanged' => $unchanged, 'skipped' => 0]];
    }

    public function addNote(int $year, string $sheet, string $questionId, string $part, $note, $source, $originYear, string $userId): array
    {
        $this->requireQuestion($year, $sheet, $questionId, $part);
        $values = $this->normalizeNote($note, $source, $originYear, $year);
        $row = $this->repository->createQuestionNote(array_merge([
            'survey_year' => $year,
            'sheet' => $sheet,
            'question_id' => $questionId,
            'part' => $part,
            'created_by' => $userId,
        ], $values));
        return $this->publicNote($row);
    }

    public function editNote(int $id, $note, $source, $originYear, string $userId): array
    {
        $current = $this->repository->findQuestionNote($id);
        if (!$current) throw new \DomainException('The collection note no longer exists.');
        $changes = $this->normalizeNote($note, $source, $originYear, (int)$current['origin_year']);
        $updated = $this->repository->updateQuestionNote($id, $changes, $userId);
        if (!$updated) throw new \DomainException('The collection note no longer exists.');
        return $this->publicNote($updated);
    }

    public function deleteNote(int $id): bool
    {
        if (!$this->repository->findQuestionNote($id)) return false;
        return $this->repository->deleteQuestionNote($id);
    }

    public function notesForQuestion(int $year, string $sheet, string $questionId, string $part): array
    {
        $this->requireQuestion($year, $sheet, $questionId, $part);
        $queue = [['year' => $year, 'sheet' => $sheet, 'question_id' => $questionId, 'part' => $part]];
        $seen = [];
        $notes = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $key = $this->stateKey($current['year'], $current['sheet'], $current['question_id'], $current['part']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            foreach ($this->repository->questionNotesForKey($current['year'], $current['sheet'], $current['question_id'], $current['part']) as $note) {
                $notes[(int)$note['id']] = $note;
            }
            foreach ($this->repository->approvedPreviousQuestions($current['year'], $current['sheet'], $current['question_id'], $current['part']) as $previous) {
                $queue[] = [
                    'year' => (int)$previous['previous_year'],
                    'sheet' => $current['sheet'],
                    'question_id' => $previous['question_id'],
                    'part' => $previous['part'],
                ];
            }
        }
        $notes = array_values($notes);
        usort($notes, function ($left, $right) {
            $dateCompare = strcmp((string)($right['created_at'] ?? ''), (string)($left['created_at'] ?? ''));
            return $dateCompare === 0 ? ((int)$right['id'] - (int)$left['id']) : $dateCompare;
        });
        return array_map([$this, 'publicNote'], $notes);
    }

    public function questionKeysWithNotes(int $year): array
    {
        $data = $this->repository->questionNoteKeysAndLinksThroughYear($year);
        $linksBySource = [];
        foreach ($data['links'] as $link) {
            $sourceKey = $this->stateKey((int)$link['previous_year'], $link['sheet'], $link['previous_question_id'], $link['previous_part']);
            $linksBySource[$sourceKey][] = $link;
        }

        $result = [];
        foreach ($data['notes'] as $noteKey) {
            $states = [[
                'year' => (int)$noteKey['survey_year'],
                'sheet' => $noteKey['sheet'],
                'question_id' => $noteKey['question_id'],
                'part' => $noteKey['part'],
            ]];
            $seenStates = [];
            while (!empty($states)) {
                $current = array_shift($states);
                if ($current['year'] === $year) {
                    $result[$this->key($current['sheet'], $current['question_id'], $current['part'])] = true;
                    continue;
                }
                $sourceKey = $this->stateKey($current['year'], $current['sheet'], $current['question_id'], $current['part']);
                if (isset($seenStates[$sourceKey])) continue;
                $seenStates[$sourceKey] = true;
                foreach ($linksBySource[$sourceKey] ?? [] as $link) {
                    if ((int)$link['current_year'] > $year) continue;
                    $states[] = [
                        'year' => (int)$link['current_year'],
                        'sheet' => $link['sheet'],
                        'question_id' => $link['current_question_id'],
                        'part' => $link['current_part'],
                    ];
                }
            }
        }
        return $result;
    }

    private function prepareImport(int $year, $rows): array
    {
        if (!$this->catalogue->year($year)) throw new \DomainException('The selected ACRL year does not exist.');
        if (!is_array($rows) || empty($rows) || count($rows) > 5000) {
            throw new \InvalidArgumentException('Provide between 1 and 5,000 collection-note rows.');
        }
        $response = ['surveyYear' => $year, 'matchedCount' => 0, 'unknownCount' => 0, 'invalidCount' => 0, 'items' => []];
        $items = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            if (!is_array($row)) {
                $response['invalidCount'] += 1;
                $response['items'][] = ['line' => $line, 'status' => 'invalid', 'error' => 'CSV row must contain named fields.'];
                continue;
            }
            $sheet = trim((string)($row['sheet'] ?? 'survey'));
            $questionId = trim((string)($row['question_id'] ?? ''));
            $part = trim((string)($row['part'] ?? ''));
            $note = trim((string)($row['note'] ?? ''));
            $source = trim((string)($row['source'] ?? ''));
            $originYear = $this->originYear($row['origin_year'] ?? null, $source, $year);
            if (!in_array($sheet, ['survey', 'trends'], true) || !preg_match('/^[A-Za-z0-9]{1,32}$/', $questionId)
                || strlen($part) > 1 || ($part !== '' && !preg_match('/^[A-Za-z]$/', $part))
                || $note === '' || strlen($note) > 10000 || $source === '' || strlen($source) > 255
                || $originYear < 1900 || $originYear > 2100) {
                $response['invalidCount'] += 1;
                $response['items'][] = ['line' => $line, 'questionId' => $questionId, 'part' => $part, 'status' => 'invalid', 'error' => 'Note, source, year, sheet, or question key is invalid.'];
                continue;
            }
            $signature = hash('sha256', implode("\0", [$sheet, $questionId, $part, $note, $source, (string)$originYear]));
            if (isset($seen[$signature])) {
                $response['invalidCount'] += 1;
                $response['items'][] = ['line' => $line, 'questionId' => $questionId, 'part' => $part, 'status' => 'invalid', 'error' => 'This note row is duplicated in the CSV.'];
                continue;
            }
            $seen[$signature] = true;
            if (!$this->catalogue->question($year, $sheet, $questionId, $part)) {
                $response['unknownCount'] += 1;
                $response['items'][] = ['line' => $line, 'questionId' => $questionId, 'part' => $part, 'status' => 'unknown', 'error' => 'No matching question part exists in this survey year.'];
                continue;
            }
            $unchanged = $this->repository->hasQuestionNote($year, $sheet, $questionId, $part, $note, $source, $originYear);
            $response['matchedCount'] += 1;
            $response['items'][] = [
                'line' => $line,
                'questionId' => $questionId,
                'part' => $part,
                'originYear' => $originYear,
                'source' => $source,
                'status' => $unchanged ? 'unchanged' : 'will_import',
                'error' => null,
            ];
            $items[] = [
                'sheet' => $sheet,
                'question_id' => $questionId,
                'part' => $part,
                'note' => $note,
                'source' => $source,
                'origin_year' => $originYear,
                'status' => $unchanged ? 'unchanged' : 'will_import',
            ];
        }
        return ['response' => $response, 'items' => $items];
    }

    private function normalizeNote($note, $source, $originYear, int $fallbackYear): array
    {
        $note = is_string($note) ? trim($note) : '';
        $source = is_string($source) ? trim($source) : '';
        $originYear = $this->originYear($originYear, $source, $fallbackYear);
        if ($note === '' || strlen($note) > 10000 || $source === '' || strlen($source) > 255 || $originYear < 1900 || $originYear > 2100) {
            throw new \InvalidArgumentException('Enter a note of 1 to 10,000 characters, a source, and a valid year.');
        }
        return ['note' => $note, 'source' => $source, 'origin_year' => $originYear];
    }

    private function originYear($value, string $source, int $fallbackYear): int
    {
        if ($value !== null && $value !== '' && is_numeric($value)) return (int)$value;
        if (preg_match('/\b(20\d{2})\b/', $source, $match)) return (int)$match[1];
        return $fallbackYear;
    }

    private function requireQuestion(int $year, string $sheet, string $questionId, string $part): array
    {
        if (!$this->catalogue->year($year) || !in_array($sheet, ['survey', 'trends'], true)) {
            throw new \DomainException('The selected ACRL year or sheet does not exist.');
        }
        $question = $this->catalogue->question($year, $sheet, $questionId, $part);
        if (!$question) throw new \DomainException('The selected ACRL question part does not exist.');
        return $question;
    }

    private function publicNote(array $note): array
    {
        return [
            'id' => (int)$note['id'],
            'note' => $note['note'],
            'source' => $note['source'],
            'origin_year' => (int)$note['origin_year'],
            'created_at' => $note['created_at'] ?? null,
            'edited_at' => $note['edited_at'] ?? null,
        ];
    }

    private function key(string $sheet, string $questionId, string $part): string
    {
        return $sheet . ':' . $questionId . ':' . $part;
    }

    private function stateKey(int $year, string $sheet, string $questionId, string $part): string
    {
        return $year . ':' . $this->key($sheet, $questionId, $part);
    }
}
