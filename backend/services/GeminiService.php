<?php

namespace app\services;

use Yii;
use yii\httpclient\Client;

/**
 * GeminiService — sends natural-language prompts to Google Gemini 2.5 Flash
 * with schema context, receives generated SQL, validates it, and returns.
 */
class GeminiService
{
    const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';
    const OPENAI_API_BASE = 'https://api.openai.com/v1';
    const REQUEST_TIMEOUT_SECONDS = 120;
    const DEFAULT_MAX_RETRIES = 3;
    const DEFAULT_RETRY_BASE_DELAY_MS = 400;
    const MAX_RETRY_BACKOFF_MS = 5000;
    const LEGACY_PROMPT_VERSION = 'legacy_sql_prompt.v1';
    const INTENT_PROMPT_VERSION = 'intent_json_prompt.v1';
    const FAMILY_SLOT_PROMPT_VERSION = 'family_slot_prompt.v1';
    const INDEX_RECOMMENDER_PROMPT_VERSION = 'index_recommender.v1';
    const FOLLOW_UP_SUGGESTION_PROMPT_VERSION = 'followup_suggestions.v1';
    const NL2SQL_TELEMETRY_CATEGORY = 'nl2sql.telemetry';

    /**
     * Resolve preferred AI provider from settings (gemini|openai).
     *
     * @return string
     */
    private static function getPreferredAiProvider()
    {
        $provider = strtolower(trim((string)(Yii::$app->params['aiProvider'] ?? 'gemini')));
        return $provider === 'openai' ? 'openai' : 'gemini';
    }

    /**
     * Resolve the effective AI configuration.
     * Falls back to the alternate provider when the preferred provider has no key.
     *
     * @return array{provider:string,apiKey:string,model:string}
     */
    private static function getAiConfig()
    {
        $preferredProvider = self::getPreferredAiProvider();
        $geminiApiKey = trim((string)(Yii::$app->params['geminiApiKey'] ?? ''));
        $openaiApiKey = trim((string)(Yii::$app->params['openaiApiKey'] ?? ''));

        $provider = 'none';
        $apiKey = '';

        if ($preferredProvider === 'openai') {
            if ($openaiApiKey !== '') {
                $provider = 'openai';
                $apiKey = $openaiApiKey;
            } elseif ($geminiApiKey !== '') {
                $provider = 'gemini';
                $apiKey = $geminiApiKey;
            }
        } else {
            if ($geminiApiKey !== '') {
                $provider = 'gemini';
                $apiKey = $geminiApiKey;
            } elseif ($openaiApiKey !== '') {
                $provider = 'openai';
                $apiKey = $openaiApiKey;
            }
        }

        $model = $provider === 'openai'
            ? (string)(Yii::$app->params['openaiModel'] ?? 'gpt-4.1-mini')
            : (string)(Yii::$app->params['geminiModel'] ?? 'gemini-2.5-flash');

        return [
            'provider' => $provider,
            'apiKey' => $apiKey,
            'model' => $model,
        ];
    }

    /**
     * Resolve active AI provider (gemini|openai|none).
     *
     * @return string
     */
    private static function getAiProvider()
    {
        return self::getAiConfig()['provider'];
    }

    /**
     * Resolve API key for the active provider.
     *
     * @return string
     */
    private static function getAiApiKey()
    {
        return self::getAiConfig()['apiKey'];
    }

    /**
     * Resolve default model for the active provider.
     *
     * @return string
     */
    private static function getAiModel()
    {
        return self::getAiConfig()['model'];
    }

    /**
     * Standardized message when no AI provider key is configured.
     *
     * @return string
     */
    private static function getMissingAiApiKeyMessage()
    {
        return 'AI API key not configured. Set GEMINI_API_KEY or OPENAI_API_KEY in .env.';
    }

    /**
     * Step 8 entrypoint: run the configured primary mode and optionally execute
     * the alternate mode in shadow for comparison telemetry.
     *
     * @param string $prompt
     * @param string|null $campus
     * @param int|null $userId
     * @return array {sql: string, explanation: string, dataSource: string}
     */
    public static function generateSqlWithShadow($prompt, $campus = null, $userId = null)
    {
        $primaryMode = self::resolvePrimaryMode();
        $primary = $primaryMode === 'intent'
            ? self::generateSql($prompt, $campus, false, true)
            : self::generateSql($prompt, $campus, true, false);

        if (($primary['route'] ?? null) === 'legacy_freeform' && ($primary['routeReason'] ?? '') === 'forced_legacy_mode') {
            $primary['routeReason'] = !empty(Yii::$app->params['nl2sqlForceLegacy'])
                ? 'forced_legacy_mode'
                : 'primary_legacy_mode';
        }

        if (!self::shouldRunShadowForUser($userId, $prompt)) {
            return $primary;
        }

        $shadowMode = $primaryMode === 'intent' ? 'legacy' : 'intent';

        try {
            $shadow = $shadowMode === 'intent'
                ? self::generateSql($prompt, $campus, false, true)
                : self::generateSql($prompt, $campus, true, false);

            self::logShadowComparison($primary, $shadow, [
                'primaryMode' => $primaryMode,
                'shadowMode' => $shadowMode,
                'userId' => $userId,
                'promptFingerprint' => self::fingerprintPrompt($prompt),
            ]);
        } catch (\Throwable $e) {
            self::logNlTelemetry('nl2sql.shadow_error', [
                'primaryMode' => $primaryMode,
                'shadowMode' => $shadowMode,
                'userId' => $userId,
                'promptFingerprint' => self::fingerprintPrompt($prompt),
                'error' => $e->getMessage(),
            ], true);
        }

        return $primary;
    }

    /**
     * Generate index recommendations from query-history workload snapshots.
     *
     * @param array $snapshot
     * @return array
     * @throws \RuntimeException
     */
    public static function recommendIndexesFromHistory(array $snapshot)
    {
        $apiKey = self::getAiApiKey();
        if (empty($apiKey)) {
            throw new \RuntimeException(self::getMissingAiApiKeyMessage());
        }

        $model = self::getAiModel();
        $workloadPayload = [
            'generatedAt' => $snapshot['generatedAt'] ?? null,
            'windowDays' => $snapshot['windowDays'] ?? null,
            'workload' => [
                'logsAnalyzed' => $snapshot['workload']['logsAnalyzed'] ?? 0,
                'eligibleLogs' => $snapshot['workload']['eligibleLogs'] ?? 0,
                'uniqueQueryPatterns' => $snapshot['workload']['uniqueQueryPatterns'] ?? 0,
                'tables' => $snapshot['workload']['tables'] ?? [],
                'queryPatterns' => $snapshot['workload']['queryPatterns'] ?? [],
            ],
            'existingIndexesByTable' => $snapshot['existingIndexesByTable'] ?? [],
        ];

        $promptFingerprint = substr(hash('sha256', json_encode($workloadPayload)), 0, 16);
        $systemPrompt = self::buildIndexRecommendationSystemPrompt();

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";
        $requestResult = self::sendGeminiRequestWithRetries(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [[
                            'text' => "WORKLOAD_SNAPSHOT_JSON:\n" . json_encode(
                                $workloadPayload,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                            ),
                        ]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 4096,
                    'responseMimeType' => 'application/json',
                ],
            ],
            'index_recommend.generate'
        );

        $response = $requestResult['response'];
        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        $parsed = json_decode(trim((string)$text), true);
        if (!is_array($parsed)) {
            $fragment = self::extractJsonObject((string)$text);
            if ($fragment !== null) {
                $parsed = json_decode($fragment, true);
            }
        }
        if (!is_array($parsed)) {
            throw new \RuntimeException('Model returned malformed index recommendation JSON.');
        }

        $recommendations = $parsed['recommendations'] ?? [];
        if (!is_array($recommendations)) {
            $recommendations = [];
        }

        $notes = $parsed['notes'] ?? [];
        if (!is_array($notes)) {
            $notes = [];
        }

        self::logNlTelemetry('nl2sql.index_recommendation', [
            'model' => $model,
            'promptVersion' => self::INDEX_RECOMMENDER_PROMPT_VERSION,
            'promptFingerprint' => $promptFingerprint,
            'finishReason' => $finishReason,
            'attempts' => $requestResult['attempts'] ?? null,
            'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            'recommendationCount' => count($recommendations),
            'tableCount' => count($workloadPayload['workload']['tables'] ?? []),
            'queryPatternCount' => count($workloadPayload['workload']['queryPatterns'] ?? []),
        ]);

        return [
            'summary' => trim((string)($parsed['summary'] ?? '')),
            'recommendations' => $recommendations,
            'notes' => $notes,
            'model' => $model,
            'promptVersion' => self::INDEX_RECOMMENDER_PROMPT_VERSION,
            'route' => 'index_recommender',
        ];
    }

    /**
     * Generate follow-up NL prompts that expand on the original request.
     *
     * @param string $prompt
     * @param string $sql
     * @param string $explanation
     * @param string|null $campus
     * @return array
     */
    public static function suggestFollowUpQueries($prompt, $sql, $explanation = '', $campus = null)
    {
        $apiKey = self::getAiApiKey();
        if (empty($apiKey)) {
            return [];
        }

        $model = self::getAiModel();

        $payload = [
            'prompt' => trim((string)$prompt),
            'sql' => trim((string)$sql),
            'explanation' => trim((string)$explanation),
            'campus' => $campus,
        ];

        $systemPrompt = self::buildFollowUpSuggestionPrompt();
        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $requestResult = self::sendGeminiRequestWithRetries(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [[
                            'text' => "FOLLOW_UP_INPUT_JSON:\n" . json_encode(
                                $payload,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                            ),
                        ]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 512,
                    'responseMimeType' => 'application/json',
                ],
            ],
            'nl2sql.followup_suggestions'
        );

        $response = $requestResult['response'];
        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        $parsed = json_decode(trim((string)$text), true);
        if (!is_array($parsed)) {
            $fragment = self::extractJsonObject((string)$text);
            if ($fragment !== null) {
                $parsed = json_decode($fragment, true);
            }
        }

        if (!is_array($parsed)) {
            self::logNlTelemetry('nl2sql.followup_suggestions_parse_error', [
                'model' => $model,
                'promptVersion' => self::FOLLOW_UP_SUGGESTION_PROMPT_VERSION,
                'promptFingerprint' => self::fingerprintPrompt((string)$prompt),
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ], true);

            return self::sanitizeFollowUpSuggestions(
                self::buildFallbackFollowUpSuggestions((string)$prompt, $campus),
                (string)$prompt
            );
        }

        $suggestions = $parsed['suggestions'] ?? [];
        if (!is_array($suggestions)) {
            $suggestions = [];
        }

        $suggestions = self::sanitizeFollowUpSuggestions($suggestions, (string)$prompt);
        if (count($suggestions) < 3) {
            $fallback = self::buildFallbackFollowUpSuggestions((string)$prompt, $campus);
            $suggestions = self::sanitizeFollowUpSuggestions(
                array_merge($suggestions, $fallback),
                (string)$prompt
            );
        }

        self::logNlTelemetry('nl2sql.followup_suggestions_generated', [
            'model' => $model,
            'promptVersion' => self::FOLLOW_UP_SUGGESTION_PROMPT_VERSION,
            'promptFingerprint' => self::fingerprintPrompt((string)$prompt),
            'finishReason' => $finishReason,
            'attempts' => $requestResult['attempts'] ?? null,
            'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            'suggestionCount' => count($suggestions),
        ]);

        return $suggestions;
    }

    /**
     * Build the system prompt used for workload-driven index recommendations.
     *
     * @return string
     */
    private static function buildIndexRecommendationSystemPrompt()
    {
        return <<<PROMPT
You are a PostgreSQL performance advisor for a FOLIO reporting workload.

You are given:
1) Query history workload patterns (frequency + execution time + sample SQL).
2) Existing indexes by table.

Goal:
Recommend practical NEW indexes that are most likely to reduce execution time
for the observed workload.

Rules:
1. Recommend only indexes that do not already exist (same table + leading column sequence).
2. Prioritize high-impact patterns first (frequent and/or slow queries).
3. Use realistic PostgreSQL index types: btree by default, gin/gist only when justified.
4. Avoid recommending indexes on tiny lookup/value tables unless clearly justified.
5. Prefer composite indexes when multiple columns repeatedly appear together in JOIN/WHERE predicates.
6. Keep recommendations conservative: max 10 recommendations.
7. If workload is insufficient, return an empty recommendations array and explain why in notes.

Return ONLY JSON with this exact shape:
{
  "summary": "short plain-English summary",
  "recommendations": [
    {
      "table": "schema.table",
      "columns": ["column_a", "column_b"],
      "indexType": "btree",
      "confidence": "high|medium|low",
      "reason": "why this helps",
      "evidence": {
        "patternIds": ["Q001", "Q004"],
        "estimatedImpact": "high|medium|low"
      },
      "createIndexSql": "CREATE INDEX CONCURRENTLY ..."
    }
  ],
  "notes": ["optional caveats or follow-up checks"]
}
PROMPT;
    }

        /**
         * Build the system prompt used to generate follow-up NL suggestions.
         *
         * @return string
         */
        private static function buildFollowUpSuggestionPrompt()
        {
                return <<<PROMPT
You generate short follow-up natural-language report prompts for a library analytics user.

You are given:
1) The user's original question.
2) The SQL that was generated.
3) A brief explanation.
4) Optional campus context.

Return ONLY JSON with this shape:
{
    "suggestions": [
        "prompt 1",
        "prompt 2",
        "prompt 3",
        "prompt 4"
    ]
}

Rules:
1. Provide 3 to 5 suggestions.
2. Suggestions must be user-facing prompts in plain English (not SQL).
3. Keep each suggestion concise (around 6 to 18 words).
4. Make each suggestion distinct: trend, breakdown, anomaly, comparison, or drill-down.
5. Keep scope consistent with the original domain and campus context.
6. Do not repeat the original prompt verbatim.
7. Do not include markdown or extra keys.
PROMPT;
        }

    /**
     * Generate SQL from a natural-language prompt.
     *
     * @param string $prompt User's natural language query description
     * @param bool $forceLegacy Internal control for deterministic fallback routing.
     * @param bool $forceIntent Internal control for shadow-mode intent execution.
     * @return array {sql: string, explanation: string, dataSource: string}
     * @throws \RuntimeException
     */
    public static function generateSql($prompt, $campus = null, $forceLegacy = false, $forceIntent = false)
    {
        if ($forceLegacy && $forceIntent) {
            throw new \InvalidArgumentException('Cannot force both legacy and intent generation modes.');
        }

        $apiKey = self::getAiApiKey();
        if (empty($apiKey)) {
            throw new \RuntimeException(self::getMissingAiApiKeyMessage());
        }

        $model = self::getAiModel();

        if ($forceIntent) {
            return self::generateSqlFromIntent($prompt, $campus, $apiKey, $model);
        }

        if (!$forceLegacy && self::isIntentModeEnabled()) {
            return self::generateSqlFromIntent($prompt, $campus, $apiKey, $model);
        }

        $schemaContext = FolioSchemaService::buildSchemaContext($prompt);
        $schemaTelemetry = self::buildSchemaTelemetry($schemaContext);
        $promptFingerprint = self::fingerprintPrompt($prompt);

        // Load acqUnit codes from settings.json (campus full name → 2-letter abbreviation)
        // Maintained in backend/data/settings.json under "acqUnitCodes" — configurable
        $settingsPath = Yii::getAlias('@app/data/settings.json');
        $acqUnitCodes = [];
        if (file_exists($settingsPath)) {
            $settings = json_decode(file_get_contents($settingsPath), true);
            $acqUnitCodes = $settings['acqUnitCodes'] ?? [];
        }
        // Fallback defaults if settings file is missing or key not present
        if (empty($acqUnitCodes)) {
            $acqUnitCodes = [
                'Smith College'               => 'SC',
                'Amherst College'             => 'AC',
                'Mount Holyoke College'       => 'MH',
                'University Of Massachusetts' => 'UM',
                'Hampshire College'           => 'HC',
                'Five Colleges Collections'   => 'RP',
                'National Yiddish Book Center'=> 'YB',
            ];
        }

        // Build optional campus-scope rule (injected as Rule 17 in the system prompt)
        $campusRule = '';
        if ($campus && $campus !== 'All Colleges') {
            $safe = addslashes($campus);
            $acqCode = $acqUnitCodes[$campus] ?? strtoupper(substr($campus, 0, 2));
            $campusRule = "17. CAMPUS SCOPE — MANDATORY: The user's home institution is {$campus} (acquisitions unit code: {$acqCode}). EVERY query MUST be scoped to this campus unless the user explicitly asks about all colleges or a different campus. Choose the correct join path based on the query domain:

  a) INVENTORY / CIRCULATION (items, holdings, locations, loans): Join through the location hierarchy — inventory.location__t → inventory.loclibrary__t → inventory.loccampus__t (alias: camp) — then add WHERE LOWER(camp.name) = LOWER('{$safe}').

  b) FINANCE / ACQUISITIONS (invoices, purchase orders, vouchers, expense classes, fund distributions, vendor spending): Campus scope is via the ACQUISITIONS UNIT, NOT location. The join chain is: orders.po_line__t (alias: plt) → orders.purchase_order__t__acq_unit_ids (alias: potaui) ON potaui.id = plt.purchase_order_id → orders.acquisitions_unit__t (alias: au) ON au.id = potaui.acq_unit_ids AND au.name = '{$acqCode}'. For queries starting from invoice tables, the full path is: invoice.invoice_lines__t__fund_distributions → orders.po_line__t → orders.purchase_order__t__acq_unit_ids → orders.acquisitions_unit__t. Aggregate line-level amounts (SUM of iltfd.total * iltfd.fund_distributions__value * 0.01), NOT invoice-header totals (inv.total).
  IMPORTANT: acquisitions_unit__t.name stores 2-letter abbreviation codes (SC, AC, MH, UM, HC, RP, YB) — NOT full campus names. Use au.name = '{$acqCode}' (exact string match). Never use LOWER(au.name) = LOWER('Smith College') or any full-name comparison.

  NEVER skip campus filtering for finance/acquisitions queries. Do not omit the acquisitions unit join.
  System-wide reference data (material types, instance types, fund types, fiscal years, etc.) does NOT need campus filtering.";
        }

        $systemPrompt = <<<PROMPT
