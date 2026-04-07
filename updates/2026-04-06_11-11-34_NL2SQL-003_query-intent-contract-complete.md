# Update: Query Intent Contract Complete

- Timestamp: 2026-04-06 11:11:34
- Ticket: NL2SQL-003
- Status: Completed

## Summary
- Defined a server-side QueryIntent v1 contract for structured NL2SQL inputs.
- Implemented a structured validator that returns path/code/message error objects.
- Implemented translation from QueryIntent to SqlBuilder query-definition format.

## Changes Made
- Added `QueryIntentService` with:
  - QueryIntent v1 contract validation.
  - Structured error reporting (`valid`, `errors`, `normalizedIntent`).
  - Translation to builder shape (`tables`, `columns`, `filters`, `joins`, `orderBy`, `groupBy`, `having`, `distinct`, `limit`).
- Added `QueryIntentValidationException` for structured contract failures.
- Added additive helper methods in `GeminiService`:
  - `validateQueryIntent($intent)`
  - `intentToQueryDefinition($intent)`
- Kept current `/api/nl` freeform SQL generation behavior unchanged.

## Files Changed
- [backend/services/QueryIntentService.php](../backend/services/QueryIntentService.php)
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-04-06_11-11-34_NL2SQL-003_query-intent-contract-complete.md](2026-04-06_11-11-34_NL2SQL-003_query-intent-contract-complete.md)

## Validation Evidence
- `php -l backend/services/QueryIntentService.php` passed.
- `php -l backend/services/GeminiService.php` passed.
- Executed PHP validation snippet:
  - Valid intent returned `valid=true` with normalized payload.
  - `intentToQueryDefinition` returned builder-compatible query definition.
  - Invalid intent returned structured errors with explicit `path`, `code`, and `message` fields.

## Open Risks or Follow-ups
- QueryIntent contract is additive and not yet active in runtime NL generation flow.
- Runtime adoption will occur under the upcoming structured-output flag and router steps.

## Next Ticket
- NL2SQL-004 - Gemini Structured Output (Flagged)
