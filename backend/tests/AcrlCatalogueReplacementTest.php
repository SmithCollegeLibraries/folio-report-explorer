<?php

require_once __DIR__ . '/../services/AcrlCatalogueService.php';

class AcrlCatalogueReplacementTransaction
{
    public $isActive = true;
    public $committed = false;
    public $rolledBack = false;

    public function commit(): void
    {
        $this->isActive = false;
        $this->committed = true;
    }

    public function rollBack(): void
    {
        $this->isActive = false;
        $this->rolledBack = true;
    }
}

class AcrlCatalogueReplacementDb
{
    public $driverName = 'mysql';
    public $year = ['survey_year' => 2026, 'status' => 'published'];
    public $questions = [];
    public $answers = [];
    public $notes = [];
    public $concurrentAnswer = null;
    public $transaction;
    public $events = [];
    public $writes = [];

    public function __construct()
    {
        $this->questions = [
            ['id' => 1, 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'Q1', 'part' => '', 'section' => 'Services', 'prompt' => 'Keep', 'answer_type' => 'text'],
            ['id' => 2, 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'Q1', 'part' => 'A', 'section' => 'Services', 'prompt' => 'Remove', 'answer_type' => 'text'],
        ];
    }

    public function beginTransaction(): AcrlCatalogueReplacementTransaction
    {
        $this->events[] = 'begin';
        return $this->transaction = new AcrlCatalogueReplacementTransaction();
    }

    public function createCommand($sql = null, array $params = []): AcrlCatalogueReplacementCommand
    {
        return new AcrlCatalogueReplacementCommand($this, $sql, $params);
    }
}

class AcrlCatalogueReplacementCommand
{
    private $db;
    private $sql;
    private $params;
    private $write;

    public function __construct(AcrlCatalogueReplacementDb $db, $sql, array $params)
    {
        $this->db = $db;
        $this->sql = (string)$sql;
        $this->params = $params;
    }

    public function queryOne()
    {
        $this->db->events[] = $this->sql;
        if (strpos($this->sql, 'FROM acrl_survey_years') !== false) {
            return $this->db->year;
        }
        return false;
    }

    public function queryAll(): array
    {
        $this->db->events[] = $this->sql;
        if (strpos($this->sql, 'FROM acrl_answers') !== false || strpos($this->sql, 'FROM acrl_question_notes') !== false) {
            if ($this->db->concurrentAnswer !== null && strpos($this->sql, 'FROM acrl_answers') !== false
                && strpos(implode(' ', $this->db->events), 'FOR UPDATE') !== false) {
                $this->db->answers[] = $this->db->concurrentAnswer;
                $this->db->concurrentAnswer = null;
            }
            $rows = strpos($this->sql, 'FROM acrl_answers') !== false ? $this->db->answers : $this->db->notes;
            return array_values(array_filter($rows, function ($row) {
                return (int)$row['survey_year'] === (int)$this->params[':year']
                    && $row['sheet'] === $this->params[':sheet']
                    && $row['question_id'] === $this->params[':question']
                    && $row['part'] === $this->params[':part'];
            }));
        }
        return [];
    }

    public function insert(string $table, array $values)
    {
        $this->write = ['insert', $table, $values];
        return $this;
    }

    public function update(string $table, array $values, array $condition)
    {
        $this->write = ['update', $table, $values, $condition];
        return $this;
    }

    public function upsert(string $table, array $values, array $update)
    {
        $this->write = ['upsert', $table, $values, $update];
        return $this;
    }

    public function delete(string $table, array $condition)
    {
        $this->write = ['delete', $table, $condition];
        return $this;
    }

    public function execute(): int
    {
        $this->db->writes[] = $this->write;
        return 1;
    }
}

class AcrlCatalogueReplacementValidator
{
    public function validateUpload($year, $fileName, $sha256, $items): array
    {
        return $items;
    }
}

class AcrlCatalogueReplacementReview
{
    private $db;

