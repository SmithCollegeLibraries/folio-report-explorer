<?php

namespace app\services;

/** Composes published survey rows with answer history, comparisons, and section state. */
class AcrlEntryService
{
    private $repository;
    private $rules;
    private $calculations;
    private $values;
    private $thresholds;

    public function __construct($repository = null, $rules = null, $values = null, $thresholds = null, $calculations = null)
    {
        $this->repository = $repository ?: new AcrlAnswerRepository();
        $this->rules = $rules ?: new AcrlEntryRulesService();
        $this->calculations = $calculations ?: new AcrlCalculationService();
        $this->values = $values ?: new AcrlAnswerValueService();
        $this->thresholds = $thresholds ?: new AcrlThresholdService();
    }

    public function latestYear(): int
    {
        $years = $this->repository->publishedYears();
        if (empty($years)) {
            throw new \DomainException('No published ACRL survey year is available.');
        }
        return (int)$years[0];
    }

    public function entry(?int $year = null): array
    {
        $year = $year ?: $this->latestYear();
        $yearRow = $this->repository->year($year);
        if (!$yearRow || $yearRow['status'] !== 'published') {
            throw new \DomainException('The selected ACRL year is not published.');
        }

        $questions = array_merge(
            $this->repository->questions($year, 'survey'),
            $this->repository->questions($year, 'trends')
        );
        $answerRows = array_merge(
            $this->repository->answers($year, 'survey'),
            $this->repository->answers($year, 'trends')
        );
        $answers = $this->answerMap($answerRows);
        $calculationResults = $this->calculations->evaluate($questions, $answerRows);
        $previousAnswers = $this->answerMap($this->repository->answers($year - 1, 'survey'));
        $links = $this->linksByCurrentKey($this->repository->approvedLinks($year));
        $statuses = [];
        foreach ($this->repository->sectionStatuses($year) as $status) {
            $statuses[$status['section']] = $status;
        }
        $threshold = $this->thresholds->get();
        $sections = [];

        foreach ($questions as $question) {
            $key = $this->questionKey($question['sheet'], $question['question_id'], $question['part']);
            $answer = $answers[$key] ?? $this->emptyAnswer();
            $linked = $links[$key] ?? [];
            $previous = $this->previousValue($linked, $previousAnswers);
            $calculation = $calculationResults[$this->calculationKey($question['sheet'], $question['question_id'], $question['part'])] ?? null;
            $currentValue = $answer['value_number'] ?? $answer['value_text'];
            $comparison = $this->rules->compare(
                $currentValue,
                $previous['value'],
                $threshold,
                !empty($linked),
                $question['sheet']
            );
            $computed = $calculation ? [
                'supported' => $calculation['supported'],
                'raw' => $calculation['supported'] ? null : $question['calculated_from'],
                'value' => $calculation['value'],
                'mismatch' => $this->rules->calculatedStatus($answer['value_number'] ?? null, $calculation['value'])['mismatch'],
                'waitingFor' => $calculation['waitingFor'],
            ] : null;
            $row = [
                'questionId' => $question['question_id'],
                'sheet' => $question['sheet'],
                'part' => $question['part'],
                'section' => $question['section'],
                'prompt' => $question['prompt'],
                'answerType' => $question['answer_type'],
                'required' => $question['required_status'],
                'calculatedFrom' => $question['calculated_from'],
                'surveyOrder' => (int)$question['survey_order'],
                'answer' => $answer,
                'previous' => $previous,
                'comparison' => $comparison,
                'calculated' => $computed,
            ];
            if (!isset($sections[$question['section']])) {
                $status = $statuses[$question['section']] ?? null;
                $sections[$question['section']] = [
                    'name' => $question['section'],
                    'status' => $status['status'] ?? 'open',
                    'setAt' => $status['set_at'] ?? null,
                    'reopenedAt' => $status['reopened_at'] ?? null,
                    'filled' => 0,
                    'total' => 0,
                    'questions' => [],
                ];
            }
            $sections[$question['section']]['total'] += 1;
            if (!$this->values->isEmpty($answer)) {
                $sections[$question['section']]['filled'] += 1;
            }
            $sections[$question['section']]['questions'][] = $row;
        }

        return [
            'selectedYear' => $year,
            'previousYear' => $year - 1,
            'years' => array_map('intval', $this->repository->publishedYears()),
            'thresholdPercent' => $threshold,
            'sections' => array_values($sections),
        ];
    }

