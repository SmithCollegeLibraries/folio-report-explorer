-- Migration 013: Add data_source and composite_config to report_templates
-- Also add metadata column to query_jobs for composite report config.

ALTER TABLE report_templates
  ADD COLUMN data_source ENUM('folio', 'local', 'composite') NOT NULL DEFAULT 'folio'
    COMMENT 'Which database this report targets' AFTER parameters,
  ADD COLUMN composite_config JSON NULL
    COMMENT 'For composite reports: secondary query, merge key, and append columns' AFTER data_source;

ALTER TABLE query_jobs
  ADD COLUMN metadata JSON NULL
    COMMENT 'Extra job metadata (e.g. composite_config for cross-DB reports)';
