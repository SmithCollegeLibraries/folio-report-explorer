# NL2SQL-008 Shadow Report Route Divergence Summary

## Summary
- Upgraded the Step 8 daily report generator so it no longer stops at hash counts. It now reports route divergence counts, provider-fallback warning counts, top route-pair breakdowns, and a latest shadow-compare snapshot.
- Regenerated the 2026-05-12 report after the local smoke. The updated report makes the current cutover issue explicit: the telemetry path is healthy, but the covered `inventory_collection_age` prompt still diverges from `legacy_freeform` primary SQL to `builder_intent` shadow SQL.

## Files Changed
- `planning/baseline/report_nl2sql_shadow_metrics.sh`
- `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md`
- `planning/tickets.md`

## Validation Evidence
- `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`
- `bash planning/baseline/report_nl2sql_shadow_metrics.sh 2026-05-12`
- Regenerated report now includes:
  - `Route divergence count: 1`
  - `Provider fallback warning count: 18`
  - `Top Route Pairs: legacy_freeform -> builder_intent`
  - Latest compare snapshot showing `primaryRouteReason=primary_legacy_mode`, `shadowRouteReason=family_contract_supported:inventory_collection_age`, and `SQL hash match: false`

## Open Risks
- This slice improves observability, not correctness. The active Step 8 question is now whether the covered-family mismatch is acceptable legacy-vs-intent divergence or evidence that the legacy primary path should no longer be trusted for this family.
- Provider fallback warnings remain present in the same day’s telemetry, so model/provider stability can still influence shadow comparisons.

## Next Step
- Investigate the `inventory_collection_age` divergence and decide whether to treat the deterministic shadow SQL as the expected answer and tighten Step 8 acceptance around covered-family legacy-primary mismatches.