# Update: Index Recommendations Heuristic Fallback

- Timestamp: 2026-04-07 08:57:47
- Ticket: NL2SQL-100
- Status: In Progress

## Summary
- Added a deterministic fallback recommendation path so index suggestions can still be produced when Gemini output is malformed or empty.
- Wired fallback selection into the existing endpoint and surfaced recommendation source metadata to the frontend.
- Updated the History UI to clearly show fallback source and backend warning messages.

## Changes Made
- Extended `IndexRecommendationService` with deterministic workload heuristics:
  - parse table aliases and SQL predicates from `sampleSql`,
  - score columns by JOIN/WHERE/ORDER-BY usage weighted by frequency/latency,
  - generate composite and single-column index candidates,
  - dedupe and filter against existing indexes.
- Updated `FolioQueryController::actionQueryIndexRecommendations()`:
  - invoke deterministic fallback when Gemini recommendations are empty,
  - return `recommendationSource` as `gemini`, `heuristic`, or `none`,
  - preserve warning details when Gemini fails.
- Updated frontend types and History panel rendering:
  - added `recommendationSource` and `warnings` fields,
  - display heuristic title when fallback is used,
  - render warning banner for degraded AI responses.

## Files Changed
- [backend/services/IndexRecommendationService.php](../backend/services/IndexRecommendationService.php)
- [backend/controllers/FolioQueryController.php](../backend/controllers/FolioQueryController.php)
- [frontend/src/types/schema.ts](../frontend/src/types/schema.ts)
- [frontend/src/pages/History.tsx](../frontend/src/pages/History.tsx)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-04-07_08-57-47_NL2SQL-100_index-recommendations-heuristic-fallback.md](2026-04-07_08-57-47_NL2SQL-100_index-recommendations-heuristic-fallback.md)

## Validation Evidence
- PHP syntax checks passed:
  - `php -l backend/services/IndexRecommendationService.php`
  - `php -l backend/controllers/FolioQueryController.php`
- Frontend build passed:
  - `npm run build`
- Endpoint smoke test passed:
  - `POST /api/query/index-recommendations` returned:
    - `recommendationSource: "heuristic"`
    - `recommendationCount: 8`
    - warning about malformed Gemini JSON (expected degraded path)

## Open Risks or Follow-ups
- SQL signal parsing is regex-based and conservative; a parser-backed approach could improve precision.
- Recommendation quality should be validated with EXPLAIN ANALYZE before creating indexes in production.
- Optional next step: implement retry-with-reprompt before fallback to increase Gemini hit rate.
