-- Migration 024: Catch-up for migrations 013 and 023 that were skipped
-- on existing installations.
--
-- Migration 013 added:
--   - report_templates.data_source   (ENUM folio/local/composite)
--   - report_templates.composite_config (JSON)
--   - query_jobs.metadata           (JSON)
--
-- Migration 023 added:
--   - saved_queries.source 'report'  (ENUM extension)
--
-- All ALTER TABLE statements below are safe to re-run:
--   ADD COLUMN IF NOT EXISTS  →  no-op when column already exists (MariaDB 10.0+)
--   MODIFY COLUMN             →  idempotent ENUM re-declaration

-- ── report_templates: datasource columns (from migration 013) ─────────────────

ALTER TABLE `report_templates`
  ADD COLUMN IF NOT EXISTS `data_source`
    ENUM('folio', 'local', 'composite') NOT NULL DEFAULT 'folio'
    COMMENT 'Which database this report targets'
    AFTER `parameters`,
  ADD COLUMN IF NOT EXISTS `composite_config`
    JSON NULL
    COMMENT 'For composite reports: secondary query, merge key, and append columns'
    AFTER `data_source`;

-- ── query_jobs: metadata column (from migration 013) ─────────────────────────

ALTER TABLE `query_jobs`
  ADD COLUMN IF NOT EXISTS `metadata`
    JSON NULL
    COMMENT 'Extra job metadata (e.g. composite_config for cross-DB reports)';

-- ── saved_queries: add ''report'' to source ENUM (from migration 021) ─────────

ALTER TABLE `saved_queries`
  MODIFY COLUMN `source`
    ENUM('builder', 'nl', 'report') DEFAULT 'builder'
    COMMENT 'Origin: query builder, AI, or widget gallery';

-- ── Backfill data_source / composite_config for existing report_template rows ─
-- Rows inserted by migrations 014/015 were seeded without these columns because
-- migration 013 had been skipped.  After the ADD COLUMN above they default to
-- data_source='folio' / composite_config=NULL, which breaks composite reports.

-- id=34  Expense Class Allocation vs. Actual  (composite)
UPDATE `report_templates` SET
  `data_source`      = 'composite',
  `composite_config` = '{"secondary_sql":"SELECT expense_class_code, allocation_amount FROM report_expense_allocations WHERE fiscal_year = :fiscalYear","secondary_db":"local","merge_key":{"primary":"Expense Code","secondary":"expense_class_code"},"append_columns":["allocation_amount AS Allocation"]}'
WHERE `id` = 34 AND `data_source` = 'folio';

-- id=35  ACRL Statistics  (local MySQL)
UPDATE `report_templates` SET
  `data_source` = 'local'
WHERE `id` = 35 AND `data_source` = 'folio';

-- id=36  Budget Year Expense Class Report  (composite)
UPDATE `report_templates` SET
  `data_source`      = 'composite',
  `composite_config` = '{"secondary_sql":"SELECT expense_class_code, allocation_amount FROM report_expense_allocations WHERE fiscal_year = :fiscalYear","secondary_db":"local","merge_key":{"primary":"Expense Class Code","secondary":"expense_class_code"},"append_columns":["allocation_amount AS Allocation"],"computed_columns":[{"name":"Remaining","formula":"Allocation - Total Payments - Total Encumbrances"}]}'
WHERE `id` = 36 AND `data_source` = 'folio';
