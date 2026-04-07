# NL2SQL-007 Replay Acceptance Threshold

## Purpose
Define the regression gate used by the NL2SQL-007 replay harness.

## Gate Criteria
- `regressionsOnBaselineSuccess <= 0`
- `overallPassRate >= 80%`

## Definitions
- `regressionsOnBaselineSuccess`: count of prompts where baseline status was `success` and current replay status regressed to `error`, or changed data source unexpectedly.
- `overallPassRate`: `(pass / total prompts) * 100` from the replay report.

## Harness Parameters
- `MAX_REGRESSIONS_ON_BASELINE_SUCCESS` default: `0`
- `MIN_OVERALL_PASS_RATE` default: `80`

These values are consumed by [planning/baseline/replay_nl_regression.sh](replay_nl_regression.sh).
