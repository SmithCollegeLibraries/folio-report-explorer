# Gemini Pipeline Hardening Tickets

## Workflow Rule
- When a ticket is completed, create a timestamped update file in [updates](../updates).
- Naming format: `YYYY-MM-DD_HH-MM-SS_ticket-id_short-title.md`
- Each update file must include: summary, files changed, validation evidence, blockers/risks, and next ticket.

## Current Main State
- `origin/main` now includes the deterministic query-family slice at commit `1bc3f36`, including the checked-in canonical query graph artifact, query-family contracts, slot validation, compiler, and Gemini family routing/shape validation.
- The focused query-family validation pack is currently green on `main`: `QueryFamilyContractServiceTest`, `QueryFamilySlotServiceTest`, `QueryFamilyCompilerServiceTest`, `GeminiServiceQueryFamilySelectionTest`, `GeminiServiceFamilyCompilerFallbackTest`, `GeminiServiceFamilyCompilerResultTest`, `GeminiServiceFamilyIntentBranchTest`, `GeminiServiceFamilyMatchPolicyTest`, `GeminiServiceFamilyShapeValidationTest`, and `GeminiServiceIntentRequestPathTest`.
- Treat this clean `main` checkout as the source of truth for planning and future work; archived local-only diffs are no longer authoritative for ticket status.
- The current local workspace also contains unmerged hardening slices for `NL2SQL-009`, `NL2SQL-010`, and `NL2SQL-011`. Treat those ticket entries as workspace status until they are committed and shipped.

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
- Status: COMPLETED
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
  - Step 7 remains closed; the current status sync is recorded in [updates/2026-05-11_14-31-00_NL2SQL-007_step7-status-sync-on-main.md](../updates/2026-05-11_14-31-00_NL2SQL-007_step7-status-sync-on-main.md).
  - The deterministic query-family slice now lives on `origin/main` at `1bc3f36`, and the focused query-family validation pack is green on that landed runtime.
  - `NL2SQL-008` remains the active release gate: Step 8 still needs two more qualifying shadow days before cutover eligibility.

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
  - On-campus live validation resumed on 2026-05-11 and produced another qualifying Step 8 day on the current query-family slice.
  - Current Step 8 position: qualifying streak `1`, remaining qualifying days `2`, `shadow_error events=0`, `dataSourceMismatchCount=0`, and no forced-legacy activations on the latest controlled pass.
  - Regenerated [planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md](baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md) after fixing web telemetry capture; it now reports `Events scanned: 1`, `shadow_compare events: 1`, `shadow_error events: 0`, `SQL hash mismatch count: 1`, and `Data source mismatch count: 0` for 2026-05-12.
  - Earlier same-day no-event results were traced to the local web logging config: `GeminiService::logShadowComparison(...)` emits `nl2sql.shadow_compare` through `nl2sql.telemetry` at info level, but the web log target only captured warnings/errors, so successful compares never reached `backend/runtime/logs/app.log` for the daily report script.
  - Added a dedicated info-level `nl2sql.telemetry` web file target and disabled web `logVars` so future shadow metrics are written without Yii request-context dumps of cookies or environment variables.
  - Added focused regression coverage in `backend/tests/WebLogConfigRedactionTest.php`; validated with `php backend/tests/WebLogConfigRedactionTest.php` and `php -l backend/config/web.php`.
  - After test keys were added locally and the `php`, `worker`, and `export-worker` containers were recreated, a controlled local smoke returned SQL via `route=legacy_freeform`, logged provider-fallback warnings plus one `nl2sql.shadow_compare` event with `primaryRoute=legacy_freeform`, `shadowRoute=builder_intent`, and `sqlHashMatch=false`, and appended no new `$_SERVER` request-context dump in the new log region.
  - Enhanced `planning/baseline/report_nl2sql_shadow_metrics.sh` so the daily Step 8 report now includes route divergence counts, provider-fallback warning counts, top route pairs, and a latest-compare snapshot instead of only raw hash counts.
  - Current local Step 8 signal is now explicit in the report: `legacy_freeform -> builder_intent` route divergence `1`, provider fallback warning count `18`, and the latest compare belongs to the covered `inventory_collection_age` family with `sqlHashMatch=false` but `Data source mismatch count=0` and `shadow_error events=0`.
  - The report now also elevates covered-family `legacy_freeform -> builder_intent` compares into a dedicated blocker class. The regenerated 2026-05-12 report shows `Covered-family legacy-primary mismatch count: 1` with the affected family broken out as `inventory_collection_age`.
  - The Step 8 gate worksheet is now fail-closed instead of manual: the regenerated 2026-05-12 report marks `Required period day status: BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH`, `Compare/error trend acceptable: NO`, and `Covered-family legacy-primary mismatches acceptable: NO`.
  - `GeminiService::generateSqlWithShadow(...)` now upgrades covered-family prompts from configured legacy primary to deterministic intent primary unless `nl2sqlForceLegacy` is explicitly enabled.
  - Focused validation passed via `php backend/tests/GeminiServiceShadowModePolicyTest.php`, `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`, and `php -l backend/services/GeminiService.php`.
  - Live validation against `/api/nl` with `nl2sql_primary_mode=legacy`, `nl2sql_shadow_mode=true`, and `nl2sql_force_legacy=false` now returns the Neilson Reference collection-age prompt on `route=builder_intent` with `routeReason=family_contract_supported:inventory_collection_age`.
  - Further collection-age hardening fixed the deterministic scope canonicalization for the Neilson Reference prompt: malformed non-empty family slots are now repaired from prompt text, and explicit `Reference collection` location labels are no longer widened back to a generic `%Reference%` location filter during slot matching.
  - Focused validation passed via `php backend/tests/QueryFamilySlotServiceTest.php`, `php backend/tests/QueryFamilyCompilerServiceTest.php`, `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`, `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`, `php backend/tests/GeminiServiceShadowModePolicyTest.php`, `php -l backend/services/GeminiService.php`, and `php -l backend/services/QueryFamilySlotService.php`.
  - Live `/api/nl` validation for `What is the average age of items in the Neilson Reference collection?` now returns deterministic SQL scoped with `il.name ILIKE '%Neilson Library%'` and `ilo.name ILIKE '%Reference collection%'`, eliminating the earlier false-positive broadening to `%Reference%`.
  - Regenerated [planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md](baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md) after the scope fix; it now reports `Events scanned: 4`, `shadow_compare events: 4`, `Covered-family legacy-primary mismatch count: 1`, and still remains `BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH` because the blocker is the earlier same-day legacy-primary event, not the current deterministic scope.
  - Further Step 8 hardening tightened the legacy shadow behavior for the same covered `inventory_collection_age` prompt by rewriting the legacy freeform prompt payload with explicit publication-year, library-scope, and location-scope constraints recovered from the prompt text.
  - Focused validation passed via `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php` and `php -l backend/services/GeminiService.php`.
  - A direct forced-mode comparison inside the running `php` container now shows that both deterministic and legacy SQL for `What is the average age of items in the Neilson Reference collection?` use the same holdings -> instance -> publication join path, the same publication-year age basis, and the same explicit `'%Neilson Library%'` plus `'%Reference collection%'` scope filters; the remaining mismatch is now textual SQL shape rather than the earlier semantic drift.
  - Regenerated [planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md](baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md) after a fresh live `/api/nl` request. It now reports `Events scanned: 5`, `shadow_compare events: 5`, `SQL hash mismatch count: 5`, `Route divergence count: 5`, `Covered-family legacy-primary mismatch count: 1`, and `Provider fallback warning count: 36`. The latest compare at `18:40:21+00:00` still shows `Primary mode/route: intent / builder_intent` versus `Shadow mode/route: legacy / legacy_freeform`, but the SQL lengths have narrowed to `788 -> 794`, which is materially closer than the earlier semantically divergent legacy output.
  - Added semantic shadow comparison for aligned covered-family SQL in `GeminiService::logShadowComparison(...)`. The logger now records both raw SQL hashes and a family-specific semantic comparison signature for `inventory_collection_age`, so equivalent builder/legacy SQL shapes no longer need exact alias, CTE, or formatting parity to count as aligned.
  - Enhanced [planning/baseline/report_nl2sql_shadow_metrics.sh](baseline/report_nl2sql_shadow_metrics.sh) to prefer `sqlComparisonMatch` when present and still report raw SQL hash counts separately for debugging.
  - Focused validation passed via `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`, `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`, `php -l backend/services/GeminiService.php`, and `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`.
  - Restored the standalone request-path regression coverage in `backend/tests/GeminiServiceIntentRequestPathTest.php` by aligning its bootstrap with the compiler's schema-manifest dependency; the covered-family prompt-recovery path is now executable again instead of failing at harness bootstrap.
  - Focused validation passed via `php backend/tests/GeminiServiceIntentRequestPathTest.php`, `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`, and `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`.
  - Added structured warning-level `nl2sql.provider_fallback` telemetry in `GeminiService` with normalized reason codes and updated the daily Step 8 report to bucket fallback reasons while still counting pre-existing raw warning lines as `legacy_unstructured`.
  - Regenerated [planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md](baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md) after the report change. It still shows `Provider fallback warning count: 45`, but the new `Provider Fallback Reasons` section now makes clear that all 45 current entries are `legacy_unstructured`, meaning they were emitted before the new structured fallback telemetry landed.
  - Focused validation passed via `php backend/tests/GeminiServiceProviderFallbackTelemetryTest.php`, `bash backend/tests/ShadowMetricsProviderFallbackReportTest.sh`, `php -l backend/services/GeminiService.php`, `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`, and `bash planning/baseline/report_nl2sql_shadow_metrics.sh 2026-05-12`.
  - Live data validation for the reversed collection-age prompt variant showed that the deterministic `'%Reference collection%'` location predicate matched `0` items, while `'%Neilson Reference%'` matched `3631` items and the actual Smith/Neilson location labels are `SC Neilson Reference` and `SC Neilson Reference Oversize`.
  - Updated `GeminiService` collection-age prompt recovery and legacy prompt rewrite so prompts like `What is the age of the reference collection in Neilson Library?` and `What is the average age of items in the Neilson Reference collection?` now recover `location = Neilson Reference` instead of the non-existent abstract `Reference collection` scope.
  - Focused validation passed via `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`, `php backend/tests/QueryFamilyCompilerServiceTest.php`, `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`, `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`, and `php -l backend/services/GeminiService.php`.
  - Started the next hardening slice on slot scope boundaries: collection-age recovery now treats `location` as an explicit prompt scope, not something that can be inferred from a broader `library` mention. For `What is the average age of items in Neilson Library?`, prompt recovery now preserves `library = Neilson Library` and clears any prefilled `location` slot instead of inventing `Neilson Reference`.
  - Added focused regressions for both prompt recovery and legacy prompt rewrite so library-only collection-age prompts cannot silently narrow themselves to a sub-location. Added deterministic compiler coverage confirming that library-only collection-age SQL includes campus/library predicates only, while explicit reference-collection prompts still include the `Neilson Reference` location predicate.
  - Promoted that scope-boundary rule into the checked-in family contract: `inventory_collection_age.slots.inferencePolicies.location = explicit_prompt_only` now governs the shared policy hook in `QueryFamilySlotService`, and the family slot system prompt explicitly tells the model to omit `slots.location` for library-only prompts.
  - Focused validation passed via `php backend/tests/QueryFamilyContractServiceTest.php`, `php backend/tests/QueryFamilySlotServiceTest.php`, `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`, `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`, `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`, `php backend/tests/QueryFamilyCompilerServiceTest.php`, `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`, `php -l backend/services/GeminiService.php`, `php -l backend/services/QueryFamilySlotService.php`, and `php -l backend/services/QueryFamilyContractService.php`.
  - Added the first slot-provenance telemetry slice for covered-family builder intent routes: `GeminiService` now carries a `slotProvenance` map into `nl2sql.generated`, recording whether collection-age slots came from model output, explicit prompt recovery, prompt repair, default campus context, or explicit-only policy omission.
  - Focused validation passed via `php backend/tests/GeminiServiceSlotProvenanceTelemetryTest.php`, `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`, `php backend/tests/GeminiServiceIntentRequestPathTest.php`, and `php -l backend/services/GeminiService.php`.
  - Extended slot-provenance observability onto the family-mismatch path: covered-family `family_contract_mismatch` warnings, guarded-failure telemetry, and emergency-override `legacy_fallback` responses now preserve model-output slot provenance instead of dropping back to family-key-only telemetry.
  - Focused validation passed via `php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php`, `php backend/tests/GeminiServiceIntentRequestPathTest.php`, and `php -l backend/services/GeminiService.php`.
  - Updated `planning/baseline/report_nl2sql_shadow_metrics.sh` so the Step 8 daily report now buckets slot provenance beyond the success path: it reports `builder_intent` and `clarification` generated-event counts, validation-event counts, a flat `Slot Provenance Signals` summary, and a source-qualified `Slot Provenance Sources` section that distinguishes `generated.clarification`, `validation.family_contract_mismatch`, and `validation.family_fallback_guard` from `generated.builder_intent`.
  - Focused validation passed via `bash backend/tests/ShadowMetricsSlotProvenanceReportTest.sh`, `bash backend/tests/ShadowMetricsProviderFallbackReportTest.sh`, and `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`.
  - Live `/api/nl` validation on `localhost:8080` now shows the intended split: `What is the average age of items in Neilson Library?` returns `route=builder_intent` with no `ilo.name ILIKE` predicate, while `What is the average age of the Neilson Library Reference collection?` still returns `route=builder_intent` with `ilo.name ILIKE '%Neilson Reference%'`.
  - Focused validation passed via `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`, `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`, `php backend/tests/QueryFamilyCompilerServiceTest.php`, `php -l backend/services/GeminiService.php`, and live `/api/nl` checks for both the library-only and explicit reference-collection prompt variants.
  - The live web path required `docker compose up -d --force-recreate php worker export-worker` before the recreated PHP runtimes began emitting the updated semantic comparison fields in `backend/runtime/logs/app.log`.
  - After that runtime refresh and one fresh live `/api/nl` request, the regenerated [planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md](baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md) now reports `Events scanned: 8`, `SQL comparison match count: 1`, `SQL comparison mismatch count: 7`, `Raw SQL hash mismatch count: 8`, and the latest compare at `18:54:03+00:00` now shows `SQL comparison: true (semantic_sql_signature)` while `Raw SQL hash match: false`.
  - After the slot-provenance report slice, the regenerated [planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md](baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md) now includes `builder_intent generated events with slot provenance: 0` and a `Slot Provenance Signals` section. The current live report still shows `None` there because the log file has not yet recorded any fresh post-change `nl2sql.generated` events carrying the new field.
  - A fresh [planning/baseline/reports/2026-05-13_nl2sql-008-shadow-metrics.md](baseline/reports/2026-05-13_nl2sql-008-shadow-metrics.md) run now exercises the expanded parser but is still `BLOCKED_NO_SHADOW_TELEMETRY`; there are no 2026-05-13 shadow events yet, so the new clarification and mismatch provenance buckets have no live entries to summarize.
  - The regenerated 2026-05-12 report still remains blocked because it includes the earlier same-day legacy-primary mismatch event at `17:48:13+00:00`, but the latest compare at `18:06:50+00:00` now shows the desired direction: `Primary mode/route: intent / builder_intent` and `Shadow mode/route: legacy / legacy_freeform`.
  - The local smoke clears the Step 8 telemetry-path blocker, but it does not by itself advance the required-period gate; the qualifying streak remains `1` and the remaining qualifying days remain `2` until qualifying cohort traffic is collected under shadow mode.
  - The repaired `P74` library-clarification seam is part of the landed `1bc3f36` main baseline, so any further Step 8 or publish work should continue from that current main branch state.
  - Production parity check on 2026-05-11 found that the server was already on commit `1bc3f36`, but a blank `backend/data/settings.json` left NL2SQL runtime flags at their defaults and `/api/nl` still served the legacy freeform path. Restoring `nl2sql_intent_mode=true`, `nl2sql_primary_mode=intent`, and `nl2sql_force_legacy=false` brought production back onto the deterministic query-family route for the covered contributor/theses prompt.
  - Deployment implication: carrying the query-family slice on `main` is not sufficient by itself; server rollout must also preserve or reapply the NL2SQL runtime settings needed for intent-mode parity. See [updates/2026-05-11_14-43-00_NL2SQL-008_production-settings-parity-restored.md](../updates/2026-05-11_14-43-00_NL2SQL-008_production-settings-parity-restored.md).
  - Operational status update logged at [updates/2026-05-12_17-00-00_NL2SQL-008_shadow-report-no-events.md](../updates/2026-05-12_17-00-00_NL2SQL-008_shadow-report-no-events.md).
  - Follow-up update logged at [updates/2026-05-12_13-40-56_NL2SQL-008_web-telemetry-target-and-local-ai-key-blocker.md](../updates/2026-05-12_13-40-56_NL2SQL-008_web-telemetry-target-and-local-ai-key-blocker.md).
  - Local smoke verification logged at [updates/2026-05-12_13-49-43_NL2SQL-008_shadow-compare-flow-restored.md](../updates/2026-05-12_13-49-43_NL2SQL-008_shadow-compare-flow-restored.md).
  - Report-detail follow-up logged at [updates/2026-05-12_13-55-10_NL2SQL-008_shadow-report-route-divergence-summary.md](../updates/2026-05-12_13-55-10_NL2SQL-008_shadow-report-route-divergence-summary.md).
  - Covered-family blocker follow-up logged at [updates/2026-05-12_13-59-16_NL2SQL-008_covered-family-mismatch-reporting.md](../updates/2026-05-12_13-59-16_NL2SQL-008_covered-family-mismatch-reporting.md).
  - Automatic gate-status follow-up logged at [updates/2026-05-12_14-01-23_NL2SQL-008_fail-closed-gate-status.md](../updates/2026-05-12_14-01-23_NL2SQL-008_fail-closed-gate-status.md).
  - Covered-family primary-mode enforcement logged at [updates/2026-05-12_14-07-31_NL2SQL-008_covered-family-primary-intent.md](../updates/2026-05-12_14-07-31_NL2SQL-008_covered-family-primary-intent.md).
  - Collection-age scope canonicalization follow-up logged at [updates/2026-05-12_14-23-11_NL2SQL-008_collection-age-scope-canonicalization.md](../updates/2026-05-12_14-23-11_NL2SQL-008_collection-age-scope-canonicalization.md).
  - Legacy shadow semantic-alignment follow-up logged at [updates/2026-05-12_14-40-56_NL2SQL-008_collection-age-legacy-shadow-alignment.md](../updates/2026-05-12_14-40-56_NL2SQL-008_collection-age-legacy-shadow-alignment.md).
  - Semantic shadow-comparison follow-up logged at [updates/2026-05-12_14-54-56_NL2SQL-008_semantic-shadow-comparison.md](../updates/2026-05-12_14-54-56_NL2SQL-008_semantic-shadow-comparison.md).
  - Request-path regression coverage follow-up logged at [updates/2026-05-12_15-02-45_NL2SQL-008_request-path-regression-restored.md](../updates/2026-05-12_15-02-45_NL2SQL-008_request-path-regression-restored.md).
  - Provider fallback reason telemetry follow-up logged at [updates/2026-05-12_15-13-52_NL2SQL-008_provider-fallback-reasons.md](../updates/2026-05-12_15-13-52_NL2SQL-008_provider-fallback-reasons.md).
  - Neilson Reference collection-age scope follow-up logged at [updates/2026-05-12_15-25-19_NL2SQL-008_neilson-reference-location-scope.md](../updates/2026-05-12_15-25-19_NL2SQL-008_neilson-reference-location-scope.md).
  - Slot-scope hardening follow-up logged at [updates/2026-05-12_15-47-57_NL2SQL-008_slot-scope-hardening.md](../updates/2026-05-12_15-47-57_NL2SQL-008_slot-scope-hardening.md).
  - Contract-slot-policy follow-up logged at [updates/2026-05-12_15-57-24_NL2SQL-008_contract-slot-policy.md](../updates/2026-05-12_15-57-24_NL2SQL-008_contract-slot-policy.md).
  - Slot-provenance telemetry follow-up logged at [updates/2026-05-12_16-15-41_NL2SQL-008_slot-provenance-telemetry.md](../updates/2026-05-12_16-15-41_NL2SQL-008_slot-provenance-telemetry.md).
  - Mismatch slot-provenance telemetry follow-up logged at [updates/2026-05-12_16-34-53_NL2SQL-008_mismatch-slot-provenance.md](../updates/2026-05-12_16-34-53_NL2SQL-008_mismatch-slot-provenance.md).
  - Slot-provenance report follow-up logged at [updates/2026-05-12_16-28-32_NL2SQL-008_slot-provenance-report.md](../updates/2026-05-12_16-28-32_NL2SQL-008_slot-provenance-report.md).
  - Clarification-and-mismatch provenance report follow-up logged at [updates/2026-05-13_08-54-13_NL2SQL-008_provenance-report-buckets.md](../updates/2026-05-13_08-54-13_NL2SQL-008_provenance-report-buckets.md).
  - Optimized the deterministic `inventory_collection_age` compiler path for broad library scopes: collection-age SQL now filters and groups scoped items by instance in a `scoped_instances` CTE, then computes a weighted publication-year average from `item_count` instead of joining every scoped item row directly to publication data. The aggregate-only query no longer appends the no-op outer `LIMIT 100` that did not reduce execution cost.
  - Updated collection-age semantic shadow comparison so the new weighted aggregate shape remains equivalent to legacy `AVG(age)` SQL and does not diverge just because the aggregate query omits `LIMIT 100`.
  - Focused validation passed via `php backend/tests/QueryFamilyCompilerServiceTest.php`, `php backend/tests/GeminiServiceFamilyCompilerResultTest.php`, `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`, and `php backend/tests/GeminiServiceIntentRequestPathTest.php`.
  - Collection-age query-shape hardening follow-up logged at [updates/2026-05-13_09-39-49_NL2SQL-008_collection-age-query-shape-hardening.md](../updates/2026-05-13_09-39-49_NL2SQL-008_collection-age-query-shape-hardening.md).
  - User-confirmed live validation: the previously timing-out Neilson Library collection-age prompt cleared after the query-shape hardening change, so the 30-minute `statement_timeout` is no longer reproducing on that prompt in the current runtime.
  - Live collection-age timeout clearance follow-up logged at [updates/2026-05-13_10-02-08_NL2SQL-008_collection-age-live-timeout-clearance.md](../updates/2026-05-13_10-02-08_NL2SQL-008_collection-age-live-timeout-clearance.md).
  - Generated fresh 2026-05-13 live shadow traffic for the library-only Neilson Library collection-age prompt and regenerated [planning/baseline/reports/2026-05-13_nl2sql-008-shadow-metrics.md](baseline/reports/2026-05-13_nl2sql-008-shadow-metrics.md). The current report now shows `shadow_compare events: 6`, `shadow_error events: 1`, `Provider fallback warning count: 3`, `builder_intent generated events with slot provenance: 7`, and latest compare `SQL comparison: true (semantic_sql_signature)` with `Raw SQL hash match: false`.
  - Closed the remaining library-only semantic-comparison blind spot in `GeminiService`: collection-age semantic signatures no longer require a location predicate for library-only prompts, and the comparator now also extracts legacy scope literals from `LOWER(alias.name) ILIKE LOWER('...')` predicates emitted by the live legacy SQL path.
  - Tightened `backend/tests/GeminiServiceShadowSemanticComparisonTest.php` to use the exact live forced-legacy SQL shape for `Show me the average age of items in the Neilson Library collection`, verified the new regression fails before the extractor fix, and reran it green after the patch.
  - Fresh live `/api/nl` validation for `Show me the average age of items in the Neilson Library collection` now returns `route=builder_intent`, `routeReason=family_contract_supported:inventory_collection_age`, `dataSource=folio`, `has_sql=true`, and the latest 2026-05-13 compare records semantic alignment instead of falling back to raw-hash-only comparison.
  - The 2026-05-13 Step 8 day is still blocked, but now for evidence hygiene rather than the original timeout or semantic drift: `BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH` comes from the temporary diagnostic `nl2sql_force_legacy=true` request captured at `2026-05-13T15:22:00+00:00`, and the same day still includes one `shadow_error` caused by provider high demand. Do not count this day as qualifying shadow evidence.
  - Library-only semantic shadow-alignment follow-up logged at [updates/2026-05-13_15-31-00_NL2SQL-008_library-only-shadow-semantic-alignment.md](../updates/2026-05-13_15-31-00_NL2SQL-008_library-only-shadow-semantic-alignment.md).
  - User-confirmed live validation on 2026-05-13: `Show me items from library location code SRBC that are missing 6xx fields` correctly returned results on the current runtime. This confirms the repaired `folio_source_record.records__t` jsonb/deleted-flag heuristic still supports location-code-scoped missing-`6xx` prompts, but it does not close the separate open risk that whole-field-family checks remain source-record text heuristics while `marctab` is blocked.
  - SRBC missing-`6xx` heuristic validation follow-up logged at [updates/2026-05-13_13-46-01_NL2SQL-008_srbc-6xx-live-validation.md](../updates/2026-05-13_13-46-01_NL2SQL-008_srbc-6xx-live-validation.md).
  - Generalized collection-age location-scope recovery so prompts such as `What is the average age of the Hillyer locked stacks collection?` recover an explicit `location = Locked Stack` scope instead of rolling up to broad Hillyer library totals. The same slice allows explicit location scope to satisfy `inventory_collection_age` validation without a required broader library slot, while keeping fully unscoped collection-age payloads invalid.
  - Added guard coverage for broad library collection wording so prompts such as `What is the average age of the Neilson Library collection?` remain scoped to `library = Neilson Library` and do not invent a `location = Neilson` predicate.
  - Focused validation passed via `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`, `php backend/tests/QueryFamilySlotServiceTest.php`, `php backend/tests/QueryFamilyCompilerServiceTest.php`, `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`, `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`, `php backend/tests/GeminiServiceIntentRequestPathTest.php`, `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`, `php -l backend/services/GeminiService.php`, `php -l backend/services/QueryFamilySlotService.php`, and `php -l backend/services/QueryFamilyCompilerService.php`.
  - Live `/api/nl` validation for `What is the average age of the Hillyer locked stacks collection?` now returns `route=builder_intent`, `routeReason=family_contract_supported:inventory_collection_age`, includes an `ilo.name ILIKE` location predicate, and mentions `Locked Stack` in the generated SQL.
  - Collection-age location-scope recovery follow-up logged at [updates/2026-05-13_16-33-47_NL2SQL-008_collection-age-location-scope-recovery.md](../updates/2026-05-13_16-33-47_NL2SQL-008_collection-age-location-scope-recovery.md).
  - Extended the collection-age family to support combined count+age prompts: `item_count` is now an allowed `inventory_collection_age` output, and combined SQL reports `SUM(scoped_instances.item_count) AS item_count` alongside `average_age_years` without letting publication-date filtering shrink the item count.
  - Repaired `collection at <library>` prompt recovery so `How many items are in the zine collection at Hillyer library and what is their average age?` resolves as `library = Hillyer` plus `location = Zine Collection` instead of collapsing the whole phrase into a library predicate. Live FOLIO location sampling confirmed `SC Art Zine Collection | SCZAC | SC Hillyer Art Library`, and direct deterministic SQL returned `item_count=134` and `average_age_years=5.8333333333333333`.
  - Focused validation passed via `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`, `php backend/tests/QueryFamilyCompilerServiceTest.php`, `php backend/tests/QueryFamilyContractServiceTest.php`, `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`, `php backend/tests/QueryFamilySlotServiceTest.php`, `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`, `php backend/tests/GeminiServiceIntentRequestPathTest.php`, `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`, `php backend/tests/GeminiServiceFamilyCompilerResultTest.php`, `php backend/tests/GeminiServiceSlotProvenanceTelemetryTest.php`, `php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php`, `php -l backend/services/GeminiService.php`, `php -l backend/services/QueryFamilyCompilerService.php`, and `php -l backend/services/QueryFamilySlotService.php`.
  - Zine collection location-scope follow-up logged at [updates/2026-05-14_09-04-11_NL2SQL-008_zine-collection-location-scope.md](../updates/2026-05-14_09-04-11_NL2SQL-008_zine-collection-location-scope.md).










