# Update: Baseline Script Ready

- Timestamp: 2026-04-06 09:39:27
- Ticket: NL2SQL-000
- Status: In Progress

## Summary
- Added an executable script to capture NL baseline results for all 10 prompts.
- Verified script fails safely with clear error message when API is unavailable.

## Changes Made
- Added capture script that:
  - checks `/api/health`
  - posts prompts to `/api/nl`
  - writes timestamped output JSON under `planning/baseline/outputs`
- Updated ticket progress notes.

## Files Changed
- [planning/baseline/capture_nl_baseline.sh](../planning/baseline/capture_nl_baseline.sh)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-04-06_09-39-27_NL2SQL-000_baseline-script-ready.md](2026-04-06_09-39-27_NL2SQL-000_baseline-script-ready.md)

## Validation Evidence
- `chmod +x planning/baseline/capture_nl_baseline.sh` succeeded.
- Script execution result: health check failed cleanly because API is not running.

## Open Risks or Follow-ups
- Local runtime is still blocked by Docker PHP image build failure.
- Need either local runtime fix or alternate reachable API environment to complete baseline output capture.

## Next Ticket
- Continue NL2SQL-000 by resolving runtime blocker and generating baseline output artifact.
