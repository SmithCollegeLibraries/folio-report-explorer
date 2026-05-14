# Update: Zine Collection Location Scope

- Timestamp: 2026-05-14 09:04:11
- Ticket: NL2SQL-008
- Status: Completed

## Summary
- Repaired collection-age prompts that ask for both item count and average age, such as `How many items are in the zine collection at Hillyer library and what is their average age?`.
- Split `collection at <library>` wording into a broad library token plus an explicit location scope so the generated SQL targets `ilo.name ILIKE '%Zine Collection%'` instead of collapsing the whole phrase into `il.name`.
- Added `item_count` as a supported deterministic `inventory_collection_age` output alongside `average_age_years`.

## Changes Made
- Added `item_count` to the collection-age family output contract and family slot prompt.
- Extended prompt recovery for named collection-at-library wording, preserving specific collection phrases as location scope.
- Kept Hillyer library wording broad as `Hillyer` so it matches the live `SC Hillyer Art Library` label.
- Updated collection-age SQL generation so combined count+age reports count all scoped items while computing average age only over rows with usable publication years.

## Files Changed
- [backend/data/query_family_contracts.json](../backend/data/query_family_contracts.json)
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [backend/services/QueryFamilyCompilerService.php](../backend/services/QueryFamilyCompilerService.php)
- [backend/tests/GeminiServiceFamilyIntentBranchTest.php](../backend/tests/GeminiServiceFamilyIntentBranchTest.php)
- [backend/tests/GeminiServiceQueryFamilySelectionTest.php](../backend/tests/GeminiServiceQueryFamilySelectionTest.php)
- [backend/tests/QueryFamilyCompilerServiceTest.php](../backend/tests/QueryFamilyCompilerServiceTest.php)
- [backend/tests/QueryFamilyContractServiceTest.php](../backend/tests/QueryFamilyContractServiceTest.php)

## Validation Evidence
- Live FOLIO data sample confirmed the relevant row: `SC Art Zine Collection | SCZAC | SC Hillyer Art Library`.
- Direct deterministic SQL against Smith College, `%Hillyer%`, and `%Zine Collection%` returned `item_count=134` and `average_age_years=5.8333333333333333`.
- Focused regression pack passed: `GeminiServiceFamilyIntentBranchTest`, `QueryFamilyCompilerServiceTest`, `QueryFamilyContractServiceTest`, `GeminiServiceQueryFamilySelectionTest`, `QueryFamilySlotServiceTest`, `GeminiServiceLegacyPromptGuidanceTest`, `GeminiServiceIntentRequestPathTest`, `GeminiServiceShadowSemanticComparisonTest`, `GeminiServiceFamilyCompilerResultTest`, `GeminiServiceSlotProvenanceTelemetryTest`, and `GeminiServiceFamilyMismatchTelemetryTest`.
- Syntax checks passed for `backend/services/GeminiService.php`, `backend/services/QueryFamilyCompilerService.php`, and `backend/services/QueryFamilySlotService.php`.

## Open Risks or Follow-ups
- The live `/api/nl` smoke could not complete because the AI provider returned a temporary high-demand error; deterministic recovery and compiled SQL were validated without relying on provider availability.
- This improves prompt recovery for named collection-at-library phrasing, but richer location aliasing should continue to be backed by live location vocabulary samples as more failures appear.

## Next Ticket
- NL2SQL-008 - Continue shadow-mode qualification with clean collection-age traffic.
