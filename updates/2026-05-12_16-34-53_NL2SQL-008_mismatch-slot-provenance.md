# NL2SQL-008 Mismatch Slot Provenance

## Summary
- Extended `slotProvenance` beyond the covered-family success path and onto family-mismatch telemetry.
- `GeminiService::maybeRouteQueryFamilyIntentResponse()` now enriches mismatch telemetry with model-output slot provenance before logging `family_contract_mismatch`, `family_fallback_guard`, or emergency-override `legacy_fallback` events.
- This keeps the same slot vocabulary visible even when the model returns the wrong family key and the request is guarded or forced through an override fallback.

## Files Changed
- `backend/services/GeminiService.php`
- `backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/GeminiServiceFamilyMismatchTelemetryTest.php`
- `php backend/tests/GeminiServiceIntentRequestPathTest.php`
- `php -l backend/services/GeminiService.php`

## Step 8 Impact
- Covered-family mismatch telemetry is no longer limited to expected/returned family keys; it now shows which returned slots came directly from the model.
- This makes future mismatch triage less dependent on replaying raw model JSON because the mismatch warning and override fallback both preserve slot-level context.