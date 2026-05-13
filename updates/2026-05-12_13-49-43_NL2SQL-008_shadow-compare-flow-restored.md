# NL2SQL-008 Shadow Compare Flow Restored

## Summary
- Recreated the `php`, `worker`, and `export-worker` containers after local test AI keys were added so the running runtime would pick up the refreshed `.env`.
- Re-ran a controlled local Step 8 smoke against `http://localhost:8080` with `primary_mode=legacy`, `shadow_mode=true`, `shadow_users=all`, and `shadow_sample_percent=100`.
- Confirmed the web logging fix works end to end: the new log region now contains `nl2sql.shadow_compare` telemetry, the daily shadow report sees the event, and no fresh Yii request-context dump was appended even though provider-fallback warnings were logged.

## Files Changed
- `planning/tickets.md`
- `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md`
- `updates/2026-05-12_13-49-43_NL2SQL-008_shadow-compare-flow-restored.md`

## Validation Evidence
- Redacted env/runtime checks after container recreate showed both provider keys as present in `.env`, present in the running `php` container, and masked in `GET /api/settings`.
- Controlled `POST /api/nl` for “What is the average age of items in the Neilson Reference collection?” returned SQL successfully via `route=legacy_freeform`.
- Newly appended `backend/runtime/logs/app.log` lines included:
  - `warning` `nl2sql.provider_fallback` entries for Gemini quota exhaustion.
  - one `info` `nl2sql.telemetry` record with `event=nl2sql.shadow_compare`, `primaryRoute=legacy_freeform`, `shadowRoute=builder_intent`, `primaryDataSource=folio`, `shadowDataSource=folio`, and `sqlHashMatch=false`.
- The appended log region contained no new `$_SERVER = [` request-context dump.
- Regenerated report at `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md` now shows:
  - `Events scanned: 1`
  - `shadow_compare events: 1`
  - `shadow_error events: 0`
  - `SQL hash mismatch count: 1`
  - `Data source mismatch count: 0`

## Blockers / Risks
- Step 8 telemetry plumbing is now working locally, but the quality gate is still open: the current local smoke produced a hash mismatch between the legacy primary SQL and the intent shadow SQL.
- Gemini quota exhaustion still triggers provider fallback warnings locally, so provider stability remains a variable in shadow comparisons.
- Historical `backend/runtime/logs/app.log` entries still contain previously logged credential material from before the `logVars` fix; those credentials should still be treated as exposed and rotated externally.

## Next Ticket
- `NL2SQL-008 - Shadow Mode and Cutover`