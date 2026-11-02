<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../services/QueryJobHistoryMigrationRecognitionService.php';
require __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;
use app\services\QueryJobHistoryMigrationRecognitionService;

$migrationPath = __DIR__ . '/../../mysql/migrations/063_query_job_history_group.sql';
$failures = [];

function historyMigrationAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function historyMigrationCase($name, $callback)
{
    global $failures;
    try {
        $callback();
        fwrite(STDOUT, 'PASS: ' . $name . "\n");
    } catch (Throwable $error) {
        $failures[] = $name;
        fwrite(STDERR, 'FAIL: ' . $name . ': ' . $error->getMessage() . "\n");
    }
}

function historyMigrationSource($path)
{
    if (!is_file($path)) {
        throw new RuntimeException('Migration 063 is not present yet.');
    }
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Migration 063 is empty.');
    }
    return $source;
}

final class HistoryMigrationTestDatabase
{
    public $columns;
    public $indexes;
    public $queries = [];

    public function __construct(array $columns, array $indexes)
    {
        $this->columns = $columns;
        $this->indexes = $indexes;
    }

    public function createCommand($sql = '', array $params = [])
    {
        return new HistoryMigrationTestCommand($this, (string)$sql, $params);
    }

    public function evaluateMetadataQuery($sql, array $params)
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        $normalized = strtoupper($sql);
        if (strpos($normalized, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            $rows = $this->columns;
        } elseif (strpos($normalized, 'INFORMATION_SCHEMA.STATISTICS') !== false) {
            $rows = $this->indexes;
        } else {
            throw new RuntimeException('Recognition query did not inspect MySQL metadata.');
        }

        preg_match_all('/([A-Z_]+)\s*=\s*(:[A-Z0-9_]+)/i', $sql, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $field = strtolower($match[1]);
            $placeholder = $match[2];
            if (!array_key_exists($placeholder, $params) || !in_array($field, [
                'table_schema', 'table_name', 'column_name', 'column_type', 'data_type',
                'is_nullable', 'index_name', 'non_unique', 'seq_in_index', 'sub_part',
            ], true)) {
                continue;
            }
            $expected = $params[$placeholder];
            $rows = array_values(array_filter($rows, function ($row) use ($field, $expected) {
                return array_key_exists($field, $row) && (string)$row[$field] === (string)$expected;
            }));
        }

        if (preg_match('/\b([A-Z_]+)\s+IS\s+NULL\b/i', $sql, $nullMatch) === 1) {
            $field = strtolower($nullMatch[1]);
            $rows = array_values(array_filter($rows, function ($row) use ($field) {
                return array_key_exists($field, $row) && $row[$field] === null;
            }));
        }
        return $rows;
    }
}

final class HistoryMigrationTestCommand
{
    private $db;
    private $sql;
    private $params;

