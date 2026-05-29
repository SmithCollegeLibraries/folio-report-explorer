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
    }

    class Response
    {
        public const FORMAT_JSON = 'json';
    }
}

namespace app\models {
    class DummyIdentity
    {
        public function getId()
        {
            return 1;
        }

        public function isAdmin()
        {
            return false;
        }
    }

    class QueryJob
    {
        public static $jobs = [];

        public $id;
        public $name;
        public $sql_text;
        public $status;
        public $user_id;
        public $result_columns;

        public static function findOne($id)
        {
            return self::$jobs[$id] ?? null;
        }

        public function getDecodedColumns()
        {
            return json_decode($this->result_columns ?: '[]', true) ?: [];
        }
    }
}

namespace app\services {
    class FolioSchemaService {}
    class SqlBuilderService {}
    class GeminiService
    {
        public const NL2SQL_TELEMETRY_CATEGORY = 'nl2sql.telemetry';
    }
    class SettingsService {}
    class DatabaseRetryService {}
    class IndexRecommendationService {}
    class Nl2sqlRuntimePreflightService {}
    class SqlPreflightService {}
}

namespace Firebase\JWT {
    class JWT {}
}

namespace {
    if (!defined('YII_ENV')) {
        define('YII_ENV', 'test');
    }

    class Yii
    {
        public static $app;

        public static function warning($message, $category = 'application')
        {
        }
    }

    final class FakeFollowUpResponse
    {
        public $statusCode = 200;
        public $format;
    }

    function assertSameValue($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function assertContainsText(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) === false) {
            fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
            exit(1);
        }
    }

    $controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';
    if (!file_exists($controllerPath)) {
        fwrite(STDERR, "FolioQueryController is missing at {$controllerPath}\n");
        exit(1);
    }

    require_once $controllerPath;

    Yii::$app = (object) [
        'response' => new FakeFollowUpResponse(),
        'user' => (object) ['isGuest' => true, 'id' => null, 'identity' => null],
    ];

    $controller = new \app\controllers\FolioQueryController('folio-query', null);

    $normalize = new ReflectionMethod($controller, 'normalizeFollowUpContext');
    $build = new ReflectionMethod($controller, 'buildFollowUpPrompt');

    $context = $normalize->invoke($controller, [
        'previousPrompt' => 'Please provide a list of titles with the location MRBC Reference Collection containing only records for which the MRBC Reference Collection is the only holding location in the 5 Colleges.',
        'previousSql' => 'SELECT inst.title FROM inventory.instance__t inst',
        'previousColumns' => ['title'],
        'source' => 'ask',
    ]);
    $expandedPrompt = $build->invoke(
        $controller,
        'Provide the same list and include instance numbers and call numbers.',
        $context
    );

    assertContainsText('Previous request:', $expandedPrompt, 'Expanded follow-up prompt should label the previous user request.');
    assertContainsText('MRBC Reference Collection', $expandedPrompt, 'Expanded follow-up prompt should include the previous request.');
    assertContainsText('SELECT inst.title FROM inventory.instance__t inst', $expandedPrompt, 'Expanded follow-up prompt should include the previous SQL.');
    assertContainsText('Previous result columns: title', $expandedPrompt, 'Expanded follow-up prompt should include prior result columns.');
    assertContainsText('Follow-up request:', $expandedPrompt, 'Expanded follow-up prompt should label the follow-up request.');
    assertContainsText('include instance numbers and call numbers', $expandedPrompt, 'Expanded follow-up prompt should include the follow-up request.');
    assertContainsText('Preserve all previous filters, joins, CTEs, and result-set semantics unless the follow-up request explicitly changes them.', $expandedPrompt, 'Expanded follow-up prompt should preserve prior query semantics.');

    $completed = new \app\models\QueryJob();
    $completed->id = 'done-job';
    $completed->name = 'Original MRBC title list';
    $completed->sql_text = 'SELECT inst.title FROM inventory.instance__t inst';
    $completed->status = 'completed';
    $completed->user_id = 1;
    $completed->result_columns = json_encode(['title']);
    \app\models\QueryJob::$jobs = ['done-job' => $completed];
    Yii::$app->response->statusCode = 200;

    $historyContext = $normalize->invoke($controller, [
        'jobId' => 'done-job',
        'source' => 'history',
    ]);

    assertSameValue('history', $historyContext['source'] ?? null, 'History follow-up context should preserve the history source.');
    assertSameValue('Original MRBC title list', $historyContext['previousPrompt'] ?? null, 'History follow-up context should use the job name as the previous prompt.');
    assertSameValue('SELECT inst.title FROM inventory.instance__t inst', $historyContext['previousSql'] ?? null, 'History follow-up context should use stored job SQL.');
    assertSameValue(['title'], $historyContext['previousColumns'] ?? null, 'History follow-up context should expose stored result columns.');

    Yii::$app->response->statusCode = 200;
    $missingContext = $normalize->invoke($controller, [
        'jobId' => 'missing-job',
        'source' => 'history',
    ]);
    assertSameValue(null, $missingContext, 'Missing history jobs should not return a follow-up context.');
    assertSameValue(404, Yii::$app->response->statusCode, 'Missing history jobs should set a 404 response.');

    $running = new \app\models\QueryJob();
    $running->id = 'running-job';
    $running->name = 'Running query';
    $running->sql_text = 'SELECT 1';
    $running->status = 'running';
    $running->user_id = 1;
    \app\models\QueryJob::$jobs = ['running-job' => $running];
    Yii::$app->response->statusCode = 200;

    $runningContext = $normalize->invoke($controller, [
        'jobId' => 'running-job',
        'source' => 'history',
    ]);
    assertSameValue(null, $runningContext, 'Non-completed history jobs should not return a follow-up context.');
    assertSameValue(409, Yii::$app->response->statusCode, 'Non-completed history jobs should set a 409 response.');

    fwrite(STDOUT, "FolioQueryController NL follow-up test passed\n");
}
