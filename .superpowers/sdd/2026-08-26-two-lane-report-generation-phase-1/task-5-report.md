# Task 5 report: unified Ask results with generation provenance

## Scope

- Added the stable frontend `GenerationProvenance` contract and accepted public labels.
- Rendered `Verified pattern` (green/neutral) and `AI-built` (blue) as accessible `role="note"` provenance markers.
- Added an SQL-first Ask response selector: successful SQL always uses the shared Results, Related follow-ups, and Output SQL experience.
- Retained clarification and exploratory-recovery UI/handlers strictly for no-SQL legacy rollback response shapes. `ExploratoryRecoveryPanel.tsx` remains in place.
- Added the compact no-SQL `sql_generation_failed` message and Retry action, without recovery/correction details.
- Replaced clarification-oriented progress copy with neutral generation and automatic-repair copy.

## TDD evidence

RED was run before production changes:

```text
npm test -- src/components/AskTrustNotice.test.tsx src/pages/Ask.errorFormatting.test.ts
5 failed, 26 passed
```

The expected failures showed that canonical provenance was invisible, AI-built remained titled as the legacy AI-assisted result, and SQL-first/terminal-failure selectors did not exist.

After a second focused RED for the explicit response-shape selector:

```text
npm test -- src/pages/Ask.errorFormatting.test.ts
3 failed, 24 passed
```

The new `getAskResponseView()` cases were then implemented. GREEN verification:

```text
npm test -- src/components/AskTrustNotice.test.tsx src/pages/Ask.errorFormatting.test.ts src/pages/Ask.followUp.test.ts
3 files passed, 36 tests passed
```

## Changed files

- `frontend/src/types/schema.ts`
- `frontend/src/components/AskTrustNotice.tsx`
- `frontend/src/components/AskTrustNotice.test.tsx`
- `frontend/src/pages/Ask.tsx`
- `frontend/src/pages/Ask.errorFormatting.test.ts`

`Ask.followUp.test.ts` was run as required and remains unchanged.

## Verification

- Focused suite: 3 files, 36 tests passed.
- Full frontend suite: 39 files, 213 tests passed.
- Production build: passed (`tsc -b && vite build`).
- `git diff --check`: passed.
- Lint: the prescribed `npm run lint` could not run because `eslint` is not installed in this checkout (`sh: eslint: command not found`). No dependencies or lockfiles were changed to work around that pre-existing environment issue.

## Self-review

- The response selector gives SQL first priority, so legacy `needsClarification`, validation-summary, mode, and recovery fields cannot override executable SQL.
- Terminal `sql_generation_failed` responses select only the compact technical message and Retry action; rollback recovery renders only for no-SQL legacy validation summaries.
- The notice accepts only the stable provenance pair for its label path and keeps assumptions/review notices advisory.
- Follow-up context and query feedback helpers remain unchanged and continue to use the SQL result path.

## Concerns

- Lint remains unverified solely because the project does not provide its `eslint` executable. Build and all frontend tests pass; Vite emitted its existing Browserslist-age and chunk-size warnings.

## Fix round 1: review findings

### Changes

- Query-reuse candidates now carry trusted stored generation provenance from query-job metadata when it exists. The frontend preserves a valid stored label and derives the matching public label when needed. Legacy candidates with no stored provenance are explicitly presented as `AI-built`, because reuse does not re-verify them against a canonical compiler.
- The Ask reuse path uses the same provenance-bearing `NlResponse` for accepted and edited SQL, so its normal result experience always has exactly one label.
- Assumption details now say only that executable SQL passed safety and preflight checks (including automatic repairs). They no longer claim semantic validation.
- Only the asynchronous no-SQL `sql_generation_failed` notice is announced with `role="alert"` and `aria-live="assertive"`; AI-built provenance remains a nonblocking `role="note"`.

### RED/GREEN evidence

RED before implementation:

```text
frontend focused tests: 6 failures
backend/tests/PreviousSuccessfulQueryReuseServiceTest.php: failed because stored provenance was omitted
```

GREEN:

```text
npm test -- src/pages/Ask.queryReuse.test.ts src/components/ExploratoryAssumptionsPanel.test.tsx src/pages/Ask.errorFormatting.test.ts
3 files passed, 36 tests passed

php backend/tests/PreviousSuccessfulQueryReuseServiceTest.php
PreviousSuccessfulQueryReuseServiceTest passed
```

Final verification:

- Full frontend suite: 39 files, 216 tests passed.
- Frontend build: passed.
- PHP syntax check for `PreviousSuccessfulQueryReuseService.php`: passed.
- `git diff --check`: passed.
- Lint remained unavailable because `eslint` is not installed; no dependency changes were made.

## Fix round 2: edited reuse provenance

An edited reuse candidate is no longer treated as compiler-unchanged. `buildReusedNlResult()` compares the selected SQL with the stored candidate SQL and assigns `AI-built` whenever text differs. The four-case reuse matrix now covers unchanged verified SQL, edited verified SQL, unchanged stored AI-built SQL, and legacy SQL without provenance.

TDD evidence:

```text
RED: edited verified SQL rendered "Verified pattern" instead of "AI-built".
GREEN focused suite: 2 files, 8 tests passed.
```

Final verification: full frontend suite passed (39 files, 215 tests), frontend build passed, and `git diff --check` passed. Lint remained unavailable because `eslint` is not installed.
