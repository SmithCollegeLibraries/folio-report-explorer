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
