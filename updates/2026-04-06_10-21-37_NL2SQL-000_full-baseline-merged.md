# Update: Full Baseline Merged

- Timestamp: 2026-04-06 10:21:37
- Ticket: NL2SQL-000
- Status: In Review

## Summary
- Completed batched captures across all 10 baseline prompts.
- Produced consolidated merged baseline artifact with full prompt coverage.
- Documented two known failure examples with reproducible evidence.

## Changes Made
- Ran subset captures in quota-friendly 2-prompt batches.
- Merged subset artifacts into a single 10-prompt baseline file.
- Added dedicated failure evidence documentation.
- Updated ticket status from IN PROGRESS to IN REVIEW.

## Files Changed
- [planning/baseline/outputs/2026-04-06_10-11-10_nl2sql-000-baseline-results.json](../planning/baseline/outputs/2026-04-06_10-11-10_nl2sql-000-baseline-results.json)
- [planning/baseline/outputs/2026-04-06_10-14-43_nl2sql-000-baseline-results.json](../planning/baseline/outputs/2026-04-06_10-14-43_nl2sql-000-baseline-results.json)
- [planning/baseline/outputs/2026-04-06_10-18-00_nl2sql-000-baseline-results.json](../planning/baseline/outputs/2026-04-06_10-18-00_nl2sql-000-baseline-results.json)
- [planning/baseline/outputs/2026-04-06_10-18-14_nl2sql-000-baseline-results.json](../planning/baseline/outputs/2026-04-06_10-18-14_nl2sql-000-baseline-results.json)
- [planning/baseline/outputs/2026-04-06_10-18-25_nl2sql-000-baseline-results.json](../planning/baseline/outputs/2026-04-06_10-18-25_nl2sql-000-baseline-results.json)
- [planning/baseline/outputs/2026-04-06_10-20-49_nl2sql-000-merged-results.json](../planning/baseline/outputs/2026-04-06_10-20-49_nl2sql-000-merged-results.json)
- [planning/baseline/NL2SQL-000-failure-evidence.md](../planning/baseline/NL2SQL-000-failure-evidence.md)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-04-06_10-21-37_NL2SQL-000_full-baseline-merged.md](2026-04-06_10-21-37_NL2SQL-000_full-baseline-merged.md)

## Validation Evidence
- Merged artifact includes all 10 prompt IDs.
- Captured prompt statuses: P01-P04 and P07 success, P05/P06/P08/P09/P10 errors.
- Failure example 1: P07 SQL includes `users.users__t` join.
- Failure example 2: targeted "also" prompt returned only first intent, dropping second intent.

## Open Risks or Follow-ups
- Some failures are operational (high demand / quota) and may obscure functional behavior on specific prompts.
- Team review is still needed to accept NL2SQL-000 gate completion.

## Next Ticket
- NL2SQL-001 - Central SQL Safety Enforcement
