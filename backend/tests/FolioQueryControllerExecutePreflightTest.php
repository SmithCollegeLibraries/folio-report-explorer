<?php

namespace yii\web {
    class Controller
    {
        public function __construct($id = null, $module = null, $config = [])
        {
        }

        public function behaviors()
        {
            return [];
        }

        public function beforeAction($action)
        {
            return true;
        }
    }

    class Response
    {
        public const FORMAT_JSON = 'json';
    }
}

namespace app\models {
    class QueryLog
    {
        public $sql_text;
        public $params;
        public $source;
        public $data_source;
        public $user_id;
        public $row_count;
        public $execution_time_ms;
        public $error_message;

        public function hasAttribute($name)
        {
            return $name === 'data_source';
        }

        public function save($validate = true)
        {
            return true;
        }
    }

    class DummyIdentity
    {
        public function getId()
        {
            return 1;
        }
    }
}

namespace app\services {
    class GeminiService
    {
        public const NL2SQL_TELEMETRY_CATEGORY = 'nl2sql.telemetry';

        public static function normalizeGeneratedSql($sql)
        {
            return trim((string) $sql);
        }
    }

    class SqlBuilderService
    {
        public static $buildCalls = [];

        public static function build($queryDefinition)
        {
            self::$buildCalls[] = $queryDefinition;
            return [
                'sql' => 'SELECT * FROM inventory.items WHERE holdings_record_id = :holdings_id',
                'params' => [':holdings_id' => 'not-a-uuid'],
            ];
        }

        public static function normalizeForExecution($sql)
        {
            return trim((string) $sql);
        }

        public static function validateSafety($sql)
        {
        }

        public static function validateTablePolicy($sql)
        {
        }
    }

    class DatabaseRetryService
    {
        public static function runWithReconnectRetry($db, $callback, $context)
        {
            return $callback();
        }
    }

    class SqlPreflightService
    {
        public static $calls = [];
        public static $nextResult = null;

        public static function estimateQueryComplexity($db, string $sql, int $queryTimeoutMs, int $preflightTimeoutMs = 10000, array $params = [])
        {
            self::$calls[] = [
                'sql' => $sql,
                'queryTimeoutMs' => $queryTimeoutMs,
                'preflightTimeoutMs' => $preflightTimeoutMs,
                'params' => $params,
            ];
            return self::$nextResult;
        }
    }
}

namespace {
    if (!defined('YII_ENV')) {
        define('YII_ENV', 'test');
    }

    class Yii
    {
        public static $app;
        public static $warnings = [];

        public static function warning($message, $category = 'application')
        {
            self::$warnings[] = [
                'message' => $message,
                'category' => $category,
            ];
        }
    }

    final class FakeExecuteRequest
    {
        private $body;

        public function __construct(array $body)
        {
            $this->body = $body;
        }

        public function getBodyParams()
        {
            return $this->body;
        }
    }

    final class FakeExecuteResponse
    {
        public $statusCode = 200;
        public $format;
    }

    final class FakeExecuteTransaction
    {
        public $isActive = true;

        public function commit()
        {
            $this->isActive = false;
        }

        public function rollBack()
        {
            $this->isActive = false;
        }
    }

    final class FakeExecuteCommand
    {
        private $sql;
        private $db;

        public function __construct(string $sql, FakeExecuteDb $db)
        {
            $this->sql = $sql;
            $this->db = $db;
        }

        public function execute()
        {
            $this->db->executedSql[] = $this->sql;
            return 0;
        }

        public function bindValue($key, $value)
        {
            return $this;
        }

        public function queryAll()
        {
            $this->db->queriedSql[] = $this->sql;
            if ($this->db->queryException instanceof \Throwable) {
                throw $this->db->queryException;
            }
            return $this->db->queryAllResult;
        }
    }

    final class FakeExecuteDb
    {
        public $executedSql = [];
        public $queriedSql = [];
        public $queryAllResult = [];
        public $queryException;

        public function beginTransaction()
        {
            return new FakeExecuteTransaction();
        }

        public function createCommand(string $sql)
        {
            return new FakeExecuteCommand($sql, $this);
        }
    }