You are a PostgreSQL query generator for a FOLIO library management system.
The database uses LDLite (a lightweight version of MetaDB) with schema-qualified table names.

RULES:
1. Generate ONLY SELECT queries — never INSERT, UPDATE, DELETE, DROP, or ALTER.
2. Use EXACT table and column names from the schema below — do NOT invent columns.
3. Table names are schema-qualified (e.g. inventory.item__t, circulation.loan__t).
   Always use the full schema.table form. Schema names do NOT have a "folio_" prefix.
4. Qualify column references with table aliases, not the full schema.table name.
5. Use appropriate JOINs based on the foreign key relationships shown.
6. Add a LIMIT clause (default 100) unless the user asks for a specific number.
7. Use PostgreSQL-compatible syntax.
8. LDLite tables have flattened columns (no JSON "data" blobs). Use the column names directly.
   Nested JSON fields appear as double-underscore columns (e.g. metadata__created_date, status__name).
9. Create short aliases for tables (e.g. inventory.item__t AS ii, circulation.loan__t AS cl).
10. Use the TABLE DESCRIPTIONS, DOMAIN VOCABULARY, and LOCATION NAMING SCHEMA in the schema below to resolve ambiguous terms.
    For example, "Smith College" is a CAMPUS (inventory.loccampus__t), NOT an organization/vendor.
    This is a shared Five Colleges system — library/location names are prefixed with 2-letter campus codes
    (SC=Smith, AC=Amherst, MH=Mt Holyoke, UM=UMass, HC=Hampshire, RP=Five Colleges, YB=Yiddish Book Center).
    When matching library/location names, use ILIKE with wildcards (e.g. '%Neilson%') since names are stored
    with campus prefixes (e.g. 'SC Neilson Library'). See the Location Naming Schema section for details.
    Always check the vocabulary section before choosing a table for user-mentioned entities.
11. For text/name comparisons, ALWAYS use case-insensitive matching with LOWER() on both sides
    or ILIKE operator. Never compare name columns with exact case (e.g. use LOWER(imt.name) = 'book'
    instead of imt.name = 'book'). Database values are often Title Case (e.g. 'Book', 'DVD').
12. For item location joins, ALWAYS use inventory.item__t.effective_location_id (NOT
    holdings_record__t.permanent_location_id). The effective location reflects the item's
    current/temporary location and is the correct column for circulation and item-level queries.
13. If the query references ONLY local supplementary tables (acrl_statistics, report_expense_allocations),
    generate MySQL-compatible SELECT and set DATA SOURCE to "local".
14. Otherwise set DATA SOURCE to "folio" and use PostgreSQL syntax.
15. NEVER use the PostgreSQL ? operator (JSONB key-exists). Our query layer treats ? as a bind-parameter
    placeholder and it causes a fatal syntax error. Instead use jsonb_exists(jsonb_val, 'key'),
    jsonb_typeof(), or jsonb_each(). The same applies to ?| and ?& — use jsonb_exists_any / jsonb_exists_all.
16. CRITICAL — COLUMN TYPE WARNINGS: Before writing any column expression, check the
    COLUMN TYPE WARNINGS & SAMPLE VALUES section below. Many columns that look like they should
    be JSONB are actually TEXT (stored JSON strings). Do NOT use ->, ->>, or @> on TEXT columns.
    If a column is marked as TEXT containing JSON, prefer an alternative table listed under PREFER,
    or cast explicitly with ::jsonb only as a last resort. Sample values listed for enum-like columns
    show the exact casing stored in the database — always match that casing (ILIKE or LOWER()).
18. PERFORMANCE — ORDER BY: Do NOT add ORDER BY unless the user explicitly requests sorted or
    ranked results (keywords: "top N", "highest", "lowest", "sorted by", "alphabetical").
    ORDER BY forces PostgreSQL to materialize and sort the ENTIRE result set before returning
    the first row — even with LIMIT 100 the planner must find and sort all matching rows first.
    On large datasets (10K+ rows) this adds massive overhead with no benefit to the user.
    OMIT ORDER BY for: listing queries, existence checks, missing-field reports, any general
    "show me records" query where the user did not ask for a specific order.
    KEEP ORDER BY only for: ranking queries (ORDER BY count DESC LIMIT 20), explicit top-N
    requests, or when the user specifically asks for a sorted result.
19. UUID TYPE CASTS — NEVER write ::uuid or ::text anywhere. MetaDB join columns are
    already compatible types. Explicit casts bypass PostgreSQL indexes and cause
    catastrophically slow full-table scans. Always write plain equality with no casts:
      ii.material_type_id      = imt.id
      ii.holdings_record_id    = hr.id
      hr.instance_id           = inst.id
      ii.effective_location_id = loc.id
      loc.library_id           = lib.id
      lib.campus_id            = camp.id
      cont.id                  = inst.id
      subj.id                  = inst.id
      iden.id                  = inst.id
      iden.identifiers__identifier_type_id = idt.id
    ::uuid and ::text are NEVER correct anywhere in JOIN ON conditions or WHERE clauses.
20. MONETARY / FINANCIAL COLUMNS — MANDATORY: Format ALL money amounts as USD currency.
    Finance tables store amounts as NUMERIC with many decimal places (e.g. 1548302.2100000000000000).
    ALWAYS use TO_CHAR to format as a USD dollar amount with comma separators:
      TO_CHAR(inv.total, 'FM$999,999,999,990.00')          -- column directly
      TO_CHAR(SUM(inv.amount), 'FM$999,999,999,990.00')    -- aggregate
      TO_CHAR(ROUND(SUM(inv.total), 2), 'FM$999,999,999,990.00')  -- if subquery
    Use TO_CHAR only in the outermost SELECT (not in WHERE, JOIN, or subquery filters).
    This applies to any column from finance.*, invoice.*, acq_unit.*, or any column whose name
    contains: total, amount, price, cost, spent, encumbered, expenditure, budget, balance.
    NEVER return raw unformatted monetary values to the user.
21. SINGLE STATEMENT — Return exactly one SELECT statement for the user's request.
    Never output multiple semicolon-delimited statements, even if the user asks for "also"
    or multiple follow-ups in one prompt. If needed, combine logic into one query.
{$campusRule}

SCHEMA:
{$schemaContext}