### NL2SQL-009 - Postgres Preflight Enforcement and Telemetry
- Status: COMPLETED
- Source step: Step 3
- Scope:
  - Centralize Postgres EXPLAIN preflight in a shared service.
  - Enforce generated-SQL preflight on `/api/nl`, `/api/query/submit`, and generated `/api/execute`.
  - Emit structured controller-side preflight telemetry and surface `422` validation failures clearly in the Ask UI.
- Validation gate:
  - Invalid generated SQL fails with `422` before execution or queueing.
  - Manual `/api/execute` SQL keeps its existing execution-time behavior.
  - Structured telemetry is emitted for preflight failures.
- Completion update file required: Yes
- Progress notes:
  - Extracted shared EXPLAIN logic into `backend/services/SqlPreflightService.php`.
  - `/api/nl`, `/api/query/submit`, and generated `/api/execute` now all run Postgres preflight after normalization and SQL safety checks.
  - Added controller-side `nl2sql.validation_failure` telemetry for `postgres_preflight` failures with endpoint, source, dataSource, errorFamily, error text, and stable SQL hash.
  - Added focused backend tests `backend/tests/SqlPreflightServiceTest.php` and `backend/tests/FolioQueryControllerExecutePreflightTest.php`.
  - Updated the Ask page so `422` NL failures render as `Query validation failed: ...` instead of a generic AI error; added frontend test `frontend/src/pages/Ask.errorFormatting.test.ts`.
  - Live validation against the running local stack returned `422` for `SELECT EXTRACT(EPOCH FROM 1)` on generated `/api/execute` with Postgres connected.
  - Completion update logged at [updates/2026-05-12_11-47-51_NL2SQL-009_postgres-preflight-enforcement.md](../updates/2026-05-12_11-47-51_NL2SQL-009_postgres-preflight-enforcement.md).

