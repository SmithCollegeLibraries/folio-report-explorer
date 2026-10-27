<?php

require_once __DIR__ . '/../services/AcrlSectionAssignmentService.php';

use app\services\AcrlSectionAssignmentService;

class AcrlSectionAssignmentTestRepository
{
    public $replacements = [];
    private $sections = [
        2026 => ['Circulation', 'Library services'],
        2025 => ['Circulation', 'Old holdings'],
    ];
    private $assignments = [
        2026 => [['section' => 'Circulation', 'user_id' => 1]],
        2025 => [
            ['section' => 'Circulation', 'user_id' => 1],
            ['section' => 'Library services', 'user_id' => 2],
            ['section' => 'Old holdings', 'user_id' => 2],
        ],
    ];

    public function isPublishedYear(int $year): bool
    {
        return isset($this->sections[$year]);
    }

    public function sections(int $year): array
    {
        return $this->sections[$year] ?? [];
    }

    public function assignments(int $year): array
    {
        return $this->assignments[$year] ?? [];
    }

    public function setAssignments(int $year, array $rows): void
    {
        $this->assignments[$year] = $rows;
    }

    public function replaceSections(int $year, array $assignmentsBySection, int $assignedBy): void
    {
        $this->replacements[] = [$year, $assignmentsBySection, $assignedBy];
        $rows = array_values(array_filter($this->assignments[$year] ?? [], function ($row) use ($assignmentsBySection) {
            return !array_key_exists($row['section'], $assignmentsBySection);
        }));
        foreach ($assignmentsBySection as $section => $userIds) {
            foreach ($userIds as $userId) {
                $rows[] = ['section' => $section, 'user_id' => $userId];
            }
        }
        $this->assignments[$year] = $rows;
    }

    public function fillUnassignedSections(int $year, array $assignmentsBySection, int $assignedBy): array
    {
        $assignedSections = [];
        foreach ($this->assignments($year) as $row) {
            $assignedSections[$row['section']] = true;
        }
        $toCopy = [];
        $skippedSections = [];
        foreach ($assignmentsBySection as $section => $userIds) {
            if (isset($assignedSections[$section])) {
                $skippedSections[] = $section;
            } else {
                $toCopy[$section] = $userIds;
            }
        }
        if (!empty($toCopy)) {
            $this->replaceSections($year, $toCopy, $assignedBy);
        }
        return [
            'copiedSections' => array_keys($toCopy),
            'skippedSections' => $skippedSections,
            'alreadyAssigned' => false,
        ];
    }

    public function copySectionsIfYearEmpty(int $year, array $assignmentsBySection, int $assignedBy): array
    {
        $assignedSections = array_values(array_unique(array_column($this->assignments($year), 'section')));
        if (!empty($assignedSections)) {
            return [
                'copiedSections' => [],
                'skippedSections' => $assignedSections,
                'alreadyAssigned' => true,
            ];
        }
        return array_merge(
            $this->fillUnassignedSections($year, $assignmentsBySection, $assignedBy),
            ['alreadyAssigned' => false]
        );
    }
}

class AcrlSectionAssignmentTestDirectory
{
    private $users = [
        1 => ['id' => 1, 'displayName' => 'Alex Editor'],
        2 => ['id' => 2, 'displayName' => 'Casey Editor'],
    ];

    public function search(string $query): array
    {
        return array_values(array_filter($this->users, function ($user) use ($query) {
            return stripos($user['displayName'], $query) !== false;
        }));
    }

    public function findByIds(array $ids): array
    {
        $users = [];
        foreach ($ids as $id) {
            if (isset($this->users[$id])) {
                $users[] = $this->users[$id];
            }
        }
        return $users;
    }
}

function acrlAssignmentAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$repository = new AcrlSectionAssignmentTestRepository();
$directory = new AcrlSectionAssignmentTestDirectory();
$service = new AcrlSectionAssignmentService($repository, $directory);

