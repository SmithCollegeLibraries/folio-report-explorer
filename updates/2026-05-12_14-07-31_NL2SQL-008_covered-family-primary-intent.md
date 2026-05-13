# NL2SQL-008 Covered-Family Primary Intent

## Summary
- Updated `GeminiService::generateSqlWithShadow(...)` so covered-family prompts no longer honor configured legacy primary mode by default. When a prompt resolves to a supported deterministic family and `nl2sqlForceLegacy` is false, the primary path now upgrades to deterministic intent mode.
- This aligns the live runtime with the hardening policy: legacy remains available for unsupported prompts and emergency override, but covered families no longer default back to `legacy_freeform` just because Step 8 is configured with `nl2sql_primary_mode=legacy`.

## Files Changed
- `backend/services/GeminiService.php`
- `backend/tests/GeminiServiceShadowModePolicyTest.php`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/GeminiServiceShadowModePolicyTest.php`
- `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`
- `php -l backend/services/GeminiService.php`
- Live `/api/nl` validation with current settings:
  - `nl2sql_primary_mode=legacy`
  - `nl2sql_shadow_mode=true`
  - `nl2sql_force_legacy=false`
  - Result: `route=builder_intent`, `routeReason=family_contract_supported:inventory_collection_age`
- Regenerated `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md` now shows the latest compare as `intent / builder_intent -> legacy / legacy_freeform`.

## Open Risks
- The 2026-05-12 report still remains blocked because it contains the earlier same-day legacy-primary mismatch event from before this fix.
- SQL hash mismatch still remains between the deterministic and legacy variants for `inventory_collection_age`; this patch changes which route is trusted as primary, not whether the two SQL texts converge.
- Provider fallback warnings remain present in the same day’s telemetry.

## Next Step
- Run the same covered-family smoke on the next qualifying shadow day and confirm the day no longer trips the covered-family legacy-primary blocker class.