<?php

namespace app\services;

require_once __DIR__ . '/AcrlSectionAssignmentService.php';

/** Applies assignment rules for individual questions in an annual ACRL survey. */
class AcrlQuestionAssignmentService
{
    private $repository;
    private $users;

    public function __construct($repository = null, $users = null)
    {
        $this->repository = $repository ?: new AcrlQuestionAssignmentRepository();
        $this->users = $users ?: new AcrlApprovedUserDirectoryService();
    }

    public function saveQuestion(int $year, string $sheet, string $questionId, string $part, array $userIds, string $assignedBy): array
    {
        $this->requirePublishedYear($year);
        if (!in_array($sheet, ['survey', 'trends'], true) || $part === '_') {
            throw new \InvalidArgumentException('The selected ACRL question is not available.');
        }
        $question = $this->repository->question($year, $sheet, $questionId, $part);
        if (!$question) {
            throw new \DomainException('The selected ACRL question is not available.');
        }
        $userIds = AcrlSectionAssignmentService::normalizeUserIds($userIds);
        AcrlSectionAssignmentService::requireApprovedUsers($this->users, $userIds);
        $actorId = AcrlSectionAssignmentService::actorId($assignedBy);
        $this->repository->replaceQuestionAssignments($year, $sheet, $questionId, $part, $userIds, $actorId);
        $assignees = $this->approvedAssigneesForQuestion($year, $sheet, $questionId, $part);
        return [
            'sheet' => $sheet,
            'questionId' => $questionId,
            'part' => $part,
            'section' => $question['section'],
            'assignees' => $assignees,
        ];
    }

    public function listForYear(int $year): array
    {
        $this->requirePublishedYear($year);
        $questions = $this->repository->questions($year);
        $assigneesByQuestion = $this->approvedUsersByQuestion($questions, $this->repository->assignments($year));
        $result = [];
        foreach ($questions as $question) {
            $key = $this->questionKey($question['sheet'], $question['question_id'], $question['part']);
            if (empty($assigneesByQuestion[$key])) {
                continue;
            }
            $result[] = [
                'sheet' => $question['sheet'],
                'questionId' => $question['question_id'],
                'part' => $question['part'],
                'section' => $question['section'],
                'assignees' => $assigneesByQuestion[$key],
            ];
        }
        return ['surveyYear' => $year, 'questions' => $result];
    }

    public function forEntry(int $year, ?string $userId): array
    {
        $currentUserId = (int)$userId;
        $result = [];
        foreach ($this->listForYear($year)['questions'] as $question) {
            $assigned = false;
            foreach ($question['assignees'] as $assignee) {
                if ((int)$assignee['id'] === $currentUserId) {
                    $assigned = true;
                    break;
                }
            }
            $result[$this->questionKey($question['sheet'], $question['questionId'], $question['part'])] = [
                'assignees' => $question['assignees'],
                'assignedToCurrentUser' => $assigned,
            ];
        }
        return $result;
    }

    public function copyFromPrevious(int $year, string $assignedBy): array
    {
        $this->requirePublishedYear($year);
        $previousYear = $year - 1;
        $this->requirePublishedYear($previousYear, 'The previous ACRL year is not published.');
        return $this->copyWithYearLock($year, function () use ($year, $previousYear, $assignedBy) {
            return $this->copyQuestionAssignments($year, $previousYear, $assignedBy, false);
        });
    }

    public function copyOnPublish(int $year, string $assignedBy): array
    {
        $this->requirePublishedYear($year);
        $previousYear = $year - 1;
        if (!$this->repository->isPublishedYear($previousYear)) {
            return $this->emptyCopyResult($year, $previousYear);
        }
        return $this->copyWithYearLock($year, function () use ($year, $previousYear, $assignedBy) {
            return $this->copyQuestionAssignments($year, $previousYear, $assignedBy, true);
        });
    }

    private function copyWithYearLock(int $year, callable $copy): array
    {
        if (method_exists($this->repository, 'withLockedYear')) {
            return $this->repository->withLockedYear($year, $copy);
        }
        return $copy();
    }

