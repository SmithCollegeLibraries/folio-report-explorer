<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\services\BuilderQueryDefinitionNormalizerService;
use app\services\BuilderSchemaService;
use app\services\FolioSchemaService;
use app\services\SqlBuilderService;
use app\services\GeminiService;
use app\services\SettingsService;
use app\services\DatabaseRetryService;
use app\services\IndexRecommendationService;
use app\services\Nl2sqlRuntimePreflightService;
use app\services\PreviousSuccessfulQueryReuseService;
use app\services\QueryMemoryService;
use app\services\QueryJobCancellationService;
use app\services\QueryHistoryDeletionService;
use app\services\ReferenceCacheRefreshService;
use app\services\ReferenceJsonBundleService;
use app\services\SqlPreflightService;
use app\services\SqlSelectStructureService;
use app\services\AdministratorReviewService;
use app\services\AskConfidenceClassificationService;
use app\services\AskGenerationEvidenceService;
use app\services\AskGenerationCoordinatorService;
use app\services\AskResponseContractService;
use app\services\AskUserExplanationService;
use app\services\CatalogingReportCompilerService;
use app\services\ReportExecutionContractService;
use app\models\SavedQuery;
use app\models\QueryLog;
use app\models\QueryJob;
use app\models\ReportTemplate;
use app\models\AcrlStatistic;
use app\models\ExpenseAllocation;
use app\models\User;
use app\models\DummyIdentity;
use Firebase\JWT\JWT;

require_once __DIR__ . '/../exceptions/DatabaseQueryCancelledException.php';
require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/../services/AdministratorReviewService.php';
require_once __DIR__ . '/../services/AskConfidenceClassificationService.php';
require_once __DIR__ . '/../services/AskGenerationEvidenceService.php';
require_once __DIR__ . '/../services/AskRequestPolicyService.php';
require_once __DIR__ . '/../services/AskGenerationCoordinatorService.php';
require_once __DIR__ . '/../services/AskResponseContractService.php';
require_once __DIR__ . '/../services/AskUserExplanationService.php';

/**
 * FolioQueryController — REST API for the FOLIO Report Explorer.
 *
 * Endpoints:
 *   GET  /api/schema            — list all tables with summary info
 *   GET  /api/schema/<table>    — full detail for one table
 *   GET  /api/path              — find FK join path between two tables
 *   GET  /api/derived           — get derived table metadata
 *   POST /api/build             — generate SQL from query definition
 *   POST /api/execute           — execute SQL against FOLIO Postgres
 *   POST /api/nl                — natural language → SQL via Gemini
 *   POST /api/saved             — save a query definition
 *   GET  /api/saved             — list saved queries
 *   GET  /api/saved/<id>        — get a saved query
 *   DELETE /api/saved/<id>      — delete a saved query
 *   GET  /api/health            — health check
 *   GET  /api/nl2sql-preflight  — effective NL2SQL runtime parity summary
 */
class FolioQueryController extends Controller
{
    private const QUERY_JOB_NAME_MAX_LENGTH = 240;

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    public function behaviors()
    {
        $behaviors = parent::behaviors();

        // CORS support (must be first — before authenticator)
        $behaviors['corsFilter'] = [
            'class' => \yii\filters\Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
            ],
        ];

        // JSON response format
        $behaviors['contentNegotiator'] = [
            'class' => \yii\filters\ContentNegotiator::class,
            'formats' => [
                'application/json' => Response::FORMAT_JSON,
            ],
        ];

        // JWT Bearer authentication (production only)
        if (YII_ENV !== 'dev') {
            $behaviors['authenticator'] = [
                'class' => \yii\filters\auth\HttpBearerAuth::class,
                'except' => ['options', 'health', 'auth-refresh'],
            ];

            // Role-based access control
            $behaviors['access'] = [
                'class' => \yii\filters\AccessControl::class,
                'except' => ['options', 'health', 'auth-refresh'],
                'rules' => [
                    // Admin-only actions
                    [
                        'allow' => true,
                        'actions' => [
                            'settings', 'settings-save', 'settings-test', 'nl2sql-preflight', 'reference-cache-status', 'reference-cache-candidates', 'reference-cache-candidate-review', 'reference-cache-refresh',
                            'training-list', 'training-detail', 'training-create',
                            'training-update', 'training-delete', 'training-correct',
                            'report-create', 'report-update', 'report-delete',
                            'report-generate', 'report-convert',
                            'local-acrl-list', 'local-acrl-years', 'local-acrl-create',
                            'local-acrl-update', 'local-acrl-delete', 'local-acrl-copy-year',
                            'local-alloc-list', 'local-alloc-years', 'local-alloc-upsert',
                            'local-alloc-delete', 'local-alloc-copy-year',
                            'user-list', 'user-approve', 'user-role', 'user-delete', 'user-notifications',
                            'admin-widget-create', 'admin-widget-update', 'admin-widget-delete',
                            'report-review-list', 'report-review-detail', 'report-review-claim', 'report-review-update',
                        ],
                        'roles' => ['@'],
                        'matchCallback' => function ($rule, $action) {
                            return $this->isPersistedAdministrator();
                        },
                    ],
                    // Stable API denial for authenticated non-administrators.
                    [
                        'allow' => false,
                        'actions' => [
                            'report-review-list', 'report-review-detail',
                            'report-review-claim', 'report-review-update',
                        ],
                        'roles' => ['@'],
                        'denyCallback' => function ($rule, $action) {
                            Yii::$app->response->statusCode = 403;
                            Yii::$app->response->data = ['error' => 'Forbidden'];
                        },
                    ],
                    // Any authenticated user
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ];
        }

        return $behaviors;
    }

    /**
     * Ensure JSON responses for all actions.
     */
    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    /**
     * Returns the current identity cast to User|DummyIdentity|null.
     * Both types declare isAdmin(), which IdentityInterface does not —
     * this is the single cast point so static analysis tools are satisfied.
     *
     * @return User|DummyIdentity|null
     */
    private function getAppIdentity()
    {
        $identity = Yii::$app->user->identity;
        if ($identity instanceof User || $identity instanceof DummyIdentity) {
            return $identity;
        }

        if (YII_ENV === 'dev') {
            return new DummyIdentity();
        }

        return null;
    }

    /**
     * Get the current authenticated user's ID.
     *
     * Uses authenticated identity when available in all environments.
     * Falls back to null when unauthenticated.
     * @return int|null
     */
    private function getCurrentUserId()
    {
        if (!Yii::$app->user->isGuest && Yii::$app->user->id !== null) {
            return (int) Yii::$app->user->id;
        }

        if (YII_ENV === 'dev') {
            return (int) (new DummyIdentity())->getId();
        }

        return null;
    }

    /**
     * Require admin role. Returns true if admin, sends 403 and returns false otherwise.
     * (Used for dev-mode manual checks since AccessControl is only active in production.)
     * @return bool
     */
    private function requireAdmin()
    {
        if (YII_ENV === 'dev') {
            return true; // No auth enforcement in dev
        }
        $identity = $this->getAppIdentity();
        if (!$identity || !$identity->isAdmin()) {
            Yii::$app->response->statusCode = 403;
            return false;
        }
        return true;
    }

    /**
     * Enforce administrator access in every environment and return stable API copy.
     * Authentication still runs through AccessControl in production, while this
     * guard owns the role check and stable response in every environment.
     *
     * @return array|null
     */
    private function requireAdministrator(): ?array
    {
        if ($this->isPersistedAdministrator()) {
            return null;
        }

        Yii::$app->response->statusCode = 403;
        return ['error' => 'Forbidden'];
    }

    private function isPersistedAdministrator(): bool
    {
        $user = Yii::$app->user;
        $identity = $user->identity;
        if ($user->isGuest || !($identity instanceof User)) {
            return false;
        }

        return User::find()->where([
            'id' => (int)$identity->getId(),
            'role' => 'admin',
            'is_approved' => 1,
        ])->exists();
    }

    /**
     * Normalize datasource input to folio|local.
     * @param mixed $value
     * @return string
     */
    private function normalizeDataSource($value)
    {
        $normalized = strtolower((string) $value);
        if ($normalized === 'local') {
            return 'local';
        }
        if ($normalized === 'composite') {
            return 'composite';
        }
        return 'folio';
    }

    /**
     * Resolve DB component name for a datasource.
     * @param string $dataSource
     * @return string
     */
    private function resolveDbComponent($dataSource)
    {
        return $this->normalizeDataSource($dataSource) === 'local' ? 'db' : 'folioDb';
    }

    /**
     * Only generated FOLIO SQL should be preflighted on /api/execute.
     * Manual SQL still gets its validation from the execution path itself.
     *
     * @param string $dataSource
     * @param string $source
     * @return bool
     */
    private function shouldPreflightExecuteSql($dataSource, $source)
    {
        return $this->normalizeDataSource($dataSource) === 'folio'
            && strtolower(trim((string) $source)) !== 'manual';
    }

    /**
     * Create a stable prompt fingerprint for telemetry without logging prompt text.
     *
     * @param string $prompt
     * @return string
     */
    private function fingerprintPrompt($prompt)
    {
        return substr(hash('sha256', trim((string) $prompt)), 0, 16);
    }

