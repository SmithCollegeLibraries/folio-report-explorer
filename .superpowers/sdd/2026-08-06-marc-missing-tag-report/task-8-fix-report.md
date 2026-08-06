# Task 8 review fix — File-mode truncation propagation coverage

## Scope

Added the missing file-mode regression coverage for `useJobPolling()`.

The completed file-status fixture includes `truncated: true`, a UUID preview,
and a download URL. The test asserts that the resulting `ExecuteResponse`
retains all three file-result properties:

- `outputMode: 'file'`
- `downloadUrl`
- `truncated: true`

No production change was required: the existing file conversion already copied
the server truncation flag and download metadata.

## Verification

```bash
cd frontend && npm test -- --run src/hooks/useJobPolling.test.tsx
# 1 file passed, 3 tests passed
```

Full frontend tests and the production build were rerun before commit.

## Files

- `frontend/src/hooks/useJobPolling.test.tsx`
- `.superpowers/sdd/2026-08-06-marc-missing-tag-report/task-8-fix-report.md`
