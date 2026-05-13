# NL2SQL-008 Provenance Report Buckets

## Summary
- Expanded the daily Step 8 shadow metrics report so slot provenance is bucketed beyond `generated.builder_intent`.
- The report now counts provenance-bearing `generated.clarification` events and provenance-bearing `nl2sql.validation_failure` events, in addition to the existing `builder_intent` count.
- Added a source-qualified `Slot Provenance Sources` section so the report distinguishes `generated.builder_intent`, `generated.clarification`, `validation.family_contract_mismatch`, and `validation.family_fallback_guard`.
- Ran the fresh 2026-05-13 live report after the parser change; it is still `BLOCKED_NO_SHADOW_TELEMETRY`, so the new buckets currently have no live data.

## Files Changed
- `planning/baseline/report_nl2sql_shadow_metrics.sh`
- `backend/tests/ShadowMetricsSlotProvenanceReportTest.sh`
- `planning/tickets.md`

## Validation Evidence
- `bash backend/tests/ShadowMetricsSlotProvenanceReportTest.sh`
- `bash backend/tests/ShadowMetricsProviderFallbackReportTest.sh`
- `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`
- `bash planning/baseline/report_nl2sql_shadow_metrics.sh 2026-05-13`

## Live Report Status
- `planning/baseline/reports/2026-05-13_nl2sql-008-shadow-metrics.md` reports `No shadow telemetry events were found for this date.`
- Gate status is `BLOCKED_NO_SHADOW_TELEMETRY`.
- The parser change is live, but the new provenance buckets will stay empty until fresh 2026-05-13 shadow traffic is generated.