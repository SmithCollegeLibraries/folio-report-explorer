# Task 6 Report — End-to-End Two-Lane Regression and Rollout Evidence

## Scope

Implemented Task 6 only against `a0bf0d9`, following the approved plan/spec, SDD ledger, and binding `task-6-brief.md` rulings. No subagents, production FOLIO, or external AI provider were used.

## TDD evidence

### RED: backend public provenance seam

Added final-response cases to `AskResponseContractServiceTest.php` for trusted canonical, missing, invalid, and exploratory-forged provenance.

```text
trusted canonical success must derive its matching public label.
Expected: 'Verified pattern'
Actual: 'AI-built'
```

Minimal fix: backend response finalization now preserves verified provenance only for trusted canonical compiler routing and derives AI-built for every other executable result. No-SQL responses remove both fields.

A follow-up public action regression then verified the pair is normalized before trusted evidence persistence:

```text
Generic executable SQL must persist the same trusted provenance shown to the user.
Expected: 'ai_built'
Actual: NULL
```

Minimal fix: controller finalization now normalizes the pair before building generation evidence, so persistence and the browser response share the same trusted value.

### GREEN: backend public provenance seam

```text
Ask response contract service test passed
```

### RED: public hard-failure seams

The new `actionNl()` matrix failed successively on the exact exposed seams before each fix:

```text
destructive AI SQL must use concise Retry copy.
Expected: 'Report Explorer could not safely run this report. Please retry.'
Actual: 'I couldn\'t safely turn this request into a report...refine one part...'
```

```text
destructive AI SQL must omit forbidden response field suggestions.
Expected: false
Actual: true
```

```text
database cancellation must omit forbidden response field recoveryContext.
Expected: false
Actual: true
```

```text
database connectivity must omit forbidden response field needsClarification.
Expected: false
Actual: true
```

```text
database authentication must retain its public hard-failure type.
Expected: 'policy_blocked'
Actual: NULL
```

Minimal fixes: Retry-only unsafe copy; suggestions only on SQL successes; no cancellation/connectivity recovery payloads; stable policy type. Multiple-statement and timeout REDs identified incomplete standalone doubles, which were brought into parity with production safety/timeout classification without changing production behavior.

### GREEN: public hard-failure matrix

```text
FolioQueryController exploratory repair test passed
```

### RED: frontend terminal-failure routing

Added a regression proving typed no-SQL failures select the terminal failure view before the retained rollback recovery component. It failed first on the HTTP-200 compatibility response:

```text
routes typed no-SQL hard failures ahead of rollback recovery
Expected: 'terminal_failure'
Actual: 'legacy_recovery'
```

Minimal fix: the frontend now recognizes the stable hard-failure types before applying the legacy rejected/exhausted recovery predicate, renders concise Retry guidance, and accepts the backend's typed `error` field.

### GREEN: frontend terminal-failure routing

```text
Test Files  1 passed (1)
Tests       30 passed (30)
```

### Full-suite integration REDs

The first full standalone run stopped at `AskAiCrossDomainRoiRegressionTest.php` because its resolver double lacked `appendGenerationContextToPrompt()`. After signature parity, its rollback-era exhaustion assertions failed because enabled two-lane exhaustion correctly returns `sql_generation_failed`; those assertions were replaced with the approved no-SQL Retry-only contract.

`PhysicalRoiCompilerRoutingTest.php` had the same stale resolver-double signature. Its prior safe semantic fixture now correctly becomes an advisory success under Task 3, so the fixture was changed to an unknown-table failure to exercise the deterministic compiler fallback the test is intended to cover.

### New exact routing matrix

`GeminiServiceTwoLaneRoutingTest.php` initially errored because the standalone fixture omitted the local domain-hint DB boundary. A deterministic empty local-DB fixture corrected the harness error. The exact three routing expectations then passed without a production routing change, confirming Task 1–5 behavior.

### Fix round 1: pre-result safety exceptions

