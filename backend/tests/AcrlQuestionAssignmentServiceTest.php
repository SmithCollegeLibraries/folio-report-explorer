<?php

$servicePath = __DIR__ . '/../services/AcrlQuestionAssignmentService.php';
$serviceAvailable = file_exists($servicePath);
if ($serviceAvailable) {
    require_once $servicePath;
}

$failures = [];

function acrlQuestionAssignmentCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function acrlQuestionAssignmentSame($expected, $actual, string $message): void
{
    acrlQuestionAssignmentCheck($expected === $actual, $message
        . "\nExpected: " . var_export($expected, true)
        . "\nActual: " . var_export($actual, true));
}

function acrlQuestionAssignmentScenario(string $name, callable $scenario): void
{
    try {
        $scenario();
    } catch (Throwable $error) {
        acrlQuestionAssignmentCheck(false, $name . ' threw ' . get_class($error) . ': ' . $error->getMessage());
    }
}

if (!$serviceAvailable || !class_exists('app\\services\\AcrlQuestionAssignmentService')) {
    acrlQuestionAssignmentCheck(false, 'AcrlQuestionAssignmentService must be loadable for question assignment behavior tests.');
} else {
    class AcrlQuestionAssignmentTestRepository
    {
        public $publishedYears = [2025 => true, 2026 => true];
        public $catalogue = [];
        public $rows = [];
        public $links = [];
        public $sectionRows = [];
        public $writes = [];

        public function isPublishedYear(int $year): bool
        {
            return isset($this->publishedYears[$year]);
        }

        public function questions(int $year, ?string $sheet = null): array
        {
            $questions = [];
            foreach ($this->catalogue as $question) {
                if ((int)$question['survey_year'] === $year && ($sheet === null || $question['sheet'] === $sheet)) {
                    $questions[] = $question;
                }
            }
            return $questions;
        }

        public function question(int $year, string $sheet, string $questionId, string $part): ?array
        {
            foreach ($this->questions($year, $sheet) as $question) {
                if ($question['question_id'] === $questionId && $question['part'] === $part) {
                    return $question;
                }
            }
            return null;
        }

        public function assignments(int $year): array
        {
            return array_values(array_filter($this->rows, function ($row) use ($year) {
                return (int)$row['survey_year'] === $year;
            }));
        }

        public function approvedLinks(int $previousYear, int $currentYear): array
        {
            return array_values(array_filter($this->links, function ($link) use ($previousYear, $currentYear) {
                return (int)$link['previous_year'] === $previousYear && (int)$link['current_year'] === $currentYear;
            }));
        }

        public function replaceQuestionAssignments(int $year, string $sheet, string $questionId, string $part, array $userIds, int $assignedBy): void
        {
            $this->writes[] = [$year, $sheet, $questionId, $part, $userIds, $assignedBy];
            $this->rows = array_values(array_filter($this->rows, function ($row) use ($year, $sheet, $questionId, $part) {
                return !((int)$row['survey_year'] === $year && $row['sheet'] === $sheet
                    && $row['question_id'] === $questionId && $row['part'] === $part);
            }));
            foreach ($userIds as $userId) {
                $this->rows[] = [
                    'survey_year' => $year, 'sheet' => $sheet, 'question_id' => $questionId,
                    'part' => $part, 'user_id' => $userId, 'assigned_by' => $assignedBy,
                ];
            }
        }

        public function insertQuestionAssignments(array $rows): void
        {
            $this->writes[] = ['insert', $rows];
            foreach ($rows as $row) {
                $this->rows[] = $row;
            }
        }
    }

    class AcrlQuestionAssignmentTestUsers
    {
        private $users = [
            1 => ['id' => 1, 'displayName' => 'Alex Editor'],
            2 => ['id' => 2, 'displayName' => 'Casey Editor'],
            3 => ['id' => 3, 'displayName' => 'Jordan Editor'],
        ];

        public function findByIds(array $ids): array
        {
            $approved = [];
            foreach ($ids as $id) {
                if (isset($this->users[(int)$id])) {
                    $approved[] = $this->users[(int)$id];
                }
            }
            return $approved;
        }
    }

    function acrlQuestionAssignmentQuestion(int $year, string $sheet, string $id, string $part, string $section, int $order): array
    {
        return [
            'survey_year' => $year, 'sheet' => $sheet, 'question_id' => $id, 'part' => $part,
            'section' => $section, 'prompt' => $id . ' prompt', 'survey_order' => $order,
        ];
    }

    function acrlQuestionAssignmentService(AcrlQuestionAssignmentTestRepository $repository)
    {
        return new \app\services\AcrlQuestionAssignmentService($repository, new AcrlQuestionAssignmentTestUsers());
    }

    acrlQuestionAssignmentScenario('save, replace, and clear by full tuple', function () {
        $repository = new AcrlQuestionAssignmentTestRepository();
        $repository->catalogue = [
            acrlQuestionAssignmentQuestion(2026, 'survey', '60', 'A', 'Circulation', 1),
            acrlQuestionAssignmentQuestion(2026, 'survey', '60', 'B', 'Circulation', 2),
            acrlQuestionAssignmentQuestion(2026, 'survey', '61', '', 'Circulation', 3),
        ];
        $repository->rows = [[
            'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'B', 'user_id' => 1,
        ]];
        $service = acrlQuestionAssignmentService($repository);

        $saved = $service->saveQuestion(2026, 'survey', '60', 'A', [2, '2'], '9');
        acrlQuestionAssignmentSame(['sheet' => 'survey', 'questionId' => '60', 'part' => 'A', 'section' => 'Circulation', 'assignees' => [['id' => 2, 'displayName' => 'Casey Editor']]], $saved, 'Saving replaces one question tuple and returns question-level approved assignees.');
        acrlQuestionAssignmentSame([2026, 'survey', '60', 'A', [2], 9], $repository->writes[0] ?? null, 'Saving deduplicates IDs and writes the exact tuple and administrator ID.');
        acrlQuestionAssignmentSame(1, count($repository->assignments(2026)), 'Saving one question leaves the adjacent part untouched.');

        $cleared = $service->saveQuestion(2026, 'survey', '60', 'A', [], '9');
        acrlQuestionAssignmentSame([], $cleared['assignees'] ?? null, 'An empty user list clears question-level assignees.');
        acrlQuestionAssignmentSame('B', $repository->assignments(2026)[0]['part'] ?? null, 'Clearing one tuple leaves another part assigned.');
    });

    acrlQuestionAssignmentScenario('empty part, case, and leading zeros remain distinct', function () {
        $repository = new AcrlQuestionAssignmentTestRepository();
        $repository->catalogue = [
            acrlQuestionAssignmentQuestion(2026, 'survey', '01', '', 'Circulation', 1),
            acrlQuestionAssignmentQuestion(2026, 'survey', '1', '', 'Circulation', 2),
            acrlQuestionAssignmentQuestion(2026, 'survey', 'Qx', '', 'Circulation', 3),
            acrlQuestionAssignmentQuestion(2026, 'survey', 'qx', '', 'Circulation', 4),
        ];
        $service = acrlQuestionAssignmentService($repository);
        $service->saveQuestion(2026, 'survey', '01', '', [1], '9');
        $service->saveQuestion(2026, 'survey', '1', '', [2], '9');
        $service->saveQuestion(2026, 'survey', 'Qx', '', [1], '9');
        $service->saveQuestion(2026, 'survey', 'qx', '', [2], '9');
        acrlQuestionAssignmentSame([
            ['01', 1], ['1', 2], ['Qx', 1], ['qx', 2],
        ], array_map(function ($row) { return [$row['question_id'], $row['user_id']]; }, $repository->assignments(2026)), 'Question IDs preserve leading zeros and ASCII case, and the empty part remains a real tuple value.');
    });

    acrlQuestionAssignmentScenario('save rejects unknown tuples, unpublished years, unapproved users, and invalid IDs', function () {
        $repository = new AcrlQuestionAssignmentTestRepository();
        $repository->catalogue = [acrlQuestionAssignmentQuestion(2026, 'survey', '60', 'A', 'Circulation', 1)];
        $service = acrlQuestionAssignmentService($repository);
        $cases = [
            [2030, 'survey', '60', 'A', [1], 'The selected ACRL year is not published.'],
            [2026, 'survey', '999', '', [1], 'The selected ACRL question is not available.'],
            [2026, 'survey', '60', 'A', [99], 'Only approved users can be assigned.'],
            [2026, 'survey', '60', 'A', [0], 'User IDs must be positive integers.'],
            [2026, 'survey', '60', 'A', [1.5], 'User IDs must be positive integers.'],
        ];
        foreach ($cases as $case) {
            $caught = null;
            try {
                $service->saveQuestion($case[0], $case[1], $case[2], $case[3], $case[4], '9');
            } catch (Throwable $error) {
                $caught = $error;
            }
            acrlQuestionAssignmentCheck($caught !== null && $caught->getMessage() === $case[5], 'saveQuestion must reject ' . $case[2] . ':' . $case[3] . ' with the established ACRL error.');
        }
    });

    acrlQuestionAssignmentScenario('list ignores stale tuples and revoked users, then applies a re-added tuple', function () {
        $repository = new AcrlQuestionAssignmentTestRepository();
        $repository->catalogue = [
            acrlQuestionAssignmentQuestion(2026, 'survey', '60', 'A', 'Circulation', 1),
            acrlQuestionAssignmentQuestion(2026, 'survey', '61', '', 'Circulation', 2),
        ];
        $repository->rows = [
            ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => 1],
            ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '61', 'part' => '', 'user_id' => 99],
            ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'removed', 'part' => '', 'user_id' => 1],
        ];
        $service = acrlQuestionAssignmentService($repository);
        $first = $service->listForYear(2026);
        acrlQuestionAssignmentSame([
            ['sheet' => 'survey', 'questionId' => '60', 'part' => 'A', 'section' => 'Circulation', 'assignees' => [['id' => 1, 'displayName' => 'Alex Editor']]],
        ], $first['questions'] ?? null, 'The question list is in survey order and excludes stale questions and questions with only revoked users.');

        $repository->catalogue[] = acrlQuestionAssignmentQuestion(2026, 'survey', 'removed', '', 'Services', 3);
        $readded = $service->listForYear(2026);
        acrlQuestionAssignmentCheck(isset($readded['questions'][1]) && $readded['questions'][1]['questionId'] === 'removed', 'A stale tuple becomes effective again if the catalogue re-adds the same question.');
        acrlQuestionAssignmentSame([['id' => 1, 'displayName' => 'Alex Editor']], $readded['questions'][1]['assignees'] ?? null, 'Re-added tuples retain their stored assignment.');
    });

    acrlQuestionAssignmentScenario('copy follows approved links across sheets, splits, merges, renumbering, and stale rows', function () {
        $repository = new AcrlQuestionAssignmentTestRepository();
        $repository->catalogue = [
            acrlQuestionAssignmentQuestion(2025, 'survey', '01', '', 'Staffing', 1),
            acrlQuestionAssignmentQuestion(2025, 'survey', 'S2', '', 'Staffing', 2),
            acrlQuestionAssignmentQuestion(2025, 'survey', 'M1', '', 'Services', 3),
            acrlQuestionAssignmentQuestion(2025, 'survey', 'M2', '', 'Services', 4),
            acrlQuestionAssignmentQuestion(2025, 'survey', '88', '', 'Services', 5),
            acrlQuestionAssignmentQuestion(2025, 'survey', 'R1', '', 'Services', 6),
            acrlQuestionAssignmentQuestion(2025, 'trends', 'T1', '', 'Trends', 7),
            acrlQuestionAssignmentQuestion(2025, 'trends', 'T2', '', 'Trends', 8),
            acrlQuestionAssignmentQuestion(2026, 'survey', '1', '', 'Staffing', 1),
            acrlQuestionAssignmentQuestion(2026, 'survey', 'S2', 'A', 'Staffing', 2),
            acrlQuestionAssignmentQuestion(2026, 'survey', 'S2', 'B', 'Staffing', 3),
            acrlQuestionAssignmentQuestion(2026, 'survey', 'M3', '', 'Services', 4),
            acrlQuestionAssignmentQuestion(2026, 'survey', '88', '', 'Services', 5),
            acrlQuestionAssignmentQuestion(2026, 'survey', 'R1', '', 'Services', 6),
            acrlQuestionAssignmentQuestion(2026, 'trends', 'T0', '', 'Trends', 1),
        ];
        $repository->rows = [
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => '01', 'part' => '', 'user_id' => 1],
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'S2', 'part' => '', 'user_id' => 1],
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'M1', 'part' => '', 'user_id' => 1],
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'M2', 'part' => '', 'user_id' => 2],
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => '88', 'part' => '', 'user_id' => 1],
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'R1', 'part' => '', 'user_id' => 1],
            ['survey_year' => 2025, 'sheet' => 'trends', 'question_id' => 'T1', 'part' => '', 'user_id' => 1],
            ['survey_year' => 2025, 'sheet' => 'trends', 'question_id' => 'T2', 'part' => '', 'user_id' => 2],
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'gone', 'part' => '', 'user_id' => 1],
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'M2', 'part' => '', 'user_id' => 99],
            ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'S2', 'part' => 'B', 'user_id' => 2],
        ];
        $repository->links = [
            ['previous_year' => 2025, 'current_year' => 2026, 'sheet' => 'survey', 'previous_question_id' => '01', 'previous_part' => '', 'current_question_id' => '1', 'current_part' => '', 'decision' => 'approved'],
            ['previous_year' => 2025, 'current_year' => 2026, 'sheet' => 'survey', 'previous_question_id' => 'S2', 'previous_part' => '', 'current_question_id' => 'S2', 'current_part' => 'A', 'decision' => 'approved'],
            ['previous_year' => 2025, 'current_year' => 2026, 'sheet' => 'survey', 'previous_question_id' => 'S2', 'previous_part' => '', 'current_question_id' => 'S2', 'current_part' => 'B', 'decision' => 'approved'],
            ['previous_year' => 2025, 'current_year' => 2026, 'sheet' => 'survey', 'previous_question_id' => 'M1', 'previous_part' => '', 'current_question_id' => 'M3', 'current_part' => '', 'decision' => 'approved'],
            ['previous_year' => 2025, 'current_year' => 2026, 'sheet' => 'survey', 'previous_question_id' => 'M2', 'previous_part' => '', 'current_question_id' => 'M3', 'current_part' => '', 'decision' => 'approved'],
            ['previous_year' => 2025, 'current_year' => 2026, 'sheet' => 'survey', 'previous_question_id' => 'R1', 'previous_part' => '', 'current_question_id' => 'R1', 'current_part' => '', 'decision' => 'rejected'],
            ['previous_year' => 2025, 'current_year' => 2026, 'sheet' => 'trends', 'previous_question_id' => 'T1', 'previous_part' => '', 'current_question_id' => 'T0', 'current_part' => '', 'decision' => 'approved'],
        ];
        $service = acrlQuestionAssignmentService($repository);
        $result = $service->copyFromPrevious(2026, '9');
        acrlQuestionAssignmentSame([
            ['sheet' => 'survey', 'questionId' => '1', 'part' => '', 'label' => 'Q1'],
            ['sheet' => 'survey', 'questionId' => 'S2', 'part' => 'A', 'label' => 'QS2 · A'],
            ['sheet' => 'survey', 'questionId' => 'M3', 'part' => '', 'label' => 'QM3'],
            ['sheet' => 'trends', 'questionId' => 'T0', 'part' => '', 'label' => 'QT0'],
        ], $result['copiedQuestions'] ?? null, 'Copy uses approved full-tuple links, copies split and merge targets, and excludes stale/revoked source rows.');
        acrlQuestionAssignmentSame([
            ['sheet' => 'survey', 'questionId' => 'S2', 'part' => 'B', 'label' => 'QS2 · B'],
        ], $result['skippedQuestions'] ?? null, 'Copy skips only target questions that already have question-level assignments.');
        acrlQuestionAssignmentSame([
            ['sheet' => 'survey', 'questionId' => '88', 'part' => '', 'label' => 'Q88'],
            ['sheet' => 'survey', 'questionId' => 'R1', 'part' => '', 'label' => 'QR1'],
            ['sheet' => 'trends', 'questionId' => 'T2', 'part' => '', 'label' => 'QT2'],
        ], $result['unmatchedQuestions'] ?? null, 'Same-ID reuse, rejected links, and unlinked Trends questions remain unmatched.');
        $mergeRows = array_values(array_filter($repository->assignments(2026), function ($row) {
            return $row['question_id'] === 'M3';
        }));
        acrlQuestionAssignmentSame([1, 2], array_column($mergeRows, 'user_id'), 'Approved source users from a merge are unioned on the target question.');
        acrlQuestionAssignmentSame(false, $result['questionsAlreadyAssigned'] ?? null, 'Copy from FY N-1 does not report the publish-only guard as set.');
    });

    acrlQuestionAssignmentScenario('publish copy guard ignores section assignments and stale or revoked question rows', function () {
        $repository = new AcrlQuestionAssignmentTestRepository();
        $repository->catalogue = [
            acrlQuestionAssignmentQuestion(2025, 'survey', '60', 'A', 'Circulation', 1),
            acrlQuestionAssignmentQuestion(2026, 'survey', '60', 'A', 'Circulation', 1),
        ];
        $repository->rows = [
            ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => 1],
            ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'removed', 'part' => '', 'user_id' => 1],
            ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => 99],
        ];
        $repository->sectionRows = [['survey_year' => 2026, 'section' => 'Circulation', 'user_id' => 1]];
        $result = acrlQuestionAssignmentService($repository)->copyOnPublish(2026, '9');
        acrlQuestionAssignmentSame(false, $result['questionsAlreadyAssigned'] ?? null, 'Existing section assignments and inactive question rows do not block question copy on publish.');
        acrlQuestionAssignmentSame([['sheet' => 'survey', 'questionId' => '60', 'part' => 'A', 'label' => 'Q60 · A']], $result['copiedQuestions'] ?? null, 'Question copy remains independent from the section-copy guard.');

        $repository->rows[] = ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => 2];
        $guarded = acrlQuestionAssignmentService($repository)->copyOnPublish(2026, '9');
        acrlQuestionAssignmentSame(true, $guarded['questionsAlreadyAssigned'] ?? null, 'A current target tuple with an approved assignee activates the independent question-copy guard.');
        acrlQuestionAssignmentSame([], $guarded['copiedQuestions'] ?? null, 'The publish guard does not overwrite an assigned question.');
    });

