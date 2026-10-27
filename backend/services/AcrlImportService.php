<?php

namespace app\services;

/** Previews and confirms administrator-uploaded values against a published catalogue. */
class AcrlImportService
{
    private $repository;
    private $values;

    public function __construct($repository = null, $values = null)
    {
        $this->repository = $repository ?: new AcrlAnswerRepository();
        $this->values = $values ?: new AcrlAnswerValueService();
    }

    public function preview(int $year, $rows, $sourceLabel, bool $overwrite = false): array
    {
        $prepared = $this->prepare($year, $rows, $sourceLabel, $overwrite);
        return $prepared['response'];
    }

    public function confirm(int $year, $rows, $sourceLabel, bool $overwrite, string $userId): array
    {
        $prepared = $this->prepare($year, $rows, $sourceLabel, $overwrite);
        $preview = $prepared['response'];
        if ($preview['unknownCount'] > 0 || $preview['typeErrorCount'] > 0) {
            throw new \InvalidArgumentException('Resolve unknown IDs and type errors before confirming this import.');
        }
        $import = $this->repository->importAnswers($prepared['items'], $userId, trim($sourceLabel), $overwrite);
        return ['preview' => $preview, 'result' => $import];
    }

    private function prepare(int $year, $rows, $sourceLabel, bool $overwrite): array
    {
        $yearRow = $this->repository->year($year);
        if (!$yearRow || $yearRow['status'] !== 'published') {
            throw new \DomainException('The selected ACRL year is not published.');
        }
        if (!is_string($sourceLabel) || trim($sourceLabel) === '' || strlen($sourceLabel) > 255) {
            throw new \InvalidArgumentException('Enter a source label of 1 to 255 characters.');
        }
        if (!is_array($rows) || empty($rows) || count($rows) > 5000) {
            throw new \InvalidArgumentException('Provide between 1 and 5,000 CSV rows.');
        }

        $catalogue = [];
        $existing = [];
        foreach (['survey', 'trends'] as $sheet) {
            foreach ($this->repository->questions($year, $sheet) as $question) {
                $key = $this->key($sheet, $question['question_id'], $question['part']);
                $catalogue[$key] = $question;
            }
            foreach ($this->repository->answers($year, $sheet) as $answer) {
                $existing[$this->key($sheet, $answer['question_id'], $answer['part'])] = $answer;
            }
        }

        $response = [
            'surveyYear' => $year,
            'sourceLabel' => trim($sourceLabel),
            'overwrite' => $overwrite,
            'matchedCount' => 0,
            'unknownCount' => 0,
            'typeErrorCount' => 0,
            'existingChanges' => 0,
            'items' => [],
        ];
        $preparedItems = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            if (!is_array($row)) {
                $response['typeErrorCount'] += 1;
                $response['items'][] = ['line' => $line, 'status' => 'type_error', 'error' => 'CSV row must contain named fields.'];
                continue;
            }
            $sheet = isset($row['sheet']) && $row['sheet'] !== '' ? (string)$row['sheet'] : 'survey';
            $questionId = isset($row['question_id']) ? trim((string)$row['question_id']) : '';
            $part = isset($row['part']) ? trim((string)$row['part']) : '';
            $key = $this->key($sheet, $questionId, $part);
            if (isset($seen[$key])) {
                $response['typeErrorCount'] += 1;
                $response['items'][] = $this->itemResponse($line, $sheet, $questionId, $part, 'type_error', null, $row['value'] ?? null, 'Duplicate sheet, ID, and part.');
                continue;
            }
            $seen[$key] = true;
            if (!isset($catalogue[$key])) {
                $response['unknownCount'] += 1;
                $response['items'][] = $this->itemResponse($line, $sheet, $questionId, $part, 'unknown', null, $row['value'] ?? null, 'No matching question part in this published year.');
                continue;
            }
            try {
                $question = $catalogue[$key];
                $value = $this->values->normalize($question['answer_type'], $row['value'] ?? null);
                $note = $this->values->normalizeNote($row['note'] ?? null);
            } catch (\InvalidArgumentException $exception) {
                $response['typeErrorCount'] += 1;
                $response['items'][] = $this->itemResponse($line, $sheet, $questionId, $part, 'type_error', null, $row['value'] ?? null, $exception->getMessage());
                continue;
            }

            $response['matchedCount'] += 1;
            $current = $existing[$key] ?? null;
            $changed = $current && !$this->sameValues($current, $value, $note);
            if ($changed) {
                $response['existingChanges'] += 1;
            }
            $disposition = $changed && !$overwrite ? 'will_skip_existing' : ($changed ? 'will_replace' : 'will_import');
            $response['items'][] = $this->itemResponse(
                $line,
                $sheet,
                $questionId,
                $part,
                $disposition,
                $current,
                $row['value'] ?? null,
                null
            );
            $preparedItems[] = array_merge($question, $value, [
                'survey_year' => $year,
                'sheet' => $sheet,
                'question_id' => $questionId,
                'part' => $part,
                'note' => $note,
            ]);
        }
        return ['response' => $response, 'items' => $preparedItems];
    }

    private function itemResponse(int $line, string $sheet, string $questionId, string $part, string $status, ?array $current, $value, ?string $error): array
    {
        return [
            'line' => $line,
            'sheet' => $sheet,
            'questionId' => $questionId,
            'part' => $part,
            'status' => $status,
            'currentValue' => $current['value_text'] ?? null,
            'value' => is_scalar($value) ? (string)$value : null,
            'error' => $error,
        ];
    }

    private function sameValues(array $current, array $value, ?string $note): bool
    {
        $currentNumber = $current['value_number'] ?? null;
        $newNumber = $value['value_number'] ?? null;
        $sameNumber = $currentNumber === null && $newNumber === null;
        if ($currentNumber !== null && $newNumber !== null && is_numeric($currentNumber) && is_numeric($newNumber)) {
            $sameNumber = round((float)$currentNumber, 2) === round((float)$newNumber, 2);
        }
        return ($current['value_text'] ?? null) === $value['value_text']
            && $sameNumber
            && ($current['note'] ?? null) === $note;
    }

    private function key(string $sheet, string $questionId, string $part): string
    {
        return $sheet . ':' . $questionId . ':' . $part;
    }
}