    function assertSameValue($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function assertCountValue(int $expected, array $actual, string $message): void
    {
        if (count($actual) !== $expected) {
            fwrite(STDERR, $message . "\nExpected count: {$expected}\nActual count: " . count($actual) . "\n");
            exit(1);
        }
    }

    function assertTrueValue(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
    }

    function decodeTelemetryRecord(string $message): array
    {
        $prefix = 'NL2SQL telemetry: ';
        if (strpos($message, $prefix) !== 0) {
            fwrite(STDERR, "Telemetry message did not start with the expected prefix.\nMessage: {$message}\n");
            exit(1);
        }

        $decoded = json_decode(substr($message, strlen($prefix)), true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "Telemetry payload was not valid JSON.\nMessage: {$message}\n");
            exit(1);
        }

        return $decoded;
    }

    $controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';
    if (!file_exists($controllerPath)) {
        fwrite(STDERR, "FolioQueryController is missing at {$controllerPath}\n");
        exit(1);
    }

    require_once $controllerPath;

    $folioDb = new FakeExecuteDb();
    \app\services\SqlPreflightService::$calls = [];
    \app\services\SqlPreflightService::$nextResult = ['error' => 'operator does not exist: jsonb !~~* unknown'];
    Yii::$warnings = [];

    Yii::$app = (object) [
        'request' => new FakeExecuteRequest([
            'sql' => 'SELECT * FROM folio_source_record.records__t WHERE campus_id = :campus_id',
            'params' => [':campus_id' => 'ku'],
            'source' => 'nl',
            'dataSource' => 'folio',
        ]),
        'response' => new FakeExecuteResponse(),
        'folioDb' => $folioDb,
        'user' => (object) ['isGuest' => true, 'id' => null],
        'params' => [
            'maxQueryRows' => 100,
            'queryTimeoutMs' => 1800000,
        ],
    ];

    $controller = new \app\controllers\FolioQueryController('folio-query', null);
    $generatedResult = $controller->actionExecute();

    assertSameValue(422, Yii::$app->response->statusCode, 'Generated execute requests should fail fast when PostgreSQL preflight rejects the SQL.');
    assertSameValue('operator does not exist: jsonb !~~* unknown', $generatedResult['error'] ?? null, 'Generated execute requests should surface the PostgreSQL preflight error directly.');
    assertCountValue(1, \app\services\SqlPreflightService::$calls, 'Generated execute requests should invoke PostgreSQL preflight exactly once.');
    assertSameValue([':campus_id' => 'ku'], \app\services\SqlPreflightService::$calls[0]['params'] ?? null, 'Generated execute requests should pass execution parameters to PostgreSQL preflight.');
    assertCountValue(0, $folioDb->queriedSql, 'Generated execute requests should not reach query execution when preflight fails.');
    assertCountValue(1, Yii::$warnings, 'Generated execute requests should emit one telemetry warning when PostgreSQL preflight fails.');

    $telemetryRecord = decodeTelemetryRecord((string) (Yii::$warnings[0]['message'] ?? ''));
    assertSameValue('nl2sql.telemetry', Yii::$warnings[0]['category'] ?? null, 'Generated execute requests should log preflight telemetry to the nl2sql.telemetry category.');
    assertSameValue('nl2sql.validation_failure', $telemetryRecord['event'] ?? null, 'Generated execute requests should log a validation_failure telemetry event.');
    assertSameValue('postgres_preflight', $telemetryRecord['stage'] ?? null, 'Generated execute requests should classify the telemetry stage as postgres_preflight.');
    assertSameValue('api.execute', $telemetryRecord['endpoint'] ?? null, 'Generated execute requests should identify the failing endpoint in telemetry.');
    assertSameValue('nl', $telemetryRecord['source'] ?? null, 'Generated execute requests should preserve the request source in telemetry.');
    assertSameValue('folio', $telemetryRecord['dataSource'] ?? null, 'Generated execute requests should preserve the data source in telemetry.');
    assertSameValue('operator_error', $telemetryRecord['errorFamily'] ?? null, 'Generated execute requests should classify operator errors for telemetry.');
    assertSameValue('operator does not exist: jsonb !~~* unknown', $telemetryRecord['error'] ?? null, 'Generated execute requests should include the PostgreSQL error text in telemetry.');
    assertTrueValue(!empty($telemetryRecord['sqlHash'] ?? null), 'Generated execute requests should include a stable SQL hash in telemetry.');

    \app\services\SqlPreflightService::$calls = [];
    \app\services\SqlPreflightService::$nextResult = ['error' => 'syntax error at end of input'];
    Yii::$warnings = [];

