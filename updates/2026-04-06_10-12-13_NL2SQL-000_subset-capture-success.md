# Update: Subset Capture Success

- Timestamp: 2026-04-06 10:12:13
- Ticket: NL2SQL-000
- Status: In Progress

## Summary
- Added quota-friendly subset mode to baseline capture script.
- Successfully captured P01 and P02 in one run with no API quota failures.
- Logged the new subset artifact for replay evidence.

## Changes Made
- Enhanced baseline capture script to accept optional prompt ID list (e.g., `P01,P02`).
- Ran subset capture and produced timestamped output artifact.
- Updated runbook and ticket notes with subset workflow and latest artifact.

## Files Changed
- [planning/baseline/capture_nl_baseline.sh](../planning/baseline/capture_nl_baseline.sh)
- [planning/baseline/NL2SQL-000-runbook.md](../planning/baseline/NL2SQL-000-runbook.md)
- [planning/tickets.md](../planning/tickets.md)
- [planning/baseline/outputs/2026-04-06_10-11-10_nl2sql-000-baseline-results.json](../planning/baseline/outputs/2026-04-06_10-11-10_nl2sql-000-baseline-results.json)
- [updates/2026-04-06_10-12-13_NL2SQL-000_subset-capture-success.md](2026-04-06_10-12-13_NL2SQL-000_subset-capture-success.md)

## Validation Evidence
- Subset command succeeded:
  - `./planning/baseline/capture_nl_baseline.sh http://localhost:8090/api P01,P02`
- Result file contains both prompt IDs and successful responses.
- SQL for P02 matches expected open-loans-by-campus pattern.

## Open Risks or Follow-ups
- Full 10-prompt baseline gate is still pending due free-tier quota limits on larger runs.
- Need continued batched captures (or alternate key) to complete NL2SQL-000 validation gate.

## Next Ticket
- Continue NL2SQL-000 by capturing remaining prompts in batches and documenting the two known failure examples.
