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
            $singleStatement = rtrim($trimmed, " \t\r\n;");
            if (preg_match('/^(?:SELECT|WITH)\b/i', $trimmed) !== 1
                || preg_match('/^DELETE\b/i', $trimmed) === 1
                || strpos($singleStatement, ';') !== false
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
        public static $generationFailure;
        public static $preflightRepairResult;
        public static $preflightRepairFailure;

        public static function isAiTimeoutMessage($message): bool
        {
            return preg_match('/timeout|timed out|deadline exceeded|operation timed out/i', (string)$message) === 1;
        }

        public static function isAiProviderFailureMessage($message): bool
        {
            return preg_match(
                '/AI API error:|AI request failed:|OpenAI fallback (?:request )?failed:|MAX_TOKENS|AI (?:intent )?response was truncated/i',
                (string)$message
            ) === 1;
        }

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
            if (self::$generationFailure instanceof \Throwable) {
                throw self::$generationFailure;
            }
            $generationTransport = self::$generationTransport;
            return self::$generationResult;
        }

        public static function repairExploratorySqlAfterPreflight(
            string $originalQuestion,
            $campus,
            array $currentResult,
            string $preflightError,
            ?string $generationPrompt = null,
            array $resolvedFilters = [],
            array $preflightResult = []
        ): array {
            self::$preflightRepairCalls[] = [
                'originalQuestion' => $originalQuestion,
                'campus' => $campus,
                'currentResult' => $currentResult,
                'preflightError' => $preflightError,
                'generationPrompt' => $generationPrompt,
                'preflightResult' => $preflightResult,
            ];

            if (self::$preflightRepairFailure instanceof \Throwable) {
                throw self::$preflightRepairFailure;
            }

            return self::$preflightRepairResult ?? [
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

    function repairAssertPublicHardFailure(array $response, string $name): void
    {
        foreach ([
            'sql',
            'generationProvenance',
            'provenanceLabel',
            'needsClarification',
            'clarificationItems',
            'correctionInstruction',
            'recoveryContext',
            'recoveryItems',
            'attemptedPlan',
            'suggestions',
        ] as $forbiddenField) {
            repairAssertSame(
                false,
                array_key_exists($forbiddenField, $response),
                $name . ' must omit forbidden response field ' . $forbiddenField . '.'
            );
        }
        repairAssertSame(false, ($response['route'] ?? null) === 'clarification', $name . ' must not become clarification.');
        repairAssertSame(false, ($response['route'] ?? null) === 'exploratory_recovery', $name . ' must not become recovery.');
        $encoded = strtolower((string)json_encode($response));
        repairAssertNotContains('request is preserved', $encoded, $name . ' must not emit legacy request-preserved copy.');
        repairAssertNotContains('what still needs to be resolved', $encoded, $name . ' must not emit resolver blocker copy.');
    }

    require_once __DIR__ . '/../exceptions/ExploratorySqlValidationException.php';
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
            'mode' => 'canonical',
            'route' => 'builder_intent',
            'generationProvenance' => 'verified_pattern',
            'provenanceLabel' => 'Verified pattern',
            'repairAttempts' => 0,
            'assumptions' => [['key' => 'purchase_date_basis', 'value' => 'payment_date']],
            'attemptedPlan' => 'Aggregate investment before joining circulation.',
            'suggestions' => ['Use a shorter reporting window.'],
            'referenceResolver' => ['trace' => 'internal resolver trace'],
            '_askEvidence' => [
                'initialSql' => 'SELECT original_column FROM inventory.instance__t',
                'finalSql' => 'SELECT missing_column FROM inventory.instance__t',
                'repairAttempts' => 0,
                'referenceBundleMetadata' => ['version' => 'bundle-v1', 'hash' => 'bundle-hash'],
            ],
        ],
        'Compare investment and circulation ROI',
        'Smith College',
        function (): array { return ['error' => 'column "missing_column" does not exist']; },
        function () use (&$exhaustedRepairCalls): array {
            $exhaustedRepairCalls++;
            if ($exhaustedRepairCalls === 1) {
                return [
                    'sql' => 'SELECT missing_column FROM inventory.instance__t',
                    'repairAttempts' => 1,
                    'generationProvenance' => 'ai_built',
                    'provenanceLabel' => 'AI-built',
                ];
            }
            return [
                'repairAttempts' => 2,
                'attemptedPlan' => 'Rejected internal plan.',
                'suggestions' => ['Change the SQL filter.'],
                'correctionExample' => 'Use a private predicate.',
            ];
        }
    );

    repairAssertSame(2, $exhaustedRepairCalls, 'Canonical database failures must consume the shared two-repair budget before exhaustion.');
    repairAssertSame(false, array_key_exists('sql', $exhausted), 'Exhaustion must never include SQL.');
    repairAssertSame('sql_generation_failed', $exhausted['errorType'] ?? null, 'Exhaustion should expose the concise SQL-generation failure type.');
    repairAssertSame('generation_failed', $exhausted['route'] ?? null, 'Exhaustion must not route through exploratory recovery.');
    repairAssertSame('sql_repair_exhausted', $exhausted['routeReason'] ?? null, 'Exhaustion should retain a stable machine-readable reason.');
    repairAssertSame('exhausted', $exhausted['validationSummary']['status'] ?? null, 'Exhaustion should expose its validation status.');
    repairAssertSame(2, $exhausted['validationSummary']['repairAttempts'] ?? null, 'Exhaustion should report the actual repair count.');
    repairAssertSame('SELECT original_column FROM inventory.instance__t', $exhausted['_askEvidence']['initialSql'] ?? null, 'Controller exhaustion must retain trusted initial candidate evidence until finalization.');
    repairAssertSame('bundle-hash', $exhausted['_askEvidence']['referenceBundleMetadata']['hash'] ?? null, 'Controller exhaustion must retain trusted provenance until finalization.');
    repairAssertSame(
        'Report Explorer could not safely run this report. Please retry.',
        $exhausted['message'] ?? null,
        'Exhaustion should use concise Retry-oriented copy.'
    );
    foreach ([
        'recoveryContext',
        'recoveryItems',
        'attemptedPlan',
        'suggestions',
        'semanticValidation',
        'referenceResolver',
        'correctionExample',
        'generationProvenance',
        'provenanceLabel',
    ] as $forbiddenField) {
        repairAssertSame(false, array_key_exists($forbiddenField, $exhausted), 'Exhaustion must omit recovery, correction, resolver, and provenance fields.');
    }
    $exhaustedJson = json_encode($exhausted);
    repairAssertNotContains('request is preserved', strtolower($exhaustedJson), 'Exhaustion must not promise request preservation.');
    repairAssertNotContains('missing_column', $exhaustedJson, 'Exhaustion must not expose rejected SQL.');
    repairAssertNotContains('internal resolver trace', $exhaustedJson, 'Exhaustion must not expose resolver traces.');

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
    repairAssertSame('sql_generation_failed', $semanticExhausted['errorType'] ?? null, 'Semantic repair exhaustion should use the same concise SQL-generation failure type.');
    repairAssertSame('generation_failed', $semanticExhausted['route'] ?? null, 'Semantic repair exhaustion must not use exploratory recovery.');
    repairAssertSame(
        'Report Explorer could not safely run this report. Please retry.',
        $semanticExhausted['message'] ?? null,
        'Semantic repair exhaustion should use concise Retry-oriented copy.'
    );
    repairAssertSame(false, array_key_exists('sql', $semanticExhausted), 'Semantic controller recovery must not expose rejected SQL.');
    repairAssertSame(false, array_key_exists('semanticValidation', $semanticExhausted), 'Semantic controller recovery must not expose stale candidate validation.');
    foreach (['unmetRequirements', 'assumptions', 'attemptedPlan', 'suggestions', 'recoveryContext'] as $forbiddenField) {
        repairAssertSame(false, array_key_exists($forbiddenField, $semanticExhausted), 'Semantic exhaustion must omit recovery and correction fields.');
    }
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

    foreach ([
        'SQLSTATE[08006] [7] timeout expired',
        'SQLSTATE[08003]: connection does not exist',
        'connection does not exist',
    ] as $connectivityFailure) {
        $connectivityRepairCalls = 0;
        $connectivity = $validateAndRepair->invoke(
            $controller,
            ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'exploratory', 'repairAttempts' => 0],
            'Show titles',
            'Smith College',
            function () use ($connectivityFailure): array { return ['error' => $connectivityFailure]; },
            function () use (&$connectivityRepairCalls): array {
                $connectivityRepairCalls++;
                return [];
            }
        );

        repairAssertSame(0, $connectivityRepairCalls, 'Connectivity failures must not trigger SQL repair.');
        repairAssertSame('postgres_connectivity', $connectivity['errorType'] ?? null, 'Connectivity failures should remain distinct.');
        repairAssertSame(true, strpos($connectivity['message'] ?? '', 'VPN') !== false, 'Connectivity recovery should continue to mention VPN.');
    }

    Yii::$app->response->statusCode = 200;
    $structuredConnectivityRepairCalls = 0;
    $structuredConnectivity = $validateAndRepair->invoke(
        $controller,
        ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'canonical', 'repairAttempts' => 0],
        'Show titles',
        'Smith College',
        function (): array {
            return [
                'error' => 'invalid frontend protocol message',
                'sqlState' => '08P01',
                'sqlStateClass' => '08',
            ];
        },
        function () use (&$structuredConnectivityRepairCalls): array {
            $structuredConnectivityRepairCalls++;
            return ['sql' => 'SELECT should_not_run'];
        }
    );
    repairAssertSame(0, $structuredConnectivityRepairCalls, 'Structured SQLSTATE class 08 must stop before controller AI repair.');
    repairAssertSame('postgres_connectivity', $structuredConnectivity['errorType'] ?? null, 'Structured SQLSTATE class 08 must retain connectivity behavior.');
    repairAssertSame(200, Yii::$app->response->statusCode, 'Structured SQLSTATE class 08 must retain the connectivity response status.');

    $canonicalRepairCalls = 0;
    $canonicalPreflightSql = [];
    $canonicalFailure = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT broken_column FROM inventory.instance__t',
            'mode' => 'canonical',
            'route' => 'builder_intent',
            'routeReason' => 'family_contract_supported:inventory_listing',
            'generationProvenance' => 'verified_pattern',
            'provenanceLabel' => 'Verified pattern',
        ],
        'Show titles',
        'Smith College',
        function (string $sql) use (&$canonicalPreflightSql): array {
            $canonicalPreflightSql[] = $sql;
            return strpos($sql, 'broken_column') !== false
                ? ['error' => 'column "broken_column" does not exist']
                : [];
        },
        function () use (&$canonicalRepairCalls): array {
            $canonicalRepairCalls++;
            return [
                'sql' => 'SELECT title FROM inventory.instance__t',
                'mode' => 'exploratory',
                'route' => 'exploratory',
            ];
        }
    );
    repairAssertSame(1, $canonicalRepairCalls, 'Canonical preflight failure must enter seeded AI repair.');
    repairAssertSame(
        ['SELECT broken_column FROM inventory.instance__t', 'SELECT title FROM inventory.instance__t'],
        $canonicalPreflightSql,
        'Canonical repair must preflight exactly the initial and repaired SQL candidates.'
    );
    repairAssertSame('ai_built', $canonicalFailure['generationProvenance'] ?? null, 'Repaired canonical SQL is AI-built.');
    repairAssertSame('SELECT title FROM inventory.instance__t', $canonicalFailure['sql'] ?? null, 'Repaired SQL must pass a second preflight.');

    foreach ([
        'SQLSTATE[53200]: Out of memory',
        'SQLSTATE[53400]: Configuration limit exceeded',
        'SQLSTATE[54001]: Statement too complex',
        'stack depth limit exceeded',
        'program limit exceeded',
        'Query is too complex for the configured preflight limit',
        'Estimated query cost exceeds configured limit',
    ] as $resourceFailure) {
        $resourceRepairCalls = 0;
        $resourceResponse = $validateAndRepair->invoke(
            $controller,
            [
                'sql' => 'SELECT title FROM inventory.instance__t',
                'mode' => 'canonical',
                'route' => 'builder_intent',
                'generationProvenance' => 'verified_pattern',
            ],
            'Show titles',
            'Smith College',
            function () use ($resourceFailure): array {
                return ['error' => $resourceFailure];
            },
            function () use (&$resourceRepairCalls): array {
                $resourceRepairCalls++;
                return ['sql' => 'SELECT should_not_run'];
            }
        );
        repairAssertSame(0, $resourceRepairCalls, 'Database resource-limit failures must never invoke AI repair.');
        repairAssertSame('database_resource_limit', $resourceResponse['errorType'] ?? null, 'Database resource-limit failures must retain a distinct hard-stop type.');
        repairAssertSame('database_resource_limit', $resourceResponse['route'] ?? null, 'Database resource-limit failures must not use a recovery route.');
        repairAssertSame(503, Yii::$app->response->statusCode, 'Database resource-limit failures must retain service-unavailable status.');
        repairAssertSame(false, isset($resourceResponse['sql']), 'Database resource-limit hard stops must not expose rejected SQL.');
    }

    foreach ([
        'SQLSTATE[28P01]: password authentication failed',
        'password authentication failed for user report_reader',
    ] as $authorizationFailure) {
        Yii::$app->response->statusCode = 200;
        $authorizationRepairCalls = 0;
        $authorizationResponse = $validateAndRepair->invoke(
            $controller,
            ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'canonical', 'repairAttempts' => 0],
            'Show titles',
            'Smith College',
            function () use ($authorizationFailure): array { return ['error' => $authorizationFailure]; },
            function () use (&$authorizationRepairCalls): array {
                $authorizationRepairCalls++;
                return ['sql' => 'SELECT should_not_run'];
            }
        );
        repairAssertSame(0, $authorizationRepairCalls, 'Database authentication failures must not trigger SQL repair.');
        repairAssertSame('blocked', $authorizationResponse['route'] ?? null, 'Database authentication failures must retain policy handling.');
        repairAssertSame(403, Yii::$app->response->statusCode, 'Database authentication failures must retain forbidden status.');
        repairAssertSame(false, isset($authorizationResponse['sql']), 'Database authentication failures must not expose SQL.');
    }

    Yii::$app->response->statusCode = 200;
    $structuredAuthorizationRepairCalls = 0;
    $structuredAuthorization = $validateAndRepair->invoke(
        $controller,
        ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'canonical', 'repairAttempts' => 0],
        'Show titles',
        'Smith College',
        function (): array {
            return [
                'error' => 'role "report_reader" is not permitted to log in',
                'sqlState' => '28000',
                'sqlStateClass' => '28',
            ];
        },
        function () use (&$structuredAuthorizationRepairCalls): array {
            $structuredAuthorizationRepairCalls++;
            return ['sql' => 'SELECT should_not_run'];
        }
    );
    repairAssertSame(0, $structuredAuthorizationRepairCalls, 'Structured SQLSTATE class 28 must stop before controller AI repair.');
    repairAssertSame('blocked', $structuredAuthorization['route'] ?? null, 'Structured SQLSTATE class 28 must retain policy behavior.');
    repairAssertSame(403, Yii::$app->response->statusCode, 'Structured SQLSTATE class 28 must retain forbidden status.');

    Yii::$app->response->statusCode = 200;
    $structuredPrivilegeRepairCalls = 0;
    $structuredPrivilege = $validateAndRepair->invoke(
        $controller,
        ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'canonical', 'repairAttempts' => 0],
        'Show titles',
        'Smith College',
        function (): array {
            return [
                'error' => 'opaque database failure',
                'sqlState' => '42501',
                'sqlStateClass' => '42',
            ];
        },
        function () use (&$structuredPrivilegeRepairCalls): array {
            $structuredPrivilegeRepairCalls++;
            return ['sql' => 'SELECT should_not_run'];
        }
    );
    repairAssertSame(0, $structuredPrivilegeRepairCalls, 'Exact structured SQLSTATE 42501 must stop before controller AI repair.');
    repairAssertSame('blocked', $structuredPrivilege['route'] ?? null, 'Exact structured SQLSTATE 42501 must retain policy behavior.');
    repairAssertSame(403, Yii::$app->response->statusCode, 'Exact structured SQLSTATE 42501 must retain forbidden status.');

    foreach ([
        ['53100', 'could not write to temporary file: No space left on device'],
        ['53300', 'remaining connection slots are reserved for roles with the SUPERUSER attribute'],
        ['54011', 'target lists can have at most 1664 entries'],
        ['54023', 'cannot pass more than 100 arguments to a function'],
    ] as $structuredResourceCase) {
        Yii::$app->response->statusCode = 200;
        $structuredResourceRepairCalls = 0;
        $structuredResource = $validateAndRepair->invoke(
            $controller,
            ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'canonical', 'repairAttempts' => 0],
            'Show titles',
            'Smith College',
            function () use ($structuredResourceCase): array {
                return [
                    'error' => $structuredResourceCase[1],
                    'sqlState' => $structuredResourceCase[0],
                    'sqlStateClass' => substr($structuredResourceCase[0], 0, 2),
                ];
            },
            function () use (&$structuredResourceRepairCalls): array {
                $structuredResourceRepairCalls++;
                return ['sql' => 'SELECT should_not_run'];
            }
        );
        repairAssertSame(0, $structuredResourceRepairCalls, 'Structured SQLSTATE classes 53/54 must stop before controller AI repair.');
        repairAssertSame('database_resource_limit', $structuredResource['errorType'] ?? null, 'Structured SQLSTATE classes 53/54 must retain resource-limit behavior.');
        repairAssertSame(503, Yii::$app->response->statusCode, 'Structured SQLSTATE classes 53/54 must retain service-unavailable status.');
    }

    Yii::$app->response->statusCode = 200;
    $structuredAvailabilityRepairCalls = 0;
    $structuredAvailability = $validateAndRepair->invoke(
        $controller,
        ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'canonical', 'repairAttempts' => 0],
        'Show titles',
        'Smith College',
        function (): array {
            return [
                'error' => 'opaque database failure',
                'sqlState' => '57P01',
                'sqlStateClass' => '57',
            ];
        },
        function () use (&$structuredAvailabilityRepairCalls): array {
            $structuredAvailabilityRepairCalls++;
            return ['sql' => 'SELECT should_not_run'];
        }
    );
    repairAssertSame(0, $structuredAvailabilityRepairCalls, 'Structured SQLSTATE 57P01 must stop before controller AI repair.');
    repairAssertSame('postgres_connectivity', $structuredAvailability['errorType'] ?? null, 'Structured SQLSTATE 57P01 must retain database availability behavior.');
    repairAssertSame(200, Yii::$app->response->statusCode, 'Structured SQLSTATE 57P01 must retain the compatibility connectivity status.');

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

    $structuredCancelRepairCalls = 0;
    $structuredCancellationThrown = false;
    try {
        $validateAndRepair->invoke(
            $controller,
            ['sql' => 'SELECT title FROM inventory.instance__t', 'mode' => 'exploratory', 'route' => 'exploratory'],
            'Show titles',
            'Smith College',
            function (): array {
                return [
                    'error' => 'opaque database failure',
                    'sqlState' => '57014',
                    'sqlStateClass' => '57',
                ];
            },
            function () use (&$structuredCancelRepairCalls): array {
                $structuredCancelRepairCalls++;
                return ['sql' => 'SELECT should_not_run'];
            }
        );
    } catch (\Throwable $exception) {
        $structuredCancellationThrown = $exception instanceof \app\exceptions\DatabaseQueryCancelledException
            || $exception->getPrevious() instanceof \app\exceptions\DatabaseQueryCancelledException;
    }
    repairAssertSame(true, $structuredCancellationThrown, 'Exact structured SQLSTATE 57014 must remain a cancellation independent of message text.');
    repairAssertSame(0, $structuredCancelRepairCalls, 'Exact structured SQLSTATE 57014 must never call Gemini repair.');

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
    $advisoryPreflightCalls = 0;
    $advisoryRepairCalls = 0;
    $advisoryResult = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT purchase_count FROM purchase_data ORDER BY purchase_count DESC',
            'mode' => 'exploratory',
            'route' => 'exploratory',
            'generationProvenance' => 'ai_built',
            'semanticContractApplicable' => true,
            'semanticValidation' => [
                'status' => 'advisory',
                'contractVersion' => 1,
                'checkedRequirements' => [],
            ],
            'reviewRequired' => true,
            'repairAttempts' => 2,
        ],
        $roiQuestion,
        'Smith College',
        function () use (&$advisoryPreflightCalls): array {
            $advisoryPreflightCalls++;
            return ['rows' => 1];
        },
        function () use (&$advisoryRepairCalls): array {
            $advisoryRepairCalls++;
            return [];
        }
    );
    repairAssertSame(1, $advisoryPreflightCalls, 'An AI-reviewed semantic advisory must proceed to database preflight.');
    repairAssertSame(0, $advisoryRepairCalls, 'A semantic advisory that passes preflight must not be repaired again.');
    repairAssertSame('SELECT purchase_count FROM purchase_data ORDER BY purchase_count DESC', $advisoryResult['sql'] ?? null, 'A preflighted semantic advisory remains executable.');

    $rejectedSemanticPreflightCalls = 0;
    $rejectedSemanticRepairCalls = 0;
    $repairedSemantic = $validateAndRepair->invoke(
        $controller,
        [
            'sql' => 'SELECT unreviewed_count FROM purchase_data',
            'mode' => 'canonical',
            'route' => 'builder_intent',
            'generationProvenance' => 'verified_pattern',
            'semanticContractApplicable' => true,
            'semanticValidation' => [
                'status' => 'rejected',
                'violations' => [['key' => 'purchase_count', 'guidance' => 'private validator guidance']],
            ],
            'repairAttempts' => 0,
        ],
        $roiQuestion,
        'Smith College',
        function (string $sql) use (&$rejectedSemanticPreflightCalls): array {
            $rejectedSemanticPreflightCalls++;
            repairAssertSame('SELECT purchase_count FROM purchase_data', $sql, 'Raw semantic rejection must not reach database preflight before AI review.');
            return ['rows' => 1];
        },
        function ($question, $campus, array $candidate, string $diagnostic) use (&$rejectedSemanticRepairCalls): array {
            $rejectedSemanticRepairCalls++;
            repairAssertSame('Semantic validation requires AI review.', $diagnostic, 'Raw semantic rejection should enter the repair seam with a safe diagnostic.');
            return [
                'sql' => 'SELECT purchase_count FROM purchase_data',
                'semanticContractApplicable' => true,
                'semanticValidation' => ['status' => 'advisory', 'checkedRequirements' => []],
                'reviewRequired' => true,
                'repairAttempts' => 1,
            ];
        }
    );
    repairAssertSame(1, $rejectedSemanticRepairCalls, 'Raw canonical semantic rejection must enter seeded AI repair.');
    repairAssertSame(1, $rejectedSemanticPreflightCalls, 'The AI-reviewed semantic candidate must be preflighted once.');
    repairAssertSame('ai_built', $repairedSemantic['generationProvenance'] ?? null, 'A semantic repair must be relabeled AI-built.');

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
    repairAssertSame('sql_generation_failed', $missingSemantic['errorType'] ?? null, 'Unreviewed semantic exhaustion should use concise terminal failure.');

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
    repairAssertSame('exhausted', $ordinaryRecoveryRecorder->received['validationStatus'] ?? null, 'Ordinary database repair exhaustion persistence must record the exhausted budget.');
    repairAssertSame(true, $ordinaryRecoveryRecorder->received['reviewRequired'] ?? null, 'Ordinary database recovery must require administrator review.');
    repairAssertSame(true, in_array('unable_to_validate', $ordinaryRecoveryRecorder->received['reviewReasons'] ?? [], true), 'Ordinary database recovery must include the unable-to-validate review reason.');
    repairAssertSame(null, $ordinaryRecoveryRecorder->received['generatedSql'] ?? null, 'Ordinary database recovery must not persist rejected SQL as executable.');
    repairAssertSame(null, $ordinaryRecoveryRecorder->received['sqlHash'] ?? null, 'Ordinary database recovery must not persist a rejected SQL hash.');
    repairAssertSame(true, is_array($ordinaryRecoveryRecorder->received['initialStructure'] ?? null), 'Ordinary database recovery persistence must retain initial candidate structure.');
    repairAssertSame(null, $ordinaryRecoveryRecorder->received['finalStructure'] ?? null, 'Ordinary database repair exhaustion must not persist rejected SQL as the final structure.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['_askEvidence']), 'Ordinary database recovery must strip the internal envelope after persistence.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['sql']), 'Ordinary database recovery must not expose generated SQL.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['validationStatus']), 'Ordinary database recovery must not expose validator status.');
    repairAssertSame('exhausted', $finalizedOrdinaryRecovery['validationSummary']['status'] ?? null, 'Ordinary database exhaustion must expose only the safe no-SQL terminal status.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['recoveryContext']), 'Ordinary database exhaustion must not preserve request text in a recovery context.');
    repairAssertSame(false, isset($finalizedOrdinaryRecovery['failureCategory']), 'Ordinary database recovery must not expose validator category.');
    repairAssertSame(true, $finalizedOrdinaryRecovery['reviewRequired'] ?? null, 'Ordinary database recovery may expose only the designed review signal.');
    repairAssertSame(200, Yii::$app->response->statusCode, 'Ordinary database recovery must preserve its HTTP 200 status.');
    repairAssertSame('Report Explorer could not safely run this report. Please retry.', $finalizedOrdinaryRecovery['message'] ?? null, 'Ordinary database exhaustion must use concise Retry-oriented copy.');

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
        repairAssertSame('rejected', $outcomeRecorder->received['validationStatus'] ?? null, ucfirst($postGenerationOutcome) . ' recovery persistence must record an explicit rejection.');
        repairAssertSame(null, $outcomeRecorder->received['generatedSql'] ?? null, ucfirst($postGenerationOutcome) . ' recovery persistence must not retain executable SQL.');
        repairAssertSame(null, $outcomeRecorder->received['sqlHash'] ?? null, ucfirst($postGenerationOutcome) . ' recovery persistence must not retain an executable SQL hash.');
        repairAssertSame(
            $postGenerationOutcome === 'connectivity',
            $outcomeRecorder->received['reviewRequired'] ?? null,
            ucfirst($postGenerationOutcome) . ' recovery review policy must distinguish inability to validate from a policy block.'
        );
        if ($postGenerationOutcome === 'connectivity') {
            repairAssertSame(true, in_array('unable_to_validate', $outcomeRecorder->received['reviewReasons'] ?? [], true), 'Connectivity recovery must create unable-to-validate administrator review work.');
        }
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
    repairAssertSame('rejected', $cancellationRecorder->received['validationStatus'] ?? null, 'Cancellation recovery persistence must record an explicit rejection.');
    repairAssertSame(null, $cancellationRecorder->received['generatedSql'] ?? null, 'Cancellation recovery persistence must not retain executable SQL.');
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
        'Report Explorer could not safely run this report. Please retry.',
        $unsafe['message'] ?? null,
        'Unsafe recovery should use concise Retry-only copy.'
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
    repairAssertSame(false, isset($unsafeCategory['validationSummary']['failureCategory']), 'Terminal exhaustion must omit validator failure categories.');
    repairAssertNotContains('password=secret', json_encode($unsafeCategory), 'Terminal exhaustion must not expose untrusted database details.');

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
    \app\services\GeminiService::$preflightRepairResult = null;
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

    $untrustedActionExplanation = "Plan: filter with inventory.material_type__t.name = 'E-Book'.";
    \app\services\GeminiService::$generationCalls = [];
    \app\services\GeminiService::$preflightRepairCalls = [];
    \app\services\GeminiService::$generationTransport = [
        'rawQuestion' => $actionRawQuestion,
        'generationPrompt' => $actionGenerationPrompt,
    ];
    \app\services\GeminiService::$generationResult = [
        'sql' => 'SELECT broken_column FROM inventory.instance__t',
        'explanation' => $untrustedActionExplanation,
        'mode' => 'exploratory',
        'route' => 'legacy_freeform',
        'routeReason' => 'primary_legacy_mode',
        'repairAttempts' => 0,
    ];
    \app\services\GeminiService::$preflightRepairResult = [
        'mode' => 'exploratory',
        'route' => 'exploratory_recovery',
        'routeReason' => 'primary_legacy_mode',
        'repairAttempts' => 2,
        'attemptedPlan' => $untrustedActionExplanation,
        'assumptions' => [],
        'suggestions' => [],
        'validationSummary' => [
            'status' => 'exhausted',
            'repairAttempts' => 2,
            'validatorStage' => 'explicit_values',
            'failureCategory' => 'missing_explicit_values',
        ],
        'recoveryContext' => ['originalQuestion' => $actionRawQuestion],
    ];
    \app\services\SqlPreflightService::$calls = [];
    \app\services\SqlPreflightService::$results = [
        ['error' => 'column "broken_column" does not exist'],
    ];
    $actionExhaustionReview = new CapturingAdministratorReviewService();
    $actionExhaustionController = new TestableFolioQueryController('folio-query', null);
    $actionExhaustionController->reviewService = $actionExhaustionReview;
    $actionExhaustionResponse = $actionExhaustionController->actionNl();

    repairAssertSame(1, count(\app\services\GeminiService::$preflightRepairCalls), 'Routed action exhaustion should enter the default repair seam once.');
    repairAssertSame(2, $actionExhaustionResponse['validationSummary']['repairAttempts'] ?? null, 'Final routed action recovery must preserve the shared two-attempt cap.');
    repairAssertSame('sql_generation_failed', $actionExhaustionResponse['errorType'] ?? null, 'Final action exhaustion must expose concise SQL-generation failure.');
    repairAssertSame('generation_failed', $actionExhaustionResponse['route'] ?? null, 'Final action exhaustion must not use exploratory recovery.');
    repairAssertSame(false, isset($actionExhaustionResponse['recoveryContext']), 'Final action exhaustion must not preserve request text.');
    repairAssertSame(false, isset($actionExhaustionResponse['attemptedPlan']), 'Final routed action recovery must omit an attempted plan without trusted provenance.');
    repairAssertNotContains(
        $untrustedActionExplanation,
        json_encode($actionExhaustionResponse),
        'The actual finalized actionNl response must not expose the untrusted model explanation.'
    );
    repairAssertNotContains(
        "inventory.material_type__t.name = 'E-Book'",
        json_encode($actionExhaustionResponse),
        'The actual finalized routed-exhaustion response must not expose the distinctive resolver predicate.'
    );

    foreach ([
        'AI request failed: provider unavailable',
        'MAX_TOKENS',
        'The AI response was truncated because the query is too complex. Try simplifying your request or asking for fewer fields.',
    ] as $providerFailureMessage) {
        \app\services\GeminiService::$preflightRepairFailure = new \RuntimeException($providerFailureMessage);
        \app\services\GeminiService::$preflightRepairCalls = [];
        \app\services\GeminiService::$generationResult = [
            'sql' => 'SELECT broken_column FROM inventory.instance__t',
            'mode' => 'canonical',
            'route' => 'builder_intent',
            'generationProvenance' => 'verified_pattern',
            'repairAttempts' => 0,
        ];
        \app\services\SqlPreflightService::$results = [
            ['error' => 'column "broken_column" does not exist'],
        ];
        Yii::$app->response->statusCode = 200;
        $providerFailureReview = new CapturingAdministratorReviewService();
        $providerFailureController = new TestableFolioQueryController('folio-query', null);
        $providerFailureController->reviewService = $providerFailureReview;
        $providerFailureResponse = $providerFailureController->actionNl();

        repairAssertSame(1, count(\app\services\GeminiService::$preflightRepairCalls), 'Provider failure regression must reach the seeded repair seam exactly once.');
        repairAssertSame('ai_provider_failure', $providerFailureResponse['errorType'] ?? null, 'Provider repair failures must retain a distinct hard-failure type.');
        repairAssertSame('ai_provider_failure', $providerFailureResponse['route'] ?? null, 'Provider repair failures must not use exploratory recovery.');
        repairAssertSame(503, Yii::$app->response->statusCode, 'Provider repair failures must retain service-unavailable status.');
        foreach (['sql', 'correctionInstruction', 'recoveryContext', 'recoveryItems', 'attemptedPlan', 'suggestions'] as $forbiddenField) {
            repairAssertSame(false, array_key_exists($forbiddenField, $providerFailureResponse), 'Provider repair failures must omit SQL, correction, and recovery fields.');
        }
        repairAssertNotContains('request is preserved', strtolower(json_encode($providerFailureResponse)), 'Provider hard failures must not emit legacy recovery copy.');
    }
    \app\services\GeminiService::$preflightRepairFailure = null;
    \app\services\GeminiService::$preflightRepairResult = null;

    \app\services\GeminiService::$generationResult = [
        'sql' => 'SELECT id FROM inventory.item__t',
        'mode' => 'exploratory',
        'route' => 'legacy_freeform',
    ];
    \app\services\SqlPreflightService::$calls = [];
    \app\services\SqlPreflightService::$results = [[]];
    Yii::$app->request = new TestRequest([
        'prompt' => 'Show generic legacy item identifiers.',
        'campus' => 'Smith College',
        'includeSuggestions' => false,
    ]);
    Yii::$app->response->statusCode = 200;
    $genericProvenanceRecorder = new CapturingAdministratorReviewService();
    $genericProvenanceController = new TestableFolioQueryController('folio-query', null);
    $genericProvenanceController->reviewService = $genericProvenanceRecorder;
    $genericProvenanceResponse = $genericProvenanceController->actionNl();
    repairAssertSame('ai_built', $genericProvenanceResponse['generationProvenance'] ?? null, 'Generic public SQL must normalize to AI-built provenance.');
    repairAssertSame('AI-built', $genericProvenanceResponse['provenanceLabel'] ?? null, 'Generic public SQL must derive the AI-built label.');
    repairAssertSame(
        'ai_built',
        $genericProvenanceRecorder->received['provenance']['generationProvenance'] ?? null,
        'Generic executable SQL must persist the same trusted provenance shown to the user.'
    );

    foreach ([true, false] as $twoLaneEnabled) {
        Yii::$app->params['nl2sqlTwoLaneEnabled'] = $twoLaneEnabled;
        \app\services\GeminiService::$generationFailure = new \RuntimeException('unclassified generation exception');
        \app\services\GeminiService::$generationCalls = [];
        \app\services\GeminiService::$preflightRepairCalls = [];
        \app\services\SqlPreflightService::$calls = [];
        \app\services\SqlPreflightService::$results = [];
        Yii::$app->request = new TestRequest([
            'prompt' => 'Show item identifiers after an unexpected generator failure.',
            'campus' => 'Smith College',
            'includeSuggestions' => false,
        ]);
        Yii::$app->response->statusCode = 200;
        $genericFailureController = new TestableFolioQueryController('folio-query', null);
        $genericFailureController->reviewService = new CapturingAdministratorReviewService();
        $genericFailureResponse = $genericFailureController->actionNl();

        repairAssertSame(1, count(\app\services\GeminiService::$generationCalls), 'Generic runtime failures must cross the public generation boundary once.');
        if ($twoLaneEnabled) {
            repairAssertSame('sql_generation_failed', $genericFailureResponse['errorType'] ?? null, 'Enabled two-lane mode must convert an unclassified runtime exception to a typed terminal failure.');
            repairAssertSame('generation_failed', $genericFailureResponse['route'] ?? null, 'Enabled two-lane mode must not select rollback recovery for an unclassified runtime exception.');
            repairAssertSame('Report Explorer could not safely run this report. Please retry.', $genericFailureResponse['message'] ?? null, 'Enabled generic failures must use concise Retry-only copy.');
            repairAssertPublicHardFailure($genericFailureResponse, 'enabled generic generation failure');
        } else {
            repairAssertSame(null, $genericFailureResponse['errorType'] ?? null, 'Rollback mode must retain the legacy untyped recovery response.');
            repairAssertSame('exploratory_recovery', $genericFailureResponse['route'] ?? null, 'Rollback mode must retain the legacy recovery route.');
            repairAssertSame(true, isset($genericFailureResponse['recoveryContext']), 'Rollback mode must retain its recovery context.');
        }
    }
    \app\services\GeminiService::$generationFailure = null;
    Yii::$app->params['nl2sqlTwoLaneEnabled'] = true;

    foreach ([
        [
            new \app\exceptions\ExploratorySqlValidationException(
                'safety',
                'non_select',
                'DELETE FROM inventory.instance__t',
                false,
                'The AI response contains a non-SELECT SQL command: DELETE.'
            ),
            'destructive AI SQL',
        ],
        [
            new \InvalidArgumentException('Only a single SELECT statement is allowed.'),
            'multiple-statement AI SQL',
        ],
    ] as $unsafePublicCase) {
        \app\services\GeminiService::$generationFailure = $unsafePublicCase[0];
        \app\services\GeminiService::$generationCalls = [];
        \app\services\GeminiService::$preflightRepairCalls = [];
        \app\services\SqlPreflightService::$calls = [];
        \app\services\SqlPreflightService::$results = [];
        Yii::$app->request = new TestRequest([
            'prompt' => 'Unsafe fake-provider response.',
            'campus' => 'Smith College',
            'includeSuggestions' => false,
        ]);
        Yii::$app->response->statusCode = 200;
        $unsafePublicController = new TestableFolioQueryController('folio-query', null);
        $unsafePublicController->reviewService = new CapturingAdministratorReviewService();
        $unsafePublicResponse = $unsafePublicController->actionNl();

        repairAssertSame('unsafe_generated_sql', $unsafePublicResponse['errorType'] ?? null, $unsafePublicCase[1] . ' must retain its hard-failure type.');
        repairAssertSame(1, count(\app\services\GeminiService::$generationCalls), $unsafePublicCase[1] . ' must cross the public generation boundary once.');
        repairAssertSame(0, count(\app\services\GeminiService::$preflightRepairCalls), $unsafePublicCase[1] . ' must not enter AI repair.');
        repairAssertSame(0, count(\app\services\SqlPreflightService::$calls), $unsafePublicCase[1] . ' must stop before database preflight.');
        repairAssertSame(
            'Report Explorer could not safely run this report. Please retry.',
            $unsafePublicResponse['message'] ?? null,
            $unsafePublicCase[1] . ' must use concise Retry copy.'
        );
        repairAssertPublicHardFailure($unsafePublicResponse, $unsafePublicCase[1]);
    }
    \app\services\GeminiService::$generationFailure = null;

    $publicPreflightHardStops = [
        [
            'name' => 'database cancellation',
            'preflight' => ['error' => 'SQLSTATE[57014]: canceling statement due to statement timeout'],
            'errorType' => 'database_cancelled',
        ],
        [
            'name' => 'database connectivity',
            'preflight' => ['error' => 'connection failure', 'sqlState' => '08006', 'sqlStateClass' => '08'],
            'errorType' => 'postgres_connectivity',
        ],
        [
            'name' => 'database authentication',
            'preflight' => ['error' => 'authentication failure', 'sqlState' => '28P01', 'sqlStateClass' => '28'],
            'errorType' => 'policy_blocked',
        ],
        [
            'name' => 'database resource limit',
            'preflight' => ['error' => 'resource failure', 'sqlState' => '53200', 'sqlStateClass' => '53'],
            'errorType' => 'database_resource_limit',
        ],
    ];
    foreach ($publicPreflightHardStops as $hardStop) {
        \app\services\GeminiService::$generationResult = [
            'sql' => 'SELECT id FROM inventory.item__t',
            'mode' => 'exploratory',
            'route' => 'exploratory',
            'generationProvenance' => 'ai_built',
            'provenanceLabel' => 'AI-built',
        ];
        \app\services\GeminiService::$preflightRepairCalls = [];
        \app\services\GeminiService::$preflightRepairFailure = null;
        \app\services\GeminiService::$preflightRepairResult = null;
        \app\services\SqlPreflightService::$calls = [];
        \app\services\SqlPreflightService::$results = [$hardStop['preflight']];
        Yii::$app->request = new TestRequest([
            'prompt' => 'Show item identifiers.',
            'campus' => 'Smith College',
            'includeSuggestions' => false,
        ]);
        Yii::$app->response->statusCode = 200;
        $hardStopController = new TestableFolioQueryController('folio-query', null);
        $hardStopController->reviewService = new CapturingAdministratorReviewService();
        $hardStopResponse = $hardStopController->actionNl();

        repairAssertSame($hardStop['errorType'], $hardStopResponse['errorType'] ?? null, $hardStop['name'] . ' must retain its public hard-failure type.');
        repairAssertSame(0, count(\app\services\GeminiService::$preflightRepairCalls), $hardStop['name'] . ' must not invoke AI repair.');
        repairAssertPublicHardFailure($hardStopResponse, $hardStop['name']);
    }

    \app\services\GeminiService::$generationResult = [
        'sql' => 'SELECT missing_column FROM inventory.item__t',
        'mode' => 'canonical',
        'route' => 'builder_intent',
        'routeReason' => 'family_contract_supported:inventory_library_location_listing',
        'generationProvenance' => 'verified_pattern',
        'provenanceLabel' => 'Verified pattern',
        'repairAttempts' => 0,
    ];
    \app\services\GeminiService::$preflightRepairCalls = [];
    \app\services\GeminiService::$preflightRepairResult = null;
    \app\services\GeminiService::$preflightRepairFailure = new \RuntimeException('Operation timed out after 300000 milliseconds');
    \app\services\SqlPreflightService::$results = [['error' => 'column "missing_column" does not exist']];
    Yii::$app->request = new TestRequest([
        'prompt' => 'Show item identifiers.',
        'campus' => 'Smith College',
        'includeSuggestions' => false,
    ]);
    Yii::$app->response->statusCode = 200;
    $providerTimeoutController = new TestableFolioQueryController('folio-query', null);
    $providerTimeoutController->reviewService = new CapturingAdministratorReviewService();
    $providerTimeoutResponse = $providerTimeoutController->actionNl();
    repairAssertSame('ai_timeout', $providerTimeoutResponse['errorType'] ?? null, 'Provider timeout must retain its public hard-failure type.');
    repairAssertSame(504, Yii::$app->response->statusCode, 'Provider timeout must retain gateway-timeout status.');
    repairAssertPublicHardFailure($providerTimeoutResponse, 'provider timeout');
    \app\services\GeminiService::$preflightRepairFailure = null;

    repairAssertPublicHardFailure($actionExhaustionResponse, 'shared repair-budget exhaustion');

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
