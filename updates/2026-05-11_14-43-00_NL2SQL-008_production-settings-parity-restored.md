# Update: NL2SQL-008 Production Settings Parity Restored

- Timestamp: 2026-05-11 14:43:00
- Ticket: NL2SQL-008
- Status: In Progress

## Summary
- Verified that production code was already on commit `1bc3f36`, so the development/production mismatch was not a branch or deploy-content problem.
- Identified the real parity gap: production had a blank `backend/data/settings.json`, which left NL2SQL runtime flags at default values and kept `/api/nl` on the legacy freeform primary path.
- Restored the NL2SQL runtime settings needed for deterministic query-family behavior on production; the covered contributor/theses prompt then returned the correct deterministic result.

## Files Changed
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-05-11_14-43-00_NL2SQL-008_production-settings-parity-restored.md](2026-05-11_14-43-00_NL2SQL-008_production-settings-parity-restored.md)

## Validation Evidence
- Production `git rev-parse --short HEAD` returned `1bc3f36`.
- Production `backend/data/settings.json` was blank before the fix.
- With blank settings, the covered contributor/material-type prompt returned legacy freeform SQL that filtered `inventory.instance_type__t` and produced zero results.
- After restoring runtime flags `nl2sql_intent_mode=true`, `nl2sql_primary_mode=intent`, and `nl2sql_force_legacy=false`, rerunning the same prompt returned the expected deterministic contributor/material-type SQL and produced results.
- The landed clean-main validation pack still covers the same deterministic route on the current main baseline:
  - `php backend/tests/GeminiServiceQueryFamilySelectionTest.php`
  - `php backend/tests/QueryFamilyCompilerServiceTest.php`

## Blockers or Risks
- This parity issue sits outside the git diff: code deploy alone does not guarantee runtime behavior if server settings are empty or stale.
- Future deploys should preserve `backend/data/settings.json` or reapply the NL2SQL runtime flags explicitly during rollout.

## Next Ticket
- `NL2SQL-008 - Shadow Mode and Cutover`