### NL2SQL-010 - Runtime Mode Parity Preflight
- Status: COMPLETED
- Source step: Step 9
- Scope:
  - Add an admin/deploy preflight that reports effective NL2SQL runtime settings and artifact versions.
  - Warn when runtime settings fall back to legacy defaults because persisted settings are missing.
- Validation gate:
  - Preflight surface reports effective `nl2sql_*` flags.
  - Artifact metadata is present for the graph/contracts/schema files.
  - Missing or blank `settings.json` produces an explicit warning state.
- Completion update file required: Yes
- Progress notes:
  - Added `backend/services/Nl2sqlRuntimePreflightService.php` to build a parity report from live params, persisted settings, and artifact files.
  - Added `GET /api/nl2sql-preflight` in `backend/controllers/FolioQueryController.php` and registered the pretty URL rule in `backend/config/web.php`.
  - The preflight response now reports effective NL2SQL flags, persisted runtime settings presence, artifact hashes/version metadata, and warning messages when parity is at risk.
  - Added focused backend test `backend/tests/Nl2sqlRuntimePreflightServiceTest.php`.
  - Live endpoint validation on the local stack returned `status=warning`, `hasSettingsFile=false`, and explicit warnings about `settings.json` and legacy defaults, matching the production parity issue this ticket targets.
  - Completion update logged at [updates/2026-05-12_11-47-52_NL2SQL-010_runtime-mode-parity-preflight.md](../updates/2026-05-12_11-47-52_NL2SQL-010_runtime-mode-parity-preflight.md).

