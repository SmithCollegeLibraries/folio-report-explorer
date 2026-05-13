# NL2SQL-008 Contract Slot Policy

## Summary
- Promoted the collection-age optional-scope rule from local Gemini recovery logic into the checked-in query family contract.
- `backend/data/query_family_contracts.json` now declares `inventory_collection_age.slots.inferencePolicies.location = explicit_prompt_only`.
- `QueryFamilySlotService` now exposes a shared `slotRequiresExplicitPromptEvidence()` helper, and `GeminiService` uses that policy when deciding whether a recovered `location` slot is allowed to survive.
- The family slot system prompt now tells the model not to emit `slots.location` for library-only collection-age prompts.

## Files Changed
- `backend/data/query_family_contracts.json`
- `backend/services/QueryFamilyContractService.php`
- `backend/services/QueryFamilySlotService.php`
- `backend/services/GeminiService.php`
- `backend/tests/QueryFamilyContractServiceTest.php`
- `backend/tests/QueryFamilySlotServiceTest.php`
- `backend/tests/GeminiServiceQueryFamilySelectionTest.php`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/QueryFamilyContractServiceTest.php`
- `php backend/tests/QueryFamilySlotServiceTest.php`
- `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`
- `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`
- `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`
- `php backend/tests/QueryFamilyCompilerServiceTest.php`
- `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
- `php -l backend/services/GeminiService.php`
- `php -l backend/services/QueryFamilySlotService.php`
- `php -l backend/services/QueryFamilyContractService.php`

## Step 8 Impact
- This makes the explicit-only `location` rule part of the deterministic family contract instead of a Gemini-only convention.
- The same contract/prompt/helper pattern can now be extended to other covered families with optional narrowing scopes.
- This reduces the chance that future model output or prompt-repair code silently reintroduces false-positive sub-location filters for broader library-scoped requests.