    private function copyQuestionAssignments(int $year, int $previousYear, string $assignedBy, bool $onlyIfYearEmpty): array
    {
        $currentQuestions = $this->repository->questions($year);
        $previousQuestions = $this->repository->questions($previousYear);
        $currentByKey = $this->questionsByKey($currentQuestions);
        $previousByKey = $this->questionsByKey($previousQuestions);
        $currentAssignees = $this->approvedUsersByQuestion($currentQuestions, $this->repository->assignments($year));
        $hasCurrentAssignments = !empty($currentAssignees);

        $sourceAssignees = $this->approvedUsersByQuestion($previousQuestions, $this->repository->assignments($previousYear));
        $targetsBySource = [];
        foreach ($this->repository->approvedLinks($previousYear, $year) as $link) {
            if (($link['decision'] ?? null) !== 'approved'
                || (int)$link['previous_year'] !== $previousYear
                || (int)$link['current_year'] !== $year) {
                continue;
            }
            $sheet = (string)$link['sheet'];
            $sourceKey = $this->questionKey($sheet, $link['previous_question_id'], $link['previous_part']);
            $targetKey = $this->questionKey($sheet, $link['current_question_id'], $link['current_part']);
            if (isset($previousByKey[$sourceKey]) && isset($currentByKey[$targetKey])) {
                $targetsBySource[$sourceKey][$targetKey] = true;
            }
        }

        $unmatchedQuestions = [];
        $usersByTarget = [];
        foreach ($previousQuestions as $question) {
            $sourceKey = $this->questionKey($question['sheet'], $question['question_id'], $question['part']);
            if (empty($sourceAssignees[$sourceKey])) {
                continue;
            }
            if (empty($targetsBySource[$sourceKey])) {
                $unmatchedQuestions[] = $this->questionLabel($question);
                continue;
            }
            foreach (array_keys($targetsBySource[$sourceKey]) as $targetKey) {
                foreach ($sourceAssignees[$sourceKey] as $assignee) {
                    $usersByTarget[$targetKey][(int)$assignee['id']] = true;
                }
            }
        }

        $questionsAlreadyAssigned = $onlyIfYearEmpty && $hasCurrentAssignments;
        $copiedQuestions = [];
        $skippedQuestions = [];
        $rowsToInsert = [];
        if ($questionsAlreadyAssigned) {
            foreach ($currentQuestions as $question) {
                $key = $this->questionKey($question['sheet'], $question['question_id'], $question['part']);
                if (!empty($currentAssignees[$key])) {
                    $skippedQuestions[] = $this->questionLabel($question);
                }
            }
        } else {
            foreach ($currentQuestions as $question) {
                $key = $this->questionKey($question['sheet'], $question['question_id'], $question['part']);
                if (empty($usersByTarget[$key])) {
                    continue;
                }
                if (!empty($currentAssignees[$key])) {
                    $skippedQuestions[] = $this->questionLabel($question);
                    continue;
                }
                $copiedQuestions[] = $this->questionLabel($question);
                foreach (array_keys($usersByTarget[$key]) as $userId) {
                    $rowsToInsert[] = [
                        'survey_year' => $year,
                        'sheet' => $question['sheet'],
                        'question_id' => $question['question_id'],
                        'part' => $question['part'],
                        'user_id' => (int)$userId,
                        'assigned_by' => 0,
                    ];
                }
            }
        }
        if (!empty($rowsToInsert)) {
            $actorId = AcrlSectionAssignmentService::actorId($assignedBy);
            foreach ($rowsToInsert as &$row) {
                $row['assigned_by'] = $actorId;
            }
            unset($row);
            $this->repository->insertQuestionAssignments($rowsToInsert);
        }
        return [
            'surveyYear' => $year,
            'sourceYear' => $previousYear,
            'copiedQuestions' => $copiedQuestions,
            'skippedQuestions' => $skippedQuestions,
            'unmatchedQuestions' => $unmatchedQuestions,
            'questionsAlreadyAssigned' => $questionsAlreadyAssigned,
        ];
    }

    private function approvedAssigneesForQuestion(int $year, string $sheet, string $questionId, string $part): array
    {
        $question = $this->repository->question($year, $sheet, $questionId, $part);
        if (!$question) {
            return [];
        }
        $key = $this->questionKey($sheet, $questionId, $part);
        $approved = $this->approvedUsersByQuestion([$question], $this->repository->assignments($year));
        return $approved[$key] ?? [];
    }

    private function approvedUsersByQuestion(array $questions, array $assignmentRows): array
    {
        $questionKeys = [];
        foreach ($questions as $question) {
            $questionKeys[$this->questionKey($question['sheet'], $question['question_id'], $question['part'])] = true;
        }
        $ids = [];
        foreach ($assignmentRows as $row) {
            $key = $this->questionKey($row['sheet'], $row['question_id'], $row['part']);
            if (isset($questionKeys[$key])) {
                $ids[(int)$row['user_id']] = (int)$row['user_id'];
            }
        }
        $usersById = $this->usersById(array_values($ids));
        $assignees = [];
        foreach ($assignmentRows as $row) {
            $key = $this->questionKey($row['sheet'], $row['question_id'], $row['part']);
            $userId = (int)$row['user_id'];
            if (isset($questionKeys[$key]) && isset($usersById[$userId])) {
                $assignees[$key][$userId] = $usersById[$userId];
            }
        }
        foreach ($assignees as $key => $users) {
            $assignees[$key] = array_values($users);
        }
        return $assignees;
    }

    private function questionsByKey(array $questions): array
    {
        $result = [];
        foreach ($questions as $question) {
            $result[$this->questionKey($question['sheet'], $question['question_id'], $question['part'])] = $question;
        }
        return $result;
    }

    private function usersById(array $ids): array
    {
        $result = [];
        foreach ($this->users->findByIds($ids) as $user) {
            $result[(int)$user['id']] = $user;
        }
        return $result;
    }

    private function questionLabel(array $question): array
    {
        return [
            'sheet' => $question['sheet'],
            'questionId' => $question['question_id'],
            'part' => $question['part'],
            'label' => 'Q' . $question['question_id'] . ($question['part'] !== '' ? ' · ' . $question['part'] : ''),
        ];
    }

    private function questionKey(string $sheet, string $questionId, string $part): string
    {
        return serialize([$sheet, $questionId, $part]);
    }

    private function emptyCopyResult(int $year, int $previousYear): array
    {
        return [
            'surveyYear' => $year,
            'sourceYear' => $previousYear,
            'copiedQuestions' => [],
            'skippedQuestions' => [],
            'unmatchedQuestions' => [],
            'questionsAlreadyAssigned' => false,
        ];
    }

    private function requirePublishedYear(int $year, string $message = 'The selected ACRL year is not published.'): void
    {
        if (!$this->repository->isPublishedYear($year)) {
            throw new \DomainException($message);
        }
    }
}
