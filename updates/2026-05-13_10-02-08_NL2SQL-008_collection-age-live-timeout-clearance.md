# NL2SQL-008 Collection-Age Live Timeout Clearance

## Summary
- The previously timing-out Neilson Library collection-age prompt cleared successfully after the collection-age query-shape hardening change.
- This is user-confirmed live validation evidence that the broad library-scoped `inventory_collection_age` query no longer reproduces the earlier 30-minute `statement_timeout` failure in the current runtime.

## Validation Evidence
- User-confirmed successful rerun of the previously failing Neilson Library collection-age prompt on 2026-05-13.
- This validates the compiler-side change that scopes items first in `scoped_instances`, groups by instance, and computes the weighted publication-year average without the no-op outer `LIMIT 100`.

## Notes
- This update records successful live behavior, not a captured SQL timing or EXPLAIN artifact.
- If we want a stronger audit trail for Step 8, the next follow-up should capture the generated SQL and execution timing from a fresh controlled run or app-log entry.