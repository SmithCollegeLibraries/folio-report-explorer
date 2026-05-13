# NL2SQL-008 Collection-Age Query-Shape Hardening

## Summary
- Optimized the deterministic `inventory_collection_age` SQL shape for broad library-scoped prompts such as `What is the average age of items in Neilson Library?`.
- The compiler now scopes item rows first, groups them by instance in a `scoped_instances` CTE, and computes a weighted publication-year average from per-instance `item_count` instead of joining every scoped item row directly to publication data.
- Removed the outer `LIMIT 100` from the aggregate query because the aggregate already returns a single row and the limit did not reduce work.
- Updated collection-age semantic shadow comparison so the new weighted aggregate shape remains equivalent to legacy `AVG(age)` SQL and does not regress Step 8 comparisons on harmless aggregate-shape differences.

## Files Changed
- `backend/services/QueryFamilyCompilerService.php`
- `backend/services/GeminiService.php`
- `backend/tests/QueryFamilyCompilerServiceTest.php`
- `backend/tests/GeminiServiceFamilyCompilerResultTest.php`
- `backend/tests/GeminiServiceShadowSemanticComparisonTest.php`

## Validation Evidence
- `php backend/tests/QueryFamilyCompilerServiceTest.php`
- `php backend/tests/GeminiServiceFamilyCompilerResultTest.php`
- `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
- `php backend/tests/GeminiServiceIntentRequestPathTest.php`

## Open Risks
- This slice hardens the SQL shape and keeps the shadow comparator aligned, but it was not live-validated against a running FOLIO database in this pass.
- The next runtime check should re-run the Neilson Library collection-age prompt through `/api/nl` or an EXPLAIN-capable local stack and confirm the previous 30-minute timeout no longer reproduces.