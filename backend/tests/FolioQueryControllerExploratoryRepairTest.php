<?php

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
    class SqlBuilderService
    {
        public static function normalizeForExecution($sql)
        {
            return trim((string)$sql);
        }
    }
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

namespace Firebase\JWT { class JWT {} }

namespace {
    if (!defined('YII_ENV')) {
        define('YII_ENV', 'test');
    }

    class Yii
    {
        public static $app;
        public static function warning($message, $category = 'application') {}
    }

    function repairAssertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function repairAssertNotContains(string $needle, string $haystack, string $message): void
    {
        if (strpos($haystack, $needle) !== false) {
            fwrite(STDERR, $message . "\nUnexpected text: {$needle}\nActual: {$haystack}\n");
            exit(1);
        }
    }

    require_once __DIR__ . '/../controllers/FolioQueryController.php';

    Yii::$app = (object) [
        'response' => (object) ['statusCode' => 200, 'format' => null],
        'user' => (object) ['isGuest' => true, 'id' => null, 'identity' => null],
    ];

    $controller = new \app\controllers\FolioQueryController('folio-query', null);
    $validateAndRepair = new ReflectionMethod($controller, 'validateAndRepairNlResult');

    $preflightSql = [];
    $repairCalls = 0;
    $repaired = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => ' SELECT broken_column FROM inventory.instance__t ',
            'mode' => 'exploratory',
            'repairAttempts' => 0,
            'route' => 'exploratory',
            'routeReason' => 'unsupported_query_family',
        ],
        'Show title investment and circulation ROI',
        'Smith College',
        function (string $sql) use (&$preflightSql): array {
            $preflightSql[] = $sql;
            return count($preflightSql) === 1
                ? ['error' => 'column "broken_column" does not exist']
                : ['rows' => 10, 'cost' => 20.0];
        },
        function (string $prompt, $campus, array $result, string $error) use (&$repairCalls): array {
            $repairCalls++;
            repairAssertSame('Show title investment and circulation ROI', $prompt, 'Repair should receive the original question.');
            repairAssertSame('Smith College', $campus, 'Repair should receive the campus.');
            repairAssertSame('column "broken_column" does not exist', $error, 'Repair should receive the preflight failure.');
            return [
                'sql' => 'SELECT title FROM inventory.instance__t',
                'mode' => 'canonical',
                'repairAttempts' => 1,
                'route' => 'canonical',
            ];
        }
    );

    repairAssertSame(1, $repairCalls, 'A repairable preflight failure should make one repair call.');
    repairAssertSame(
        ['SELECT broken_column FROM inventory.instance__t', 'SELECT title FROM inventory.instance__t'],
        $preflightSql,
        'Every candidate should be normalized and preflighted.'
    );
    repairAssertSame('SELECT title FROM inventory.instance__t', $repaired['sql'] ?? null, 'The repaired SQL should be returned after successful preflight.');
    repairAssertSame(1, $repaired['repairAttempts'] ?? null, 'The repair count should be preserved.');
    repairAssertSame('exploratory', $repaired['mode'] ?? null, 'AI-repaired canonical output should be relabeled exploratory.');
    repairAssertSame('exploratory', $repaired['route'] ?? null, 'AI-repaired canonical output should not retain a canonical route.');

    $exhaustedRepairCalls = 0;
    $exhausted = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT missing_column FROM inventory.instance__t',
            'mode' => 'exploratory',
            'repairAttempts' => 2,
            'assumptions' => [['key' => 'purchase_date_basis', 'value' => 'payment_date']],
            'attemptedPlan' => 'Aggregate investment before joining circulation.',
            'suggestions' => ['Use a shorter reporting window.'],
        ],
        'Compare investment and circulation ROI',
        'Smith College',
        function (): array { return ['error' => 'column "missing_column" does not exist']; },
        function () use (&$exhaustedRepairCalls): array {
            $exhaustedRepairCalls++;
            return [];
        }
    );

    repairAssertSame(0, $exhaustedRepairCalls, 'An exhausted result must not make another repair call.');
    repairAssertSame(false, array_key_exists('sql', $exhausted), 'Exhausted recovery must never include SQL.');
    repairAssertSame('sql_repair_exhausted', $exhausted['errorType'] ?? null, 'Exhaustion should expose a stable error type.');
    repairAssertSame('exhausted', $exhausted['validationSummary']['status'] ?? null, 'Exhaustion should expose its validation status.');
    repairAssertSame(2, $exhausted['validationSummary']['repairAttempts'] ?? null, 'Exhaustion should report the actual repair count.');
    repairAssertSame('Compare investment and circulation ROI', $exhausted['recoveryContext']['originalQuestion'] ?? null, 'Recovery should preserve the original question.');
    repairAssertSame('Smith College', $exhausted['recoveryContext']['campus'] ?? null, 'Recovery should preserve campus scope.');
    repairAssertSame([['key' => 'purchase_date_basis', 'value' => 'payment_date']], $exhausted['assumptions'] ?? null, 'Recovery should preserve assumptions.');
    repairAssertSame('Aggregate investment before joining circulation.', $exhausted['attemptedPlan'] ?? null, 'Recovery should preserve the attempted plan.');
    repairAssertSame(['Use a shorter reporting window.'], $exhausted['suggestions'] ?? null, 'Recovery should preserve suggestions.');
    repairAssertNotContains('verified report pattern', json_encode($exhausted), 'Exhausted recovery must not use verified-pattern roadblock copy.');

    $connectivityRepairCalls = 0;
    $connectivity = $validateAndRepair->invoke(
        $controller,
        ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'exploratory', 'repairAttempts' => 0],
        'Show titles',
        'Smith College',
        function (): array { return ['error' => 'SQLSTATE[08006] [7] timeout expired']; },
        function () use (&$connectivityRepairCalls): array {
            $connectivityRepairCalls++;
            return [];
        }
    );

    repairAssertSame(0, $connectivityRepairCalls, 'Connectivity failures must not trigger SQL repair.');
    repairAssertSame('postgres_connectivity', $connectivity['errorType'] ?? null, 'Connectivity failures should remain distinct.');
    repairAssertSame(true, strpos($connectivity['message'] ?? '', 'VPN') !== false, 'Connectivity recovery should continue to mention VPN.');

    $unsafeRepairCalls = 0;
    $unsafe = $validateAndRepair->invoke(
        $controller,
        ['sql' => 'DELETE FROM inventory.instance__t', 'mode' => 'canonical', 'repairAttempts' => 1],
        'Delete old titles',
        'Smith College',
        function (): array {
            fwrite(STDERR, "Unsafe SQL must be rejected before database preflight.\n");
            exit(1);
        },
        function () use (&$unsafeRepairCalls): array {
            $unsafeRepairCalls++;
            return [];
        }
    );

    repairAssertSame(0, $unsafeRepairCalls, 'Unsafe generated SQL must not enter repair.');
    repairAssertSame('unsafe_generated_sql', $unsafe['errorType'] ?? null, 'Unsafe generated SQL should expose a distinct error type.');
    repairAssertSame(0, $unsafe['validationSummary']['repairAttempts'] ?? null, 'Unsafe generated SQL should report zero repairs.');
    repairAssertSame(false, array_key_exists('sql', $unsafe), 'Unsafe recovery must not include SQL.');
    repairAssertSame(true, strpos($unsafe['message'] ?? '', 'no unsafe SQL ran') !== false, 'Unsafe recovery should state that no unsafe SQL ran.');

    fwrite(STDOUT, "FolioQueryController exploratory repair test passed\n");
}