RESPONSE FORMAT:
Return exactly one SQL statement in a ```sql code block, followed by a brief plain-English explanation
of what the query does and which tables/joins are used.
Then add a final line exactly like: DATA SOURCE: folio OR DATA SOURCE: local
PROMPT;

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $requestResult = self::sendGeminiRequestWithRetries(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 16384,
                ],
            ],
            'nl2sql.generate'
        );
        $response = $requestResult['response'];

        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            self::logNlTelemetry('nl2sql.max_tokens', [
                'route' => 'legacy_freeform',
                'model' => $model,
                'promptVersion' => self::LEGACY_PROMPT_VERSION,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry, true);
            throw new \RuntimeException(
                'The AI response was truncated because the query is too complex. '
                . 'Try simplifying your request or asking for fewer fields.'
            );
        }
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        try {
            $parsed = self::parseResponse($text);
        } catch (\Throwable $e) {
            self::logValidationFailure('legacy_sql_parse', [
                'route' => 'legacy_freeform',
                'model' => $model,
                'promptVersion' => self::LEGACY_PROMPT_VERSION,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
                'error' => $e->getMessage(),
            ] + $schemaTelemetry);
            throw $e;
        }

        if (!isset($parsed['route'])) {
            $parsed['route'] = 'legacy_freeform';
        }
        if (!isset($parsed['routeReason'])) {
            $parsed['routeReason'] = $forceLegacy ? 'forced_legacy_mode' : 'intent_mode_disabled';
        }

        self::logNlTelemetry('nl2sql.generated', [
            'route' => $parsed['route'],
            'routeReason' => $parsed['routeReason'],
            'model' => $model,
            'promptVersion' => self::LEGACY_PROMPT_VERSION,
            'promptFingerprint' => $promptFingerprint,
            'finishReason' => $finishReason,
            'dataSource' => $parsed['dataSource'] ?? 'folio',
            'attempts' => $requestResult['attempts'] ?? null,
            'elapsedMs' => $requestResult['elapsedMs'] ?? null,
        ] + $schemaTelemetry);

        return $parsed;
    }

    /**
     * Feature flag gate for structured intent mode.
     */
    private static function isIntentModeEnabled()
    {
        return !empty(Yii::$app->params['nl2sqlIntentMode']);
    }

    /**
     * Generate SQL through structured QueryIntent output.
     *
     * This path is guarded by a feature flag and intentionally keeps the
     * legacy freeform SQL path unchanged when disabled.
     *
     * @param string $prompt
     * @param string|null $campus
     * @param string $apiKey
     * @param string $model
     * @return array {sql: string, explanation: string, dataSource: string}
     * @throws \RuntimeException
     */
    private static function generateSqlFromIntent($prompt, $campus, $apiKey, $model)
    {
        $schemaContext = FolioSchemaService::buildSchemaContext($prompt);
        $schemaTelemetry = self::buildSchemaTelemetry($schemaContext);
        $promptFingerprint = self::fingerprintPrompt($prompt);
        $requestContext = self::buildIntentRequestContext($prompt, $campus, $schemaContext);
        $queryFamily = $requestContext['queryFamily'];
        $systemPrompt = $requestContext['systemPrompt'];
        $promptVersion = $requestContext['promptVersion'];

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $requestResult = self::sendGeminiRequestWithRetries(
            $url,
            [
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 8192,
                    'responseMimeType' => 'application/json',
                ],
            ],
            'nl2sql.intent'
        );
        $response = $requestResult['response'];

        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            self::logNlTelemetry('nl2sql.max_tokens', [
                'route' => 'intent_json',
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry, true);
            throw new \RuntimeException(
                'The AI intent response was truncated because the query is too complex. '
                . 'Try simplifying your request or asking for fewer fields.'
            );
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        try {
            $intent = self::parseIntentResponse($text);
        } catch (\Throwable $e) {
            self::logValidationFailure('intent_json_parse', [
                'route' => 'intent_json',
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
                'error' => $e->getMessage(),
            ] + $schemaTelemetry);
            throw $e;
        }

        $familyResponse = self::maybeRouteQueryFamilyIntentResponse(
            $intent,
            $queryFamily,
            $prompt,
            $campus,
            [
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry
        );
        if ($familyResponse !== null) {
            return $familyResponse;
        }

        $validation = QueryIntentService::validateIntent($intent);
        if (empty($validation['valid'])) {
            $first = $validation['errors'][0] ?? [];
            $path = $first['path'] ?? 'intent';
            $message = $first['message'] ?? 'Unknown validation error.';
            self::logValidationFailure('intent_contract', [
                'route' => 'intent_json',
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
                'errorCount' => count($validation['errors'] ?? []),
                'firstErrorPath' => $path,
                'firstErrorMessage' => $message,
            ] + $schemaTelemetry);
            throw new \RuntimeException(
                "Model returned invalid intent JSON ({$path}): {$message}"
            );
        }

        $normalizedIntent = $validation['normalizedIntent'];

        $capability = self::classifyIntentCapability($normalizedIntent);
        if (!$capability['supported']) {
            self::logRouteSelection('legacy_fallback', $capability['reason'], $normalizedIntent);
            $fallback = self::generateSql($prompt, $campus, true);
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = $capability['reason'];
            self::logNlTelemetry('nl2sql.generated', [
                'route' => $fallback['route'],
                'routeReason' => $fallback['routeReason'],
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'dataSource' => $fallback['dataSource'] ?? 'folio',
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry);
            return $fallback;
        }

        try {
            $queryDef = QueryIntentService::toQueryDefinition($normalizedIntent);
            $built = SqlBuilderService::build($queryDef);
        } catch (QueryIntentValidationException $e) {
            self::logValidationFailure('intent_to_query_definition', [
                'route' => 'intent_json',
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
                'error' => $e->getMessage(),
            ] + $schemaTelemetry);
            throw new \RuntimeException('Intent validation failed: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $reason = 'builder_conversion_failed';
            self::logRouteSelection('legacy_fallback', $reason . ': ' . $e->getMessage(), $normalizedIntent);
            $fallback = self::generateSql($prompt, $campus, true);
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = $reason;
            self::logNlTelemetry('nl2sql.generated', [
                'route' => $fallback['route'],
                'routeReason' => $fallback['routeReason'],
                'model' => $model,
                'promptVersion' => $promptVersion,
                'promptFingerprint' => $promptFingerprint,
                'finishReason' => $finishReason,
                'dataSource' => $fallback['dataSource'] ?? 'folio',
                'attempts' => $requestResult['attempts'] ?? null,
                'elapsedMs' => $requestResult['elapsedMs'] ?? null,
            ] + $schemaTelemetry);
            return $fallback;
        }

        $sql = self::inlineParams($built['sql'] ?? '', $built['params'] ?? []);
        $sql = self::normalizeIdCasts($sql);

        SqlBuilderService::validateSafety($sql);
        SqlBuilderService::validateTablePolicy($sql);
        self::validateTableReferences($sql);

        $dataSource = 'folio';
        if (preg_match('/\b(acrl_statistics|report_expense_allocations)\b/i', $sql)) {
            $dataSource = 'local';
        }

        $tables = $normalizedIntent['query']['tables'] ?? [];
        $explanation = 'Generated from structured intent mode.';
        if (!empty($tables)) {
            $explanation .= ' Tables: ' . implode(', ', $tables) . '.';
        }

        self::logRouteSelection('builder_intent', 'intent_supported', $normalizedIntent);

        self::logNlTelemetry('nl2sql.generated', [
            'route' => 'builder_intent',
            'routeReason' => 'intent_supported',
            'model' => $model,
            'promptVersion' => $promptVersion,
            'promptFingerprint' => $promptFingerprint,
            'finishReason' => $finishReason,
            'dataSource' => $dataSource,
            'attempts' => $requestResult['attempts'] ?? null,
            'elapsedMs' => $requestResult['elapsedMs'] ?? null,
        ] + $schemaTelemetry);

        return [
            'sql' => $sql,
            'explanation' => $explanation,
            'dataSource' => $dataSource,
            'route' => 'builder_intent',
            'routeReason' => 'intent_supported',
        ];
    }

    private static function buildIntentRequestContext($prompt, $campus, string $schemaContext): array
    {
        $queryFamily = self::resolvePromptQueryFamily($prompt, $campus);
        $systemPrompt = $queryFamily !== null
            ? self::buildQueryFamilySlotSystemPrompt($queryFamily['familyKey'], $campus)
            : self::buildIntentSystemPrompt($schemaContext, $campus);
        $promptVersion = $queryFamily !== null
            ? self::FAMILY_SLOT_PROMPT_VERSION
            : self::INTENT_PROMPT_VERSION;

        return [
            'queryFamily' => $queryFamily,
            'systemPrompt' => $systemPrompt,
            'promptVersion' => $promptVersion,
        ];
    }

    private static function maybeRouteQueryFamilyIntentResponse(
        array $intent,
        ?array $queryFamily,
        $prompt,
        $campus,
        array $telemetryContext,
        $familyResponseBuilder = null,
        $legacyFallbackFactory = null
    ): ?array {
        if ($queryFamily === null) {
            return null;
        }

        $expectedFamilyKey = trim((string)($queryFamily['familyKey'] ?? ''));
        $returnedFamilyKey = trim((string)($intent['familyKey'] ?? ''));
        if ($expectedFamilyKey !== '' && $returnedFamilyKey !== $expectedFamilyKey) {
            if ($legacyFallbackFactory === null) {
                $legacyFallbackFactory = function () use ($prompt, $campus): array {
                    return self::generateSql($prompt, $campus, true);
                };
            }

            $reason = 'family_contract_mismatch';
            self::logValidationFailure('family_contract_mismatch', [
                'route' => 'intent_json',
                'model' => $telemetryContext['model'] ?? null,
                'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                'finishReason' => $telemetryContext['finishReason'] ?? null,
                'attempts' => $telemetryContext['attempts'] ?? null,
                'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
                'expectedFamilyKey' => $expectedFamilyKey,
                'returnedFamilyKey' => $returnedFamilyKey === '' ? null : $returnedFamilyKey,
            ] + $telemetryContext);
            self::logRouteSelection('legacy_fallback', $reason . ':' . $expectedFamilyKey, [
                'query' => [],
            ]);

            $fallback = $legacyFallbackFactory();
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = $reason;
            self::logNlTelemetry('nl2sql.generated', [
                'route' => $fallback['route'],
                'routeReason' => $fallback['routeReason'],
                'model' => $telemetryContext['model'] ?? null,
                'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                'finishReason' => $telemetryContext['finishReason'] ?? null,
                'dataSource' => $fallback['dataSource'] ?? 'folio',
                'attempts' => $telemetryContext['attempts'] ?? null,
                'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
                'expectedFamilyKey' => $expectedFamilyKey,
                'returnedFamilyKey' => $returnedFamilyKey === '' ? null : $returnedFamilyKey,
            ] + $telemetryContext);
            return $fallback;
        }

        if ($familyResponseBuilder === null) {
            $familyResponseBuilder = function (
                array $intent,
                array $queryFamily,
                $prompt,
                $campus,
                array $telemetryContext
            ): array {
                return self::buildQueryFamilyIntentResponse(
                    $intent,
                    $queryFamily,
                    $prompt,
                    $campus,
                    $telemetryContext
                );
            };
        }

        return $familyResponseBuilder(
            $intent,
            $queryFamily,
            $prompt,
            $campus,
            $telemetryContext
        );
    }

    /**
     * Deterministic capability classifier for builder support.
     *
     * @param array $normalizedIntent
     * @return array {supported: bool, reason: string}
     */
    private static function classifyIntentCapability(array $normalizedIntent)
    {
        $query = $normalizedIntent['query'] ?? [];
        $tables = $query['tables'] ?? [];
        $joins = $query['joins'] ?? 'auto';

        // Phase 1 router keeps explicit joins on the fallback path.
        if (is_array($joins) && !empty($joins)) {
            return [
                'supported' => false,
                'reason' => 'explicit_joins_unsupported_in_builder_route',
            ];
        }

        // Cap very large multi-table plans for deterministic builder routing.
        if (is_array($tables) && count($tables) > 6) {
            return [
                'supported' => false,
                'reason' => 'too_many_tables_for_builder_route',
            ];
        }

        return [
            'supported' => true,
            'reason' => 'intent_supported',
        ];
    }

    /**
     * Record selected route for observability.
     *
     * @param string $route
     * @param string $reason
     * @param array $normalizedIntent
     */
    private static function logRouteSelection($route, $reason, array $normalizedIntent)
    {
        $query = $normalizedIntent['query'] ?? [];
        $payload = [
            'route' => $route,
            'reason' => $reason,
            'tables' => $query['tables'] ?? [],
            'selectCount' => count($query['select'] ?? []),
            'whereCount' => count($query['where'] ?? []),
            'hasExplicitJoins' => is_array($query['joins'] ?? null),
            'intentVersion' => $normalizedIntent['intentVersion'] ?? null,
        ];

        Yii::info('NL2SQL route: ' . json_encode($payload), 'nl2sql.routing');
    }

    /**
     * Build deterministic schema telemetry fields from the prompt context payload.
     */
    private static function buildSchemaTelemetry($schemaContext)
    {
        $metadata = FolioSchemaService::getMetadata();

        return [
            'schemaContextHash' => substr(hash('sha256', (string)$schemaContext), 0, 16),
            'schemaContextBytes' => strlen((string)$schemaContext),
            'schemaVersion' => $metadata['scraped_at'] ?? null,
        ];
    }

    /**
     * Create a stable prompt fingerprint for telemetry without logging prompt text.
     */
    private static function fingerprintPrompt($prompt)
    {
        return substr(hash('sha256', trim((string)$prompt)), 0, 16);
    }

    /**
     * Structured NL2SQL telemetry logging.
     */
    private static function logNlTelemetry($event, array $payload, $warning = false)
    {
        $record = array_merge([
            'event' => (string)$event,
            'timestamp' => gmdate('c'),
        ], $payload);

        $message = 'NL2SQL telemetry: ' . json_encode($record);
        if ($warning) {
            Yii::warning($message, self::NL2SQL_TELEMETRY_CATEGORY);
            return;
        }

        Yii::info($message, self::NL2SQL_TELEMETRY_CATEGORY);
    }

    /**
     * Emit structured validation-failure telemetry.
     */
    private static function logValidationFailure($stage, array $payload)
    {
        self::logNlTelemetry('nl2sql.validation_failure', array_merge([
            'stage' => (string)$stage,
        ], $payload), true);
    }

    /**
     * Resolve the primary NL2SQL mode for user-facing responses.
     */
    private static function resolvePrimaryMode()
    {
        if (!empty(Yii::$app->params['nl2sqlForceLegacy'])) {
            return 'legacy';
        }

        $configured = strtolower((string)(Yii::$app->params['nl2sqlPrimaryMode'] ?? ''));
        if ($configured === 'intent' || $configured === 'legacy') {
            return $configured;
        }

        return self::isIntentModeEnabled() ? 'intent' : 'legacy';
    }

    /**
     * Determine if the current user/prompt should run shadow comparison.
     */
    private static function shouldRunShadowForUser($userId, $prompt)
    {
        if (empty(Yii::$app->params['nl2sqlShadowMode'])) {
            return false;
        }

        if (!self::isShadowUserAllowed($userId)) {
            return false;
        }

        $percent = (int)(Yii::$app->params['nl2sqlShadowSamplePercent'] ?? 100);
        $percent = max(0, min(100, $percent));
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        $seed = (string)$userId . '|' . self::fingerprintPrompt((string)$prompt);
        $hash = hash('sha256', $seed);
        $bucket = hexdec(substr($hash, 0, 8)) % 100;
        return $bucket < $percent;
    }

    /**
     * Check user cohort allowlist for shadow-mode execution.
     */
    private static function isShadowUserAllowed($userId)
    {
        $raw = trim((string)(Yii::$app->params['nl2sqlShadowUsers'] ?? ''));
        if ($raw === '') {
            return false;
        }

        $normalized = strtolower($raw);
        if ($normalized === '*' || $normalized === 'all') {
            return true;
        }

        if ($userId === null) {
            return false;
        }

        $allowed = array_filter(array_map('trim', explode(',', $raw)), function ($value) {
            return $value !== '';
        });

        return in_array((string)$userId, $allowed, true);
    }

    /**
     * Normalize SQL text for stable hash comparisons.
     */
    private static function normalizeSqlForHash($sql)
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim((string)$sql)));
        return trim((string)$normalized);
    }

    /**
     * Log shadow comparison metrics without affecting the primary response.
     */
    private static function logShadowComparison(array $primary, array $shadow, array $context)
    {
        $primarySql = $primary['sql'] ?? '';
        $shadowSql = $shadow['sql'] ?? '';

        $primaryHash = $primarySql !== ''
            ? substr(hash('sha256', self::normalizeSqlForHash($primarySql)), 0, 16)
            : null;
        $shadowHash = $shadowSql !== ''
            ? substr(hash('sha256', self::normalizeSqlForHash($shadowSql)), 0, 16)
            : null;

        self::logNlTelemetry('nl2sql.shadow_compare', array_merge($context, [
            'primaryRoute' => $primary['route'] ?? null,
            'primaryRouteReason' => $primary['routeReason'] ?? null,
            'shadowRoute' => $shadow['route'] ?? null,
            'shadowRouteReason' => $shadow['routeReason'] ?? null,
            'primaryDataSource' => $primary['dataSource'] ?? null,
            'shadowDataSource' => $shadow['dataSource'] ?? null,
            'primarySqlHash' => $primaryHash,
            'shadowSqlHash' => $shadowHash,
            'sqlHashMatch' => $primaryHash !== null && $shadowHash !== null
                ? $primaryHash === $shadowHash
                : null,
            'primarySqlLength' => strlen((string)$primarySql),
            'shadowSqlLength' => strlen((string)$shadowSql),
        ]));
    }

    /**
     * Send Gemini API requests with deterministic retry policy for transient failures.
     *
     * @param string $url
     * @param array $payload
     * @param string $metricContext
     * @return array {response: mixed, attempts: int, elapsedMs: int}
     * @throws \RuntimeException
     */
    private static function sendGeminiRequestWithRetries($url, array $payload, $metricContext)
    {
        $provider = self::getAiProvider();
        $apiKey = self::getAiApiKey();

        if (empty($apiKey)) {
            throw new \RuntimeException(self::getMissingAiApiKeyMessage());
        }

        $maxRetries = (int)(Yii::$app->params['geminiMaxRetries'] ?? self::DEFAULT_MAX_RETRIES);
        if ($maxRetries < 1) {
            $maxRetries = 1;
        }

        $baseDelayMs = (int)(Yii::$app->params['geminiRetryBaseDelayMs'] ?? self::DEFAULT_RETRY_BASE_DELAY_MS);
        if ($baseDelayMs < 1) {
            $baseDelayMs = self::DEFAULT_RETRY_BASE_DELAY_MS;
        }

        $attempt = 0;
        $startedAt = microtime(true);

        while (true) {
            $attempt++;

            try {
                $client = new Client();
                $client->transport = 'yii\\httpclient\\CurlTransport';

                $requestUrl = $url;
                $requestPayload = $payload;
                $headers = ['Content-Type' => 'application/json'];

                if ($provider === 'openai') {
                    $requestUrl = self::OPENAI_API_BASE . '/chat/completions';
                    $requestPayload = self::buildOpenAiPayloadFromGeminiShape($payload);
                    $headers['Authorization'] = 'Bearer ' . $apiKey;
                }

                $response = $client->createRequest()
                    ->setMethod('POST')
                    ->setUrl($requestUrl)
                    ->setHeaders($headers)
                    ->addOptions([CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS])
                    ->setContent(json_encode($requestPayload))
                    ->send();

                if ($response->isOk) {
                    $normalizedResponse = $provider === 'openai'
                        ? self::normalizeOpenAiResponseToGeminiShape($response)
                        : $response;

                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    self::logRequestOutcome($metricContext, $attempt, $elapsedMs);
                    return [
                        'response' => $normalizedResponse,
                        'attempts' => $attempt,
                        'elapsedMs' => $elapsedMs,
                    ];
                }

                $statusCode = (int)$response->statusCode;
                $errorMessage = self::extractGeminiErrorMessage($response);
                $retryable = self::isRetryableGeminiResponse($statusCode, $errorMessage);

                if (!$retryable || $attempt >= $maxRetries) {
                    // For Gemini quota exhaustion, transparently fall back to OpenAI
                    // when an OpenAI key is configured and the primary provider is Gemini.
                    if (
                        $provider === 'gemini' &&
                        self::isQuotaExhaustedResponse($statusCode, $errorMessage) &&
                        !empty((string)(Yii::$app->params['openaiApiKey'] ?? ''))
                    ) {
                        return self::sendOpenAiFallbackRequest($payload, $metricContext, $startedAt);
                    }

                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    self::logRequestOutcome($metricContext, $attempt, $elapsedMs, false, $statusCode, $errorMessage);
                    throw new \RuntimeException('AI API error: ' . $errorMessage);
                }

                self::logRetryAttempt($metricContext, $attempt, $maxRetries, $statusCode, $errorMessage, false);
                self::sleepWithBackoff($attempt, $baseDelayMs);
            } catch (\Throwable $e) {
                if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'AI API error:') === 0) {
                    throw $e;
                }

                $timedOut = self::isTimeoutThrowable($e);
                $retryable = self::isRetryableThrowable($e);

                if (!$retryable || $attempt >= $maxRetries) {
                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    self::logRequestOutcome($metricContext, $attempt, $elapsedMs, $timedOut, null, $e->getMessage());
                    throw new \RuntimeException('AI request failed: ' . $e->getMessage(), 0, $e);
                }

                self::logRetryAttempt($metricContext, $attempt, $maxRetries, null, $e->getMessage(), $timedOut);
                self::sleepWithBackoff($attempt, $baseDelayMs);
            }
        }
    }

    /**
     * Convert existing Gemini-style payload shape into OpenAI chat payload.
     *
     * @param array $payload
     * @return array
     */
    private static function buildOpenAiPayloadFromGeminiShape(array $payload)
    {
        $messages = [];

        $systemParts = $payload['system_instruction']['parts'] ?? [];
        $systemText = [];
        foreach ($systemParts as $part) {
            if (is_array($part) && isset($part['text'])) {
                $systemText[] = (string)$part['text'];
            }
        }
        $systemMessage = trim(implode("\n\n", $systemText));
        if ($systemMessage !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $systemMessage,
            ];
        }

        foreach (($payload['contents'] ?? []) as $content) {
            $parts = $content['parts'] ?? [];
            $texts = [];
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $texts[] = (string)$part['text'];
                }
            }

            $message = trim(implode("\n\n", $texts));
            if ($message !== '') {
                $messages[] = [
                    'role' => 'user',
                    'content' => $message,
                ];
            }
        }

        if (empty($messages)) {
            $messages[] = [
                'role' => 'user',
                'content' => '',
            ];
        }

        $generationConfig = $payload['generationConfig'] ?? [];
        $openAiPayload = [
            'model' => (string)(Yii::$app->params['openaiModel'] ?? 'gpt-4.1-mini'),
            'messages' => $messages,
            'temperature' => isset($generationConfig['temperature'])
                ? (float)$generationConfig['temperature']
                : 0.1,
            'max_tokens' => isset($generationConfig['maxOutputTokens'])
                ? (int)$generationConfig['maxOutputTokens']
                : 4096,
        ];

        if (($generationConfig['responseMimeType'] ?? '') === 'application/json') {
            $openAiPayload['response_format'] = ['type' => 'json_object'];
        }

        return $openAiPayload;
    }

    /**
     * Normalize OpenAI response shape into the Gemini-like structure expected by parsers.
     *
     * @param mixed $response
     * @return object
     */
    private static function normalizeOpenAiResponseToGeminiShape($response)
    {
        $decoded = [];
        if (!empty($response->content)) {
            $decoded = json_decode((string)$response->content, true);
        }

        $choice = $decoded['choices'][0] ?? [];
        $finishReason = strtoupper((string)($choice['finish_reason'] ?? ''));
        if ($finishReason === 'LENGTH') {
            $finishReason = 'MAX_TOKENS';
        }

        $messageContent = $choice['message']['content'] ?? '';
        if (is_array($messageContent)) {
            $parts = [];
            foreach ($messageContent as $segment) {
                if (is_array($segment) && ($segment['type'] ?? '') === 'text') {
                    $parts[] = (string)($segment['text'] ?? '');
                }
            }
            $messageContent = implode('', $parts);
        }

        $geminiLike = [
            'candidates' => [[
                'finishReason' => $finishReason,
                'content' => [
                    'parts' => [[
                        'text' => (string)$messageContent,
                    ]],
                ],
            ]],
        ];

        $normalized = new \stdClass();
        $normalized->content = json_encode($geminiLike);
        return $normalized;
    }

    /**
     * Extract a normalized Gemini API error message from an HTTP response.
     */
    private static function extractGeminiErrorMessage($response)
    {
        $error = null;

        if (!empty($response->content)) {
            $decoded = json_decode($response->content, true);
            if (is_array($decoded)) {
                $error = $decoded['error']['message'] ?? null;
            }
        }

        if (empty($error) && is_array($response->data ?? null)) {
            $error = $response->data['error']['message'] ?? null;
        }

        if (!empty($error)) {
            return (string)$error;
        }

        $statusCode = (int)($response->statusCode ?? 0);
        return $statusCode > 0
            ? "Unknown Gemini API error (HTTP {$statusCode})"
            : 'Unknown Gemini API error';
    }

    /**
     * Retry only transient HTTP failures.
     */
    private static function isRetryableGeminiResponse($statusCode, $errorMessage)
    {
        if (in_array((int)$statusCode, [408, 500, 502, 503, 504], true)) {
            return true;
        }

        if ((int)$statusCode === 429) {
            // Retry rate-limit spikes, but do not retry hard quota/billing failures.
            return !preg_match('/quota|billing|exceeded/i', (string)$errorMessage);
        }

        return preg_match(
            '/temporar(?:y|ily)|unavailable|timeout|timed out|deadline exceeded|resource exhausted|backend error|try again/i',
            (string)$errorMessage
        ) === 1;
    }

    /**
     * Determine if a thrown exception indicates a timeout condition.
     */
    private static function isTimeoutThrowable(\Throwable $e)
    {
        return preg_match('/timeout|timed out|deadline exceeded|operation timed out/i', $e->getMessage()) === 1;
    }

    /**
     * Retry only transient transport/availability exceptions.
     */
    private static function isRetryableThrowable(\Throwable $e)
    {
        $message = $e->getMessage();

        if (self::isTimeoutThrowable($e)) {
            return true;
        }

        return preg_match(
            '/temporar(?:y|ily)|unavailable|connection reset|connection refused|failed to connect|network is unreachable|could not resolve host|ssl|try again/i',
            $message
        ) === 1;
    }

    /**
     * Returns true when a Gemini response signals hard quota/billing exhaustion
     * — errors that will not resolve on retry and warrant a provider fallback.
     */
    private static function isQuotaExhaustedResponse($statusCode, $errorMessage)
    {
        $msg = (string)$errorMessage;

        // Hard 429 with quota/billing language (Gemini REST API)
        if ((int)$statusCode === 429 && preg_match('/quota|billing|exceeded/i', $msg)) {
            return true;
        }

        // RESOURCE_EXHAUSTED gRPC status that may surface via any HTTP code
        if (preg_match('/RESOURCE_EXHAUSTED|quota exceeded|free tier.*limit|daily.*limit|monthly.*limit/i', $msg)) {
            return true;
        }

        return false;
    }

    /**
     * Perform a single OpenAI request as a transparent fallback when Gemini
     * quota is exhausted. Re-uses the same Gemini-shape payload and metric
     * context so callers require no changes.
     *
     * @param array  $payload       Gemini-shape payload (will be translated internally)
     * @param string $metricContext Logging context string
     * @param float  $startedAt     microtime(true) from the original request
     * @return array {response, attempts, elapsedMs, providerFallback}
     * @throws \RuntimeException
     */
    private static function sendOpenAiFallbackRequest(array $payload, $metricContext, $startedAt)
    {
        $apiKey = (string)(Yii::$app->params['openaiApiKey'] ?? '');

        Yii::warning(
            'Gemini quota exhausted — falling back to OpenAI for this request.',
            'nl2sql.provider_fallback'
        );

        try {
            $client = new Client();
            $client->transport = 'yii\\httpclient\\CurlTransport';

            $response = $client->createRequest()
                ->setMethod('POST')
                ->setUrl(self::OPENAI_API_BASE . '/chat/completions')
                ->setHeaders([
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ])
                ->addOptions([CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS])
                ->setContent(json_encode(self::buildOpenAiPayloadFromGeminiShape($payload)))
                ->send();

            $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

            if ($response->isOk) {
                self::logRequestOutcome($metricContext . '.openai_fallback', 1, $elapsedMs);
                return [
                    'response'       => self::normalizeOpenAiResponseToGeminiShape($response),
                    'attempts'       => 1,
                    'elapsedMs'      => $elapsedMs,
                    'providerFallback' => 'openai',
                ];
            }

            $statusCode   = (int)$response->statusCode;
            $errorMessage = self::extractGeminiErrorMessage($response);
            self::logRequestOutcome($metricContext . '.openai_fallback', 1, $elapsedMs, false, $statusCode, $errorMessage);
            throw new \RuntimeException('OpenAI fallback failed: ' . $errorMessage);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenAI fallback request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Exponential backoff with jitter for retry pacing.
     */
    private static function sleepWithBackoff($attempt, $baseDelayMs)
    {
        $expDelayMs = (int)($baseDelayMs * pow(2, max(0, (int)$attempt - 1)));
        $jitterMs = random_int(0, 200);
        $delayMs = min(self::MAX_RETRY_BACKOFF_MS, $expDelayMs + $jitterMs);
        usleep($delayMs * 1000);
    }

    /**
     * Emit per-attempt retry telemetry.
     */
    private static function logRetryAttempt($context, $attempt, $maxRetries, $statusCode, $errorMessage, $timedOut)
    {
        $payload = [
            'context' => $context,
            'attempt' => (int)$attempt,
            'maxRetries' => (int)$maxRetries,
            'statusCode' => $statusCode,
            'timedOut' => (bool)$timedOut,
            'error' => (string)$errorMessage,
        ];

        Yii::warning('Gemini retry attempt: ' . json_encode($payload), 'nl2sql.retry');
    }

    /**
     * Emit terminal request metrics for success or final failure.
     */
    private static function logRequestOutcome($context, $attempts, $elapsedMs, $timedOut = false, $statusCode = null, $errorMessage = null)
    {
        $payload = [
            'context' => $context,
            'attempts' => (int)$attempts,
            'elapsedMs' => (int)$elapsedMs,
            'timedOut' => (bool)$timedOut,
            'statusCode' => $statusCode,
            'error' => $errorMessage,
        ];

        if (!empty($errorMessage)) {
            Yii::warning('Gemini request failed: ' . json_encode($payload), 'nl2sql.retry');
            return;
        }

        Yii::info('Gemini request success: ' . json_encode($payload), 'nl2sql.retry');
    }

    /**
     * Build the system prompt for structured QueryIntent generation.
     */
    private static function buildIntentSystemPrompt($schemaContext, $campus)
    {
        $campusRule = '';
        if ($campus && $campus !== 'All Colleges') {
            $safeCampus = str_replace("'", "''", (string)$campus);
            $campusRule = <<<RULE

CAMPUS SCOPE REQUIREMENT:
- The user's home institution is '{$safeCampus}'.
- Unless the prompt explicitly asks for all colleges or a different campus, include a campus filter in query.where.
- For inventory/circulation entities, campus is represented through location campus names.
- For finance/acquisitions entities, campus is represented through acquisitions unit codes.
RULE;
        }

        return <<<PROMPT
You are a deterministic QueryIntent planner for a FOLIO PostgreSQL dataset.

Return ONLY a JSON object matching this contract:
{
  "intentVersion": 1,
  "query": {
        "tables": ["inventory_items"],
    "select": [
            {"table": "inventory_items", "column": "id", "alias": "optional_alias", "aggregate": "COUNT|SUM|AVG|MIN|MAX"}
    ],
    "where": [
            {"table": "inventory_items", "column": "barcode", "op": "=|!=|<>|>|<|>=|<=|LIKE|ILIKE|NOT LIKE|IN|NOT IN|IS NULL|IS NOT NULL|BETWEEN", "value": "literal or array"}
    ],
    "joins": "auto",
        "groupBy": [{"table": "inventory_items", "column": "material_type_id"}],
        "having": [{"aggregate": "COUNT|SUM|AVG|MIN|MAX", "table": "inventory_items", "column": "id", "op": "=|!=|>|<|>=|<=", "value": "literal"}],
        "sort": [{"table": "inventory_items", "column": "id", "direction": "ASC|DESC"}],
    "distinct": false,
    "limit": 100
  }
}

Rules:
1. Use ONLY table and column names present in SCHEMA below.
2. Use table identifiers from SCHEMA keys (for example: inventory_items, circulation_loans).
3. Do NOT use schema-qualified SQL names like inventory.item__t in the JSON contract.
4. Generate SELECT-only intent; no DDL/DML behavior.
5. Keep joins as "auto" unless an explicit join structure is required.
6. Use limit <= 1000. Default to 100 if unsure.
7. Prefer case-insensitive matching for name/text filters via ILIKE or LOWER semantics.
8. Do not include markdown, code fences, or commentary.
9. Return exactly one query object (one SQL statement intent), never multiple alternatives.
{$campusRule}

SCHEMA:
{$schemaContext}
PROMPT;
    }

    /**
     * Parse and validate raw model output into an intent array.
     */
    private static function parseIntentResponse($text)
    {
        $clean = trim((string)$text);
        if ($clean === '') {
            throw new \RuntimeException('Model returned an empty structured intent response.');
        }

        // Be tolerant if the model still wraps JSON in markdown.
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $clean = trim($clean);

        $intent = json_decode($clean, true);
        if (is_array($intent)) {
            return $intent;
        }

        $fragment = self::extractJsonObject($clean);
        if ($fragment !== null) {
            $intent = json_decode($fragment, true);
            if (is_array($intent)) {
                return $intent;
            }
        }

        throw new \RuntimeException(
            'Model returned malformed intent JSON. Unable to parse structured response.'
        );
    }

    /**
     * Extract the first balanced JSON object from arbitrary text.
     *
     * @param string $text
     * @return string|null
     */
    private static function extractJsonObject($text)
    {
        $len = strlen($text);
        $start = -1;
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }

            if ($ch === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($ch === '}') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0 && $start >= 0) {
                        return substr($text, $start, $i - $start + 1);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Normalize and filter follow-up prompt suggestions.
     *
     * @param array $suggestions
     * @param string $originalPrompt
     * @return array
     */
    private static function sanitizeFollowUpSuggestions(array $suggestions, $originalPrompt)
    {
        $original = strtolower(trim((string)$originalPrompt));
        $seen = [];
        $final = [];

        foreach ($suggestions as $candidate) {
            $text = trim((string)$candidate);
            if ($text === '') {
                continue;
            }

            $text = preg_replace('/\s+/', ' ', $text);
            if ($text === '') {
                continue;
            }

            $normalized = strtolower($text);
            if ($normalized === $original) {
                continue;
            }
            if (isset($seen[$normalized])) {
                continue;
            }
            if (strlen($text) < 10 || strlen($text) > 180) {
                continue;
            }

            $seen[$normalized] = true;
            $final[] = $text;

            if (count($final) >= 5) {
                break;
            }
        }

        return $final;
    }

    /**
     * Deterministic fallback suggestions when model output is weak/unavailable.
     *
     * @param string $prompt
     * @param string|null $campus
     * @return array
     */
    private static function buildFallbackFollowUpSuggestions($prompt, $campus = null)
    {
        $promptLower = strtolower(trim((string)$prompt));
        $scopeSuffix = '';
        if (!empty($campus) && $campus !== 'All Colleges') {
            $scopeSuffix = ' for ' . trim((string)$campus);
        }

        $generic = [
            'Break this result down by month over the last 12 months' . $scopeSuffix,
            'Show the top 10 categories contributing the most to this result' . $scopeSuffix,
            'Compare this metric across campuses and highlight differences',
            'List records that are missing key fields related to this query',
            'Show year-over-year trend changes for this metric' . $scopeSuffix,
        ];

        $finance = [
            'Show this spending trend by fiscal year' . $scopeSuffix,
            'Which vendors account for the highest share of this spending' . $scopeSuffix,
            'Break this amount down by fund and expense class' . $scopeSuffix,
            'Compare encumbered versus expended amounts for the same scope',
        ];

        $circulation = [
            'Show this circulation metric by material type' . $scopeSuffix,
            'Which locations have the highest and lowest circulation for this scope',
            'Break this down by patron group and loan type' . $scopeSuffix,
            'Show monthly circulation trend and identify peak periods' . $scopeSuffix,
        ];

        $inventory = [
            'Break this inventory count down by library and location' . $scopeSuffix,
            'Show item age distribution for this result set',
            'Which call number ranges are most represented in this scope',
            'Show records added in the last 90 days for this same criteria',
        ];

        if (preg_match('/spent|spend|budget|invoice|encumber|expend|vendor|fund|fiscal/', $promptLower)) {
            return array_merge($finance, $generic);
        }

        if (preg_match('/loan|checkout|circulation|renew|return/', $promptLower)) {
            return array_merge($circulation, $generic);
        }

        if (preg_match('/item|holdings|instance|location|call number|inventory|material type/', $promptLower)) {
            return array_merge($inventory, $generic);
        }

        return $generic;
    }

    /**
     * Inline SqlBuilder-style bind parameters into the SQL string so the
     * existing NL execution flow can continue to submit raw SQL.
     *
     * @param string $sql
     * @param array $params
     * @return string
     */
    private static function inlineParams($sql, array $params)
    {
        if (empty($params)) {
            return $sql;
        }

        uksort($params, function ($a, $b) {
            return strlen((string)$b) <=> strlen((string)$a);
        });

        foreach ($params as $name => $value) {
            $sql = str_replace((string)$name, self::toSqlLiteral($value), $sql);
        }

        return $sql;
    }

    private static function buildQueryFamilyIntentResponse(
        array $intent,
        array $queryFamily,
        $prompt,
        $campus,
        array $telemetryContext,
        $familyResultBuilder = null
    ): array {
        $intent = self::recoverPromptScopedFamilySlots(
            $intent,
            (string)$prompt,
            $campus
        );

        $slotValidation = QueryFamilySlotService::validateFamilyPayload($intent, [
            'campus' => $campus,
        ]);
        if (empty($slotValidation['valid'])) {
            $clarification = self::buildFamilySlotClarificationResponse(
                $slotValidation['errors'] ?? [],
                $intent,
                $telemetryContext
            );
            if ($clarification !== null) {
                return $clarification;
            }

            $first = $slotValidation['errors'][0] ?? [];
            $path = $first['path'] ?? 'slots';
            $message = $first['message'] ?? 'Unknown validation error.';
            self::logValidationFailure('family_slot_contract', [
                'route' => 'intent_json',
                'model' => $telemetryContext['model'] ?? null,
                'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                'finishReason' => $telemetryContext['finishReason'] ?? null,
                'attempts' => $telemetryContext['attempts'] ?? null,
                'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
                'errorCount' => count($slotValidation['errors'] ?? []),
                'firstErrorPath' => $path,
                'firstErrorMessage' => $message,
            ] + $telemetryContext);
            throw new \RuntimeException(
                "Model returned invalid family slot JSON ({$path}): {$message}"
            );
        }

        $normalizedPayload = QueryFamilySlotService::applyPromptMatchPolicy(
            $slotValidation['normalizedPayload'],
            (string)$prompt,
            $campus
        );
        $normalizedPayload = self::normalizeQueryFamilyPayload(
            $normalizedPayload,
            (string)$prompt,
            $campus
        );

        $routeReason = 'family_contract_supported:'
            . ($normalizedPayload['familyKey'] ?? $queryFamily['familyKey'] ?? '');

        if ($familyResultBuilder === null) {
            $familyResultBuilder = function (
                array $normalizedPayload,
                string $familyRouteReason,
                $requestPrompt,
                $requestCampus,
                array $requestTelemetry
            ): array {
                return self::buildCompiledQueryFamilyOrLegacyFallback(
                    $normalizedPayload,
                    $familyRouteReason,
                    $requestPrompt,
                    $requestCampus,
                    $requestTelemetry
                );
            };
        }

        $compiledFamily = $familyResultBuilder(
            $normalizedPayload,
            $routeReason,
            $prompt,
            $campus,
            $telemetryContext
        );

        if (($compiledFamily['route'] ?? null) === 'legacy_fallback') {
            return $compiledFamily;
        }

        self::logRouteSelection('builder_intent', $routeReason, [
            'intentVersion' => QueryIntentService::CONTRACT_VERSION,
            'query' => [
                'tables' => $compiledFamily['queryDefinition']['tables'] ?? [],
                'select' => $compiledFamily['queryDefinition']['columns'] ?? [],
                'where' => $compiledFamily['queryDefinition']['filters'] ?? [],
                'joins' => $compiledFamily['queryDefinition']['joins'] ?? [],
            ],
        ]);
        self::logNlTelemetry('nl2sql.generated', [
            'route' => 'builder_intent',
            'routeReason' => $routeReason,
            'model' => $telemetryContext['model'] ?? null,
            'promptVersion' => $telemetryContext['promptVersion'] ?? null,
            'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
            'finishReason' => $telemetryContext['finishReason'] ?? null,
            'dataSource' => $compiledFamily['dataSource'] ?? 'folio',
            'attempts' => $telemetryContext['attempts'] ?? null,
            'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
        ] + $telemetryContext);

        unset($compiledFamily['queryDefinition']);
        return $compiledFamily;
    }

    private static function recoverPromptScopedFamilySlots(array $intent, string $prompt, $campus = null): array
    {
        $familyKey = trim((string)($intent['familyKey'] ?? ''));
        if (!is_array($intent['slots'] ?? null)) {
            return $intent;
        }

        if ($familyKey === 'inventory_collection_age') {
            $intent['slots'] = self::recoverCollectionAgeFamilySlotsFromPrompt(
                $intent['slots'],
                $prompt
            );
        }

        if ($familyKey === 'inventory_library_location_listing') {
            $intent['slots'] = self::recoverInventoryListingFamilySlotsFromPrompt(
                $intent['slots'],
                $prompt
            );
        }

        return $intent;
    }

    private static function recoverInventoryListingFamilySlotsFromPrompt(array $slots, string $prompt): array
    {
        $locationCodes = self::extractInventoryListingLocationCodes($prompt);
        if ($locationCodes === []) {
            return $slots;
        }

        $slots['location_code'] = implode(',', $locationCodes);

        $library = trim((string)($slots['library'] ?? ''));
        if ($library !== '' && self::valueLooksLikeLocationCodeList($library) && !self::promptMentionsExplicitLibraryScope($prompt)) {
            unset($slots['library']);
        }

        $location = trim((string)($slots['location'] ?? ''));
        if ($location !== '' && self::valueLooksLikeLocationCodeList($location)) {
            unset($slots['location']);
        }

        return $slots;
    }

    private static function recoverCollectionAgeFamilySlotsFromPrompt(array $slots, string $prompt): array
    {
        if (trim($prompt) === '') {
            return $slots;
        }

        $library = trim((string)($slots['library'] ?? ''));
        if ($library === '') {
            $recoveredLibrary = self::extractCollectionAgeLibraryScope($prompt);
            if ($recoveredLibrary !== '') {
                $slots['library'] = $recoveredLibrary;
            }
        }

        $location = trim((string)($slots['location'] ?? ''));
        if ($location === '') {
            $recoveredLocation = self::extractCollectionAgeLocationScope($prompt);
            if ($recoveredLocation !== '') {
                $slots['location'] = $recoveredLocation;
            }
        }

        return $slots;
    }

    private static function extractCollectionAgeLibraryScope(string $prompt): string
    {
        $patterns = [
            '/\b(?:in|at|from|for)\s+(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?library)\b/i',
            '/\b(?:of|in|at|for)\s+(?:the\s+)?([a-z0-9][a-z0-9 .\'\-]*?)\s+reference collection\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) !== 1) {
                continue;
            }

            $library = self::normalizeRecoveredPromptScope((string)($matches[1] ?? ''));
            if ($library === '') {
                continue;
            }

            if (preg_match('/\blibrary\b/i', $library) !== 1) {
                $library .= ' Library';
            }

            return $library;
        }

        return '';
    }

    private static function extractCollectionAgeLocationScope(string $prompt): string
    {
        if (preg_match('/\breference collection\b/i', $prompt) === 1) {
            return 'Reference collection';
        }

        return '';
    }

    private static function normalizeRecoveredPromptScope(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private static function extractInventoryListingLocationCodes(string $prompt): array
    {
        if (trim($prompt) === '') {
            return [];
        }

        $patterns = [
            '/\blocation codes?\s+((?:[A-Z0-9]{3,10}(?:\s*(?:,|and|or)\s*)?)+)\b/i',
            '/\b(?:in|at|from|for)\s+(?:the\s+)?((?:[A-Z0-9]{3,10}(?:\s*(?:,|and|or)\s*)?)+)\s+locations?\b/i',
            '/\b(?:in|at|from|for)\s+(?:the\s+)?((?:[A-Z0-9]{3,10}(?:\s*(?:,|and|or)\s*)?)+)\s+location codes?\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches) !== 1) {
                continue;
            }

            preg_match_all('/\b[A-Z0-9]{3,10}\b/', strtoupper((string)($matches[1] ?? '')), $codeMatches);
            $codes = [];
            foreach (($codeMatches[0] ?? []) as $code) {
                if (in_array($code, ['AND', 'OR'], true)) {
                    continue;
                }

                if (!in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }

            if ($codes !== []) {
                return $codes;
            }
        }

        return [];
    }

    private static function valueLooksLikeLocationCodeList(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        $tokens = preg_split('/\s*(?:,|and|or)\s*/i', strtoupper($value)) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), static function (string $token): bool {
            return $token !== '';
        }));

        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (preg_match('/^[A-Z0-9]{3,10}$/', $token) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function promptMentionsExplicitLibraryScope(string $prompt): bool
    {
        return preg_match('/\blibrary\b/i', $prompt) === 1;
    }

    private static function normalizeQueryFamilyPayload(array $normalizedPayload, string $prompt, $campus = null): array
    {
        $familyKey = trim((string)($normalizedPayload['familyKey'] ?? ''));
        if (!is_array($normalizedPayload['slots'] ?? null)) {
            return $normalizedPayload;
        }

        if ($familyKey === 'circulation_trends_matrix') {
            $normalizedPayload['slots'] = self::normalizeTrendFamilySlots(
                $normalizedPayload['slots'],
                $prompt,
                $campus
            );

            return $normalizedPayload;
        }

        if ($familyKey === 'circulation_top_items') {
            $normalizedPayload['slots'] = self::normalizeTopItemsFamilySlots(
                $normalizedPayload['slots'],
                $prompt,
                $campus
            );
        }

        return $normalizedPayload;
    }

    private static function normalizeTrendFamilySlots(array $slots, string $prompt, $campus = null): array
    {
        $normalizedPrompt = strtolower(trim($prompt));
        if ($normalizedPrompt === '') {
            return $slots;
        }

        $groupingDimension = trim((string)($slots['grouping_dimension'] ?? ''));
        if ($groupingDimension !== '') {
            $normalizedGroupingDimension = strtolower(str_replace(['-', ' '], '_', $groupingDimension));
            if (in_array($normalizedGroupingDimension, ['primary_call_number_class', 'call_number_class'], true)) {
                $slots['grouping_dimension'] = 'primary_call_number_class';
            }
        }

        $circulationSourcePolicy = trim((string)($slots['circulation_source_policy'] ?? ''));
        if (self::promptMentionsFormerAlephComparisonPolicy($normalizedPrompt)) {
            $slots['circulation_source_policy'] = 'former_aleph_comparison';

            return $slots;
        }

        if (self::promptMentionsPriorYearComparisonPolicy($normalizedPrompt)) {
            $slots['circulation_source_policy'] = 'prior_year_comparison';

            return $slots;
        }

        if (self::promptMentionsCumulativeBeforeComparisonPolicy($normalizedPrompt)) {
            $slots['circulation_source_policy'] = 'cumulative_before_selected_years_comparison';

            return $slots;
        }

        if (
            ($circulationSourcePolicy === '' || $circulationSourcePolicy !== 'current_loans_only')
            && !self::promptMentionsExplicitHistoricalCirculationPolicy($normalizedPrompt)
        ) {
            $slots['circulation_source_policy'] = 'current_loans_only';
        }

        return $slots;
    }

    private static function normalizeTopItemsFamilySlots(array $slots, string $prompt, $campus = null): array
    {
        $materialType = strtolower(trim((string)($slots['material_type'] ?? '')));
        if ($materialType !== '' && preg_match('/\bbooks?\b/', $materialType) === 1) {
            $slots['material_type'] = 'book';
        }

        $limit = trim((string)($slots['limit'] ?? ''));
        if ($limit === '' && preg_match('/\btop\s+(\d+)\b/i', $prompt, $matches) === 1) {
            $slots['limit'] = $matches[1];
        }

        return $slots;
    }

    private static function promptMentionsExplicitHistoricalCirculationPolicy(string $normalizedPrompt): bool
    {
        if (self::promptMentionsFormerAlephComparisonPolicy($normalizedPrompt)
            || self::promptMentionsPriorYearComparisonPolicy($normalizedPrompt)
            || self::promptMentionsCumulativeBeforeComparisonPolicy($normalizedPrompt)
        ) {
            return true;
        }

        return preg_match('/\baudit[_ -]?loan\b/', $normalizedPrompt) === 1;
    }

    private static function buildFamilySlotClarificationResponse(
        array $errors,
        array $intent,
        array $telemetryContext
    ): ?array {
        $missingSlots = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            if ((string)($error['code'] ?? '') !== 'required') {
                continue;
            }

            $path = (string)($error['path'] ?? '');
            if (strpos($path, 'slots.') !== 0) {
                continue;
            }

            $slotName = substr($path, strlen('slots.'));
            if ($slotName === false || $slotName === '') {
                continue;
            }

            $missingSlots[] = $slotName;
        }

        $missingSlots = array_values(array_unique($missingSlots));
        sort($missingSlots);
        if (empty($missingSlots)) {
            return null;
        }

        $question = self::buildFamilySlotClarificationQuestion($missingSlots);
        $routeReason = 'family_slot_missing_required_slot';

        self::logRouteSelection('clarification', $routeReason, [
            'familyKey' => $intent['familyKey'] ?? null,
            'missingSlots' => $missingSlots,
        ]);
        self::logNlTelemetry('nl2sql.generated', [
            'route' => 'clarification',
            'routeReason' => $routeReason,
            'model' => $telemetryContext['model'] ?? null,
            'promptVersion' => $telemetryContext['promptVersion'] ?? null,
            'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
            'finishReason' => $telemetryContext['finishReason'] ?? null,
            'dataSource' => null,
            'attempts' => $telemetryContext['attempts'] ?? null,
            'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
            'missingSlots' => $missingSlots,
        ] + $telemetryContext);

        return [
            'needsClarification' => true,
            'clarificationType' => 'missing_required_slot',
            'question' => $question,
            'options' => [],
            'missingSlots' => $missingSlots,
            'warnings' => [],
            'suggestions' => [],
            'route' => 'clarification',
            'routeReason' => $routeReason,
        ];
    }

    private static function buildFamilySlotClarificationQuestion(array $missingSlots): string
    {
        sort($missingSlots);

        if ($missingSlots === ['library']) {
            return 'Which library should I use for this report?';
        }

        if ($missingSlots === ['location']) {
            return 'Which location or collection should I use for this report?';
        }

        if ($missingSlots === ['location_code']) {
            return 'Which location code should I use for this report?';
        }

        if (count($missingSlots) === 1) {
            switch ($missingSlots[0]) {
                case 'contributor_name':
                    return 'Which contributor should I use for this report?';
                case 'campus':
                    return 'Which campus should I scope this report to?';
                case 'material_type':
                    return 'Which material type should I use for this report?';
                case 'grouping_dimension':
                    return 'Which grouping dimension should I use for this report?';
                case 'year_buckets':
                    return 'Which years should I use for this report?';
                case 'requested_outputs':
                    return 'What fields should I include in the results?';
            }
        }

        return 'I need one more detail before I can generate SQL for this request.';
    }

    private static function buildCompiledQueryFamilyOrLegacyFallback(
        array $normalizedPayload,
        string $routeReason,
        $prompt,
        $campus,
        array $telemetryContext,
        $compiler = null,
        $legacyFallbackFactory = null
    ): array {
        if ($compiler === null) {
            $compiler = function (array $payload, string $reason): array {
                return self::buildCompiledQueryFamilyResult($payload, $reason);
            };
        }

        if ($legacyFallbackFactory === null) {
            $legacyFallbackFactory = function () use ($prompt, $campus): array {
                return self::generateSql($prompt, $campus, true);
            };
        }

        try {
            return $compiler($normalizedPayload, $routeReason);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $reason = 'family_compiler_failed';
            self::logRouteSelection('legacy_fallback', $reason . ': ' . $e->getMessage(), [
                'query' => [],
            ]);
            $fallback = $legacyFallbackFactory();
            $fallback['route'] = 'legacy_fallback';
            $fallback['routeReason'] = $reason;
            self::logNlTelemetry('nl2sql.generated', [
                'route' => $fallback['route'],
                'routeReason' => $fallback['routeReason'],
                'model' => $telemetryContext['model'] ?? null,
                'promptVersion' => $telemetryContext['promptVersion'] ?? null,
                'promptFingerprint' => $telemetryContext['promptFingerprint'] ?? null,
                'finishReason' => $telemetryContext['finishReason'] ?? null,
                'dataSource' => $fallback['dataSource'] ?? 'folio',
                'attempts' => $telemetryContext['attempts'] ?? null,
                'elapsedMs' => $telemetryContext['elapsedMs'] ?? null,
            ] + $telemetryContext);
            return $fallback;
        }
    }

    private static function buildCompiledQueryFamilyResult(array $normalizedPayload, string $routeReason): array
    {
        $queryDef = QueryFamilyCompilerService::compileToQueryDefinition($normalizedPayload);
        $built = QueryFamilyCompilerService::compileToSql($normalizedPayload);

        $sql = self::inlineParams($built['sql'] ?? '', $built['params'] ?? []);
        $sql = self::normalizeIdCasts($sql);

        SqlBuilderService::validateSafety($sql);
        SqlBuilderService::validateTablePolicy($sql);
        self::validateTableReferences($sql);

        try {
            self::validateCompiledQueryFamilyShape($normalizedPayload, $queryDef, $sql);
        } catch (\InvalidArgumentException $e) {
            $issueFamily = 'family_sql_shape';
            if (preg_match('/^([a-z0-9_]+)/i', $e->getMessage(), $matches) === 1) {
                $issueFamily = strtolower((string)$matches[1]);
            }

            self::logValidationFailure('family_sql_shape', [
                'route' => 'builder_intent',
                'routeReason' => $routeReason,
                'familyKey' => $normalizedPayload['familyKey'] ?? null,
                'issueFamily' => $issueFamily,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $dataSource = 'folio';
        if (preg_match('/\b(acrl_statistics|report_expense_allocations)\b/i', $sql)) {
            $dataSource = 'local';
        }

        $tables = $queryDef['tables'] ?? [];
        $explanation = 'Generated from structured family compiler mode.';
        if (!empty($tables)) {
            $explanation .= ' Tables: ' . implode(', ', $tables) . '.';
        }

        return [
            'sql' => $sql,
            'explanation' => $explanation,
            'dataSource' => $dataSource,
            'route' => 'builder_intent',
            'routeReason' => $routeReason,
            'queryDefinition' => $queryDef,
        ];
    }

    private static function validateCompiledQueryFamilyShape(array $normalizedPayload, array $queryDef, string $sql): void
    {
        $familyKey = trim((string)($normalizedPayload['familyKey'] ?? ''));
        if ($familyKey === 'inventory_collection_age') {
            $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
            $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];

            $hasPublicationYearAnchor = in_array('inventory_instance__t__publication', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_instance__t__publication')
                && stripos($sql, 'LEFT JOIN inventory.instance__t__publication') !== false
                && stripos($sql, 'publication__date_of_publication') !== false
                && stripos($sql, "publication__date_of_publication ~ '^\\d{4}'") !== false;

            $usesInvalidAgeSource = preg_match('/\b(status__date|metadata__created_date|cataloged_date)\b/i', $sql) === 1;
            if (!$hasPublicationYearAnchor || $usesInvalidAgeSource) {
                throw new \InvalidArgumentException(
                    'missing_publication_year_anchor: Collection-age family prompts require validated instance publication-year logic.'
                );
            }

            $library = trim((string)($slots['library'] ?? ''));
            if ($library !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLibraryFilter = QueryFamilySlotService::resolveSlotMatch('library', $library, $matchPolicy);
                $hasLibraryScope = in_array('inventory_libraries', $queryTables, true)
                    && self::queryDefinitionHasJoin($queryDef, 'inventory_locations', 'inventory_libraries')
                    && stripos($sql, 'JOIN inventory.loclibrary__t') !== false
                    && self::queryDefinitionHasFilter($queryDef, 'inventory_libraries', 'name', (string)($expectedLibraryFilter['value'] ?? ''));

                if (!$hasLibraryScope) {
                    throw new \InvalidArgumentException(
                        'missing_library_scope_anchor: Collection-age family prompts require a library lookup join and filter.'
                    );
                }
            }

            $location = trim((string)($slots['location'] ?? ''));
            if ($location !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLocationFilter = QueryFamilySlotService::resolveSlotMatch('location', $location, $matchPolicy);
                $hasLocationScope = in_array('inventory_locations', $queryTables, true)
                    && self::queryDefinitionHasJoin($queryDef, 'inventory_items', 'inventory_locations')
                    && stripos($sql, 'JOIN inventory.location__t') !== false
                    && self::queryDefinitionHasFilter($queryDef, 'inventory_locations', 'name', (string)($expectedLocationFilter['value'] ?? ''));

                if (!$hasLocationScope) {
                    throw new \InvalidArgumentException(
                        'missing_location_scope_anchor: Collection-age family location prompts require a location lookup join and filter.'
                    );
                }
            }

            return;
        }

        if ($familyKey === 'inventory_library_location_listing') {
            $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
            $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];
            $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];

            $hasInventoryListingScopeAnchor = in_array('inventory_instances', $queryTables, true)
                && in_array('inventory_holdings', $queryTables, true)
                && in_array('inventory_items', $queryTables, true)
                && in_array('inventory_locations', $queryTables, true)
                && in_array('inventory_libraries', $queryTables, true)
                && in_array('inventory_campuses', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_holdings')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_holdings', 'inventory_items')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_items', 'inventory_locations')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_locations', 'inventory_libraries')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_libraries', 'inventory_campuses')
                && stripos($sql, 'JOIN inventory.holdings_record__t') !== false
                && stripos($sql, 'JOIN inventory.item__t') !== false
                && stripos($sql, 'JOIN inventory.location__t') !== false
                && stripos($sql, 'JOIN inventory.loclibrary__t') !== false
                && stripos($sql, 'JOIN inventory.loccampus__t') !== false;

            if (!$hasInventoryListingScopeAnchor) {
                throw new \InvalidArgumentException(
                    'missing_inventory_listing_scope_anchor: Library/location listing prompts require the canonical inventory scope path from instances through holdings, items, and library lookups.'
                );
            }

            $requiresContributorJoin = false;
            foreach ($requestedOutputs as $outputField) {
                if (in_array($outputField, ['author', 'contributor_name'], true)) {
                    $requiresContributorJoin = true;
                    break;
                }
            }

            if ($requiresContributorJoin) {
                $hasContributorOutputAnchor = in_array('inventory_instance__t__contributors', $queryTables, true)
                    && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_instance__t__contributors')
                    && stripos($sql, 'JOIN inventory.instance__t__contributors') !== false;

                if (!$hasContributorOutputAnchor) {
                    throw new \InvalidArgumentException(
                        'missing_listing_contributor_anchor: Library/location listing prompts that request author outputs require the contributor join.'
                    );
                }
            }

            $campus = trim((string)($slots['campus'] ?? ''));
            if ($campus !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedCampusFilter = QueryFamilySlotService::resolveSlotMatch('campus', $campus, $matchPolicy);
                $hasCampusScope = self::queryDefinitionHasFilter($queryDef, 'inventory_campuses', 'name', (string)($expectedCampusFilter['value'] ?? ''));

                if (!$hasCampusScope) {
                    throw new \InvalidArgumentException(
                        'missing_campus_scope_anchor: Library/location listing prompts require a campus lookup filter when campus scope is present.'
                    );
                }
            }

            $library = trim((string)($slots['library'] ?? ''));
            if ($library !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLibraryFilter = QueryFamilySlotService::resolveSlotMatch('library', $library, $matchPolicy);
                $hasLibraryScope = self::queryDefinitionHasFilter($queryDef, 'inventory_libraries', 'name', (string)($expectedLibraryFilter['value'] ?? ''));

                if (!$hasLibraryScope) {
                    throw new \InvalidArgumentException(
                        'missing_library_scope_anchor: Library/location listing prompts require a library lookup filter.'
                    );
                }
            }

            $location = trim((string)($slots['location'] ?? ''));
            if ($location !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLocationFilter = QueryFamilySlotService::resolveSlotMatch('location', $location, $matchPolicy);
                $hasLocationScope = self::queryDefinitionHasFilter($queryDef, 'inventory_locations', 'name', (string)($expectedLocationFilter['value'] ?? ''));

                if (!$hasLocationScope) {
                    throw new \InvalidArgumentException(
                        'missing_location_scope_anchor: Library/location listing prompts require a location lookup filter when explicit location scope is present.'
                    );
                }
            }

            $locationCode = trim((string)($slots['location_code'] ?? ''));
            if ($locationCode !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLocationCodeFilter = QueryFamilySlotService::resolveSlotMatch('location_code', $locationCode, $matchPolicy);
                $hasLocationCodeScope = self::queryDefinitionHasFilter($queryDef, 'inventory_locations', 'code', (string)($expectedLocationCodeFilter['value'] ?? ''));

                if (!$hasLocationCodeScope) {
                    throw new \InvalidArgumentException(
                        'missing_location_code_scope_anchor: Library/location listing prompts with location codes require an inventory location code filter.'
                    );
                }
            }

            return;
        }

        if ($familyKey === 'circulation_top_items') {
            $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
            $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];

            $hasInventoryScopeAnchor = in_array('inventory_instances', $queryTables, true)
                && in_array('inventory_holdings', $queryTables, true)
                && in_array('inventory_items', $queryTables, true)
                && in_array('inventory_locations', $queryTables, true)
                && in_array('inventory_libraries', $queryTables, true)
                && in_array('inventory_campuses', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_holdings')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_holdings', 'inventory_items')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_items', 'inventory_locations')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_locations', 'inventory_libraries')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_libraries', 'inventory_campuses')
                && stripos($sql, 'JOIN inventory.holdings_record__t') !== false
                && stripos($sql, 'JOIN inventory.instance__t') !== false
                && stripos($sql, 'JOIN inventory.location__t') !== false
                && stripos($sql, 'JOIN inventory.loclibrary__t') !== false
                && stripos($sql, 'JOIN inventory.loccampus__t') !== false;

            if (!$hasInventoryScopeAnchor) {
                throw new \InvalidArgumentException(
                    'missing_top_items_scope_anchor: Top-items family prompts require the canonical inventory scope path from instances through holdings, items, and library lookups.'
                );
            }

            $campus = trim((string)($slots['campus'] ?? ''));
            if ($campus !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedCampusFilter = QueryFamilySlotService::resolveSlotMatch('campus', $campus, $matchPolicy);
                $hasCampusScope = self::queryDefinitionHasFilter($queryDef, 'inventory_campuses', 'name', (string)($expectedCampusFilter['value'] ?? ''));

                if (!$hasCampusScope) {
                    throw new \InvalidArgumentException(
                        'missing_campus_scope_anchor: Top-items family prompts require a campus lookup filter when campus scope is present.'
                    );
                }
            }

            $library = trim((string)($slots['library'] ?? ''));
            if ($library !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLibraryFilter = QueryFamilySlotService::resolveSlotMatch('library', $library, $matchPolicy);
                $hasLibraryScope = self::queryDefinitionHasFilter($queryDef, 'inventory_libraries', 'name', (string)($expectedLibraryFilter['value'] ?? ''));

                if (!$hasLibraryScope) {
                    throw new \InvalidArgumentException(
                        'missing_library_scope_anchor: Top-items family prompts require a library lookup filter.'
                    );
                }
            }

            $location = trim((string)($slots['location'] ?? ''));
            if ($location !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLocationFilter = QueryFamilySlotService::resolveSlotMatch('location', $location, $matchPolicy);
                $hasLocationScope = self::queryDefinitionHasFilter($queryDef, 'inventory_locations', 'name', (string)($expectedLocationFilter['value'] ?? ''));

                if (!$hasLocationScope) {
                    throw new \InvalidArgumentException(
                        'missing_location_scope_anchor: Top-items family prompts with explicit location scope require a location lookup filter.'
                    );
                }
            }

            $materialType = trim((string)($slots['material_type'] ?? ''));
            if ($materialType !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedMaterialTypeFilter = QueryFamilySlotService::resolveSlotMatch('material_type', $materialType, $matchPolicy);
                $hasMaterialTypeAnchor = in_array('inventory_material_types', $queryTables, true)
                    && self::queryDefinitionHasJoin($queryDef, 'inventory_items', 'inventory_material_types')
                    && stripos($sql, 'JOIN inventory.material_type__t') !== false
                    && self::queryDefinitionHasFilter($queryDef, 'inventory_material_types', 'name', (string)($expectedMaterialTypeFilter['value'] ?? ''));

                if (!$hasMaterialTypeAnchor) {
                    throw new \InvalidArgumentException(
                        'missing_material_type_anchor: Top-items family prompts require a material-type join and filter for ranked item scope.'
                    );
                }
            }

            $limit = trim((string)($slots['limit'] ?? ''));
            $expectedLimit = $limit === '' ? QueryFamilySlotService::DEFAULT_LIMIT : max(1, min((int)$limit, QueryFamilySlotService::DEFAULT_LIMIT));
            $hasCirculationAnchor = stripos($sql, 'FROM circulation.audit_loan__t al') !== false
                && stripos($sql, "al.loan__action IN ('checkedout', 'checkedOutThroughOverride')") !== false
                && stripos($sql, 'FROM inventory.item__t__notes itn') !== false
                && stripos($sql, "itn.notes__item_note_type_id = '" . QueryFamilyCompilerService::FORMER_CIRCULATION_NOTE_TYPE_ID . "'") !== false
                && stripos($sql, 'AS total_circulation') !== false
                && stripos($sql, 'ORDER BY total_circulation DESC') !== false
                && stripos($sql, 'LIMIT ' . $expectedLimit) !== false;

            if (!$hasCirculationAnchor) {
                throw new \InvalidArgumentException(
                    'missing_top_items_circulation_anchor: Top-items family prompts require audit-loan counts, former-circulation notes, total_circulation ranking, and the requested top-N limit.'
                );
            }

            return;
        }

        if ($familyKey === 'circulation_trends_matrix') {
            $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
            $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];

            $hasCallNumberClassAnchor = in_array('circulation_loans', $queryTables, true)
                && in_array('inventory_items', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'circulation_loans', 'inventory_items')
                && stripos($sql, 'FROM circulation.loan__t') !== false
                && stripos($sql, 'JOIN inventory.item__t') !== false
                && stripos($sql, 'AS call_number_class') !== false
                && stripos($sql, "effective_call_number_components__call_number ~ '^[A-Z]{1,3}[0-9]'") !== false
                && stripos($sql, 'LPAD(') !== false
                && preg_match('/LEFT\s*\([^\)]*call_number/i', $sql) !== 1;

            if (!$hasCallNumberClassAnchor) {
                throw new \InvalidArgumentException(
                    'missing_call_number_class_anchor: Trend-matrix family prompts require the canonical primary call-number-class extraction logic.'
                );
            }

            $hasLocationBranch = in_array('inventory_locations', $queryTables, true)
                && in_array('inventory_libraries', $queryTables, true)
                && in_array('inventory_campuses', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'circulation_loans', 'inventory_locations')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_locations', 'inventory_libraries')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_libraries', 'inventory_campuses')
                && stripos($sql, 'JOIN inventory.location__t') !== false
                && stripos($sql, 'JOIN inventory.loclibrary__t') !== false
                && stripos($sql, 'JOIN inventory.loccampus__t') !== false;

            if (!$hasLocationBranch) {
                throw new \InvalidArgumentException(
                    'missing_circulation_scope_anchor: Trend-matrix family prompts require the circulation location-to-library scope branch.'
                );
            }

            $campus = trim((string)($slots['campus'] ?? ''));
            if ($campus !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedCampusFilter = QueryFamilySlotService::resolveSlotMatch('campus', $campus, $matchPolicy);
                $hasCampusScope = self::queryDefinitionHasFilter($queryDef, 'inventory_campuses', 'name', (string)($expectedCampusFilter['value'] ?? ''));

                if (!$hasCampusScope) {
                    throw new \InvalidArgumentException(
                        'missing_campus_scope_anchor: Trend-matrix family prompts require a campus lookup filter.'
                    );
                }
            }

            $library = trim((string)($slots['library'] ?? ''));
            if ($library !== '') {
                $matchPolicy = (string)($slots['match_policy'] ?? QueryFamilySlotService::DEFAULT_MATCH_POLICY);
                $expectedLibraryFilter = QueryFamilySlotService::resolveSlotMatch('library', $library, $matchPolicy);
                $hasLibraryScope = self::queryDefinitionHasFilter($queryDef, 'inventory_libraries', 'name', (string)($expectedLibraryFilter['value'] ?? ''));

                if (!$hasLibraryScope) {
                    throw new \InvalidArgumentException(
                        'missing_library_scope_anchor: Trend-matrix family prompts require a library lookup filter.'
                    );
                }
            }

            $yearBuckets = is_array($slots['year_buckets'] ?? null) ? $slots['year_buckets'] : [];
            foreach ($yearBuckets as $year) {
                $year = (string)$year;
                if (
                    stripos($sql, 'EXTRACT(YEAR FROM cl.loan_date) = ' . $year) === false
                    || stripos($sql, 'AS circulation_' . $year) === false
                ) {
                    throw new \InvalidArgumentException(
                        'missing_year_bucket_anchor: Trend-matrix family prompts require one aggregate column per requested year bucket.'
                    );
                }
            }

            $circulationSourcePolicy = trim((string)($slots['circulation_source_policy'] ?? ''));
            if ($circulationSourcePolicy === 'current_loans_only'
                && (
                    stripos($sql, "cl.action IN ('checkedout', 'checkedOutThroughOverride')") === false
                    || stripos($sql, 'GROUP BY call_number_class') === false
                )
            ) {
                throw new \InvalidArgumentException(
                    'missing_current_circulation_anchor: Trend-matrix family prompts require current checkout action filtering and grouped matrix output.'
                );
            }

            return;
        }

        if ($familyKey !== 'inventory_contributor_campus_item_barcode') {
            return;
        }

        $slots = is_array($normalizedPayload['slots'] ?? null) ? $normalizedPayload['slots'] : [];
        $queryTables = is_array($queryDef['tables'] ?? null) ? $queryDef['tables'] : [];
        $requestedOutputs = is_array($slots['requested_outputs'] ?? null) ? $slots['requested_outputs'] : [];

        $requiresItemBranch = false;
        foreach ($requestedOutputs as $outputField) {
            if (in_array($outputField, ['barcode', 'item_id'], true)) {
                $requiresItemBranch = true;
                break;
            }
        }

        if ($requiresItemBranch) {
            $hasHoldingsBranch = in_array('inventory_holdings', $queryTables, true)
                && in_array('inventory_items', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instances', 'inventory_holdings')
                && self::queryDefinitionHasJoin($queryDef, 'inventory_holdings', 'inventory_items')
                && stripos($sql, 'JOIN inventory.holdings_record__t') !== false
                && stripos($sql, 'JOIN inventory.item__t') !== false;

            if (!$hasHoldingsBranch) {
                throw new \InvalidArgumentException(
                    'missing_holdings_item_branch: Covered-family item outputs require holdings-to-items joins.'
                );
            }
        }

        $campus = trim((string)($slots['campus'] ?? ''));
        if ($campus !== '') {
            $hasCampusScope = in_array('inventory_campuses', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_libraries', 'inventory_campuses')
                && stripos($sql, 'JOIN inventory.loccampus__t') !== false
                && self::queryDefinitionHasFilter($queryDef, 'inventory_campuses', 'name', $campus);

            if (!$hasCampusScope) {
                throw new \InvalidArgumentException(
                    'missing_campus_scope_anchor: Covered-family campus prompts require a campus lookup join and filter.'
                );
            }
        }

        $contributorNameType = trim((string)($slots['contributor_name_type'] ?? ''));
        if ($contributorNameType !== '') {
            $hasContributorNameTypeScope = in_array('inventory_contributor_name_types', $queryTables, true)
                && self::queryDefinitionHasJoin($queryDef, 'inventory_instance__t__contributors', 'inventory_contributor_name_types')
                && stripos($sql, 'JOIN inventory.contributor_name_type__t') !== false
                && self::queryDefinitionHasFilter(
                    $queryDef,
                    'inventory_contributor_name_types',
                    'name',
                    $contributorNameType
                );

            if (!$hasContributorNameTypeScope) {
                throw new \InvalidArgumentException(
                    'missing_contributor_name_type_anchor: Covered-family contributor name type prompts require the contributor-name-type join and filter.'
                );
            }
        }
    }

    private static function queryDefinitionHasJoin(array $queryDef, string $fromTable, string $toTable): bool
    {
        foreach (($queryDef['joins'] ?? []) as $join) {
            if (!is_array($join)) {
                continue;
            }

            if (($join['from_table'] ?? null) === $fromTable && ($join['to_table'] ?? null) === $toTable) {
                return true;
            }
        }

        return false;
    }

    private static function queryDefinitionHasFilter(array $queryDef, string $table, string $column, string $value): bool
    {
        foreach (($queryDef['filters'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            if (
                ($filter['table'] ?? null) === $table
                && ($filter['column'] ?? null) === $column
                && (string)($filter['value'] ?? '') === $value
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert a scalar value to a SQL literal representation.
     *
     * @param mixed $value
     * @return string
     */
    private static function toSqlLiteral($value)
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_numeric($value)) {
            $numeric = trim((string)$value);
            if (!preg_match('/^0[0-9]+$/', $numeric)) {
                return $numeric;
            }
        }

        $escaped = str_replace("'", "''", (string)$value);
        return "'{$escaped}'";
    }

    /**
     * Validate a QueryIntent payload against the server-side contract.
     *
     * This is intentionally additive for NL2SQL-003 and does not change the
     * existing freeform SQL generation pipeline.
     *
     * @param mixed $intent
     * @return array {valid: bool, errors: array, normalizedIntent: array|null}
     */
    public static function validateQueryIntent($intent)
    {
        return QueryIntentService::validateIntent($intent);
    }

    /**
     * Translate a QueryIntent payload to a SqlBuilder query definition.
     *
     * @param mixed $intent
     * @return array QueryDefinition shape accepted by SqlBuilderService::build
     * @throws QueryIntentValidationException
     */
    public static function intentToQueryDefinition($intent)
    {
        return QueryIntentService::toQueryDefinition($intent);
    }

    /**
     * Parse the Gemini response into SQL and explanation.
     * @param string $text Raw Gemini response text
      * @return array {sql: string, explanation: string, dataSource: string}
     */
    private static function parseResponse($text)
    {
        $sql = '';
        $explanation = '';
          $dataSource = 'folio';

        // Extract SQL from ```sql ... ``` code block
        if (preg_match('/```sql\s*\n(.*?)```/s', $text, $matches)) {
            $sql = trim($matches[1]);
        } elseif (preg_match('/```\s*\n(.*?)```/s', $text, $matches)) {
            $sql = trim($matches[1]);
        } else {
            // Try to find SELECT statement directly
            if (preg_match('/(SELECT\s+.+)/si', $text, $matches)) {
                $sql = trim($matches[1]);
            }
        }

        // Everything outside the code block is the explanation
        $explanation = preg_replace('/```(?:sql)?\s*\n.*?```/s', '', $text);
        $explanation = trim($explanation);

        if (preg_match('/DATA\s+SOURCE\s*:\s*(local|folio)/i', $text, $matches)) {
            $dataSource = strtolower($matches[1]);
        }

        // Strip DATA SOURCE directive if Gemini included it inside the SQL block
        $sql = preg_replace('/^\s*DATA\s+SOURCE\s*:\s*(local|folio)\s*\n?/im', '', $sql);
        $sql = trim($sql);

        if (empty($sql)) {
            throw new \RuntimeException(
                'Could not extract SQL from Gemini response. Raw response: ' . substr($text, 0, 500)
            );
        }

        // Validate the generated SQL
        SqlBuilderService::validateSafety($sql);
        SqlBuilderService::validateTablePolicy($sql);

        // Validate that referenced tables exist
        self::validateTableReferences($sql);

        // Safety net: if SQL clearly uses local tables, force local datasource
        if (preg_match('/\b(acrl_statistics|report_expense_allocations)\b/i', $sql)) {
            $dataSource = 'local';
        }

        // Normalize all ID-column comparisons to use ::text on both sides
        $sql = self::normalizeIdCasts($sql);

        return [
            'sql' => $sql,
            'explanation' => $explanation,
            'dataSource' => $dataSource,
        ];
    }

    /**
     * Strip any AI-generated type casts from SQL.
     *
     * MetaDB join columns are already compatible types — explicit casts bypass
     * PostgreSQL indexes and cause catastrophically slow seq scan / nested loop plans.
     * The original "operator does not exist: uuid = text" errors were caused by the
     * AI writing one-sided ::uuid casts, not by genuinely mismatched column types.
     * Solution: remove all ::uuid and ::text casts; write no new ones.
     */
    private static function normalizeIdCasts(string $sql): string
    {
        $sql = preg_replace('/::uuid\b/i', '', $sql);
        $sql = preg_replace('/::text\b/i', '', $sql);
        return $sql;
    }

    /**
     * Answer a schema-related question using AI.
     * Provides expert knowledge about the FOLIO schema, table relationships,
     * and can suggest relevant tables/SQL snippets.
     *
     * @param string $question The user's question about the schema
     * @param string|null $selectedTable Optional table for context
     * @return array {answer: string, recommendedTables?: string[], sql?: string}
     * @throws \RuntimeException
     */
    public static function answerSchemaQuestion($question, $selectedTable = null)
    {
        $apiKey = Yii::$app->params['geminiApiKey'];
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'Gemini API key not configured. Set GEMINI_API_KEY in .env'
            );
        }

        $model = Yii::$app->params['geminiModel'] ?: 'gemini-2.5-flash';
        $schemaContext = FolioSchemaService::buildSchemaContext();

        // If a specific table is selected, add its full detail
        $tableContext = '';
        if ($selectedTable) {
            $detail = FolioSchemaService::getTable($selectedTable);
            if ($detail) {
                $cols = array_map(function($c) {
                    return "  - {$c['name']} ({$c['type']}" . ($c['nullable'] ? ', nullable' : '') . ')';
                }, $detail['table']['columns'] ?? []);
                $tableContext = "\n\nCURRENTLY SELECTED TABLE: {$selectedTable}\nColumns:\n" . implode("\n", $cols);
                if (!empty($detail['relationships']['parents'])) {
                    $tableContext .= "\nParent FKs:";
                    foreach ($detail['relationships']['parents'] as $p) {
                        $tableContext .= "\n  - {$p['local_column']} → {$p['parent_table']}.{$p['parent_column']}";
                    }
                }
                if (!empty($detail['relationships']['children'])) {
                    $tableContext .= "\nChild FKs:";
                    foreach ($detail['relationships']['children'] as $c) {
                        $tableContext .= "\n  - {$c['child_table']}.{$c['child_column']} → {$c['local_column']}";
                    }
                }
            }
        }

        $systemPrompt = <<<PROMPT
You are a FOLIO library management system schema expert. You help users understand the database schema,
find the right tables for their needs, and write queries.

The database uses LDLite (a lightweight version of MetaDB) with schema-qualified table names.
Tables ending in __t are MetaDB flattened tables. Tables with __t__ in the name are subtables
(flattened array/object columns from the parent table).

IMPORTANT: Use the TABLE DESCRIPTIONS, DOMAIN VOCABULARY, and LOCATION NAMING SCHEMA in the schema to resolve ambiguous terms.
For example, "Smith College" is a CAMPUS (inventory.loccampus__t), NOT a vendor organization.
This is a shared Five Colleges system — library/location names are prefixed with 2-letter campus codes
(SC=Smith, AC=Amherst, MH=Mt Holyoke, UM=UMass, HC=Hampshire, RP=Five Colleges, YB=Yiddish Book Center).
When matching library/location names, use ILIKE with wildcards (e.g. '%Neilson%'). See Location Naming Schema.
Always check the vocabulary section before choosing a table for user-mentioned entities.
For text/name comparisons, use case-insensitive matching (LOWER() or ILIKE). Database values are often Title Case.
For item location joins, ALWAYS use item__t.effective_location_id (NOT holdings_record__t.permanent_location_id).

SCHEMA:
{$schemaContext}
{$tableContext}

RESPONSE FORMAT:
Return a JSON object with these fields:
- "answer": A clear, helpful explanation answering the user's question. Use markdown formatting.
- "recommendedTables": (optional) An array of full table names that are relevant to the question.
  Only include this if the question involves finding or recommending tables.
- "sql": (optional) A sample SQL query if helpful. Only include if the user is asking how to
  query something or wants an example. Use PostgreSQL syntax with schema-qualified table names.

Return ONLY the JSON object, no code fences or other text.
PROMPT;

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $client = new Client();
        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl($url)
            ->setHeaders(['Content-Type' => 'application/json'])
            ->setContent(json_encode([
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $question]],
                    ],
                ],
                'systemInstruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 4096,
                ],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                ],
            ]))
            ->send();

        if (!$response->isOk) {
            $err = $response->data['error']['message'] ?? 'Unknown error';
            throw new \RuntimeException("Gemini API error: {$err}");
        }

        $data = $response->data;
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Clean up response - remove any code fences
        $text = preg_replace('/^```(?:json)?\s*\n?/m', '', $text);
        $text = preg_replace('/\n?```\s*$/m', '', $text);
        $text = trim($text);

        $result = json_decode($text, true);
        if (!$result || !is_array($result) || empty($result['answer'])) {
            // If JSON parsing failed, return the raw text as the answer
            return ['answer' => $text];
        }

        return $result;
    }

    /**
     * Check that table names in the generated SQL actually exist in our schema.
     * Handles both LDP1 names and MetaDB schema-qualified names.
     * @param string $sql
     */
    private static function validateTableReferences($sql)
    {
        $tableNames = FolioSchemaService::getTableNames();
        $metadbMap = FolioSchemaService::discoverTableMapping();
        $metadbValues = array_flip(array_values($metadbMap));
        $localTables = ['acrl_statistics' => true, 'report_expense_allocations' => true];

        // Extract table references from FROM and JOIN clauses
        // Handle both plain names and schema-qualified names (schema.table)
        // Table names may contain hyphens (e.g. loc-campus__t), underscores, etc.
        preg_match_all('/(?:FROM|JOIN)\s+([\w-]+(?:\.[\w-]+)?)/i', $sql, $matches);
        $warnings = [];

        foreach ($matches[1] as $ref) {
            $ref = strtolower($ref);
            if ($ref === 'select' || $ref === 'lateral' || $ref === 'unnest') {
                continue;
            }

            // Check if it's a known MetaDB name (schema.table format)
            if (isset($metadbValues[$ref])) {
                continue; // Valid MetaDB table
            }

            // Check if it's a known LDP1 name
            $matched = FolioSchemaService::fuzzyMatch($ref);
            if ($matched !== null) {
                continue; // Valid LDP1 table
            }

            if (isset($localTables[$ref])) {
                continue;
            }

            $warnings[] = "Table '$ref' not found in schema";
        }

        // We warn but don't block — the SQL might use derived tables or CTEs
        if (!empty($warnings)) {
            Yii::warning('Gemini SQL validation warnings: ' . implode('; ', $warnings));
        }
    }

    /**
     * Generate a report template from a natural-language description.
     * Returns a structured template definition (not yet saved) for user preview.
     *
     * @param string $description What report the user wants
     * @return array Template definition: {slug, name, description, category, sqlTemplate, parameters, defaultLimit}
     * @throws \RuntimeException
     */
    public static function generateReportTemplate($description)
    {
        $apiKey = Yii::$app->params['geminiApiKey'];
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'Gemini API key not configured. Set GEMINI_API_KEY in .env'
            );
        }

        $model = Yii::$app->params['geminiModel'] ?: 'gemini-2.5-flash';
        $schemaContext = FolioSchemaService::buildSchemaContext();

        $systemPrompt = <<<PROMPT
