# Gemini Pipeline Hardening Tickets

## Workflow Rule
- When a ticket is completed, create a timestamped update file in [updates](../updates).
- Naming format: `YYYY-MM-DD_HH-MM-SS_ticket-id_short-title.md`
- Each update file must include: summary, files changed, validation evidence, blockers/risks, and next ticket.

## Ticket List

### NL2SQL-000 - Baseline Capture
- Status: IN REVIEW
- Source step: Step 0
- Scope:
  - Capture 10 baseline prompts and outputs.
  - Document at least 2 known failure cases.
- Validation gate:
  - Baseline dataset exists and is reviewable.
  - Failure cases documented and reproducible.
- Completion update file required: Yes
- Progress notes:
  - Prompt set created at [planning/baseline/NL2SQL-000-prompts.json](baseline/NL2SQL-000-prompts.json).
  - Runbook created at [planning/baseline/NL2SQL-000-runbook.md](baseline/NL2SQL-000-runbook.md).
  - Capture script created at [planning/baseline/capture_nl_baseline.sh](baseline/capture_nl_baseline.sh).
  - Capture script smoke-test fails cleanly when API is unavailable.
  - Runtime blocker resolved: PHP Docker image now builds and stack starts successfully.
  - Baseline run artifact created at [planning/baseline/outputs/2026-04-06_09-43-52_nl2sql-000-baseline-results.json](baseline/outputs/2026-04-06_09-43-52_nl2sql-000-baseline-results.json).
  - New blocker: Gemini free-tier input token quota exhausted after 2 prompts, preventing full 10-prompt quality capture in one run.
  - Subset capture support added to script (`P01,P02` style filtering).
  - New subset artifact created at [planning/baseline/outputs/2026-04-06_10-11-10_nl2sql-000-baseline-results.json](baseline/outputs/2026-04-06_10-11-10_nl2sql-000-baseline-results.json) with both prompts successful.
  - Additional subset artifacts captured for P03..P10 in 2-prompt batches.
  - Consolidated 10-prompt artifact created at [planning/baseline/outputs/2026-04-06_10-20-49_nl2sql-000-merged-results.json](baseline/outputs/2026-04-06_10-20-49_nl2sql-000-merged-results.json).
  - Failure evidence documented at [planning/baseline/NL2SQL-000-failure-evidence.md](baseline/NL2SQL-000-failure-evidence.md).

### NL2SQL-001 - Central SQL Safety Enforcement
- Status: COMPLETED
- Source step: Step 1
- Scope:
  - Multi-statement rejection.
  - Blocked table/schema policy checks in execute and submit endpoints.
- Validation gate:
  - Two-statement SQL rejected.
  - Blocked-table SQL rejected from all execution paths.
  - Valid single SELECT still works.
- Completion update file required: Yes
- Progress notes:
  - Added multi-statement detection to SQL safety validation.
  - Added centralized blocked schema/table policy validation.
  - Enforced policy in `/api/execute` and `/api/query/submit`.
  - Enforced policy on NL generation parse path (`/api/nl`) with clean 403 error handling.
  - Validation checks passed for all three gate criteria.
  - Frontend Ask page now shows blocked NL responses as `Query blocked: <reason>` instead of generic Axios 403 text.
  - Follow-up update logged at [updates/2026-04-06_10-39-08_NL2SQL-001_blocked-query-message-improved.md](../updates/2026-04-06_10-39-08_NL2SQL-001_blocked-query-message-improved.md).

### NL2SQL-002 - Builder Identifier Validation
- Status: COMPLETED
- Source step: Step 2
- Scope:
  - Validate columns/groupBy/orderBy/having identifiers.
  - Validate alias format.
- Validation gate:
  - Good query definitions pass.
  - Bad identifiers fail with clear errors.
- Completion update file required: Yes
- Progress notes:
  - Added centralized query-definition validation for `columns`, `filters`, `groupBy`, `orderBy`, `having`, and explicit `joins` references.
  - Added strict alias/identifier format validation using an SQL identifier regex.
  - Added table-scope validation so clause tables must exist in the top-level `tables` list.
  - Added per-table column lookup validation via schema metadata discovery.
  - Confirmed valid query definitions still build and invalid identifiers return clear 400 errors.