    /**
     * Normalize SQL for stable telemetry hashing.
     *
     * @param string $sql
     * @return string
     */
    private function normalizeSqlForTelemetry($sql)
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim((string) $sql)));
        return trim((string) $normalized);
    }

    /**
     * Bucket PostgreSQL preflight failures into stable error families.
     *
     * @param string $error
     * @return string
     */
    private function classifyPreflightErrorFamily($error)
    {
        $message = strtolower(trim((string) $error));
        if ($message === '') {
            return 'unknown_error';
        }
        if (strpos($message, 'syntax error') !== false) {
            return 'syntax_error';
        }
        if (strpos($message, 'operator does not exist') !== false) {
            return 'operator_error';
        }
        if (strpos($message, 'function ') !== false && strpos($message, ' does not exist') !== false) {
            return 'function_error';
        }
        if (strpos($message, 'column ') !== false && strpos($message, ' does not exist') !== false) {
            return 'missing_column';
        }
        if (strpos($message, 'relation ') !== false && strpos($message, ' does not exist') !== false) {
            return 'missing_table';
        }
        if (strpos($message, 'type ') !== false && strpos($message, ' does not exist') !== false) {
            return 'missing_type';
        }
        if (strpos($message, 'permission denied') !== false) {
            return 'permission_error';
        }
        return 'unknown_error';
    }

    /**
     * Emit structured telemetry for PostgreSQL preflight validation failures.
     *
     * @param string $endpoint
     * @param string $error
     * @param string $dataSource
     * @param string $source
     * @param string $sql
     * @param array $context
     * @return void
     */
    private function logPreflightValidationFailure($endpoint, $error, $dataSource, $source, $sql, array $context = [])
    {
        $payload = array_merge([
            'event' => 'nl2sql.validation_failure',
            'timestamp' => gmdate('c'),
            'stage' => 'postgres_preflight',
            'endpoint' => (string) $endpoint,
            'source' => strtolower(trim((string) $source)),
            'dataSource' => $this->normalizeDataSource($dataSource),
            'errorFamily' => $this->classifyPreflightErrorFamily($error),
            'sqlHash' => substr(hash('sha256', $this->normalizeSqlForTelemetry($sql)), 0, 16),
            'sqlLength' => strlen((string) $sql),
        ], $context);

        Yii::warning(
            'NL2SQL telemetry: ' . json_encode($payload),
            GeminiService::NL2SQL_TELEMETRY_CATEGORY
        );
    }

    /**
     * Estimate query complexity using EXPLAIN (FORMAT JSON).
     * Returns null when estimation is unavailable.
     *
     * @param string $sql
     * @param string $dataSource
     * @param array $params
     * @return array|null ['rows' => int|null, 'cost' => float|null]
     */
    private function estimateQueryComplexity($sql, $dataSource, array $params = [])
    {
        if ($this->normalizeDataSource($dataSource) !== 'folio') {
            return null;
        }

        return SqlPreflightService::estimateQueryComplexity(
            Yii::$app->folioDb,
            (string) $sql,
            (int) Yii::$app->params['queryTimeoutMs'],
            10000,
            $params
        );
    }

    /**
     * Normalize and database-preflight an NL2SQL result, repairing exploratory
     * SQL while the shared two-repair budget still has capacity.
     */
    private function validateAndRepairNlResult(
        array $result,
        string $rawQuestion,
        $campus,
        ?callable $preflight = null,
        ?callable $repair = null,
        ?string $generationPrompt = null
    ): array {
        $generationPrompt = $generationPrompt === null ? $rawQuestion : $generationPrompt;
        if (isset($result['sql']) || array_key_exists('repairAttempts', $result)) {
            $result['repairAttempts'] = $this->clampExploratoryRepairAttempts(
                $result['repairAttempts'] ?? 0
            );
        }
        $preflight = $preflight ?: function (string $sql, string $dataSource): array {
            return $this->estimateQueryComplexity($sql, $dataSource) ?? [];
        };
        $repair = $repair ?: function (
            string $question,
            $campusScope,
            array $currentResult,
            string $error,
            array $preflightResult = []
        ) use ($generationPrompt): array {
            return GeminiService::repairExploratorySqlAfterPreflight(
                $question,
                $campusScope,
                $currentResult,
                $error,
                $generationPrompt,
                [],
                $preflightResult
            );
        };

        while (isset($result['sql'])) {
            $result['sql'] = SqlBuilderService::normalizeForExecution((string)$result['sql']);
            if (!$this->isSafeSelectNlSql((string)$result['sql'])) {
                throw new \app\exceptions\ExploratorySqlValidationException(
                    'safety',
                    'non_select',
                    (string)$result['sql'],
                    true,
                    'The generated candidate was not a single read-only SELECT statement.'
                );
            }

            $semanticStatus = (string)($result['semanticValidation']['status'] ?? '');
            $semanticRepairRequired = !empty($result['semanticContractApplicable'])
                && !in_array($semanticStatus, ['validated', 'advisory'], true);
            $dataSource = (string)($result['dataSource'] ?? 'folio');
            if ($semanticRepairRequired) {
                $estimate = ['error' => 'Semantic validation requires AI review.'];
            } else {
                try {
                    $estimate = $preflight((string)$result['sql'], $dataSource);
                } catch (\app\exceptions\DatabaseQueryCancelledException $exception) {
                    $this->logExploratoryTerminalOutcome($result, $rawQuestion, 'cancelled', 'database_cancelled');
                    throw $exception;
                }
            }
            if (!isset($estimate['error'])) {
                $this->logExploratoryTerminalOutcome($result, $rawQuestion, 'validated', 'validated');
                return $result;
            }

            $error = (string)$estimate['error'];
            if (!$semanticRepairRequired) {
                $this->logPreflightValidationFailure(
                    'api.nl',
                    $error,
                    $dataSource,
                    'nl',
                    (string)$result['sql'],
                    [
                        'route' => $result['route'] ?? null,
                        'routeReason' => $result['routeReason'] ?? null,
                        'promptFingerprint' => $this->fingerprintPrompt($rawQuestion),
                        'repairAttempts' => (int)($result['repairAttempts'] ?? 0),
                    ]
                );
            }

            if (!$semanticRepairRequired && $this->isAskPostgresConnectivityFailure($error, $estimate)) {
                $this->logExploratoryTerminalOutcome($result, $rawQuestion, 'connectivity_failure', 'database_connectivity');
                return $this->attachTrustedAskEvidence(
                    $this->buildAskPostgresConnectivityFailure(),
                    $result
                );
            }
            if (!$semanticRepairRequired && $this->isAskPreflightCancellationFailure($error, $estimate)) {
                $this->logExploratoryTerminalOutcome($result, $rawQuestion, 'cancelled', 'database_cancelled');
                throw new \app\exceptions\DatabaseQueryCancelledException();
            }
            if (!$semanticRepairRequired && $this->isAskPreflightPolicyFailure($error, $estimate)) {
                $this->logExploratoryTerminalOutcome($result, $rawQuestion, 'policy_blocked', 'policy_blocked');
                return $this->attachTrustedAskEvidence(
                    $this->buildAskContinuationFromFailure(
                        new \app\exceptions\PolicyViolationException('Database access policy blocked query validation.'),
                        $rawQuestion,
                        $campus,
                        'ask_sql_preflight_recovery'
                    ),
                    $result
                );
            }
            if (!$semanticRepairRequired && $this->isAskPreflightResourceFailure($error, $estimate)) {
                $this->logExploratoryTerminalOutcome($result, $rawQuestion, 'resource_limited', 'resource_limit');
                return $this->buildDatabaseResourceLimitResponse($result);
            }

            if (!$this->isAiRepairEligible($result)) {
                $result['_askEvidence'] = array_merge(
                    is_array($result['_askEvidence'] ?? null) ? $result['_askEvidence'] : [],
                    ['validationStatus' => 'rejected']
                );
                return $this->attachTrustedAskEvidence(
                    $this->buildAskContinuationFromFailure(
                        new \RuntimeException('Generated query failed database validation.'),
                        $rawQuestion,
                        $campus,
                        'ask_sql_preflight_recovery'
                    ),
                    $result
                );
            }

            $failureCategory = $semanticRepairRequired
                ? 'semantic_coverage_gap'
                : $this->classifyPreflightErrorFamily($error);
            $repairAttempts = (int)($result['repairAttempts'] ?? 0);
            if ($repairAttempts >= 2) {
                $freshResult = $this->regenerateAiBuiltNlResultAfterExhaustion(
                    $result,
                    $rawQuestion,
                    $campus,
                    $generationPrompt
                );
                if ($freshResult !== null) {
                    if (!isset($freshResult['sql'])) {
                        return $freshResult;
                    }
                    $result = $freshResult;
                    continue;
                }
                $this->logExploratoryTerminalOutcome(
                    $result,
                    $rawQuestion,
                    'exhausted',
                    $failureCategory
                );
                return $this->buildAiSqlGenerationFailedResponse($result);
            }

            $previousResult = $result;
            $repairResult = $repair($rawQuestion, $campus, $result, $error, $estimate);
            if (!is_array($repairResult)) {
                $repairResult = [];
            }
            $previousEvidence = is_array($previousResult['_askEvidence'] ?? null)
                ? $previousResult['_askEvidence']
                : [];
            $repairEvidence = is_array($repairResult['_askEvidence'] ?? null)
                ? $repairResult['_askEvidence']
                : [];
            unset($previousResult['semanticValidation']);
            $result = array_replace($previousResult, $repairResult);
            if (!array_key_exists('sql', $repairResult)) {
                unset($result['sql']);
            }
            $result['repairAttempts'] = min(
                2,
                max(
                    $repairAttempts + 1,
                    $this->clampExploratoryRepairAttempts($repairResult['repairAttempts'] ?? 0)
                )
            );
            if (isset($result['sql'])) {
                $result['mode'] = 'exploratory';
                $result['route'] = 'exploratory';
                $result = AskResponseContractService::withGenerationProvenance(
                    $result,
                    AskResponseContractService::PROVENANCE_AI_BUILT
                );
            }
            if ($previousEvidence !== [] || $repairEvidence !== []) {
                $result['_askEvidence'] = array_merge($previousEvidence, $repairEvidence, [
                    'initialSql' => $previousEvidence['initialSql']
                        ?? $repairEvidence['initialSql']
                        ?? null,
                    'finalSql' => isset($result['sql']) ? (string)$result['sql'] : ($repairEvidence['finalSql'] ?? null),
                    'repairAttempts' => $result['repairAttempts'],
                ]);
            }

            if (!isset($result['sql'])) {
                $freshResult = $this->regenerateAiBuiltNlResultAfterExhaustion(
                    $result,
                    $rawQuestion,
                    $campus,
                    $generationPrompt
                );
                if ($freshResult !== null) {
                    if (!isset($freshResult['sql'])) {
                        return $freshResult;
                    }
                    $result = $freshResult;
                    continue;
                }
                return $this->buildAiSqlGenerationFailedResponse($result);
            }
        }

        return $result;
    }

    private function regenerateAiBuiltNlResultAfterExhaustion(
        array $result,
        string $rawQuestion,
        $campus,
        string $generationPrompt
    ): ?array {
        if (!$this->isAiRepairEligible($result)) {
            return null;
        }
        $evidence = is_array($result['_askEvidence'] ?? null)
            ? $result['_askEvidence']
            : [];
        if ((int)($evidence['freshGenerationAttempts'] ?? 0) >= 1) {
            return null;
        }

        $freshResult = GeminiService::regenerateAiBuiltSqlAfterExhaustion(
            $rawQuestion,
            $campus,
            $generationPrompt,
            $result
        );
        return $freshResult === [] ? null : $freshResult;
    }

    private function clampExploratoryRepairAttempts($repairAttempts): int
    {
        return max(0, min(2, (int)$repairAttempts));
    }

    private function isSafeSelectNlSql(string $sql): bool
    {
        try {
            SqlBuilderService::validateSafety($sql);
            return true;
        } catch (\InvalidArgumentException $exception) {
            return false;
        }
    }

    private function isAskPreflightPolicyFailure(string $message, array $preflightResult = []): bool
    {
        if ($this->isAskSqlStateClass($preflightResult, ['28'])
            || $this->isAskSqlState($preflightResult, ['42501'])
        ) {
            return true;
        }
        return preg_match(
            '/SQLSTATE\[(?:28[0-9A-Z]{3}|42501)\]|password authentication failed|invalid authorization specification|'
                . 'permission denied|insufficient privilege|row-level security|access denied|not authorized|must be owner of/i',
            $message
        ) === 1;
    }

    private function isAskPreflightCancellationFailure(string $message, array $preflightResult = []): bool
    {
        if ($this->isAskSqlState($preflightResult, ['57014'])) {
            return true;
        }
        return preg_match(
            '/SQLSTATE\[57014\]|statement timeout|cancel(?:ing|ling)? statement|query (?:canceled|cancelled)/i',
            $message
        ) === 1;
    }

    private function isAskPreflightResourceFailure(string $message, array $preflightResult = []): bool
    {
        if ($this->isAskSqlStateClass($preflightResult, ['53', '54'])) {
            return true;
        }
        return preg_match(
            '/SQLSTATE\[(?:53|54)[0-9A-Z]{3}\]|resource exhausted|out of memory|disk full|too many connections|'
                . 'insufficient resources|configuration limit exceeded|program limit exceeded|stack depth limit exceeded|'
                . 'statement too complex|too many (?:columns|arguments)|query.{0,40}too complex|'
                . '(?:estimated\s+)?query\s+(?:cost|rows?).{0,40}(?:exceeds?|above).{0,20}(?:configured\s+)?limit|'
                . '(?:configured|complexity|resource|row|cost)\s+limit\s+(?:exceeded|reached)/i',
            $message
        ) === 1;
    }

    private function isAskSqlStateClass(array $preflightResult, array $classes): bool
    {
        $sqlStateClass = strtoupper(trim((string)($preflightResult['sqlStateClass'] ?? '')));
        if ($sqlStateClass === '') {
            $sqlState = strtoupper(trim((string)($preflightResult['sqlState'] ?? '')));
            $sqlStateClass = strlen($sqlState) >= 2 ? substr($sqlState, 0, 2) : '';
        }
        return in_array($sqlStateClass, $classes, true);
    }

    private function isAskSqlState(array $preflightResult, array $states): bool
    {
        $sqlState = strtoupper(trim((string)($preflightResult['sqlState'] ?? '')));
        return in_array($sqlState, $states, true);
    }

    private function isAiRepairEligible(array $result): bool
    {
        if (!isset($result['sql'])) {
            return false;
        }
        $params = is_array(Yii::$app->params ?? null) ? Yii::$app->params : [];
        $twoLaneEnabled = !array_key_exists('nl2sqlTwoLaneEnabled', $params)
            || (bool)$params['nl2sqlTwoLaneEnabled'];
        $isCanonical = ($result['generationProvenance'] ?? null) === AskResponseContractService::PROVENANCE_VERIFIED_PATTERN
            || ($result['mode'] ?? null) === 'canonical'
            || (($result['route'] ?? null) === 'builder_intent'
                && strpos((string)($result['routeReason'] ?? ''), 'family_contract_supported:') === 0);
        if (!$twoLaneEnabled && $isCanonical) {
            return false;
        }
        return in_array(
            (string)($result['generationProvenance'] ?? ''),
            [
                AskResponseContractService::PROVENANCE_VERIFIED_PATTERN,
                AskResponseContractService::PROVENANCE_AI_BUILT,
            ],
            true
        ) || in_array((string)($result['mode'] ?? ''), ['canonical', 'exploratory'], true);
    }

    private function logExploratoryTerminalOutcome(
        array $result,
        string $prompt,
        string $outcome,
        string $category
    ): void {
        if (!$this->isAiRepairEligible($result)) {
            return;
        }
        $allowedOutcomes = ['exhausted', 'policy_blocked', 'connectivity_failure', 'resource_limited', 'provider_failure', 'cancelled', 'validated'];
        $safeOutcome = in_array($outcome, $allowedOutcomes, true) ? $outcome : 'provider_failure';
        $payload = [
            'event' => 'nl2sql.exploratory_terminal_outcome',
            'timestamp' => gmdate('c'),
            'promptFingerprint' => $this->fingerprintPrompt($prompt),
            'route' => $this->sanitizeTelemetryLabel($result['route'] ?? null, 'exploratory'),
            'routeReason' => $this->sanitizeTelemetryLabel($result['routeReason'] ?? null, 'preflight_validation_failed'),
            'outcome' => $safeOutcome,
            'category' => $this->sanitizeExploratoryFailureCategory($category),
            'repairAttempts' => $this->clampExploratoryRepairAttempts($result['repairAttempts'] ?? 0),
        ];
        Yii::warning(
            'NL2SQL telemetry: ' . json_encode($payload),
            GeminiService::NL2SQL_TELEMETRY_CATEGORY
        );
    }

    private function sanitizeTelemetryLabel($value, string $fallback): string
    {
        $value = strtolower(trim((string)$value));
        return $value !== '' && preg_match('/^[a-z0-9_.:-]{1,120}$/', $value) === 1
            ? $value
            : $fallback;
    }

    private function buildAiSqlGenerationFailedResponse(array $result): array
    {
        $repairAttempts = $this->clampExploratoryRepairAttempts($result['repairAttempts'] ?? 0);
        $resultEvidence = is_array($result['_askEvidence'] ?? null)
            ? $result['_askEvidence']
            : [];
        return [
            'errorType' => 'sql_generation_failed',
            'message' => 'Report Explorer could not build a valid report after retrying. Please retry.',
            'route' => 'generation_failed',
            'routeReason' => 'sql_repair_exhausted',
            'validationSummary' => [
                'status' => 'exhausted',
                'repairAttempts' => $repairAttempts,
            ],
            '_askEvidence' => array_merge($resultEvidence, [
                'finalSql' => null,
                'repairAttempts' => $repairAttempts,
                'validationStatus' => 'exhausted',
            ]),
        ];
    }

    private function buildDatabaseResourceLimitResponse(array $result): array
    {
        Yii::$app->response->statusCode = 503;
        $resultEvidence = is_array($result['_askEvidence'] ?? null)
            ? $result['_askEvidence']
            : [];
        $repairAttempts = $this->clampExploratoryRepairAttempts($result['repairAttempts'] ?? 0);
        return [
            'errorType' => 'database_resource_limit',
            'error' => 'Database validation stopped because this report exceeded available resources or configured limits. Please retry with a narrower scope.',
            'route' => 'database_resource_limit',
            'routeReason' => 'database_resource_limit',
            'validationSummary' => [
                'status' => 'rejected',
                'repairAttempts' => $repairAttempts,
            ],
            '_askEvidence' => array_merge($resultEvidence, [
                'finalSql' => null,
                'repairAttempts' => $repairAttempts,
                'validationStatus' => 'rejected',
            ]),
        ];
    }

    private function buildAiProviderFailureResponse(array $result): array
    {
        Yii::$app->response->statusCode = 503;
        $resultEvidence = is_array($result['_askEvidence'] ?? null)
            ? $result['_askEvidence']
            : [];
        $repairAttempts = $this->clampExploratoryRepairAttempts($result['repairAttempts'] ?? 0);
        return [
            'errorType' => 'ai_provider_failure',
            'error' => 'The AI provider could not complete this report. Please retry.',
            'route' => 'ai_provider_failure',
            'routeReason' => 'ai_provider_failure',
            'validationSummary' => [
                'status' => 'rejected',
                'repairAttempts' => $repairAttempts,
            ],
            '_askEvidence' => array_merge($resultEvidence, [
                'finalSql' => null,
                'repairAttempts' => $repairAttempts,
                'validationStatus' => 'rejected',
            ]),
        ];
    }

    private function sanitizeExploratoryFailureCategory(string $category): string
    {
        $category = strtolower(trim($category));
        $allowedCategories = [
            'ambiguous_column',
            'assumption_mismatch',
            'database_cancelled',
            'database_connectivity',
            'database_validation',
            'function_error',
            'grouping_error',
            'grain_mismatch',
            'invalid_operator',
            'missing_column',
            'missing_ordering',
            'missing_table',
            'missing_type',
            'non_select',
            'operator_error',
            'output_type_mismatch',
            'policy_blocked',
            'provider_failure',
            'query_too_complex',
            'resource_limit',
            'semantic_coverage_gap',
            'syntax_error',
            'unknown_column',
            'unknown_error',
            'unknown_table',
            'unrequested_filter',
            'validated',
            'validation_failure',
        ];

        return in_array($category, $allowedCategories, true)
            ? $category
            : 'database_validation';
    }

    /**
     * Normalize optional NL follow-up context from Ask or History.
     *
     * @param mixed $rawContext
     * @return array|null
     */
    private function normalizeFollowUpContext($rawContext)
    {
        if (!is_array($rawContext) || empty($rawContext)) {
            return null;
        }

        $jobId = trim((string)($rawContext['jobId'] ?? ''));
        if ($jobId !== '') {
            return $this->resolveHistoryFollowUpContext($rawContext);
        }

        $previousSql = trim((string)($rawContext['previousSql'] ?? ''));
        if ($previousSql === '') {
            Yii::$app->response->statusCode = 400;
            return null;
        }

        $previousColumns = $rawContext['previousColumns'] ?? [];
        if (!is_array($previousColumns)) {
            $previousColumns = [];
        }

        $previousAssumptions = [];
        if (is_array($rawContext['previousAssumptions'] ?? null)) {
            $allowedFields = ['key', 'label', 'value', 'explanation', 'correctionExample', 'source'];
            foreach ($rawContext['previousAssumptions'] as $assumption) {
                if (!is_array($assumption)) {
                    continue;
                }
                $normalizedAssumption = [];
                foreach ($allowedFields as $field) {
                    if (isset($assumption[$field]) && is_scalar($assumption[$field])) {
                        $normalizedAssumption[$field] = trim((string)$assumption[$field]);
                    }
                }
                if (($normalizedAssumption['key'] ?? '') !== '' && ($normalizedAssumption['value'] ?? '') !== '') {
                    $previousAssumptions[] = $normalizedAssumption;
                }
            }
        }

        return [
            'source' => 'ask',
            'previousPrompt' => trim((string)($rawContext['previousPrompt'] ?? 'Previous Ask query')),
            'previousSql' => $previousSql,
            'previousColumns' => array_values(array_filter(array_map('strval', $previousColumns))),
            'previousAssumptions' => $previousAssumptions,
        ];
    }

    /**
     * Resolve follow-up context from a completed historical job.
     *
     * @param array $context
     * @return array|null
     */
    private function resolveHistoryFollowUpContext(array $context)
    {
        $jobId = trim((string)($context['jobId'] ?? ''));
        $job = $jobId !== '' ? QueryJob::findOne($jobId) : null;
        if (!$job) {
            Yii::$app->response->statusCode = 404;
            return null;
        }

        if ((string)$job->status !== 'completed') {
            Yii::$app->response->statusCode = 409;
            return null;
        }

        $userId = $this->getCurrentUserId();
        $identity = $this->getAppIdentity();
        $isAdmin = $identity && $identity->isAdmin();
        if (!$isAdmin && $userId && isset($job->user_id) && (int)$job->user_id !== (int)$userId) {
            Yii::$app->response->statusCode = 403;
            return null;
        }

        $previousSql = trim((string)($job->sql_text ?? ''));
        if ($previousSql === '') {
            Yii::$app->response->statusCode = 400;
            return null;
        }

        $previousColumns = method_exists($job, 'getDecodedColumns')
            ? $job->getDecodedColumns()
            : [];
        $metadata = $this->decodeQueryJobMetadata($job);
        $askAiProvenance = is_array($metadata['askAiProvenance'] ?? null)
            ? $metadata['askAiProvenance']
            : [];
        $parentGenerationId = trim((string)($askAiProvenance['generationId'] ?? ''));

        $resolved = [
            'source' => 'history',
            'jobId' => $jobId,
            'previousPrompt' => $this->getQueryJobOriginalPrompt($job) ?: 'Previous historical query',
            'previousSql' => $previousSql,
            'previousColumns' => is_array($previousColumns) ? array_values(array_filter(array_map('strval', $previousColumns))) : [],
        ];
        if ($parentGenerationId !== '') {
            $resolved['parentGenerationId'] = $parentGenerationId;
        }
        return $resolved;
    }

    /**
     * Expand a terse follow-up into a standalone NL2SQL prompt.
     *
     * @param string $prompt
     * @param array $context
     * @return string
     */
    private function buildFollowUpPrompt($prompt, array $context)
    {
        $previousPrompt = trim((string)($context['previousPrompt'] ?? 'Previous query'));
        $previousSql = trim((string)($context['previousSql'] ?? ''));
        $previousColumns = $context['previousColumns'] ?? [];
        if (!is_array($previousColumns)) {
            $previousColumns = [];
        }
        $columnText = !empty($previousColumns)
            ? implode(', ', array_values(array_filter(array_map('strval', $previousColumns))))
            : 'not provided';

        $assumptionLines = [];
        foreach (($context['previousAssumptions'] ?? []) as $assumption) {
            if (!is_array($assumption)) {
                continue;
            }
            $line = '- ' . ($assumption['key'] ?? 'interpretation') . ' = ' . ($assumption['value'] ?? '');
            if (($assumption['explanation'] ?? '') !== '') {
                $line .= ': ' . $assumption['explanation'];
            }
            $assumptionLines[] = $line;
        }
        $assumptionBlock = "Previous documented interpretations:\n"
            . (!empty($assumptionLines) ? implode("\n", $assumptionLines) : 'none provided');

        return implode("\n\n", [
            'This is a follow-up request to a previously generated library report.',
            'Previous request: ' . $previousPrompt,
            "Previous SQL:\n```sql\n{$previousSql}\n```",
            'Previous result columns: ' . $columnText,
            $assumptionBlock,
            'The follow-up request overrides any previous documented interpretation that addresses the same concept.',
            'Follow-up request: ' . trim((string)$prompt),
            'Preserve all previous filters, joins, CTEs, and result-set semantics unless the follow-up request explicitly changes them.',
            'Add or modify only the columns, grouping, ordering, or constraints requested by the follow-up. Return one complete executable SQL query for the revised request.',
        ]);
    }

    // ─── Schema endpoints ─────────────────────────────────────────────

    /**
     * GET /api/schema — list all tables with summary.
     * Optional: ?tables=inventory_items,circulation_loans (comma-separated filter)
     */
    public function actionSchema()
    {
        $filter = Yii::$app->request->get('tables');
        $filterArray = $filter ? array_map('trim', explode(',', $filter)) : null;

        $tables = $this->usesLdliteBuilderIdentity()
            ? BuilderSchemaService::getTables($filterArray)
            : FolioSchemaService::getTables($filterArray);
        $meta = FolioSchemaService::getMetadata();

        return [
            'metadata' => $meta,
            'tables' => $tables,
        ];
    }

    /**
     * GET /api/schema/<table> — full column/FK/index detail for one table.
     */
    public function actionSchemaDetail($table)
    {
        $data = $this->usesLdliteBuilderIdentity()
            ? BuilderSchemaService::getTable($table)
            : FolioSchemaService::getTable($table);
        if ($data === null) {
            Yii::$app->response->statusCode = 404;
            return ['error' => "Table '$table' not found"];
        }
        return $data;
    }

    /**
     * GET /api/path — find FK join path between two tables.
     * Params: from, to, all (0|1), maxDepth (int)
     */
    public function actionPath()
    {
        $from = Yii::$app->request->get('from');
        $to = Yii::$app->request->get('to');
        $all = (bool)Yii::$app->request->get('all', 0);
        $maxDepth = (int)Yii::$app->request->get('maxDepth', 6);

        if (!$from || !$to) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Both "from" and "to" parameters are required'];
        }

        if ($this->usesLdliteBuilderIdentity()) {
            if ($all) {
                $paths = BuilderSchemaService::findAllPaths($from, $to, $maxDepth);
                return [
                    'from' => $from,
                    'to' => $to,
                    'total_paths' => count($paths),
                    'paths' => $paths,
                ];
            }

            $path = BuilderSchemaService::findShortestPath($from, $to);
            if ($path === null) {
                Yii::$app->response->statusCode = 404;
                return ['error' => "No FK path found between '$from' and '$to'"];
            }
            return [
                'from' => $from,
                'to' => $to,
                'path' => $path,
            ];
        }

        $resolvedFrom = FolioSchemaService::fuzzyMatch($from);
        $resolvedTo = FolioSchemaService::fuzzyMatch($to);

        if (!$resolvedFrom) {
            Yii::$app->response->statusCode = 404;
            return ['error' => "Table '$from' not found"];
        }
        if (!$resolvedTo) {
            Yii::$app->response->statusCode = 404;
            return ['error' => "Table '$to' not found"];
        }

        if ($all) {
            $paths = FolioSchemaService::findAllPaths($resolvedFrom, $resolvedTo, $maxDepth);
            $formatted = [];
            foreach ($paths as $p) {
                $formatted[] = FolioSchemaService::formatPath($p, $resolvedFrom);
            }
            return [
                'from' => $resolvedFrom,
                'to' => $resolvedTo,
                'total_paths' => count($paths),
                'paths' => $formatted,
            ];
        } else {
            $path = FolioSchemaService::findShortestPath($resolvedFrom, $resolvedTo);
            if ($path === null) {
                Yii::$app->response->statusCode = 404;
                return ['error' => "No FK path found between '$resolvedFrom' and '$resolvedTo'"];
            }
            return [
                'from' => $resolvedFrom,
                'to' => $resolvedTo,
                'path' => FolioSchemaService::formatPath($path, $resolvedFrom),
            ];
        }
    }

    private function usesLdliteBuilderIdentity(): bool
    {
        return strtolower(trim((string)Yii::$app->request->get('identity', ''))) === 'ldlite';
    }

    /**
     * GET /api/derived — get derived table metadata.
     */
    public function actionDerived()
    {
        return FolioSchemaService::loadDerived();
    }

    // ─── Query endpoints ──────────────────────────────────────────────

    /**
     * POST /api/build — generate SQL from a structured query definition.
     * Body: {tables, columns, filters, joins, orderBy, limit}
     */
    public function actionBuild()
    {
        $body = Yii::$app->request->getBodyParams();

        try {
            $definition = BuilderQueryDefinitionNormalizerService::normalize($body);
            $result = SqlBuilderService::build($definition);
            return $result;
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 400;
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * POST /api/execute — execute SQL against FOLIO Postgres.
     * Body: {sql: string, params: object} OR {queryDefinition: object}
     */
    public function actionExecute()
    {
        $body = Yii::$app->request->getBodyParams();

        $sql = $body['sql'] ?? null;
        $params = $body['params'] ?? [];
        $source = $body['source'] ?? 'manual';
        $dataSource = $this->normalizeDataSource($body['dataSource'] ?? $body['data_source'] ?? 'folio');

        // If a query definition is provided instead of raw SQL, build it first
        if (!$sql && isset($body['queryDefinition'])) {
            try {
                $built = SqlBuilderService::build($body['queryDefinition']);
                $sql = $built['sql'];
                $params = $built['params'];
                $source = 'builder';
                $dataSource = 'folio';
            } catch (\InvalidArgumentException $e) {
                Yii::$app->response->statusCode = 400;
                return ['error' => $e->getMessage()];
            }
        }

        if (!$sql) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Either "sql" or "queryDefinition" is required'];
        }

        if ((string)$source === 'nl') {
            $sql = GeminiService::normalizeGeneratedSql((string)$sql);
        }

        $sql = SqlBuilderService::normalizeForExecution($sql);

        // Safety validation
        try {
            SqlBuilderService::validateSafety($sql);
            SqlBuilderService::validateTablePolicy($sql);
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'This query is blocked by reporting data policy.'];
        }

        if ($this->shouldPreflightExecuteSql($dataSource, $source)) {
            try {
                $estimate = $this->estimateQueryComplexity($sql, $dataSource, $params);
            } catch (\app\exceptions\DatabaseQueryCancelledException $exception) {
                return $this->buildDatabaseCancelledResponse();
            }
            if (isset($estimate['error'])) {
                $this->logPreflightValidationFailure('api.execute', (string) $estimate['error'], $dataSource, $source, $sql);
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Query validation failed before execution.'];
            }
        }

        // Enforce LIMIT
        $maxRows = Yii::$app->params['maxQueryRows'];
        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            $sql = rtrim($sql, "; \n") . "\nLIMIT {$maxRows}";
        }

        // Execute with read-only transaction
        $startTime = microtime(true);
        $log = new QueryLog();
        $log->sql_text = $sql;
        $log->params = json_encode($params);
        $log->source = $source;
        if ($log->hasAttribute('data_source')) {
            $log->data_source = $dataSource;
        }
        $log->user_id = $this->getCurrentUserId();

        try {
            $dbComponent = $this->resolveDbComponent($dataSource);
            $db = Yii::$app->{$dbComponent};

            $runQuery = function () use ($db, $dataSource, $sql, $params) {
                $transaction = $db->beginTransaction();
                try {
                    if ($dataSource === 'folio') {
                        $db->createCommand("SET TRANSACTION READ ONLY")->execute();
                    }

                    $command = $db->createCommand($sql);
                    foreach ($params as $key => $value) {
                        $command->bindValue($key, $value);
                    }

                    $rows = $command->queryAll();
                    $transaction->commit();
                    return $rows;
                } catch (\Throwable $e) {
                    if ($transaction->isActive) {
                        try {
                            $transaction->rollBack();
                        } catch (\Throwable $rollbackError) {
                            Yii::warning(
                                'Rollback failed in actionExecute: ' . $rollbackError->getMessage(),
                                'db.retry'
                            );
                        }
                    }
                    throw $e;
                }
            };

            $rows = $dataSource === 'folio'
                ? DatabaseRetryService::runWithReconnectRetry($db, $runQuery, 'api.execute.folio')
                : $runQuery();

            $executionTime = round((microtime(true) - $startTime) * 1000);

            // Get column names from first row
            $columns = !empty($rows) ? array_keys($rows[0]) : [];

            // Log success
            $log->row_count = count($rows);
            $log->execution_time_ms = $executionTime;
            $log->save(false);

            return [
                'columns' => $columns,
                'rows' => $rows,
                'rowCount' => count($rows),
                'executionTimeMs' => $executionTime,
                'sql' => $sql,
                'dataSource' => $dataSource,
            ];
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000);
            $log->execution_time_ms = $executionTime;
            $log->error_message = $e->getMessage();
            $log->save(false);

            Yii::$app->response->statusCode = 422;
            return [
                'errorType' => 'query_execution_failed',
                'error' => 'Query execution failed.',
            ];
        }
    }

    /**
     * POST /api/query/submit — submit a query for background execution.
     * Body: {sql: string, params: object, source: string}
     *    OR {queryDefinition: object}
     * Returns: {jobId: string, status: 'pending'}
     */
    public function actionQuerySubmit()
    {
        $body = Yii::$app->request->getBodyParams();
        $executionGenerationRequest = null;
        $administratorReviewService = null;

        $sql = $body['sql'] ?? null;
        $params = $body['params'] ?? [];
        $source = $body['source'] ?? 'manual';
        $dataSource = $this->normalizeDataSource($body['dataSource'] ?? $body['data_source'] ?? 'folio');
        $outputMode = strtolower((string)($body['outputMode'] ?? 'table')) === 'file' ? 'file' : 'table';
        $confirmed = !empty($body['confirmed']);

        // Build SQL from query definition if provided
        if (!$sql && isset($body['queryDefinition'])) {
            try {
                $built = SqlBuilderService::build($body['queryDefinition']);
                $sql = $built['sql'];
                $params = $built['params'];
                $source = 'builder';
                $dataSource = 'folio';
            } catch (\InvalidArgumentException $e) {
                Yii::$app->response->statusCode = 400;
                return ['error' => $e->getMessage()];
            }
        }

        if (!$sql) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Either "sql" or "queryDefinition" is required'];
        }

        if ((string)$source === 'nl') {
            $sql = GeminiService::normalizeGeneratedSql((string)$sql);
        }

        $sql = SqlBuilderService::normalizeForExecution($sql);

        $reuseRequest = $body['queryReuse'] ?? $body['query_reuse'] ?? null;
        $reuseCandidateJobId = is_array($reuseRequest)
            ? trim((string)($reuseRequest['candidateJobId'] ?? $reuseRequest['candidate_job_id'] ?? ''))
            : '';
        $generationId = trim((string)($body['generationId'] ?? $body['generation_id'] ?? ''));
        if ((string)$source === 'nl' && $reuseCandidateJobId !== '') {
            $userId = $this->getCurrentUserId();
            if ($userId === null) {
                Yii::$app->response->statusCode = 403;
                return ['error' => 'This reusable query is not available for execution.'];
            }
            $reuseJobs = QueryJob::find()
                ->where([
                    'id' => $reuseCandidateJobId,
                    'status' => 'completed',
                    'source' => 'nl',
                    'data_source' => $dataSource,
                ])
                ->asArray()
                ->all();
            $rawReuseScope = $body['resolvedContext'] ?? $body['resolved_context'] ?? [];
            $reuseScope = $this->normalizeQueryMemoryScope(
                is_array($rawReuseScope) ? $rawReuseScope : []
            );
            try {
                $trustedReuse = $this->findTrustedQueryReuseCandidate(
                    trim((string)($body['name'] ?? '')),
                    $dataSource,
                    $reuseScope,
                    $reuseJobs,
                    false
                );
            } catch (\Throwable $exception) {
                $trustedReuse = null;
            }
            if ($trustedReuse === null || (string)$trustedReuse['jobId'] !== $reuseCandidateJobId) {
                Yii::$app->response->statusCode = 409;
                return ['error' => 'This reusable query is no longer available.'];
            }

            $trustedSql = SqlBuilderService::normalizeForExecution(
                GeminiService::normalizeGeneratedSql((string)$trustedReuse['sql'])
            );
            $administratorReviewService = $this->administratorReviewService();
            $executionGenerationRequest = [
                'type' => 'reuse',
                'sourceGenerationId' => (string)$trustedReuse['sourceGenerationId'],
                'reuseTrust' => (string)$trustedReuse['reuseTrust'],
                'userId' => $userId,
                'question' => trim((string)($body['name'] ?? '')),
                'normalizedSql' => $sql,
                'edited' => trim($trustedSql) !== trim($sql),
            ];
        } elseif ((string)$source === 'nl' && $generationId !== '') {
            $userId = $this->getCurrentUserId();
            if ($userId === null) {
                Yii::$app->response->statusCode = 403;
                return ['error' => 'This generated query is not available for execution.'];
            }
            $administratorReviewService = $this->administratorReviewService();
            try {
                $administratorReviewService->assertExecutionGenerationOwned(
                    $generationId,
                    $userId
                );
            } catch (\DomainException $exception) {
                Yii::$app->response->statusCode = 403;
                return ['error' => 'This generated query is not available for execution.'];
            }
            $executionGenerationRequest = [
                'type' => 'generation',
                'generationId' => $generationId,
                'userId' => $userId,
                'normalizedSql' => $sql,
            ];
        }

        // Safety validation
        try {
            SqlBuilderService::validateSafety($sql);
            SqlBuilderService::validateTablePolicy($sql);
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'This query is blocked by reporting data policy.'];
        }

        $estimate = null;
        if ($dataSource === 'folio') {
            try {
                $estimate = $this->estimateQueryComplexity($sql, $dataSource, $params);
            } catch (\app\exceptions\DatabaseQueryCancelledException $exception) {
                return $this->buildDatabaseCancelledResponse();
            }
            // Surface PostgreSQL validation errors immediately instead of queuing a doomed 30-minute job.
            if (isset($estimate['error'])) {
                $this->logPreflightValidationFailure('api.query_submit', (string) $estimate['error'], $dataSource, $source, $sql);
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Query validation failed before execution.'];
            }
            // Auto-route large queries to file export so the user gets all rows without a truncated table.
            if ($outputMode !== 'file' && $estimate !== null) {
                $thresholdRows = (int) (Yii::$app->params['exportRowThreshold'] ?? Yii::$app->params['maxQueryRows']);
                $thresholdCost = (float) (Yii::$app->params['exportCostThreshold'] ?? 500000);
                $estimatedRows = $estimate['rows'] ?? null;
                $estimatedCost = $estimate['cost'] ?? null;
                $isLarge = ($estimatedRows !== null && $estimatedRows > $thresholdRows)
                    || ($estimatedCost !== null && $estimatedCost > $thresholdCost);
                if ($isLarge) {
                    $outputMode = 'file';
                }
            }
        }

        // Enforce LIMIT for table mode; export mode gets its own cap in export worker.
        if ($outputMode !== 'file') {
            $maxRows = (int) Yii::$app->params['maxQueryRows'];
            if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
                $sql = rtrim($sql, "; \n") . "\nLIMIT {$maxRows}";
            }
        }

        // Create job
        $metadata = $this->buildQueryJobMetadata($body, $source);
        $job = QueryJob::createJob($sql, $params, $source, $dataSource, $metadata);
        $job->user_id = $this->getCurrentUserId();
        $job->name = $this->normalizeQueryJobName($body['name'] ?? null);
        $job->sql_hash = hash('sha256', $sql . json_encode($params));
        if ($job->hasAttribute('output_mode')) {
            $job->output_mode = $outputMode;
        }
        if ($outputMode === 'file') {
            $job->status = 'pending_export';
            $job->progress_message = 'Queued for CSV export';
        }
        if ($estimate !== null) {
            if ($job->hasAttribute('estimated_rows')) {
                $job->estimated_rows = $estimate['rows'] ?? null;
            }
            if ($job->hasAttribute('estimated_cost')) {
                $cost = $estimate['cost'] ?? null;
                // Cap to prevent DECIMAL overflow on very large PG plan costs
                $job->estimated_cost = ($cost !== null) ? min((float)$cost, 1.0e15) : null;
            }
        }
        $jobSaveErrors = [];
        $executionGenerationId = null;
        try {
            QueryJob::getDb()->transaction(function () use (
                $job,
                $executionGenerationRequest,
                $administratorReviewService,
                &$jobSaveErrors,
                &$executionGenerationId
            ): void {
                if (!$job->save()) {
                    $jobSaveErrors = $job->errors;
                    throw new \RuntimeException('query_job_save_failed');
                }

                if (
                    $executionGenerationRequest !== null
                    && $administratorReviewService !== null
                ) {
                    if (($executionGenerationRequest['type'] ?? null) === 'reuse') {
                        $reuseGeneration = $administratorReviewService->createTrustedReuseChild(
                            $executionGenerationRequest['sourceGenerationId'],
                            $executionGenerationRequest['userId'],
                            $executionGenerationRequest['question'],
                            $executionGenerationRequest['normalizedSql'],
                            $executionGenerationRequest['edited'],
                            $executionGenerationRequest['reuseTrust']
                        );
                        $administratorReviewService->linkExecutionGeneration(
                            $reuseGeneration['generation'],
                            (string)$job->id,
                            $reuseGeneration['provenanceGeneration']
                        );
                        $executionGenerationId = (string)$reuseGeneration['generationId'];
                        return;
                    }

                    $executionGeneration = $administratorReviewService->resolveExecutionGeneration(
                        $executionGenerationRequest['generationId'],
                        $executionGenerationRequest['userId'],
                        $executionGenerationRequest['normalizedSql']
                    );
                    $administratorReviewService->linkExecutionGeneration(
                        $executionGeneration['generation'],
                        (string)$job->id,
                        $executionGeneration['provenanceGeneration']
                    );
                    $executionGenerationId = (string)$executionGeneration['generation']->id;
                }
            });
        } catch (\DomainException $exception) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'This generated query is not available for execution.'];
        } catch (\Throwable $exception) {
            Yii::warning(
                'Atomic query submission persistence failed: '
                    . $exception->getMessage(),
                'query.submit'
            );
            Yii::$app->response->statusCode = 500;
            $response = ['error' => 'Failed to create job'];
            if ($jobSaveErrors !== []) {
                $response['details'] = $jobSaveErrors;
            }
            return $response;
        }

        Yii::$app->response->statusCode = 202;
        $response = $job->toStatusArray();
        if ($executionGenerationId !== null) {
            $response['generationId'] = $executionGenerationId;
        }
        return $response;
    }

    /**
     * POST /api/query/reuse-candidate — find a prior successful NL query for review.
     * Body: {prompt: string, dataSource?: string, resolvedContext?: object}
     */
    public function actionQueryReuseCandidate()
    {
        $body = Yii::$app->request->getBodyParams();
        $prompt = trim((string)($body['prompt'] ?? ''));
        if ($prompt === '') {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'prompt is required'];
        }

        $dataSource = $this->normalizeDataSource($body['dataSource'] ?? $body['data_source'] ?? 'folio');
        $rawResolvedContext = $body['resolvedContext'] ?? $body['resolved_context'] ?? [];
        $resolvedContext = $this->normalizeQueryMemoryScope(
            is_array($rawResolvedContext) ? $rawResolvedContext : []
        );

        $jobs = QueryJob::find()
            ->where(['status' => 'completed', 'source' => 'nl'])
            ->andWhere(['data_source' => $dataSource])
            ->orderBy(['completed_at' => SORT_DESC, 'created_at' => SORT_DESC])
            ->limit(100)
            ->asArray()
            ->all();

        try {
            $match = $this->findTrustedQueryReuseCandidate(
                $prompt,
                $dataSource,
                $resolvedContext,
                $jobs,
                true
            );
        } catch (\Throwable $exception) {
            Yii::warning(
                'Query-memory lookup unavailable: ' . get_class($exception),
                'query.memory'
            );
            return ['match' => null];
        }
        if ($match === null) {
            return ['match' => null];
        }

        return ['match' => $this->publicQueryReuseCandidate($match)];
    }

    private function findTrustedQueryReuseCandidate(
        string $prompt,
        string $dataSource,
        array $authorizedScope,
        array $jobs,
        bool $preflight
    ): ?array {
        $shapedCandidates = PreviousSuccessfulQueryReuseService::findStrongMatches(
            $prompt,
            $dataSource,
            $authorizedScope,
            $jobs
        );
        if ($shapedCandidates === []) {
            return null;
        }

        $candidates = QueryMemoryService::hydrateCandidates($shapedCandidates, $jobs);
        $request = [
            'question' => $prompt,
            'dataSource' => $dataSource,
            'userId' => $this->getCurrentUserId(),
            'directReuseSchemaFingerprint' => QueryMemoryService::currentDirectReuseSchemaFingerprint($prompt),
            'scopeFingerprint' => QueryMemoryService::scopeFingerprint($dataSource, $authorizedScope),
        ];
        $match = QueryMemoryService::findDirectReuse($request, $candidates);
        if ($match === null) {
            return null;
        }

        try {
            GeminiService::validateTableReferences($match['sql']);
            if ($preflight && $dataSource === 'folio') {
                $estimate = $this->estimateQueryComplexity($match['sql'], $dataSource, []);
                if (is_array($estimate) && isset($estimate['error'])) {
                    return null;
                }
            }
        } catch (\Throwable $exception) {
            Yii::warning(
                'Query-memory candidate rejected: ' . get_class($exception),
                'query.memory'
            );
            return null;
        }

        return $match;
    }

    private function normalizeQueryMemoryScope(array $scope): array
    {
        $normalized = [];
        foreach ($scope as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $normalizedKey = trim((string)$key);
            $normalizedValue = preg_replace('/\s+/', ' ', trim((string)$value));
            if ($normalizedKey !== '' && $normalizedValue !== '') {
                $normalized[$normalizedKey] = $normalizedValue;
            }
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private function publicQueryReuseCandidate(array $match): array
    {
        $provenance = (string)($match['generationProvenance'] ?? '');
        return [
            'jobId' => (string)($match['jobId'] ?? ''),
            'previousPrompt' => (string)($match['previousPrompt'] ?? $match['question'] ?? ''),
            'sql' => (string)($match['sql'] ?? ''),
            'dataSource' => (string)($match['dataSource'] ?? 'folio'),
            'score' => (int)($match['score'] ?? 0),
            'matchReasons' => array_values($match['matchReasons'] ?? []),
            'rowCount' => isset($match['rowCount']) ? (int)$match['rowCount'] : null,
            'executionTimeMs' => isset($match['executionTimeMs']) ? (int)$match['executionTimeMs'] : null,
            'completedAt' => $match['completedAt'] ?? null,
            'generationProvenance' => $provenance,
            'provenanceLabel' => $provenance === 'verified_pattern' ? 'Verified pattern' : 'AI-built',
            'sourceGenerationId' => (string)($match['sourceGenerationId'] ?? ''),
            'reuseTrust' => (string)($match['reuseTrust'] ?? ''),
        ];
    }

    /**
     * POST /api/query/reuse-decision — record review-panel decisions.
     * Body: {decision: accepted|edited|bypassed, candidateJobId?: string, prompt?: string}
     */
    public function actionQueryReuseDecision()
    {
        $body = Yii::$app->request->getBodyParams();
        $decision = strtolower(trim((string)($body['decision'] ?? '')));
        if (!in_array($decision, ['accepted', 'edited', 'bypassed'], true)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'decision must be accepted, edited, or bypassed'];
        }

        $payload = [
            'event' => 'nl2sql.query_reuse',
            'timestamp' => gmdate('c'),
            'decision' => $decision,
            'candidateJobId' => trim((string)($body['candidateJobId'] ?? $body['candidate_job_id'] ?? '')) ?: null,
            'prompt' => trim((string)($body['prompt'] ?? '')) ?: null,
            'edited' => $decision === 'edited',
        ];

        Yii::info(
            'NL2SQL telemetry: ' . json_encode($payload),
            GeminiService::NL2SQL_TELEMETRY_CATEGORY
        );

        return ['ok' => true];
    }

    /**
     * Preserve full NL prompts while keeping query_jobs.name safe for older VARCHAR(255) installs.
     *
     * @param array $body
     * @param string $source
     * @return array|null
     */
    private function buildQueryJobMetadata(array $body, $source)
    {
        $rawName = isset($body['name']) ? trim((string)$body['name']) : '';
        if ($rawName === '') {
            return null;
        }

        $metadata = [];
        if ((string)$source === 'nl') {
            $metadata['originalPrompt'] = $rawName;
        }

        $resolvedContext = $body['resolvedContext'] ?? $body['resolved_context'] ?? null;
        if ((string)$source === 'nl' && is_array($resolvedContext)) {
            $cleanContext = [];
            foreach ($resolvedContext as $key => $value) {
                $cleanKey = trim((string)$key);
                $cleanValue = trim((string)$value);
                if ($cleanKey !== '' && $cleanValue !== '') {
                    $cleanContext[$cleanKey] = $cleanValue;
                }
            }
            if ($cleanContext !== []) {
                $metadata['resolvedContext'] = $cleanContext;
            }
        }

        $normalizedName = $this->normalizeQueryJobName($rawName);
        if ($normalizedName !== $rawName) {
            $metadata['originalName'] = $rawName;
        }

        $reuseCandidate = $body['queryReuse'] ?? $body['query_reuse'] ?? null;
        if (is_array($reuseCandidate)) {
            $metadata['queryReuse'] = [
                'decision' => 'accepted',
                'candidateJobId' => trim((string)($reuseCandidate['candidateJobId'] ?? $reuseCandidate['candidate_job_id'] ?? '')) ?: null,
                'edited' => !empty($reuseCandidate['edited']),
                'score' => isset($reuseCandidate['score']) ? (int)$reuseCandidate['score'] : null,
            ];
        }

        return $metadata ?: null;
    }

    /**
     * Return a compact display name that fits legacy query_jobs.name schemas.
     *
     * @param mixed $name
     * @return string|null
     */
    private function normalizeQueryJobName($name)
    {
        $label = preg_replace('/\s+/', ' ', trim((string)($name ?? '')));
        if ($label === '') {
            return null;
        }

        if ($this->textLength($label) <= self::QUERY_JOB_NAME_MAX_LENGTH) {
            return $label;
        }

        $prefixLength = self::QUERY_JOB_NAME_MAX_LENGTH - 3;
        return rtrim($this->textSubstring($label, 0, $prefixLength)) . '...';
    }

    /**
     * @param QueryJob $job
     * @return string
     */
    private function getQueryJobOriginalPrompt(QueryJob $job)
    {
        $metadata = $this->decodeQueryJobMetadata($job);
        foreach (['originalPrompt', 'originalName', 'nlPrompt'] as $key) {
            $value = trim((string)($metadata[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string)($job->name ?? ''));
    }

    /**
     * @param QueryJob $job
     * @return array
     */
    private function decodeQueryJobMetadata(QueryJob $job)
    {
        if (method_exists($job, 'hasAttribute') && !$job->hasAttribute('metadata')) {
            return [];
        }

        if (empty($job->metadata)) {
            return [];
        }

        $decoded = json_decode((string)$job->metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param string $value
     * @return int
     */
    private function textLength($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * @param string $value
     * @param int $start
     * @param int $length
     * @return string
     */
    private function textSubstring($value, $start, $length)
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
    }

    /**
     * GET /api/query/status/<id> — check the status/results of a background job.
     * Returns full results when status is 'completed'.
     */
    public function actionQueryStatus($id)
    {
        $job = QueryJob::findOne($id);
        if (!$job) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Job not found'];
        }

        return $job->toStatusArray(true); // include results when completed
    }

    /**
     * POST /api/query/cancel/<id> — cancel a pending or running job.
     */
    public function actionQueryCancel($id)
    {
        $job = QueryJob::findOne($id);
        if (!$job) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Job not found'];
        }

        $userId = $this->getCurrentUserId();
        $identity = $this->getAppIdentity();
        $isAdmin = $identity && $identity->isAdmin();
        if (!$isAdmin && (int) $job->user_id !== (int) $userId) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Forbidden'];
        }

        try {
            $service = new QueryJobCancellationService(Yii::$app->db, Yii::$app->folioDb);
            $job = $service->cancel($job);
        } catch (\DomainException $exception) {
            Yii::$app->response->statusCode = 409;
            return ['error' => $exception->getMessage()];
        } catch (\Throwable $exception) {
            Yii::warning(
                "Unable to interrupt query job {$job->id}: {$exception->getMessage()}",
                'query.cancellation'
            );
            Yii::$app->response->statusCode = 503;
            return ['error' => 'Unable to stop this query right now. It is still being monitored.'];
        }

        return $job->toStatusArray();
    }

    /**
     * GET /api/query/export/<id> — download completed CSV export for a job.
     */
    public function actionQueryExport($id)
    {
        $job = QueryJob::findOne($id);
        if (!$job) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Job not found'];
        }

        if (($job->hasAttribute('output_mode') ? $job->output_mode : 'table') !== 'file') {
            Yii::$app->response->statusCode = 409;
            return ['error' => 'Job is not a file export'];
        }

        if ($job->status !== 'completed') {
            Yii::$app->response->statusCode = 409;
            return ['error' => 'Export is not completed'];
        }

        $path = $job->hasAttribute('export_file_path') ? $job->export_file_path : null;
        if (!$path) {
            $path = Yii::getAlias('@runtime/exports/' . $job->id . '.csv');
        }
        if (!$path || !is_file($path)) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Export file not found'];
        }

        $downloadFilename = basename($path);
        try {
            $reportExecution = ReportExecutionContractService::fromJob($job);
            if ($reportExecution !== null) {
                $downloadFilename = $reportExecution['downloadFilename'];
            }
        } catch (\InvalidArgumentException $exception) {
            Yii::warning("Invalid report export metadata for query job {$job->id}: {$exception->getMessage()}", 'query.export');
        }

        Yii::$app->response->format = Response::FORMAT_RAW;
        return Yii::$app->response->sendFile($path, $downloadFilename, [
            'mimeType' => 'text/csv',
            'inline' => false,
        ]);
    }

    /**
     * GET /api/query/jobs — list recent jobs.
     */
    public function actionQueryList()
    {
        $jobs = QueryJob::find()
            ->orderBy(['created_at' => SORT_DESC])
            ->limit(50)
            ->all();

        return array_map(function ($job) {
            return $job->toStatusArray(false);
        }, $jobs);
    }

    /**
     * POST /api/nl — natural language → SQL via Gemini.
     * Body: {prompt: string}
     */
    public function actionNl()
    {
        $body = Yii::$app->request->getBodyParams();
        $prompt = $body['prompt'] ?? '';
        $userId = $this->getCurrentUserId();
        $includeSuggestionsRaw = $body['includeSuggestions'] ?? true;
        $includeSuggestions = filter_var($includeSuggestionsRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($includeSuggestions === null) {
            $includeSuggestions = true;
        }
        $allowExploratoryRaw = $body['allowExploratory'] ?? false;
        $allowExploratory = filter_var($allowExploratoryRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($allowExploratory === null) {
            $allowExploratory = false;
        }

        if (empty($prompt)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'A "prompt" is required'];
        }

        // Pre-flight: block prompts that would require patron PII tables (users.*,
        // feesfines.*, audit.*) before spending AI tokens. The execution-time check in
        // SqlBuilderService::validateTablePolicy() remains as the authoritative safety
        // net; this is an early-exit optimisation only.
        $promptLower = strtolower($prompt);
        $blockedPatterns = [
            // Patron personal fields
            '/\bpatron\s+(name|email|address|phone|barcode|contact|record|profile|detail)\b/',
            '/\bborrower\s+(name|email|address|phone|barcode|contact|record)\b/',
            '/\buser\s+(email|address|phone|barcode|personal|contact)\b/',
            // General PII phrasing
            '/\b(personal|contact)\s+information\b/',
            '/\bpatron\s+personal\b/',
            '/\bpii\b/',
            // Enumerate all patrons / borrowers (highly likely to require users__t)
            '/\b(list|show|find|get|display|return|report\s+on|retrieve)\s+(all\s+)?patrons\b/',
            '/\b(list|show|find|get|display|return|report\s+on|retrieve)\s+(all\s+)?borrowers\b/',
            // Patron accounts / fee records with patron identity
            '/\bpatron\s+(fee|fine|account|block|debt)\b/',
            '/\bborrower\s+(fee|fine|account|block)\b/',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $promptLower)) {
                Yii::warning(
                    "Blocked pre-flight prompt (patron PII pattern): {$prompt}",
                    'nl2sql.prompt_block'
                );
                Yii::$app->response->statusCode = 403;
                return $this->finalizeAskResponse([
                    'error' => 'Queries about patron personal information or individual patron records are not supported. This system provides aggregate and operational library reporting only.',
                    'route' => 'blocked',
                    'routeReason' => 'ask_policy_block',
                ], $prompt, $userId, [
                    'policyBlocked' => true,
                    'parentGenerationId' => $body['parentGenerationId'] ?? null,
                ]);
            }
        }

        // Resolve campus: request body overrides user's saved preference
        $campus = $body['campus'] ?? null;
        if ($campus === null) {
            if ($userId) {
                $user = User::findOne($userId);
                $campus = $user ? ($user->default_campus ?: 'Smith College') : 'Smith College';
            } else {
                $campus = 'Smith College';
            }
        }

        $followUpContext = null;
        $parentGenerationId = null;
        try {
            $followUpContext = $this->normalizeFollowUpContext($body['followUpContext'] ?? null);
            if (($body['followUpContext'] ?? null) !== null && $followUpContext === null) {
                $status = Yii::$app->response->statusCode ?: 400;
                $messages = [
                    400 => 'Follow-up context is missing previous SQL.',
                    403 => 'Forbidden',
                    404 => 'History job not found.',
                    409 => 'History job is not completed.',
                ];
                Yii::$app->response->statusCode = $status;
                $rawFollowUpContext = $body['followUpContext'] ?? null;
                $isHistoryFollowUp = is_array($rawFollowUpContext)
                    && ($rawFollowUpContext['source'] ?? null) === 'history';
                return $this->finalizeAskResponse([
                    'error' => $messages[$status] ?? 'Invalid follow-up context.',
                    'route' => 'follow_up_context_rejected',
                    'routeReason' => 'invalid_follow_up_context',
                ], $prompt, $userId, [
                    'followUpContext' => null,
                    'parentGenerationId' => $isHistoryFollowUp
                        ? null
                        : ($body['parentGenerationId'] ?? null),
                ]);
            }
            $parentGenerationId = $this->resolveAskParentGenerationId(
                $body,
                $followUpContext
            );

            $effectivePrompt = $followUpContext !== null
                ? $this->buildFollowUpPrompt($prompt, $followUpContext)
                : $prompt;

            $generationPrompt = $effectivePrompt;
            $initialSql = null;
            $result = [];
            $params = is_array(Yii::$app->params ?? null) ? Yii::$app->params : [];
            $coordinatorEnabled = !array_key_exists('nl2sqlCoordinatorEnabled', $params)
                || (bool)$params['nl2sqlCoordinatorEnabled'];

            if ($coordinatorEnabled) {
                $result = AskGenerationCoordinatorService::run(
                    $prompt,
                    function () use (
                        $prompt,
                        $campus,
                        $userId,
                        $allowExploratory,
                        $effectivePrompt,
                        &$generationPrompt,
                        &$initialSql,
                        &$result
                    ): array {
                        try {
                            $generationTransport = null;
                            $result = GeminiService::generateSqlWithShadow(
                                $prompt,
                                $campus ?: null,
                                $userId,
                                $allowExploratory,
                                $effectivePrompt,
                                $generationTransport
                            );
                            $generationPrompt = is_array($generationTransport)
                                ? (string)($generationTransport['generationPrompt'] ?? $effectivePrompt)
                                : $effectivePrompt;
                            $initialSql = isset($result['sql']) ? (string)$result['sql'] : null;
                            if (!isset($result['dataSource'])) {
                                $result['dataSource'] = 'folio';
                            }
                            $result = $this->validateAndRepairNlResult(
                                $result,
                                $prompt,
                                $campus ?: null,
                                null,
                                null,
                                $generationPrompt
                            );
                            return $this->coordinatorOutcomeFromResult($result);
                        } catch (\Throwable $exception) {
                            return $this->coordinatorOutcomeFromFailure(
                                $exception,
                                $prompt,
                                $campus ?: null,
                                $result
                            );
                        }
                    },
                    function () use (
                        $prompt,
                        $campus,
                        $userId,
                        &$generationPrompt,
                        &$result
                    ): array {
                        try {
                            $evidence = is_array($result['_askEvidence'] ?? null)
                                ? $result['_askEvidence']
                                : [];
                            $resolvedFilters = is_array($evidence['resolvedReferenceFilters'] ?? null)
                                ? $evidence['resolvedReferenceFilters']
                                : [];
                            $result = GeminiService::generateFreshAiBuiltSql(
                                $prompt,
                                $generationPrompt,
                                $campus ?: null,
                                $resolvedFilters,
                                'coordinator_continuation',
                                $userId
                            );
                            if (!isset($result['dataSource'])) {
                                $result['dataSource'] = 'folio';
                            }
                            $result = $this->validateAndRepairNlResult(
                                $result,
                                $prompt,
                                $campus ?: null,
                                null,
                                null,
                                $generationPrompt
                            );
                            return $this->coordinatorOutcomeFromResult($result);
                        } catch (\Throwable $exception) {
                            return $this->coordinatorOutcomeFromFailure(
                                $exception,
                                $prompt,
                                $campus ?: null,
                                $result
                            );
                        }
                    }
                );
            } else {
                $generationTransport = null;
                $result = GeminiService::generateSqlWithShadow(
                    $prompt,
                    $campus ?: null,
                    $userId,
                    $allowExploratory,
                    $effectivePrompt,
                    $generationTransport
                );
                $generationPrompt = is_array($generationTransport)
                    ? (string)($generationTransport['generationPrompt'] ?? $effectivePrompt)
                    : $effectivePrompt;
                $initialSql = isset($result['sql']) ? (string)$result['sql'] : null;
                if (!isset($result['dataSource'])) {
                    $result['dataSource'] = 'folio';
                }
                $result = $this->validateAndRepairNlResult(
                    $result,
                    $prompt,
                    $campus ?: null,
                    null,
                    null,
                    $generationPrompt
                );
            }

            if (!array_key_exists('suggestions', $result) && !empty($result['sql'])) {
                $result['suggestions'] = [];
            }
            if ($includeSuggestions && empty($result['needsClarification']) && !empty($result['sql'])) {
                try {
                    $result['suggestions'] = GeminiService::suggestFollowUpQueries(
                        $effectivePrompt,
                        (string)($result['sql'] ?? ''),
                        (string)($result['explanation'] ?? ''),
                        $campus ?: null
                    );
                } catch (\Throwable $suggestionError) {
                    Yii::warning(
                        'Follow-up suggestion generation failed: ' . $suggestionError->getMessage(),
                        'nl2sql.suggestions'
                    );
                }
            }

            return $this->finalizeAskResponse($result, $prompt, $userId, [
                'campus' => $campus ?: null,
                'followUpContext' => $followUpContext,
                'parentGenerationId' => $parentGenerationId,
                'initialSql' => $initialSql,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->finalizeAskResponse(
                $this->attachTrustedAskEvidence(
                    $this->buildAskContinuationFromFailure($e, $prompt, $campus ?? null),
                    isset($result) && is_array($result) ? $result : []
                ),
                $prompt,
                $userId,
                [
                    'campus' => $campus ?? null,
                    'followUpContext' => $followUpContext,
                    'parentGenerationId' => $parentGenerationId,
                    'initialSql' => $initialSql ?? null,
                ]
            );
        } catch (\RuntimeException $e) {
            if ($e instanceof \app\exceptions\DatabaseQueryCancelledException) {
                return $this->finalizeAskResponse(
                    $this->attachTrustedAskEvidence(
                        $this->buildAskContinuationFromFailure($e, $prompt, $campus ?? null),
                        isset($result) && is_array($result) ? $result : []
                    ),
                    $prompt,
                    $userId,
                    [
                        'campus' => $campus ?? null,
                        'followUpContext' => $followUpContext,
                        'parentGenerationId' => $parentGenerationId,
                        'initialSql' => $initialSql ?? null,
                    ]
                );
            }
            if (GeminiService::isAiTimeoutMessage($e->getMessage())) {
                Yii::warning(
                    'NL2SQL AI timeout: ' . $e->getMessage(),
                    'nl2sql.timeout'
                );
                Yii::$app->response->statusCode = 504;
                return $this->finalizeAskResponse([
                    'errorType' => 'ai_timeout',
                    'error' => 'The AI request timed out. Your question is fine; the model or network took too long to respond. Please try again, or simplify the request if it keeps happening.',
                    'route' => 'ai_timeout',
                    'routeReason' => 'ai_provider_timeout',
                ], $prompt, $userId, [
                    'campus' => $campus ?? null,
                    'followUpContext' => $followUpContext,
                    'parentGenerationId' => $parentGenerationId,
                    'initialSql' => $initialSql ?? null,
                ]);
            }
            if (GeminiService::isAiProviderFailureMessage($e->getMessage())) {
                Yii::warning(
                    'NL2SQL AI provider failure: ' . $e->getMessage(),
                    'nl2sql.provider'
                );
                return $this->finalizeAskResponse(
                    $this->buildAiProviderFailureResponse(
                        isset($result) && is_array($result) ? $result : []
                    ),
                    $prompt,
                    $userId,
                    [
                        'campus' => $campus ?? null,
                        'followUpContext' => $followUpContext,
                        'parentGenerationId' => $parentGenerationId,
                        'initialSql' => $initialSql ?? null,
                    ]
                );
            }
            return $this->finalizeAskResponse(
                $this->attachTrustedAskEvidence(
                    $this->buildAskContinuationFromFailure($e, $prompt, $campus ?? null),
                    isset($result) && is_array($result) ? $result : []
                ),
                $prompt,
                $userId,
                [
                    'campus' => $campus ?? null,
                    'followUpContext' => $followUpContext,
                    'parentGenerationId' => $parentGenerationId,
                    'initialSql' => $initialSql ?? null,
                ]
            );
        }
    }

    private function coordinatorOutcomeFromResult(array $result): array
    {
        if (isset($result['sql'])) {
            return ['state' => 'handled', 'result' => $result];
        }

        $errorType = trim((string)($result['errorType'] ?? ''));
        if ($errorType === 'request_blocked') {
            return ['state' => 'request_blocked', 'reason' => 'explicit_write_intent', 'result' => $result];
        }
        if (in_array($errorType, [
            'ai_provider_failure',
            'ai_timeout',
            'postgres_connectivity',
            'policy_blocked',
            'database_cancelled',
            'database_resource_limit',
            'configured_resource_limit',
        ], true)) {
            return [
                'state' => 'infrastructure_failure',
                'reason' => $errorType,
                'result' => $result,
            ];
        }
        if ($errorType === 'sql_generation_failed') {
            return [
                'state' => 'candidate_rejected',
                'reason' => (string)($result['routeReason'] ?? 'sql_repair_exhausted'),
            ];
        }

        return [
            'state' => 'not_handled',
            'reason' => (string)($result['routeReason'] ?? 'initial_attempt_not_handled'),
        ];
    }

    private function coordinatorOutcomeFromFailure(
        \Throwable $error,
        string $prompt,
        $campus,
        array $candidate = []
    ): array {
        $message = trim($error->getMessage());
        if ($error instanceof \app\exceptions\PolicyViolationException
            || $this->isAskSecurityPolicyFailure($message)
        ) {
            return [
                'state' => 'infrastructure_failure',
                'reason' => 'policy_blocked',
                'result' => $this->attachTrustedAskEvidence(
                    $this->buildAskContinuationFromFailure($error, $prompt, $campus),
                    $candidate
                ),
            ];
        }
        if ($error instanceof \app\exceptions\DatabaseQueryCancelledException) {
            return [
                'state' => 'infrastructure_failure',
                'reason' => 'database_cancelled',
                'result' => $this->attachTrustedAskEvidence(
                    $this->buildAskContinuationFromFailure($error, $prompt, $campus),
                    $candidate
                ),
            ];
        }
        if (GeminiService::isAiTimeoutMessage($message)) {
            Yii::$app->response->statusCode = 504;
            return [
                'state' => 'infrastructure_failure',
                'reason' => 'ai_timeout',
                'result' => [
                    'errorType' => 'ai_timeout',
                    'error' => 'The AI request timed out. Your question is fine; the model or network took too long to respond. Please try again, or simplify the request if it keeps happening.',
                    'route' => 'ai_timeout',
                    'routeReason' => 'ai_provider_timeout',
                ],
            ];
        }
        if (GeminiService::isAiProviderFailureMessage($message)) {
            return [
                'state' => 'infrastructure_failure',
                'reason' => 'ai_provider_failure',
                'result' => $this->buildAiProviderFailureResponse($candidate),
            ];
        }
        if ($this->isAskPostgresConnectivityFailure($message)) {
            return [
                'state' => 'infrastructure_failure',
                'reason' => 'postgres_connectivity',
                'result' => $this->buildAskPostgresConnectivityFailure(),
            ];
        }
        if ($this->isAskPreflightResourceFailure($message)) {
            return [
                'state' => 'infrastructure_failure',
                'reason' => 'database_resource_limit',
                'result' => $this->buildDatabaseResourceLimitResponse($candidate),
            ];
        }

        $candidateSql = trim((string)($candidate['sql'] ?? ''));
        if ($error instanceof \app\exceptions\ExploratorySqlValidationException) {
            $candidateSql = trim($error->getCandidateSql());
        }
        return [
            'state' => 'candidate_rejected',
            'reason' => $error instanceof \app\exceptions\ExploratorySqlValidationException
                ? $error->getSafeCategory()
                : 'generation_candidate_rejected',
            'candidateSqlHash' => $candidateSql === '' ? null : hash('sha256', $candidateSql),
        ];
    }

    private function resolveAskParentGenerationId(array $body, $followUpContext): ?string
    {
        if (
            is_array($followUpContext)
            && ($followUpContext['source'] ?? null) === 'history'
        ) {
            $trustedHistoryGenerationId = trim((string)(
                $followUpContext['parentGenerationId'] ?? ''
            ));
            return $trustedHistoryGenerationId === ''
                ? null
                : $trustedHistoryGenerationId;
        }

        $parentGenerationId = trim((string)($body['parentGenerationId'] ?? ''));
        return $parentGenerationId === '' ? null : $parentGenerationId;
    }

    private function finalizeAskResponse(array $result, string $prompt, $userId, array $context): array
    {
        $result = AskResponseContractService::normalizeMode($result);
        $result = AskResponseContractService::normalizeGenerationProvenance($result);
        $evidence = AskGenerationEvidenceService::build($result, $context + ['prompt' => $prompt]);
        $classification = AskConfidenceClassificationService::classify($evidence);
        try {
            $record = $this->administratorReviewService()->recordGeneration(
                $evidence + $classification + ['userId' => $userId]
            );
            $result['generationId'] = $record['generationId'];
            $result['conversationId'] = $record['conversationId'];
        } catch (\Throwable $exception) {
            Yii::warning('Ask review persistence failed: ' . get_class($exception), 'nl2sql.review');
        }
        $result['reviewRequired'] = $classification['reviewRequired'];
        $result['reviewNotice'] = AskUserExplanationService::notice(
            (string)($result['mode'] ?? ''),
            (bool)$classification['reviewRequired'],
            $classification['reviewReasons'],
            is_array($result['assumptions'] ?? null) ? $result['assumptions'] : []
        );
        unset($result['semanticValidation'], $result['_askEvidence']);
        return AskResponseContractService::toUserResponse($result);
    }

    private function attachTrustedAskEvidence(array $response, array $generatedResult): array
    {
        $evidence = is_array($generatedResult['_askEvidence'] ?? null)
            ? $generatedResult['_askEvidence']
            : [];

        if (!isset($evidence['finalSql']) && isset($generatedResult['sql'])) {
            $evidence['finalSql'] = (string)$generatedResult['sql'];
        }
        if (!isset($evidence['repairAttempts']) && array_key_exists('repairAttempts', $generatedResult)) {
            $evidence['repairAttempts'] = $this->clampExploratoryRepairAttempts(
                $generatedResult['repairAttempts']
            );
        }
        $evidence = array_merge(
            $evidence,
            is_array($response['_askEvidence'] ?? null) ? $response['_askEvidence'] : []
        );
        $failureStatus = $this->askFailureValidationStatus($response);
        if ($failureStatus !== null) {
            $evidence['validationStatus'] = $failureStatus;
        }
        if ($evidence !== []) {
            $response['_askEvidence'] = $evidence;
        }
        return $response;
    }

    private function askFailureValidationStatus(array $response): ?string
    {
        $status = (string)($response['validationSummary']['status'] ?? '');
        if ($status === 'exhausted') {
            return 'exhausted';
        }
        if ($status === 'rejected') {
            return 'rejected';
        }

        $route = (string)($response['route'] ?? '');
        if (
            array_key_exists('error', $response)
            || trim((string)($response['errorType'] ?? '')) !== ''
            || in_array($route, [
                'blocked',
                'exploratory_recovery',
                'postgres_connectivity_recovery',
                'database_cancelled',
                'ai_timeout',
                'follow_up_context_rejected',
            ], true)
        ) {
            return 'rejected';
        }

        return null;
    }

    protected function administratorReviewService()
    {
        return new AdministratorReviewService();
    }

    /**
     * GET /api/admin/report-reviews
     */
    public function actionReportReviewList()
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }

        $limit = max(1, min(100, (int)Yii::$app->request->get('limit', 25)));
        $offset = max(0, (int)Yii::$app->request->get('offset', 0));
        $allowedStatuses = ['pending', 'in_review', 'resolved', 'dismissed'];
        $allowedDispositions = $this->reportReviewDispositions();
        $status = strtolower(trim((string)Yii::$app->request->get('status', 'pending')));
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'pending';
        }
        $disposition = strtolower(trim((string)Yii::$app->request->get('disposition', '')));

        $query = (new \yii\db\Query())
            ->from(['r' => 'ai_report_reviews'])
            ->innerJoin(['g' => 'ai_report_generations'], 'g.id = r.generation_id')
            ->where(['r.status' => $status]);
        if (in_array($disposition, $allowedDispositions, true)) {
            $query->andWhere(['r.disposition' => $disposition]);
        }

        $total = (int)(clone $query)->count('*', Yii::$app->db);
        $rows = $query
            ->select($this->reportReviewSummarySelect())
            ->orderBy(['r.created_at' => SORT_ASC, 'r.id' => SORT_ASC])
            ->limit($limit)
            ->offset($offset)
            ->all(Yii::$app->db);

        return [
            'items' => array_map([$this, 'mapReportReviewSummary'], $rows),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => $total,
            ],
        ];
    }

    /**
     * GET /api/admin/report-reviews/<id>
     */
    public function actionReportReviewDetail($id)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }

        $row = $this->reportReviewDetailRow((string)$id);
        if ($row === null) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Review not found'];
        }

        return $this->mapReportReviewDetail($row);
    }

    /**
     * POST /api/admin/report-reviews/<id>/claim
     */
    public function actionReportReviewClaim($id)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }

        try {
            $this->administratorReviewService()->claim((string)$id, (int)$this->getCurrentUserId());
        } catch (\DomainException $exception) {
            Yii::$app->response->statusCode = 409;
            return ['error' => 'Review is no longer available to claim'];
        }

        return $this->actionReportReviewDetail((string)$id);
    }

    /**
     * PATCH /api/admin/report-reviews/<id>
     */
    public function actionReportReviewUpdate($id)
    {
        if (($forbidden = $this->requireAdministrator()) !== null) {
            return $forbidden;
        }

        $body = Yii::$app->request->getBodyParams();
        if (!is_array($body) || ($body !== [] && array_keys($body) === range(0, count($body) - 1))) {
            return $this->invalidReportReviewUpdate('Request body must be a JSON object');
        }
        $status = strtolower(trim((string)($body['status'] ?? '')));
        $disposition = strtolower(trim((string)($body['disposition'] ?? '')));
        $advisoryState = strtolower(trim((string)($body['advisoryState'] ?? 'none')));
        $supersededByJobId = isset($body['supersededByJobId'])
            ? trim((string)$body['supersededByJobId'])
            : null;
        $notes = trim((string)($body['notes'] ?? ''));
        if (array_key_exists('takeover', $body) && !is_bool($body['takeover'])) {
            return $this->invalidReportReviewUpdate('takeover must be a boolean');
        }
        $takeover = $body['takeover'] ?? false;

        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            return $this->invalidReportReviewUpdate('status must be resolved or dismissed');
        }
        if (!in_array($disposition, $this->reportReviewDispositions(), true)) {
            return $this->invalidReportReviewUpdate('A valid disposition is required');
        }
        if (!in_array($advisoryState, ['none', 'cautioned', 'superseded'], true)) {
            return $this->invalidReportReviewUpdate('Invalid advisory state');
        }
        if ($status === 'dismissed' && $advisoryState !== 'none') {
            return $this->invalidReportReviewUpdate('Dismissed reviews cannot change report advisory state');
        }
        if ($advisoryState === 'superseded') {
            if ($supersededByJobId === null || $supersededByJobId === '') {
                return $this->invalidReportReviewUpdate('A superseded review requires a replacement job');
            }
        } elseif ($supersededByJobId !== null && $supersededByJobId !== '') {
            return $this->invalidReportReviewUpdate('A replacement job is allowed only for supersession');
        } else {
            $supersededByJobId = null;
        }

        try {
            if ($status === 'dismissed') {
                $this->administratorReviewService()->dismiss(
                    (string)$id,
                    (int)$this->getCurrentUserId(),
                    $disposition,
                    $notes,
                    $takeover
                );
            } else {
                $this->administratorReviewService()->resolve(
                    (string)$id,
                    (int)$this->getCurrentUserId(),
                    $disposition,
                    $notes,
                    $advisoryState,
                    $supersededByJobId,
                    $takeover
                );
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->invalidReportReviewUpdate('Invalid review update');
        } catch (\DomainException $exception) {
            Yii::$app->response->statusCode = 409;
            return ['error' => 'Review is no longer available to update'];
        }

        return $this->actionReportReviewDetail((string)$id);
    }

    private function invalidReportReviewUpdate(string $message): array
    {
        Yii::$app->response->statusCode = 422;
        return ['error' => 'Invalid review update', 'detail' => $message];
    }

    private function reportReviewDispositions(): array
    {
        return [
            'acceptable',
            'assumption_change',
            'deterministic_candidate',
            'generation_defect',
            'data_unavailable',
            'specialist_interpretation',
        ];
    }

    private function reportReviewSummarySelect(): array
    {
        return [
            'id' => 'r.id',
            'generationId' => 'r.generation_id',
            'status' => 'r.status',
            'disposition' => 'r.disposition',
            'advisoryState' => 'r.advisory_state',
            'supersededByJobId' => 'r.superseded_by_job_id',
            'reviewedBy' => 'r.reviewed_by',
            'claimedAt' => 'r.claimed_at',
            'resolvedAt' => 'r.resolved_at',
            'createdAt' => 'r.created_at',
            'updatedAt' => 'r.updated_at',
            'question' => 'g.original_question',
            'queryJobId' => 'g.query_job_id',
            'userId' => 'g.user_id',
            'executionMode' => 'g.execution_mode',
            'route' => 'g.route',
            'routeReason' => 'g.route_reason',
            'validationStatus' => 'g.validation_status',
            'reviewReasonsJson' => 'g.review_reasons_json',
        ];
    }

    private function reportReviewDetailRow(string $id): ?array
    {
        $select = array_merge($this->reportReviewSummarySelect(), [
            'administratorNotes' => 'r.administrator_notes',
            'conversationId' => 'g.conversation_id',
            'parentGenerationId' => 'g.parent_generation_id',
            'followUpContextJson' => 'g.follow_up_context',
            'responseMode' => 'g.response_mode',
            'generatedSql' => 'g.generated_sql',
            'sqlHash' => 'g.sql_hash',
            'assumptionsJson' => 'g.assumptions_json',
            'userNoticeJson' => 'g.user_notice_json',
            'confidenceEvidenceJson' => 'g.confidence_evidence_json',
            'initialStructureJson' => 'g.initial_structure_json',
            'finalStructureJson' => 'g.final_structure_json',
            'provenanceJson' => 'g.provenance_json',
            'generationCreatedAt' => 'g.created_at',
            'linkedAt' => 'g.linked_at',
        ]);
        $row = (new \yii\db\Query())
            ->select($select)
            ->from(['r' => 'ai_report_reviews'])
            ->innerJoin(['g' => 'ai_report_generations'], 'g.id = r.generation_id')
            ->where(['r.id' => $id])
            ->one(Yii::$app->db);

        return $row === false ? null : $row;
    }

    private function mapReportReviewSummary(array $row): array
    {
        $row['reviewedBy'] = $row['reviewedBy'] === null ? null : (int)$row['reviewedBy'];
        $row['userId'] = $row['userId'] === null ? null : (int)$row['userId'];
        $row['reviewReasons'] = $this->decodeReportReviewJson($row['reviewReasonsJson'], []);
        unset($row['reviewReasonsJson']);
        return $row;
    }

    private function mapReportReviewDetail(array $row): array
    {
        $row = $this->mapReportReviewSummary($row);
        $jsonFields = [
            'followUpContextJson' => ['followUpContext', null],
            'assumptionsJson' => ['assumptions', []],
            'userNoticeJson' => ['userNotice', null],
            'confidenceEvidenceJson' => ['confidenceEvidence', []],
            'initialStructureJson' => ['initialStructure', null],
            'finalStructureJson' => ['finalStructure', null],
            'provenanceJson' => ['provenance', []],
        ];
        foreach ($jsonFields as $source => $mapping) {
            $row[$mapping[0]] = $this->decodeReportReviewJson($row[$source], $mapping[1]);
            unset($row[$source]);
        }
        return $row;
    }

    private function decodeReportReviewJson($value, $fallback)
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
    }

    private function buildAskContinuationFromFailure(
        \Throwable $error,
        string $prompt,
        $campus = null,
        string $routeReason = 'ask_generation_recovery'
    ): array {
        $message = trim($error->getMessage());
        if ($error instanceof \app\exceptions\DatabaseQueryCancelledException) {
            $response = $this->buildDatabaseCancelledResponse();
            $response['validationSummary'] = [
                'status' => 'rejected',
                'repairAttempts' => 0,
            ];
            $response['_askEvidence'] = [
                'validationStatus' => 'rejected',
            ];
            return $response;
        }
        // Prefer the typed policy violation; fall back to message matching for
        // policy errors that bubble up from elsewhere as plain exceptions.
        if ($error instanceof \app\exceptions\PolicyViolationException || $this->isAskSecurityPolicyFailure($message)) {
            Yii::$app->response->statusCode = 403;
            return [
                'errorType' => 'policy_blocked',
                'error' => $this->buildAskPolicyBlockMessage($message),
                'route' => 'blocked',
                'routeReason' => 'ask_policy_block',
                '_askEvidence' => [
                    'validationStatus' => 'rejected',
                ],
            ];
        }

        if ($this->isAskPostgresConnectivityFailure($message)) {
            return $this->buildAskPostgresConnectivityFailure();
        }

        $params = is_array(Yii::$app->params ?? null) ? Yii::$app->params : [];
        $twoLaneEnabled = !array_key_exists('nl2sqlTwoLaneEnabled', $params)
            || (bool)$params['nl2sqlTwoLaneEnabled'];
        if ($twoLaneEnabled) {
            return $this->buildAskGenerationFailedResponse();
        }

        Yii::$app->response->statusCode = 200;
        Yii::warning(
            'Ask generation recovered with continuation response category: generation_failure',
            'nl2sql.ask_recovery'
        );

        return [
            'needsClarification' => false,
            'mode' => 'exploratory',
            'message' => 'I could not build a report I could safely run. Your request is preserved, and you can retry it or adjust one part of the question.',
            'exploratoryNotice' => [
                'title' => 'AI-assisted query',
                'message' => 'I could not build a report I could safely run. Your request is preserved, and you can retry it or adjust one part of the question.',
                'detail' => 'Similar wording may produce different SQL until this request type is reviewed and promoted to a verified report pattern.',
                'reason' => $routeReason,
            ],
            'warnings' => [
                'The first attempt could not produce fully validated SQL.',
            ],
            'suggestions' => [],
            'route' => 'exploratory_recovery',
            'routeReason' => $routeReason,
            'validationSummary' => [
                'status' => 'rejected',
                'repairAttempts' => 0,
            ],
            'recoveryContext' => [
                'originalQuestion' => $prompt,
                'campus' => $campus,
                'promptFingerprint' => $this->fingerprintPrompt($prompt),
            ],
        ];
    }

    private function buildAskGenerationFailedResponse(): array
    {
        return [
            'errorType' => 'sql_generation_failed',
            'message' => 'Report Explorer could not build a valid report after retrying. Please retry.',
            'route' => 'generation_failed',
            'routeReason' => 'ask_generation_failed',
            'validationSummary' => [
                'status' => 'rejected',
                'repairAttempts' => 0,
            ],
            '_askEvidence' => [
                'finalSql' => null,
                'repairAttempts' => 0,
                'validationStatus' => 'rejected',
            ],
        ];
    }

    private function isAskPostgresConnectivityFailure(string $message, array $preflightResult = []): bool
    {
        if ($this->isAskSqlStateClass($preflightResult, ['08'])
            || ($this->isAskSqlStateClass($preflightResult, ['57'])
                && !$this->isAskSqlState($preflightResult, ['57014']))
        ) {
            return true;
        }
        return preg_match(
            '/\bSQLSTATE\[08[0-9A-Z]{3}\]|\bSQLSTATE\[HY000\].*(?:timeout expired|could not connect|connection refused|no connection|SSL SYSCALL|server closed the connection)|'
                . '\b(?:timeout expired|could not connect to server|connection refused|connection does not exist|connection is closed|no connection to the server|no route to host|server closed the connection)\b/i',
            $message
        ) === 1;
    }

    private function buildDatabaseCancelledResponse(): array
    {
        Yii::$app->response->statusCode = 503;
        return [
            'errorType' => 'database_cancelled',
            'error' => 'Database validation was cancelled before the query could run. Please retry the request.',
            'route' => 'database_cancelled',
            'routeReason' => 'database_query_cancelled',
        ];
    }

    private function buildAskPostgresConnectivityFailure(): array
    {
        Yii::$app->response->statusCode = 200;
        $message = 'I could not connect to the FOLIO reporting database to validate this query. If you are off campus, connect to VPN and try again.';

        return [
            'errorType' => 'postgres_connectivity',
            'message' => $message,
            'route' => 'postgres_connectivity',
            'routeReason' => 'database_connectivity',
            'validationSummary' => [
                'status' => 'rejected',
                'repairAttempts' => 0,
            ],
            '_askEvidence' => [
                'validationStatus' => 'rejected',
            ],
        ];
    }

    /**
     * Normalize a client-supplied clarification batch id to a value that fits the
     * CHAR(36) column. Returns null for empty, over-length, or unsafe input so an
     * insert can never overflow (MySQL 1406) or silently truncate.
     */
    private function normalizeClarificationBatchId($raw): ?string
    {
        $value = trim((string)$raw);
        if ($value === '' || strlen($value) > 36) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]{1,36}$/', $value) === 1 ? $value : null;
    }

    private function isAskSecurityPolicyFailure(string $message): bool
    {
        return preg_match(
            '/\b(?:patron personal|individual patron|pii|forbidden|blocked|not allowed|table policy|users\.|feesfines\.|audit\.)\b/i',
            $message
        ) === 1;
    }

    private function buildAskPolicyBlockMessage(string $message): string
    {
        return 'This request is blocked by reporting data policy. '
            . 'Try an aggregate operational report instead, such as counts, totals, trends, or grouped activity that does not identify individual patrons.';
    }

    /**
     * POST /api/clarifications/resolve — persist a user's clarification choice.
     */
    public function actionClarificationResolve()
    {
        $body = Yii::$app->request->getBodyParams();

        $originalQuestion = trim((string)($body['originalQuestion'] ?? ''));
        $clarificationKey = trim((string)($body['clarificationKey'] ?? ''));
        if ($originalQuestion === '' || $clarificationKey === '') {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'originalQuestion and clarificationKey are required.'];
        }

        $selectedOptionIds = $body['selectedOptionIds'] ?? [];
        if (!is_array($selectedOptionIds)) {
            $selectedOptionIds = [];
        }

        $options = $body['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }

        $resolvedFilter = $body['resolvedFilter'] ?? null;
        if ($resolvedFilter !== null && !is_array($resolvedFilter)) {
            $resolvedFilter = null;
        }

        $detectedTerms = $body['detectedTerms'] ?? [];
        if (!is_array($detectedTerms)) {
            $detectedTerms = [];
        }

        $db = Yii::$app->db;
        $items = $body['items'] ?? [];
        if (is_array($items) && !empty($items)) {
            $ids = [];
            $batchId = $this->normalizeClarificationBatchId($body['clarificationBatchId'] ?? null);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemKey = trim((string)($item['clarificationKey'] ?? ''));
                if ($itemKey === '') {
                    continue;
                }
                $itemOptions = $item['options'] ?? [];
                $itemSelected = $item['selectedOptionIds'] ?? [];
                $itemFilter = $item['resolvedFilter'] ?? null;
                if (!is_array($itemOptions)) {
                    $itemOptions = [];
                }
                if (!is_array($itemSelected)) {
                    $itemSelected = [];
                }
                if ($itemFilter !== null && !is_array($itemFilter)) {
                    $itemFilter = null;
                }

                $db->createCommand()->insert('ai_clarification_events', [
                    'user_id' => $this->getCurrentUserId(),
                    'clarification_batch_id' => $batchId,
                    'original_question' => $originalQuestion,
                    'clarification_key' => $itemKey,
                    'term' => trim((string)($item['term'] ?? '')) ?: null,
                    'detected_terms_json' => json_encode(array_values($detectedTerms)),
                    'options_json' => json_encode(array_values($itemOptions)),
                    'selected_option_ids_json' => json_encode(array_values($itemSelected)),
                    'free_text_response' => trim((string)($item['freeTextResponse'] ?? '')) ?: null,
                    'resolved_filter_json' => $itemFilter !== null ? json_encode($itemFilter) : null,
                    'selected_source_table' => trim((string)($item['selectedSourceTable'] ?? ($itemFilter['table'] ?? ''))) ?: null,
                    'selected_source_id' => trim((string)($item['selectedSourceId'] ?? '')) ?: null,
                    'selected_value' => trim((string)($item['selectedValue'] ?? ($itemFilter['value'] ?? ''))) ?: null,
                    'confidence' => trim((string)($item['confidence'] ?? '')) ?: null,
                    'promotion_status' => trim((string)($item['promotionStatus'] ?? 'none')) ?: 'none',
                    'generated_sql' => trim((string)($body['generatedSql'] ?? '')) ?: null,
                    'result_status' => trim((string)($body['resultStatus'] ?? '')) ?: null,
                ])->execute();
                $ids[] = (int)$db->getLastInsertID();
            }

            return [
                'ids' => $ids,
                'message' => 'Clarifications saved.',
            ];
        }

        $db->createCommand()->insert('ai_clarification_events', [
            'user_id' => $this->getCurrentUserId(),
            'clarification_batch_id' => $this->normalizeClarificationBatchId($body['clarificationBatchId'] ?? null),
            'original_question' => $originalQuestion,
            'clarification_key' => $clarificationKey,
            'term' => trim((string)($body['term'] ?? '')) ?: null,
            'detected_terms_json' => json_encode(array_values($detectedTerms)),
            'options_json' => json_encode(array_values($options)),
            'selected_option_ids_json' => json_encode(array_values($selectedOptionIds)),
            'free_text_response' => trim((string)($body['freeTextResponse'] ?? '')) ?: null,
            'resolved_filter_json' => $resolvedFilter !== null ? json_encode($resolvedFilter) : null,
            'selected_source_table' => trim((string)($body['selectedSourceTable'] ?? ($resolvedFilter['table'] ?? ''))) ?: null,
            'selected_source_id' => trim((string)($body['selectedSourceId'] ?? '')) ?: null,
            'selected_value' => trim((string)($body['selectedValue'] ?? ($resolvedFilter['value'] ?? ''))) ?: null,
            'confidence' => trim((string)($body['confidence'] ?? '')) ?: null,
            'promotion_status' => trim((string)($body['promotionStatus'] ?? 'none')) ?: 'none',
            'generated_sql' => trim((string)($body['generatedSql'] ?? '')) ?: null,
            'result_status' => trim((string)($body['resultStatus'] ?? '')) ?: null,
        ])->execute();

        return [
            'id' => (int)$db->getLastInsertID(),
            'message' => 'Clarification saved.',
        ];
    }

    /**
     * POST /api/query-feedback — persist a user's temperature-check result.
     */
    public function actionQueryFeedback()
    {
        $body = Yii::$app->request->getBodyParams();
        $generationId = trim((string)($body['generationId'] ?? $body['generation_id'] ?? ''));
        $queryJobId = trim((string)($body['queryJobId'] ?? $body['query_job_id'] ?? ''));
        $resultAccuracy = strtolower(trim((string)($body['resultAccuracy'] ?? '')));
        if ($generationId === '' || $queryJobId === '') {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'generationId and queryJobId are required.'];
        }
        if (!in_array($resultAccuracy, ['accurate', 'inaccurate', 'unsure'], true)) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'resultAccuracy must be accurate, inaccurate, or unsure.'];
        }

        $db = Yii::$app->db;
        $generation = (new \yii\db\Query())
            ->from('ai_report_generations')
            ->where(['id' => $generationId])
            ->one($db);
        $job = (new \yii\db\Query())
            ->from('query_jobs')
            ->where(['id' => $queryJobId])
            ->one($db);
        if (!is_array($generation) || !is_array($job)) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'The completed report could not be found.'];
        }

        $userId = $this->getCurrentUserId();
        $owned = $userId !== null
            && (int)($generation['user_id'] ?? 0) === $userId
            && (int)($job['user_id'] ?? 0) === $userId;
        $linked = trim((string)($generation['query_job_id'] ?? '')) === $queryJobId;
        $completedNlJob = ($job['status'] ?? null) === 'completed'
            && ($job['source'] ?? null) === 'nl';
        if (!$owned || !$linked || !$completedNlJob) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Feedback is only available for your completed report.'];
        }

        $originalQuestion = trim((string)($generation['original_question'] ?? ''));
        $generatedSql = trim((string)($job['sql_text'] ?? $generation['generated_sql'] ?? ''));
        if ($originalQuestion === '' || $generatedSql === '') {
            Yii::$app->response->statusCode = 409;
            return ['error' => 'The completed report is missing trusted generation evidence.'];
        }
        $sqlHash = trim((string)($job['sql_hash'] ?? $generation['sql_hash'] ?? ''));
        if ($sqlHash === '') {
            $sqlHash = hash('sha256', $this->normalizeSqlForTelemetry($generatedSql));
        }
        $dataSource = $this->normalizeDataSource($job['data_source'] ?? 'folio');
        $provenance = $this->decodeQueryMemoryJson($generation['provenance_json'] ?? null);
        $generationProvenance = trim((string)($provenance['generationProvenance'] ?? ''));
        if (!in_array($generationProvenance, ['verified_pattern', 'ai_built'], true)) {
            $generationProvenance = null;
        }
        $schemaMetadata = is_array($provenance['schemaMetadata'] ?? null)
            ? $provenance['schemaMetadata']
            : [];
        $schemaVersion = $schemaMetadata['version'] ?? $schemaMetadata['scraped_at'] ?? null;
        $directFingerprint = $schemaVersion !== null
            && trim((string)($schemaMetadata['contextHash'] ?? '')) !== ''
            ? QueryMemoryService::directReuseSchemaFingerprint($schemaMetadata)
            : null;
        $versionFingerprint = $schemaVersion !== null
            ? QueryMemoryService::schemaVersionFingerprint($schemaMetadata)
            : null;
        $jobMetadata = $this->decodeQueryMemoryJson($job['metadata'] ?? null);
        $authorizedScope = $this->normalizeQueryMemoryScope(
            is_array($jobMetadata['resolvedContext'] ?? null) ? $jobMetadata['resolvedContext'] : []
        );
        $scopeFingerprint = QueryMemoryService::scopeFingerprint($dataSource, $authorizedScope);
        $feedbackId = null;

        $db->transaction(function () use (
            $db,
            $userId,
            $generationId,
            $queryJobId,
            $originalQuestion,
            $generatedSql,
            $sqlHash,
            $generation,
            $dataSource,
            $resultAccuracy,
            $body,
            $generationProvenance,
            $directFingerprint,
            $versionFingerprint,
            $scopeFingerprint,
            &$feedbackId
        ): void {
            $db->createCommand()->insert('ai_query_feedback', [
                'user_id' => $userId,
                'generation_id' => $generationId,
                'query_job_id' => $queryJobId,
                'original_question' => $originalQuestion,
                'prompt_fingerprint' => $this->fingerprintPrompt($originalQuestion),
                'generated_sql' => $generatedSql,
                'sql_hash' => $sqlHash,
                'route' => trim((string)($generation['route'] ?? '')) ?: null,
                'route_reason' => trim((string)($generation['route_reason'] ?? '')) ?: null,
                'mode' => trim((string)($generation['response_mode'] ?? '')) ?: null,
                'data_source' => $dataSource,
                'result_accuracy' => $resultAccuracy,
                'feedback_note' => trim((string)($body['feedbackNote'] ?? '')) ?: null,
                'generation_provenance' => $generationProvenance,
                'direct_reuse_schema_fingerprint' => $directFingerprint,
                'schema_version_fingerprint' => $versionFingerprint,
                'scope_fingerprint' => $scopeFingerprint,
                'reuse_suppressed' => $resultAccuracy === 'inaccurate' ? 1 : 0,
            ])->execute();
            $feedbackId = (int)$db->getLastInsertID();

            if ($resultAccuracy === 'inaccurate') {
                $db->createCommand()->update('ai_query_feedback', [
                    'reuse_suppressed' => 1,
                    'admin_reuse_approved_at' => null,
                    'admin_reuse_approved_by' => null,
                ], [
                    'sql_hash' => $sqlHash,
                    'schema_version_fingerprint' => $versionFingerprint,
                    'scope_fingerprint' => $scopeFingerprint,
                ])->execute();
            }
        });

        return [
            'feedbackId' => $feedbackId,
            'resultAccuracy' => $resultAccuracy,
            'reuseSuppressed' => $resultAccuracy === 'inaccurate',
            'message' => 'Feedback saved.',
        ];
    }

    private function decodeQueryMemoryJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) && trim($value) !== '' ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * GET /api/campuses — list available campuses for the scope selector.
     */
    public function actionCampusList()
    {
        return [
            ['code' => 'ALL', 'name' => 'All Colleges'],
            ['code' => 'SC',  'name' => 'Smith College'],
            ['code' => 'AC',  'name' => 'Amherst College'],
            ['code' => 'MH',  'name' => 'Mount Holyoke College'],
            ['code' => 'UM',  'name' => 'University Of Massachusetts'],
            ['code' => 'HC',  'name' => 'Hampshire College'],
            ['code' => 'RP',  'name' => 'Five Colleges Collections'],
            ['code' => 'YB',  'name' => 'National Yiddish Book Center'],
        ];
    }

    /**
     * PATCH /api/user/campus — save the user's default campus preference.
     * Body: {campus: string}
     */
    public function actionCampusSave()
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Not authenticated'];
        }
        $user = User::findOne($userId);
        if (!$user) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'User not found'];
        }
        $body = Yii::$app->request->getBodyParams();
        $campus = $body['campus'] ?? null;
        if (empty($campus)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'campus is required'];
        }
        $allowed = ['All Colleges', 'Smith College', 'Amherst College', 'Mount Holyoke College', 'University Of Massachusetts', 'Hampshire College', 'Five Colleges Collections', 'National Yiddish Book Center'];
        if (!in_array($campus, $allowed)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Invalid campus value'];
        }
        $user->default_campus = $campus;
        $user->save(false);
        return ['defaultCampus' => $user->default_campus];
    }

    /**
     * POST /api/schema/ask — AI-powered schema Q&A.
     * Body: {question: string, selectedTable?: string}
     */
    public function actionSchemaAsk()
    {
        $body = Yii::$app->request->getBodyParams();
        $question = $body['question'] ?? '';
        $selectedTable = $body['selectedTable'] ?? null;

        if (empty($question)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'A "question" is required'];
        }

        try {
            $result = GeminiService::answerSchemaQuestion($question, $selectedTable);
            return $result;
        } catch (\RuntimeException $e) {
            Yii::$app->response->statusCode = 500;
            return ['error' => $e->getMessage()];
        }
    }

    // ─── Saved queries ────────────────────────────────────────────────

    /**
     * POST /api/saved — save a query definition.
     * Body: {name, description?, queryDefinition, generatedSql?}
     */
    public function actionSave()
    {
        $body = Yii::$app->request->getBodyParams();
        $queryDefinition = $body['queryDefinition'] ?? [];
        $generatedSql = $body['generatedSql'] ?? null;

        if (($queryDefinition['schemaIdentity'] ?? null) === 'ldlite') {
            try {
                $queryDefinition = BuilderQueryDefinitionNormalizerService::canonicalizeDefaultsForSave(
                    $queryDefinition
                );
                $normalizedDefinition = BuilderQueryDefinitionNormalizerService::normalize($queryDefinition);
            } catch (\InvalidArgumentException $e) {
                Yii::$app->response->statusCode = 400;
                return ['error' => $e->getMessage()];
            }

            try {
                $trustedBuild = SqlBuilderService::build($normalizedDefinition);
            } catch (\Throwable $e) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Could not rebuild the canonical query. The query was not saved.'];
            }

            $trustedSql = trim((string)($trustedBuild['sql'] ?? ''));
            $submittedSql = !empty($body['sqlEdited'])
                ? trim((string)$generatedSql)
                : $trustedSql;

            try {
                if (!empty($body['sqlEdited'])) {
                    SqlBuilderService::validateSafety($submittedSql);
                    SqlBuilderService::validateTablePolicy($submittedSql);
                    $this->assertEditedCanonicalSqlBinding($trustedSql, $submittedSql);
                }
            } catch (\InvalidArgumentException $e) {
                Yii::$app->response->statusCode = 422;
                return ['error' => $e->getMessage()];
            }

            $generatedSql = $submittedSql;
        }

        $model = new SavedQuery();
        $model->name = $body['name'] ?? 'Untitled Query';
        $model->user_id = $this->getCurrentUserId();
        $model->description = $body['description'] ?? null;
        $model->query_definition = json_encode($queryDefinition);
        $model->generated_sql = $generatedSql;
        $model->source = $body['source'] ?? 'builder';
        $model->nl_prompt = $body['nlPrompt'] ?? null;
        $model->is_pinned = !empty($body['isPinned']) ? 1 : 0;

        if (!$model->save()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Failed to save query', 'errors' => $model->errors];
        }

        Yii::$app->response->statusCode = 201;
        return $this->formatSaved($model);
    }

    private function assertEditedCanonicalSqlBinding(string $trustedSql, string $editedSql): void
    {
        $trusted = SqlSelectStructureService::analyzeCanonical($trustedSql);
        $edited = SqlSelectStructureService::analyzeCanonical($editedSql);

        if ($trusted['tables'] !== $edited['tables']) {
            throw new \InvalidArgumentException(
                'Edited canonical SQL must retain exactly the tables in the query definition.'
            );
        }
        if ($trusted['joins'] !== $edited['joins']) {
            throw new \InvalidArgumentException(
                'Edited canonical SQL must retain the server-approved table links.'
            );
        }
    }

    /**
     * GET /api/saved — list all saved queries.
     */
    public function actionSavedList()
    {
        $queries = SavedQuery::find()
            ->orderBy(['updated_at' => SORT_DESC])
            ->all();

        return array_map(function ($q) {
            return $this->formatSaved($q);
        }, $queries);
    }

    /**
     * GET /api/saved/pinned — list pinned queries for the dashboard.
     */
    public function actionSavedPinned()
    {
        $queries = SavedQuery::find()
            ->where(['is_pinned' => 1])
            ->orderBy(['updated_at' => SORT_DESC])
            ->all();

        return array_map(function ($q) {
            return $this->formatSaved($q);
        }, $queries);
    }

    // ─── Per-user dashboard ───────────────────────────────────────────

    /**
     * GET /api/dashboard — merged dashboard for the current user.
     * Returns personal pinned items + admin-global items, with per-user
     * position overrides and hidden flags applied.
     */
    public function actionDashboard()
    {
        $userId = $this->getCurrentUserId();

        // Personal pinned items for this user
        $pinnedQ = SavedQuery::find()->where(['is_pinned' => 1]);
        if ($userId) {
            $pinnedQ->andWhere(['user_id' => $userId]);
        }
        $pinned = $pinnedQ->orderBy(['updated_at' => SORT_DESC])->all();

        // Admin-global items (all users see these unless they hide them)
        $global = SavedQuery::find()->where(['is_global' => 1])
            ->orderBy(['updated_at' => SORT_DESC])->all();

        // Merge, deduplicate (user may have personally pinned a global item)
        $itemMap = []; // id => ['query' => ..., 'source_type' => ...]
        foreach ($global as $q) {
            $itemMap[$q->id] = ['query' => $q, 'source_type' => 'global'];
        }
        foreach ($pinned as $q) {
            if (!isset($itemMap[$q->id])) {
                $itemMap[$q->id] = ['query' => $q, 'source_type' => 'personal'];
            }
        }

        // Load per-user prefs
        $prefs = [];
        if ($userId && !empty($itemMap)) {
            $ids = array_keys($itemMap);
            // Build named placeholders (:id0, :id1, …) — PDO forbids mixing named and positional params
            $idParams = [];
            $params = [':uid' => $userId];
            foreach ($ids as $i => $id) {
                $key = ':id' . $i;
                $idParams[] = $key;
                $params[$key] = $id;
            }
            $prefRows = Yii::$app->db->createCommand(
                'SELECT saved_query_id, position, hidden, display_type, chart_config FROM user_dashboard_prefs WHERE user_id = :uid AND saved_query_id IN (' . implode(',', $idParams) . ')',
                $params
            )->queryAll();
            foreach ($prefRows as $row) {
                $prefs[(int)$row['saved_query_id']] = $row;
            }
        }

        // Assign default positions (personal items first, then global)
        $defaultPos = 0;
        $active  = [];
        $hidden  = [];

        foreach ($itemMap as $sqId => $entry) {
            $q    = $entry['query'];
            $pref = $prefs[$sqId] ?? null;
            $pos  = $pref !== null ? (int)$pref['position'] : ($defaultPos * 100);
            $defaultPos++;

            $formatted = $this->formatSaved($q);
            $formatted['position']    = $pos;
            $formatted['source_type'] = $entry['source_type'];
            $formatted['display_type'] = $pref['display_type'] ?? 'table';
            $formatted['chart_config'] = isset($pref['chart_config'])
                ? json_decode($pref['chart_config'], true)
                : null;

            if ($pref && (int)$pref['hidden'] === 1) {
                $hidden[] = $formatted;
            } else {
                $active[] = $formatted;
            }
        }

        usort($active, function($a, $b) { return $a['position'] - $b['position']; });

        return ['items' => $active, 'hidden' => $hidden];
    }

    /**
     * PATCH /api/dashboard/reorder — save user-defined item order.
     * Body: {"order": [savedQueryId1, savedQueryId2, ...]}
     */
    public function actionDashboardReorder()
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Authentication required'];
        }
        $body  = Yii::$app->request->getBodyParams();
        $order = $body['order'] ?? [];
        if (!is_array($order)) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'order must be an array of saved_query_id values'];
        }

        $db = Yii::$app->db;
        foreach ($order as $pos => $sqId) {
            $sqId = (int)$sqId;
            $pos  = (int)$pos * 100;
            $db->createCommand(
                'INSERT INTO user_dashboard_prefs (user_id, saved_query_id, position) VALUES (:uid, :sqid, :pos) ON DUPLICATE KEY UPDATE position = :pos, updated_at = NOW()',
                [':uid' => $userId, ':sqid' => $sqId, ':pos' => $pos]
            )->execute();
        }

        return ['success' => true];
    }

    /**
     * POST /api/dashboard/<id>/hide — hide a global dashboard item.
     */
    public function actionDashboardHide($id)
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Authentication required'];
        }
        $sqId = (int)$id;
        Yii::$app->db->createCommand(
            'INSERT INTO user_dashboard_prefs (user_id, saved_query_id, hidden) VALUES (:uid, :sqid, 1) ON DUPLICATE KEY UPDATE hidden = 1, updated_at = NOW()',
            [':uid' => $userId, ':sqid' => $sqId]
        )->execute();
        return ['success' => true];
    }

    /**
     * POST /api/dashboard/<id>/show — restore a hidden dashboard item.
     */
    public function actionDashboardShow($id)
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Authentication required'];
        }
        $sqId = (int)$id;
        Yii::$app->db->createCommand(
            'UPDATE user_dashboard_prefs SET hidden = 0, updated_at = NOW() WHERE user_id = :uid AND saved_query_id = :sqid',
            [':uid' => $userId, ':sqid' => $sqId]
        )->execute();
        return ['success' => true];
    }

    /**
     * POST /api/dashboard/<id>/refresh — re-run a saved query, store new job ID as last_job_id.
     * Returns {jobId} immediately; client polls query/status/{jobId}.
     */
    public function actionDashboardRefresh($id)
    {
        $userId = $this->getCurrentUserId();
        $sq = SavedQuery::findOne((int)$id);
        if (!$sq) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Saved query not found'];
        }
        if (!$sq->generated_sql) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Saved query has no SQL'];
        }

        // For widget-gallery report queries the SQL contains named placeholders;
        // the resolved values are stored in query_definition['bound_params'].
        // Fall back to re-running bindParams if that field is absent (legacy row).
        $jobParams = [];
        if ($sq->source === 'report') {
            $def = json_decode($sq->query_definition ?: '{}', true);
            if (isset($def['bound_params']) && is_array($def['bound_params'])) {
                $jobParams = $def['bound_params'];
            } elseif (!empty($def['report_template_id'])) {
                // Legacy row: re-derive bound_params from the stored user params
                $rt = ReportTemplate::findOne((int)$def['report_template_id']);
                if ($rt) {
                    $bound = $rt->bindParams($def['params'] ?? []);
                    $jobParams = $bound['params'];
                    // Back-fill so future refreshes skip this work
                    $def['bound_params'] = $jobParams;
                    $sq->query_definition = json_encode($def);
                    $sq->save(false);
                }
            }
        }

        $job = QueryJob::createJob($sq->generated_sql, $jobParams, $sq->source ?: 'builder', 'folio');
        $job->name    = $this->normalizeQueryJobName($sq->name);
        $job->user_id = $userId;
        if (!$job->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to create job'];
        }
        $jobId = $job->id;

        // Persist the new job id on the saved query so dashboard can show it
        $sq->last_job_id = $jobId;
        $sq->save(false);

        Yii::$app->response->statusCode = 202;
        return ['jobId' => $jobId];
    }

    /**
     * PATCH /api/dashboard/<id>/display — persist per-user display type + chart config.
     * Body: {"displayType": "bar", "chartConfig": {"xAxis": "...", "yAxes": [...]}}
     */
    public function actionDashboardDisplay($id)
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Authentication required'];
        }
        $body        = Yii::$app->request->getBodyParams();
        $sqId        = (int)$id;
        $displayType = $body['displayType'] ?? 'table';
        $chartConfig = isset($body['chartConfig']) ? json_encode($body['chartConfig']) : null;

        $allowed = ['table', 'bar', 'line', 'pie', 'area'];
        if (!in_array($displayType, $allowed, true)) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Invalid displayType'];
        }

        Yii::$app->db->createCommand(
            'INSERT INTO user_dashboard_prefs (user_id, saved_query_id, display_type, chart_config)
             VALUES (:uid, :sqid, :dt, :cc)
             ON DUPLICATE KEY UPDATE display_type = :dt, chart_config = :cc, updated_at = NOW()',
            [':uid' => $userId, ':sqid' => $sqId, ':dt' => $displayType, ':cc' => $chartConfig]
        )->execute();

        return ['success' => true, 'displayType' => $displayType];
    }

    /**
     * PATCH /api/saved/<id>/global — admin-only toggle is_global.
     */
    public function actionSavedGlobal($id)
    {
        if (!$this->requireAdmin()) {
            return ['error' => 'Admin required'];
        }
        $query = SavedQuery::findOne($id);
        if (!$query) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Saved query not found'];
        }
        $query->is_global = $query->is_global ? 0 : 1;
        $query->save(false);
        return $this->formatSaved($query);
    }

    /**
     * POST /api/saved/<id>/pin — toggle pin status.
     */
    public function actionSavedPin($id)
    {
        $query = SavedQuery::findOne($id);
        if (!$query) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Saved query not found'];
        }
        $query->is_pinned = $query->is_pinned ? 0 : 1;
        $query->save(false);
        return $this->formatSaved($query);
    }

    /**
     * POST /api/saved/<id>/promote — convert saved query into a report template.
     */
    public function actionSavedPromote($id)
    {
        $query = SavedQuery::findOne($id);
        if (!$query) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Saved query not found'];
        }

        if (!$query->generated_sql) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'No SQL to promote — run the query first'];
        }

        // Generate a URL-safe slug from the name
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($query->name));
        $slug = trim($slug, '-');
        // Ensure uniqueness
        $existing = ReportTemplate::findOne(['slug' => $slug]);
        if ($existing) {
            $slug .= '-' . time();
        }

        $report = new ReportTemplate();
        $report->slug = $slug;
        $report->name = $query->name;
        $report->description = $query->description ?: ($query->nl_prompt ?: '');
        $report->category = 'other';
        $report->sql_template = $query->generated_sql;
        $report->parameters = '[]';
        $report->default_limit = 100;
        $report->is_active = 1;
        $report->created_by = $query->source === 'nl' ? 'ai' : 'manual';

        if (!$report->save()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Failed to create report', 'errors' => $report->errors];
        }

        Yii::$app->response->statusCode = 201;
        return [
            'id' => $report->id,
            'slug' => $report->slug,
            'name' => $report->name,
        ];
    }

    /**
     * GET /api/saved/<id> — get a single saved query.
     */
    public function actionSavedDetail($id)
    {
        $query = SavedQuery::findOne($id);
        if (!$query) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Saved query not found'];
        }
        return $this->formatSaved($query);
    }

    /**
     * DELETE /api/saved/<id> — delete a saved query.
     */
    public function actionSavedDelete($id)
    {
        $query = SavedQuery::findOne($id);
        if (!$query) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Saved query not found'];
        }
        $query->delete();
        return ['success' => true];
    }

    /**
     * Format a SavedQuery model for JSON response.
     */
    private function formatSaved(SavedQuery $q)
    {
        return [
            'id'               => (int)$q->id,
            'name'             => $q->name,
            'description'      => $q->description,
            'query_definition' => $q->query_definition ? json_decode($q->query_definition, true) : null,
            'generated_sql'    => $q->generated_sql,
            'source'           => $q->source ?: 'builder',
            'nl_prompt'        => $q->nl_prompt,
            'is_pinned'        => (bool)$q->is_pinned,
            'is_global'        => (bool)($q->is_global ?? false),
            'last_job_id'      => $q->last_job_id ?: null,
            'created_at'       => $q->created_at,
            'updated_at'       => $q->updated_at,
        ];
    }

    // ─── Utility ──────────────────────────────────────────────────────

    /**
     * GET /api/health — health check.
     */
    public function actionHealth()
    {
        $checks = [
            'status' => 'ok',
            'schema_loaded' => false,
            'mysql_connected' => false,
            'postgres_connected' => false,
        ];

        try {
            FolioSchemaService::loadSchema();
            $checks['schema_loaded'] = true;
        } catch (\Exception $e) {
            $checks['schema_error'] = $e->getMessage();
        }

        try {
            Yii::$app->db->open();
            $checks['mysql_connected'] = true;
        } catch (\Exception $e) {
            $checks['mysql_error'] = $e->getMessage();
        }

        try {
            Yii::$app->folioDb->open();
            $checks['postgres_connected'] = true;
        } catch (\Exception $e) {
            $checks['postgres_error'] = $e->getMessage();
        }

        if (!$checks['schema_loaded'] || !$checks['mysql_connected']) {
            $checks['status'] = 'degraded';
        }

        return $checks;
    }

    // ─── Settings (dev) ───────────────────────────────────────────────

    /**
     * GET /api/settings — return current settings (passwords masked).
     */
    public function actionSettings()
    {
        return SettingsService::forDisplay();
    }

    /**
     * GET /api/nl2sql-preflight — return effective NL2SQL runtime parity details.
     */
    public function actionNl2sqlPreflight()
    {
        return Nl2sqlRuntimePreflightService::buildFromAppContext();
    }

    /**
     * GET /api/reference-cache/status — summarize local FOLIO reference cache freshness.
     */
    public function actionReferenceCacheStatus()
    {
        $jsonBundle = $this->buildReferenceJsonBundleStatus();
        $empty = [
            'available' => false,
            'jsonBundle' => $jsonBundle,
            'enabledTables' => 0,
            'activeRows' => 0,
            'failedTables' => 0,
            'manualReviewTables' => 0,
            'disabledCacheableTables' => 0,
            'lastRefreshedAt' => null,
            'tables' => [],
        ];

        try {
            $db = Yii::$app->db;
            $tables = $db->createCommand(
                'SELECT source_table, enabled, classification, row_count, last_refreshed_at, last_refresh_status, last_error
                 FROM folio_reference_tables
                 ORDER BY enabled DESC, source_table'
            )->queryAll();

            $activeRows = (int)$db->createCommand(
                'SELECT COUNT(*) FROM folio_reference_values WHERE is_active = 1'
            )->queryScalar();
        } catch (\Throwable $e) {
            $empty['error'] = $e->getMessage();
            return $empty;
        }

        $enabledTables = 0;
        $failedTables = 0;
        $manualReviewTables = 0;
        $disabledCacheableTables = 0;
        $lastRefreshedAt = null;
        $tableSummaries = [];

        foreach ($tables as $row) {
            $enabled = !empty($row['enabled']);
            $classification = (string)($row['classification'] ?? '');
            $status = (string)($row['last_refresh_status'] ?? 'never');
            $refreshedAt = $row['last_refreshed_at'] ?? null;

            if ($enabled) {
                $enabledTables++;
            }
            if ($enabled && $status === 'failed') {
                $failedTables++;
            }
            if (!$enabled && $classification === 'manual_review') {
                $manualReviewTables++;
            }
            if (!$enabled && $classification === 'cacheable_reference') {
                $disabledCacheableTables++;
            }
            if ($refreshedAt !== null && ($lastRefreshedAt === null || strcmp((string)$refreshedAt, (string)$lastRefreshedAt) > 0)) {
                $lastRefreshedAt = (string)$refreshedAt;
            }

            if ($enabled || $status === 'failed') {
                $tableSummaries[] = [
                    'sourceTable' => (string)$row['source_table'],
                    'enabled' => $enabled,
                    'classification' => $classification,
                    'rowCount' => $row['row_count'] !== null ? (int)$row['row_count'] : null,
                    'lastRefreshedAt' => $refreshedAt !== null ? (string)$refreshedAt : null,
                    'lastRefreshStatus' => $status,
                    'lastError' => $row['last_error'] !== null ? (string)$row['last_error'] : null,
                ];
            }
        }

        return [
            'available' => true,
            'jsonBundle' => $jsonBundle,
            'enabledTables' => $enabledTables,
            'activeRows' => $activeRows,
            'failedTables' => $failedTables,
            'manualReviewTables' => $manualReviewTables,
            'disabledCacheableTables' => $disabledCacheableTables,
            'lastRefreshedAt' => $lastRefreshedAt,
            'tables' => $tableSummaries,
        ];
    }

    private function buildReferenceJsonBundleStatus(): array
    {
        $status = ReferenceJsonBundleService::bundleStatus();
        $bundle = ReferenceJsonBundleService::loadBundle();
        $tables = is_array($bundle['tables'] ?? null) ? $bundle['tables'] : [];
        $rowCount = 0;

        foreach ($tables as $rows) {
            if (is_array($rows)) {
                $rowCount += count($rows);
            }
        }

        return $status + [
            'tableCount' => count($tables),
            'rowCount' => $rowCount,
            'approvedTableCount' => count(ReferenceJsonBundleService::approvedTables()),
        ];
    }

    /**
     * GET /api/reference-cache/candidates — summarize disabled reference-cache candidates for review.
     */
    public function actionReferenceCacheCandidates()
    {
        try {
            $summaryRows = Yii::$app->db->createCommand(
                'SELECT classification, source_schema, COUNT(*) AS table_count
                 FROM folio_reference_tables
                 WHERE enabled = 0
                 GROUP BY classification, source_schema
                 ORDER BY classification, table_count DESC, source_schema'
            )->queryAll();

            $candidateRows = Yii::$app->db->createCommand(
                'SELECT source_table, source_schema, classification, estimated_rows, total_bytes
                 FROM folio_reference_tables
                 WHERE enabled = 0
                   AND classification IN ("cacheable_reference", "manual_review")
                 ORDER BY classification, estimated_rows ASC, total_bytes ASC, source_table
                 LIMIT 80'
            )->queryAll();
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'summaryBySchema' => [],
                'candidates' => [],
                'error' => $e->getMessage(),
            ];
        }

        $summaryBySchema = [];
        foreach ($summaryRows as $row) {
            $summaryBySchema[] = [
                'classification' => (string)$row['classification'],
                'sourceSchema' => (string)$row['source_schema'],
                'tableCount' => (int)$row['table_count'],
            ];
        }

        $candidates = [];
        foreach ($candidateRows as $row) {
            $candidates[] = [
                'sourceTable' => (string)$row['source_table'],
                'sourceSchema' => (string)$row['source_schema'],
                'classification' => (string)$row['classification'],
                'estimatedRows' => $row['estimated_rows'] !== null ? (int)$row['estimated_rows'] : null,
                'totalBytes' => $row['total_bytes'] !== null ? (int)$row['total_bytes'] : null,
            ];
        }

        return [
            'available' => true,
            'summaryBySchema' => $summaryBySchema,
            'candidates' => $candidates,
        ];
    }

    /**
     * POST /api/reference-cache/candidates/review — enable, disable, or reject one discovered reference table.
     */
    public function actionReferenceCacheCandidateReview()
    {
        if (!$this->requireAdmin()) {
            return ['error' => 'Forbidden'];
        }

        $body = Yii::$app->request->getBodyParams();
        $sourceTable = trim((string)($body['sourceTable'] ?? ''));
        $decision = trim((string)($body['decision'] ?? ''));
        $decisions = [
            'enable' => [true, 'cacheable_reference'],
            'disable' => [false, 'manual_review'],
            'reject' => [false, 'do_not_cache'],
        ];

        if ($sourceTable === '' || !isset($decisions[$decision])) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'sourceTable and decision are required'];
        }

        [$enabled, $classification] = $decisions[$decision];
        $db = Yii::$app->db;

        try {
            $existing = $db->createCommand(
                'SELECT id FROM folio_reference_tables WHERE source_table = :sourceTable',
                [':sourceTable' => $sourceTable]
            )->queryOne();

            if ($existing === false) {
                Yii::$app->response->statusCode = 404;
                return ['error' => 'Reference candidate not found'];
            }

            if ($enabled) {
                $refreshCheck = $this->assertReferenceCandidateCanRefresh($sourceTable);
                if ($refreshCheck !== null) {
                    Yii::$app->response->statusCode = 422;
                    return ['error' => $refreshCheck];
                }
            }

            $db->createCommand()->update(
                'folio_reference_tables',
                [
                    'enabled' => $enabled ? 1 : 0,
                    'classification' => $classification,
                    'updated_at' => date('Y-m-d H:i:s'),
                ],
                ['source_table' => $sourceTable]
            )->execute();

            $row = $db->createCommand(
                'SELECT source_table, enabled, classification, estimated_rows, total_bytes
                 FROM folio_reference_tables
                 WHERE source_table = :sourceTable',
                [':sourceTable' => $sourceTable]
            )->queryOne();
        } catch (\Throwable $e) {
            Yii::$app->response->statusCode = 500;
            return ['error' => $e->getMessage()];
        }

        return [
            'sourceTable' => (string)$row['source_table'],
            'enabled' => !empty($row['enabled']),
            'classification' => (string)$row['classification'],
            'estimatedRows' => $row['estimated_rows'] !== null ? (int)$row['estimated_rows'] : null,
            'totalBytes' => $row['total_bytes'] !== null ? (int)$row['total_bytes'] : null,
        ];
    }

    /**
     * POST /api/reference-cache/refresh — refresh one enabled local reference table immediately.
     */
    public function actionReferenceCacheRefresh()
    {
        if (!$this->requireAdmin()) {
            return ['error' => 'Forbidden'];
        }

        $body = Yii::$app->request->getBodyParams();
        $sourceTable = trim((string)($body['sourceTable'] ?? ''));
        if ($sourceTable === '') {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'sourceTable is required'];
        }

        try {
            return (new ReferenceCacheRefreshService())->refreshTableBySourceTable($sourceTable);
        } catch (\RuntimeException $e) {
            Yii::$app->response->statusCode = 422;
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Yii::$app->response->statusCode = 500;
            return ['error' => $e->getMessage()];
        }
    }

    private function assertReferenceCandidateCanRefresh(string $sourceTable)
    {
        try {
            return (new ReferenceCacheRefreshService())->validateSourceTableCanRefresh($sourceTable);
        } catch (\Throwable $e) {
            return 'Cannot enable candidate because FOLIO columns could not be inspected: ' . $e->getMessage();
        }
    }

    /**
     * POST /api/settings — save connection settings to data/settings.json.
     */
    public function actionSettingsSave()
    {
        $body = Yii::$app->request->getBodyParams();

        $allowed = [
            'pg_host',
            'pg_port',
            'pg_db',
            'pg_user',
            'pg_pass',
            'pg_sslmode',
            'ai_provider',
            'gemini_api_key',
            'gemini_model',
            'openai_api_key',
            'openai_model',
            'nl2sql_intent_mode',
            'nl2sql_primary_mode',
            'nl2sql_shadow_mode',
            'nl2sql_shadow_users',
            'nl2sql_shadow_sample_percent',
            'nl2sql_force_legacy',
            'nl2sql_hardened_physical_roi',
        ];
        $filtered = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $filtered[$key] = $body[$key];
            }
        }

        $saved = SettingsService::save($filtered);

        // Close and reconfigure the Postgres connection with new settings
        try {
            Yii::$app->folioDb->close();
        } catch (\Exception $e) {
            // ignore
        }

        // Clear the table mapping cache so it re-discovers with new connection
        FolioSchemaService::clearMappingCache();

        return SettingsService::forDisplay();
    }

    /**
     * POST /api/settings/test — test Postgres and/or AI provider connection.
     */
    public function actionSettingsTest()
    {
        $body = Yii::$app->request->getBodyParams();
        $results = [];

        if (isset($body['test_postgres']) && $body['test_postgres']) {
            $results['postgres'] = SettingsService::testPostgres(
                $body['pg_host'] ?? null,
                $body['pg_port'] ?? null,
                $body['pg_db'] ?? null,
                $body['pg_user'] ?? null,
                $body['pg_pass'] ?? null,
                $body['pg_sslmode'] ?? null
            );
        }

        if (isset($body['test_gemini']) && $body['test_gemini']) {
            $results['gemini'] = SettingsService::testGemini(
                $body['gemini_api_key'] ?? null,
                $body['gemini_model'] ?? null
            );
        }

        if (isset($body['test_openai']) && $body['test_openai']) {
            $results['openai'] = SettingsService::testOpenAi(
                $body['openai_api_key'] ?? null,
                $body['openai_model'] ?? null
            );
        }

        return $results;
    }

    // ─── Report Templates ─────────────────────────────────────────

    /**
     * GET /api/reports — list all active report templates, grouped by category.
     */
    public function actionReportList()
    {
        $reports = ReportTemplate::find()
            ->where(['is_active' => 1])
            ->orderBy(['category' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        // Group by category
        $grouped = [];
        foreach ($reports as $report) {
            $cat = $report->category ?: 'other';
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [];
            }
            $grouped[$cat][] = $report->toSummary();
        }

        return $grouped;
    }

    /**
     * GET /api/reports/<id> — get full report detail with resolved defaults and dropdown options.
     */
    public function actionReportDetail($id)
    {
        $report = ReportTemplate::findOne(['id' => $id, 'is_active' => 1]);
        if (!$report) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Report not found'];
        }

        $data = $report->toDetailArray();
        $data['selectOptions'] = $report->fetchSelectOptions();

        return $data;
    }

    /**
     * POST /api/reports/<id>/run — execute a report with user-provided parameters.
        * Body: {params: {startDate: '2025-07-01', endDate: '2026-06-30', ...}, outputMode?: 'table'|'file'}
     * Returns: {jobId: string} — uses async job pipeline.
     */
    public function actionReportRun($id)
    {
        $report = ReportTemplate::findOne(['id' => $id, 'is_active' => 1]);
        if (!$report) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Report not found'];
        }

        $body = Yii::$app->request->getBodyParams();
        $userParams = $body['params'] ?? [];
        $outputMode = strtolower((string)($body['outputMode'] ?? 'table')) === 'file' ? 'file' : 'table';
        $exportKind = $body['exportKind'] ?? 'worklist';

        if (!is_string($exportKind) || !in_array($exportKind, ['worklist', 'identifier'], true)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Unsupported report export kind.'];
        }
        if ($exportKind === 'identifier' && !$report->hasIdentifierExport()) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'This report does not support identifier export.'];
        }

        // Validate required params
        $paramDefs = $report->getDecodedParameters();
        $isGovernedReport = CatalogingReportCompilerService::supports($report);
        $missing = [];
        foreach ($paramDefs as $def) {
            if (!empty($def['required']) && empty($userParams[$def['name']])) {
                // Check if there's a default
                $resolved = ReportTemplate::resolveDefaultMacro($def['default'] ?? '');
                if (empty($resolved)) {
                    $missing[] = $def['label'] ?? $def['name'];
                }
            }
        }
        if (!empty($missing) && !$isGovernedReport) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Missing required parameters: ' . implode(', ', $missing)];
        }

        $compiled = null;
        if (CatalogingReportCompilerService::supports($report)) {
            try {
                $compiled = CatalogingReportCompilerService::build(
                    $report,
                    $userParams,
                    Yii::$app->folioDb
                );
            } catch (\app\exceptions\ReportParameterValidationException $e) {
                Yii::$app->response->statusCode = 400;
                return [
                    'error' => 'Report parameters are invalid.',
                    'fieldErrors' => $e->getFieldErrors(),
                ];
            } catch (\InvalidArgumentException $e) {
                if ($this->isCatalogingReportIntegrityError($e)) {
                    Yii::$app->response->statusCode = 422;
                    return ['error' => $this->catalogingReportIntegrityMessage($e)];
                }
                if ($isGovernedReport) {
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'The report definition could not be validated. Please contact an administrator.'];
                }
                Yii::$app->response->statusCode = 400;
                return ['error' => $e->getMessage()];
            } catch (\Throwable $e) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'The report could not be validated. Please try again or contact an administrator.'];
            }
            $bound = ['sql' => $compiled['sql'], 'params' => $compiled['params']];
        } else {
            $bound = $report->bindParams($userParams);
            $bound['sql'] = $this->normalizeLegacyReportSql($report, $bound['sql']);
        }

        // Safety validation
        try {
            SqlBuilderService::validateSafety($bound['sql']);
            SqlBuilderService::validateTablePolicy($bound['sql']);
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'This query is blocked by reporting data policy.'];
        }

        // Determine data source from report template
        $rawDataSource = $report->hasAttribute('data_source') ? $report->data_source : null;
        $dataSource = in_array($rawDataSource, ['folio', 'local', 'composite'])
            ? $rawDataSource
            : 'folio';

        // For composite reports, attach the composite_config as job metadata.
        $metadata = null;
        if ($dataSource === 'composite') {
            $compositeConfig = $report->getCompositeConfig();
            if (!$compositeConfig) {
                Yii::$app->response->statusCode = 400;
                return ['error' => 'Composite report is missing composite_config'];
            }
            // Bind secondary SQL params too (secondary uses same param set)
            $metadata = [
                'composite_config' => $compositeConfig,
                'bound_params' => $bound['params'],
            ];
        }

        $estimate = null;
        $reportExecution = null;
        if ($compiled !== null && $dataSource === 'folio' && $report->getExecutionConfig() !== null) {
            try {
                $reportExecution = ReportExecutionContractService::fromReport($report, [
                    'exportKind' => $exportKind,
                    'marcTag' => $compiled['marcTag'],
                    'locationName' => $compiled['location']['name'],
                    'locationCode' => $compiled['location']['code'],
                ]);
            } catch (\InvalidArgumentException $e) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'The report execution configuration is invalid. Please contact an administrator.'];
            }

            try {
                $estimate = $this->estimateQueryComplexity($bound['sql'], 'folio', $bound['params']);
            } catch (\app\exceptions\DatabaseQueryCancelledException $exception) {
                return $this->buildDatabaseCancelledResponse();
            } catch (\Throwable $exception) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Query validation failed before execution.'];
            }
            if (isset($estimate['error'])) {
                $this->logPreflightValidationFailure(
                    'api.report_run',
                    (string) $estimate['error'],
                    $dataSource,
                    'report',
                    $bound['sql']
                );
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Query validation failed before execution.'];
            }
            if ($estimate !== null) {
                $thresholdRows = (int) (Yii::$app->params['exportRowThreshold'] ?? Yii::$app->params['maxQueryRows']);
                $thresholdCost = (float) (Yii::$app->params['exportCostThreshold'] ?? 500000);
                $estimatedRows = $estimate['rows'] ?? null;
                $estimatedCost = $estimate['cost'] ?? null;
                if (($estimatedRows !== null && $estimatedRows > $thresholdRows)
                    || ($estimatedCost !== null && $estimatedCost > $thresholdCost)) {
                    $outputMode = 'file';
                }
            }
        }
        // Governed reports stream to a file regardless of planner estimates.
        // Their sentinel/cap contract is enforced by the export worker, so a
        // table job must never materialize an unexpectedly large result set.
        if ($reportExecution !== null || $exportKind === 'identifier') {
            $outputMode = 'file';
        }
        if ($reportExecution !== null) {
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata[ReportExecutionContractService::METADATA_KEY] = $reportExecution;
        }

        // Create async job
        $job = QueryJob::createJob($bound['sql'], $bound['params'], 'report', $dataSource, $metadata);
        $job->user_id = $this->getCurrentUserId();
        if ($job->hasAttribute('output_mode')) {
            $job->output_mode = $outputMode;
        }
        if ($outputMode === 'file') {
            $job->status = 'pending_export';
            $job->progress_message = 'Queued for CSV export';
        }
        if ($estimate !== null) {
            if ($job->hasAttribute('estimated_rows')) {
                $job->estimated_rows = $estimate['rows'] ?? null;
            }
            if ($job->hasAttribute('estimated_cost')) {
                $cost = $estimate['cost'] ?? null;
                $job->estimated_cost = $cost !== null ? min((float) $cost, 1.0e15) : null;
            }
        }
        if (!$job->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to create job', 'details' => $job->errors];
        }

        Yii::$app->response->statusCode = 202;
        return [
            'jobId' => $job->id,
            'reportName' => $report->name,
            'status' => $job->status,
            'outputMode' => $job->hasAttribute('output_mode') ? ($job->output_mode ?: 'table') : 'table',
            'dataSource' => $dataSource,
            'progressMessage' => $job->progress_message,
            'createdAt' => $job->created_at,
            'startedAt' => $job->started_at,
            'completedAt' => $job->completed_at,
        ];
    }

    private function isCatalogingReportIntegrityError(\InvalidArgumentException $exception): bool
    {
        $message = $exception->getMessage();
        return strpos($message, 'Reporting schema is missing the expected MARC tag table') === 0
            || $message === 'A selected location no longer exists.';
    }

    private function catalogingReportIntegrityMessage(\InvalidArgumentException $exception): string
    {
        if (strpos($exception->getMessage(), 'Reporting schema is missing the expected MARC tag table') === 0) {
            return 'The selected MARC tag data is unavailable. Please contact an administrator.';
        }
        return 'A selected location is unavailable. Please update the selection.';
    }

    /**
     * POST /api/reports — create a new report template.
     * Body: {slug, name, description, helpText?, category, sqlTemplate, parameters, defaultLimit?, createdBy?}
     */
    public function actionReportCreate()
    {
        $body = Yii::$app->request->getBodyParams();

        $report = new ReportTemplate();
        $report->slug = $body['slug'] ?? '';
        $report->name = $body['name'] ?? '';
        $report->description = $body['description'] ?? '';
        if ($report->hasAttribute('help_text')) {
            $report->help_text = $body['helpText'] ?? null;
        }
        $report->category = $body['category'] ?? 'other';
        $report->sql_template = $body['sqlTemplate'] ?? '';
        $report->parameters = is_string($body['parameters'] ?? null)
            ? $body['parameters']
            : json_encode($body['parameters'] ?? []);
        $report->default_limit = $body['defaultLimit'] ?? 100;
        $report->is_active = 1;
        $report->created_by = $body['createdBy'] ?? 'manual';

        // Validate SQL safety
        try {
            SqlBuilderService::validateSafety($report->sql_template);
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => $e->getMessage()];
        }

        if (!$report->save()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'details' => $report->errors];
        }

        Yii::$app->response->statusCode = 201;
        return $report->toDetailArray();
    }

    /**
     * PUT /api/reports/<id> — update a report template.
     */
    public function actionReportUpdate($id)
    {
        $report = ReportTemplate::findOne($id);
        if (!$report) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Report not found'];
        }

        $body = Yii::$app->request->getBodyParams();

        if (isset($body['name'])) $report->name = $body['name'];
        if (isset($body['description'])) $report->description = $body['description'];
        if ($report->hasAttribute('help_text') && array_key_exists('helpText', $body)) {
            $report->help_text = $body['helpText'];
        }
        if (isset($body['category'])) $report->category = $body['category'];
        if (isset($body['sqlTemplate'])) {
            SqlBuilderService::validateSafety($body['sqlTemplate']);
            $report->sql_template = $body['sqlTemplate'];
        }
        if (isset($body['parameters'])) {
            $report->parameters = is_string($body['parameters'])
                ? $body['parameters']
                : json_encode($body['parameters']);
        }
        if (isset($body['defaultLimit'])) $report->default_limit = $body['defaultLimit'];

        if (!$report->save()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'details' => $report->errors];
        }

        return $report->toDetailArray();
    }

    /**
     * DELETE /api/reports/<id> — permanently delete a report template.
     */
    public function actionReportDelete($id)
    {
        $report = ReportTemplate::findOne($id);
        if (!$report) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Report not found'];
        }

        $report->delete();

        return ['success' => true, 'message' => 'Report deleted'];
    }

    /**
     * POST /api/reports/generate — use AI to generate a report template from a description.
     * Body: {description: string}
     * Returns: The generated template for preview (not yet saved).
     */
    public function actionReportGenerate()
    {
        $body = Yii::$app->request->getBodyParams();
        $description = $body['description'] ?? '';

        if (empty($description)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Description is required'];
        }

        try {
            $template = GeminiService::generateReportTemplate($description);
            return $template;
        } catch (\Exception $e) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'AI generation failed: ' . $e->getMessage()];
        }
    }

    /**
     * POST /api/reports/convert — convert a Yii2 PHP report model into a report template using AI.
     * Body: {phpCode: string}
     * Returns: The generated template for preview (not yet saved).
     */
    public function actionReportConvert()
    {
        $body = Yii::$app->request->getBodyParams();
        $phpCode = $body['phpCode'] ?? '';

        if (empty($phpCode)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'PHP code is required'];
        }

        try {
            $template = GeminiService::convertReportFromPhp($phpCode);
            return $template;
        } catch (\Exception $e) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'AI conversion failed: ' . $e->getMessage()];
        }
    }

    // ─── AI Training Hints ────────────────────────────────────────────

    /**
     * GET /api/training — list all training hints.
     * Optional query param: ?type=table_description|vocabulary|example|correction
     */
    public function actionTrainingList()
    {
        $type = Yii::$app->request->get('type');
        $db = Yii::$app->db;

        $sql = 'SELECT * FROM ai_training_hints';
        $params = [];
        if ($type) {
            $sql .= ' WHERE type = :type';
            $params[':type'] = $type;
        }
        $sql .= ' ORDER BY type, hint_key, id';

        return $db->createCommand($sql, $params)->queryAll();
    }

    /**
     * GET /api/training/<id> — get single training hint.
     */
    public function actionTrainingDetail($id)
    {
        $db = Yii::$app->db;
        $row = $db->createCommand('SELECT * FROM ai_training_hints WHERE id = :id', [':id' => $id])->queryOne();

        if (!$row) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Training hint not found'];
        }

        return $row;
    }

    /**
     * POST /api/training — create a new training hint.
     * Body: {type, hintKey?, hintValue?, exampleQuestion?, exampleSql?, notes?, isActive?}
     */
    public function actionTrainingCreate()
    {
        $body = Yii::$app->request->getBodyParams();
        $type = $body['type'] ?? '';

        $validTypes = ['table_description', 'vocabulary', 'example', 'correction'];
        if (!in_array($type, $validTypes)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Invalid type. Must be one of: ' . implode(', ', $validTypes)];
        }

        $db = Yii::$app->db;
        $db->createCommand()->insert('ai_training_hints', [
            'type' => $type,
            'hint_key' => $body['hintKey'] ?? null,
            'hint_value' => $body['hintValue'] ?? null,
            'example_question' => $body['exampleQuestion'] ?? null,
            'example_sql' => $body['exampleSql'] ?? null,
            'original_sql' => $body['originalSql'] ?? null,
            'notes' => $body['notes'] ?? null,
            'is_active' => isset($body['isActive']) ? (int) $body['isActive'] : 1,
            'user_id' => $this->getCurrentUserId(),
        ])->execute();

        $id = $db->getLastInsertID();
        // Clear cached hints so next Gemini call uses updated data
        FolioSchemaService::clearDomainHintsCache();

        return $db->createCommand('SELECT * FROM ai_training_hints WHERE id = :id', [':id' => $id])->queryOne();
    }

    /**
     * PUT /api/training/<id> — update an existing training hint.
     */
    public function actionTrainingUpdate($id)
    {
        $db = Yii::$app->db;
        $existing = $db->createCommand('SELECT * FROM ai_training_hints WHERE id = :id', [':id' => $id])->queryOne();

        if (!$existing) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Training hint not found'];
        }

        $body = Yii::$app->request->getBodyParams();
        $updates = [];

        $fields = [
            'type' => 'type',
            'hintKey' => 'hint_key',
            'hintValue' => 'hint_value',
            'exampleQuestion' => 'example_question',
            'exampleSql' => 'example_sql',
            'originalSql' => 'original_sql',
            'notes' => 'notes',
            'isActive' => 'is_active',
        ];

        foreach ($fields as $bodyKey => $dbCol) {
            if (array_key_exists($bodyKey, $body)) {
                $updates[$dbCol] = $body[$bodyKey];
            }
        }

        if (empty($updates)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'No fields to update'];
        }

        $db->createCommand()->update('ai_training_hints', $updates, ['id' => $id])->execute();
        FolioSchemaService::clearDomainHintsCache();

        return $db->createCommand('SELECT * FROM ai_training_hints WHERE id = :id', [':id' => $id])->queryOne();
    }

    /**
     * DELETE /api/training/<id> — delete a training hint.
     */
    public function actionTrainingDelete($id)
    {
        $db = Yii::$app->db;
        $existing = $db->createCommand('SELECT id FROM ai_training_hints WHERE id = :id', [':id' => $id])->queryScalar();

        if (!$existing) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Training hint not found'];
        }

        $db->createCommand()->delete('ai_training_hints', ['id' => $id])->execute();
        FolioSchemaService::clearDomainHintsCache();

        return ['success' => true];
    }

    /**
     * POST /api/training/correct — submit a correction from Ask AI.
     * Body: {prompt, originalSql, correctedSql, notes?}
     * Creates both a correction record AND an active example record.
     */
    public function actionTrainingCorrect()
    {
        $body = Yii::$app->request->getBodyParams();
        $prompt = $body['prompt'] ?? '';
        $originalSql = $body['originalSql'] ?? '';
        $correctedSql = $body['correctedSql'] ?? '';
        $notes = $body['notes'] ?? null;

        if (empty($prompt) || empty($correctedSql)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'prompt and correctedSql are required'];
        }

        $db = Yii::$app->db;

        // 1. Create correction record (audit trail)
        $db->createCommand()->insert('ai_training_hints', [
            'type' => 'correction',
            'example_question' => $prompt,
            'example_sql' => $correctedSql,
            'original_sql' => $originalSql,
            'notes' => $notes,
            'is_active' => 1,
            'user_id' => $this->getCurrentUserId(),
        ])->execute();
        $correctionId = $db->getLastInsertID();

        FolioSchemaService::clearDomainHintsCache();

        $correction = $db->createCommand(
            'SELECT * FROM ai_training_hints WHERE id = :id', [':id' => $correctionId]
        )->queryOne();

        return [
            'correction' => $correction,
            'message' => 'Correction saved. The corrected query will be used as a training example for future AI queries.',
        ];
    }

    // ─── Local supplementary data (admin) ─────────────────────────

    /**
     * GET /api/local/acrl
     */
    public function actionLocalAcrlList()
    {
        $year = Yii::$app->request->get('year');
        $category = Yii::$app->request->get('category');

        $query = AcrlStatistic::find()->orderBy(['year' => SORT_DESC, 'category' => SORT_ASC, 'subcategory' => SORT_ASC]);
        if ($year !== null && $year !== '') {
            $query->andWhere(['year' => (int) $year]);
        }
        if ($category) {
            $query->andWhere(['category' => $category]);
        }

        $rows = $query->asArray()->all();
        return [
            'items' => $rows,
            'years' => AcrlStatistic::getAvailableYears(),
        ];
    }

    /**
     * GET /api/local/acrl/years
     */
    public function actionLocalAcrlYears()
    {
        return ['years' => AcrlStatistic::getAvailableYears()];
    }

    /**
     * POST /api/local/acrl
     * Body: {rows: [{category, subcategory, year, value, notes}], row?: {...}}
     */
    public function actionLocalAcrlCreate()
    {
        $body = Yii::$app->request->getBodyParams();
        $rows = $body['rows'] ?? null;
        if ($rows === null && isset($body['row'])) {
            $rows = [$body['row']];
        }
        if (!is_array($rows) || empty($rows)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'rows is required'];
        }

        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $category = trim((string) ($row['category'] ?? ''));
            $subcategory = trim((string) ($row['subcategory'] ?? ''));
            $year = (int) ($row['year'] ?? 0);
            if ($category === '' || $subcategory === '' || $year <= 0) {
                continue;
            }

            $model = AcrlStatistic::findOne([
                'category' => $category,
                'subcategory' => $subcategory,
                'year' => $year,
            ]);
            if (!$model) {
                $model = new AcrlStatistic();
                $created++;
            } else {
                $updated++;
            }

            $model->category = $category;
            $model->subcategory = $subcategory;
            $model->year = $year;
            $model->value = array_key_exists('value', $row) ? $row['value'] : null;
            $model->notes = $row['notes'] ?? null;

            if (!$model->save()) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Validation failed', 'details' => $model->errors];
            }
        }

        return ['success' => true, 'created' => $created, 'updated' => $updated];
    }

    /**
     * PUT /api/local/acrl/<id>
     */
    public function actionLocalAcrlUpdate($id)
    {
        $model = AcrlStatistic::findOne((int) $id);
        if (!$model) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Row not found'];
        }

        $body = Yii::$app->request->getBodyParams();
        foreach (['category', 'subcategory', 'year', 'value', 'notes'] as $field) {
            if (array_key_exists($field, $body)) {
                $model->{$field} = $body[$field];
            }
        }

        if (!$model->save()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'details' => $model->errors];
        }

        return ['success' => true, 'item' => $model->toArray()];
    }

    /**
     * DELETE /api/local/acrl/<id>
     */
    public function actionLocalAcrlDelete($id)
    {
        $model = AcrlStatistic::findOne((int) $id);
        if (!$model) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Row not found'];
        }
        $model->delete();
        return ['success' => true];
    }

    /**
     * POST /api/local/acrl/copy-year
     * Body: {fromYear: number, toYear: number, overwrite?: boolean}
     */
    public function actionLocalAcrlCopyYear()
    {
        $body = Yii::$app->request->getBodyParams();
        $fromYear = (int) ($body['fromYear'] ?? 0);
        $toYear = (int) ($body['toYear'] ?? 0);
        $overwrite = !empty($body['overwrite']);

        if ($fromYear <= 0 || $toYear <= 0) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'fromYear and toYear are required'];
        }

        $sourceRows = AcrlStatistic::find()->where(['year' => $fromYear])->asArray()->all();
        if (empty($sourceRows)) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'No rows found for source year'];
        }

        $copied = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($sourceRows as $row) {
            $existing = AcrlStatistic::findOne([
                'category' => $row['category'],
                'subcategory' => $row['subcategory'],
                'year' => $toYear,
            ]);
            if ($existing) {
                if (!$overwrite) {
                    $skipped++;
                    continue;
                }
                $existing->value = $row['value'];
                $existing->notes = $row['notes'];
                $existing->save(false);
                $updated++;
                continue;
            }

            $new = new AcrlStatistic();
            $new->category = $row['category'];
            $new->subcategory = $row['subcategory'];
            $new->year = $toYear;
            $new->value = $row['value'];
            $new->notes = $row['notes'];
            $new->save(false);
            $copied++;
        }

        return ['success' => true, 'copied' => $copied, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * GET /api/local/allocations
     */
    public function actionLocalAllocList()
    {
        $fiscalYear = Yii::$app->request->get('fiscalYear');
        $query = ExpenseAllocation::find()->orderBy(['fiscal_year' => SORT_DESC, 'expense_class_code' => SORT_ASC]);
        if ($fiscalYear !== null && $fiscalYear !== '') {
            $query->andWhere(['fiscal_year' => (int) $fiscalYear]);
        }
        return [
            'items' => $query->asArray()->all(),
            'years' => ExpenseAllocation::getAvailableYears(),
        ];
    }

    /**
     * GET /api/local/allocations/years
     */
    public function actionLocalAllocYears()
    {
        return ['years' => ExpenseAllocation::getAvailableYears()];
    }

    private function parseBulkAllocationData($pastedData)
    {
        $lines = explode("\n", trim((string) $pastedData));
        $allocations = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s*\t+\s*|\s{2,}/', $line);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }

            $code = strtoupper(trim((string) $parts[count($parts) - 1]));
            $amounts = [];
            foreach ($parts as $part) {
                if (preg_match('/\$?([0-9,]+\.?\d*)/', (string) $part, $matches)) {
                    $amounts[] = (float) str_replace([',', '$'], '', $matches[1]);
                }
            }

            if ($code !== '' && !empty($amounts)) {
                $allocation = count($amounts) > 1 ? $amounts[1] : $amounts[0];
                $allocations[$code] = $allocation;
            }
        }

        return $allocations;
    }

    /**
     * POST /api/local/allocations
     * Body supports:
     * - {fiscalYear, code, amount}
     * - {fiscalYear, rows:[{expense_class_code, allocation_amount}]}
     * - {fiscalYear, pastedData:"..."}
     */
    public function actionLocalAllocUpsert()
    {
        $body = Yii::$app->request->getBodyParams();
        $fiscalYear = (int) ($body['fiscalYear'] ?? 0);
        if ($fiscalYear <= 0) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'fiscalYear is required'];
        }

        $payload = [];
        if (!empty($body['pastedData'])) {
            $parsed = $this->parseBulkAllocationData($body['pastedData']);
            foreach ($parsed as $code => $amount) {
                $payload[] = ['expense_class_code' => $code, 'allocation_amount' => $amount];
            }
        } elseif (!empty($body['rows']) && is_array($body['rows'])) {
            $payload = $body['rows'];
        } elseif (isset($body['code'], $body['amount'])) {
            $payload = [[
                'expense_class_code' => $body['code'],
                'allocation_amount' => $body['amount'],
            ]];
        }

        if (empty($payload)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'No allocation data provided'];
        }

        $inserted = 0;
        $updated = 0;
        foreach ($payload as $row) {
            $code = strtoupper(trim((string) ($row['expense_class_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $amount = (float) ($row['allocation_amount'] ?? 0);

            $model = ExpenseAllocation::findOne([
                'fiscal_year' => $fiscalYear,
                'expense_class_code' => $code,
            ]);
            if (!$model) {
                $model = new ExpenseAllocation();
                $model->fiscal_year = $fiscalYear;
                $model->expense_class_code = $code;
                $inserted++;
            } else {
                $updated++;
            }
            $model->allocation_amount = $amount;
            if (!$model->save()) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Validation failed', 'details' => $model->errors];
            }
        }

        return ['success' => true, 'inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * DELETE /api/local/allocations/<id>
     */
    public function actionLocalAllocDelete($id)
    {
        $model = ExpenseAllocation::findOne((int) $id);
        if (!$model) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Allocation not found'];
        }
        $model->delete();
        return ['success' => true];
    }

    /**
     * POST /api/local/allocations/copy-year
     * Body: {fiscalYear: number}
     */
    public function actionLocalAllocCopyYear()
    {
        $body = Yii::$app->request->getBodyParams();
        $targetYear = (int) ($body['fiscalYear'] ?? 0);
        if ($targetYear <= 0) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'fiscalYear is required'];
        }

        try {
            $result = ExpenseAllocation::copyFromPreviousYear($targetYear);
            if (!$result['foundSource']) {
                Yii::$app->response->statusCode = 404;
                return ['error' => 'No rows found in previous fiscal year'];
            }

            return ['success' => true] + $result;
        } catch (\Throwable $e) {
            Yii::$app->response->statusCode = 500;
            return ['error' => $e->getMessage()];
        }
    }

    // ─── Auth endpoints ────────────────────────────────────────────

    /**
     * GET /api/auth/me — return current authenticated user info.
     */
    public function actionAuthMe()
    {
        if (Yii::$app->user->isGuest) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Not authenticated'];
        }

        return Yii::$app->user->identity->toArray();
    }

    /**
     * POST /api/auth/refresh — exchange refresh token for new access token.
     * Body: {refreshToken: string}
     */
    public function actionAuthRefresh()
    {
        $body = Yii::$app->request->getBodyParams();
        $refreshToken = $body['refreshToken'] ?? '';

        if (empty($refreshToken)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'refreshToken is required'];
        }

        $secret = getenv('JWT_SECRET');
        if (!$secret) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'JWT not configured'];
        }

        try {
            $decoded = JWT::decode($refreshToken, $secret, ['HS256']);

            if (!isset($decoded->sub) || ($decoded->type ?? '') !== 'refresh') {
                Yii::$app->response->statusCode = 401;
                return ['error' => 'Invalid refresh token'];
            }

            $user = User::findOne((int) $decoded->sub);
            if (!$user || !$user->is_approved) {
                Yii::$app->response->statusCode = 401;
                return ['error' => 'User not found or not approved'];
            }

            // Verify token hasn't been revoked
            if (!$user->validateRefreshToken($refreshToken)) {
                Yii::$app->response->statusCode = 401;
                return ['error' => 'Refresh token has been revoked'];
            }

            // Generate new token pair
            $newAccessToken = $user->generateAccessToken();
            $newRefreshToken = $user->generateRefreshToken();

            return [
                'accessToken' => $newAccessToken,
                'refreshToken' => $newRefreshToken,
                'user' => $user->toArray(),
            ];
        } catch (\Exception $e) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Invalid or expired refresh token'];
        }
    }

    /**
     * POST /api/auth/logout — revoke refresh token.
     */
    public function actionAuthLogout()
    {
        if (!Yii::$app->user->isGuest) {
            Yii::$app->user->identity->revokeRefreshToken();
        }
        return ['success' => true];
    }

    // ─── User management (admin) ──────────────────────────────────

    /**
     * GET /api/users — list all users.
     */
    public function actionUserList()
    {
        $users = User::find()
            ->orderBy(['created_at' => SORT_DESC])
            ->all();

        return array_map(function ($u) {
            return $u->toArray();
        }, $users);
    }

    /**
     * PUT /api/users/<id>/approve — toggle user approval.
     */
    public function actionUserApprove($id)
    {
        $user = User::findOne($id);
        if (!$user) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'User not found'];
        }

        $body = Yii::$app->request->getBodyParams();
        $user->is_approved = !empty($body['approved']) ? 1 : 0;
        $user->save(false);

        return $user->toArray();
    }

    /**
     * PUT /api/users/<id>/role — change user role.
     */
    public function actionUserRole($id)
    {
        $user = User::findOne($id);
        if (!$user) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'User not found'];
        }

        $body = Yii::$app->request->getBodyParams();
        $role = $body['role'] ?? '';

        if (!in_array($role, ['admin', 'user'])) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Role must be "admin" or "user"'];
        }

        $user->role = $role;
        $user->save(false);

        return $user->toArray();
    }

    /**
     * PUT /api/users/<id>/notifications — toggle notification preference.
     */
    public function actionUserNotifications($id)
    {
        $user = User::findOne($id);
        if (!$user) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'User not found'];
        }

        $body = Yii::$app->request->getBodyParams();
        $user->receive_notifications = !empty($body['receive']) ? 1 : 0;
        $user->save(false);

        return $user->toArray();
    }

    /**
     * DELETE /api/users/<id> — delete a user.
     */
    public function actionUserDelete($id)
    {
        // Prevent self-deletion
        if (!Yii::$app->user->isGuest && (int) $id === Yii::$app->user->id) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Cannot delete your own account'];
        }

        $user = User::findOne($id);
        if (!$user) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'User not found'];
        }

        $reviewService = new AdministratorReviewService(Yii::$app->db);
        $reviewService->purgeUserContent((int) $id);
        $user->delete();
        return ['success' => true];
    }

    // ─── Query history ────────────────────────────────────────────

    /**
     * PATCH /api/query/history/<id> — rename a completed query job.
     * Body: {"name": "New name"}
     */
    public function actionRenameHistoryJob($id)
    {
        $job = QueryJob::findOne($id);
        if (!$job) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Job not found'];
        }

        // Only the owner or an admin may rename
        $userId   = $this->getCurrentUserId();
        $identity = $this->getAppIdentity();
        $isAdmin  = $identity && $identity->isAdmin();
        if (!$isAdmin && $userId && (int) $job->user_id !== (int) $userId) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Forbidden'];
        }

        $body = Yii::$app->request->getBodyParams();
        $name = isset($body['name']) ? trim($body['name']) : null;
        $job->name = $this->normalizeQueryJobName($name);
        if (!$job->save(false)) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to save'];
        }

        return ['jobId' => $job->id, 'name' => $job->name];
    }

    /**
     * GET /api/query/history — list jobs, optionally filtered by status.
     * Optional: ?limit=50&offset=0&status=all|active|completed|failed
     */
    public function actionQueryHistory()
    {
        $userId = $this->getCurrentUserId();
        $identity = $this->getAppIdentity();
        $isAdmin = $identity && $identity->isAdmin();
        $limit        = (int) (Yii::$app->request->get('limit', 50));
        $offset       = (int) (Yii::$app->request->get('offset', 0));
        $statusFilter = Yii::$app->request->get('status', 'all');
        $mineOnly     = filter_var(Yii::$app->request->get('mine', false), FILTER_VALIDATE_BOOLEAN);
        $advisoryReviewSubquery = "(SELECT r2.id
            FROM ai_report_generations linked_generation
            INNER JOIN ai_report_reviews r2
                ON r2.generation_id = linked_generation.id
                OR r2.generation_id = linked_generation.parent_generation_id
            WHERE linked_generation.query_job_id = qj.id
              AND r2.advisory_state IN ('cautioned', 'superseded')
            ORDER BY r2.updated_at DESC, r2.id DESC
            LIMIT 1)";

        $query = QueryJob::find()
            ->select([
                'qj.*',
                'u.email AS runBy',
                'r.advisory_state AS reviewAdvisoryState',
                'r.superseded_by_job_id AS reviewSupersededByJobId',
            ])
            ->alias('qj')
            ->leftJoin('users u', 'u.id = qj.user_id')
            ->leftJoin('ai_report_reviews r', 'r.id = ' . $advisoryReviewSubquery)
            ->orderBy(['qj.completed_at' => SORT_DESC, 'qj.created_at' => SORT_DESC])
            ->limit(min($limit, 100))
            ->offset($offset);

        if ($statusFilter === 'active') {
            $query->andWhere(['qj.status' => ['pending', 'pending_export', 'running', 'cancelling']]);
        } elseif ($statusFilter === 'completed') {
            $query->andWhere(['qj.status' => 'completed']);
        } elseif ($statusFilter === 'failed') {
            $query->andWhere(['qj.status' => 'failed']);
        }
        // 'all' — no status restriction

        if ($mineOnly) {
            if ($userId) {
                $query->andWhere(['qj.user_id' => $userId]);
            } else {
                $query->andWhere('1=0');
            }
        }

        // All authenticated users can see the full history.
        // The 'Mine Only' toggle above lets users filter to their own jobs.
        // Deletion is still restricted: canDelete checks ownership or admin role.

        $total = (clone $query)->count();
        $jobs  = $query->asArray()->all();

        return [
            'total'  => (int) $total,
            'offset' => $offset,
            'limit'  => $limit,
            'items'  => array_map(function ($job) use ($isAdmin, $userId) {
                $authorized = $isAdmin
                    || ($userId !== null && (int) $job['user_id'] === (int) $userId);
                $terminal = in_array($job['status'], ['completed', 'failed', 'cancelled'], true);
                $canDelete = $authorized && $terminal;
                $item = [
                    'jobId'           => $job['id'],
                    'name'            => $job['name'] ?? null,
                    'status'          => $job['status'],
                    'sql'             => $job['sql_text'],
                    'source'          => $job['source'],
                    'dataSource'      => $job['data_source'] ?? 'folio',
                    'progressMessage' => $job['progress_message'] ?? null,
                    'rowCount'        => (int) ($job['row_count'] ?? 0),
                    'executionTimeMs' => (int) ($job['execution_time_ms'] ?? 0),
                    'errorMessage'    => $job['error_message'] ?? null,
                    'createdAt'       => $job['created_at'],
                    'startedAt'       => $job['started_at'] ?? null,
                    'completedAt'     => $job['completed_at'],
                    'runBy'           => $job['runBy'] ?? null,
                    'canDelete'       => $canDelete,
                ];

                if (($job['reviewAdvisoryState'] ?? null) === 'cautioned') {
                    $item['reviewAdvisory'] = [
                        'state' => 'cautioned',
                        'message' => 'A reporting specialist identified an important limitation in this result.',
                    ];
                } elseif (($job['reviewAdvisoryState'] ?? null) === 'superseded') {
                    if (!empty($job['reviewSupersededByJobId'])) {
                        $item['reviewAdvisory'] = [
                            'state' => 'superseded',
                            'message' => 'A corrected version of this report is available.',
                            'supersededByJobId' => $job['reviewSupersededByJobId'],
                        ];
                    } else {
                        $item['reviewAdvisory'] = [
                            'state' => 'superseded',
                            'message' => 'A corrected version of this report was created, but it is no longer available in your history.',
                        ];
                    }
                }

                return $item;
            }, $jobs),
        ];
    }

    /**
     * POST /api/query/history/<id>/suggestions
     *
     * Generate related follow-up NL prompts for a specific historical query job.
     */
    public function actionQueryHistorySuggestions($id)
    {
        $userId = $this->getCurrentUserId();
        $identity = $this->getAppIdentity();
        $isAdmin = $identity && $identity->isAdmin();

        $job = QueryJob::findOne($id);
        if (!$job) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Job not found'];
        }

        if (!$isAdmin && $userId && (int)$job->user_id !== (int)$userId) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Forbidden'];
        }

        $sql = trim((string)($job->sql_text ?? ''));
        if ($sql === '') {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Historical query has no SQL text'];
        }

        $promptSeed = $this->getQueryJobOriginalPrompt($job);
        if ($promptSeed === '') {
            $promptSeed = 'Suggest related analysis for this historical query';
        }

        $warnings = [];
        $suggestions = [];
        $suggestionSource = 'gemini';

        try {
            $suggestions = GeminiService::suggestFollowUpQueries($promptSeed, $sql, '', null);
        } catch (\Throwable $e) {
            $suggestionSource = 'heuristic';
            $warnings[] = 'AI suggestion generation failed: ' . $e->getMessage();
            Yii::warning(
                'Query history suggestion generation failed for job ' . $job->id . ': ' . $e->getMessage(),
                'nl2sql.history_suggestions'
            );
        }

        if (empty($suggestions)) {
            $suggestionSource = 'heuristic';
            $suggestions = $this->buildHistorySuggestionFallback($promptSeed, $sql);
        }

        return [
            'jobId' => (string)$job->id,
            'promptSeed' => $promptSeed,
            'suggestions' => $suggestions,
            'suggestionSource' => $suggestionSource,
            'warnings' => $warnings,
        ];
    }

    /**
     * Deterministic follow-up suggestions for history queries when AI is unavailable.
     *
     * @param string $promptSeed
     * @param string $sql
     * @return array
     */
    private function buildHistorySuggestionFallback($promptSeed, $sql)
    {
        $text = strtolower(trim((string)$promptSeed . ' ' . (string)$sql));

        $generic = [
            'Break this result down by month over the last 12 months',
            'Show top 10 categories contributing most to this result',
            'Compare this metric across campuses and highlight differences',
            'List records missing key fields related to this query',
            'Show year-over-year trend changes for this metric',
        ];

        $circulation = [
            'Show circulation counts by material type',
            'Which locations have the highest and lowest circulation',
            'Break circulation down by patron group',
            'Show monthly circulation trend and peak periods',
            'Which call number ranges are trending up versus last year',
        ];

        $finance = [
            'Show spending trend by fiscal year',
            'Which vendors account for the highest share of spending',
            'Break spending down by fund and expense class',
            'Compare encumbered versus expended amounts for this scope',
            'Which funds are most over or under budget this year',
        ];

        $inventory = [
            'Break inventory count down by library and location',
            'Show item age distribution for this result set',
            'Which call number ranges are most represented',
            'Show records added in the last 90 days for this criteria',
            'List locations with the highest concentration of these items',
        ];

        if (preg_match('/spent|spend|budget|invoice|encumber|expend|vendor|fund|fiscal/', $text)) {
            return $finance;
        }

        if (preg_match('/loan|checkout|circulation|renew|return|call number/', $text)) {
            return $circulation;
        }

        if (preg_match('/item|holdings|instance|location|inventory|material type/', $text)) {
            return $inventory;
        }

        return $generic;
    }

    /**
     * POST /api/query/index-recommendations
     *
     * Build a workload snapshot from recent query history and ask Gemini
     * for index recommendations.
     *
     * Body (optional):
     * {
     *   "days": 30,
     *   "maxLogs": 300,
     *   "maxPatterns": 25
     * }
     */
    public function actionQueryIndexRecommendations()
    {
        if (!$this->requireAdmin()) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Admin access required'];
        }

        $body = Yii::$app->request->getBodyParams();
        $days = max(1, min(180, (int)($body['days'] ?? 30)));
        $maxLogs = max(50, min(2000, (int)($body['maxLogs'] ?? 300)));
        $maxPatterns = max(5, min(100, (int)($body['maxPatterns'] ?? 25)));

        try {
            $snapshot = IndexRecommendationService::buildWorkloadSnapshot($days, $maxLogs, $maxPatterns);
            $workload = $snapshot['workload'] ?? [];

            if ((int)($workload['uniqueQueryPatterns'] ?? 0) === 0) {
                return [
                    'generatedAt' => gmdate('c'),
                    'summary' => 'No eligible query history found for the selected window.',
                    'workload' => $workload,
                    'recommendations' => [],
                    'recommendationSource' => 'none',
                    'notes' => [
                        'Run and complete more FOLIO queries to collect workload evidence before recommending indexes.',
                    ],
                ];
            }

            $aiResult = [
                'summary' => '',
                'recommendations' => [],
                'notes' => [],
                'model' => null,
                'promptVersion' => null,
            ];
            $warnings = [];

            try {
                $aiResult = GeminiService::recommendIndexesFromHistory($snapshot);
            } catch (\Throwable $aiError) {
                Yii::warning(
                    'Index recommendation AI generation failed: ' . $aiError->getMessage(),
                    'index.recommendation'
                );

                $warnings[] = 'Gemini recommendation generation failed: ' . $aiError->getMessage();
                $aiResult['summary'] = 'Workload snapshot generated, but AI recommendations are temporarily unavailable.';
                $aiResult['notes'] = [
                    'Retry the request in a moment. The workload evidence has been collected successfully.',
                ];
            }

            $finalRecommendations = IndexRecommendationService::finalizeRecommendations(
                $aiResult['recommendations'] ?? [],
                $snapshot['existingIndexesByTable'] ?? [],
                $workload['tables'] ?? []
            );

            $recommendationSource = 'gemini';
            if (empty($finalRecommendations)) {
                $heuristic = IndexRecommendationService::generateHeuristicRecommendations(
                    $workload,
                    $snapshot['existingIndexesByTable'] ?? []
                );

                if (!empty($heuristic['recommendations'])) {
                    $finalRecommendations = $heuristic['recommendations'];
                    $recommendationSource = 'heuristic';

                    if (!empty($warnings)) {
                        $heuristicNotes = is_array($heuristic['notes'] ?? null) ? $heuristic['notes'] : [];
                        $heuristicNotes[] = 'Gemini output was unavailable or malformed, so deterministic fallback recommendations were returned.';
                        $heuristic['notes'] = array_values(array_unique($heuristicNotes));
                    }

                    $aiResult['summary'] = trim((string)($heuristic['summary'] ?? ''));
                    $aiResult['notes'] = is_array($heuristic['notes'] ?? null) ? $heuristic['notes'] : [];
                } else {
                    $recommendationSource = 'none';
                    if (trim((string)($aiResult['summary'] ?? '')) === '') {
                        $aiResult['summary'] = trim((string)($heuristic['summary'] ?? 'No index recommendations were produced for this workload.'));
                    }

                    $mergedNotes = [];
                    if (is_array($aiResult['notes'] ?? null)) {
                        $mergedNotes = array_merge($mergedNotes, $aiResult['notes']);
                    }
                    if (is_array($heuristic['notes'] ?? null)) {
                        $mergedNotes = array_merge($mergedNotes, $heuristic['notes']);
                    }
                    $aiResult['notes'] = array_values(array_unique($mergedNotes));
                }
            }

            return [
                'generatedAt' => gmdate('c'),
                'summary' => $aiResult['summary'] ?? '',
                'workload' => [
                    'logsAnalyzed' => (int)($workload['logsAnalyzed'] ?? 0),
                    'eligibleLogs' => (int)($workload['eligibleLogs'] ?? 0),
                    'uniqueQueryPatterns' => (int)($workload['uniqueQueryPatterns'] ?? 0),
                    'tables' => $workload['tables'] ?? [],
                    'queryPatterns' => $workload['queryPatterns'] ?? [],
                ],
                'recommendations' => $finalRecommendations,
                'recommendationSource' => $recommendationSource,
                'notes' => $aiResult['notes'] ?? [],
                'model' => $aiResult['model'] ?? null,
                'promptVersion' => $aiResult['promptVersion'] ?? null,
                'warnings' => $warnings,
            ];
        } catch (\Throwable $e) {
            Yii::error('Index recommendation generation failed: ' . $e->getMessage(), 'index.recommendation');
            Yii::$app->response->statusCode = 500;
            return [
                'error' => 'Failed to generate index recommendations: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * DELETE /api/query/history/<id> — remove a single job from history.
     * Admins may delete any job; regular users only their own.
     */
    public function actionDeleteHistoryJob($id)
    {
        $userId   = $this->getCurrentUserId();
        $identity = $this->getAppIdentity();
        $isAdmin  = $identity && $identity->isAdmin();

        $job = QueryJob::findOne((string) $id);
        if (!$job) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Job not found'];
        }

        if (!$isAdmin && ($userId === null || (int) $job->user_id !== (int) $userId)) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Forbidden'];
        }

        try {
            $service = new QueryHistoryDeletionService(Yii::getAlias('@runtime/exports'));
            $service->delete($job);
        } catch (\DomainException $exception) {
            Yii::$app->response->statusCode = 409;
            return ['error' => $exception->getMessage()];
        } catch (\Throwable $exception) {
            Yii::error(
                'Query history deletion failed for job ' . $job->id . ': ' . $exception,
                'query.history.deletion'
            );
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Unable to delete this history item right now. Please try again.'];
        }

        return ['success' => true, 'jobId' => (string) $id];
    }

    // ─── Expense Class Monitor endpoints ──────────────────────────

    /**
     * GET /api/expense-monitor/codes
     * Returns all SC-prefixed expense classes available in FOLIO, ordered by name.
     * Used to populate the code-selection UI.
     */
    public function actionExpenseMonitorCodes()
    {
        try {
            $rows = Yii::$app->folioDb->createCommand(
                "SELECT code, name FROM finance.expense_class__t
                  WHERE name LIKE 'SC%'
                  ORDER BY name ASC"
            )->queryAll();
            return ['codes' => $rows];
        } catch (\Exception $e) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to fetch expense class codes: ' . $e->getMessage()];
        }
    }

    /**
     * GET /api/expense-monitor
     * Returns the current user's monitored expense class codes.
     */
    public function actionExpenseMonitorList()
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Not authenticated'];
        }

        $rows = Yii::$app->db->createCommand(
            'SELECT expense_class_code FROM user_expense_monitors
              WHERE user_id = :uid ORDER BY expense_class_code ASC',
            [':uid' => $userId]
        )->queryAll();

        return ['codes' => array_column($rows, 'expense_class_code')];
    }

    /**
     * POST /api/expense-monitor
     * Replace the current user's entire set of monitored codes.
     * Body: {codes: string[]}
     */
    public function actionExpenseMonitorSave()
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Not authenticated'];
        }

        $body  = Yii::$app->request->getBodyParams();
        $codes = $body['codes'] ?? [];

        if (!is_array($codes)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'codes must be an array'];
        }

        // Sanitise: uppercase, non-empty, max 20 chars
        $codes = array_values(array_unique(array_filter(
            array_map(function ($c) { return strtoupper(trim((string) $c)); }, $codes),
            function ($c) { return $c !== '' && strlen($c) <= 20; }
        )));

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand(
                'DELETE FROM user_expense_monitors WHERE user_id = :uid',
                [':uid' => $userId]
            )->execute();

            foreach ($codes as $code) {
                $db->createCommand(
                    'INSERT INTO user_expense_monitors (user_id, expense_class_code) VALUES (:uid, :code)',
                    [':uid' => $userId, ':code' => $code]
                )->execute();
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to save monitored codes: ' . $e->getMessage()];
        }

        return ['success' => true, 'codes' => $codes];
    }

    /**
     * DELETE /api/expense-monitor/<code>
     * Remove a single expense class code from the current user's monitor list.
     */
    public function actionExpenseMonitorRemove($code)
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Not authenticated'];
        }

        $code = strtoupper(trim((string) $code));
        Yii::$app->db->createCommand(
            'DELETE FROM user_expense_monitors WHERE user_id = :uid AND expense_class_code = :code',
            [':uid' => $userId, ':code' => $code]
        )->execute();

        return ['success' => true];
    }

    /**
     * POST /api/expense-monitor/refresh
     * Enqueue a composite budget-vs-actual job scoped to the user's monitored codes.
     * Returns {jobId} for polling via GET /api/query/status/{jobId}.
     * Optional body: {fiscalYear: number} — defaults to the current fiscal year.
     */
    public function actionExpenseMonitorRefresh()
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Not authenticated'];
        }

        // Fetch the user's monitored codes
        $rows = Yii::$app->db->createCommand(
            'SELECT expense_class_code FROM user_expense_monitors
              WHERE user_id = :uid ORDER BY expense_class_code ASC',
            [':uid' => $userId]
        )->queryAll();

        $monitoredCodes = array_column($rows, 'expense_class_code');

        if (empty($monitoredCodes)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'No expense classes are being monitored. Add codes first.'];
        }

        // Resolve fiscal year and date range
        $body = Yii::$app->request->getBodyParams();
        $requestedFy = isset($body['fiscalYear']) ? (int) $body['fiscalYear'] : 0;

        $startDate = ReportTemplate::resolveDefaultMacro('$fiscal_year_start');
        $endDate   = ReportTemplate::resolveDefaultMacro('$fiscal_year_end');

        if ($requestedFy > 0) {
            $startDate = ($requestedFy - 1) . '-07-01';
            $endDate   = $requestedFy . '-06-30';
            $fiscalYear = $requestedFy;
        } else {
            // Derive fiscal year from resolved dates (year of the June 30 end date)
            $fiscalYear = (int) substr($endDate, 0, 4);
        }

        // Build the SQL: same template as migration 015 but with an IN-list filter
        // for the user's monitored expense class codes appended to the WHERE clause.
        // We add a separate CTE-level filter so the expensive cross-join is still
        // driven by the ect rows first.
        $placeholders = implode(', ', array_map(
            function ($i) { return ':monCode' . $i; },
            array_keys($monitoredCodes)
        ));

        $sql = <<<SQL