You are a report template generator for a FOLIO library management system (LDLite/MetaDB database).

Your job is to create a parameterized SQL report template with parameter definitions based on a user's description.

RULES:
1. Generate ONLY SELECT queries — never INSERT, UPDATE, DELETE, DROP, or ALTER.
2. Use EXACT table and column names from the schema below.
3. Table names are schema-qualified (e.g. inventory.item__t, circulation.loan__t).
   Schema names do NOT have a "folio_" prefix.
4. Use PostgreSQL-compatible syntax.
5. LDLite tables have flattened columns (no JSON "data" blobs).
   Nested JSON fields appear as double-underscore columns (e.g. metadata__created_date, status__name).
6. Use the TABLE DESCRIPTIONS, DOMAIN VOCABULARY, and LOCATION NAMING SCHEMA in the schema to resolve ambiguous terms.
   For example, "Smith College" is a CAMPUS (inventory.loccampus__t), NOT a vendor organization.
   This is a shared Five Colleges system — library/location names are prefixed with 2-letter campus codes
   (SC=Smith, AC=Amherst, MH=Mt Holyoke, UM=UMass, HC=Hampshire, RP=Five Colleges, YB=Yiddish Book Center).
   When matching library/location names, use ILIKE with wildcards (e.g. '%Neilson%'). See Location Naming Schema.
   Always check the vocabulary section before choosing a table for user-mentioned entities.
   For text/name comparisons, use case-insensitive matching (LOWER() or ILIKE). Database values are often Title Case.
   For item location joins, ALWAYS use item__t.effective_location_id (NOT holdings_record__t.permanent_location_id).
