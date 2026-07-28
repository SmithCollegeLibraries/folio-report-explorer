# Task 10 Report: Nonblocking Ask and History confidence notices

## Status

Complete on `feature/ask-ai-confidence-review`, based on `08667b7962a0cd68042c4fb63f72ec061590bd6a`.

## Delivered

- Added `AskTrustNotice`, an accessible `role="note"` presentation for AI-assisted reports.
- Kept canonical/deterministic reports free of trust notices.
- Distinguished ordinary exploratory reports (`AI-assisted report`) from flagged reports (`AI-assisted report — review flagged`).
- Rendered only the user-facing review message and documented assumptions; backend titles and technical confidence metadata are not displayed.
- Added the stable nonblocking sentence “This report was flagged for routine review.” without approval, confirmation, or acknowledgment controls.
- Replaced copy that assigned SQL review to the user. SQL remains available through the existing deliberate View SQL/tab/reuse controls.
- Added safe cautioned/superseded history advisories while preserving Follow-up, Save, Dashboard, Download, SQL, and suggestion actions.
- Added the typed `reviewAdvisory` history contract.

## TDD Evidence

Initial RED:

```text
Failed to resolve import "./AskTrustNotice"
expected fallback notice not to match /review ... sql/i
expected progress copy not to match /review ... sql/i
```

A second focused RED covered reuse copy before its exported safe message was implemented:

```text
ASK_REUSE_CANDIDATE_MESSAGE was undefined
```

GREEN focused verification:

```text
Test Files  4 passed (4)
Tests       35 passed (35)
```

Coverage includes canonical suppression, ordinary and flagged title distinction, routine-review copy, assumptions, absence of technical metadata/percentages and approval controls, safe history advisory rendering, and continued availability of Follow-up, Save, and Download.

## Verification

Focused suite:

```bash
cd frontend
npm test -- --run src/components/AskTrustNotice.test.tsx src/pages/Ask.errorFormatting.test.ts src/pages/Ask.followUp.test.ts src/pages/history/HistoryResultsModal.followUp.test.tsx
```

Full frontend suite:

```text
Test Files  33 passed (33)
Tests       171 passed (171)
```

Production build:

```text
tsc -b && vite build
✓ built in 5.63s
```

The build reports the repository's existing large-chunk advisory; it completes successfully.

`git diff --check` passed.

## Frontend-design influence

The frontend-design skill led to a restrained utilitarian/editorial treatment rather than a new component language: compact typography, an asymmetric left rule, the existing folio/sky/amber palette, and measured spacing. The ordinary notice is distinct but quiet; the flagged variant is stronger without red/alarm styling. Existing responsive flex and modal behavior remains unchanged.

## Self-review

- No notice is rendered when `mode === 'canonical'`.
- No confidence percentage, route, validator, schema, table, administrator note, or backend-provided title is rendered by the trust notice.
- The notices have no Approve, Continue, Confirm, or other acknowledgment control.
- Run, Save, Export/Download, Follow-up, re-run/reuse, and View SQL behavior is not gated or disabled.
- History renders only `reviewAdvisory.message` and a stable state-derived heading; it does not receive administrator review details.
- Unrelated dirty files and scratch artifacts were not staged or modified.

## Files

- `frontend/src/components/AskTrustNotice.tsx`
- `frontend/src/components/AskTrustNotice.test.tsx`
- `frontend/src/pages/Ask.tsx`
- `frontend/src/pages/Ask.errorFormatting.test.ts`
- `frontend/src/pages/history/HistoryResultsModal.tsx`
- `frontend/src/types/schema.ts`
- `.superpowers/sdd/task-10-report.md`
