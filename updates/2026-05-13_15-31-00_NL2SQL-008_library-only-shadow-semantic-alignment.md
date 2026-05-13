# NL2SQL-008 Library-Only Shadow Semantic Alignment

## Summary
- Closed the remaining semantic-comparison blind spot for library-only `inventory_collection_age` prompts after fresh 2026-05-13 live telemetry still showed the latest compare falling back to raw SQL hash comparison.
- `GeminiService` semantic comparison now accepts library-only collection-age SQL without a location predicate and also extracts scope literals from legacy predicates of the form `LOWER(alias.name) ILIKE LOWER('...')`.
- Fresh live `/api/nl` traffic for `Show me the average age of items in the Neilson Library collection` now records `SQL comparison: true (semantic_sql_signature)` in the latest 2026-05-13 shadow compare, even though raw SQL hashes still differ.

## Files Changed
- `backend/services/GeminiService.php`
- `backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
- `planning/tickets.md`
- `updates/2026-05-13_15-31-00_NL2SQL-008_library-only-shadow-semantic-alignment.md`

## Validation Evidence
- Tightened `backend/tests/GeminiServiceShadowSemanticComparisonTest.php` to use the exact live forced-legacy SQL shape for the library-only Neilson Library prompt.
- Verified the new regression fails before the extractor fix with:
  - `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
  - failure: `Expected: '{"family":"inventory_collection_age","age_basis":"publication_year","campus":"smith college","library":"%neilson library%","location":null}' Actual: NULL`
- Verified the regression passes after the `GeminiService` extractor patch with:
  - `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
- Fresh live runtime check:
  - `POST /api/nl` for `Show me the average age of items in the Neilson Library collection`
  - Result: `route=builder_intent`, `routeReason=family_contract_supported:inventory_collection_age`, `dataSource=folio`, `has_sql=true`, `error=null`
- Regenerated Step 8 report:
  - `bash planning/baseline/report_nl2sql_shadow_metrics.sh 2026-05-13`

## Current Report State
- [planning/baseline/reports/2026-05-13_nl2sql-008-shadow-metrics.md](../planning/baseline/reports/2026-05-13_nl2sql-008-shadow-metrics.md) now shows:
  - `shadow_compare events: 6`
  - `shadow_error events: 1`
  - `Provider fallback warning count: 3`
  - `Latest Shadow Compare` at `2026-05-13T15:23:47+00:00`
  - latest compare `SQL comparison: true (semantic_sql_signature)` and `Raw SQL hash match: false`
- The day is still blocked with `BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH` because the diagnostic forced-legacy request at `2026-05-13T15:22:00+00:00` created a `legacy_freeform -> builder_intent` compare for the covered `inventory_collection_age` family.
- The same day also still contains one `shadow_error` caused by provider high demand.

## Notes
- `nl2sql_force_legacy` was restored to `false` immediately after the legacy SQL inspection request.
- Treat the latest compare as proof that the semantic-comparison bug is fixed, but do not use 2026-05-13 as qualifying Step 8 evidence.
- The next clean evidence day should avoid forced-legacy diagnostics and should not include provider-demand shadow errors.