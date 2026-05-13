# NL2SQL-008 Semantic Shadow Comparison

## Summary
- Added a family-specific semantic comparison signature for aligned `inventory_collection_age` SQL inside `GeminiService::logShadowComparison(...)`.
- The shadow telemetry now records both raw SQL hashes and semantic comparison fields so Step 8 can distinguish harmless SQL-shape differences from real semantic drift.
- Updated the Step 8 report script to prefer the semantic comparison result when present while still surfacing raw hash mismatch counts for debugging.

## Files Changed
- `backend/services/GeminiService.php`
- `backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
- `planning/baseline/report_nl2sql_shadow_metrics.sh`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
- `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`
- `php -l backend/services/GeminiService.php`
- `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`
- Recreated the live PHP runtimes with `docker compose up -d --force-recreate php worker export-worker`
- Ran a fresh live `/api/nl` request for `What is the average age of items in the Neilson Reference collection?`
- Regenerated `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md`

## Current Report State
- `Events scanned: 8`
- `SQL comparison match count: 1`
- `SQL comparison mismatch count: 7`
- `Raw SQL hash mismatch count: 8`
- Latest compare now shows:
  - `Primary mode/route: intent / builder_intent`
  - `Shadow mode/route: legacy / legacy_freeform`
  - `SQL comparison: true (semantic_sql_signature)`
  - `Raw SQL hash match: false`
- The current day remains `BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH` because of the earlier same-day covered-family primary-legacy event, not because the latest collection-age compare remains semantically divergent.

## Remaining Risk
- The semantic comparison currently covers the aligned `inventory_collection_age` family shape only. Other covered families will still fall back to raw SQL hash comparison until they get equivalent semantic signatures or deterministic SQL-shape convergence.