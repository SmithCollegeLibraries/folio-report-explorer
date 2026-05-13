# NL2SQL-008 Slot Scope Hardening

## Summary
- Started a systemic hardening slice for covered-family slot boundaries.
- For `inventory_collection_age`, optional `location` scope is no longer allowed to appear just because the prompt mentioned a broader `library` scope.
- Library-only prompts such as `What is the average age of items in Neilson Library?` now recover only `library = Neilson Library` and compile without an `inventory.location__t` predicate.
- Explicit reference-collection prompts such as `What is the average age of the Neilson Library Reference collection?` still recover `location = Neilson Reference` and compile with the location predicate.

## Files Changed
- `backend/services/GeminiService.php`
- `backend/tests/GeminiServiceFamilyIntentBranchTest.php`
- `backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`
- `backend/tests/QueryFamilyCompilerServiceTest.php`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`
- `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`
- `php backend/tests/QueryFamilyCompilerServiceTest.php`
- `php -l backend/services/GeminiService.php`
- Live `/api/nl` check for `What is the average age of items in Neilson Library?` returned `route=builder_intent` with no `ilo.name ILIKE` predicate.
- Live `/api/nl` check for `What is the average age of the Neilson Library Reference collection?` returned `route=builder_intent` with `ilo.name ILIKE '%Neilson Reference%'`.

## Step 8 Impact
- This is a policy hardening change, not another one-off phrase patch.
- It establishes the rule that optional covered-family scope slots must be explicit in the prompt or absent.
- That same rule can now be applied to other covered families so broader library/campus prompts do not silently collapse into narrower location-level SQL.