    public function saveAnswer(int $year, string $sheet, string $questionId, string $part, $value, $note, ?string $expectedUpdatedAt, string $userId, bool $isAdmin): array
    {
        if (!in_array($sheet, ['survey', 'trends'], true)) {
            throw new \InvalidArgumentException('Sheet must be survey or trends.');
        }
        $question = $this->publishedQuestion($year, $sheet, $questionId, $part);
        if (is_string($question['calculated_from'] ?? null) && trim($question['calculated_from']) !== '') {
            throw new \InvalidArgumentException('This total is calculated.');
        }
        $status = $this->repository->sectionStatus($year, $question['section']);
        if ($status && $status['status'] === 'ready' && !$isAdmin) {
            throw new \DomainException('This section is ready for review and is read-only.');
        }
        $normalized = $this->values->normalize($question['answer_type'], $value);
        $note = $this->values->normalizeNote($note);
        $allQuestions = array_merge(
            $this->repository->questions($year, 'survey'),
            $this->repository->questions($year, 'trends')
        );
        $answerRows = array_merge(
            $this->repository->answers($year, 'survey'),
            $this->repository->answers($year, 'trends')
        );
        $answerRows = array_values(array_filter($answerRows, function ($answer) use ($question) {
            return !($answer['sheet'] === $question['sheet']
                && $answer['question_id'] === $question['question_id']
                && $answer['part'] === $question['part']);
        }));
        $answerRows[] = array_merge($question, $normalized);
        $calculationResults = $this->calculations->evaluate($allQuestions, $answerRows);
        $dependentCalculations = $this->calculations->dependentCalculations($allQuestions, $question);
        $calculatedChanges = [];
        foreach ($allQuestions as $calculatedQuestion) {
            $calculatedKey = $this->calculationKey($calculatedQuestion['sheet'], $calculatedQuestion['question_id'], $calculatedQuestion['part']);
            $calculation = $calculationResults[$calculatedKey] ?? null;
            if (!isset($dependentCalculations[$calculatedKey]) || !$calculation || !$calculation['supported']) {
                continue;
            }
            $valueNumber = $calculation['value'] === null ? null : number_format($calculation['value'], 2, '.', '');
            $calculatedChanges[] = [
                'question' => $calculatedQuestion,
                'values' => ['value_text' => $valueNumber, 'value_number' => $valueNumber],
            ];
        }
        $result = $this->repository->saveAnswer($question, $normalized, $note, $userId, $expectedUpdatedAt, 'manual', null, $calculatedChanges);
        if ($result['conflict']) {
            return ['conflict' => true, 'current' => $result['current']];
        }
        return ['conflict' => false, 'answer' => $result['current']];
    }

    public function setSectionStatus(int $year, string $section, string $status, string $userId, bool $isAdmin): array
    {
        $yearRow = $this->repository->year($year);
        if (!$yearRow || $yearRow['status'] !== 'published') {
            throw new \DomainException('The selected ACRL year is not published.');
        }
        if (!in_array($status, ['open', 'ready'], true)) {
            throw new \InvalidArgumentException('Section status must be open or ready.');
        }
        $sections = [];
        foreach (['survey', 'trends'] as $sheet) {
            foreach ($this->repository->questions($year, $sheet) as $question) {
                $sections[$question['section']] = true;
            }
        }
        if (!isset($sections[$section])) {
            throw new \DomainException('The selected section does not exist in this survey year.');
        }
        if ($status === 'open' && !$isAdmin) {
            throw new \DomainException('Only an administrator can reopen a ready section.');
        }
        return $this->repository->setSectionStatus($year, $section, $status, $userId) ?: [];
    }

    public function answerValueService(): AcrlAnswerValueService
    {
        return $this->values;
    }

    private function publishedQuestion(int $year, string $sheet, string $questionId, string $part): array
    {
        $yearRow = $this->repository->year($year);
        if (!$yearRow || $yearRow['status'] !== 'published') {
            throw new \DomainException('The selected ACRL year is not published.');
        }
        $question = $this->repository->question($year, $sheet, $questionId, $part);
        if (!$question) {
            throw new \DomainException('The selected ACRL question does not exist.');
        }
        return $question;
    }

    private function answerMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$this->questionKey($row['sheet'], $row['question_id'], $row['part'])] = $row;
        }
        return $map;
    }

    private function linksByCurrentKey(array $links): array
    {
        $map = [];
        foreach ($links as $link) {
            if ($link['sheet'] !== 'survey') {
                continue;
            }
            $map[$this->questionKey($link['sheet'], $link['current_question_id'], $link['current_part'])][] = $link;
        }
        return $map;
    }

    private function previousValue(array $links, array $answers): array
    {
        $values = [];
        foreach ($links as $link) {
            $key = $this->questionKey($link['sheet'], $link['previous_question_id'], $link['previous_part']);
            if (isset($answers[$key])) {
                $row = $answers[$key];
                $value = $row['value_number'] !== null ? $row['value_number'] : $row['value_text'];
                if ($value !== null && $value !== '') {
                    $values[] = [
                        'questionId' => $link['previous_question_id'],
                        'part' => $link['previous_part'],
                        'value' => $value,
                    ];
                }
            }
        }
        if (empty($values)) {
            return ['value' => null, 'label' => 'No previous value', 'isSum' => false, 'linkedValues' => []];
        }
        $isSum = count($values) > 1 || count(array_filter($links, function ($link) {
            return in_array($link['match_kind'], ['split', 'merged'], true);
        })) > 0;
        $value = count($values) === 1
            ? $values[0]['value']
            : $this->rules->sumLinkedValues(array_column($values, 'value'));
        if (count($values) > 1 && $value === null) {
            return ['value' => null, 'label' => 'No previous value', 'isSum' => true, 'linkedValues' => $values];
        }
        $label = $isSum ? 'Sum of linked values: ' . $this->displayValue($value) : $this->displayValue($value);
        return ['value' => $value, 'label' => $label, 'isSum' => $isSum, 'linkedValues' => $values];
    }

    private function questionKey(string $sheet, string $questionId, string $part): string
    {
        return $sheet . ':' . $questionId . ':' . $part;
    }

    private function calculationKey(string $sheet, string $questionId, string $part): string
    {
        return $sheet . '|' . $questionId . '|' . $part;
    }

    private function emptyAnswer(): array
    {
        return [
            'value_text' => null,
            'value_number' => null,
            'note' => null,
            'source' => null,
            'source_label' => null,
            'updated_by' => null,
            'updated_at' => null,
        ];
    }

    private function displayValue($value): string
    {
        if (is_numeric($value)) {
            return number_format((float)$value, floor((float)$value) === (float)$value ? 0 : 2, '.', ',');
        }
        return (string)$value;
    }
}
