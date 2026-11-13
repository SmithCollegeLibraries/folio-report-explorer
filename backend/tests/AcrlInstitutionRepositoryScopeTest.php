<?php

require_once __DIR__ . '/../services/AcrlAnswerRepository.php';

use app\services\AcrlAnswerRepository;

class AcrlInstitutionScopeFakeCommand
{
    private $sql;
    private $params;
    private $rows;

    public function __construct(string $sql, array $params, array $rows)
    {
        $this->sql = $sql;
        $this->params = $params;
        $this->rows = $rows;
    }

    public function queryAll(): array
    {
        $rows = $this->rows;
        if (strpos($this->sql, 'sheet = :sheet') !== false) {
            $rows = array_values(array_filter($rows, function ($row) {
                return $row['sheet'] === $this->params[':sheet'];
            }));
        }
        if (strpos($this->sql, 'institution_code = :institution') !== false) {
            $rows = array_values(array_filter($rows, function ($row) {
                return $row['institution_code'] === $this->params[':institution'];
            }));
        }
        return $rows;
    }
}

class AcrlInstitutionScopeFakeDb
{
    public $rows = [
        ['institution_code' => 'SC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'Q1', 'part' => '', 'value_text' => 'Smith'],
        ['institution_code' => 'HC', 'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'Q1', 'part' => '', 'value_text' => 'Hampshire'],
    ];

    public function createCommand($sql, array $params = []): AcrlInstitutionScopeFakeCommand
    {
        return new AcrlInstitutionScopeFakeCommand((string)$sql, $params, $this->rows);
    }
}

function acrlInstitutionRepositoryScopeAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$rows = (new AcrlAnswerRepository(new AcrlInstitutionScopeFakeDb()))->answers(2026, 'survey', 'HC');
acrlInstitutionRepositoryScopeAssert(count($rows) === 1 && $rows[0]['value_text'] === 'Hampshire', 'An institution-scoped answer read excludes another college with the same question tuple.');
