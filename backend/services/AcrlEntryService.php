<?php

namespace app\services;

require_once __DIR__ . '/AcrlEntryAssemblerService.php';
require_once __DIR__ . '/AcrlEntryDataMapper.php';

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
    private $questionAssignments;
    private $questionNotes;
    private $structuredAnswers;
    private $assembler;

    public function __construct($repository = null, $rules = null, $values = null, $thresholds = null, $calculations = null, $history = null, $assignments = null, $questionNotes = null, $structuredAnswers = null, $questionAssignments = null)
    {
        $this->repository = $repository ?: new AcrlAnswerRepository();
        $this->rules = $rules ?: new AcrlEntryRulesService();
        $this->calculations = $calculations ?: new AcrlCalculationService();
        $this->values = $values ?: new AcrlAnswerValueService();
        $this->thresholds = $thresholds ?: new AcrlThresholdService();
        $this->history = $history ?: new AcrlHistoryService($this->repository, $this->rules);
        $this->assignments = $assignments ?: new AcrlSectionAssignmentService();
        $this->questionAssignments = $questionAssignments ?: ($repository ? null : new AcrlQuestionAssignmentService());
        $this->questionNotes = $questionNotes ?: ($repository
            ? new AcrlQuestionNotesService($repository, $repository)
            : new AcrlQuestionNotesService());
        $this->structuredAnswers = $structuredAnswers ?: new AcrlStructuredAnswerService();
        $this->assembler = new AcrlEntryAssemblerService(
            $this->repository, $this->rules, $this->values, $this->thresholds, $this->calculations,
            $this->history, $this->assignments, $this->questionNotes, $this->structuredAnswers, $this->questionAssignments
        );
    }

    public function latestYear(): int
    {
        $years = $this->repository->publishedYears();
        if (empty($years)) {
            throw new \DomainException('No published ACRL survey year is available.');
        }
        return (int)$years[0];
    }

    public function entry(?int $year, ?string $userId, string $institutionCode): array
    {
        return $this->assembler->entry($year, $userId, $institutionCode);
    }

    public function saveAnswer(int $year, string $sheet, string $questionId, string $part, $value, $note, ?string $expectedUpdatedAt, string $userId, bool $isAdmin, string $institutionCode): array
    {
        return $this->repository->withSharedYearLock($year, function () use ($year, $sheet, $questionId, $part, $value, $note, $expectedUpdatedAt, $userId, $isAdmin, $institutionCode) {
            return $this->saveAnswerLocked($year, $sheet, $questionId, $part, $value, $note, $expectedUpdatedAt, $userId, $isAdmin, $institutionCode);
        });
    }

    private function saveAnswerLocked(int $year, string $sheet, string $questionId, string $part, $value, $note, ?string $expectedUpdatedAt, string $userId, bool $isAdmin, string $institutionCode): array
    {
        if (!in_array($sheet, ['survey', 'trends'], true)) {
            throw new \InvalidArgumentException('Sheet must be survey or trends.');
        }
        $question = $this->publishedQuestion($year, $sheet, $questionId, $part);
        if (is_string($question['calculated_from'] ?? null) && trim($question['calculated_from']) !== '') {
            throw new \InvalidArgumentException('This total is calculated.');
        }
        $status = $this->repository->sectionStatus($year, $question['section'], $institutionCode);
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
            $this->repository->answers($year, 'survey', $institutionCode),
            $this->repository->answers($year, 'trends', $institutionCode)
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
            $calculatedKey = AcrlEntryDataMapper::calculationKey($calculatedQuestion['sheet'], $calculatedQuestion['question_id'], $calculatedQuestion['part']);
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
        $result = $this->repository->saveAnswer($question, $normalized, $note, $userId, $expectedUpdatedAt, 'manual', null, $calculatedChanges, $institutionCode);
        if ($result['conflict']) {
            return ['conflict' => true, 'current' => $result['current']];
        }
        return ['conflict' => false, 'answer' => $result['current']];
    }

    public function acceptSuggestions(int $year, string $section, array $items, string $userId, bool $isAdmin, string $institutionCode): array
    {
        return $this->repository->withSharedYearLock($year, function () use ($year, $section, $items, $userId, $isAdmin, $institutionCode) {
            return $this->acceptSuggestionsLocked($year, $section, $items, $userId, $isAdmin, $institutionCode);
        });
    }

    private function acceptSuggestionsLocked(int $year, string $section, array $items, string $userId, bool $isAdmin, string $institutionCode): array
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
            $questions[AcrlEntryDataMapper::questionKey('survey', $question['question_id'], $question['part'])] = $question;
        }
        $answerMap = AcrlEntryDataMapper::answerMap($this->repository->answers($year, 'survey', $institutionCode));
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
            $key = AcrlEntryDataMapper::questionKey('survey', $questionId, $part);
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
            if (!$this->values->isEmpty($answerMap[$key] ?? AcrlEntryDataMapper::emptyAnswer())) {
                throw new \DomainException('The selected suggestion is no longer available.');
            }
            $selected[$key] = $question;
        }

        $status = $this->repository->sectionStatus($year, $section, $institutionCode);
        if ($status && $status['status'] === 'ready' && !$isAdmin) {
            throw new \DomainException('This section is ready for review and is read-only.');
        }

        $history = $this->history->forQuestions($year, array_values($selected), $institutionCode);
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
        return $this->repository->saveSuggestions($suggestions, $userId, $institutionCode);
    }

    public function setSectionStatus(int $year, string $section, string $status, string $userId, bool $isAdmin, string $institutionCode): array
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
        return $this->repository->setSectionStatus($year, $section, $status, $userId, $institutionCode) ?: [];
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

}
