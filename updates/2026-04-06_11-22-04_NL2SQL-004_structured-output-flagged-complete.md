# Update: Structured Output (Flagged) Complete

- Timestamp: 2026-04-06 11:22:04
- Ticket: NL2SQL-004
- Status: Completed

## Summary
- Implemented a feature-flagged structured QueryIntent generation path for `/api/nl`.
- Preserved the legacy freeform SQL generation behavior when the flag is disabled.
- Added robust malformed JSON handling and clean runtime errors for invalid model output.

## Changes Made
- Added `nl2sqlIntentMode` flag wiring from settings/env in backend params.
- Exposed and persisted `nl2sql_intent_mode` through settings display and settings save API whitelist.
- Added intent-mode branch in `GeminiService::generateSql()` that:
  - requests JSON-only model output,
  - parses and validates QueryIntent payload,
  - translates intent to query definition,
  - builds SQL via `SqlBuilderService`,
  - safely inlines builder params for compatibility with existing raw SQL execution flow.
- Added tolerant JSON parsing helpers (fence stripping + balanced object extraction fallback).

## Files Changed
- [backend/config/params.php](../backend/config/params.php)
- [backend/services/SettingsService.php](../backend/services/SettingsService.php)
- [backend/controllers/FolioQueryController.php](../backend/controllers/FolioQueryController.php)
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-04-06_11-22-04_NL2SQL-004_structured-output-flagged-complete.md](2026-04-06_11-22-04_NL2SQL-004_structured-output-flagged-complete.md)

## Validation Evidence
- `php -l backend/services/GeminiService.php` passed.
- `php -l backend/controllers/FolioQueryController.php` passed.
- `php -l backend/services/SettingsService.php` passed.
- `php -l backend/config/params.php` passed.
- Reflection test of `parseIntentResponse`:
  - valid JSON parsed successfully,
  - malformed input raised clean runtime error (`Model returned malformed intent JSON...`).
- QueryIntent contract compatibility check with builder naming:
  - `inventory_items` sample intent validated (`intent_valid=true`),
  - translated successfully to query definition.

## Open Risks or Follow-ups
- Full end-to-end `/api/nl` runtime verification with live Gemini calls was not executed in this pass because local API runtime was not available on `localhost:8080`.
- Intent mode currently assumes builder-compatible constructs; richer unsupported intent fallback routing is deferred to NL2SQL-005.

## Next Ticket
- NL2SQL-005 - Deterministic Router
