# Gemini Pipeline Hardening Plan (Agent-Friendly, Gate-Based)

## Goal
Improve NL-to-SQL consistency, safety, and maintainability by moving from freeform SQL generation to a structured intent pipeline with deterministic SQL assembly.

## Execution Rules (apply to every step)
- Keep each step in a separate PR or isolated commit.
- Do not start the next step until all validation checks for the current step pass.
- If a validation check fails, stop and fix only that step.
- Keep behavior changes minimal per step.
- Record results for each validation check in PR notes.
- When a step/ticket is completed, create a timestamped markdown update file in [updates](../updates).
- Update filename format: `YYYY-MM-DD_HH-MM-SS_ticket-id_short-title.md`.
- Required sections in each update file: Summary, Files Changed, Validation Evidence, Open Risks, Next Step.

## Branch Strategy
- Recommended working branch pattern: `feat/nl2sql-step-XX-short-name`
- One step per branch.

## Step 0 - Baseline and Safety Net

### Objective
Establish reproducible baseline behavior before code changes.

### Small Tasks
1. Confirm current NL endpoint behavior and capture 10 representative prompts.
2. Save current outputs: generated SQL, data source, and execution status.
3. Capture known failures (multi-statement output, blocked table access attempts).
4. Verify current limits and safety behavior for `/api/execute` and `/api/query/submit`.

### Validation Gate (must pass)
- Baseline fixture file with 10 prompts and outputs exists in internal notes or issue tracker.
- At least 2 known failure examples are documented.
- Team agrees baseline set is sufficient for regression checks.

### Rollback
- No code changes in this step.

---

## Step 1 - Strengthen SQL Safety in One Place

### Objective
Ensure all execution paths enforce core SQL safety rules consistently.

### Small Tasks
1. Extend `SqlBuilderService::validateSafety()` to reject multiple statements.
2. Add helper to normalize trailing semicolons before statement-count checks.
3. Add table-policy checker that rejects blocked schemas/tables from SQL text.
4. Call the same checker from both:
   - `/api/execute`
   - `/api/query/submit`
5. Return clear 4xx errors for policy violations.

### Files
- `backend/services/SqlBuilderService.php`
- `backend/controllers/FolioQueryController.php`
- (optional) `backend/services/FolioSchemaService.php` for policy lists/helper reuse

### Validation Gate (must pass)
- Query with two statements is rejected.
- Query referencing blocked table is rejected from both execute endpoints.
- Normal single SELECT still executes.
- No new PHP syntax errors.

### Suggested Checks
- `php -l backend/services/SqlBuilderService.php`
- `php -l backend/controllers/FolioQueryController.php`

### Rollback
- Revert only safety changes in this step.

---

## Step 2 - Tighten Builder Identifier Validation

### Objective
Make structured SQL assembly reject unknown or unsafe identifiers early.

### Small Tasks
1. Validate columns exist for each resolved table in `build()` paths.
2. Validate `groupBy`, `orderBy`, and `having` column references.
3. Validate alias names with a strict identifier regex.
4. Keep fuzzy table matching, but fail closed for unknown columns.
5. Improve error messages (table + column + clause).

### Files
- `backend/services/SqlBuilderService.php`
- `backend/services/FolioSchemaService.php` (if helper needed)

### Validation Gate (must pass)
- Known-good query definition builds successfully.
- Query definition with bad column fails with explicit error.
- Query definition with bad alias fails with explicit error.
- Existing valid query definitions still build.

### Rollback
- Revert identifier-validation additions only.

---

## Step 3 - Define Query Intent Contract (No Gemini Change Yet)

### Objective
Create a strict server-side intent schema and validator before changing model calls.

### Small Tasks
1. Define `QueryIntent` PHP structure (required and optional keys).
2. Ensure intent keys map exactly to builder fields.
3. Decide v1 join strategy:
   - Prefer auto joins only, or
   - explicit joins with full join columns.
4. Add server-side intent validator with actionable errors.
5. Add translator from intent to builder query definition.

### Files
- `backend/services/GeminiService.php` (or new intent service)
- `backend/services/SqlBuilderService.php` (if mapping helper added)

### Validation Gate (must pass)
- Valid sample intents convert to query definitions successfully.
- Invalid intents fail with structured errors.
- No runtime dependency on Gemini yet for this step.