    public function __construct(HistoryMigrationTestDatabase $db, string $sql, array $params)
    {
        $this->db = $db;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryAll()
    {
        return $this->db->evaluateMetadataQuery($this->sql, $this->params);
    }

    public function queryOne()
    {
        $rows = $this->queryAll();
        return $rows[0] ?? false;
    }

    public function queryScalar()
    {
        return count($this->queryAll());
    }
}

function historyMigrationColumnFixture(array $overrides = [])
{
    return [array_merge([
        'table_schema' => 'synthetic',
        'table_name' => 'query_jobs',
        'column_name' => 'history_group_key',
        'column_type' => 'char(40)',
        'data_type' => 'char',
        'is_nullable' => 'YES',
    ], $overrides)];
}

function historyMigrationIndexFixture(array $names = ['user_id', 'history_group_key', 'created_at'], $nonUnique = 1, $prefixAt = null)
{
    $rows = [];
    foreach ($names as $index => $columnName) {
        $rows[] = [
            'table_schema' => 'synthetic',
            'table_name' => 'query_jobs',
            'index_name' => 'idx_query_jobs_history_group',
            'non_unique' => $nonUnique,
            'seq_in_index' => $index + 1,
            'column_name' => $columnName,
            'sub_part' => $prefixAt === ($index + 1) ? 12 : null,
        ];
    }
    return $rows;
}

function historyMigrationRecognition(array $columns, array $indexes)
{
    $db = new HistoryMigrationTestDatabase($columns, $indexes);
    $result = QueryJobHistoryMigrationRecognitionService::historyGroupKeyAndIndexAppearComplete($db);
    historyMigrationAssert($db->queries !== [], 'Recognition must evaluate metadata queries against the fake schema.');
    return $result;
}

historyMigrationCase('migration 063 declares nullable CHAR(40) and the full nonunique history group index', function () use ($migrationPath) {
    $source = historyMigrationSource($migrationPath);
    historyMigrationAssert(
        preg_match('/ADD\s+COLUMN\s+`?history_group_key`?\s+CHAR\s*\(\s*40\s*\)\s+NULL/i', $source) === 1,
        'The migration must add history_group_key as nullable CHAR(40).'
    );
    historyMigrationAssert(
        preg_match('/CREATE\s+(?:NON)?UNIQUE?\s*INDEX|CREATE\s+INDEX/i', $source) === 1,
        'The migration must create a nonunique index.'
    );
    historyMigrationAssert(
        preg_match('/idx_query_jobs_history_group[\s\S]*?user_id[\s\S]*?history_group_key[\s\S]*?created_at/i', $source) === 1,
        'The index must contain user_id, history_group_key, and created_at in that order.'
    );
});

historyMigrationCase('migration 063 has separate idempotent column and index guards for partial application', function () use ($migrationPath) {
    $source = historyMigrationSource($migrationPath);
    historyMigrationAssert(stripos($source, 'information_schema.COLUMNS') !== false, 'The column guard must inspect information_schema.COLUMNS.');
    historyMigrationAssert(stripos($source, 'information_schema.STATISTICS') !== false, 'The index guard must inspect information_schema.STATISTICS.');
    historyMigrationAssert(substr_count(strtoupper($source), 'PREPARE ') >= 2, 'The column and index ALTER statements must have separate prepared guards.');
    historyMigrationAssert(substr_count(strtoupper($source), 'EXECUTE ') >= 2, 'Each guarded change must execute independently when absent.');
});

historyMigrationCase('migration 063 fails loudly when the named index exists with the wrong shape', function () use ($migrationPath) {
    $source = historyMigrationSource($migrationPath);
    historyMigrationAssert(
        preg_match('/SELECT\s+\*\s+FROM\s+`?[A-Za-z0-9_]*conflict[A-Za-z0-9_]*`?/i', $source) === 1,
        'A wrong-shape index must select from an intentionally missing conflict table to stop migration.'
    );
});

historyMigrationCase('recognition accepts the exact nullable column and full ordered nonunique index', function () {
    historyMigrationAssert(
        historyMigrationRecognition(historyMigrationColumnFixture(), historyMigrationIndexFixture()) === true,
        'The exact column and index shape should be recognized.'
    );
});

historyMigrationCase('recognition rejects missing and wrong nullable CHAR(40) column shapes', function () {
    $columns = [
        [],
        historyMigrationColumnFixture(['column_type' => 'varchar(40)', 'data_type' => 'varchar']),
        historyMigrationColumnFixture(['column_type' => 'char(41)']),
        historyMigrationColumnFixture(['is_nullable' => 'NO']),
    ];
    foreach ($columns as $fixture) {
        historyMigrationAssert(
            historyMigrationRecognition($fixture, historyMigrationIndexFixture()) === false,
            'Missing, wrong-type, wrong-length, or nonnullable column must not count as applied.'
        );
    }
});

historyMigrationCase('recognition rejects missing, unique, prefix, and wrong-order group indexes', function () {
    $indexes = [
        [],
        historyMigrationIndexFixture(['user_id', 'history_group_key', 'created_at'], 0),
        historyMigrationIndexFixture(['user_id', 'history_group_key', 'created_at'], 1, 2),
        historyMigrationIndexFixture(['history_group_key', 'user_id', 'created_at']),
    ];
    foreach ($indexes as $fixture) {
        historyMigrationAssert(
            historyMigrationRecognition(historyMigrationColumnFixture(), $fixture) === false,
            'Missing, unique, prefix, and wrong-order indexes must not count as applied.'
        );
    }
});

historyMigrationCase('MigrationService delegates 063 recognition and requires it before empty-ledger baselining', function () {
    $migrationServiceSource = file_get_contents(__DIR__ . '/../services/MigrationService.php');
    historyMigrationAssert(
        strpos($migrationServiceSource, "case '063_query_job_history_group.sql'") !== false
            && strpos($migrationServiceSource, 'QueryJobHistoryMigrationRecognitionService::historyGroupKeyAndIndexAppearComplete($db)') !== false,
        'migrationAppearsApplied must delegate 063 to the recognition service.'
    );
    if (preg_match('/private static function databaseAppearsCurrent\(\$db\): bool(.*?)\n    }\n\n    private static function migrationAppearsApplied/s', $migrationServiceSource, $match) !== 1) {
        throw new RuntimeException('Could not read databaseAppearsCurrent.');
    }
    historyMigrationAssert(
        strpos($match[1], 'QueryJobHistoryMigrationRecognitionService::historyGroupKeyAndIndexAppearComplete($db)') !== false,
        'An empty ledger is current only when 063 history grouping schema is recognized.'
    );
});

historyMigrationCase('MigrationService full runner applies and records 063 from an empty ledger', function () use ($migrationPath) {
    $source = historyMigrationSource($migrationPath);
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'history-migration-empty-ledger-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    $copyPath = $directory . DIRECTORY_SEPARATOR . basename($migrationPath);
    file_put_contents($copyPath, $source);
    $db = new HistoryMigrationRunnerTestDatabase();
    try {
        $result = MigrationService::run($db, $directory);
        historyMigrationAssert($result['applied'] === [basename($migrationPath)], 'The full runner should apply the one pending migration.');
        historyMigrationAssert($db->ledger[0]['filename'] === basename($migrationPath), 'The runner records the migration in the empty ledger.');
        historyMigrationAssert($db->rawStatements !== [], 'The runner executes the statements from the actual 063 file.');
    } finally {
        @unlink($copyPath);
        @rmdir($directory);
    }
});

final class HistoryMigrationRunnerSchema
{
    public function getTableSchema($table, $refresh = false)
    {
        return null;
    }
}

final class HistoryMigrationRunnerTestDatabase
{
    public $schema;
    public $ledger = [];
    public $rawStatements = [];

