# NL2SQL-000 Baseline Capture Runbook

## Objective
Capture baseline NL->SQL outputs for 10 representative prompts and document known failures.

## Inputs
- Prompt set: [NL2SQL-000-prompts.json](NL2SQL-000-prompts.json)
- Target endpoint: `POST /api/nl`
- Optional execution endpoint: `POST /api/query/submit`

## Capture Procedure
1. Ensure API health is OK at `GET /api/health`.
2. For each prompt in the prompt file:
   - Submit prompt to `/api/nl`
   - Save response payload (sql, explanation, dataSource, error)
3. For successful SQL output, optionally submit for execution and capture job status.
4. Save raw responses to a timestamped baseline result file.

### Scripted Capture
- Full set:
   - `./planning/baseline/capture_nl_baseline.sh http://localhost:8090/api`
- Small sample set (quota-friendly):
   - `./planning/baseline/capture_nl_baseline.sh http://localhost:8090/api P01,P02`

## Required Output Artifact
- `planning/baseline/outputs/YYYY-MM-DD_HH-MM-SS_nl2sql-000-baseline-results.json`

## Known Failures To Track (from user feedback)
- Multi-statement SQL appears in some prompts (e.g., prompts containing "also").
- User-related/PII table access may still be possible in some cases.

## Current Blocker
- Local runtime blocker is resolved (containers start and API health is OK).
- Current blocker is Gemini free-tier input token quota exhaustion after high-token prompts.
- This blocks completing all 10 prompts in a single continuous run on free tier.

## Temporary Workaround
- Run capture in smaller batches after quota window reset.
- Or use a paid/alternate API key for uninterrupted full-run capture.
- Use the same prompt set and save results in the required output artifact format.
