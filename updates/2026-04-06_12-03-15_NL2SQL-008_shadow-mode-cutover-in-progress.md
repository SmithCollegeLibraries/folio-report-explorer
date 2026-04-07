# Update: Shadow Mode and Cutover In Progress

- Timestamp: 2026-04-06 12:03:15
- Ticket: NL2SQL-008
- Status: In Progress

## Summary
- Implemented Step 8 shadow-mode and controlled-cutover scaffolding with safe defaults.
- Added an emergency rollback toggle to force legacy mode immediately.
- Wired `/api/nl` through a new shadow-aware entrypoint while keeping response format stable.
- Added repeatable daily shadow-metrics reporting artifacts and executed an initial smoke run.

## Changes Made
- Added runtime params and settings exposure for:
  - `nl2sql_primary_mode` (`auto|legacy|intent`)
  - `nl2sql_shadow_mode`
  - `nl2sql_shadow_users`
  - `nl2sql_shadow_sample_percent`
  - `nl2sql_force_legacy`
- Added settings save whitelist support for the new Step 8 controls.
- Added `GeminiService::generateSqlWithShadow($prompt, $campus, $userId)`:
  - executes configured primary mode,
  - optionally executes alternate mode in shadow for selected users,
  - logs non-blocking shadow comparison telemetry.
- Added shadow telemetry events:
  - `nl2sql.shadow_compare`
  - `nl2sql.shadow_error`
- Added primary-mode route metadata normalization:
  - `primary_legacy_mode` for normal legacy primary routing,
  - `forced_legacy_mode` when emergency rollback toggle is active.
- Added Step 8 runbook/checklist for daily shadow validation operations.
- Added `planning/baseline/report_nl2sql_shadow_metrics.sh` to summarize `nl2sql.shadow_compare` and `nl2sql.shadow_error` telemetry per day.
- Generated first daily report artifact for 2026-04-06.

## Files Changed
- [backend/config/params.php](../backend/config/params.php)
- [backend/services/SettingsService.php](../backend/services/SettingsService.php)
- [backend/controllers/FolioQueryController.php](../backend/controllers/FolioQueryController.php)
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [planning/tickets.md](../planning/tickets.md)
- [planning/baseline/NL2SQL-008-shadow-operations-checklist.md](../planning/baseline/NL2SQL-008-shadow-operations-checklist.md)
- [planning/baseline/report_nl2sql_shadow_metrics.sh](../planning/baseline/report_nl2sql_shadow_metrics.sh)
- [planning/baseline/reports/2026-04-06_nl2sql-008-shadow-metrics.md](../planning/baseline/reports/2026-04-06_nl2sql-008-shadow-metrics.md)
- [updates/2026-04-06_12-03-15_NL2SQL-008_shadow-mode-cutover-in-progress.md](2026-04-06_12-03-15_NL2SQL-008_shadow-mode-cutover-in-progress.md)

## Validation Evidence
- `php -l backend/services/GeminiService.php` passed.
- `php -l backend/controllers/FolioQueryController.php` passed.
- `php -l backend/services/SettingsService.php` passed.
- `php -l backend/config/params.php` passed.
- `/api/nl` smoke checks succeeded after wiring and returned valid SQL with route metadata.
- Live rollback verification via API settings toggles:
  - baseline (`nl2sql_primary_mode=auto`, `nl2sql_force_legacy=false`) returned SQL with `route=legacy_freeform` and `routeReason=primary_legacy_mode`.
  - `nl2sql_primary_mode=intent` with rollback disabled produced an intent-validation error for the sample prompt.
  - enabling `nl2sql_force_legacy=true` immediately restored successful SQL generation with `route=legacy_freeform` and `routeReason=forced_legacy_mode`.
  - settings were restored to baseline (`auto`, rollback off, shadow off) after test.
- Live shadow smoke verification via API settings toggles:
  - enabled `nl2sql_primary_mode=legacy`, `nl2sql_shadow_mode=true`, `nl2sql_shadow_users=all`, `nl2sql_shadow_sample_percent=100` for one request.
  - primary `/api/nl` response succeeded with `route=legacy_freeform` and `routeReason=primary_legacy_mode`.
  - telemetry emitted `nl2sql.shadow_error` in `backend/runtime/logs/app.log` (shadow intent failed validation for this prompt).
  - restored settings to baseline (`auto`, shadow off, empty shadow users, rollback off) immediately after the smoke run.
- Shadow metrics script validation:
  - `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh` passed.
  - `./planning/baseline/report_nl2sql_shadow_metrics.sh 2026-04-06` generated report at `planning/baseline/reports/2026-04-06_nl2sql-008-shadow-metrics.md`.

## Open Risks or Follow-ups
- Shadow mode compares two model executions and can increase API usage; keep `nl2sql_shadow_mode=false` until quota/billing supports it.
- Step 8 validation gate requires sustained metrics over time; this update delivers scaffolding and immediate rollback controls, not final cutover signoff.

## Next Ticket
- Continue NL2SQL-008 by enabling a small shadow cohort and collecting daily comparison metrics.
