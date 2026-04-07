# Update: Deterministic Context and Retry Policy Complete

- Timestamp: 2026-04-06 11:37:05
- Ticket: NL2SQL-006
- Status: Completed

## Summary
- Completed deterministic context hardening for NL2SQL schema prompts.
- Added bounded, relevance-ranked context selection to reduce prompt variance.
- Added transient-only Gemini retry/backoff policy with retry and timeout telemetry.

## Changes Made
- Updated `FolioSchemaService::loadDomainHints()` to load hints in deterministic order and dedupe by key with latest active value winning.
- Added prompt-term extraction and relevance scoring helpers to cap context payloads for:
  - table descriptions,
  - domain vocabulary,
  - query examples.
- Updated `FolioSchemaService::buildSchemaContext($prompt = '')` to support prompt-aware, bounded context generation.
- Updated `GeminiService` NL generation paths to pass the user prompt into schema context building for both:
  - legacy SQL route,
  - structured intent route.
- Added retry wrapper for Gemini requests with:
  - transient HTTP retry policy (408, retryable 429, 5xx),
  - transient transport/timeout retry policy,
  - exponential backoff + jitter,
  - explicit non-retry handling for quota/billing style 429 failures.
- Added retry/outcome logs in `nl2sql.retry` category with context, attempts, elapsed milliseconds, status, and timeout markers.

## Files Changed
- [backend/services/FolioSchemaService.php](../backend/services/FolioSchemaService.php)
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-04-06_11-37-05_NL2SQL-006_deterministic-context-retry-complete.md](2026-04-06_11-37-05_NL2SQL-006_deterministic-context-retry-complete.md)

## Validation Evidence
- `php -l backend/services/FolioSchemaService.php` passed.
- `php -l backend/services/GeminiService.php` passed.
- IDE diagnostics report no errors in modified service files.

## Open Risks or Follow-ups
- Live end-to-end retry behavior against Gemini API was not exercised in this pass.
- Retry configuration is parameterized (`geminiMaxRetries`, `geminiRetryBaseDelayMs`) and currently relies on defaults if unset.

## Next Ticket
- NL2SQL-007 - Observability and Regression Harness
