# NL2SQL-008 Slot Provenance Telemetry

## Summary
- Added the first covered-family slot-provenance telemetry slice on the `builder_intent` success path.
- `GeminiService` now carries a `slotProvenance` map through collection-age prompt recovery and emits it on the structured `nl2sql.generated` event.
- The initial provenance states now visible in telemetry are `model_output`, `prompt_explicit`, `prompt_repaired`, `default_context`, and `policy_omitted_explicit_prompt_only`.
- For the library-only collection-age prompt, telemetry now makes the decision visible without inspecting SQL: `library = prompt_explicit`, `campus = default_context`, and `location = policy_omitted_explicit_prompt_only`.

## Files Changed
- `backend/services/GeminiService.php`
- `backend/tests/GeminiServiceSlotProvenanceTelemetryTest.php`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/GeminiServiceSlotProvenanceTelemetryTest.php`
- `php backend/tests/GeminiServiceFamilyIntentBranchTest.php`
- `php backend/tests/GeminiServiceIntentRequestPathTest.php`
- `php -l backend/services/GeminiService.php`

## Step 8 Impact
- This makes optional-scope policy decisions observable on the structured success-path telemetry, not just implicit in the compiled SQL.
- The next telemetry/reporting slice can now trend policy omissions and prompt-repair activity without reverse-engineering SQL predicates from app logs.