    Yii::$app = (object) [
        'request' => new FakeExecuteRequest([
            'sql' => 'SELECT * FROM inventory.items',
            'source' => 'nl',
            'dataSource' => 'folio',
        ]),
        'response' => new FakeExecuteResponse(),
        'folioDb' => $folioDb,
        'user' => (object) ['isGuest' => true, 'id' => null],
        'params' => [
            'maxQueryRows' => 100,
            'queryTimeoutMs' => 1800000,
        ],
    ];

    $defaultParamsController = new \app\controllers\FolioQueryController('folio-query', null);
    $defaultParamsController->actionExecute();

    assertCountValue(1, \app\services\SqlPreflightService::$calls, 'Generated execute requests without params should invoke PostgreSQL preflight exactly once.');
    assertSameValue([], \app\services\SqlPreflightService::$calls[0]['params'] ?? null, 'Generated execute requests without params should use the default empty preflight map.');

    \app\services\SqlPreflightService::$calls = [];
    \app\services\SqlPreflightService::$nextResult = ['error' => 'invalid input syntax for type uuid'];
    \app\services\SqlBuilderService::$buildCalls = [];
    Yii::$warnings = [];

    Yii::$app = (object) [
        'request' => new FakeExecuteRequest([
            'queryDefinition' => [
                'tables' => ['inventory.items'],
                'filters' => [
                    ['column' => 'holdings_record_id', 'operator' => '=', 'value' => 'not-a-uuid'],
                ],
            ],
        ]),
        'response' => new FakeExecuteResponse(),
        'folioDb' => $folioDb,
        'user' => (object) ['isGuest' => true, 'id' => null],
        'params' => [
            'maxQueryRows' => 100,
            'queryTimeoutMs' => 1800000,
        ],
    ];

    $submitController = new \app\controllers\FolioQueryController('folio-query', null);
    $submitResult = $submitController->actionQuerySubmit();

    assertSameValue(422, Yii::$app->response->statusCode, 'Query submit requests should fail fast when PostgreSQL preflight rejects parameterized SQL.');
    assertSameValue('invalid input syntax for type uuid', $submitResult['error'] ?? null, 'Query submit requests should surface the PostgreSQL preflight error directly.');
    assertCountValue(1, \app\services\SqlBuilderService::$buildCalls, 'Query submit requests should build queryDefinition input exactly once.');
    assertCountValue(1, \app\services\SqlPreflightService::$calls, 'Query submit requests should invoke PostgreSQL preflight exactly once.');
    assertSameValue('SELECT * FROM inventory.items WHERE holdings_record_id = :holdings_id', \app\services\SqlPreflightService::$calls[0]['sql'] ?? null, 'Query submit requests should preflight the SQL returned by the builder.');
    assertSameValue([':holdings_id' => 'not-a-uuid'], \app\services\SqlPreflightService::$calls[0]['params'] ?? null, 'Query submit requests should pass queued query parameters to PostgreSQL preflight.');

    $manualDb = new FakeExecuteDb();
    $manualDb->queryAllResult = [['total' => 1]];
    \app\services\SqlPreflightService::$calls = [];
    \app\services\SqlPreflightService::$nextResult = ['error' => 'should not run for manual SQL'];
    Yii::$warnings = [];

    Yii::$app = (object) [
        'request' => new FakeExecuteRequest([
            'sql' => 'SELECT 1 AS total',
            'source' => 'manual',
            'dataSource' => 'folio',
        ]),
        'response' => new FakeExecuteResponse(),
        'folioDb' => $manualDb,
        'user' => (object) ['isGuest' => true, 'id' => null],
        'params' => [
            'maxQueryRows' => 100,
            'queryTimeoutMs' => 1800000,
        ],
    ];

    $manualController = new \app\controllers\FolioQueryController('folio-query', null);
    $manualResult = $manualController->actionExecute();

    assertSameValue(200, Yii::$app->response->statusCode, 'Manual execute requests should keep their current execution behavior.');
    assertSameValue(1, $manualResult['rowCount'] ?? null, 'Manual execute requests should still return executed rows when preflight is skipped.');
    assertCountValue(0, \app\services\SqlPreflightService::$calls, 'Manual execute requests should skip PostgreSQL preflight.');
    assertCountValue(1, $manualDb->queriedSql, 'Manual execute requests should reach the database execution path.');
    assertCountValue(0, Yii::$warnings, 'Manual execute requests should not emit preflight telemetry warnings.');

    fwrite(STDOUT, "FolioQueryController execute preflight test passed\n");
}
