# Update: Collection-Age Location Scope Recovery

- Timestamp: 2026-05-13 16:33:47
- Ticket: NL2SQL-008
- Status: Completed

## Summary
- Generalized collection-age prompt recovery so explicit collection/location wording like "Hillyer locked stacks collection" preserves a concrete `location` scope instead of rolling up to broad library totals.
- Allowed `inventory_collection_age` payloads to be scoped by either `library` or explicit `location`, while keeping unscoped collection-age payloads invalid.
- Added guard coverage so broad wording like "Neilson Library collection" remains library-scoped and does not invent a location.

## Changes Made
- Passed recovered/model library context into collection-age location recovery so library qualifiers can be stripped from explicit sub-location phrases.
- Added generic collection/location phrase extraction and normalization, including plural `stacks` to `Stack` normalization.
- Made the collection-age compiler library filter optional when validation is satisfied by an explicit location.
- Preserved both word orders for Neilson Reference recovery: `Neilson Reference collection` and `reference collection in Neilson Library`.

## Files Changed
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [backend/services/QueryFamilySlotService.php](../backend/services/QueryFamilySlotService.php)
- [backend/services/QueryFamilyCompilerService.php](../backend/services/QueryFamilyCompilerService.php)
- [backend/tests/GeminiServiceFamilyIntentBranchTest.php](../backend/tests/GeminiServiceFamilyIntentBranchTest.php)
- [backend/tests/QueryFamilySlotServiceTest.php](../backend/tests/QueryFamilySlotServiceTest.php)
- [backend/tests/QueryFamilyCompilerServiceTest.php](../backend/tests/QueryFamilyCompilerServiceTest.php)

## Validation Evidence
- `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`: passed.
- `php backend/tests/QueryFamilySlotServiceTest.php`: passed.
- `php backend/tests/QueryFamilyCompilerServiceTest.php`: passed.
- `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`: passed.
- `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`: passed.
- `php backend/tests/GeminiServiceIntentRequestPathTest.php`: passed.
- `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`: passed.
- `php -l backend/services/GeminiService.php`, `php -l backend/services/QueryFamilySlotService.php`, and `php -l backend/services/QueryFamilyCompilerService.php`: no syntax errors.
- Live `/api/nl` smoke for `What is the average age of the Hillyer locked stacks collection?` returned `route=builder_intent`, `routeReason=family_contract_supported:inventory_collection_age`, included an `ilo.name ILIKE` location predicate, and mentioned `Locked Stack` in the generated SQL.

## Open Risks or Follow-ups
- Production still needs this commit deployed before the user will see the repaired Hillyer locked-stacks behavior there.
- The Step 8 required-period shadow gate remains blocked until clean qualifying shadow traffic is collected; this fix does not retroactively qualify the noisy 2026-05-13 evidence day.

## Next Ticket
- NL2SQL-008 - Continue shadow-mode qualification with clean collection-age traffic.