### Rollback
- Revert intent schema/validator code only.

---

## Step 4 - Add Gemini Structured Output Mode Behind a Flag

### Objective
Switch NL generation to structured JSON output safely behind feature flag.

### Small Tasks
1. Add feature flag (example: `NL2SQL_INTENT_MODE=true|false`).
2. Add Gemini request config for structured JSON output.
3. Replace freeform SQL extraction path when flag is enabled.
4. Keep existing raw SQL path unchanged when flag is disabled.
5. Add robust parse/error handling for malformed JSON.

### Files
- `backend/services/GeminiService.php`
- `backend/config/params.php` (or env wiring)

### Validation Gate (must pass)
- Flag OFF: existing NL behavior unchanged.
- Flag ON: returns valid intent JSON for test prompts.
- Malformed model response returns clean error, no fatal.

### Rollback
- Disable feature flag immediately.
- Revert structured output code if needed.

---

## Step 5 - Deterministic Router (Intent -> Builder or Fallback)

### Objective
Make server decide routing deterministically; do not rely on model self-rating.

### Small Tasks
1. Implement capability classifier based on intent content.
2. Route supported intents to builder.
3. Route unsupported constructs to fallback path.
4. Record selected route in response metadata/logging.
5. Ensure both routes pass through centralized safety checks.

### Files
- `backend/services/GeminiService.php`
- `backend/controllers/FolioQueryController.php`

### Validation Gate (must pass)
- Same prompt repeated 5 times routes consistently.
- Supported prompts produce deterministic SQL via builder.
- Unsupported prompt uses fallback and still validates safely.

### Rollback
- Force routing to legacy path via flag.

---

## Step 6 - Deterministic Prompt Inputs and Retry Policy

### Objective
Reduce output variance and transient API failures.

### Small Tasks
1. Add deterministic ordering to training-hints query.
2. Bound context size (cap examples and hints by relevance).
3. Add bounded retry with backoff for 429/5xx/timeouts.
4. Keep non-retryable failures explicit.
5. Add timeout and retry metrics to logs.

### Files
- `backend/services/FolioSchemaService.php`
- `backend/services/GeminiService.php`

### Validation Gate (must pass)
- Hint ordering is stable across runs.
- Retry triggers only on transient failures.
- No duplicate execution side effects.

### Rollback
- Disable retry logic with config toggle.

---

## Step 7 - Observability and Regression Harness

### Objective
Track quality/cost and detect regressions quickly.

### Small Tasks
1. Log model, prompt version, route, finish reason, and validation failures.
2. Add schema-context hash/version to logs.
3. Build a replay harness for baseline prompt set.
4. Compare old vs new SQL/results in report format.
5. Define acceptance threshold for cutover.

### Files
- `backend/services/GeminiService.php`
- `backend/models/QueryLog.php` (if schema changes needed)
- optional migration files (if new columns required)

### Validation Gate (must pass)
- Replay harness runs all baseline prompts.
- Report produced with pass/fail summary.
- Acceptance threshold approved by team.

### Rollback
- Keep logs additive and non-blocking.

---

## Step 8 - Shadow Mode and Controlled Cutover

### Objective
Adopt new pipeline with low risk.

### Small Tasks
1. Run old and new pipelines in shadow mode for selected users.
2. Compare output quality and failure rates daily.
3. Fix high-severity mismatches first.
4. Enable new mode by default when threshold is met.
5. Keep emergency toggle to revert instantly.

### Validation Gate (must pass)
- Shadow metrics meet threshold for at least 3 consecutive days.
- No unresolved critical safety issues.
- Team signoff recorded.

### Rollback
- Toggle back to legacy mode immediately.

---

## Optional Track (After Cutover): Index Recommendations

### Objective
Implement query-history-based index suggestions as a separate project.

### Why separate
This is valuable but should not block NL-to-SQL stability work.

### Validation Gate
- Only begin once Step 8 is complete and stable.

---

## Definition of Done (Program Level)
- Multi-statement SQL generation issue is eliminated or blocked before execution.
- Blocked tables/schemas cannot be queried through any endpoint path.
- Repeated prompts produce stable routing and SQL in supported scenarios.
- New pipeline has clear telemetry and rollback controls.
- Cutover completed with documented acceptance metrics.
