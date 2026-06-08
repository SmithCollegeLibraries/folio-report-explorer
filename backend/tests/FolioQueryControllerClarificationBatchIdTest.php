<?php

// Regression test: client-supplied clarificationBatchId was inserted verbatim
// into ai_clarification_events.clarification_batch_id (CHAR(36)) with only an
// empty-string check. An over-length or malformed value triggers MySQL error
// 1406 (strict mode) aborting the batch, or silent truncation. The controller
// must normalize the batch id to a safe CHAR(36)-compatible token or null.

namespace yii\web {
    class Controller
    {
        public function __construct($id = null, $module = null, $config = []) {}
        public function behaviors() { return []; }
    }
    class Response { public const FORMAT_JSON = 'json'; }
}

namespace app\models {
    class SavedQuery {}
    class QueryLog {}
    class QueryJob {}
    class ReportTemplate {}
    class AcrlStatistic {}
    class ExpenseAllocation {}
    class User {}
    class DummyIdentity {}
}

namespace app\services {
    class FolioSchemaService {}
    class SqlBuilderService {}
    class GeminiService
    {
        public const NL2SQL_TELEMETRY_CATEGORY = 'nl2sql.telemetry';
        public static function isAiTimeoutMessage($message): bool { return false; }
    }
    class SettingsService {}
    class DatabaseRetryService {}
    class IndexRecommendationService {}
    class Nl2sqlRuntimePreflightService {}
    class ReferenceCacheRefreshService {}
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
        public static function warning($message, $category = 'application') {}
    }

    function assertSameValue($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
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
        'response' => (object) ['statusCode' => 200, 'format' => null],
        'user' => (object) ['isGuest' => true, 'id' => null, 'identity' => null],
    ];

    $controller = new \app\controllers\FolioQueryController('folio-query', null);
    $normalize = new ReflectionMethod($controller, 'normalizeClarificationBatchId');
    $normalize->setAccessible(true);

    $uuid = '550e8400-e29b-41d4-a716-446655440000'; // 36 chars
    assertSameValue($uuid, $normalize->invoke($controller, $uuid), 'A valid 36-char UUID batch id must be preserved.');
    assertSameValue(null, $normalize->invoke($controller, ''), 'An empty batch id must become null.');
    assertSameValue(null, $normalize->invoke($controller, str_repeat('a', 50)), 'An over-length batch id must become null rather than overflow CHAR(36).');
    assertSameValue(null, $normalize->invoke($controller, "bad'; DROP TABLE x;--"), 'A malformed batch id with unsafe characters must become null.');
    assertSameValue('abc-123_DEF', $normalize->invoke($controller, '  abc-123_DEF  '), 'A safe token must be trimmed and preserved.');

    fwrite(STDOUT, "FolioQueryController clarification batch id test passed\n");
}
