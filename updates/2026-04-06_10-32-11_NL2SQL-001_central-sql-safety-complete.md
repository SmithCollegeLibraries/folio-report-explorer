# Update: Central SQL Safety Complete

- Timestamp: 2026-04-06 10:32:11
- Ticket: NL2SQL-001
- Status: Completed

## Summary
- Implemented centralized SQL safety hardening for multi-statement rejection and blocked-table policy.
- Enforced the same policy in synchronous execute, background submit, and NL parse flow.
- Fixed NL controller handling to return clean 403 validation errors (no stack trace leakage).

## Changes Made
- Strengthened SQL safety validation to:
  - reject empty SQL
  - reject non-SELECT/CTE statements
  - reject multiple statements via semicolon parsing outside literals/comments
- Added blocked schema/table policy validation with explicit `users` and `perms` protections.
- Applied policy checks in:
  - `/api/execute`
  - `/api/query/submit`
  - `/api/nl` parse path
- Added `InvalidArgumentException` handling in NL endpoint for clean error responses.

## Files Changed
- [backend/services/SqlBuilderService.php](../backend/services/SqlBuilderService.php)
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [backend/controllers/FolioQueryController.php](../backend/controllers/FolioQueryController.php)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-04-06_10-32-11_NL2SQL-001_central-sql-safety-complete.md](2026-04-06_10-32-11_NL2SQL-001_central-sql-safety-complete.md)

## Validation Evidence
- `php -l backend/services/SqlBuilderService.php` passed.
- `php -l backend/controllers/FolioQueryController.php` passed.
- `php -l backend/services/GeminiService.php` passed.
- `POST /api/execute` with `SELECT 1; SELECT 2;` returned error: `Only a single SELECT statement is allowed.`
- `POST /api/execute` with `SELECT id FROM users.users__t LIMIT 1;` returned error: `Query references blocked table: users.users__t`.
- `POST /api/query/submit` rejected both multi-statement and blocked-table inputs.
- `POST /api/execute` with `SELECT 1 AS ok` succeeded.
- `POST /api/nl` for borrower prompt now returns clean 403-style error payload instead of uncaught stack trace.

## Open Risks or Follow-ups
- Table reference extraction relies on regex over FROM/JOIN tokens; deeply complex SQL/CTE edge cases may need parser-level hardening later.
- NL2SQL-000 remains in review due operational quota/high-demand errors in some baseline prompts.

## Next Ticket
- NL2SQL-002 - Builder Identifier Validation
