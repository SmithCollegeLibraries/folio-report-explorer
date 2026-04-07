# NL2SQL-000 Failure Evidence

## Evidence Sources
- Full merged baseline artifact: [outputs/2026-04-06_10-20-49_nl2sql-000-merged-results.json](outputs/2026-04-06_10-20-49_nl2sql-000-merged-results.json)
- Targeted "also" prompt run: captured in terminal validation notes (2026-04-06)

## Failure Example 1: PII Access Not Blocked
- Prompt ID: P07
- Prompt: "Show me overdue items and also show the borrowers"
- Observed SQL includes direct join to `users.users__t`.
- Why this is a failure:
  - User feedback stated borrower/user data should not be freely queryable.
  - This demonstrates table-policy enforcement is not guaranteed at NL generation stage.

## Failure Example 2: Multi-Intent Prompt Is Not Reliably Honored
- Prompt: "Count open loans by campus and also list overdue borrowers"
- Observed behavior:
  - Model returned a single query for open loans by campus only.
  - The second intent (overdue borrowers) was silently dropped.
- Why this is a failure:
  - Multi-intent prompts are handled inconsistently (sometimes split, sometimes partial, sometimes single-intent only).
  - This supports the need for structured intent routing and deterministic handling.

## Additional Operational Failures Observed
- P05/P06: transient Gemini high-demand errors.
- P08/P09/P10: free-tier token quota errors.
- These are operational blockers that require retry/backoff and batching controls.
