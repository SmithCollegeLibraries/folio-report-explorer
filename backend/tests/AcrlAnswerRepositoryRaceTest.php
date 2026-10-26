<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/AcrlAnswerRepository.php';

use app\services\AcrlAnswerRepository;

class AcrlRaceTestTransaction
{
    public $isActive = true;
    public $rolledBack = false;

    public function commit(): void
    {
        $this->isActive = false;
    }

    public function rollBack(): void
    {
        $this->rolledBack = true;
        $this->isActive = false;
    }
}

class AcrlRaceTestDb
{
    public $answer;
    public $transaction;
    public $historyWrites = 0;
    public $simulateDuplicate = true;

    public function beginTransaction(): AcrlRaceTestTransaction
    {
        $this->transaction = new AcrlRaceTestTransaction();
        return $this->transaction;
    }

    public function createCommand($sql = null, array $params = []): AcrlRaceTestCommand
    {
        return new AcrlRaceTestCommand($this, $sql);
    }
}

class AcrlRaceTestCommand
{
    private $db;
    private $table;
    private $columns;
    private $sql;

    public function __construct(AcrlRaceTestDb $db, $sql = null)
    {
        $this->db = $db;
        $this->sql = $sql;
    }

    public function queryOne()
    {
        if (strpos((string)$this->sql, 'FOR UPDATE') !== false) {
            return false;
        }
        return $this->db->answer;
    }

    public function insert(string $table, array $columns): self
    {
        $this->table = $table;
        $this->columns = $columns;
        return $this;
    }

    public function execute(): int
    {
        if ($this->table === 'acrl_answers') {
            if (!$this->db->simulateDuplicate) {
                $this->db->answer = $this->columns + ['updated_at' => '2026-10-26 00:00:00.000002'];
                return 1;
            }
            $this->db->answer = [
                'survey_year' => 2026,
                'sheet' => 'survey',
                'question_id' => '6C',
                'part' => '',
                'value_text' => 'first writer',
                'value_number' => '4000000.00',
                'note' => null,
                'source' => 'manual',
                'source_label' => null,
                'updated_by' => 'writer-1',
                'updated_at' => '2026-10-26 00:00:00.000001',
            ];
            throw new \yii\db\IntegrityException('Duplicate answer key.', ['23000', 1062, 'Duplicate entry'], '23000');
        }
        if ($this->table === 'acrl_answer_history') {
            $this->db->historyWrites += 1;
        }
        return 1;
    }
}

function acrlAnswerRaceAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = new AcrlRaceTestDb();
$repository = new AcrlAnswerRepository($db);
try {
    $result = $repository->saveAnswer(
        ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '6C', 'part' => ''],
        ['value_text' => 'second writer', 'value_number' => '5000000.00'],
        null,
        'writer-2',
        null
    );
} catch (\yii\db\IntegrityException $exception) {
    fwrite(STDERR, "FAIL: a duplicate-key first save must return a conflict instead of throwing\n");
    exit(1);
}

acrlAnswerRaceAssert($result['conflict'] === true, 'A duplicate-key first save must be reported as a conflict.');
acrlAnswerRaceAssert($result['current']['value_text'] === 'first writer', 'The conflict must include the current answer.');
acrlAnswerRaceAssert($db->transaction->rolledBack, 'The losing insert transaction must be rolled back before reading the current answer.');
acrlAnswerRaceAssert($db->historyWrites === 0, 'A losing insert must not append a history row.');

// A normal first insert must accept Yii's false result for an absent queryOne row as null history.
$normalDb = new AcrlRaceTestDb();
$normalDb->simulateDuplicate = false;
$normalRepository = new AcrlAnswerRepository($normalDb);
$normalResult = $normalRepository->saveAnswer(
    ['survey_year' => 2026, 'sheet' => 'survey', 'question_id' => '6C', 'part' => ''],
    ['value_text' => '100000', 'value_number' => '100000.00'],
    null,
    'writer-1',
    null
);
acrlAnswerRaceAssert($normalResult['conflict'] === false, 'A normal first save must not be reported as a conflict.');
acrlAnswerRaceAssert($normalResult['current']['value_text'] === '100000', 'A normal first save must return the inserted answer.');
acrlAnswerRaceAssert($normalDb->historyWrites === 1, 'A successful first save must append one history row.');

echo "PASS: normal first saves append history; concurrent first saves return conflict data without history\n";
