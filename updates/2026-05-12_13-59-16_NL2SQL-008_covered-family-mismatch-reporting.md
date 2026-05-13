# NL2SQL-008 Covered-Family Mismatch Reporting

## Summary
- Tightened the Step 8 daily report so covered-family `legacy_freeform -> builder_intent` divergences are treated as a first-class blocker signal instead of disappearing into generic SQL hash mismatches.
- Regenerated the 2026-05-12 report and confirmed the current local blocker is now explicit: `Covered-family legacy-primary mismatch count: 1`, affecting `inventory_collection_age`.

## Files Changed
- `planning/baseline/report_nl2sql_shadow_metrics.sh`
- `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md`
- `planning/tickets.md`

## Validation Evidence
- `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`
- `bash planning/baseline/report_nl2sql_shadow_metrics.sh 2026-05-12`
- Regenerated report now includes:
  - `Covered-family legacy-primary mismatch count: 1`
  - `Covered-Family Legacy-Primary Divergences: inventory_collection_age`
  - `Latest Covered-Family Legacy-Primary Divergence` block with timestamp, prompt fingerprint, route reasons, and SQL hash match state

## Open Risks
- This still does not resolve the underlying `inventory_collection_age` divergence; it makes it impossible to miss during Step 8 review.
- Provider fallback warnings remain elevated on the same day, so route/mismatch trends should still be interpreted alongside provider stability.

## Next Step
- Decide whether covered deterministic families should be counted as automatic Step 8 blockers whenever the primary route remains legacy while the shadow route is `family_contract_supported:*`, starting with `inventory_collection_age`.