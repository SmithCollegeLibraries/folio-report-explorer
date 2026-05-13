<?php

$servicePath = __DIR__ . '/../services/SqlPreflightService.php';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "SqlPreflightService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

$serviceClass = 'app\\services\\SqlPreflightService';

if (!class_exists($serviceClass)) {
    fwrite(STDERR, "SqlPreflightService class was not loaded from {$servicePath}\n");
    exit(1);
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertNullValue($actual, string $message): void
{
    if ($actual !== null) {
        fwrite(STDERR, $message . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

final class FakeSqlPreflightCommand
{
    private $sql;
    private $db;

    public function __construct(string $sql, FakeSqlPreflightDb $db)
    {
        $this->sql = $sql;
        $this->db = $db;
    }

    public function execute(): void
    {
        $this->db->executedCommands[] = $this->sql;
    }

    public function queryOne()
    {
        $this->db->queriedCommands[] = $this->sql;
        if ($this->db->queryException instanceof Throwable) {
            throw $this->db->queryException;
        }
        return $this->db->queryResult;
    }
}

final class FakeSqlPreflightDb
{
    public $executedCommands = [];
    public $queriedCommands = [];
    public $queryResult;
    public $queryException;

    public function __construct($queryResult = null, ?Throwable $queryException = null)
    {
        $this->queryResult = $queryResult;
        $this->queryException = $queryException;
    }

    public function createCommand(string $sql): FakeSqlPreflightCommand
    {
        return new FakeSqlPreflightCommand($sql, $this);
    }
}

$successDb = new FakeSqlPreflightDb([
    'QUERY PLAN' => json_encode([
        [
            'Plan' => [
                'Plan Rows' => 42,
                'Total Cost' => 15.5,
                'Plans' => [
                    ['Total Cost' => 99.25],
                ],
            ],
        ],
    ]),
]);

$successEstimate = $serviceClass::estimateQueryComplexity(
    $successDb,
    'SELECT 1',
    1800000
);

assertSameValue(42, $successEstimate['rows'] ?? null, 'Preflight should capture the top-level planned row estimate.');
assertSameValue(99.25, $successEstimate['cost'] ?? null, 'Preflight should capture the maximum cost across the full plan tree.');
assertSameValue(
    ['SET statement_timeout = 10000', 'SET statement_timeout = 1800000'],
    $successDb->executedCommands,
    'Preflight should lower the statement timeout for EXPLAIN and restore the configured query timeout afterwards.'
);
assertSameValue(
    ['EXPLAIN (FORMAT JSON) SELECT 1'],
    $successDb->queriedCommands,
    'Preflight should run EXPLAIN FORMAT JSON against the generated SQL.'
);

$errorDb = new FakeSqlPreflightDb(
    null,
    new RuntimeException("SQLSTATE[42883]: Undefined function: 7 ERROR:  function pg_catalog.extract(unknown, integer) does not exist\nDETAIL:  test detail")
);

$errorEstimate = $serviceClass::estimateQueryComplexity(
    $errorDb,
    'SELECT broken_sql',
    1800000
);

assertSameValue(
    'function pg_catalog.extract(unknown, integer) does not exist',
    $errorEstimate['error'] ?? null,
    'Preflight should surface the useful PostgreSQL error text when EXPLAIN rejects invalid SQL.'
);
assertSameValue(
    ['SET statement_timeout = 10000', 'SET statement_timeout = 1800000'],
    $errorDb->executedCommands,
    'Preflight should restore the configured timeout even when EXPLAIN fails.'
);

$timeoutDb = new FakeSqlPreflightDb(
    null,
    new RuntimeException('ERROR: canceling statement due to statement timeout')
);

$timeoutEstimate = $serviceClass::estimateQueryComplexity(
    $timeoutDb,
    'SELECT maybe_expensive_sql',
    1800000
);

assertNullValue(
    $timeoutEstimate,
    'Preflight should treat EXPLAIN timeouts as unavailable estimates rather than invalid SQL.'
);

fwrite(STDOUT, "SqlPreflightService test passed\n");