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
    private $params;
    private $db;

    public function __construct(string $sql, array $params, FakeSqlPreflightDb $db)
    {
        $this->sql = $sql;
        $this->params = $params;
        $this->db = $db;
    }

    public function execute(): void
    {
        $this->db->executedCommands[] = $this->sql;
    }

    public function queryOne()
    {
        $this->db->queriedCommands[] = $this->sql;
        $this->db->queriedParams[] = $this->params;
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
    public $queriedParams = [];
    public $queryResult;
    public $queryException;

    public function __construct($queryResult = null, ?Throwable $queryException = null)
    {
        $this->queryResult = $queryResult;
        $this->queryException = $queryException;
    }

    public function createCommand(string $sql, array $params = []): FakeSqlPreflightCommand
    {
        return new FakeSqlPreflightCommand($sql, $params, $this);
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
    'SELECT :campus_id',
    1800000,
    2500,
    [':campus_id' => 'ku']
);

assertSameValue(42, $successEstimate['rows'] ?? null, 'Preflight should capture the top-level planned row estimate.');
assertSameValue(99.25, $successEstimate['cost'] ?? null, 'Preflight should capture the maximum cost across the full plan tree.');
assertSameValue(
    ['SET statement_timeout = 2500', 'SET statement_timeout = 1800000'],
    $successDb->executedCommands,
    'Preflight should lower the statement timeout for EXPLAIN and restore the configured query timeout afterwards.'
);
assertSameValue(
    ['EXPLAIN (FORMAT JSON) SELECT :campus_id'],
    $successDb->queriedCommands,
    'Preflight should run EXPLAIN FORMAT JSON against the generated SQL.'
);
assertSameValue(
    [[':campus_id' => 'ku']],
    $successDb->queriedParams,
    'Preflight should bind the query parameters when running EXPLAIN.'
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
assertSameValue('42883', $errorEstimate['sqlState'] ?? null, 'Preflight should preserve the PostgreSQL SQLSTATE alongside normalized error text.');
assertSameValue('42', $errorEstimate['sqlStateClass'] ?? null, 'Preflight should preserve the PostgreSQL SQLSTATE class alongside normalized error text.');
assertSameValue(
    ['SET statement_timeout = 10000', 'SET statement_timeout = 1800000'],
    $errorDb->executedCommands,
    'Preflight should restore the configured timeout even when EXPLAIN fails.'
);

foreach ([
    ['08P01', 'invalid frontend protocol message'],
    ['28000', 'role "report_reader" is not permitted to log in'],
    ['53100', 'could not write to temporary file: No space left on device'],
    ['53300', 'remaining connection slots are reserved for roles with the SUPERUSER attribute'],
    ['54011', 'target lists can have at most 1664 entries'],
    ['54023', 'cannot pass more than 100 arguments to a function'],
] as $sqlStateCase) {
    $sqlState = $sqlStateCase[0];
    $detail = $sqlStateCase[1];
    $sqlStateDb = new FakeSqlPreflightDb(
        null,
        new RuntimeException("SQLSTATE[{$sqlState}]: PostgreSQL failure: 7 ERROR:  {$detail}\nDETAIL: hidden driver detail")
    );

    $sqlStateEstimate = $serviceClass::estimateQueryComplexity(
        $sqlStateDb,
        'SELECT candidate_sql',
        1800000
    );

    assertSameValue($detail, $sqlStateEstimate['error'] ?? null, 'Preflight should retain normalized PostgreSQL detail for every structured hard-failure family.');
    assertSameValue($sqlState, $sqlStateEstimate['sqlState'] ?? null, 'Preflight should retain the exact PostgreSQL SQLSTATE for downstream hard-stop routing.');
    assertSameValue(substr($sqlState, 0, 2), $sqlStateEstimate['sqlStateClass'] ?? null, 'Preflight should retain the PostgreSQL SQLSTATE class for downstream hard-stop routing.');
}

$timeoutDb = new FakeSqlPreflightDb(
    null,
    new RuntimeException('ERROR: canceling statement due to statement timeout')
);

try {
    $serviceClass::estimateQueryComplexity(
        $timeoutDb,
        'SELECT maybe_expensive_sql',
        1800000
    );
    fwrite(STDERR, "Preflight statement cancellation must remain a typed hard stop.\n");
    exit(1);
} catch (\app\exceptions\DatabaseQueryCancelledException $exception) {
    assertSameValue(
        'Database query validation was cancelled.',
        $exception->getMessage(),
        'Preflight cancellation should expose only a stable typed message.'
    );
}

fwrite(STDOUT, "SqlPreflightService test passed\n");