7. For parameters that users should fill in, use :paramName placeholders (PDO bind syntax).
7. Choose appropriate parameter types: date, text, select, number, boolean.
8. For text filters that should do partial matching, add "wrap": "like" to the parameter definition.
9. For select parameters, include "options_sql" — a small SQL query to populate the dropdown.
10. NEVER use the PostgreSQL ? operator for JSONB queries — PDO treats ? as a positional parameter placeholder.
    Instead of: data->'key' ? :param  use: po.acq_unit_ids = :param (LDLite tables have denormalized columns).
    If you must query JSONB, use jsonb_exists(data->'key', :param) instead of the ? operator.
11. ALWAYS prefer SUBTABLES over JSONB/data column queries. Subtables (pattern: schema.parent__t__child) are
    flattened versions of nested JSON arrays. They join to their parent on id.
    Examples:
    - Use invoice.invoice_lines__t__fund_distributions instead of data->'fundDistributions' or jsonb_array_elements()
    - Use orders.purchase_order__t__acq_unit_ids instead of data->'acqUnitIds'
    - Use finance.fund__t__acq_unit_ids instead of data->'acqUnitIds'
    NEVER use data-> column references or jsonb_array_elements() — these do not exist in __t tables.
12. Use smart default macros where appropriate:
    - \$fiscal_year_start — July 1 of current fiscal year
    - \$fiscal_year_end — June 30 of current fiscal year  
    - \$today — current date
    - \$30_days_ago — 30 days before today
    - \$90_days_ago — 90 days before today
