# NL2SQL-008 Web Telemetry Target and Local AI Key Blocker

## Summary
- Confirmed the Step 8 no-event blind spot in the local web runtime: successful `nl2sql.shadow_compare` events were emitted at info level under `nl2sql.telemetry`, but `backend/config/web.php` only logged warnings/errors, so successful shadow comparisons never reached `backend/runtime/logs/app.log` for the daily report script.
- Closed the concurrent secret-leak path by disabling Yii request-context logging on the web file targets. Warning/error entries will no longer append `_SERVER`, cookies, or provider/database environment variables to `app.log`.
- Added a dedicated info-level `nl2sql.telemetry` file target so future successful shadow comparisons are captured in `app.log` without turning on all application-level info logging.

## Files Changed
- `backend/config/web.php`
- `backend/tests/WebLogConfigRedactionTest.php`

## Validation Evidence
- `php backend/tests/WebLogConfigRedactionTest.php`
- `php -l backend/config/web.php`
- `curl -s http://localhost:8080/api/settings | jq '{ai_provider, gemini_api_key, gemini_model, openai_api_key, openai_model, nl2sql_intent_mode, nl2sql_primary_mode, nl2sql_shadow_mode, nl2sql_shadow_users, nl2sql_shadow_sample_percent, nl2sql_force_legacy}'`
  - Result: current local web runtime reports both AI keys as blank.
- Redacted checks confirmed the current local blocker is operational, not code-path related:
  - `.env` reports `GEMINI_API_KEY=<empty>` and `OPENAI_API_KEY=<empty>`.
  - `docker compose exec php env` reports `GEMINI_API_KEY=<empty>` and `OPENAI_API_KEY=<empty>` in the running PHP container.

## Blockers / Risks
- Historical `backend/runtime/logs/app.log` entries already contain previously logged credential material. This code change stops new leaks, but existing keys should be treated as exposed and rotated outside the repo/runtime patch.
- Live local shadow smoke could not be revalidated end-to-end after the logging fix because `/api/nl` now fails with `AI API key not configured. Set GEMINI_API_KEY or OPENAI_API_KEY in .env.` until local provider credentials are restored.

## Next Ticket
- `NL2SQL-008 - Shadow Mode and Cutover`