### NL2SQL-011 - Query Family Schema Manifests
- Status: COMPLETED
- Source step: Step 4
- Scope:
  - Define checked-in schema manifests for high-value deterministic families.
  - Validate required tables, columns, types, and join edges before family compilation.
- Validation gate:
  - Missing or drifted metadata fails as schema drift without legacy fallback.
  - Regression tests cover missing columns, changed types, and missing join edges.
- Completion update file required: Yes
- Progress notes:
  - Added checked-in manifest artifact `backend/data/query_family_schema_manifests.json` covering `inventory_collection_age`, `inventory_contributor_campus_item_barcode`, `inventory_library_location_listing`, and `circulation_top_items`.
  - Added `backend/services/QueryFamilySchemaManifestService.php` to validate required entities, columns, types, graph-backed deterministic edges, and conditional slot/output requirements against the live schema caches before compilation.
  - Wired `QueryFamilyCompilerService::compileToQueryDefinition()` to fail closed on manifest-detected schema drift before join construction.
  - Added focused regression tests `backend/tests/QueryFamilySchemaManifestServiceTest.php` and `backend/tests/QueryFamilyCompilerSchemaManifestGuardTest.php`, and updated `backend/tests/QueryFamilyCompilerServiceTest.php` for the new service dependency.
  - Expanded `backend/services/CanonicalQueryGraphArtifactBuilder.php` and the checked-in `backend/data/canonical_query_graph.json` artifact to cover `circulation_loans` plus deterministic edges for `item_id` and `item_effective_location_id_at_check_out`.
  - Added `circulation_trends_matrix` to `backend/data/query_family_schema_manifests.json` and verified that the checked-in artifact now validates against the real schema caches.
  - Added focused regression test `backend/tests/QueryFamilyTrendManifestCoverageTest.php` to keep the checked-in graph artifact, builder output, and schema-manifest rollout aligned for the trend family.
  - Local validation passed for `QueryFamilyTrendManifestCoverageTest`, `QueryFamilySchemaManifestServiceTest`, `QueryFamilyCompilerSchemaManifestGuardTest`, and `QueryFamilyCompilerServiceTest`, plus PHP syntax checks for the validator and graph builder.
  - All five currently supported deterministic families in `QueryFamilyCompilerService::SUPPORTED_FAMILIES` are now covered by checked-in schema manifests.
  - Progress update logged at [updates/2026-05-12_16-34-00_NL2SQL-011_schema-manifest-first-slice.md](../updates/2026-05-12_16-34-00_NL2SQL-011_schema-manifest-first-slice.md).
  - Completion update logged at [updates/2026-05-12_16-52-00_NL2SQL-011_trend-manifest-coverage-complete.md](../updates/2026-05-12_16-52-00_NL2SQL-011_trend-manifest-coverage-complete.md).

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
  - Latest live endpoint smoke on 2026-05-11 returned `recommendationSource=gemini` on `openai/gpt-4.1-mini` with `eligibleLogs=16`, `uniqueQueryPatterns=14`, `recommendationCount=10`, and no warnings.