### NL2SQL-003 - Query Intent Contract
- Status: COMPLETED
- Source step: Step 3
- Scope:
  - Define and validate QueryIntent contract.
  - Translate intent to builder query definition.
- Validation gate:
  - Valid intents map cleanly.
  - Invalid intents return structured validation errors.
- Completion update file required: Yes
- Progress notes:
  - Added new `QueryIntentService` with QueryIntent v1 contract definition.
  - Added structured validator returning clause/path-specific error payloads.
  - Added translator from QueryIntent to `SqlBuilderService::build()` query-definition format.
  - Added `QueryIntentValidationException` for structured contract failures.
  - Added additive GeminiService helper methods (`validateQueryIntent`, `intentToQueryDefinition`) without changing current NL runtime path.
  - Valid intents now translate cleanly; invalid intents return structured validation errors.

### NL2SQL-004 - Gemini Structured Output (Flagged)
- Status: COMPLETED
- Source step: Step 4
- Scope:
  - Add feature flag.
  - Use structured JSON output for intent path.
- Validation gate:
  - Flag OFF keeps legacy behavior.
  - Flag ON returns valid intent JSON.
- Completion update file required: Yes
- Progress notes:
  - Added `nl2sqlIntentMode` parameter wiring in backend params from settings/env.
  - Added `nl2sql_intent_mode` to settings display and settings save whitelist.
  - Added feature-flagged structured intent generation path in `GeminiService::generateSql`.
  - Added Gemini structured JSON request config (`responseMimeType: application/json`).
  - Added robust structured-response parsing with markdown-fence tolerance and balanced JSON extraction fallback.
  - Added clean runtime errors for malformed/invalid intent payloads.
  - Added QueryIntent -> builder -> SQL conversion with safe literal inlining for existing raw-SQL execution flow compatibility.
  - Legacy freeform SQL generation path remains unchanged when flag is OFF.

### NL2SQL-005 - Deterministic Router
- Status: COMPLETED
- Source step: Step 5
- Scope:
  - Server-side capability routing to builder/fallback.
- Validation gate:
  - Stable routing for repeated prompts.
  - Safe fallback for unsupported constructs.
- Completion update file required: Yes
- Progress notes:
  - Added deterministic capability classifier in `GeminiService` based on normalized intent content.
  - Implemented server-side routing:
    - supported intents -> builder route (`builder_intent`)
    - unsupported intents -> legacy fallback route (`legacy_fallback`)
  - Added fallback path for builder conversion failures to keep unsupported constructs on the safe legacy path.
  - Added route metadata to NL responses (`route`, `routeReason`).
  - Added structured routing logs (`nl2sql.routing`) with route, reason, and intent summary.
  - Ensured both builder and fallback routes continue through centralized SQL safety/table-policy checks.

### NL2SQL-006 - Deterministic Context and Retry Policy
- Status: COMPLETED
- Source step: Step 6
- Scope:
  - Deterministic hint ordering and bounded context.
  - Retry policy for transient API failures.
- Validation gate:
  - Stable prompt inputs.
  - Retries only for transient failures.
- Completion update file required: Yes
- Progress notes:
  - Made domain-hint loading deterministic with explicit ordering and latest-active dedupe in `FolioSchemaService::loadDomainHints`.
  - Added prompt-term extraction and bounded relevance scoring in schema-context assembly to cap examples/vocabulary deterministically.
  - Updated `FolioSchemaService::buildSchemaContext` to accept optional prompt text and apply relevance caps.
  - Updated `GeminiService` NL generation paths (legacy + intent) to call prompt-aware schema context.
  - Added retry/backoff request wrapper for Gemini calls with transient-only retry logic (408/429 rate-limit/5xx/timeouts/network).
  - Added explicit non-retry behavior for quota/billing style 429 failures.
  - Added retry and terminal outcome telemetry logs under `nl2sql.retry` with attempts, elapsed time, status, and timeout flags.
  - Verified no syntax or editor diagnostics errors in updated service files.

### NL2SQL-007 - Observability and Regression Harness
- Status: IN PROGRESS
- Source step: Step 7
- Scope:
  - Add route/version telemetry.
  - Build replay harness with pass/fail report.
- Validation gate:
  - Full baseline replay report generated.
  - Threshold agreed.
