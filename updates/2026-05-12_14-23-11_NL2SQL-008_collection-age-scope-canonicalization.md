# NL2SQL-008 Collection-Age Scope Canonicalization

## Summary
- Fixed the remaining semantic-scope bug for the covered `inventory_collection_age` family on the prompt `What is the average age of items in the Neilson Reference collection?`.
- `GeminiService` now repairs malformed non-empty collection-age slots from prompt text instead of only filling blanks, so combined scopes like `library = Neilson Reference` and `location = Reference` are canonicalized back to `Neilson Library` and `Reference collection` before family compilation.
- `QueryFamilySlotService` now preserves explicit collection labels during location matching, so `Reference collection` no longer degrades into the broader `%Reference%` filter that was producing false-positive scope expansion.

## Files Changed
- `backend/services/GeminiService.php`
- `backend/services/QueryFamilySlotService.php`
- `backend/tests/GeminiServiceFamilyIntentBranchTest.php`
- `backend/tests/QueryFamilySlotServiceTest.php`
- `backend/tests/QueryFamilyCompilerServiceTest.php`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/QueryFamilySlotServiceTest.php`
- `php backend/tests/QueryFamilyCompilerServiceTest.php`
- `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`
- `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`
- `php backend/tests/GeminiServiceShadowModePolicyTest.php`
- `php -l backend/services/GeminiService.php`
- `php -l backend/services/QueryFamilySlotService.php`
- Live `/api/nl` validation for `What is the average age of items in the Neilson Reference collection?` now returns:
  - `route=builder_intent`
  - `routeReason=family_contract_supported:inventory_collection_age`
  - SQL predicates including `il.name ILIKE '%Neilson Library%'` and `ilo.name ILIKE '%Reference collection%'`

## Step 8 Status
- Regenerated `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md` after the live validation.
- Current report values:
  - `Events scanned: 4`
  - `shadow_compare events: 4`
  - `Covered-family legacy-primary mismatch count: 1`
  - `Required period day status: BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH`
- The day is still blocked because of the earlier same-day legacy-primary compare recorded before the covered-family primary-intent fix, not because the current deterministic collection-age scope remains incorrect.

## Open Risk
- The legacy shadow variant may still diverge from the deterministic SQL text for `inventory_collection_age`; this change removes the deterministic false-positive scope broadening, but it does not guarantee SQL hash parity with the legacy path.