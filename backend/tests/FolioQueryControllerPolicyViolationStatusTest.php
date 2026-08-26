<?php

// Regression test: buildAskContinuationFromFailure decided 403 (policy block) vs
// 200 (soft recovery) by substring-matching the exception message. A policy
// block whose wording lacks the keywords would leak as a 200 success. It must
// instead return 403 whenever the error is a PolicyViolationException, regardless
// of message wording, while genuine soft failures still return 200.

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

    require_once __DIR__ . '/../exceptions/PolicyViolationException.php';
    $controllerPath = __DIR__ . '/../controllers/FolioQueryController.php';
    if (!file_exists($controllerPath)) {
        fwrite(STDERR, "FolioQueryController is missing at {$controllerPath}\n");
        exit(1);
    }
    require_once $controllerPath;

    Yii::$app = (object) [
        'response' => (object) ['statusCode' => 200, 'format' => null],
        'user' => (object) ['isGuest' => true, 'id' => null, 'identity' => null],
        'params' => ['nl2sqlTwoLaneEnabled' => false],
    ];

    $controller = new \app\controllers\FolioQueryController('folio-query', null);
    $continuation = new ReflectionMethod($controller, 'buildAskContinuationFromFailure');
    $continuation->setAccessible(true);

    // Neutral wording with none of the legacy security keywords.
    $neutralPolicyMessage = 'Query references a restricted dataset for this report.';

    // A PolicyViolationException must block with 403 regardless of wording.
    Yii::$app->response->statusCode = 200;
    $policyResult = $continuation->invoke(
        $controller,
        new \app\exceptions\PolicyViolationException($neutralPolicyMessage),
        'Show me every patron and their address',
        'Smith College'
    );
    assertSameValue(403, Yii::$app->response->statusCode, 'A PolicyViolationException must return HTTP 403 even when the message lacks legacy keywords.');
    assertSameValue('blocked', $policyResult['route'] ?? null, 'A policy violation must be routed as blocked.');
    assertSameValue(
        false,
        strpos((string)($policyResult['error'] ?? ''), $neutralPolicyMessage) !== false,
        'Policy responses must not echo internal exception detail.'
    );

    $databasePolicyDetail = 'SQLSTATE[42501]: permission denied for table users.users__t; driver stack secret';
    $databasePolicyResult = $continuation->invoke(
        $controller,
        new \app\exceptions\PolicyViolationException($databasePolicyDetail),
        'Show restricted rows',
        'Smith College'
    );
    foreach (['SQLSTATE', '42501', 'users.users__t', 'driver stack secret'] as $unsafeFragment) {
        assertSameValue(
            false,
            stripos((string)($databasePolicyResult['error'] ?? ''), $unsafeFragment) !== false,
            'Permission responses must expose only stable policy guidance.'
        );
    }

    // The same neutral wording as a plain InvalidArgumentException retains the
    // soft recovery contract only on the explicit rollback path.
    Yii::$app->response->statusCode = 200;
    $softResult = $continuation->invoke(
        $controller,
        new \InvalidArgumentException($neutralPolicyMessage),
        'Some unsupported analytical question',
        'Smith College'
    );
    assertSameValue(200, Yii::$app->response->statusCode, 'A non-policy InvalidArgumentException must still soft-recover with 200.');
    assertSameValue('exploratory_recovery', $softResult['route'] ?? null, 'A non-policy soft failure must use the recovery route.');

    fwrite(STDOUT, "FolioQueryController policy violation status test passed\n");
}
