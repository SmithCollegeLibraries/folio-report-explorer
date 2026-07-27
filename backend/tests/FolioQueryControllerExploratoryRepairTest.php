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
        public static $preflightRepairCalls = [];
        public static $generationCalls = [];
        public static $generationResult = [];
        public static $generationTransport = [];

        public static function isAiTimeoutMessage($message): bool { return false; }

        public static function generateSqlWithShadow(
            $rawQuestion,
            $campus = null,
            $userId = null,
            $allowExploratory = false,
            $generationPrompt = null,
            ?array &$generationTransport = null
        ): array {
            self::$generationCalls[] = [
                'rawQuestion' => $rawQuestion,
                'campus' => $campus,
                'userId' => $userId,
                'allowExploratory' => $allowExploratory,
                'generationPrompt' => $generationPrompt,
            ];
            $generationTransport = self::$generationTransport;
            return self::$generationResult;
        }

        public static function repairExploratorySqlAfterPreflight(
            string $originalQuestion,
            $campus,
            array $currentResult,
            string $preflightError,
            ?string $generationPrompt = null
        ): array {
            self::$preflightRepairCalls[] = [
                'originalQuestion' => $originalQuestion,
                'campus' => $campus,
                'currentResult' => $currentResult,
                'preflightError' => $preflightError,
                'generationPrompt' => $generationPrompt,
            ];

            return [
                'sql' => 'SELECT title FROM inventory.instance__t',
                'repairAttempts' => 1,
                'mode' => 'exploratory',
                'route' => 'exploratory',
                'routeReason' => 'unsupported_query_family',
                'referenceResolver' => [
                    'resolved' => true,
                    'guidanceLines' => [
                        "- Resolved local reference: use exactly inventory.material_type__t.name = 'E-Book'.",
                    ],
                ],
            ];
        }
    }
    class SettingsService {}
    class DatabaseRetryService {}
    class IndexRecommendationService {}
    class Nl2sqlRuntimePreflightService {}
    class ReferenceCacheRefreshService {}
    class SqlPreflightService
    {
        public static $calls = [];
        public static $results = [];

        public static function estimateQueryComplexity($db, string $sql, int $timeoutMs, int $rowLimit, array $params): array
        {
            self::$calls[] = [
                'sql' => $sql,
                'timeoutMs' => $timeoutMs,
                'rowLimit' => $rowLimit,
                'params' => $params,
            ];
            return array_shift(self::$results) ?? [];
        }
    }
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

    require_once __DIR__ . '/../exceptions/PolicyViolationException.php';
    require_once __DIR__ . '/../controllers/FolioQueryController.php';

    final class CapturingAdministratorReviewService
    {
        public $received;
        public $failure;

        public function recordGeneration(array $context): array
        {
            $this->received = $context;
            if ($this->failure !== null) {
                throw $this->failure;
            }
            return ['generationId' => 'generation-1', 'conversationId' => 'conversation-1'];
        }
    }

    final class TestableFolioQueryController extends \app\controllers\FolioQueryController
    {
        public $reviewService;

        protected function administratorReviewService()
        {
            return $this->reviewService;
        }
    }

    final class TestRequest
    {
        private $body;

        public function __construct(array $body)
        {
            $this->body = $body;
        }

        public function getBodyParams(): array
        {
            return $this->body;
        }
    }

    Yii::$app = (object) [
        'response' => (object) ['statusCode' => 200, 'format' => null],
        'user' => (object) ['isGuest' => true, 'id' => null, 'identity' => null],
        'params' => ['queryTimeoutMs' => 30000],
        'folioDb' => (object) [],
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

    $rawFollowUpQuestion = 'Include instance numbers in0001 and in0002. Limit 20.';
    $modelOnlyGenerationPrompt = implode("\n\n", [
        'This is a follow-up request to a previously generated library report.',
        'Previous SQL: SELECT title FROM inventory.instance__t',
        'Follow-up request: ' . $rawFollowUpQuestion,
        "Reference resolver guidance:\n- Use inventory.material_type__t.name = 'E-Book'.",
        "EXPLICIT REPORT VALUES (preserve every value exactly):\n- instance_number: in0001, in0002",
    ]);
    \app\services\GeminiService::$preflightRepairCalls = [];
    $modelContextPreflightCalls = 0;
    $modelContextResult = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT broken_column FROM inventory.instance__t',
            'mode' => 'exploratory',
            'repairAttempts' => 0,
            'route' => 'exploratory_legacy_freeform',
            'routeReason' => 'unsupported_query_family',
        ],
        $rawFollowUpQuestion,
        'Smith College',
        function () use (&$modelContextPreflightCalls): array {
            $modelContextPreflightCalls++;
            return $modelContextPreflightCalls === 1
                ? ['error' => 'column "broken_column" does not exist']
                : ['rows' => 2, 'cost' => 3.0];
        },
        null,
        $modelOnlyGenerationPrompt
    );
    repairAssertSame(1, count(\app\services\GeminiService::$preflightRepairCalls), 'Controller post-preflight failure should invoke the production repair seam once.');
    repairAssertSame(
        $rawFollowUpQuestion,
        \app\services\GeminiService::$preflightRepairCalls[0]['originalQuestion'] ?? null,
        'Controller post-preflight repair must retain the exact latest raw question.'
    );
    repairAssertSame(
        $modelOnlyGenerationPrompt,
        \app\services\GeminiService::$preflightRepairCalls[0]['generationPrompt'] ?? null,
        'Controller post-preflight repair must retain follow-up, resolver, and explicit guidance as model-only context.'
    );
    repairAssertSame('SELECT title FROM inventory.instance__t', $modelContextResult['sql'] ?? null, 'Model-context regression should re-preflight the repaired SQL.');

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
            '_askEvidence' => [
                'initialSql' => 'SELECT original_column FROM inventory.instance__t',
                'finalSql' => 'SELECT missing_column FROM inventory.instance__t',
                'repairAttempts' => 2,
                'referenceBundleMetadata' => ['version' => 'bundle-v1', 'hash' => 'bundle-hash'],
            ],
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
    repairAssertSame('missing_column', $exhausted['validationSummary']['failureCategory'] ?? null, 'Ordinary database exhaustion should retain its existing safe database category.');
    repairAssertSame(2, $exhausted['validationSummary']['repairAttempts'] ?? null, 'Exhaustion should report the actual repair count.');
    repairAssertSame('SELECT original_column FROM inventory.instance__t', $exhausted['_askEvidence']['initialSql'] ?? null, 'Controller exhaustion must retain trusted initial candidate evidence until finalization.');
    repairAssertSame('bundle-hash', $exhausted['_askEvidence']['referenceBundleMetadata']['hash'] ?? null, 'Controller exhaustion must retain trusted provenance until finalization.');
    repairAssertSame(
        'I could not build a report I could safely run. Your request is preserved, and you can retry it or adjust one part of the question.',
        $exhausted['message'] ?? null,
        'Ordinary database exhaustion should use novice-facing recovery copy.'
    );
    repairAssertSame(false, isset($exhausted['unmetRequirements']), 'Ordinary database exhaustion should not acquire semantic requirement fields.');
    repairAssertSame('Compare investment and circulation ROI', $exhausted['recoveryContext']['originalQuestion'] ?? null, 'Recovery should preserve the original question.');
    repairAssertSame('Smith College', $exhausted['recoveryContext']['campus'] ?? null, 'Recovery should preserve campus scope.');
    repairAssertSame([['key' => 'purchase_date_basis', 'value' => 'payment_date']], $exhausted['assumptions'] ?? null, 'Recovery should preserve assumptions.');
    repairAssertSame('Aggregate investment before joining circulation.', $exhausted['attemptedPlan'] ?? null, 'Recovery should preserve the attempted plan.');
    repairAssertSame(['Use a shorter reporting window.'], $exhausted['suggestions'] ?? null, 'Recovery should preserve suggestions.');
    repairAssertNotContains('verified report pattern', json_encode($exhausted), 'Exhausted recovery must not use verified-pattern roadblock copy.');

    $semanticRepairCalls = 0;
    $semanticExhausted = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT stale_candidate FROM inventory.instance__t',
            'mode' => 'exploratory',
            'repairAttempts' => 1,
            'route' => 'exploratory',
            'routeReason' => 'unsupported_query_family',
            'semanticValidation' => [
                'status' => 'validated',
                'evidence' => 'stale rejected candidate evidence',
            ],
        ],
        'Compare purchases and circulation ROI by call number',
        'Smith College',
        function (): array {
            return ['error' => 'ERROR: column private_schema.secret_value does not exist at character 42'];
        },
        function () use (&$semanticRepairCalls): array {
            $semanticRepairCalls++;
            return [
                'repairAttempts' => 2,
                'assumptions' => [['key' => 'purchase_date_basis', 'value' => 'payment_date']],
                'attemptedPlan' => 'Aggregate paid spend before joining item-level circulation.',
                'suggestions' => ['Adjust the purchase-date assumption and retry.'],
                'unmetRequirements' => [[
                    'key' => 'purchase_date_basis',
                    'label' => 'Use the resolved purchase date basis.',
                    'guidance' => 'internal repair guidance must not cross the controller boundary',
                    'evidence' => 'private predicate evidence',
                ]],
                'validationSummary' => [
                    'status' => 'exhausted',
                    'validatorStage' => 'semantic_conformance',
                    'failureCategory' => 'assumption_mismatch',
                    'message' => "I couldn't produce a report that matched every checked requirement. Nothing ran or changed. Your request is preserved so you can retry or adjust an assumption.",
                ],
                'error' => 'raw exception text must not cross the controller boundary',
            ];
        }
    );

    repairAssertSame(1, $semanticRepairCalls, 'Semantic exhaustion after one prior repair should make exactly one remaining repair call.');
    repairAssertSame(2, $semanticExhausted['validationSummary']['repairAttempts'] ?? null, 'Semantic exhaustion should preserve the exact shared repair count.');
    repairAssertSame('semantic_conformance', $semanticExhausted['validationSummary']['validatorStage'] ?? null, 'Controller recovery should preserve the semantic validator stage.');
    repairAssertSame('assumption_mismatch', $semanticExhausted['validationSummary']['failureCategory'] ?? null, 'Controller recovery should preserve the safe semantic category.');
    repairAssertSame(
        'I could not build a report I could safely run. Your request is preserved, and you can retry it or adjust one part of the question.',
        $semanticExhausted['message'] ?? null,
        'Controller recovery should use novice-facing recovery copy.'
    );
    repairAssertSame(
        [['key' => 'purchase_date_basis', 'label' => 'Use the resolved purchase date basis.']],
        $semanticExhausted['unmetRequirements'] ?? null,
        'Controller recovery should expose stable unmet requirement keys and labels only.'
    );
    repairAssertSame([['key' => 'purchase_date_basis', 'value' => 'payment_date']], $semanticExhausted['assumptions'] ?? null, 'Semantic recovery should preserve assumptions.');
    repairAssertSame('Aggregate paid spend before joining item-level circulation.', $semanticExhausted['attemptedPlan'] ?? null, 'Semantic recovery should preserve the attempted plan.');
    repairAssertSame(['Adjust the purchase-date assumption and retry.'], $semanticExhausted['suggestions'] ?? null, 'Semantic recovery should preserve safe suggestions.');
    repairAssertSame('Compare purchases and circulation ROI by call number', $semanticExhausted['recoveryContext']['originalQuestion'] ?? null, 'Semantic recovery should preserve the original question.');
    repairAssertSame('Smith College', $semanticExhausted['recoveryContext']['campus'] ?? null, 'Semantic recovery should preserve campus scope.');
    repairAssertSame(false, array_key_exists('sql', $semanticExhausted), 'Semantic controller recovery must not expose rejected SQL.');
    repairAssertSame(false, array_key_exists('semanticValidation', $semanticExhausted), 'Semantic controller recovery must not expose stale candidate validation.');
    $semanticRecoveryJson = json_encode($semanticExhausted);
    foreach ([
        'stale_candidate',
        'internal repair guidance',
        'private predicate evidence',
        'private_schema.secret_value',
        'raw exception text',
        'stale rejected candidate evidence',
    ] as $privateFragment) {
        repairAssertNotContains($privateFragment, $semanticRecoveryJson, 'Semantic controller recovery must not expose internal SQL, guidance, evidence, or error detail.');
    }

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

    $roiQuestion = 'Rank purchase activity by purchase count';
    $semanticPreflightCalls = 0;
    $missingSemantic = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT purchase_count FROM purchase_data ORDER BY purchase_count DESC',
            'mode' => 'exploratory',
            'route' => 'exploratory_legacy_freeform',
            'semanticContractApplicable' => true,
            'repairAttempts' => 2,
        ],
        $roiQuestion,
        'Smith College',
        function () use (&$semanticPreflightCalls): array {
            $semanticPreflightCalls++;
            return ['rows' => 1];
        }
    );
    repairAssertSame(0, $semanticPreflightCalls, 'Applicable exploratory SQL without semantic validation must never reach preflight.');
    repairAssertSame(false, isset($missingSemantic['sql']), 'Unverified SQL must not be returned.');

    $validatedPreflightCalls = 0;
    $validatedResult = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT purchase_count FROM purchase_data ORDER BY purchase_count DESC',
            'mode' => 'exploratory',
            'route' => 'exploratory_legacy_freeform',
            'semanticContractApplicable' => true,
            'semanticValidation' => [
                'status' => 'validated',
                'contractVersion' => 1,
                'checkedRequirements' => [[
                    'key' => 'purchase_ranking',
                    'label' => 'Results are ranked by purchases.',
                ]],
            ],
            'repairAttempts' => 0,
        ],
        $roiQuestion,
        'Smith College',
        function () use (&$validatedPreflightCalls): array {
            $validatedPreflightCalls++;
            return ['rows' => 1];
        }
    );
    repairAssertSame(1, $validatedPreflightCalls, 'Semantically validated SQL should reach database preflight.');
    repairAssertSame('SELECT purchase_count FROM purchase_data ORDER BY purchase_count DESC', $validatedResult['sql'] ?? null, 'Validated applicable SQL should be returned after preflight.');

    $capturingReview = new CapturingAdministratorReviewService();
    $finalizingController = new TestableFolioQueryController('folio-query', null);
    $finalizingController->reviewService = $capturingReview;
    $finalize = new ReflectionMethod($finalizingController, 'finalizeAskResponse');
    $finalized = $finalize->invoke($finalizingController, [
        'mode' => 'exploratory',
        'route' => 'exploratory_recovery',
        'validationSummary' => [
            'status' => 'exhausted',
            'validatorStage' => 'semantic_conformance',
            'failureCategory' => 'semantic_coverage_gap',
        ],
        'unmetRequirements' => [[
            'key' => 'purchase_date_basis',
            'label' => 'Use the resolved purchase date basis.',
        ]],
        '_askEvidence' => [
            'initialSql' => 'SELECT purchase_count FROM purchase_data',
            'finalSql' => 'SELECT spend FROM invoice.invoices__t',
            'repairAttempts' => 2,
        ],
    ], 'Build ROI', 12, []);
    repairAssertSame('semantic_conformance', $capturingReview->received['confidenceEvidence']['validatorStage'] ?? null, 'Persistence must receive the internal validator stage.');
    repairAssertSame('semantic_coverage_gap', $capturingReview->received['confidenceEvidence']['failureCategory'] ?? null, 'Persistence must receive the internal failure category.');
    repairAssertSame(['purchase_date_basis'], $capturingReview->received['confidenceEvidence']['unmetRequirementKeys'] ?? null, 'Persistence must receive internal requirement keys.');
    repairAssertSame('generation-1', $finalized['generationId'] ?? null, 'Persisted Ask outcomes must return their generation identifier.');
    repairAssertSame('conversation-1', $finalized['conversationId'] ?? null, 'Persisted Ask outcomes must return their conversation identifier.');
    repairAssertSame(true, $finalized['reviewRequired'] ?? null, 'Exhausted outcomes must be flagged for review.');
    repairAssertSame(null, $capturingReview->received['generatedSql'] ?? null, 'Exhausted rejected SQL must never be persisted as executable generated SQL.');
    repairAssertSame(true, is_array($capturingReview->received['initialStructure'] ?? null), 'Persistence must receive the exhausted initial candidate structure.');
    repairAssertSame(true, is_array($capturingReview->received['finalStructure'] ?? null), 'Persistence must receive the exhausted last-candidate structure.');
    repairAssertSame(true, $capturingReview->received['materialRepair'] ?? null, 'Persistence must classify a structurally changed exhausted repair.');
    repairAssertSame(false, isset($finalized['validationSummary']['validatorStage']), 'Ordinary responses must omit internal validator stages.');
    repairAssertSame(false, isset($finalized['validationSummary']['failureCategory']), 'Ordinary responses must omit internal failure categories.');
    repairAssertSame(false, isset($finalized['unmetRequirements']), 'Ordinary responses must omit internal requirement keys.');
    repairAssertSame(false, isset($finalized['_askEvidence']), 'Ordinary exhausted responses must omit the trusted evidence envelope.');
    repairAssertNotContains('purchase_count FROM purchase_data', json_encode($finalized), 'Ordinary exhausted responses must not contain the initial rejected SQL.');
    repairAssertNotContains('spend FROM invoice.invoices__t', json_encode($finalized), 'Ordinary exhausted responses must not contain the last rejected SQL.');

    $capturingReview->failure = new RuntimeException('database unavailable');
    $safeSql = $finalize->invoke($finalizingController, [
        'mode' => 'exploratory',
        'route' => 'exploratory_legacy_freeform',
        'sql' => 'SELECT title FROM inventory.instance__t',
        'validationSummary' => ['status' => 'validated'],
        'semanticValidation' => [
            'status' => 'validated',
            'checkedRequirements' => [['key' => 'private_semantic_rule']],
        ],
        '_askEvidence' => [
            'initialSql' => 'SELECT id FROM inventory.item__t',
            'finalSql' => 'SELECT title FROM inventory.instance__t',
            'repairAttempts' => 1,
            'queryFamily' => 'trusted_exploratory_family',
            'modelName' => 'trusted-model',
            'promptVersion' => 'trusted-prompt.v1',
            'schemaMetadata' => ['version' => 'schema-v1'],
            'referenceBundleMetadata' => ['version' => 'bundle-v1', 'hash' => 'bundle-hash'],
        ],
    ], 'Show titles', 12, []);
    repairAssertSame('SELECT title FROM inventory.instance__t', $safeSql['sql'] ?? null, 'Persistence failure must not strip otherwise safe SQL.');
    repairAssertSame(false, isset($safeSql['generationId']), 'Identifiers must only be returned when persistence succeeds.');
    repairAssertSame(false, isset($safeSql['semanticValidation']), 'Ordinary validated responses must not expose internal semantic requirement keys.');
    repairAssertSame(true, $capturingReview->received['materialRepair'] ?? null, 'Persistence must classify the genuine Gemini repair as material even without a controller repair.');
    repairAssertSame('trusted_exploratory_family', $capturingReview->received['queryFamily'] ?? null, 'Persistence must receive the trusted generation family.');
    repairAssertSame('trusted-model', $capturingReview->received['provenance']['modelName'] ?? null, 'Persistence must receive trusted generation provenance.');
    repairAssertSame(false, isset($safeSql['_askEvidence']), 'Ordinary responses must not expose trusted internal candidate or provenance fields.');

    $provenancePreflightCalls = 0;
    $provenanceRepair = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT broken FROM inventory.item__t',
            'mode' => 'exploratory',
            'route' => 'exploratory_legacy_freeform',
            'repairAttempts' => 0,
            '_askEvidence' => [
                'initialSql' => 'SELECT original FROM inventory.item__t',
                'finalSql' => 'SELECT broken FROM inventory.item__t',
                'repairAttempts' => 0,
                'queryFamily' => 'inventory_library_location_listing',
                'compilerVersion' => 'family_compiler_v1',
                'modelName' => 'trusted-model',
                'promptVersion' => 'trusted-prompt.v1',
                'schemaMetadata' => ['version' => 'schema-v1'],
                'referenceBundleMetadata' => ['version' => 'bundle-v1', 'hash' => 'bundle-hash'],
            ],
        ],
        'Show available items',
        'Smith College',
        function () use (&$provenancePreflightCalls): array {
            $provenancePreflightCalls++;
            return $provenancePreflightCalls === 1
                ? ['error' => 'column "broken" does not exist']
                : ['rows' => 1];
        },
        function (): array {
            return [
                'sql' => 'SELECT id FROM inventory.item__t',
                'repairAttempts' => 1,
                '_askEvidence' => [
                    'finalSql' => 'SELECT id FROM inventory.item__t',
                    'repairAttempts' => 1,
                ],
            ];
        }
    );
    repairAssertSame('inventory_library_location_listing', $provenanceRepair['_askEvidence']['queryFamily'] ?? null, 'Controller repair must preserve the older trusted family key.');
    repairAssertSame('bundle-hash', $provenanceRepair['_askEvidence']['referenceBundleMetadata']['hash'] ?? null, 'Controller repair must preserve older trusted reference provenance.');
    repairAssertSame('family_compiler_v1', $provenanceRepair['_askEvidence']['compilerVersion'] ?? null, 'Controller repair must preserve older trusted compiler provenance.');
    repairAssertSame('SELECT original FROM inventory.item__t', $provenanceRepair['_askEvidence']['initialSql'] ?? null, 'Controller repair must preserve the genuine original candidate.');
    repairAssertSame('SELECT id FROM inventory.item__t', $provenanceRepair['_askEvidence']['finalSql'] ?? null, 'Controller repair must update the genuine final candidate.');
    repairAssertSame(1, $provenanceRepair['_askEvidence']['repairAttempts'] ?? null, 'Controller repair must update the actual repair count.');

    $postGenerationEvidence = [
        'initialSql' => 'SELECT id FROM inventory.item__t',
        'finalSql' => 'SELECT id, holdings_record_id FROM inventory.item__t',
        'repairAttempts' => 1,
        'queryFamily' => 'inventory_library_location_listing',
        'compilerVersion' => 'family_compiler_v1',
        'modelName' => 'trusted-model',
        'promptVersion' => 'trusted-prompt.v1',
        'schemaMetadata' => ['version' => 'schema-v1'],
        'referenceBundleMetadata' => ['version' => 'bundle-v1', 'hash' => 'bundle-hash'],
    ];

    Yii::$app->response->statusCode = 200;
    $ordinaryDatabaseRecovery = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT id, holdings_record_id FROM inventory.item__t',
            'mode' => 'canonical',
            'route' => 'canonical',
            'repairAttempts' => 1,
            '_askEvidence' => $postGenerationEvidence,
        ],
        'Show available items',
        'Smith College',
        function (): array {
            return ['error' => 'column "holdings_record_id" does not exist'];
        }
    );
    $ordinaryRecoveryRecorder = new CapturingAdministratorReviewService();
    $ordinaryRecoveryController = new TestableFolioQueryController('folio-query', null);
    $ordinaryRecoveryController->reviewService = $ordinaryRecoveryRecorder;
    $ordinaryRecoveryFinalize = new ReflectionMethod($ordinaryRecoveryController, 'finalizeAskResponse');
    $finalizedOrdinaryRecovery = $ordinaryRecoveryFinalize->invoke(
        $ordinaryRecoveryController,
        $ordinaryDatabaseRecovery,
        'Show available items',
        12,
        []
    );
    repairAssertSame('inventory_library_location_listing', $ordinaryRecoveryRecorder->received['queryFamily'] ?? null, 'Ordinary database recovery persistence must retain family evidence.');
    repairAssertSame('trusted-model', $ordinaryRecoveryRecorder->received['provenance']['modelName'] ?? null, 'Ordinary database recovery persistence must retain model provenance.');
    repairAssertSame('trusted-prompt.v1', $ordinaryRecoveryRecorder->received['provenance']['promptVersion'] ?? null, 'Ordinary database recovery persistence must retain prompt provenance.');
    repairAssertSame('schema-v1', $ordinaryRecoveryRecorder->received['provenance']['schemaMetadata']['version'] ?? null, 'Ordinary database recovery persistence must retain schema provenance.');
    repairAssertSame('bundle-hash', $ordinaryRecoveryRecorder->received['provenance']['referenceBundleMetadata']['hash'] ?? null, 'Ordinary database recovery persistence must retain reference provenance.');
    repairAssertSame('family_compiler_v1', $ordinaryRecoveryRecorder->received['provenance']['compilerVersion'] ?? null, 'Ordinary database recovery persistence must retain compiler provenance.');
    repairAssertSame('rejected', $ordinaryRecoveryRecorder->received['validationStatus'] ?? null, 'Ordinary database recovery persistence must record the failed candidate as rejected.');
    repairAssertSame(true, $ordinaryRecoveryRecorder->received['reviewRequired'] ?? null, 'Ordinary database recovery must require administrator review.');
    repairAssertSame(true, in_array('unable_to_validate', $ordinaryRecoveryRecorder->received['reviewReasons'] ?? [], true), 'Ordinary database recovery must include the unable-to-validate review reason.');
    repairAssertSame(null, $ordinaryRecoveryRecorder->received['generatedSql'] ?? null, 'Ordinary database recovery must not persist rejected SQL as executable.');
    repairAssertSame(null, $ordinaryRecoveryRecorder->received['sqlHash'] ?? null, 'Ordinary database recovery must not persist a rejected SQL hash.');
    repairAssertSame(true, is_array($ordinaryRecoveryRecorder->received['initialStructure'] ?? null), 'Ordinary database recovery persistence must retain initial candidate structure.');
    repairAssertSame(true, is_array($ordinaryRecoveryRecorder->received['finalStructure'] ?? null), 'Ordinary database recovery persistence must retain final candidate structure.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['_askEvidence']), 'Ordinary database recovery must strip the internal envelope after persistence.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['sql']), 'Ordinary database recovery must not expose generated SQL.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['validationStatus']), 'Ordinary database recovery must not expose validator status.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['validationSummary']), 'Ordinary database recovery must not expose validator details.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['failureCategory']), 'Ordinary database recovery must not expose validator category.');
    repairAssertSame(true, $finalizedOrdinaryRecovery['reviewRequired'] ?? null, 'Ordinary database recovery may expose only the designed review signal.');
    repairAssertSame(200, Yii::$app->response->statusCode, 'Ordinary database recovery must preserve its HTTP 200 status.');
    repairAssertSame('I could not build a report I could safely run. Your request is preserved, and you can retry it or adjust one part of the question.', $finalizedOrdinaryRecovery['message'] ?? null, 'Ordinary database recovery must preserve its continuation copy.');

    foreach (['connectivity', 'policy'] as $postGenerationOutcome) {
        Yii::$app->response->statusCode = 200;
        $recovery = $validateAndRepair->invoke(
            $controller,
            [
                'sql' => 'SELECT id, holdings_record_id FROM inventory.item__t',
                'mode' => 'exploratory',
                'route' => 'exploratory_legacy_freeform',
                'repairAttempts' => 1,
                '_askEvidence' => $postGenerationEvidence,
            ],
            'Show available items',
            'Smith College',
            function () use ($postGenerationOutcome): array {
                return $postGenerationOutcome === 'connectivity'
                    ? ['error' => 'SQLSTATE[08006] [7] timeout expired']
                    : ['error' => 'SQLSTATE[42501]: permission denied'];
            }
        );
        $outcomeRecorder = new CapturingAdministratorReviewService();
        $outcomeController = new TestableFolioQueryController('folio-query', null);
        $outcomeController->reviewService = $outcomeRecorder;
        $outcomeFinalize = new ReflectionMethod($outcomeController, 'finalizeAskResponse');
        $finalizedOutcome = $outcomeFinalize->invoke(
            $outcomeController,
            $recovery,
            'Show available items',
            12,
            []
        );
        repairAssertSame('bundle-hash', $outcomeRecorder->received['provenance']['referenceBundleMetadata']['hash'] ?? null, ucfirst($postGenerationOutcome) . ' recovery persistence must retain reference provenance.');
        repairAssertSame('inventory_library_location_listing', $outcomeRecorder->received['queryFamily'] ?? null, ucfirst($postGenerationOutcome) . ' recovery persistence must retain family evidence.');
        repairAssertSame(true, is_array($outcomeRecorder->received['finalStructure'] ?? null), ucfirst($postGenerationOutcome) . ' recovery persistence must retain final candidate structure.');
        repairAssertSame(false, isset($finalizedOutcome['_askEvidence']), ucfirst($postGenerationOutcome) . ' recovery must strip the internal envelope after persistence.');
        repairAssertSame(false, isset($finalizedOutcome['sql']), ucfirst($postGenerationOutcome) . ' recovery must not expose generated SQL.');
        repairAssertSame($postGenerationOutcome === 'policy' ? 403 : 200, Yii::$app->response->statusCode, ucfirst($postGenerationOutcome) . ' recovery must preserve its response status.');
    }

    Yii::$app->response->statusCode = 200;
    $continuation = new ReflectionMethod($controller, 'buildAskContinuationFromFailure');
    $cancellationRecovery = $continuation->invoke(
        $controller,
        new \app\exceptions\DatabaseQueryCancelledException(),
        'Show available items',
        'Smith College'
    );
    $attachEvidence = new ReflectionMethod($controller, 'attachTrustedAskEvidence');
    $cancellationRecovery = $attachEvidence->invoke($controller, $cancellationRecovery, [
        'sql' => 'SELECT id, holdings_record_id FROM inventory.item__t',
        '_askEvidence' => $postGenerationEvidence,
    ]);
    $cancellationRecorder = new CapturingAdministratorReviewService();
    $cancellationController = new TestableFolioQueryController('folio-query', null);
    $cancellationController->reviewService = $cancellationRecorder;
    $cancellationFinalize = new ReflectionMethod($cancellationController, 'finalizeAskResponse');
    $finalizedCancellation = $cancellationFinalize->invoke(
        $cancellationController,
        $cancellationRecovery,
        'Show available items',
        12,
        []
    );
    repairAssertSame('trusted-model', $cancellationRecorder->received['provenance']['modelName'] ?? null, 'Cancellation recovery persistence must retain model provenance.');
    repairAssertSame('family_compiler_v1', $cancellationRecorder->received['provenance']['compilerVersion'] ?? null, 'Cancellation recovery persistence must retain compiler provenance.');
    repairAssertSame(true, is_array($cancellationRecorder->received['finalStructure'] ?? null), 'Cancellation recovery persistence must retain final candidate structure.');
    repairAssertSame(false, isset($finalizedCancellation['_askEvidence']), 'Cancellation recovery must strip the internal envelope after persistence.');
    repairAssertSame(false, isset($finalizedCancellation['sql']), 'Cancellation recovery must not expose generated SQL.');
    repairAssertSame(503, Yii::$app->response->statusCode, 'Cancellation recovery must preserve its 503 response status.');

    $freshSemanticPreflightCalls = 0;
    $staleSemanticRepairCalls = 0;
    $staleSemantic = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT broken_count FROM purchase_data',
            'mode' => 'exploratory',
            'route' => 'exploratory_legacy_freeform',
            'semanticContractApplicable' => true,
            'semanticValidation' => [
                'status' => 'validated',
                'contractVersion' => 1,
                'checkedRequirements' => [['key' => 'purchase_count', 'label' => 'Count purchases.']],
            ],
            'repairAttempts' => 1,
        ],
        $roiQuestion,
        'Smith College',
        function () use (&$freshSemanticPreflightCalls): array {
            $freshSemanticPreflightCalls++;
            return $freshSemanticPreflightCalls === 1
                ? ['error' => 'column "broken_count" does not exist']
                : ['rows' => 1];
        },
        function () use (&$staleSemanticRepairCalls): array {
            $staleSemanticRepairCalls++;
            return [
                'sql' => 'SELECT purchase_count FROM purchase_data',
                'semanticContractApplicable' => true,
                'repairAttempts' => 2,
            ];
        }
    );
    repairAssertSame(1, $staleSemanticRepairCalls, 'A database-rejected candidate should use the one remaining repair attempt.');
    repairAssertSame(1, $freshSemanticPreflightCalls, 'Repaired SQL without fresh semantic validation must not reach preflight.');
    repairAssertSame(false, isset($staleSemantic['sql']), 'Repaired SQL must not inherit an earlier candidate semantic checklist.');
    repairAssertSame(2, $staleSemantic['validationSummary']['repairAttempts'] ?? null, 'Semantic boundary recovery must preserve the exact shared repair count.');

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

    $actionRawQuestion = 'Use invoice date instead.';
    $actionGenerationPrompt = implode("\n\n", [
        'This is a follow-up request to a previously generated library report.',
        'Previous request: Show purchases and circulation ROI by call number.',
        'Follow-up request: ' . $actionRawQuestion,
        "Reference resolver guidance:\n- Resolved local reference: use exactly inventory.material_type__t.name = 'E-Book'.",
    ]);
    \app\services\GeminiService::$generationCalls = [];
    \app\services\GeminiService::$preflightRepairCalls = [];
    \app\services\GeminiService::$generationTransport = [
        'rawQuestion' => $actionRawQuestion,
        'generationPrompt' => $actionGenerationPrompt,
    ];
    \app\services\GeminiService::$generationResult = [
        'sql' => 'SELECT broken_column FROM inventory.instance__t',
        'mode' => 'exploratory',
        'route' => 'exploratory_legacy_freeform',
        'routeReason' => 'unsupported_query_family',
        'repairAttempts' => 0,
        'referenceResolver' => [
            'resolved' => true,
            'guidanceLines' => [
                "- Resolved local reference: use exactly inventory.material_type__t.name = 'E-Book'.",
            ],
        ],
    ];
    \app\services\SqlPreflightService::$calls = [];
    \app\services\SqlPreflightService::$results = [
        ['error' => 'column "broken_column" does not exist'],
        ['rows' => 2, 'cost' => 3.0],
    ];
    Yii::$app->request = new TestRequest([
        'prompt' => $actionRawQuestion,
        'campus' => 'Smith College',
        'includeSuggestions' => false,
    ]);
    $actionReview = new CapturingAdministratorReviewService();
    $actionController = new TestableFolioQueryController('folio-query', null);
    $actionController->reviewService = $actionReview;
    $actionResponse = $actionController->actionNl();

    repairAssertSame(1, count(\app\services\GeminiService::$generationCalls), 'actionNl should invoke generation exactly once.');
    repairAssertSame(
        $actionRawQuestion,
        \app\services\GeminiService::$generationCalls[0]['rawQuestion'] ?? null,
        'actionNl generation must receive the exact raw latest question.'
    );
    repairAssertSame(
        $actionRawQuestion,
        \app\services\GeminiService::$generationCalls[0]['generationPrompt'] ?? null,
        'A non-follow-up action should initially generate from the unaugmented raw prompt.'
    );
    repairAssertSame(1, count(\app\services\GeminiService::$preflightRepairCalls), 'actionNl should enter the default post-preflight repair seam once.');
    repairAssertSame(
        $actionRawQuestion,
        \app\services\GeminiService::$preflightRepairCalls[0]['originalQuestion'] ?? null,
        'actionNl post-preflight repair must retain the exact raw question.'
    );
    repairAssertSame(
        $actionGenerationPrompt,
        \app\services\GeminiService::$preflightRepairCalls[0]['generationPrompt'] ?? null,
        'actionNl post-preflight repair must consume the augmented non-response generation transport.'
    );
    repairAssertSame(2, count(\app\services\SqlPreflightService::$calls), 'actionNl should preflight both the initial and repaired candidates.');
    repairAssertSame('SELECT title FROM inventory.instance__t', $actionResponse['sql'] ?? null, 'actionNl should return the repaired SQL after successful re-preflight.');
    repairAssertSame(false, isset($actionResponse['referenceResolver']), 'The finalized actionNl browser response must omit internal resolver guidance.');
    repairAssertNotContains(
        "inventory.material_type__t.name = 'E-Book'",
        json_encode($actionResponse),
        'The finalized actionNl browser response must omit the distinctive resolver schema predicate.'
    );

    $controllerSource = file_get_contents(__DIR__ . '/../controllers/FolioQueryController.php');
    repairAssertSame(
        1,
        preg_match('/generateSqlWithShadow\(\s*\$prompt,\s*\$campus\s*\?:\s*null,\s*\$userId,\s*\$allowExploratory,\s*\$effectivePrompt,\s*\$generationTransport/s', $controllerSource),
        'Ask generation must cross the service boundary with separate raw and model-only prompts plus non-response transport context.'
    );
    repairAssertSame(
        1,
        preg_match('/validateAndRepairNlResult\(\s*\$result,\s*\$prompt,\s*\$campus\s*\?:\s*null,\s*null,\s*null,\s*\$generationPrompt/s', $controllerSource),
        'Ask must pass the raw question and consumed model-only generation context to post-preflight repair.'
    );
    repairAssertSame(
        1,
        preg_match('/unset\(\$generationTransport\)/', $controllerSource),
        'Ask must consume the internal generation transport instead of serializing it into the final response.'
    );

    fwrite(STDOUT, "FolioQueryController exploratory repair test passed\n");
}
