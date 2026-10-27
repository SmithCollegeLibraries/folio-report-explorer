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
    private $history;
    private $assignments;
    private $questionNotes;
    private $structuredAnswers;

    public function __construct($repository = null, $rules = null, $values = null, $thresholds = null, $calculations = null, $history = null, $assignments = null, $questionNotes = null, $structuredAnswers = null)
    {
        $this->repository = $repository ?: new AcrlAnswerRepository();
        $this->rules = $rules ?: new AcrlEntryRulesService();
        $this->calculations = $calculations ?: new AcrlCalculationService();
        $this->values = $values ?: new AcrlAnswerValueService();
        $this->thresholds = $thresholds ?: new AcrlThresholdService();
        $this->history = $history ?: new AcrlHistoryService($this->repository, $this->rules);
        $this->assignments = $assignments ?: new AcrlSectionAssignmentService();
        $this->questionNotes = $questionNotes ?: ($repository
            ? new AcrlQuestionNotesService($repository, $repository)
            : new AcrlQuestionNotesService());
        $this->structuredAnswers = $structuredAnswers ?: new AcrlStructuredAnswerService();
    }

    public function latestYear(): int
    {
        $years = $this->repository->publishedYears();
        if (empty($years)) {
            throw new \DomainException('No published ACRL survey year is available.');
        }
        return (int)$years[0];
    }

    public function entry(?int $year = null, ?string $userId = null): array
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
        $questionHistory = $this->history->forQuestions($year, $questions);
        $questionNoteKeys = $this->questionNotes->questionKeysWithNotes($year);
        $statuses = [];
        foreach ($this->repository->sectionStatuses($year) as $status) {
            $statuses[$status['section']] = $status;
        }
        $sectionAssignments = $this->assignments->forEntry($year, $userId);
        $threshold = $this->thresholds->get();
        $sections = [];

        foreach ($questions as $question) {
            $key = $this->questionKey($question['sheet'], $question['question_id'], $question['part']);
            $answer = $answers[$key] ?? $this->emptyAnswer();
            $history = $questionHistory[$key] ?? [];
            if (!empty($question['answer_options'])) {
                foreach ($history as &$historyItem) {
                    $historyItem['label'] = $this->structuredAnswers->displayValue($question['answer_options'], $historyItem['value'] ?? null);
                }
                unset($historyItem);
            }
            $previous = $this->previousFromHistory($history[0] ?? null);
            $calculation = $calculationResults[$this->calculationKey($question['sheet'], $question['question_id'], $question['part'])] ?? null;
            $currentValue = $answer['value_number'] ?? $answer['value_text'];
            $comparison = $this->rules->compare(
                $currentValue,
                $previous['value'],
                $threshold,
                !empty($previous['linkedValues']),
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
                'answerOptions' => $this->structuredAnswers->decodeOptions($question['answer_options'] ?? null),
                'required' => $question['required_status'],
                'calculatedFrom' => $question['calculated_from'],
                'surveyOrder' => (int)$question['survey_order'],
                'answer' => $answer,
                'answerDisplay' => $this->structuredAnswers->displayValue($question['answer_options'] ?? null, $answer['value_text'] ?? null),
                'previous' => $previous,
                'history' => $history,
                'suggestion' => $this->suggestion($question, $answer, $history),
                'comparison' => $comparison,
                'calculated' => $computed,
                'hasDetailsNotes' => !empty($questionNoteKeys[$key]),
            ];
            if (!isset($sections[$question['section']])) {
                $status = $statuses[$question['section']] ?? null;
                $assignment = $sectionAssignments[$question['section']] ?? [];
                $sections[$question['section']] = [
                    'name' => $question['section'],
                    'status' => $status['status'] ?? 'open',
                    'setAt' => $status['set_at'] ?? null,
                    'reopenedAt' => $status['reopened_at'] ?? null,
                    'filled' => 0,
                    'total' => 0,
                    'assignees' => $assignment['assignees'] ?? [],
                    'assignedToCurrentUser' => $assignment['assignedToCurrentUser'] ?? false,
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
        $normalized = $this->structuredAnswers->normalize($question['answer_options'] ?? null, $value);
        if ($normalized === null) {
            $normalized = $this->values->normalize($question['answer_type'], $value);
        }
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

    public function acceptSuggestions(int $year, string $section, array $items, string $userId, bool $isAdmin): array
    {
        $yearRow = $this->repository->year($year);
        if (!$yearRow || $yearRow['status'] !== 'published') {
            throw new \DomainException('The selected ACRL year is not published.');
        }
        if ($section === '' || empty($items) || count($items) > 200) {
            throw new \InvalidArgumentException('Select one or more suggestions from a section.');
        }

        $questions = [];
        foreach ($this->repository->questions($year, 'survey') as $question) {
            $questions[$this->questionKey('survey', $question['question_id'], $question['part'])] = $question;
        }
        $answerMap = $this->answerMap($this->repository->answers($year, 'survey'));
        $selected = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['question_id']) || !is_scalar($item['question_id'])) {
                throw new \InvalidArgumentException('Each suggestion needs a question identifier.');
            }
            $questionId = (string)$item['question_id'];
            $part = isset($item['part']) && is_scalar($item['part']) ? (string)$item['part'] : '';
            if (isset($item['sheet']) && $item['sheet'] !== 'survey') {
                throw new \InvalidArgumentException('Suggestions are only available for Survey questions.');
            }
            $key = $this->questionKey('survey', $questionId, $part);
            if (isset($selected[$key])) {
                throw new \InvalidArgumentException('A suggestion can only be selected once.');
            }
            $question = $questions[$key] ?? null;
            if (!$question || $question['section'] !== $section) {
                throw new \DomainException('The selected suggestion does not belong to this section.');
            }
            if (!in_array($question['answer_type'], ['yes/no', 'choice'], true)) {
                throw new \InvalidArgumentException('Only yes/no and choice answers can use suggestions.');
            }
            if (!$this->values->isEmpty($answerMap[$key] ?? $this->emptyAnswer())) {
                throw new \DomainException('The selected suggestion is no longer available.');
            }
            $selected[$key] = $question;
        }

        $status = $this->repository->sectionStatus($year, $section);
        if ($status && $status['status'] === 'ready' && !$isAdmin) {
            throw new \DomainException('This section is ready for review and is read-only.');
        }

        $history = $this->history->forQuestions($year, array_values($selected));
        $suggestions = [];
        foreach ($selected as $key => $question) {
            $prior = $history[$key][0]['value'] ?? null;
            if ($prior === null || $prior === '') {
                throw new \DomainException('The selected suggestion is no longer available.');
            }
            $suggestions[] = [
                'question' => $question,
                'values' => $this->values->normalize($question['answer_type'], $prior),
                'note' => $answerMap[$key]['note'] ?? null,
            ];
        }
        return $this->repository->saveSuggestions($suggestions, $userId);
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

    private function previousFromHistory(?array $history): array
    {
        if (!$history || $history['value'] === null || $history['value'] === '') {
            return ['value' => null, 'label' => 'No previous value', 'isSum' => !empty($history['isSum']), 'linkedValues' => $history['linkedValues'] ?? []];
        }
        return [
            'value' => $history['value'],
            'label' => $history['label'],
            'isSum' => $history['isSum'],
            'linkedValues' => $history['linkedValues'],
        ];
    }

    private function suggestion(array $question, array $answer, array $history): ?array
    {
        if (!$this->values->isEmpty($answer)
            || !in_array($question['answer_type'], ['yes/no', 'choice'], true)
            || empty($history)
            || $history[0]['value'] === null
            || $history[0]['value'] === '') {
            return null;
        }
        $value = (string)$history[0]['value'];
        $label = in_array(strtolower($value), ['yes', 'no'], true) ? ucfirst(strtolower($value)) : $value;
        return ['value' => $value, 'label' => $label, 'year' => $history[0]['year']];
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

}
