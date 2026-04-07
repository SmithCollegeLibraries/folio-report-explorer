# Update: Observability and Replay Harness In Progress

- Timestamp: 2026-04-06 11:52:16
- Ticket: NL2SQL-007
- Status: In Progress

## Summary
- Added Step 7 structured telemetry for NL generation outcomes and validation failures.
- Added replay harness to compare current `/api/nl` behavior against baseline prompts.
- Executed a full 10-prompt replay and generated pass/fail report artifacts.

## Changes Made
- Added NL telemetry fields in `GeminiService` for:
  - model and prompt version,
  - route and routeReason,
  - finishReason,
  - prompt fingerprint,
  - schema context hash/version,
  - request attempts and elapsed time.
- Added validation-failure telemetry events for malformed structured outputs and parse/validation failures.
- Added replay script:
  - `planning/baseline/replay_nl_regression.sh`
- Added threshold definition:
  - `planning/baseline/NL2SQL-007-threshold.md`
- Added replay artifacts:
  - `planning/baseline/outputs/2026-04-06_11-51-11_nl2sql-007-replay-results.json`
  - `planning/baseline/reports/2026-04-06_11-51-11_nl2sql-007-replay-report.md`

## Files Changed
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [planning/baseline/replay_nl_regression.sh](../planning/baseline/replay_nl_regression.sh)
- [planning/baseline/NL2SQL-007-threshold.md](../planning/baseline/NL2SQL-007-threshold.md)
- [planning/tickets.md](../planning/tickets.md)
- [planning/baseline/outputs/2026-04-06_11-51-11_nl2sql-007-replay-results.json](../planning/baseline/outputs/2026-04-06_11-51-11_nl2sql-007-replay-results.json)
- [planning/baseline/reports/2026-04-06_11-51-11_nl2sql-007-replay-report.md](../planning/baseline/reports/2026-04-06_11-51-11_nl2sql-007-replay-report.md)
- [updates/2026-04-06_11-52-16_NL2SQL-007_observability-replay-in-progress.md](2026-04-06_11-52-16_NL2SQL-007_observability-replay-in-progress.md)

## Validation Evidence
- `php -l backend/services/GeminiService.php` passed.
- `bash -n planning/baseline/replay_nl_regression.sh` passed.
- `./planning/baseline/replay_nl_regression.sh http://localhost:8090/api` executed successfully.
- Replay summary:
  - total=10
  - pass=7
  - fail=3
  - regressionsOnBaselineSuccess=3
  - passRate=70%
  - gateMet=false

## Open Risks or Follow-ups
- Current replay failures are quota-driven Gemini API errors for baseline-success prompts (P03, P04, P07).
- Acceptance gate is currently failing due transient/quota errors, not deterministic SQL-policy regressions.
- If needed, rerun harness with a higher-quota API key before final Step 7 completion signoff.

## Next Ticket
- Continue NL2SQL-007 validation reruns until gate criteria are met, then proceed to NL2SQL-008.