13. PERFORMANCE — ORDER BY: Do NOT add ORDER BY unless the user explicitly requests sorted or
    ranked results (e.g. "top N", "highest", "sorted by"). ORDER BY forces PostgreSQL to
    materialize the ENTIRE result set before returning any rows — even with LIMIT 100.
    OMIT ORDER BY for listing/existence/missing-field queries. KEEP it only for ranking
    (ORDER BY count DESC) or when the user explicitly asks for sorted output.
14. UUID TYPE CASTS — NEVER write ::uuid or ::text anywhere. MetaDB join columns are
    already compatible types. Explicit casts bypass PostgreSQL indexes and cause
    catastrophically slow full-table scans. Always write plain equality with no casts:
    hr.instance_id = inst.id, ii.holdings_record_id = hr.id, etc.
    ::uuid and ::text are NEVER correct in JOIN ON conditions or WHERE clauses.
15. MONETARY / FINANCIAL COLUMNS — MANDATORY: Format ALL money amounts as USD currency.
    Finance tables store amounts as NUMERIC with many decimal places.
    ALWAYS use TO_CHAR to format as a USD dollar amount with comma separators:
      TO_CHAR(inv.total, 'FM$999,999,999,990.00')
      TO_CHAR(SUM(inv.amount), 'FM$999,999,999,990.00')
    Use TO_CHAR only in the outermost SELECT (not in WHERE, JOIN, or subquery filters).
    Applies to any column from finance.*, invoice.*, or any column whose name contains:
    total, amount, price, cost, spent, encumbered, expenditure, budget, balance.
    NEVER return raw unformatted monetary values.

