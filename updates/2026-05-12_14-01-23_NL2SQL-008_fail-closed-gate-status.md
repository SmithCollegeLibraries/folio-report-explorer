# NL2SQL-008 Fail-Closed Gate Status

## Summary
- Converted the Step 8 gate worksheet from manual `TODO` placeholders into automatic fail-closed statuses driven by the daily report data.
- Regenerated the 2026-05-12 report and confirmed the day is now classified as `BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH` because the covered `inventory_collection_age` prompt still diverges from `legacy_freeform` primary SQL to deterministic `builder_intent` shadow SQL.

## Files Changed
- `planning/baseline/report_nl2sql_shadow_metrics.sh`
- `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md`
- `planning/baseline/NL2SQL-008-shadow-operations-checklist.md`
- `planning/tickets.md`

## Validation Evidence
- `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`
- `bash planning/baseline/report_nl2sql_shadow_metrics.sh 2026-05-12`
- Regenerated report now includes:
  - `Required period day status: BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH`
  - `Compare/error trend acceptable: NO`
  - `Covered-family legacy-primary mismatches acceptable: NO`

## Open Risks
- The gate is now honest, but the underlying `inventory_collection_age` divergence remains unresolved.
- Provider fallback warnings remain elevated in the same report, so provider stability is still part of Step 8 risk review.

## Next Step
- Decide whether to force deterministic primary mode for covered families, or otherwise remove covered-family legacy-primary mismatches before counting more qualifying Step 8 days.