WITH fiscal_years AS (
    SELECT id
    FROM finance.fiscal_year__t
    WHERE series = 'SCFY'
      AND (
          (:startDate::date BETWEEN period_start::date AND period_end::date)
          OR
          (:endDate::date BETWEEN period_start::date AND period_end::date)
      )
),
encumbrances AS (
    SELECT
        tt.encumbrance__source_po_line_id AS po_line_id,
        tt.expense_class_id,
        tt.from_fund_id,
        SUM(
            tt.encumbrance__initial_amount_encumbered
            - tt.encumbrance__amount_expended
            - tt.encumbrance__amount_awaiting_payment
        ) AS current_encumbrance
    FROM finance.transaction__t tt
    WHERE tt.transaction_type = 'Encumbrance'
      AND tt.encumbrance__status IN ('Unreleased', 'Active')
      AND tt.fiscal_year_id IN (SELECT id FROM fiscal_years)
      AND tt.from_fund_id IN (
          '6330d805-1772-4c14-b25d-5f4599964dd9',
          '83d5d13c-8c9a-4ff2-89dc-e61120f5025f'
      )
    GROUP BY tt.encumbrance__source_po_line_id, tt.expense_class_id, tt.from_fund_id
),
payments AS (
    SELECT
        SUM(iltfd.total * (iltfd.fund_distributions__value * 0.01)) AS payment,
        iltfd.po_line_id,
        iltfd.fund_distributions__expense_class_id AS expense_class_id,
        iltfd.fund_distributions__fund_id AS fund_id
    FROM invoice.invoice_lines__t__fund_distributions iltfd
    INNER JOIN invoice.invoices__t it ON it.id = iltfd.invoice_id
    WHERE iltfd.invoice_line_status = 'Paid'
      AND it.payment_date::date BETWEEN :startDate::date AND :endDate::date
      AND iltfd.fund_distributions__fund_id IN (
          '6330d805-1772-4c14-b25d-5f4599964dd9',
          '83d5d13c-8c9a-4ff2-89dc-e61120f5025f'
      )
    GROUP BY iltfd.po_line_id, iltfd.fund_distributions__expense_class_id, iltfd.fund_distributions__fund_id
),
po_lines AS (
    SELECT plt.*, potaui.acq_unit_ids
    FROM orders.po_line__t plt
    INNER JOIN orders.purchase_order__t__acq_unit_ids potaui
        ON plt.purchase_order_id = potaui.id
    WHERE potaui.acq_unit_ids = 'b17b9e6b-82bb-4f97-b3e7-757e4e5aeb61'
),
material_types AS (
    SELECT id, name FROM inventory.material_type__t
)
SELECT
    ect.name AS "Expense Class Name",
    ect.code AS "Expense Class Code",
    ROUND(COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '') = 'Book'
             AND payments.fund_id = '6330d805-1772-4c14-b25d-5f4599964dd9'
        THEN payments.payment ELSE 0 END), 0), 2) AS "Book Payments",
    ROUND(COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '') = 'E-Book'
             AND payments.fund_id = '83d5d13c-8c9a-4ff2-89dc-e61120f5025f'
        THEN payments.payment ELSE 0 END), 0), 2) AS "E-Book Payments",
    ROUND(COALESCE(SUM(payments.payment), 0), 2) AS "Total Payments",
    ROUND(COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '') = 'Book'
             AND encumbrances.from_fund_id = '6330d805-1772-4c14-b25d-5f4599964dd9'
        THEN encumbrances.current_encumbrance ELSE 0 END), 0), 2) AS "Book Encumbrances",
    ROUND(COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '') = 'E-Book'
             AND encumbrances.from_fund_id = '83d5d13c-8c9a-4ff2-89dc-e61120f5025f'
        THEN encumbrances.current_encumbrance ELSE 0 END), 0), 2) AS "E-Book Encumbrances",
    ROUND(COALESCE(SUM(encumbrances.current_encumbrance), 0), 2) AS "Total Encumbrances",
    ROUND(
        COALESCE(SUM(payments.payment), 0)
        + COALESCE(SUM(encumbrances.current_encumbrance), 0)
    , 2) AS "Total Spent"
