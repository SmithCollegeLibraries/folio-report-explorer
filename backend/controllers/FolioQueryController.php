<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\services\FolioSchemaService;
use app\services\SqlBuilderService;
use app\services\GeminiService;
use app\services\SettingsService;
use app\models\SavedQuery;
use app\models\QueryLog;
use app\models\QueryJob;
use app\models\ReportTemplate;
use app\models\User;
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
                            'user-list', 'user-approve', 'user-role', 'user-delete', 'user-notifications',
                        ],
                        'roles' => ['@'],
                        'matchCallback' => function ($rule, $action) {
                            $identity = Yii::$app->user->identity;
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
     * Get the current authenticated user's ID (null in dev mode or if not authenticated).
     * @return int|null
     */
    private function getCurrentUserId()
    {
        return Yii::$app->user->isGuest ? null : Yii::$app->user->id;
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
        $identity = Yii::$app->user->identity;
        if (!$identity || !$identity->isAdmin()) {
            Yii::$app->response->statusCode = 403;
            return false;
        }
        return true;
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

        // If a query definition is provided instead of raw SQL, build it first
        if (!$sql && isset($body['queryDefinition'])) {
            try {
                $built = SqlBuilderService::build($body['queryDefinition']);
                $sql = $built['sql'];
                $params = $built['params'];
                $source = 'builder';
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
        $log->user_id = $this->getCurrentUserId();

        try {
            $db = Yii::$app->folioDb;
            $transaction = $db->beginTransaction();

            try {
                $db->createCommand("SET TRANSACTION READ ONLY")->execute();
                $command = $db->createCommand($sql);

                foreach ($params as $key => $value) {
                    $command->bindValue($key, $value);
                }

                $rows = $command->queryAll();
                $transaction->commit();

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
                ];
            } catch (\Exception $e) {
                $transaction->rollBack();
                throw $e;
            }
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

        // Build SQL from query definition if provided
        if (!$sql && isset($body['queryDefinition'])) {
            try {
                $built = SqlBuilderService::build($body['queryDefinition']);
                $sql = $built['sql'];
                $params = $built['params'];
                $source = 'builder';
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
        } catch (\InvalidArgumentException $e) {
            Yii::$app->response->statusCode = 403;
            return ['error' => $e->getMessage()];
        }

        // Enforce LIMIT
        $maxRows = Yii::$app->params['maxQueryRows'];
        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            $sql = rtrim($sql, "; \n") . "\nLIMIT {$maxRows}";
        }

        // Create job
        $job = QueryJob::createJob($sql, $params, $source);
        $job->user_id = $this->getCurrentUserId();
        $job->sql_hash = hash('sha256', $sql . json_encode($params));
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

        if (!in_array($job->status, ['pending', 'running'])) {
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

        if (empty($prompt)) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'A "prompt" is required'];
        }

        try {
            $result = GeminiService::generateSql($prompt);
            return $result;
        } catch (\RuntimeException $e) {
            Yii::$app->response->statusCode = 500;
            return ['error' => $e->getMessage()];
        }
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
            'id' => (int)$q->id,
            'name' => $q->name,
            'description' => $q->description,
            'query_definition' => $q->query_definition ? json_decode($q->query_definition, true) : null,
            'generated_sql' => $q->generated_sql,
            'source' => $q->source ?: 'builder',
            'nl_prompt' => $q->nl_prompt,
            'is_pinned' => (bool)$q->is_pinned,
            'created_at' => $q->created_at,
            'updated_at' => $q->updated_at,
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

        $allowed = ['pg_host', 'pg_port', 'pg_db', 'pg_user', 'pg_pass', 'pg_sslmode', 'gemini_api_key', 'gemini_model'];
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
     * POST /api/settings/test — test Postgres and/or Gemini connection.
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

        // Create async job
        $job = QueryJob::createJob($bound['sql'], $bound['params'], 'report');
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
     * GET /api/query/history — list the current user's completed jobs with results.
     * Optional: ?limit=20&offset=0
     */
    public function actionQueryHistory()
    {
        $userId = $this->getCurrentUserId();
        $limit = (int) (Yii::$app->request->get('limit', 50));
        $offset = (int) (Yii::$app->request->get('offset', 0));

        $query = QueryJob::find()
            ->where(['status' => 'completed'])
            ->orderBy(['completed_at' => SORT_DESC])
            ->limit(min($limit, 100))
            ->offset($offset);

        // In production, filter to current user's jobs only
        if ($userId) {
            $query->andWhere(['user_id' => $userId]);
        }

        $total = (clone $query)->count();
        $jobs = $query->all();

        return [
            'total' => (int) $total,
            'offset' => $offset,
            'limit' => $limit,
            'items' => array_map(function ($job) {
                return [
                    'jobId' => $job->id,
                    'sql' => $job->sql_text,
                    'source' => $job->source,
                    'rowCount' => (int) $job->row_count,
                    'executionTimeMs' => (int) $job->execution_time_ms,
                    'createdAt' => $job->created_at,
                    'completedAt' => $job->completed_at,
                ];
            }, $jobs),
        ];
    }

    /**
     * OPTIONS — CORS preflight handler.
     */
    public function actionOptions()
    {
        Yii::$app->response->statusCode = 204;
        return '';
    }
}
