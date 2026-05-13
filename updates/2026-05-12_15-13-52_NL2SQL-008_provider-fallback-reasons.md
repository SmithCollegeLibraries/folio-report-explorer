# NL2SQL-008 Provider Fallback Reason Telemetry

## Summary
- Added structured warning-level `nl2sql.provider_fallback` telemetry to `GeminiService` so provider fallbacks are no longer only visible as undifferentiated warning lines.
- Each structured fallback event now records `context`, `sourceProvider`, `targetProvider`, `statusCode`, and a normalized `reasonCode`.
- Updated the daily shadow metrics report to bucket provider fallback reasons from structured events while still counting older raw warning lines as `legacy_unstructured`.

## Files Changed
- `backend/services/GeminiService.php`
- `planning/baseline/report_nl2sql_shadow_metrics.sh`
- `backend/tests/GeminiServiceProviderFallbackTelemetryTest.php`
- `backend/tests/ShadowMetricsProviderFallbackReportTest.sh`
- `planning/tickets.md`

## Validation Evidence
- `php backend/tests/GeminiServiceProviderFallbackTelemetryTest.php`
- `bash backend/tests/ShadowMetricsProviderFallbackReportTest.sh`
- `php -l backend/services/GeminiService.php`
- `bash -n planning/baseline/report_nl2sql_shadow_metrics.sh`
- `bash planning/baseline/report_nl2sql_shadow_metrics.sh 2026-05-12`

## Current Report State
- Regenerated `planning/baseline/reports/2026-05-12_nl2sql-008-shadow-metrics.md` after the report change.
- The report now shows `Provider fallback warning count: 45` and `Provider Fallback Reasons: 45 legacy_unstructured`.
- That is expected for the current day because the existing app log entries were emitted before this code change; the first post-change fallback event will appear under its structured `reasonCode` instead of collapsing into the raw-warning bucket.

## Step 8 Impact
- Provider fallback noise is now attributable instead of opaque.
- This does not change routing behavior or the current Step 8 gate state.
- The next live fallback event will tell us immediately whether current warning volume is still dominated by quota exhaustion or whether a different provider-failure reason starts to surface.