acrlQuestionAssignmentScenario('publish copy reports empty question results when the prior year is unpublished', function () {
    $repository = new AcrlQuestionAssignmentTestRepository();
    $repository->catalogue = [
        acrlQuestionAssignmentQuestion(2025, 'survey', '60', 'A', 'Circulation', 1),
        acrlQuestionAssignmentQuestion(2026, 'survey', '60', 'A', 'Circulation', 1),
    ];
    $repository->rows = [
        ['survey_year' => 2025, 'sheet' => 'survey', 'question_id' => '60', 'part' => 'A', 'user_id' => 1],
    ];
    unset($repository->publishedYears[2025]);

    $result = acrlQuestionAssignmentService($repository)->copyOnPublish(2026, '9');
    acrlQuestionAssignmentSame([], $result['copiedQuestions'] ?? null, 'An unpublished previous year contributes no copied question assignments.');
    acrlQuestionAssignmentSame([], $result['skippedQuestions'] ?? null, 'An unpublished previous year contributes no skipped question assignments.');
    acrlQuestionAssignmentSame([], $result['unmatchedQuestions'] ?? null, 'An unpublished previous year contributes no unmatched question assignments.');
    acrlQuestionAssignmentSame(false, $result['questionsAlreadyAssigned'] ?? null, 'An unpublished previous year leaves the question-copy guard false.');
});
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL question-assignment checks failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "PASS: ACRL question-assignment save, list, stale-row, and copy rules\n";