### NL2SQL-101 - Previous Successful Query Reuse Backend
- Status: COMPLETE
- Source step: Repeated-question quality track
- Scope:
  - Search all prior successful NL query jobs before calling AI SQL generation.
  - Match conservatively using normalized prompt text plus data source and resolved context when available.
  - Return prior SQL only as a reviewable suggestion; never execute reused SQL automatically.
- Validation gate:
  - Completed NL jobs can be selected as strong reuse candidates.
  - Failed, cancelled, manual, report, wrong-data-source, and weak text matches are excluded.
  - Suggested SQL still passes current safety/table-policy validation before being shown.
- Completion update file required: Yes
- Progress notes:
  - Product decision: search all successful queries, not just the current user's history.
  - Product decision: strong matches should interrupt with a review panel before new SQL generation.
  - Product decision: reuse the previous SQL only; results must be rerun against current data after user approval or edits.
  - Started backend implementation with `PreviousSuccessfulQueryReuseService`, which filters to completed NL jobs on the same data source, compares normalized prompt text, checks resolved campus/domain context when provided, and returns reviewable SQL plus match reasons.
  - Added `POST /api/query/reuse-candidate` as the pre-generation backend hook for Ask. The endpoint searches recent completed NL jobs across users, delegates matching to the reuse service, and revalidates suggested SQL with the current safety/table-policy checks before returning it.
  - Focused validation passed via `php backend/tests/PreviousSuccessfulQueryReuseServiceTest.php`, `php backend/tests/FolioQueryControllerReuseCandidateEndpointTest.php`, and PHP syntax checks for the new service, controller, and web config.
  - Final verification on 2026-06-22 passed the focused backend tests, PHP syntax checks, frontend focused tests, frontend production build, and `git diff --check`.
  - Fixed legacy-history matching: prior successful jobs that predate `metadata.resolvedContext` remain eligible when the prior prompt explicitly names the requested context value, such as "Smith College".
  - Added deterministic ranking for multiple successful matches: exact prompt matches rank first, then human-reviewed reuse outcomes, then prompt similarity score, then most recent completion time.
  - Fixed exact-repeat prompts that omit the campus name: fresh NL jobs now persist `resolvedContext`, and legacy rows without context can match Smith College only when the prior SQL proves Smith scope, such as `TRIM(au.name) = 'SC'`.

