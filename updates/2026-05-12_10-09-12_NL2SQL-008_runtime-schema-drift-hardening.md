# Update: NL2SQL-008 Runtime Schema Drift Hardening

- Timestamp: 2026-05-12 10:09:12
- Ticket: NL2SQL-008
- Status: Completed

## Summary
- Hardened the NL2SQL runtime against live-schema drift in `folio_source_record.records__t`, including `jsonb` MARC source content, the missing `state` column, and alias-based source-record predicates coming out of Ask.
- Closed the local dashboard/bootstrap drift and structured-intent drift slices by updating the MySQL bootstrap schema, refreshing the checked-in table/column/subtable caches, and normalizing physical MetaDB table names back to logical intent contract identifiers.
- Added regression coverage for AI provider fallback, prompt policy filtering, SQL execution normalization, dashboard bootstrap schema, and QueryIntent table normalization so these failures do not reappear silently.

## Changes Made
- Added runtime SQL normalization for Ask, submit, and execute flows so `parsed_record__content` is cast to `::text` before `ILIKE`/`NOT ILIKE`, stale `state = 'ACTUAL'` filters are rewritten to `COALESCE(deleted, false) = false`, and aliased MARC source projections are normalized before downstream predicates run.
- Made prompt assembly live-schema-aware so blocked `marctab` guidance is filtered out, `records__t` joins use the current `external_ids_holder__instance_id` contract, and MARC source-record instructions reflect `jsonb` plus deleted-flag semantics.
- Refreshed the checked-in schema discovery artifacts and planning/update notes, and added focused PHP regression tests covering the repaired drift seams.

## Files Changed
- [backend/controllers/FolioQueryController.php](../backend/controllers/FolioQueryController.php)
- [backend/data/column_cache.json](../backend/data/column_cache.json)
- [backend/data/subtable_cache.json](../backend/data/subtable_cache.json)
- [backend/data/table_mapping_cache.json](../backend/data/table_mapping_cache.json)
- [backend/services/FolioSchemaService.php](../backend/services/FolioSchemaService.php)
- [backend/services/GeminiService.php](../backend/services/GeminiService.php)
- [backend/services/QueryIntentService.php](../backend/services/QueryIntentService.php)
- [backend/services/SqlBuilderService.php](../backend/services/SqlBuilderService.php)
- [backend/tests/FolioSchemaServicePromptPolicyFilterTest.php](../backend/tests/FolioSchemaServicePromptPolicyFilterTest.php)
- [backend/tests/GeminiServiceAiConfigResolutionTest.php](../backend/tests/GeminiServiceAiConfigResolutionTest.php)
- [backend/tests/MySqlDashboardBootstrapSchemaTest.php](../backend/tests/MySqlDashboardBootstrapSchemaTest.php)
- [backend/tests/QueryIntentServiceTableNormalizationTest.php](../backend/tests/QueryIntentServiceTableNormalizationTest.php)
- [backend/tests/SqlBuilderServiceJsonbTextCastNormalizationTest.php](../backend/tests/SqlBuilderServiceJsonbTextCastNormalizationTest.php)
- [mysql/init.sql](../mysql/init.sql)
- [planning/tickets.md](../planning/tickets.md)
- [updates/2026-05-11_14-31-00_NL2SQL-007_step7-status-sync-on-main.md](2026-05-11_14-31-00_NL2SQL-007_step7-status-sync-on-main.md)
- [updates/2026-05-11_14-43-00_NL2SQL-008_production-settings-parity-restored.md](2026-05-11_14-43-00_NL2SQL-008_production-settings-parity-restored.md)
- [updates/2026-05-12_10-09-12_NL2SQL-008_runtime-schema-drift-hardening.md](2026-05-12_10-09-12_NL2SQL-008_runtime-schema-drift-hardening.md)

## Validation Evidence
- `php backend/tests/MySqlDashboardBootstrapSchemaTest.php`
- `php backend/tests/QueryIntentServiceTableNormalizationTest.php`
- `php backend/tests/GeminiServiceAiConfigResolutionTest.php`
- `php backend/tests/FolioSchemaServicePromptPolicyFilterTest.php`
- `php backend/tests/SqlBuilderServiceJsonbTextCastNormalizationTest.php`
- `php -l backend/controllers/FolioQueryController.php`
- `php -l backend/services/SqlBuilderService.php`

## Open Risks Or Follow-ups
- Missing-field checks for whole MARC families like `6xx` still rely on a scoped source-record text heuristic because `marctab` remains blocked; a canonical approved field-family strategy is still needed.
- The checked-in schema caches now reflect the current live MetaDB shape; future upstream schema changes will require another cache refresh to keep prompt/runtime normalization aligned.

## Next Ticket
- `NL2SQL-008 - Shadow Mode and Cutover`