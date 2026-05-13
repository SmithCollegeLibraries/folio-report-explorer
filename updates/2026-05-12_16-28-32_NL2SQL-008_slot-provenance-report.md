# NL2SQL-008 Slot Provenance Report

## Summary
- Updated the daily Step 8 shadow metrics report to summarize `slotProvenance` from covered-family `builder_intent` `nl2sql.generated` telemetry.
- The report now includes a `builder_intent generated events with slot provenance` summary line and a `Slot Provenance Signals` section with per-slot state buckets.
- Added a dedicated shell regression that proves the report surfaces states such as `location = policy_omitted_explicit_prompt_only` and `library = prompt_repaired`.
- Ran the live 2026-05-12 report after the parser change; it now includes the new section but currently shows `0` provenance-bearing generated events because no fresh post-change `nl2sql.generated` entries have been logged yet.

## Files Changed
- `planning/baseline/report_nl2sql_shadow_metrics.sh`
- `backend/tests/ShadowMetricsSlotProvenanceReportTest.sh`
- `planning/tickets.md`

## Validation Evidence
- `bash backend/tests/ShadowMetricsSlotProvenanceReportTest.sh`
- `bash backend/tests/ShadowMetricsProviderFallbackReportTest.sh`
- `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`
- `bash planning/baseline/report_nl2sql_shadow_metrics.sh 2026-05-12`

## Live Report Status
- `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md` now shows `builder_intent generated events with slot provenance: 0`.
- The new `Slot Provenance Signals` section is present and currently reads `None`.
- The gate remains `BLOCKED_COVERED_FAMILY_LEGACY_PRIMARY_MISMATCH`; this slice improves observability only and does not change the existing blocker state.