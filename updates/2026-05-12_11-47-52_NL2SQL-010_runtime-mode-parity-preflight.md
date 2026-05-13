# Update: Runtime Mode Parity Preflight

- Timestamp: 2026-05-12 11:47:52
- Ticket: NL2SQL-010
- Status: Completed

## Summary
- Added an admin-facing NL2SQL runtime parity preflight surface.
- The new preflight reports effective `nl2sql_*` settings, persisted runtime-settings presence, and artifact metadata.
- Missing or blank `settings.json` now produces an explicit warning state instead of remaining an undocumented deployment hazard.

## Changes Made
- Added `Nl2sqlRuntimePreflightService` to summarize effective runtime flags, persisted settings presence, and artifact hashes/version metadata.
- Added `GET /api/nl2sql-preflight` to expose the report from the web application.
- Registered the pretty URL rule and restricted the action alongside the existing settings/admin surface.

## Files Changed
- [backend/services/Nl2sqlRuntimePreflightService.php](../backend/services/Nl2sqlRuntimePreflightService.php)
- [backend/controllers/FolioQueryController.php](../backend/controllers/FolioQueryController.php)
- [backend/config/web.php](../backend/config/web.php)
- [backend/tests/Nl2sqlRuntimePreflightServiceTest.php](../backend/tests/Nl2sqlRuntimePreflightServiceTest.php)

## Validation Evidence
- `php backend/tests/Nl2sqlRuntimePreflightServiceTest.php` passed.
- `php -l backend/services/Nl2sqlRuntimePreflightService.php` passed.
- `php -l backend/controllers/FolioQueryController.php` passed.
- Live local check: `GET /api/nl2sql-preflight` returned `200` with `status=warning`, `hasSettingsFile=false`, and warnings about `settings.json` and legacy defaults.

## Open Risks or Follow-ups
- The endpoint makes parity drift visible, but it does not itself enforce deploy-time failure or settings repair.
- `NL2SQL-008` shadow-mode cutover remains the active release gate and still needs more qualifying days.

## Next Ticket
- NL2SQL-011 - Query Family Schema Manifests