SCHEMA:
{$schemaContext}

RESPONSE FORMAT:
Return ONLY a JSON object (no markdown, no code blocks) with this structure:
{
  "slug": "kebab-case-name",
  "name": "Human Readable Report Name",
  "description": "What this report shows and why it's useful.",
  "category": "acquisitions|circulation|inventory|finance|users|other",
  "sqlTemplate": "SELECT ... FROM ... WHERE col LIKE :param ...",
  "parameters": [
    {
      "name": "paramName",
      "type": "date|text|select|number|boolean",
      "label": "User-Facing Label",
      "required": true,
      "default": "\$fiscal_year_start",
      "placeholder": "Hint text",
      "description": "What this parameter filters"
    }
  ],
  "defaultLimit": 100
}
PROMPT;

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $client = new \yii\httpclient\Client();
        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl($url)
            ->setHeaders(['Content-Type' => 'application/json'])
            ->setContent(json_encode([
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [['text' => "Create a report template for: {$description}"]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 16384,
                ],
            ]))
            ->send();

        if (!$response->isOk) {
            $error = json_decode($response->content, true);
            $msg = $error['error']['message'] ?? 'Unknown Gemini API error';
            throw new \RuntimeException("Gemini API error: {$msg}");
        }

        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            throw new \RuntimeException(
                'AI response was truncated (report too complex). Try a shorter description.'
            );
        }
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return self::parseReportTemplate($text);
    }

    /**
     * Convert a Yii2 PHP report model into a report template using Gemini AI.
     *
     * @param string $phpCode The PHP source code of a Yii2 report model
     * @return array Template definition: {slug, name, description, category, sqlTemplate, parameters, defaultLimit}
     * @throws \RuntimeException
     */
    public static function convertReportFromPhp($phpCode)
    {
        $apiKey = Yii::$app->params['geminiApiKey'];
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'Gemini API key not configured. Set GEMINI_API_KEY in .env'
            );
        }

        $model = Yii::$app->params['geminiModel'] ?: 'gemini-2.5-flash';
        $schemaContext = FolioSchemaService::buildSchemaContext();

        $systemPrompt = <<<PROMPT
You are a report template converter for a FOLIO library management system (LDLite/MetaDB database).

Your job is to analyze a Yii2 PHP report model and convert it into a parameterized SQL report template with parameter definitions.

UNDERSTANDING THE PHP CODE:
- The PHP class extends a base model and builds SQL queries using Yii2's SqlDataProvider.
- SQL is typically in a \$sql string variable or returned from a method.
- Parameters are bound via \$params array with ':paramName' => \$value patterns.
- `setDefaultDates()` sets \$fiscal_year_start and \$fiscal_year_end — map these to \$fiscal_year_start and \$fiscal_year_end macros.
- Attributes like \$this->start_date, \$this->end_date etc. are user-supplied parameters.
- Pattern `'%' . \$this->someParam . '%'` means the parameter should have "wrap": "like".
- Dynamic IN clauses built from arrays (e.g. exploding textarea input into multiple bind params) should use a single "list" type parameter — the system will expand `:paramName` into `:paramName_0, :paramName_1, ...` at runtime.
- Remove any `ldlite.` or `ldplite.` schema prefix from table references — use only the standard schema.table__t format.
- Remove `ldp.table_name` references — use the documented schema-qualified names.

CONVERSION RULES:
1. Extract the core SQL SELECT query from the PHP code.
2. Replace PHP variable bindings with :paramName placeholders.
3. Keep the SQL PostgreSQL-compatible.
4. For dynamic fiscal-year column pivots (e.g. SUM(CASE WHEN year = X)), simplify to a single date range filter with a total column.
5. For parameters with select/dropdown values populated from DB queries, include "options_sql" with the query.
6. Use appropriate parameter types: date, text, select, number, boolean, list.
7. The "list" type is for parameters that accept multiple values (one per line) for IN clauses.
8. NEVER use the PostgreSQL ? operator for JSONB queries — PDO treats ? as a positional parameter placeholder.
   Instead of: data->'key' ? :param  use the denormalized column (e.g. po.acq_unit_ids = :param).
   If you must query JSONB, use jsonb_exists(data->'key', :param) instead of the ? operator.
9. ALWAYS prefer SUBTABLES over JSONB/data column queries. Subtables (pattern: schema.parent__t__child) are
   flattened versions of nested JSON arrays. They join to their parent on id.
   Examples:
   - Use invoice.invoice_lines__t__fund_distributions instead of data->'fundDistributions' or jsonb_array_elements()
   - Use orders.purchase_order__t__acq_unit_ids instead of data->'acqUnitIds'
   - Use finance.fund__t__acq_unit_ids instead of data->'acqUnitIds'
   NEVER use data-> column references or jsonb_array_elements() — these do not exist in __t tables.
10. Use the TABLE DESCRIPTIONS, DOMAIN VOCABULARY, and LOCATION NAMING SCHEMA in the schema to resolve ambiguous terms.
    For example, "Smith College" is a CAMPUS (inventory.loccampus__t), NOT a vendor organization.
    This is a shared Five Colleges system — library/location names are prefixed with 2-letter campus codes
    (SC=Smith, AC=Amherst, MH=Mt Holyoke, UM=UMass, HC=Hampshire, RP=Five Colleges, YB=Yiddish Book Center).
    When matching library/location names, use ILIKE with wildcards (e.g. '%Neilson%'). See Location Naming Schema.
    Always check the vocabulary section before choosing a table for user-mentioned entities.
    For text/name comparisons, use case-insensitive matching (LOWER() or ILIKE). Database values are often Title Case.
    For item location joins, ALWAYS use item__t.effective_location_id (NOT holdings_record__t.permanent_location_id).