    public function __construct(AcrlCatalogueReplacementDb $db)
    {
        $this->db = $db;
    }

    public function yearRow(int $year): ?array
    {
        return $this->db->year;
    }

    public function questionRows(int $year): array
    {
        return $this->db->questions;
    }

    public function questionKey(array $question): string
    {
        return implode('|', [$question['sheet'], $question['question_id'], $question['part']]);
    }

    public function yearResponse(array $year): array
    {
        return $year;
    }

    public function getReview(int $year): array
    {
        return ['surveyYear' => $year, 'status' => $this->db->year['status']];
    }
}

function acrlCatalogueReplacementAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function acrlCatalogueReplacementRun(AcrlCatalogueReplacementDb $db): ?\DomainException
{
    $service = new \app\services\AcrlCatalogueService(
        $db,
        null,
        new AcrlCatalogueReplacementValidator(),
        null,
        new AcrlCatalogueReplacementReview($db)
    );
    try {
        $service->importWorksheet(2026, 'replacement.xlsx', str_repeat('a', 64), [[
            'sheet' => 'survey', 'question_id' => 'Q1', 'part' => '', 'section' => 'Services',
            'prompt' => 'Keep', 'answer_type' => 'text', 'answer_options' => null,
            'required' => 'no', 'calculated_from' => null, 'survey_order' => 1,
        ]], true, 'synthetic-admin');
    } catch (\DomainException $exception) {
        return $exception;
    }
    return null;
}

$notesOnlyDb = new AcrlCatalogueReplacementDb();
$notesOnlyDb->notes[] = [
    'institution_code' => 'SC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'Q1', 'part' => 'A',
];
$notesOnly = acrlCatalogueReplacementRun($notesOnlyDb);
acrlCatalogueReplacementAssert($notesOnly !== null && $notesOnly->getCode() === 409, 'A notes-only dependency blocks catalogue replacement with 409.');
acrlCatalogueReplacementAssert(strpos($notesOnly->getMessage(), 'SC note survey/Q1/A') !== false, 'The refusal identifies the college and removed question part.');
acrlCatalogueReplacementAssert($notesOnlyDb->transaction->rolledBack && $notesOnlyDb->writes === [], 'A notes-only refusal rolls back before changing catalogue rows or status.');
acrlCatalogueReplacementAssert(strpos($notesOnlyDb->events[1] ?? '', 'FOR UPDATE') !== false, 'Replacement locks the survey year before checking notes.');

$anotherCollegeDb = new AcrlCatalogueReplacementDb();
$anotherCollegeDb->answers[] = [
    'institution_code' => 'HC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'Q1', 'part' => 'A',
];
$anotherCollege = acrlCatalogueReplacementRun($anotherCollegeDb);
acrlCatalogueReplacementAssert($anotherCollege !== null && strpos($anotherCollege->getMessage(), 'HC answer survey/Q1/A') !== false, 'An answer stored only for another college blocks replacement and identifies that college.');
acrlCatalogueReplacementAssert($anotherCollegeDb->writes === [], 'An another-college-only answer cannot be deleted by replacement.');

$concurrentDb = new AcrlCatalogueReplacementDb();
$concurrentDb->concurrentAnswer = [
    'institution_code' => 'HC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'Q1', 'part' => 'A',
];
$concurrent = acrlCatalogueReplacementRun($concurrentDb);
acrlCatalogueReplacementAssert($concurrent !== null && strpos($concurrent->getMessage(), 'HC answer survey/Q1/A') !== false, 'An answer committed before the replacement lock is included in the dependency check.');
acrlCatalogueReplacementAssert(strpos($concurrentDb->events[1] ?? '', 'FOR UPDATE') !== false && $concurrentDb->writes === [], 'The concurrent answer is checked only after the year lock, and remains untouched on refusal.');

echo "PASS: catalogue replacement locks first and rejects answer or note dependencies across colleges\n";
