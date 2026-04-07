<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\services\FolioSchemaService;
use app\services\SqlBuilderService;
use app\services\GeminiService;
use app\services\SettingsService;
use app\services\DatabaseRetryService;
use app\services\IndexRecommendationService;
use app\models\SavedQuery;
use app\models\QueryLog;
use app\models\QueryJob;
use app\models\ReportTemplate;
use app\models\AcrlStatistic;
use app\models\ExpenseAllocation;
use app\models\User;
use app\models\DummyIdentity;
use Firebase\JWT\JWT;

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
 */
class FolioQueryController extends Controller
{
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
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
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
                            'settings', 'settings-save', 'settings-test',
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
                        ],
                        'roles' => ['@'],
                        'matchCallback' => function ($rule, $action) {
                            $identity = $this->getAppIdentity();
                            return $identity && $identity->isAdmin();
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
     * Estimate query complexity using EXPLAIN (FORMAT JSON).
     * Returns null when estimation is unavailable.
     *
     * @param string $sql
     * @param string $dataSource
     * @return array|null ['rows' => int|null, 'cost' => float|null]
     */
    private function estimateQueryComplexity($sql, $dataSource)
    {
        if ($this->normalizeDataSource($dataSource) !== 'folio') {
            return null;
        }

        try {
            $db = Yii::$app->folioDb;
            $db->createCommand("SET statement_timeout = 10000")->execute();
            try {
                $row = $db->createCommand('EXPLAIN (FORMAT JSON) ' . $sql)->queryOne();
            } finally {
                $db->createCommand("SET statement_timeout = " . (int) Yii::$app->params['queryTimeoutMs'])->execute();
            }

            if ($row === false || empty($row)) {
                return null;
            }

            $first = array_values($row)[0] ?? null;
            if ($first === null) {
                return null;
            }

            if (is_string($first)) {
                $decoded = json_decode($first, true);
            } elseif (is_array($first)) {
                $decoded = $first;
            } else {
                return null;
            }

            if (!is_array($decoded) || empty($decoded[0]['Plan'])) {
                return null;
            }

            // Walk the full plan tree to find the maximum cost across all nodes.
            // A top-level LIMIT node can have a tiny cost even when an underlying
            // Materialize/CTE node is enormously expensive — reading only the root
            // would cause us to miss the real cost.
            $stack = [$decoded[0]['Plan']];
            $maxCost = 0.0;
            $topRows = null;
            $first = true;
            while (!empty($stack)) {
                $node = array_pop($stack);
                if ($first) {
                    $topRows = isset($node['Plan Rows']) ? (int) $node['Plan Rows'] : null;
                    $first = false;
                }
                if (isset($node['Total Cost'])) {
                    $maxCost = max($maxCost, (float) $node['Total Cost']);
                }
                foreach (['Plans', 'InitPlans'] as $key) {
                    if (!empty($node[$key]) && is_array($node[$key])) {
                        foreach ($node[$key] as $child) {
                            $stack[] = $child;
                        }
                    }
                }
            }

            return [
                'rows' => $topRows,
                'cost' => $maxCost > 0 ? $maxCost : null,
            ];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Statement timeout means EXPLAIN itself was cut short — can't validate but not necessarily invalid SQL.
            if (stripos($msg, 'statement timeout') !== false || stripos($msg, 'canceling statement') !== false) {
                return null;
            }
            // Extract the useful part of PostgreSQL error messages.
            // Yii wraps them as: "SQLSTATE[42601]: Syntax error: 7 ERROR:  syntax error at or near ..."
            if (preg_match('/ERROR:\s*(.+?)(?:\n|HINT:|DETAIL:|$)/s', $msg, $m)) {
                return ['error' => trim($m[1])];
            }
            return ['error' => $msg];
        }
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

        $tables = FolioSchemaService::getTables($filterArray);
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
        $data = FolioSchemaService::getTable($table);
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
            $result = SqlBuilderService::build($body);
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

        // Safety validation
        try {
            SqlBuilderService::validateSafety($sql);
            SqlBuilderService::validateTablePolicy($sql);
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => $e->getMessage()];
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
                'error' => 'Query execution failed',
                'message' => $e->getMessage(),
                'sql' => $sql,
                'dataSource' => $dataSource,
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

        // Safety validation
        try {
            SqlBuilderService::validateSafety($sql);
            SqlBuilderService::validateTablePolicy($sql);
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => $e->getMessage()];
        }

        $estimate = null;
        if ($dataSource === 'folio') {
            $estimate = $this->estimateQueryComplexity($sql, $dataSource);
            // Surface PostgreSQL validation errors immediately instead of queuing a doomed 30-minute job.
            if (isset($estimate['error'])) {
                Yii::$app->response->statusCode = 422;
                return ['error' => $estimate['error']];
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
        $job = QueryJob::createJob($sql, $params, $source, $dataSource);
        $job->user_id = $this->getCurrentUserId();
        $job->name = isset($body['name']) ? substr(trim($body['name']), 0, 255) : null;
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
        if (!$job->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to create job', 'details' => $job->errors];
        }

        Yii::$app->response->statusCode = 202;
        return $job->toStatusArray();
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

        if (!in_array($job->status, ['pending', 'pending_export', 'running'])) {
            Yii::$app->response->statusCode = 409;
            return ['error' => "Cannot cancel job with status '{$job->status}'"];
        }

        $job->status = 'cancelled';
        $job->completed_at = date('Y-m-d H:i:s');
        $job->progress_message = 'Cancelled by user';
        $job->save(false);

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

        Yii::$app->response->format = Response::FORMAT_RAW;
        return Yii::$app->response->sendFile($path, basename($path), [
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
                return ['error' => 'Queries about patron personal information or individual patron records are not supported. This system provides aggregate and operational library reporting only.'];
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

        try {
            $result = GeminiService::generateSqlWithShadow($prompt, $campus ?: null, $userId);
            if (!isset($result['dataSource'])) {
                $result['dataSource'] = 'folio';
            }

            $result['suggestions'] = [];
            if ($includeSuggestions) {
                try {
                    $result['suggestions'] = GeminiService::suggestFollowUpQueries(
                        $prompt,
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

            return $result;
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => $e->getMessage()];
        } catch (\RuntimeException $e) {
            Yii::$app->response->statusCode = 500;
            return ['error' => $e->getMessage()];
        }
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

        $model = new SavedQuery();
        $model->name = $body['name'] ?? 'Untitled Query';
        $model->user_id = $this->getCurrentUserId();
        $model->description = $body['description'] ?? null;
        $model->query_definition = json_encode($body['queryDefinition'] ?? []);
        $model->generated_sql = $body['generatedSql'] ?? null;
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
        $job->name    = $sq->name;
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
     * Body: {params: {startDate: '2025-07-01', endDate: '2026-06-30', ...}}
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

        // Validate required params
        $paramDefs = $report->getDecodedParameters();
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
        if (!empty($missing)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Missing required parameters: ' . implode(', ', $missing)];
        }

        // Bind params and get SQL
        $bound = $report->bindParams($userParams);

        // Safety validation
        try {
            SqlBuilderService::validateSafety($bound['sql']);
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => $e->getMessage()];
        }

        // Determine data source from report template
        $rawDataSource = $report->hasAttribute('data_source') ? $report->data_source : null;
        $dataSource = in_array($rawDataSource, ['folio', 'local', 'composite'])
            ? $rawDataSource
            : 'folio';

        // For composite reports, attach the composite_config as job metadata
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

        // Create async job
        $job = QueryJob::createJob($bound['sql'], $bound['params'], 'report', $dataSource, $metadata);
        $job->user_id = $this->getCurrentUserId();
        if (!$job->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to create job', 'details' => $job->errors];
        }

        Yii::$app->response->statusCode = 202;
        return [
            'jobId' => $job->id,
            'reportName' => $report->name,
            'status' => 'pending',
            'dataSource' => $dataSource,
        ];
    }

    /**
     * POST /api/reports — create a new report template.
     * Body: {slug, name, description, category, sqlTemplate, parameters, defaultLimit?, createdBy?}
     */
    public function actionReportCreate()
    {
        $body = Yii::$app->request->getBodyParams();

        $report = new ReportTemplate();
        $report->slug = $body['slug'] ?? '';
        $report->name = $body['name'] ?? '';
        $report->description = $body['description'] ?? '';
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
        $name = isset($body['name']) ? substr(trim($body['name']), 0, 255) : null;
        $job->name = $name;
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

        $query = QueryJob::find()
            ->select(['qj.*', 'u.email AS runBy'])
            ->alias('qj')
            ->leftJoin('users u', 'u.id = qj.user_id')
            ->orderBy(['qj.completed_at' => SORT_DESC, 'qj.created_at' => SORT_DESC])
            ->limit(min($limit, 100))
            ->offset($offset);

        if ($statusFilter === 'active') {
            $query->andWhere(['qj.status' => ['pending', 'pending_export', 'running']]);
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
                $canDelete = $isAdmin
                    || ($userId && isset($job['user_id']) && (int) $job['user_id'] === (int) $userId);
                return [
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

        $promptSeed = trim((string)($job->name ?? ''));
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

        $job = QueryJob::findOne((int) $id);
        if (!$job) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Job not found'];
        }

        if (!$isAdmin && (int) $job->user_id !== (int) $userId) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Forbidden'];
        }

        if (!$job->delete()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to delete job'];
        }

        return ['success' => true, 'jobId' => (int) $id];
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
}
