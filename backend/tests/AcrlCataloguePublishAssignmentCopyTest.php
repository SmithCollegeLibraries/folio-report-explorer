<?php

class Yii
{
    public static $errors = [];

    public static function error($message, $category = 'application'): void
    {
        self::$errors[] = [$message, $category];
    }
}

require_once __DIR__ . '/../services/AcrlCatalogueReviewService.php';

class AcrlPublishAssignmentCopyTestDb
{
    public $years = [];

    public function __construct()
    {
        $this->years = [
            2025 => ['survey_year' => 2025, 'status' => 'published'],
            2026 => ['survey_year' => 2026, 'status' => 'draft'],
        ];
    }

    public function createCommand($sql = null, array $params = [])
    {
        return new AcrlPublishAssignmentCopyTestCommand($this, $sql, $params);
    }
}

class AcrlPublishAssignmentCopyTestCommand
{
    private $db;
    private $sql;
    private $params;
    private $update = null;

    public function __construct($db, $sql, array $params)
    {
        $this->db = $db;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryOne()
    {
        if (strpos($this->sql, 'FROM acrl_survey_years') !== false) {
            return $this->db->years[(int)$this->params[':year']] ?? false;
        }
        return false;
    }

    public function queryAll(): array
    {
        return [];
    }

    public function update(string $table, array $values, array $condition)
    {
        $this->update = [$table, $values, $condition];
        return $this;
    }

    public function execute(): int
    {
        list($table, $values, $condition) = $this->update;
        if ($table === 'acrl_survey_years' && isset($this->db->years[$condition['survey_year']])) {
            $this->db->years[$condition['survey_year']] = array_merge(
                $this->db->years[$condition['survey_year']],
                $values
            );
        }
        return 1;
    }
}

class AcrlPublishAssignmentCopyTestMatcher
{
    public function compare(array $previous, array $current): array
    {
        return [];
    }
}

class AcrlPublishAssignmentCopyTestPolicy
{
    public function evaluate(array $items): array
    {
        return ['unresolved_count' => 0, 'can_publish' => true];
    }
}

class AcrlPublishAssignmentCopyTestAssigner
{
    public $calls = [];
    public $result;
    public $failure = null;

    public function copyOnPublish(int $year, string $userId): array
    {
        $this->calls[] = [$year, $userId];
        if ($this->failure) {
            throw $this->failure;
        }
        return $this->result;
    }
}

function acrlPublishAssignmentCopyAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acrlPublishAssignmentCopyService($assigner, $db = null)
{
    return new \app\services\AcrlCatalogueReviewService(
        $db ?: new AcrlPublishAssignmentCopyTestDb(),
        new AcrlPublishAssignmentCopyTestMatcher(),
        new AcrlPublishAssignmentCopyTestPolicy(),
        $assigner
    );
}

$emptyTargetCopy = [
    'surveyYear' => 2026,
    'sourceYear' => 2025,
    'copiedSections' => ['Circulation'],
    'skippedSections' => [],
    'unmatchedSections' => ['Old services'],
    'alreadyAssigned' => false,
    'failed' => false,
];
$emptyTargetAssigner = new AcrlPublishAssignmentCopyTestAssigner();
$emptyTargetAssigner->result = $emptyTargetCopy;
$emptyTargetDb = new AcrlPublishAssignmentCopyTestDb();
$emptyTargetReview = acrlPublishAssignmentCopyService($emptyTargetAssigner, $emptyTargetDb)->publishYear(2026, '9');
acrlPublishAssignmentCopyAssert($emptyTargetAssigner->calls === [[2026, '9']], 'Publishing a first-time year attempts assignment copy-forward once.');
acrlPublishAssignmentCopyAssert($emptyTargetDb->years[2026]['status'] === 'published', 'The catalogue is published before assignment-copy results are returned.');
acrlPublishAssignmentCopyAssert($emptyTargetReview['assignmentCopy'] === $emptyTargetCopy, 'The publish response includes copied and unmatched sections.');

$existingTargetAssigner = new AcrlPublishAssignmentCopyTestAssigner();
$existingTargetAssigner->result = [
    'surveyYear' => 2026,
    'sourceYear' => 2025,
    'copiedSections' => [],
    'skippedSections' => ['Circulation'],
    'unmatchedSections' => [],
    'alreadyAssigned' => true,
    'failed' => false,
];
$existingTargetReview = acrlPublishAssignmentCopyService($existingTargetAssigner)->publishYear(2026, '9');
acrlPublishAssignmentCopyAssert($existingTargetReview['assignmentCopy']['copiedSections'] === [], 'Publishing a year with assignments must report no copied sections.');
acrlPublishAssignmentCopyAssert($existingTargetReview['assignmentCopy']['skippedSections'] === ['Circulation'], 'Publishing an assigned year reports the existing sections.');

$failureAssigner = new AcrlPublishAssignmentCopyTestAssigner();
$failureAssigner->failure = new RuntimeException('copy database unavailable');
$failureDb = new AcrlPublishAssignmentCopyTestDb();
$failureReview = acrlPublishAssignmentCopyService($failureAssigner, $failureDb)->publishYear(2026, '9');
acrlPublishAssignmentCopyAssert($failureDb->years[2026]['status'] === 'published', 'An assignment-copy exception must not roll back publication.');
acrlPublishAssignmentCopyAssert($failureReview['assignmentCopy']['failed'] === true, 'A nonfatal copy failure is included in the publish response.');
acrlPublishAssignmentCopyAssert(count(Yii::$errors) === 1, 'A copy failure is written to the application log.');

echo "PASS: ACRL publication copies assignments, preserves existing assignments, and logs copy failures\n";