FROM finance.expense_class__t ect
LEFT JOIN po_lines plt ON 1=1
LEFT JOIN payments
    ON plt.id = payments.po_line_id
    AND payments.expense_class_id = ect.id
LEFT JOIN encumbrances
    ON plt.id = encumbrances.po_line_id
    AND encumbrances.expense_class_id = ect.id
LEFT JOIN material_types mtte ON mtte.id = plt.eresource__material_type
LEFT JOIN material_types mttp ON mttp.id = plt.physical__material_type
WHERE ect.code IN ({$placeholders})
GROUP BY ect.name, ect.code
ORDER BY ect.name ASC
SQL;

        $params = [
            ':startDate'  => $startDate,
            ':endDate'    => $endDate,
            ':fiscalYear' => $fiscalYear,
        ];
        foreach ($monitoredCodes as $i => $code) {
            $params[':monCode' . $i] = $code;
        }

        $compositeConfig = [
            'secondary_sql'    => 'SELECT expense_class_code, allocation_amount FROM report_expense_allocations WHERE fiscal_year = :fiscalYear',
            'secondary_db'     => 'local',
            'merge_key'        => ['primary' => 'Expense Class Code', 'secondary' => 'expense_class_code'],
            'append_columns'   => ['allocation_amount AS Allocation'],
            'computed_columns' => [['name' => 'Remaining', 'formula' => 'Allocation - Total Payments - Total Encumbrances']],
        ];

        $metadata = ['composite_config' => $compositeConfig, 'bound_params' => $params];

        $job = QueryJob::createJob($sql, $params, 'report', 'composite', $metadata);
        $job->user_id = $userId;
        if (!$job->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to create job', 'details' => $job->errors];
        }

        Yii::$app->response->statusCode = 202;
        return [
            'jobId'      => $job->id,
            'fiscalYear' => $fiscalYear,
            'codes'      => $monitoredCodes,
            'status'     => 'pending',
        ];
    }

    // ─── Dashboard Widget Catalog ─────────────────────────────────────

    /**
     * GET /api/dashboard/widgets
     * Returns the full widget catalog (enabled templates), each annotated with
     * whether the current user has already added it (is_added).
     * For 'report' widget types the report template's required parameters are
     * included so the frontend can render a setup form.
     */
    public function actionDashboardWidgets()
    {
        $userId = $this->getCurrentUserId();

        // Fetch all enabled templates ordered by sort_order
        $templates = Yii::$app->db->createCommand(
            'SELECT id, name, description, category, icon, widget_type,
                    report_template_id, default_params, sort_order
             FROM dashboard_widget_templates
             WHERE is_enabled = 1
             ORDER BY sort_order ASC, id ASC'
        )->queryAll();

        // Determine which templates this user has already added
        $addedIds = [];
        if ($userId && !empty($templates)) {
            $rows = Yii::$app->db->createCommand(
                'SELECT widget_template_id FROM user_dashboard_widgets WHERE user_id = :uid',
                [':uid' => $userId]
            )->queryAll();
            foreach ($rows as $r) {
                $addedIds[(int)$r['widget_template_id']] = true;
            }
        }

        // For report widgets, attach required non-date parameters so the frontend
        // can render a compact setup form before the widget is added.
        $result = [];
        foreach ($templates as $tpl) {
            $tpl['id']       = (int)$tpl['id'];
            $tpl['sort_order'] = (int)$tpl['sort_order'];
            $tpl['is_added'] = isset($addedIds[$tpl['id']]);
            $tpl['default_params'] = $tpl['default_params']
                ? json_decode($tpl['default_params'], true)
                : [];
            $tpl['setup_params'] = [];

            if ($tpl['widget_type'] === 'report' && $tpl['report_template_id']) {
                $rt = ReportTemplate::findOne((int)$tpl['report_template_id']);
                if ($rt) {
                    // Only surface required select/text params that are NOT date or
                    // fiscal-year params (those are auto-filled from academic year defaults).
                    $setupParams = [];
                    foreach ($rt->getResolvedParameters() as $def) {
                        if (empty($def['required'])) {
                            continue;
                        }
                        $skipTypes = ['date'];
                        if (in_array($def['type'] ?? 'text', $skipTypes, true)) {
                            continue;
                        }
                        // Skip $current_year / $fiscal_year_* defaulted number params
                        $default = $def['default'] ?? '';
                        if (strpos($default, '$fiscal_year') === 0 || $default === '$current_year') {
                            continue;
                        }
                        $param = [
                            'name'      => $def['name'],
                            'label'     => $def['label'],
                            'type'      => $def['type'],
                            'required'  => true,
                            'placeholder' => $def['placeholder'] ?? '',
                            'description' => $def['description'] ?? '',
                            'options'   => [],
                        ];
                        // For select params, fetch options from FOLIO immediately so
                        // the UI doesn't need an extra round-trip.
                        if ($def['type'] === 'select' && !empty($def['options_sql'])) {
                            try {
                                $db = !empty($def['options_db']) && $def['options_db'] === 'folio'
                                    ? Yii::$app->folioDb
                                    : Yii::$app->folioDb; // report widgets are FOLIO by default
                                $opts = $db->createCommand($def['options_sql'])->queryAll();
                                $param['options'] = $opts;
                            } catch (\Exception $e) {
                                // Let the frontend fall back to manual input
                            }
                        }
                        $setupParams[] = $param;
                    }
                    $tpl['setup_params'] = $setupParams;
                }
            }

            $result[] = $tpl;
        }

        return ['widgets' => $result];
    }

    /**
     * POST /api/dashboard/widgets/:id/add
     * Adds a widget to the current user's dashboard.
     *
     * For 'report' widgets: creates a SavedQuery with SQL resolved from the
     * report template (using supplied params + academic-year defaults), pins it,
     * and records the link in user_dashboard_widgets.
     *
     * For 'budget_monitor' widgets: just inserts the user_dashboard_widgets row.
     *
     * Request body: { params: { paramName: value, ... } }
     */
    public function actionDashboardWidgetAdd($id)
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Not authenticated'];
        }

        $tpl = Yii::$app->db->createCommand(
            'SELECT * FROM dashboard_widget_templates WHERE id = :id AND is_enabled = 1',
            [':id' => (int)$id]
        )->queryOne();

        if (!$tpl) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Widget template not found'];
        }

        // Idempotent: already added
        $existing = Yii::$app->db->createCommand(
            'SELECT id FROM user_dashboard_widgets WHERE user_id = :uid AND widget_template_id = :wid',
            [':uid' => $userId, ':wid' => (int)$id]
        )->queryOne();
        if ($existing) {
            return ['status' => 'already_added'];
        }

        $body = Yii::$app->request->getBodyParams();
        $userParams = !empty($body['params']) && is_array($body['params']) ? $body['params'] : [];

        $savedQueryId = null;

        if ($tpl['widget_type'] === 'report') {
            $rt = ReportTemplate::findOne((int)$tpl['report_template_id']);
            if (!$rt) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Report template not found'];
            }

            // Merge: template default_params < user-supplied params
            $defaultParams = $tpl['default_params'] ? json_decode($tpl['default_params'], true) : [];
            $mergedParams = array_merge($defaultParams, $userParams);

            // Build bound SQL (params resolved against academic-year defaults where not supplied)
            $bound = $rt->bindParams($mergedParams);

            $sq = new SavedQuery();
            $sq->user_id     = $userId;
            $sq->name        = $tpl['name'];
            $sq->description = $tpl['description'];
            $sq->source      = 'report';
            $sq->is_pinned   = 1;
            $sq->generated_sql = $bound['sql'];
            $sq->query_definition = json_encode([
                'widget_template_id' => (int)$tpl['id'],
                'report_template_id' => (int)$tpl['report_template_id'],
                'params'             => $mergedParams,
                'bound_params'       => $bound['params'],
            ]);

            if (!$sq->save()) {
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Failed to create saved query', 'details' => $sq->errors];
            }
            $savedQueryId = (int)$sq->id;
        }

        // Record the user widget row
        Yii::$app->db->createCommand()->insert('user_dashboard_widgets', [
            'user_id'            => $userId,
            'widget_template_id' => (int)$id,
            'saved_query_id'     => $savedQueryId,
        ])->execute();

        Yii::$app->response->statusCode = 201;
        return ['status' => 'added', 'savedQueryId' => $savedQueryId];
    }

    /**
     * DELETE /api/dashboard/widgets/:id/remove
     * Removes a widget from the current user's dashboard.
     * For 'report' widgets, the linked SavedQuery is un-pinned and deleted.
     */
    public function actionDashboardWidgetRemove($id)
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Not authenticated'];
        }

        $row = Yii::$app->db->createCommand(
            'SELECT udw.id, udw.saved_query_id, dwt.widget_type
             FROM user_dashboard_widgets udw
             JOIN dashboard_widget_templates dwt ON dwt.id = udw.widget_template_id
             WHERE udw.user_id = :uid AND udw.widget_template_id = :wid',
            [':uid' => $userId, ':wid' => (int)$id]
        )->queryOne();

        if (!$row) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Widget not found on this user\'s dashboard'];
        }

        // For report widgets: delete the linked SavedQuery (this also removes it from dashboard)
        if ($row['widget_type'] === 'report' && $row['saved_query_id']) {
            $sq = SavedQuery::findOne((int)$row['saved_query_id']);
            if ($sq && (int)$sq->user_id === $userId) {
                $sq->delete();
            }
        }

        Yii::$app->db->createCommand()->delete('user_dashboard_widgets', [
            'user_id'            => $userId,
            'widget_template_id' => (int)$id,
        ])->execute();

        return ['status' => 'removed'];
    }

    /**
     * POST /api/admin/dashboard-widgets — admin creates a new widget template.
     * Body: { name, description?, category?, icon?, widget_type, report_template_id?, default_params?, sort_order? }
     */
    public function actionAdminWidgetCreate()
    {
        if (!$this->requireAdmin()) return null;

        $body = Yii::$app->request->getBodyParams();

        $name = trim($body['name'] ?? '');
        if (empty($name)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'name is required'];
        }

        $widgetType = $body['widget_type'] ?? 'report';
        if (!in_array($widgetType, ['report', 'budget_monitor'], true)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'widget_type must be report or budget_monitor'];
        }

        $defaultParamsRaw = $body['default_params'] ?? null;
        if ($defaultParamsRaw !== null && !is_array($defaultParamsRaw)) {
            // Accept raw JSON string from the textarea
            $decoded = json_decode($defaultParamsRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Yii::$app->response->statusCode = 400;
                return ['error' => 'default_params must be valid JSON'];
            }
            $defaultParamsRaw = $decoded;
        }

        Yii::$app->db->createCommand()->insert('dashboard_widget_templates', [
            'name'               => $name,
            'description'        => $body['description'] ?? null,
            'category'           => $body['category'] ?? 'other',
            'icon'               => $body['icon'] ?? 'BarChart3',
            'widget_type'        => $widgetType,
            'report_template_id' => !empty($body['report_template_id']) ? (int)$body['report_template_id'] : null,
            'default_params'     => $defaultParamsRaw ? json_encode($defaultParamsRaw) : null,
            'sort_order'         => isset($body['sort_order']) ? (int)$body['sort_order'] : 100,
            'is_enabled'         => 1,
            'created_by'         => $this->getCurrentUserId(),
        ])->execute();

        $newId = Yii::$app->db->getLastInsertID();

        Yii::$app->response->statusCode = 201;
        return Yii::$app->db->createCommand(
            'SELECT * FROM dashboard_widget_templates WHERE id = :id',
            [':id' => $newId]
        )->queryOne();
    }

    /**
     * PUT /api/admin/dashboard-widgets/:id — admin updates a widget template.
     */
    public function actionAdminWidgetUpdate($id)
    {
        if (!$this->requireAdmin()) return null;

        $body = Yii::$app->request->getBodyParams();

        $tpl = Yii::$app->db->createCommand(
            'SELECT id FROM dashboard_widget_templates WHERE id = :id',
            [':id' => (int)$id]
        )->queryOne();
        if (!$tpl) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Widget template not found'];
        }

        $updates = [];
        $params  = [':id' => (int)$id];

        $fields = ['name', 'description', 'category', 'icon', 'widget_type', 'sort_order', 'is_enabled'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $updates[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $body[$f];
            }
        }
        if (array_key_exists('report_template_id', $body)) {
            $updates[] = '`report_template_id` = :report_template_id';
            $params[':report_template_id'] = !empty($body['report_template_id']) ? (int)$body['report_template_id'] : null;
        }
        if (array_key_exists('default_params', $body)) {
            $dp = $body['default_params'];
            if ($dp !== null && !is_array($dp)) {
                $decoded = json_decode($dp, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Yii::$app->response->statusCode = 400;
                    return ['error' => 'default_params must be valid JSON'];
                }
                $dp = $decoded;
            }
            $updates[] = '`default_params` = :default_params';
            $params[':default_params'] = $dp ? json_encode($dp) : null;
        }

        if (!empty($updates)) {
            Yii::$app->db->createCommand(
                'UPDATE dashboard_widget_templates SET ' . implode(', ', $updates) . ' WHERE id = :id',
                $params
            )->execute();
        }

        return Yii::$app->db->createCommand(
            'SELECT * FROM dashboard_widget_templates WHERE id = :id',
            [':id' => (int)$id]
        )->queryOne();
    }

    /**
     * DELETE /api/admin/dashboard-widgets/:id — admin soft-disables a widget template.
     * Users who already added the widget keep their SavedQuery cards; the template
     * simply becomes invisible in the gallery for new users.
     */
    public function actionAdminWidgetDelete($id)
    {
        if (!$this->requireAdmin()) return null;

        $affected = Yii::$app->db->createCommand(
            'UPDATE dashboard_widget_templates SET is_enabled = 0 WHERE id = :id',
            [':id' => (int)$id]
        )->execute();

        if ($affected === 0) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Widget template not found'];
        }

        return ['status' => 'disabled'];
    }

    // ─── Options (CORS) ────────────────────────────────────────────

    /**
     * OPTIONS — CORS preflight handler.
     */
    public function actionOptions()
    {
        Yii::$app->response->statusCode = 204;
        return '';
    }

    /**
     * Applies minimal SQL normalization for known legacy report templates.
     * This keeps older seeded environments working until data migrations are applied.
     */
    private function normalizeLegacyReportSql(ReportTemplate $report, string $sql): string
    {
        if (($report->slug ?? '') === 'title-list-report') {
            $sql = preg_replace(
                '/CAST\s*\(\s*substring\(\s*it\.payment_date\s*,\s*0\s*,\s*11\s*\)\s*AS\s*date\s*\)/i',
                'it.payment_date::date',
                $sql
            ) ?? $sql;

            $sql = str_replace(
                'inv.invoice_date AS "Invoice Date"',
                'inv.invoice_date::date AS "Invoice Date"',
                $sql
            );

            $sql = str_replace(
                'it.invoice_date AS invoice_date',
                'it.invoice_date::date AS invoice_date',
                $sql
            );

            $sql = str_replace(
                'GROUP BY it.invoice_date, po_line_id, ftaui."name", iltfd.invoice_line_status',
                'GROUP BY it.invoice_date::date, po_line_id, ftaui."name", iltfd.invoice_line_status',
                $sql
            );

            if (stripos($sql, '"Invoice Date"') === false) {
                $sql = str_replace(
                    "    potaui.order_type AS \"PO Type\",\n    ROUND(inv.payment, 2) AS \"Sum of Invoice Payments\",",
                    "    potaui.order_type AS \"PO Type\",\n    inv.invoice_date::date AS \"Invoice Date\",\n    ROUND(inv.payment, 2) AS \"Sum of Invoice Payments\",",
                    $sql
                );

                $sql = str_replace(
                    "SELECT ROUND(SUM(iltfd.total * (iltfd.fund_distributions__value * .01)), 2) AS payment,\n           po_line_id, ftaui.\"name\" AS fund, iltfd.invoice_line_status AS status",
                    "SELECT ROUND(SUM(iltfd.total * (iltfd.fund_distributions__value * .01)), 2) AS payment,\n           it.invoice_date::date AS invoice_date,\n           po_line_id, ftaui.\"name\" AS fund, iltfd.invoice_line_status AS status",
                    $sql
                );

                $sql = str_replace(
                    'GROUP BY po_line_id, ftaui."name", iltfd.invoice_line_status',
                    'GROUP BY it.invoice_date::date, po_line_id, ftaui."name", iltfd.invoice_line_status',
                    $sql
                );
            }

            return $sql;
        }

        return $sql;
    }
}