$assignments = $service->listForYear(2026);
acrlAssignmentAssert($assignments['sections'][0]['assignees'] === [['id' => 1, 'displayName' => 'Alex Editor']], 'Assignment lists expose only approved assignee IDs and display names.');
acrlAssignmentAssert($assignments['sections'][1]['assignees'] === [], 'Sections without assignments remain listed.');
acrlAssignmentAssert($service->forEntry(2026, '1')['Circulation']['assignedToCurrentUser'], 'Entry assignments identify the signed-in user section.');
acrlAssignmentAssert(!$service->forEntry(2026, '2')['Circulation']['assignedToCurrentUser'], 'A different signed-in user is not marked as the assignee.');
acrlAssignmentAssert($service->searchApprovedUsers('Casey') === [['id' => 2, 'displayName' => 'Casey Editor']], 'Search returns only approved-user identity fields.');

$saved = $service->saveSection(2026, 'Circulation', [1, '1'], '9');
acrlAssignmentAssert($saved['assignees'] === [['id' => 1, 'displayName' => 'Alex Editor']], 'Saving deduplicates user IDs and returns current assignees.');
acrlAssignmentAssert($repository->replacements[0] === [2026, ['Circulation' => [1]], 9], 'Saving replaces only the selected section assignments and records the admin ID.');

try {
    $service->saveSection(2026, 'Circulation', [99], '9');
    acrlAssignmentAssert(false, 'Unapproved or unknown users cannot be assigned.');
} catch (InvalidArgumentException $exception) {
    acrlAssignmentAssert($exception->getMessage() === 'Only approved users can be assigned.', 'Unapproved assignees return a stable validation error.');
}

$copied = $service->copyFromPrevious(2026, '9');
acrlAssignmentAssert($copied['copiedSections'] === ['Library services'], 'Copy-forward fills only exact-name sections without current assignments.');
acrlAssignmentAssert($copied['skippedSections'] === ['Circulation'], 'Copy-forward reports matching sections that already have assignments.');
acrlAssignmentAssert($copied['unmatchedSections'] === ['Old holdings'], 'Copy-forward reports an assigned section whose name changed.');
acrlAssignmentAssert($repository->assignments(2026) === [
    ['section' => 'Circulation', 'user_id' => 1],
    ['section' => 'Library services', 'user_id' => 2],
], 'Copy-forward preserves existing assignments and adds only unassigned matches.');
acrlAssignmentAssert($repository->replacements[1] === [2026, ['Library services' => [2]], 9], 'Copy-forward persists only matching unassigned sections.');

$publishCopy = $service->copyOnPublish(2026, '9');
acrlAssignmentAssert($publishCopy['copiedSections'] === [], 'Publishing an already assigned year copies no sections.');
acrlAssignmentAssert($publishCopy['alreadyAssigned'] === true, 'Publish copy-forward identifies existing target assignments.');
acrlAssignmentAssert($repository->replacements[1][1] === ['Library services' => [2]], 'Publish copy-forward never overwrites existing assignments.');

$emptyPublishRepository = new AcrlSectionAssignmentTestRepository();
$emptyPublishRepository->setAssignments(2026, []);
$emptyPublishService = new AcrlSectionAssignmentService($emptyPublishRepository, $directory);
$emptyPublishCopy = $emptyPublishService->copyOnPublish(2026, '9');
acrlAssignmentAssert($emptyPublishCopy['copiedSections'] === ['Circulation', 'Library services'], 'An empty year receives exact-name assignments from the previous year on publish.');
acrlAssignmentAssert($emptyPublishCopy['unmatchedSections'] === ['Old holdings'], 'Publish copy-forward reports renamed source sections.');
acrlAssignmentAssert(!$emptyPublishCopy['alreadyAssigned'], 'A newly copied year is reported as previously unassigned.');

try {
    $service->listForYear(2030);
    acrlAssignmentAssert(false, 'Assignments are available only for published years.');
} catch (DomainException $exception) {
}

echo "PASS: ACRL assignment lists, approved users, saves, and non-overwriting copy-forward\n";
