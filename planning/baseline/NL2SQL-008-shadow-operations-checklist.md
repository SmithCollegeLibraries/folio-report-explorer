# NL2SQL-008 Shadow Operations Checklist

## Purpose
Run a safe daily shadow-mode check and produce objective evidence for Step 8 cutover decisions.

## Pre-Run Safety
- Confirm API health: `curl -sS http://localhost:8090/api/health`
- Keep rollback available: `nl2sql_force_legacy=false` by default; set to `true` only for emergency fallback.
- Start with a small cohort and sample rate.

## Recommended Shadow Settings (Initial)
- `nl2sql_primary_mode=legacy`
- `nl2sql_shadow_mode=true`
- `nl2sql_shadow_users=<small allowlist or all for controlled smoke>`
- `nl2sql_shadow_sample_percent=5` (raise gradually)
- `nl2sql_force_legacy=false`

## Daily Run Steps
1. Apply shadow settings through `/api/settings`.
2. Execute representative `/api/nl` prompts for the cohort.
3. Generate daily metrics report:
   - `./planning/baseline/report_nl2sql_shadow_metrics.sh YYYY-MM-DD`
4. Review report from:
   - `planning/baseline/reports/YYYY-MM-DD_nl2sql-008-shadow-metrics.md`
5. Restore safe default if needed:
   - `nl2sql_primary_mode=auto`
   - `nl2sql_shadow_mode=false`
   - `nl2sql_shadow_users=""`
   - `nl2sql_force_legacy=false`

## What To Track Daily
- `shadow_compare` volume
- `shadow_error` volume and top error categories
- SQL hash match/mismatch rates
- Data source mismatches (`folio` vs `local`)
- Any rollback activations and reasons

## Decision Notes
- Mark each day as pass/fail against team-agreed thresholds.
- Treat any covered-family `legacy_freeform -> builder_intent` divergence as a blocked day until the mismatch is explained or removed.
- Require the full validation period before default cutover.
- If high-severity regressions appear, keep legacy primary and investigate before expanding cohort.
