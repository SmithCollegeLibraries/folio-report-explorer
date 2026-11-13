<?php

namespace app\services;

require_once __DIR__ . '/AcrlEntryDataMapper.php';

/** Builds institution-scoped ACRL entry responses. */
class AcrlEntryAssemblerService
{
    private $repository;
    private $rules;
    private $calculations;
    private $values;
    private $thresholds;
    private $history;
    private $assignments;
    private $questionAssignments;
    private $questionNotes;
    private $structuredAnswers;

    public function __construct($repository, $rules, $values, $thresholds, $calculations, $history, $assignments, $questionNotes, $structuredAnswers, $questionAssignments)
    {
        $this->repository = $repository;
        $this->rules = $rules;
        $this->calculations = $calculations;
        $this->values = $values;
        $this->thresholds = $thresholds;
        $this->history = $history;
        $this->assignments = $assignments;
        $this->questionAssignments = $questionAssignments;
        $this->questionNotes = $questionNotes;
        $this->structuredAnswers = $structuredAnswers;
    }

    public function entry(?int $year, ?string $userId, string $institutionCode): array
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
            $this->repository->answers($year, 'survey', $institutionCode),
            $this->repository->answers($year, 'trends', $institutionCode)
        );
        $answers = AcrlEntryDataMapper::answerMap($answerRows);
        $calculationResults = $this->calculations->evaluate($questions, $answerRows);
        $questionHistory = $this->history->forQuestions($year, $questions, $institutionCode);
        $questionNoteKeys = $this->questionNotes->questionKeysWithNotes($year, $institutionCode);
        $statuses = [];
        foreach ($this->repository->sectionStatuses($year, $institutionCode) as $status) {
            $statuses[$status['section']] = $status;
        }
        $sectionAssignments = $this->assignments->forEntry($year, $userId, $institutionCode);
        $questionAssignments = $this->questionAssignments
            ? $this->questionAssignments->forEntry($year, $userId, $institutionCode)
            : [];
        $threshold = $this->thresholds->get();
        $sections = [];

        foreach ($questions as $question) {
            $key = AcrlEntryDataMapper::questionKey($question['sheet'], $question['question_id'], $question['part']);
            $answer = $answers[$key] ?? AcrlEntryDataMapper::emptyAnswer();
            $history = $questionHistory[$key] ?? [];
            if (!empty($question['answer_options'])) {
                foreach ($history as &$historyItem) {
                    $historyItem['label'] = $this->structuredAnswers->displayValue($question['answer_options'], $historyItem['value'] ?? null);
                }
                unset($historyItem);
            }
            $previous = $this->previousFromHistory($history[0] ?? null);
            $calculation = $calculationResults[AcrlEntryDataMapper::calculationKey($question['sheet'], $question['question_id'], $question['part'])] ?? null;
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
            $questionAssignment = $questionAssignments[AcrlEntryDataMapper::assignmentQuestionKey($question['sheet'], $question['question_id'], $question['part'])]
                ?? $questionAssignments[AcrlEntryDataMapper::questionKey($question['sheet'], $question['question_id'], $question['part'])]
                ?? [];
            $questionAssignees = $questionAssignment['assignees'] ?? [];
            $sectionAssignment = $sectionAssignments[$question['section']] ?? [];
            $sectionAssignees = $sectionAssignment['assignees'] ?? [];
            $questionAssignedToCurrentUser = !empty($questionAssignment['assignedToCurrentUser']);
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
                'questionAssignees' => $questionAssignees,
                'assignees' => $this->assigneeUnion($sectionAssignees, $questionAssignees),
                'assignedToCurrentUser' => !empty($sectionAssignment['assignedToCurrentUser']) || $questionAssignedToCurrentUser,
                'questionAssignedToCurrentUser' => $questionAssignedToCurrentUser,
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
                    'questionsAssignedToCurrentUser' => 0,
                    'questions' => [],
                ];
            }
            $sections[$question['section']]['total'] += 1;
            if (!$this->values->isEmpty($answer)) {
                $sections[$question['section']]['filled'] += 1;
            }
            $sections[$question['section']]['questions'][] = $row;
            if ($questionAssignedToCurrentUser && empty($sectionAssignment['assignedToCurrentUser'])) {
                $sections[$question['section']]['questionsAssignedToCurrentUser'] += 1;
            }
        }

        return [
            'institution' => $institutionCode,
            'selectedYear' => $year,
            'previousYear' => $year - 1,
            'years' => array_map('intval', $this->repository->publishedYears()),
            'thresholdPercent' => $threshold,
            'sections' => array_values($sections),
        ];
    }

    private function latestYear(): int
    {
        $years = $this->repository->publishedYears();
        if (empty($years)) {
            throw new \DomainException('No published ACRL survey year is available.');
        }
        return (int)$years[0];
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

    private function assigneeUnion(array $sectionAssignees, array $questionAssignees): array
    {
        $result = [];
        foreach (array_merge($sectionAssignees, $questionAssignees) as $assignee) {
            $result[(int)$assignee['id']] = $assignee;
        }
        return array_values($result);
    }
}
