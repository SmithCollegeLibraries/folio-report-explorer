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

        public static function validateSafety($sql): void
        {
            $trimmed = ltrim((string)$sql);
            if (preg_match('/^(?:SELECT|WITH)\b/i', $trimmed) !== 1
                || preg_match('/^DELETE\b/i', $trimmed) === 1
            ) {
                throw new \InvalidArgumentException('Only SELECT queries are allowed.');
            }
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
        public static $warnings = [];
        public static function warning($message, $category = 'application')
        {
            self::$warnings[] = ['message' => $message, 'category' => $category];
        }
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
    Yii::$warnings = [];
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
    $terminalOutcomes = [];
    foreach (Yii::$warnings as $warning) {
        $message = (string)($warning['message'] ?? '');
        if (strpos($message, 'NL2SQL telemetry: ') !== 0) {
            continue;
        }
        $record = json_decode(substr($message, strlen('NL2SQL telemetry: ')), true);
        if (($record['event'] ?? null) === 'nl2sql.exploratory_terminal_outcome') {
            $terminalOutcomes[] = $record;
        }
    }
    repairAssertSame(1, count($terminalOutcomes), 'Exploratory controller handling should emit exactly one terminal outcome after re-preflight.');
    repairAssertSame('validated', $terminalOutcomes[0]['outcome'] ?? null, 'Successful re-preflight should emit terminal validated.');
    repairAssertSame('validated', $terminalOutcomes[0]['category'] ?? null, 'Successful re-preflight should retain a safe validated category.');
    repairAssertSame('exploratory', $terminalOutcomes[0]['route'] ?? null, 'Terminal validation telemetry should preserve the final exploratory route.');

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

    $canonicalRepairCalls = 0;
    $canonicalFailure = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT broken_column FROM inventory.instance__t',
            'mode' => 'canonical',
            'route' => 'builder_intent',
            'routeReason' => 'family_contract_supported:inventory_listing',
        ],
        'Show titles',
        'Smith College',
        function (): array { return ['error' => 'column "broken_column" does not exist']; },
        function () use (&$canonicalRepairCalls): array {
            $canonicalRepairCalls++;
            return ['sql' => 'SELECT title FROM inventory.instance__t'];
        }
    );
    repairAssertSame(0, $canonicalRepairCalls, 'Verified-family preflight failures must retain legacy recovery without Gemini repair.');
    repairAssertSame('exploratory_recovery', $canonicalFailure['route'] ?? null, 'Verified-family preflight failures should retain the legacy continuation route.');
    repairAssertSame(null, $canonicalFailure['validationSummary'] ?? null, 'Verified-family preflight failures must not be mislabeled as exploratory repair exhaustion.');

    $cancelRepairCalls = 0;
    try {
        $validateAndRepair->invoke(
            $controller,
            ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'exploratory', 'route' => 'exploratory'],
            'Show titles',
            'Smith College',
            function (): array { return ['error' => 'SQLSTATE[57014]: canceling statement due to statement timeout on inventory.instance__t']; },
            function () use (&$cancelRepairCalls): array {
                $cancelRepairCalls++;
                return [];
            }
        );
        fwrite(STDERR, "Database cancellation must remain a typed hard stop.\n");
        exit(1);
    } catch (\ReflectionException $exception) {
        throw $exception;
    } catch (\Throwable $exception) {
        $typedCancellation = $exception instanceof \app\exceptions\DatabaseQueryCancelledException
            || $exception->getPrevious() instanceof \app\exceptions\DatabaseQueryCancelledException;
        repairAssertSame(true, $typedCancellation, 'SQLSTATE 57014 should become a typed database cancellation.');
    }
    repairAssertSame(0, $cancelRepairCalls, 'Database cancellation must never call Gemini repair.');

    $continuation = new ReflectionMethod($controller, 'buildAskContinuationFromFailure');
    $cancelResponse = $continuation->invoke(
        $controller,
        new \app\exceptions\DatabaseQueryCancelledException(),
        'Show titles',
        'Smith College'
    );
    repairAssertSame('database_cancelled', $cancelResponse['errorType'] ?? null, 'Controller handling should preserve database cancellation as a distinct hard stop.');
    repairAssertSame(false, strpos(strtolower((string)($cancelResponse['error'] ?? '')), 'ai') !== false, 'Database cancellation copy must not mention an AI/model timeout.');

    Yii::$warnings = [];
    try {
        $validateAndRepair->invoke(
            $controller,
            [
                'sql' => 'SELECT title FROM inventory.instance__t',
                'mode' => 'exploratory',
                'route' => 'exploratory',
                'routeReason' => 'unsupported_query_family',
            ],
            'Show titles',
            'Smith College',
            function (): array {
                throw new \app\exceptions\DatabaseQueryCancelledException();
            },
            function (): array {
                fwrite(STDERR, "Typed database cancellation must not invoke repair.\n");
                exit(1);
            }
        );
        fwrite(STDERR, "Typed preflight cancellation must propagate to sanitized controller handling.\n");
        exit(1);
    } catch (\Throwable $exception) {
        $typedCancellation = $exception instanceof \app\exceptions\DatabaseQueryCancelledException
            || $exception->getPrevious() instanceof \app\exceptions\DatabaseQueryCancelledException;
        repairAssertSame(true, $typedCancellation, 'A direct preflight cancellation should remain typed.');
    }
    $typedCancelOutcomes = [];
    foreach (Yii::$warnings as $warning) {
        $message = (string)($warning['message'] ?? '');
        if (strpos($message, 'NL2SQL telemetry: ') !== 0) {
            continue;
        }
        $record = json_decode(substr($message, strlen('NL2SQL telemetry: ')), true);
        if (($record['event'] ?? null) === 'nl2sql.exploratory_terminal_outcome') {
            $typedCancelOutcomes[] = $record;
        }
    }
    repairAssertSame(1, count($typedCancelOutcomes), 'Direct typed preflight cancellation should emit exactly one terminal outcome.');
    repairAssertSame('cancelled', $typedCancelOutcomes[0]['outcome'] ?? null, 'Direct typed preflight cancellation should use the cancelled outcome.');
    repairAssertSame('database_cancelled', $typedCancelOutcomes[0]['category'] ?? null, 'Direct typed preflight cancellation should expose only a safe category.');
    repairAssertSame('exploratory', $typedCancelOutcomes[0]['route'] ?? null, 'Direct typed preflight cancellation should preserve the safe route.');
    repairAssertSame(false, isset($typedCancelOutcomes[0]['error']), 'Cancellation telemetry must not expose exception detail.');
    $typedCancelResponse = $continuation->invoke(
        $controller,
        new \app\exceptions\DatabaseQueryCancelledException(),
        'Show titles',
        'Smith College'
    );
    repairAssertSame('database_cancelled', $typedCancelResponse['errorType'] ?? null, 'Direct typed cancellation should return the sanitized database response.');

    $safePreflightCalls = 0;
    $safeWithDoValue = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => "SELECT 'DO' AS action_word FROM inventory.instance__t",
            'mode' => 'exploratory',
            'route' => 'exploratory',
            'routeReason' => 'unsupported_query_family',
            'repairAttempts' => 0,
        ],
        'Show reporting rows with their action label',
        'Smith College',
        function () use (&$safePreflightCalls): array {
            $safePreflightCalls++;
            return ['rows' => 1, 'cost' => 1.0];
        },
        function (): array {
            fwrite(STDERR, "A valid SELECT must not enter repair.\n");
            exit(1);
        }
    );
    repairAssertSame(1, $safePreflightCalls, 'The shared safety validator should allow the SELECT to reach preflight.');
    repairAssertSame(
        "SELECT 'DO' AS action_word FROM inventory.instance__t",
        $safeWithDoValue['sql'] ?? null,
        'A valid SELECT containing a harmless standalone value should return results instead of unsafe recovery.'
    );

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
    repairAssertSame(
        "I couldn't safely turn this request into a report. Nothing ran or changed. Retry the request or refine one part of it.",
        $unsafe['message'] ?? null,
        'Unsafe recovery should use the safe rejected-response copy.'
    );

    $unsafeCategory = $validateAndRepair->invoke(
        $controller,
        ['sql' => 'SELECT broken FROM inventory.instance__t', 'mode' => 'exploratory', 'repairAttempts' => 0],
        'Show titles',
        'Smith College',
        function (): array { return ['error' => 'syntax error at or near "broken"']; },
        function (): array {
            return [
                'repairAttempts' => 1,
                'validationSummary' => [
                    'failureCategory' => 'SQLSTATE[XX999] password=secret raw database details',
                ],
            ];
        }
    );
    repairAssertSame(
        'database_validation',
        $unsafeCategory['validationSummary']['failureCategory'] ?? null,
        'Untrusted repair failure categories should map to a fixed browser-safe category.'
    );

    $negativeRepairCalls = 0;
    $negativeAttempts = $validateAndRepair->invoke(
        $controller,
        ['sql' => 'SELECT broken FROM inventory.instance__t', 'mode' => 'exploratory', 'repairAttempts' => -50],
        'Show titles',
        'Smith College',
        function (): array { return ['error' => 'syntax error at or near "broken"']; },
        function () use (&$negativeRepairCalls): array {
            $negativeRepairCalls++;
            if ($negativeRepairCalls > 2) {
                return ['repairAttempts' => -50];
            }
            return ['sql' => 'SELECT still_broken FROM inventory.instance__t', 'repairAttempts' => -50];
        }
    );
    repairAssertSame(2, $negativeRepairCalls, 'Negative incoming repair counts must still allow at most two repairs.');
    repairAssertSame(2, $negativeAttempts['validationSummary']['repairAttempts'] ?? null, 'Negative incoming repair counts should exhaust at two repairs.');

    $excessiveRepairCalls = 0;
    $excessiveAttempts = $validateAndRepair->invoke(
        $controller,
        ['sql' => 'SELECT broken FROM inventory.instance__t', 'mode' => 'exploratory', 'repairAttempts' => 500],
        'Show titles',
        'Smith College',
        function (): array { return ['error' => 'syntax error at or near "broken"']; },
        function () use (&$excessiveRepairCalls): array {
            $excessiveRepairCalls++;
            return [];
        }
    );
    repairAssertSame(0, $excessiveRepairCalls, 'Excessive incoming repair counts must not permit another repair.');
    repairAssertSame(2, $excessiveAttempts['validationSummary']['repairAttempts'] ?? null, 'Excessive incoming repair counts should be clamped to two.');

    $controllerSource = file_get_contents(__DIR__ . '/../controllers/FolioQueryController.php');
    repairAssertSame(
        1,
        preg_match('/validateAndRepairNlResult\(\s*\$result,\s*\$prompt,\s*\$campus/s', $controllerSource),
        'Ask should pass the raw user prompt to repair/recovery instead of the expanded follow-up prompt.'
    );
    repairAssertSame(
        0,
        preg_match('/validateAndRepairNlResult\(\s*\$result,\s*\$effectivePrompt,/s', $controllerSource),
        'Ask must not expose the expanded follow-up prompt or previous SQL through repair recovery context.'
    );

    fwrite(STDOUT, "FolioQueryController exploratory repair test passed\n");
}