- Completion update file required: Yes
- Progress notes:
  - Added structured NL telemetry logging in `GeminiService` under `nl2sql.telemetry`.
  - Telemetry now includes model, prompt version, route/routeReason, finishReason, prompt fingerprint, and schema-context hash/version.
  - Added structured validation-failure telemetry (`nl2sql.validation_failure`) for malformed/invalid intent and SQL parse failures.
  - Added replay harness script at `planning/baseline/replay_nl_regression.sh`.
  - Added threshold definition at `planning/baseline/NL2SQL-007-threshold.md`.
  - Generated replay artifacts:
    - `planning/baseline/outputs/2026-04-06_11-51-11_nl2sql-007-replay-results.json`
    - `planning/baseline/reports/2026-04-06_11-51-11_nl2sql-007-replay-report.md`
  - Latest replay summary: total=10, pass=7, fail=3, passRate=70%, regressionsOnBaselineSuccess=3, gateMet=false.
  - Current replay failures are quota-driven Gemini errors for baseline-success prompts (P03, P04, P07).

### NL2SQL-008 - Shadow Mode and Cutover
- Status: IN PROGRESS
- Source step: Step 8
- Scope:
  - Shadow mode, compare outputs, controlled cutover.
- Validation gate:
  - Metrics pass for required period.
  - Emergency rollback toggle verified.
- Completion update file required: Yes
- Progress notes:
  - Added Step 8 runtime controls in settings/params:
    - `nl2sql_primary_mode` (`auto|legacy|intent`)
    - `nl2sql_shadow_mode` (bool)
    - `nl2sql_shadow_users` (comma-separated allowlist or `all`)
    - `nl2sql_shadow_sample_percent` (0..100)
    - `nl2sql_force_legacy` (emergency rollback toggle)
  - Added `GeminiService::generateSqlWithShadow(...)` to run a configurable primary mode and optional shadow comparison.
  - Added non-blocking shadow comparison telemetry event `nl2sql.shadow_compare` and shadow execution failures `nl2sql.shadow_error`.
  - Wired `/api/nl` to the new shadow-aware entrypoint while preserving response shape.
  - Verified endpoint still returns valid SQL output and route metadata after wiring.
  - Verified emergency rollback toggle live:
    - with `nl2sql_primary_mode=intent` and `nl2sql_force_legacy=false`, sample prompt returned an intent-validation error.
    - switching `nl2sql_force_legacy=true` immediately restored successful SQL generation with `route=legacy_freeform` and `routeReason=forced_legacy_mode`.
  - Ran a one-request shadow smoke with `primary_mode=legacy`, `shadow_mode=true`, `shadow_users=all`, and `shadow_sample_percent=100`; primary response succeeded and emitted shadow telemetry.
  - Added daily Step 8 operations artifacts:
    - `planning/baseline/NL2SQL-008-shadow-operations-checklist.md`
    - `planning/baseline/report_nl2sql_shadow_metrics.sh`
    - `planning/baseline/reports/2026-04-06_nl2sql-008-shadow-metrics.md`

### NL2SQL-100 - Optional Index Recommendation Track
- Status: IN PROGRESS (user-directed early implementation)
- Source step: Optional track
- Scope:
  - Query-history-based index recommendations.
- Validation gate:
  - Core pipeline is stable post-cutover.
- Completion update file required: Yes
- Progress notes:
  - Added workload snapshot service `backend/services/IndexRecommendationService.php`.
  - Snapshot logic now aggregates completed `query_jobs` history by normalized SQL pattern and captures frequency/latency signals.
  - Added Postgres existing-index introspection (`pg_indexes`) scoped to tables observed in history.
  - Added Gemini recommendation method `GeminiService::recommendIndexesFromHistory(...)` with structured JSON contract.
  - Added endpoint `POST /api/query/index-recommendations` in `FolioQueryController`.
  - Added resilient fallback behavior: if Gemini output is malformed/unavailable, endpoint still returns workload summary + warnings instead of hard-failing.
  - Added deterministic heuristic fallback recommendations from query-history JOIN/WHERE/ORDER-BY signals when Gemini output is empty or malformed.
  - Endpoint now reports `recommendationSource` (`gemini|heuristic|none`) and can return non-empty suggestions even during AI JSON failures.
  - Added frontend API/types and History-page UI trigger to generate and display recommendations.
