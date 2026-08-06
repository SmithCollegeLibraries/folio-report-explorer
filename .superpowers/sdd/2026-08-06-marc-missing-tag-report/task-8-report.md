# Task 8 — Identifier export controls and truncation feedback

## Scope

Implemented the frontend portion of the MARC missing-tag report export flow:

- Added the `ReportExportKind` client type and sent optional `exportKind` in report-run requests.
- Added the report-owned `identifierExportAvailable` capability without exposing identifier-source metadata.
- Added **Export FOLIO UUID list** only for reports advertising that capability.
- Preserved **Export CSV** and made its file request explicitly use `worklist`.
- Carried the server `truncated` status through polling into both inline and file result models.
- Added the 100,000-row cap notice above inline results and file previews without replacing the full CSV download action.

## TDD evidence

The focused frontend test run was RED before implementation. It reported the expected missing behaviors:

```text
Unable to find an accessible element with the role "button" and name "Export FOLIO UUID list"
expected undefined to be true
Unable to find an accessible element with the role "alert"
```

After implementation, the focused suite was GREEN:

```text
Test Files  3 passed (3)
Tests  10 passed (10)
```

The added coverage verifies capability-gated identifier export submission, capability absence, table polling truncation propagation, and truncation feedback for both inline and file-mode results while the full file-download link remains available.

## Verification

```bash
cd frontend && npm test -- --run src/pages/Reports.test.tsx src/hooks/useJobPolling.test.tsx src/components/ResultsTable.truncation.test.tsx
# 3 files passed, 10 tests passed

cd frontend && npm test
# 36 files passed, 185 tests passed

cd frontend && npm run build
# exited 0

git diff --check -- <Task 8 frontend files>
# exited 0
```

`npm run lint` could not run in this checkout because the configured `eslint .`
script resolves to `sh: eslint: command not found`; no dependencies were changed
to work around that environment issue. The frontend test commands and Vite build
emit the pre-existing Browserslist currency advisory, and the test runner emits
its localStorage experimental warning.

## Files

- `frontend/src/types/schema.ts`
- `frontend/src/api/client.ts`
- `frontend/src/pages/ReportDetail.tsx`
- `frontend/src/hooks/useJobPolling.ts`
- `frontend/src/components/ResultsTable.tsx`
- `frontend/src/pages/Reports.test.tsx`
- `frontend/src/hooks/useJobPolling.test.tsx`
- `frontend/src/components/ResultsTable.truncation.test.tsx`
- `.superpowers/sdd/2026-08-06-marc-missing-tag-report/task-8-report.md`
