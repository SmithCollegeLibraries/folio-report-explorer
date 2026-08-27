# AI-Always Routing and Candidate Safety — Phase 1 Implementation Report

## Outcome

Phase 1 is implemented on `feat/verified-first-ai-always`.

- Explicit, high-confidence database write requests stop at request policy with `request_blocked`.
- Ambiguous analytical wording continues through enforced read-only generation.
- Canonical non-handling, unsafe generated candidates, and ordinary candidate validation failures continue to fresh AI.
- Every replacement candidate repeats SQL safety, protected-table policy, schema validation, and database preflight through the existing generation and controller boundaries.
- Generation exhaustion returns `sql_generation_failed` and does not describe the request itself as unsafe.
- Backend `unsafe_generated_sql` emission is retired. The frontend recognizes that value only for mixed-version rolling-deployment compatibility.
- Executable provenance remains `verified_pattern` only for unchanged canonical compilation; generated and repaired candidates are `ai_built`.

## Deliberate rollback contract change

Disabling `nl2sqlCoordinatorEnabled` returns control to the prior controller orchestrator, but it does not restore the retired backend `unsafe_generated_sql` response. Candidate-safety exhaustion on that path now uses `sql_generation_failed` when two-lane routing remains enabled. The older full rollback obtained by also disabling `nl2sqlTwoLaneEnabled` retains its legacy recovery response.

The old unsafe-shape assertions were deliberately replaced in:

- `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- `backend/tests/GeminiServiceTwoLaneRoutingTest.php`

Those tests now assert candidate regeneration and normal AI-built success, while separate tests assert request-level write blocking. This is an intentional contract migration, not assertion weakening.

## Acceptance regression

The exact vendor receipt-time prompt is covered by `backend/tests/AskAiAlwaysVendorReceiptRegressionTest.php`. Its first generated candidate is a write statement; the token-aware SQL gate rejects it, the coordinator requests one replacement, and the safe vendor/fiscal-year analytical SQL returns as `ai_built` after the preflight boundary.

Coordinator telemetry is covered by `backend/tests/AskGenerationCoordinatorTelemetryTest.php`. It records typed transitions, normalized reasons, prompt fingerprints, and candidate SQL hashes without logging raw prompts, rejected SQL, or result rows.

## Verification

Verified on 2026-08-27:

- PHP syntax checks passed for the request policy, coordinator, SQL structure/safety services, Gemini service, and controller.
- All 143 standalone backend PHP test files passed.
- Both shadow-metrics shell regressions passed.
- Frontend: 40 test files and 226 tests passed.
- Frontend production TypeScript/Vite build passed.
- `git diff --check` passed.
- Source audit found no `unsafe_generated_sql` in backend controllers or services.

The suite emitted existing PHP 8.5 `ReflectionMethod::setAccessible()` deprecation notices, existing reference-resolver test-harness warnings, and expected skips for unavailable live FOLIO PostgreSQL checks. None caused a test failure.
