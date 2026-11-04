<?php

namespace app\services;

/** Applies assignment rules for annual ACRL survey sections. */
class AcrlSectionAssignmentService
{
    private $repository;
    private $users;
    private $questionAssignments;

    public function __construct($repository = null, $users = null, $questionAssignments = null)
    {
        $this->repository = $repository ?: new AcrlSectionAssignmentRepository();
        $this->users = $users ?: new AcrlApprovedUserDirectoryService();
        $this->questionAssignments = $questionAssignments ?: ($repository === null
            ? new AcrlQuestionAssignmentService(null, $this->users)
            : null);
    }

    public function searchApprovedUsers(string $query): array
    {
        return $this->users->search($query);
    }

    public function listForYear(int $year): array
    {
        $this->requirePublishedYear($year);
        $sections = $this->repository->sections($year);
        $rows = $this->repository->assignments($year);
        $userIds = array_map(function ($row) {
            return (int)$row['user_id'];
        }, $rows);
        $usersById = $this->usersById($userIds);
        $assigneesBySection = [];
        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            if (isset($usersById[$userId])) {
                $assigneesBySection[$row['section']][] = $usersById[$userId];
            }
        }
        $result = [];
        foreach ($sections as $section) {
            $result[] = [
                'section' => $section,
                'assignees' => $assigneesBySection[$section] ?? [],
            ];
        }
        return ['surveyYear' => $year, 'sections' => $result];
    }

    public function forEntry(int $year, ?string $userId): array
    {
        $entry = $this->listForYear($year);
        $userId = (int)$userId;
        $result = [];
        foreach ($entry['sections'] as $section) {
            $assigned = false;
            foreach ($section['assignees'] as $assignee) {
                if ((int)$assignee['id'] === $userId) {
                    $assigned = true;
                    break;
                }
            }
            $result[$section['section']] = [
                'assignees' => $section['assignees'],
                'assignedToCurrentUser' => $assigned,
            ];
        }
        return $result;
    }

    public function saveSection(int $year, string $section, array $userIds, string $assignedBy): array
    {
        $this->requirePublishedYear($year);
        $section = trim($section);
        if ($section === '' || !in_array($section, $this->repository->sections($year), true)) {
            throw new \DomainException('The selected ACRL section is not available.');
        }
        $userIds = self::normalizeUserIds($userIds);
        self::requireApprovedUsers($this->users, $userIds);
        $actorId = self::actorId($assignedBy);
        $this->repository->replaceSections($year, [$section => $userIds], $actorId);
        foreach ($this->listForYear($year)['sections'] as $item) {
            if ($item['section'] === $section) {
                return $item;
            }
        }
        return ['section' => $section, 'assignees' => []];
    }

    public function copyFromPrevious(int $year, string $assignedBy): array
    {
        return $this->copyMatchingSections($year, $assignedBy, false);
    }

    public function copyOnPublish(int $year, string $assignedBy): array
    {
        return $this->copyMatchingSections($year, $assignedBy, true);
    }

    private function copyMatchingSections(int $year, string $assignedBy, bool $onlyIfYearEmpty): array
    {
        $this->requirePublishedYear($year);
        $copy = function () use ($year, $assignedBy, $onlyIfYearEmpty) {
            return $this->copyMatchingSectionsWhileLocked($year, $assignedBy, $onlyIfYearEmpty);
        };
        if (method_exists($this->repository, 'withLockedYear')) {
            return $this->repository->withLockedYear($year, $copy);
        }
        return $copy();
    }

    private function copyMatchingSectionsWhileLocked(int $year, string $assignedBy, bool $onlyIfYearEmpty): array
    {
        $previousYear = $year - 1;
        $targetAlreadyAssigned = $onlyIfYearEmpty && !empty($this->repository->assignments($year));
        $toCopy = [];
        $unmatchedSections = [];
        if (!$targetAlreadyAssigned) {
            $this->requirePublishedYear($previousYear, 'The previous ACRL year is not published.');
            $targetSections = array_fill_keys($this->repository->sections($year), true);
            $sourceRows = $this->repository->assignments($previousYear);
        } else {
            $targetSections = [];
            $sourceRows = [];
        }
        $sourceBySection = [];
        foreach ($sourceRows as $row) {
            $sourceBySection[$row['section']][] = (int)$row['user_id'];
        }
        $allUserIds = [];
        foreach ($sourceBySection as $userIds) {
            $allUserIds = array_merge($allUserIds, $userIds);
        }
        $usersById = $this->usersById($allUserIds);
        foreach ($sourceBySection as $section => $userIds) {
            if (!isset($targetSections[$section])) {
                $unmatchedSections[] = $section;
                continue;
            }
            $approvedUserIds = array_values(array_filter($userIds, function ($userId) use ($usersById) {
                return isset($usersById[$userId]);
            }));
            if (!empty($approvedUserIds)) {
                $toCopy[$section] = $approvedUserIds;
            }
        }
        $writeResult = [
            'copiedSections' => [],
            'skippedSections' => [],
            'alreadyAssigned' => false,
        ];
        if ($onlyIfYearEmpty && $targetAlreadyAssigned) {
            $existingSections = [];
            foreach ($this->repository->assignments($year) as $row) {
                $existingSections[$row['section']] = true;
            }
            $writeResult = [
                'copiedSections' => [],
                'skippedSections' => array_keys($existingSections),
                'alreadyAssigned' => true,
            ];
        }
        $questionCopy = [];
        $afterSections = null;
        if ($this->questionAssignments) {
            $afterSections = function () use ($year, $assignedBy, $onlyIfYearEmpty, &$questionCopy) {
                $questionCopy = $onlyIfYearEmpty
                    ? $this->questionAssignments->copyOnPublish($year, $assignedBy)
                    : $this->questionAssignments->copyFromPrevious($year, $assignedBy);
            };
        }
        $actorId = empty($toCopy) ? 0 : self::actorId($assignedBy);
        if ($onlyIfYearEmpty) {
            if ($afterSections !== null) {
                $writeResult = $this->repository->copySectionsIfYearEmpty($year, $toCopy, $actorId, $afterSections);
            } elseif (!$targetAlreadyAssigned && !empty($toCopy)) {
                $writeResult = $this->repository->copySectionsIfYearEmpty($year, $toCopy, $actorId);
            }
        } elseif ($afterSections !== null) {
            $writeResult = $this->repository->fillUnassignedSections($year, $toCopy, $actorId, $afterSections);
        } elseif (!empty($toCopy)) {
            $writeResult = $this->repository->fillUnassignedSections($year, $toCopy, $actorId);
        }
        return array_merge([
            'surveyYear' => $year,
            'sourceYear' => $previousYear,
            'copiedSections' => $writeResult['copiedSections'],
            'skippedSections' => $writeResult['skippedSections'],
            'unmatchedSections' => $unmatchedSections,
            'alreadyAssigned' => $writeResult['alreadyAssigned'],
            'failed' => false,
        ], $questionCopy);
    }

    private function requirePublishedYear(int $year, string $message = 'The selected ACRL year is not published.'): void
    {
        if (!$this->repository->isPublishedYear($year)) {
            throw new \DomainException($message);
        }
    }

    public static function normalizeUserIds(array $userIds): array
    {
        $ids = [];
        foreach ($userIds as $userId) {
            if ((!is_int($userId) && (!is_string($userId) || !ctype_digit($userId))) || (int)$userId < 1) {
                throw new \InvalidArgumentException('User IDs must be positive integers.');
            }
            $ids[(int)$userId] = (int)$userId;
        }
        return array_values($ids);
    }

    public static function actorId(string $assignedBy): int
    {
        if (!ctype_digit($assignedBy) || (int)$assignedBy < 1) {
            throw new \InvalidArgumentException('The administrator ID is invalid.');
        }
        return (int)$assignedBy;
    }

    public static function requireApprovedUsers($users, array $userIds): void
    {
        if (count($users->findByIds($userIds)) !== count($userIds)) {
            throw new \InvalidArgumentException('Only approved users can be assigned.');
        }
    }

    private function usersById(array $ids): array
    {
        $users = [];
        foreach ($this->users->findByIds($ids) as $user) {
            $users[(int)$user['id']] = $user;
        }
        return $users;
    }
}
