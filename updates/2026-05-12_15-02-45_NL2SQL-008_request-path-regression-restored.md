# NL2SQL-008 Request-Path Regression Restored

## Summary
- Restored the standalone request-path regression for the covered-family recovery path in `backend/tests/GeminiServiceIntentRequestPathTest.php`.
- The failure was not a runtime NL2SQL defect; the test bootstrap had fallen behind `QueryFamilyCompilerService` after schema-manifest enforcement was introduced, so the test died before it could exercise the Step 8 request-path logic.
- The harness now loads `QueryFamilySchemaManifestService` and the `@app/data/query_family_schema_manifests.json` alias the same way the compiler-focused tests already do.

## Files Changed
- `backend/tests/GeminiServiceIntentRequestPathTest.php`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/GeminiServiceIntentRequestPathTest.php`
- `php backend/tests/GeminiServiceShadowSemanticComparisonTest.php`
- `php backend/tests/GeminiServiceLegacyPromptGuidanceTest.php`

## Step 8 Impact
- This does not change live routing behavior.
- It restores executable regression coverage for the prompt-recovery branch that keeps covered-family collection-age prompts on the deterministic family path instead of silently falling back when the model returns a mismatched family contract.