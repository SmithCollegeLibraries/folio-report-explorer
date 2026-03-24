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

    /**
     * Generate SQL from a natural-language prompt.
     *
     * @param string $prompt User's natural language query description
     * @return array {sql: string, explanation: string, dataSource: string}
     * @throws \RuntimeException
     */
    public static function generateSql($prompt, $campus = null)
    {
        $apiKey = Yii::$app->params['geminiApiKey'];
        if (empty($apiKey)) {
            throw new \RuntimeException(
                'Gemini API key not configured. Set GEMINI_API_KEY in .env'
            );
        }

        $model = Yii::$app->params['geminiModel'] ?: 'gemini-2.5-flash';
        $schemaContext = FolioSchemaService::buildSchemaContext();

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
19. UUID TYPE CASTS — Write plain equality with NO casts. Do not write ::text or ::uuid
    anywhere. The query post-processor reads the live schema and adds ::text casts ONLY
    on the specific columns that need them (where one side is uuid and the other is text).
    Unnecessary casts destroy index usage and cause catastrophically slow queries.
    Simply write plain equality:
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
    ::uuid is NEVER correct anywhere in this system.
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
{$campusRule}

SCHEMA:
{$schemaContext}

RESPONSE FORMAT:
Return the SQL in a ```sql code block, followed by a brief plain-English explanation
of what the query does and which tables/joins are used.
Then add a final line exactly like: DATA SOURCE: folio OR DATA SOURCE: local
PROMPT;

        $url = self::API_BASE . "/{$model}:generateContent?key={$apiKey}";

        $client = new Client();
        $client->transport = 'yii\httpclient\CurlTransport';
        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl($url)
            ->setHeaders(['Content-Type' => 'application/json'])
            ->addOptions([CURLOPT_TIMEOUT => 120])
            ->setContent(json_encode([
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
                    'maxOutputTokens' => 4096,
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
            Yii::warning("Gemini NL→SQL response truncated (MAX_TOKENS). Consider reducing schema context size.");
        }
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return self::parseResponse($text);
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
     * Post-process generated SQL to add ::text casts ONLY where column types
     * genuinely differ (uuid vs text), preventing "operator does not exist: uuid = text"
     * errors while avoiding unnecessary casts that kill index usage.
     *
     * Strategy:
     *  1. Parse FROM/JOIN clauses to build alias → schema.table map
     *  2. Look up both column types from column_cache.json
     *  3. Cast to ::text only when types differ; leave matching types alone
     *  4. Fall back to name-heuristic (_id / .id) when types are unknown
     */
    private static function normalizeIdCasts(string $sql): string
    {
        // Remove all existing casts so we re-evaluate from scratch
        $sql = preg_replace('/::uuid\b/i', '', $sql);
        $sql = preg_replace('/::text\b/i', '', $sql);

        // Build type lookup: schema.table => [column => type]
        $allColumns = FolioSchemaService::discoverAllColumns();
        $typeMap = [];
        foreach ($allColumns as $table => $cols) {
            $tl = strtolower($table);
            foreach ($cols as $col) {
                $typeMap[$tl][strtolower($col['name'])] = $col['type'];
            }
        }

        // Extract alias → schema.table from FROM/JOIN clauses
        // Matches: FROM schema.table AS alias  or  JOIN schema.table alias
        $aliasMap = [];
        if (preg_match_all(
            '/\b(?:FROM|JOIN)\s+([\w]+\.[\w]+)\s+(?:AS\s+)?(\w+)\b/i',
            $sql, $matches, PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $aliasMap[strtolower($m[2])] = strtolower($m[1]);
            }
        }

        // Resolve alias.column → type from the map; null = unknown
        $resolveType = static function (string $alias, string $col) use ($aliasMap, $typeMap): ?string {
            $table = $aliasMap[strtolower($alias)] ?? null;
            if ($table === null) {
                return null;
            }
            return $typeMap[$table][strtolower($col)] ?? null;
        };

        // Apply casts only where needed
        $sql = preg_replace_callback(
            '/(\b(\w+)\.(\w+))\s*=\s*(\b(\w+)\.(\w+))/',
            static function (array $m) use ($resolveType): string {
                $left      = $m[1];
                $leftAlias = $m[2];
                $leftCol   = $m[3];
                $right     = $m[4];
                $rightAlias = $m[5];
                $rightCol   = $m[6];

                $leftType  = $resolveType($leftAlias, $leftCol);
                $rightType = $resolveType($rightAlias, $rightCol);

                // Both types known and identical — no cast needed
                if ($leftType !== null && $rightType !== null && $leftType === $rightType) {
                    return $m[0];
                }

                // Types differ (uuid vs text) — cast both to text
                if ($leftType !== null && $rightType !== null && $leftType !== $rightType) {
                    return $left . '::text = ' . $right . '::text';
                }

                // Unknown types: fall back to column-name heuristic
                $leftIsId  = (bool) preg_match('/(_id|\.id)$/i', $left);
                $rightIsId = (bool) preg_match('/(_id|\.id)$/i', $right);
                if ($leftIsId || $rightIsId) {
                    return $left . '::text = ' . $right . '::text';
                }

                return $m[0];
            },
            $sql
        );

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
14. UUID TYPE CASTS — MANDATORY: NEVER cast to ::uuid. ALWAYS cast to ::text.
    In LDP/MetaDB, large fact tables store FK columns as TEXT while reference table PKs are UUID.
    Casting to ::uuid causes "operator does not exist: uuid = text".
    Always cast the UUID PK side to TEXT: hr.instance_id = inst.id::text,
    ii.holdings_record_id = hr.id::text, ii.effective_location_id = loc.id::text.
    Lookup-to-lookup joins (loc.library_id = lib.id, lib.campus_id = camp.id) are UUID=UUID.
    ::uuid is NEVER the correct cast anywhere in this system.
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
13. UUID TYPE CASTS — MANDATORY: NEVER cast to ::uuid. ALWAYS cast to ::text.
   In LDP/MetaDB, large fact tables store FK columns as TEXT while reference table PKs are UUID.
   Casting to ::uuid causes "operator does not exist: uuid = text".
   Always cast the UUID PK side to TEXT: hr.instance_id = inst.id::text,
   ii.holdings_record_id = hr.id::text, ii.effective_location_id = loc.id::text.
   Lookup-to-lookup joins (loc.library_id = lib.id, lib.campus_id = camp.id) are UUID=UUID.
   ::uuid is NEVER the correct cast anywhere in this system.
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
}
