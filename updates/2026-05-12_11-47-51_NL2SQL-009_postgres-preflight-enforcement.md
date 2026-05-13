# Update: Postgres Preflight Enforcement

- Timestamp: 2026-05-12 11:47:51
- Ticket: NL2SQL-009
- Status: Completed

## Summary
- Centralized Postgres EXPLAIN preflight in a shared service and enforced it across generated SQL paths.
- Added structured controller-side telemetry for `postgres_preflight` validation failures.
- Updated the Ask UI to show `422` preflight failures as query-validation errors instead of generic AI failures.

## Changes Made
- Added `SqlPreflightService` and reused it from `/api/nl`, `/api/query/submit`, and generated `/api/execute`.
- Added execute-path gating so generated SQL is preflighted while manual SQL keeps its existing execution-time behavior.
- Added `nl2sql.validation_failure` telemetry emission for controller-side preflight failures.
- Added focused backend and frontend regression tests for preflight behavior and Ask-page error formatting.

## Files Changed
- [backend/services/SqlPreflightService.php](../backend/services/SqlPreflightService.php)
- [backend/controllers/FolioQueryController.php](../backend/controllers/FolioQueryController.php)
- [backend/tests/SqlPreflightServiceTest.php](../backend/tests/SqlPreflightServiceTest.php)
- [backend/tests/FolioQueryControllerExecutePreflightTest.php](../backend/tests/FolioQueryControllerExecutePreflightTest.php)
- [frontend/src/pages/Ask.tsx](../frontend/src/pages/Ask.tsx)
- [frontend/src/pages/Ask.errorFormatting.test.ts](../frontend/src/pages/Ask.errorFormatting.test.ts)

## Validation Evidence
- `php backend/tests/SqlPreflightServiceTest.php` passed.
- `php backend/tests/FolioQueryControllerExecutePreflightTest.php` passed.
- `npm test -- src/pages/Ask.errorFormatting.test.ts` passed.
- Live local check: `POST /api/execute` with generated SQL `SELECT EXTRACT(EPOCH FROM 1) AS broken_age` returned `422` with Postgres connected.

## Open Risks or Follow-ups
- Ask now distinguishes `422` preflight failures, but the frontend still does not support a true clarification-response contract for ambiguous prompts.
- Shadow-mode cutover metrics still need more qualifying days before release gating is complete.

## Next Ticket
- NL2SQL-010 - Runtime Mode Parity Preflight