11. Use smart default macros where appropriate:
   - \$fiscal_year_start — July 1 of current fiscal year
   - \$fiscal_year_end — June 30 of current fiscal year
   - \$today — current date
   - \$30_days_ago — 30 days before today
   - \$90_days_ago — 90 days before today
12. PERFORMANCE — ORDER BY: Do NOT add ORDER BY unless the user explicitly requests sorted or
   ranked results (e.g. "top N", "highest", "sorted by"). ORDER BY forces PostgreSQL to
   materialize the ENTIRE result set before returning any rows — even with LIMIT 100.
   OMIT ORDER BY for listing/existence/missing-field queries. KEEP it only for ranking
   (ORDER BY count DESC) or when the user explicitly asks for sorted output.
13. UUID TYPE CASTS — NEVER write ::uuid or ::text anywhere. MetaDB join columns are
   already compatible types. Explicit casts bypass PostgreSQL indexes and cause
   catastrophically slow full-table scans. Always write plain equality with no casts:
   hr.instance_id = inst.id, ii.holdings_record_id = hr.id, etc.
   ::uuid and ::text are NEVER correct in JOIN ON conditions or WHERE clauses.
14. MONETARY / FINANCIAL COLUMNS — MANDATORY: Format ALL money amounts as USD currency.
   Finance tables store amounts as NUMERIC with many decimal places.
   ALWAYS use TO_CHAR to format as a USD dollar amount with comma separators:
     TO_CHAR(inv.total, 'FM$999,999,999,990.00')
     TO_CHAR(SUM(inv.amount), 'FM$999,999,999,990.00')
   Use TO_CHAR only in the outermost SELECT (not in WHERE, JOIN, or subquery filters).
   Applies to any column from finance.*, invoice.*, or any column whose name contains:
   total, amount, price, cost, spent, encumbered, expenditure, budget, balance.
   NEVER return raw unformatted monetary values.

AVAILABLE SCHEMA (use these exact table/column names):
{$schemaContext}

RESPONSE FORMAT:
Return ONLY a JSON object (no markdown, no code blocks) with this structure:
{
  "slug": "kebab-case-name",
  "name": "Human Readable Report Name",
  "description": "What this report shows and why it's useful.",
  "category": "acquisitions|circulation|inventory|finance|users|other",
  "sqlTemplate": "SELECT ... FROM ... WHERE col = :param ...",
  "parameters": [
    {
      "name": "paramName",
      "type": "date|text|select|number|boolean|list",
      "label": "User-Facing Label",
      "required": true,
      "default": "\$fiscal_year_start",
      "placeholder": "Hint text",
      "description": "What this parameter filters",
      "wrap": "like",
      "options_sql": "SELECT DISTINCT col FROM table ORDER BY col"
    }
  ],
  "defaultLimit": 100
}
PROMPT;

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $client = new \yii\httpclient\Client();
        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl($url)
            ->setHeaders(['Content-Type' => 'application/json'])
            ->setContent(json_encode([
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                'contents' => [
                    [
                        'parts' => [['text' => "Convert this Yii2 PHP report model into a report template:\n\n{$phpCode}"]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 16384,
                ],
            ]))
            ->send();

        if (!$response->isOk) {
            $error = json_decode($response->content, true);
            $msg = $error['error']['message'] ?? 'Unknown Gemini API error';
            throw new \RuntimeException("Gemini API error: {$msg}");
        }

        $data = json_decode($response->content, true);
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            throw new \RuntimeException(
                'AI response was truncated (report too complex). Try simplifying the PHP model before converting.'
            );
        }
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return self::parseReportTemplate($text);
    }

    /**
     * Parse Gemini's report template response into a structured array.
     * @param string $text Raw Gemini response
     * @return array Parsed template definition
     */
    private static function parseReportTemplate($text)
    {
        // Strip markdown code blocks if present
        $text = preg_replace('/^```(?:json)?\s*\n?/m', '', $text);
        $text = preg_replace('/\n?```\s*$/m', '', $text);
        $text = trim($text);

        $template = json_decode($text, true);
        if (!$template || !is_array($template)) {
            throw new \RuntimeException(
                'Could not parse report template from AI response. Raw: ' . substr($text, 0, 500)
            );
        }

        // Validate required fields
        $required = ['slug', 'name', 'sqlTemplate', 'parameters'];
        foreach ($required as $field) {
            if (empty($template[$field])) {
                throw new \RuntimeException("AI response missing required field: {$field}");
            }
        }

        // Auto-fix: replace JSONB ? operator with jsonb_exists() to avoid PDO conflicts
        // PDO interprets ? as a positional parameter, breaking named params
        $template['sqlTemplate'] = preg_replace(
            "/(->'[^']+')\s*\?\s*(:[a-zA-Z_]+)/",
            'jsonb_exists($1, $2)',
            $template['sqlTemplate']
        );

        // Validate SQL safety
        SqlBuilderService::validateSafety($template['sqlTemplate']);

        // Ensure proper defaults
        $template['category'] = $template['category'] ?? 'other';
        $template['description'] = $template['description'] ?? '';
        $template['defaultLimit'] = $template['defaultLimit'] ?? 100;
        $template['createdBy'] = 'ai';

        return $template;
    }

    private static function promptNeedsTrendTimeframeClarification(string $normalizedPrompt): bool
    {
        $mentionsCirculation = strpos($normalizedPrompt, 'circulation') !== false;
        $mentionsTrend = preg_match('/\btrend\b|\btrends\b/i', $normalizedPrompt) === 1;
        if (!$mentionsCirculation || !$mentionsTrend) {
            return false;
        }

        if (self::promptHasExplicitTimeframe($normalizedPrompt)) {
            return false;
        }

        return true;
    }

    private static function promptNeedsPreviousCirculationClarification(string $normalizedPrompt): bool
    {
        if (strpos($normalizedPrompt, 'previous circulation') === false) {
            return false;
        }

        if (self::promptMentionsFormerAlephComparisonPolicy($normalizedPrompt)
            || self::promptMentionsPriorYearComparisonPolicy($normalizedPrompt)
            || self::promptMentionsCumulativeBeforeComparisonPolicy($normalizedPrompt)
        ) {
            return false;
        }

        return true;
    }

    private static function promptHasExplicitTimeframe(string $normalizedPrompt): bool
    {
        $timeframePatterns = [
            '/\b(19|20)\d{2}\b/',
            '/\blast\s+\d+\s+(day|days|week|weeks|month|months|year|years)\b/',
            '/\bthis\s+(year|month|quarter|week)\b/',
            '/\bcurrent\s+(year|month|quarter|week|fiscal year|academic year)\b/',
            '/\bfiscal year\b/',
            '/\bacademic year\b/',
            '/\bbetween\b/',
            '/\bfrom\b.*\bto\b/',
            '/\bmonthly\b/',
            '/\bweekly\b/',
            '/\bdaily\b/',
        ];

        foreach ($timeframePatterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function promptMentionsFormerAlephComparisonPolicy(string $normalizedPrompt): bool
    {
        $formerAlephPatterns = [
            '/\bformer\b/',
            '/\bhistoric\b/',
            '/\bhistorical\b/',
            '/\baleph\b/',
        ];

        foreach ($formerAlephPatterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function promptMentionsPriorYearComparisonPolicy(string $normalizedPrompt): bool
    {
        $priorYearPatterns = [
            '/\bprior year\b/',
            '/\bprevious year\b/',
            '/\byear over year\b/',
            '/\byoy\b/',
        ];

        foreach ($priorYearPatterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function promptMentionsCumulativeBeforeComparisonPolicy(string $normalizedPrompt): bool
    {
        $cumulativeBeforePatterns = [
            '/\bcumulative before\b/',
            '/\bcumulative circulation before\b/',
        ];

        foreach ($cumulativeBeforePatterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function promptMentionsContributorConstraint($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        return preg_match(
            '/\b(other author|by this contributor|by the contributor|with (?:the )?(?:corporate[- ]body )?(?:author|authors|contributor|contributors)|(?:author|authors|contributor|contributors)\s+(?:named|called|listed as|matching)|corporate[- ]body contributor)\b/i',
            $prompt
        ) === 1;
    }

    private static function resolvePromptQueryFamily($prompt, $campus = null)
    {
        $prompt = strtolower(trim((string)$prompt));
        if ($prompt === '') {
            return null;
        }

        if (self::promptMentionsMarcConstraint($prompt)) {
            return null;
        }

        if (self::promptMentionsCollectionAgeFamily($prompt)) {
            return [
                'familyKey' => 'inventory_collection_age',
            ];
        }

        if (self::promptMentionsCirculationTrendMatrixFamily($prompt)) {
            return [
                'familyKey' => 'circulation_trends_matrix',
            ];
        }

        if (self::promptMentionsTopCirculatingItemsFamily($prompt)) {
            return [
                'familyKey' => 'circulation_top_items',
            ];
        }

        if (self::promptMentionsInventoryLibraryLocationListingFamily($prompt)) {
            return [
                'familyKey' => 'inventory_library_location_listing',
            ];
        }

        if (!self::promptMentionsContributorConstraint($prompt)) {
            return null;
        }

        if (!self::promptMentionsCoveredInventoryOutputs($prompt)) {
            return null;
        }

        $hasCampusContext = !empty($campus) && $campus !== 'All Colleges';
        $mentionsCampusLikeScope = self::promptMentionsCoveredInventoryScope($prompt);
        if (!$hasCampusContext && !$mentionsCampusLikeScope) {
            return null;
        }

        return [
            'familyKey' => 'inventory_contributor_campus_item_barcode',
        ];
    }

    private static function promptMentionsCoveredInventoryScope($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        if (preg_match('/\b(campus|library|location|holdings?)\b/i', $prompt) === 1) {
            return true;
        }

        if (preg_match('/\b(at|from|for|in)\s+[a-z0-9 .\-]*college\b/i', $prompt) === 1) {
            return true;
        }

        return preg_match('/\b[a-z0-9 .\-]*college\s+(campus|library)\b/i', $prompt) === 1;
    }

    private static function promptMentionsCollectionAgeFamily($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        $mentionsAge = preg_match('/\b(average\s+age|avg\s+age|age)\b/i', $prompt) === 1;
        if (!$mentionsAge) {
            return false;
        }

        $mentionsScope = preg_match('/\b(library|location|collection|shelving)\b/i', $prompt) === 1;
        if (!$mentionsScope) {
            return false;
        }

        return preg_match('/\b(circulation|trend|trends|previous circulation|barcode|barcodes|instance number|item id|contributor|author)\b/i', $prompt) !== 1;
    }

    private static function promptMentionsCirculationTrendMatrixFamily($prompt)
    {
        if (!self::promptMentionsCirculationTrendMatrixCandidate($prompt)) {
            return false;
        }

        return preg_match('/\b(primary call number class|call number class)\b/i', $prompt) === 1;
    }

    private static function promptMentionsCirculationTrendMatrixCandidate($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        if (self::promptNeedsTrendTimeframeClarification(strtolower($prompt))) {
            return false;
        }

        if (self::promptNeedsPreviousCirculationClarification(strtolower($prompt))) {
            return false;
        }

        $mentionsCirculation = preg_match('/\b(circulation|loan|loans|checkout|checkouts)\b/i', $prompt) === 1;
        if (!$mentionsCirculation) {
            return false;
        }

        preg_match_all('/\b20\d{2}\b/', $prompt, $yearMatches);
        if (count($yearMatches[0] ?? []) < 2) {
            return false;
        }

        return preg_match('/\b(campus|library|location)\b/i', $prompt) === 1;
    }

    private static function promptMentionsTopCirculatingItemsFamily($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        $hasTopRanking = preg_match('/\b(top|most)\b/i', $prompt) === 1;
        $hasCirculationLanguage = preg_match('/\b(circulating|circulated|circulation)\b/i', $prompt) === 1;
        $hasItemConstraint = preg_match('/\b(item|items|material|materials|book(?:s)?|dvd(?:s)?|cd(?:s)?|video(?:s)?|journal(?:s)?|magazine(?:s)?|map(?:s)?|score(?:s)?|thes(?:is|es)|dissertation(?:s)?)\b/i', $prompt) === 1;

        return $hasTopRanking
            && $hasCirculationLanguage
            && $hasItemConstraint
            && preg_match('/\b(campus|library|location)\b/i', $prompt) === 1;
    }

    private static function promptMentionsInventoryLibraryLocationListingFamily($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        if (self::promptMentionsContributorConstraint($prompt)) {
            return false;
        }

        if (!self::promptMentionsCoveredInventoryOutputs($prompt)) {
            return false;
        }

        if (!self::promptMentionsCoveredInventoryScope($prompt)) {
            return false;
        }

        $hasListingLanguage = preg_match('/\b(list|listing|show|create)\b/i', $prompt) === 1;
        $hasInventoryNoun = preg_match('/\b(material|materials|item|items)\b/i', $prompt) === 1;

        if (!$hasListingLanguage || !$hasInventoryNoun) {
            return false;
        }

        return preg_match('/\b(top|circulating|circulation|average\s+age|avg\s+age|loan|checkout|trend|trends)\b/i', $prompt) !== 1;
    }

    private static function promptMentionsCoveredInventoryOutputs($prompt)
    {
        return preg_match('/\b(barcode|barcodes|item id|item ids|instance number|instance numbers|publication date|pub date|title|titles)\b/', (string)$prompt) === 1;
    }

    private static function promptMentionsMarcConstraint($prompt)
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        return preg_match('/\bmarc\b|\bfield\s*[0-9]{3}\b|\b[0-9]{3}\s*field\b|\b[0-9]xx\s+fields?\b/i', $prompt) === 1;
    }

    private static function buildQueryFamilySlotSystemPrompt($familyKey, $campus)
    {
        $contracts = QueryFamilyContractService::loadContracts();
        $contract = $contracts[$familyKey] ?? null;
        if (!is_array($contract)) {
            throw new \RuntimeException('Missing query family contract for slot extraction: ' . $familyKey);
        }

        $campusRule = '';
        if ($campus && $campus !== 'All Colleges') {
            $safeCampus = str_replace("'", "''", (string)$campus);
            $campusRule = <<<RULE

CAMPUS SLOT DEFAULT:
- The user's home institution is '{$safeCampus}'.
- If the prompt does not name another campus explicitly, set slots.campus to '{$safeCampus}'.
RULE;
        }

        $requiredSlots = json_encode(array_values($contract['slots']['required'] ?? []), JSON_UNESCAPED_SLASHES);
        $supportedSlots = json_encode(array_values($contract['slots']['supported'] ?? []), JSON_UNESCAPED_SLASHES);
        $allowedOutputs = json_encode(array_values($contract['outputs']['allowed'] ?? []), JSON_UNESCAPED_SLASHES);
        $matchPolicies = json_encode(array_values($contract['matchPolicy']['supported'] ?? []), JSON_UNESCAPED_SLASHES);
        $defaultMatchPolicy = trim((string)($contract['matchPolicy']['default'] ?? 'case_insensitive_contains'));
        $slotContract = self::buildQueryFamilySlotPromptContract($contract, $defaultMatchPolicy);

        return <<<PROMPT
You are a deterministic query-family slot extractor for a FOLIO inventory workflow.

Return ONLY a JSON object matching this contract:
{$slotContract}

Rules:
1. Use only the family key {$familyKey}.
2. Required slots: {$requiredSlots}
3. Supported slots: {$supportedSlots}
4. Allowed outputs: {$allowedOutputs}
5. Supported match policies: {$matchPolicies}
6. Choose exact_phrase when the prompt uses quotation marks or wording such as named, listed as, or called for a contributor or other named entity; otherwise use {$defaultMatchPolicy}.
7. Do NOT return tables, joins, SQL operators, SQL snippets, raw schema names, or query objects.
8. Do NOT include markdown, code fences, or commentary.
{$campusRule}
PROMPT;
    }

    private static function buildQueryFamilySlotPromptContract(array $contract, string $defaultMatchPolicy): string
    {
        $requiredSlots = array_fill_keys(array_values($contract['slots']['required'] ?? []), true);
        $supportedSlots = array_values($contract['slots']['supported'] ?? []);

        $lines = [];
        $lines[] = '{';
        $lines[] = '    "familyKey": "' . ($contract['familyKey'] ?? '') . '",';
        $lines[] = '    "slots": {';

        $slotLines = [];
        foreach ($supportedSlots as $slotName) {
            if ($slotName === 'year_buckets') {
                $slotLines[] = '        "' . $slotName . '": ["' . (isset($requiredSlots[$slotName]) ? 'required years' : 'optional years') . '"]';
                continue;
            }

            $slotLines[] = '        "' . $slotName . '": "' . (isset($requiredSlots[$slotName]) ? 'required string' : 'optional string') . '"';
        }
        $slotLines[] = '        "requested_outputs": ["one or more allowed outputs"]';
        $slotLines[] = '        "match_policy": "' . $defaultMatchPolicy . '"';

        $lastIndex = count($slotLines) - 1;
        foreach ($slotLines as $index => $slotLine) {
            $lines[] = $slotLine . ($index === $lastIndex ? '' : ',');
        }

        $lines[] = '    }';
        $lines[] = '}';

        return implode("\n", $lines);
    }
}
