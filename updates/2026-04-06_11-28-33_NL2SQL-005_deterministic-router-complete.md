# Update: Deterministic Router Complete

- Timestamp: 2026-04-06 11:28:33
- Ticket: NL2SQL-005
- Status: Completed

## Summary
- Implemented deterministic server-side routing for intent mode.
- Added intent capability classification that does not rely on model self-rating.
- Added safe fallback routing to legacy SQL generation for unsupported intent constructs.

## Changes Made
- Updated `GeminiService::generateSql()` to support internal forced-legacy execution for fallback.
- Added deterministic classifier method to evaluate normalized intent support for builder route.
- Added routing behavior in intent mode:
  - `builder_intent` for supported intents.
  - `legacy_fallback` for unsupported intents and builder conversion failures.
- Added route metadata in `/api/nl` response payload (`route`, `routeReason`).
- Added structured routing logs under `nl2sql.routing` category.

## Files Changed
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-04-06_11-28-33_NL2SQL-005_deterministic-router-complete.md](2026-04-06_11-28-33_NL2SQL-005_deterministic-router-complete.md)

## Validation Evidence
- `php -l backend/services/GeminiService.php` passed.
- Reflection classifier checks passed:
  - supported intent (`joins: auto`, small table count) -> `supported=true`, `reason=intent_supported`.
  - oversized table set -> `supported=false`, `reason=too_many_tables_for_builder_route`.
  - explicit joins -> `supported=false`, `reason=explicit_joins_unsupported_in_builder_route`.
- IDE diagnostics report no errors in modified files.

## Open Risks or Follow-ups
- Full repeated-prompt routing stability checks with live Gemini responses were not run in this pass.
- Route metadata is additive in NL responses; downstream consumers should ignore unknown fields if not yet typed.

## Next Ticket
- NL2SQL-006 - Deterministic Context and Retry Policy
