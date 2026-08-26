# Two-Lane Report Generation Phase 1 — Final Fix Report

**Date:** 2026-08-26
**Baseline:** `d5b375bc5e8288c4fba66e07d243988e3adbb05d`
**Scope:** Single final cross-phase fix wave for the five Important and three Minor final-review findings.

## Outcome

All final-review findings are addressed without expanding Phase 1. Advisory semantic coverage remains review-required through persistence and public finalization; real repair transport rejects provider truncation before parsing; structured PostgreSQL authorization, cancellation, and class-57 availability states never enter AI repair; enabled-mode generic generation failures cannot select rollback recovery; and rejected typed Axios responses replace stale Ask success/results with the accessible Retry terminal UI.

Rollback-only clarification/recovery components remain available behind the false switch. No test contacted production FOLIO, a live database, or an external AI provider.

## RED evidence

Boundary tests were added before each production change and failed for the intended reason:

| Boundary | Initial failing observation |
|---|---|
| Evidence service | Advisory semantic validation without `coverageStatus` produced `limitedSemanticCoverage=false`. |
| Controller finalization | Trusted advisory evidence was finalized with `reviewRequired=false` instead of persisting `limited_semantic_coverage`. |
| Real repair transport | A parseable provider response with `finishReason=MAX_TOKENS` was accepted as repaired SQL. |
| Controller structured preflight | Opaque exact `42501` reached AI repair; message-independent structured hard-stop assertions failed. |
| Gemini structured preflight | Opaque exact `42501` did not raise the policy hard stop; `57P01` and `57014` lacked explicit structured-state coverage. |
| Enabled generic generation failure | `actionNl()` returned the untyped rollback response instead of `sql_generation_failed`. |
| Telemetry | Explicit direct-AI generation emitted `from=verified_pattern` instead of `from=direct_ai`. |
| Rendered Axios lifecycle | Rejected 403/503/504 typed responses did not render an alert or Retry and left the prior success visible. |
| Default-on copy | The rendered Ask page still presented clarification as a prerequisite for generation. |

The lifecycle test initially exposed a test-harness-only `localStorage` problem; the harness was corrected before any production edit, after which all three rejected-response cases produced the intended UI RED failures.

## GREEN implementation

- `AskGenerationEvidenceService` derives limited semantic coverage from an advisory semantic-validation status as well as explicit limited/partial coverage.
- Controller finalization therefore persists `reviewRequired=true`, `limited_semantic_coverage`, and the corresponding public review notice.
- Both controller and Gemini preflight classifiers recognize exact `42501`, exact `57014`, and remaining SQLSTATE class `57` independently of error-message text. Cancellation remains distinct; the other class-57 states use the database-availability hard stop. None invokes AI repair.
- The real exploratory-repair provider response checks `finishReason` before extracting or parsing text and sends `MAX_TOKENS` through the standard recognized provider-failure contract.
- Generic failure continuation checks the default-on `nl2sqlTwoLaneEnabled` switch. Enabled mode returns typed Retry-only `sql_generation_failed`; false-switch rollback retains `exploratory_recovery`.
- Ask mutation error handling allowlists typed no-SQL Axios response bodies, replaces shared result state, resets stale execution state, preserves Retry history, and uses the existing assertive terminal alert. Unknown failures continue to use the generic error path.
- Default-on Ask copy now describes automatic context preparation, SQL generation, and validation while labeling clarification/recovery guidance as rollback-only.
- AI-built transition telemetry reports `direct_ai`, `no_matching_pattern`, or `verified_pattern` according to the actual source lane.

## Files changed

Production:

- `backend/controllers/FolioQueryController.php`
- `backend/services/AskGenerationEvidenceService.php`
- `backend/services/GeminiService.php`
- `frontend/src/pages/Ask.tsx`

Tests:

- `backend/tests/AskGenerationEvidenceServiceTest.php`
- `backend/tests/FolioQueryControllerAskContinuationPolicyTest.php`
- `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- `backend/tests/FolioQueryControllerPolicyViolationStatusTest.php`
- `backend/tests/GeminiServiceExploratoryRepairTest.php`
- `frontend/src/pages/Ask.requestLifecycle.test.tsx`

Evidence:

- `docs/superpowers/implementation-reports/2026-08-26-two-lane-report-generation-phase-1.md`
- `.superpowers/sdd/2026-08-26-two-lane-report-generation-phase-1/final-fix-report.md`

## Verification

| Check | Result |
|---|---|
| Focused backend boundary tests | Passed: evidence finalization, continuation policy, controller repair, Gemini repair, and two-lane routing. |
| All backend standalone tests | **139/139 files exited 0**; environment-gated live tests retained their existing skips. |
| Focused frontend tests | **4/4 files, 43/43 tests passed**. |
| Full frontend suite | **40/40 files, 222/222 tests passed**. |
| New rendered lifecycle suite | **1/1 file, 4/4 tests passed**. |
| Production build | Exit 0; TypeScript passed and Vite transformed **2,513 modules**. |
| Changed PHP syntax | All eight changed PHP production/test files passed `php -l`. |
| Scoped whitespace check | `git diff --check` exited 0. |
| Lint attempt | Exit 127: `sh: eslint: command not found`; ESLint remains absent from project dependencies. |

Existing Browserslist staleness, Node localStorage experimental, and large Vite chunk warnings remain non-blocking and unchanged.

## Self-review

- Confirmed all hard-stop classifications run before the AI-repair eligibility branch in both controller and service.
- Confirmed `57014` is explicitly excluded from the class-57 availability branch and retains cancellation behavior.
- Confirmed enabled generic failures expose no SQL, provenance, clarification, recovery, suggestions, or correction payloads; false-switch tests retain legacy rollback behavior.
- Confirmed Axios normalization copies only allowlisted terminal fields and refuses bodies containing executable SQL, preventing an error response from being treated as success.
- Confirmed the rendered request-lifecycle regression exercises the real Ask mutation, observes `role=alert`, Retry, prior-success removal, SQL removal, and execution reset; it is not a helper-only test.
- Confirmed unrelated dirty reports, cache files, older design documents, and the SQL dump were not edited or staged by this wave.

## Concerns

- PostgreSQL availability failures, including class-57 operator intervention, retain the existing compatibility `postgres_connectivity` response and HTTP-200 controller behavior. The frontend treats that shape as a terminal failure.
- Legacy blocker UI remains intentionally bundled for false-switch rollback. Enabled-mode controller and frontend behavior are protected by switch-state and rendered routing regressions.
- Lint remains unavailable until ESLint is deliberately added to repository tooling; no dependency change was introduced in this scoped fix wave.

The commit hash for the wave containing this report is recorded in the final handoff.