The original public unsafe cases used a service double that returned SQL, exercising controller post-result validation but not the production service exception boundary. The corrected test makes the public generation seam throw the two real failure shapes proven by the real `GeminiService`/`SqlBuilderService` fixture: destructive SQL is a non-repairable `ExploratorySqlValidationException` at stage `safety`, while multiple statements throw `InvalidArgumentException: Only a single SELECT statement is allowed.`

RED:

```text
destructive AI SQL must retain its hard-failure type.
Expected: 'unsafe_generated_sql'
Actual: NULL
```

Minimal fix: `buildAskContinuationFromFailure()` now recognizes only non-repairable safety exceptions and the exact production SELECT-safety messages, then reuses the existing sanitized `unsafe_generated_sql` response. Both cases cross generation once and make zero preflight and zero repair calls.

### Fix round 1: configured identifier ceiling

Added a real public service case with 501 explicit instance HRIDs and no queued provider response.

Backend RED:

```text
501 identifiers must use a typed configured-resource hard failure when two-lane mode is enabled.
Expected: 'configured_resource_limit'
Actual: NULL
```

Frontend RED:

```text
Expected: "terminal_failure"
Received: "legacy_recovery"
```

Minimal fix: enabled two-lane mode now returns `configured_resource_limit` with concise Retry copy before any provider call; no SQL, provenance, clarification, recovery, or correction fields are present. The false switch retains the prior clarification response. Ask treats the new type as terminal.

## Files

Production:

- `backend/services/AskResponseContractService.php`
- `backend/controllers/FolioQueryController.php`
- `backend/services/GeminiService.php`
- `frontend/src/pages/Ask.tsx`
- `frontend/src/types/schema.ts`

Regression coverage / obsolete fixture alignment:

- `backend/tests/GeminiServiceTwoLaneRoutingTest.php` (new)
- `backend/tests/FolioQueryControllerExploratoryRepairTest.php`
- `backend/tests/AskResponseContractServiceTest.php`
- `backend/tests/FolioQueryControllerAskContinuationPolicyTest.php`
- `backend/tests/AskAiCrossDomainRoiRegressionTest.php`
- `backend/tests/PhysicalRoiCompilerRoutingTest.php`
- `frontend/src/pages/Ask.errorFormatting.test.ts`

Evidence:

- `docs/superpowers/implementation-reports/2026-08-26-two-lane-report-generation-phase-1.md` (new)
- `.superpowers/sdd/2026-08-26-two-lane-report-generation-phase-1/task-6-report.md` (new)

## Verification

- Backend standalone: **139/139 test files passed**.
- Exact routing: **3/3 prompts passed** with `verified_pattern`, `ai_built`, `ai_built`.
- Hard gates: destructive, multiple-statement, 501 explicit identifiers, restricted patron, cancellation, timeout, SQLSTATE 08/28/53/54, and two-repair exhaustion passed.
- Frontend: **39/39 files, 218/218 tests passed**.
- Focused frontend blocker/provenance: **2/2 files, 34/34 tests passed**.
- Build: exit 0, **2,513 modules transformed**.
- Lint: unavailable, exit 127, exact failure `sh: eslint: command not found`.
- Source audit: 11 rollback-only blocker matches intentionally retained; behavioral routing tests passed.

## Self-review

- Every production edit followed a failing public behavior assertion.
- Unsafe/multiple-statement SQL stops before preflight.
- Enabled 501-identifier requests stop before provider generation; rollback mode retains its compatibility clarification.
- Hard failures expose neither executable SQL nor success provenance.
- Typed hard failures select terminal UI even when compatibility requires an HTTP-200 response.
- Administrator evidence remains server-only and preserves candidate structure where required.
- The exact three prompts use fake transport and deterministic fixtures only.
- No unrelated dirty cache/report/SQL-dump files were edited or staged.
- Phase 2 report revisions were not implemented.

## Concerns

- `npm run lint` remains unavailable because ESLint is not installed.
- Connectivity retains its established HTTP-200 compatibility status despite now using a typed terminal no-SQL response.
- Rollback clarification/recovery components remain in `Ask.tsx` by binding ruling; their safety depends on the covered no-SQL response routing gate.

## Commit

- `ed46fde` — `test: verify two-lane report generation`
- Fix round 1 — this commit