### NL2SQL-102 - Previous Successful Query Reuse UI
- Status: COMPLETE
- Source step: Repeated-question quality track
- Scope:
  - Add an Ask-page review panel when the backend finds a strong prior successful query match.
  - Show prior question, last successful run metadata, match reasons, and editable SQL.
  - Let the user run previous SQL, edit and run, or generate new SQL instead.
- Validation gate:
  - Strong matches show a transparent review panel before AI generation.
  - Users can inspect and edit SQL before execution.
  - Weak or absent matches continue the current generation flow.
- Completion update file required: Yes
- Progress notes:
  - Added frontend API/types for `POST /api/query/reuse-candidate`.
  - Ask now checks for a prior successful query before AI generation for first-pass prompts, skipping reuse checks for follow-up questions.
  - Strong matches render a review panel with prior question, match score, last-run metadata, match reasons, editable SQL, and actions to run the SQL or generate fresh SQL.
  - Reused SQL is rerun through the existing async query submission path; prior result rows are not reused.
  - Focused validation passed via `npm test -- Ask.queryReuse.test.ts client.followUp.test.ts` and `npm run build`.
  - Final verification on 2026-06-22 passed the focused backend tests, PHP syntax checks, frontend focused tests, frontend production build, and `git diff --check`.

### NL2SQL-103 - Query Reuse Outcome Telemetry
- Status: COMPLETE
- Source step: Repeated-question quality track
- Scope:
  - Record whether a suggested prior query was reused, edited, or bypassed.
  - Record whether the rerun succeeded.
  - Feed failed repeat patterns back into NL2SQL review workflows without offering failed SQL for reuse.
- Validation gate:
  - Reuse decisions are reviewable in history/telemetry.
  - Failed prior queries are visible as review signals but never offered as reusable SQL.
- Completion update file required: Yes
- Progress notes:
  - Added `POST /api/query/reuse-decision` to emit `nl2sql.query_reuse` telemetry for accepted, edited, and bypassed reuse-panel decisions.
  - Reused SQL submissions now include `queryReuse` metadata on the created `query_jobs` row with prior candidate job id, edited state, and match score.
  - Ask records accepted/edited decisions when the user runs previous SQL and records bypass decisions when the user chooses to generate new SQL instead.
  - Focused validation passed via `php backend/tests/QueryReuseOutcomeTelemetryTest.php`, `npm test -- client.followUp.test.ts`, and `npm run build`.
  - Final verification on 2026-06-22 passed the focused backend tests, PHP syntax checks, frontend focused tests, frontend production build, and `git diff --check`.
