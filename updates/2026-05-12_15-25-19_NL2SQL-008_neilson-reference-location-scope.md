# NL2SQL-008 Neilson Reference Location Scope

## Summary
- Fixed the collection-age prompt recovery for Neilson reference prompts.
- The previous recovery path normalized both `What is the age of the reference collection in Neilson Library?` and `What is the average age of items in the Neilson Reference collection?` to `library = Neilson Library` plus `location = Reference collection`.
- Live data validation showed that this abstract location label does not exist in the current inventory data, so the compiled deterministic SQL returned `NULL`.
- The recovery path now derives the concrete location scope `Neilson Reference`, and the legacy prompt rewrite now carries the same concrete location phrase into the shadow path.

## Validation Evidence
- Distinct live location rows at Smith College / Neilson scope include `SC Neilson Reference` and `SC Neilson Reference Oversize`.
- `loc.name ILIKE '%Reference collection%'` matched `0` items.
- `loc.name ILIKE '%Neilson Reference%'` matched `3631` items.
- `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`
- `php backend/tests/QueryFamilyCompilerServiceTest.php`
- `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`
- `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
- `php -l backend/services/GeminiService.php`

## Files Changed
- `backend/services/GeminiService.php`
- `backend/tests/GeminiServiceFamilyIntentBranchTest.php`
- `backend/tests/QueryFamilyCompilerServiceTest.php`
- `backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`
- `backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
- `planning/tickets.md`

## Step 8 Impact
- Covered collection-age prompts now recover the location scope that exists in live data instead of an abstract label.
- This fixes the deterministic `NULL` result for the Neilson reference collection-age prompt family without reopening the legacy-primary route.