    public function __construct()
    {
        $this->schema = new HistoryMigrationRunnerSchema();
    }

    public function createCommand($sql = '', array $params = [])
    {
        return new HistoryMigrationRunnerTestCommand($this, (string)$sql);
    }
}

final class HistoryMigrationRunnerTestCommand
{
    private $db;
    private $sql;

    public function __construct(HistoryMigrationRunnerTestDatabase $db, string $sql)
    {
        $this->db = $db;
        $this->sql = $sql;
    }

    public function setRawSql($sql)
    {
        $this->sql = (string)$sql;
        return $this;
    }

    public function queryAll()
    {
        if (stripos($this->sql, 'information_schema.') !== false) {
            return [];
        }
        return $this->db->ledger;
    }

    public function queryScalar()
    {
        if (stripos($this->sql, 'information_schema.') !== false) {
            return 0;
        }
        return 0;
    }

    public function execute()
    {
        if (stripos($this->sql, 'CREATE TABLE') === 0) {
            return;
        }
        $this->db->rawStatements[] = $this->sql;
    }

    public function insert($table, array $values)
    {
        if ($table === 'schema_migrations') {
            $this->db->ledger[] = $values;
        }
        return $this;
    }
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " migration 063 case(s) failed. MySQL/MariaDB execution remains an orchestrator gate.\n");
    exit(1);
}

fwrite(STDOUT, "Migration 063 recognition and runner cases passed. MySQL/MariaDB execution remains an orchestrator gate.\n");
