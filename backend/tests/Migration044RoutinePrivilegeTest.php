<?php

require_once __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;

function migration044Assert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

final class Migration044Schema
{
    private $database;

    public function __construct(Migration044Database $database)
    {
        $this->database = $database;
    }

    public function getTableSchema(string $table, bool $refresh = false)
    {
        if (!array_key_exists($table, $this->database->columns)) {
            return null;
        }

        $columns = [];
        foreach ($this->database->columns[$table] as $column) {
            $columns[$column] = new stdClass();
        }
        return (object)['columns' => $columns];
    }
}

final class Migration044Database
{
    public $schema;
    public $columns = [
        'schema_migrations' => ['filename', 'checksum'],
        'ai_query_feedback' => ['generation_id'],
        'ai_report_generations' => ['saved_count'],
    ];
    public $indexes = ['ai_query_feedback' => ['idx_feedback_generation']];
    public $constraints = ['ai_query_feedback' => ['fk_query_feedback_generation']];
    public $ledger = [];
    public $executed = [];

    public function __construct()
    {
        $this->schema = new Migration044Schema($this);
    }

    public function createCommand(string $sql = '', array $params = []): Migration044Command
    {
        return new Migration044Command($this, $sql, $params);
    }
}

final class Migration044Command
{
    private $database;
    private $sql;
    private $params;
    private $insertTable;
    private $insertRow;

    public function __construct(Migration044Database $database, string $sql, array $params)
    {
        $this->database = $database;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function setRawSql(string $sql): self
    {
        $this->sql = $sql;
        $this->params = [];
        return $this;
    }

    public function insert(string $table, array $row): self
    {
        $this->insertTable = $table;
        $this->insertRow = $row;
        return $this;
    }

    public function queryAll(): array
    {
        return $this->database->ledger;
    }

    public function queryScalar(): int
    {
        $table = (string)($this->params[':table'] ?? '');
        $object = (string)($this->params[':object'] ?? '');
        if (stripos($this->sql, 'information_schema.COLUMNS') !== false) {
            return in_array($object, $this->database->columns[$table] ?? [], true) ? 1 : 0;
        }
        if (stripos($this->sql, 'information_schema.STATISTICS') !== false) {
            return in_array($object, $this->database->indexes[$table] ?? [], true) ? 1 : 0;
        }
        if (stripos($this->sql, 'information_schema.TABLE_CONSTRAINTS') !== false) {
            return in_array($object, $this->database->constraints[$table] ?? [], true) ? 1 : 0;
        }
        return 0;
    }

    public function execute(): void
    {
        if ($this->insertTable === 'schema_migrations') {
            $this->database->ledger[] = $this->insertRow;
            return;
        }

        if (preg_match('/\b(?:CREATE|DROP)\s+PROCEDURE\b|\bCALL\s+fre_/i', $this->sql) === 1) {
            throw new RuntimeException('alter routine command denied');
        }
        if (preg_match('/^CREATE TABLE IF NOT EXISTS schema_migrations/i', $this->sql) === 1) {
            return;
        }

        $this->database->executed[] = $this->sql;
        if (preg_match('/^ALTER TABLE\s+(\w+)\s+ADD COLUMN\s+(\w+)/i', $this->sql, $matches) === 1) {
            $this->database->columns[$matches[1]][] = $matches[2];
        } elseif (preg_match('/^ALTER TABLE\s+(\w+)\s+ADD INDEX\s+(\w+)/i', $this->sql, $matches) === 1) {
            $this->database->indexes[$matches[1]][] = $matches[2];
        } elseif (preg_match('/^ALTER TABLE\s+(\w+)\s+ADD CONSTRAINT\s+(\w+)/i', $this->sql, $matches) === 1) {
            $this->database->constraints[$matches[1]][] = $matches[2];
        }
    }
}

$tempDirectory = sys_get_temp_dir() . '/migration-044-routine-privilege-' . uniqid('', true);
mkdir($tempDirectory, 0775, true);
$migrationPath = $tempDirectory . '/044_query_feedback_reuse_trust.sql';
copy(__DIR__ . '/../../mysql/migrations/044_query_feedback_reuse_trust.sql', $migrationPath);

$database = new Migration044Database();
$result = MigrationService::run($database, $tempDirectory);

migration044Assert(
    $result['applied'] === ['044_query_feedback_reuse_trust.sql'],
    'Migration 044 should apply through the ordinary migration runner without routine privileges.'
);
migration044Assert(
    count($database->columns['ai_query_feedback']) === 10
        && count($database->columns['ai_report_generations']) === 4,
    'Migration 044 should add every missing query-memory column after a partial application.'
);
migration044Assert(
    count($database->indexes['ai_query_feedback']) === 7,
    'Migration 044 should add every missing query-memory index without duplicating an existing index.'
);
migration044Assert(
    count($database->constraints['ai_query_feedback']) === 4,
    'Migration 044 should add every missing query-memory foreign key without duplicating an existing constraint.'
);
migration044Assert(
    count($database->ledger) === 1,
    'Migration 044 should be recorded only after all guarded schema changes succeed.'
);

@unlink($migrationPath);
@rmdir($tempDirectory);

fwrite(STDOUT, "Migration 044 routine privilege test passed\n");
