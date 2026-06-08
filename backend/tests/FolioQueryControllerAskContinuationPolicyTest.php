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

        public static function isAiTimeoutMessage($message): bool
        {
            return false;
        }
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

        public static function warning($message, $category = 'application')
        {
        }
    }

    final class FakeAskContinuationResponse
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
            fwrite(STDERR, $message . "\nMissing text: {$needle}\nActual: {$haystack}\n");
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
        'response' => new FakeAskContinuationResponse(),
        'user' => (object) ['isGuest' => true, 'id' => null, 'identity' => null],
    ];

    $controller = new \app\controllers\FolioQueryController('folio-query', null);
    $continuation = new ReflectionMethod($controller, 'buildAskContinuationFromFailure');

    $softFailure = $continuation->invoke(
        $controller,
        new RuntimeException('I could not safely generate this inventory library location listing request, and legacy fallback is disabled for this route to avoid incorrect results.'),
        'Show MRBC Reference Collection titles',
        'Smith College'
    );

    assertSameValue(200, Yii::$app->response->statusCode, 'Soft Ask failures should not return an HTTP error status.');
    assertSameValue(false, $softFailure['needsClarification'] ?? false, 'Soft Ask failures should not require an acknowledgment click.');
    assertSameValue(false, $softFailure['needsExploratoryApproval'] ?? false, 'Soft Ask failures should not require exploratory approval.');
    assertSameValue('ask_generation_recovery', $softFailure['routeReason'] ?? null, 'Soft Ask failures should expose a stable recovery route reason.');
    assertSameValue('AI-assisted query', $softFailure['exploratoryNotice']['title'] ?? null, 'Soft Ask recovery should return advisory notice metadata.');
    assertContainsText('could not produce fully validated SQL', $softFailure['exploratoryNotice']['message'] ?? '', 'Soft Ask recovery should use staff-facing advisory copy.');
    assertSameValue('exploratory', $softFailure['mode'] ?? null, 'Soft Ask recovery should be labeled exploratory.');

    Yii::$app->response->statusCode = 200;
    $postgresFailure = $continuation->invoke(
        $controller,
        new RuntimeException('SQLSTATE[08006] [7] timeout expired'),
        'Show MRBC Reference Collection titles',
        'Smith College',
        'ask_sql_preflight_recovery'
    );

    assertSameValue(200, Yii::$app->response->statusCode, 'Postgres connectivity failures should not be reported as AI timeouts.');
    assertSameValue('postgres_connectivity', $postgresFailure['errorType'] ?? null, 'Postgres connectivity failures should be classified separately.');
    assertContainsText('FOLIO reporting database', $postgresFailure['exploratoryNotice']['message'] ?? '', 'Postgres connectivity recovery should name the database connection issue.');
    assertContainsText('VPN', $postgresFailure['exploratoryNotice']['message'] ?? '', 'Postgres connectivity recovery should mention VPN/off-campus access.');

    Yii::$app->response->statusCode = 200;
    $policyFailure = $continuation->invoke(
        $controller,
        new InvalidArgumentException('Queries about patron personal information or individual patron records are not supported.'),
        'List all patron emails',
        'Smith College'
    );

    assertSameValue(403, Yii::$app->response->statusCode, 'Security and patron-PII policy failures should remain blocked.');
    assertContainsText('aggregate', $policyFailure['error'] ?? '', 'Policy blocks should offer an allowed aggregate-reporting alternative.');

    $controllerSource = file_get_contents($controllerPath);
    assertContainsText(
        '$includeSuggestions && empty($result[\'needsClarification\']) && !empty($result[\'sql\'])',
        $controllerSource,
        'Ask follow-up suggestions should only be generated when SQL is present.'
    );

    fwrite(STDOUT, "FolioQueryController Ask continuation policy test passed\n");
}
