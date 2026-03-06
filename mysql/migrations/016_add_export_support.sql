-- Add support for large-query file exports and export queueing.
-- Safe to re-run due to information_schema guards.

-- Expand data_source enum to include composite (needed for report composite jobs)
SET @has_composite := (
  SELECT IF(LOCATE("'composite'", COLUMN_TYPE) > 0, 1, 0)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'query_jobs'
    AND COLUMN_NAME = 'data_source'
  LIMIT 1
);

SET @sql := IF(
  @has_composite = 1,
  'SELECT 1',
  "ALTER TABLE query_jobs MODIFY data_source ENUM('folio','local','composite') DEFAULT 'folio'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Expand status enum with pending_export
SET @has_pending_export := (
  SELECT IF(LOCATE("'pending_export'", COLUMN_TYPE) > 0, 1, 0)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'query_jobs'
    AND COLUMN_NAME = 'status'
  LIMIT 1
);

SET @sql := IF(
  @has_pending_export = 1,
  'SELECT 1',
  "ALTER TABLE query_jobs MODIFY status ENUM('pending','running','completed','failed','cancelled','pending_export') DEFAULT 'pending'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add export columns if missing
SET @has_output_mode := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'query_jobs'
    AND COLUMN_NAME = 'output_mode'
);
SET @sql := IF(
  @has_output_mode > 0,
  'SELECT 1',
  "ALTER TABLE query_jobs ADD COLUMN output_mode ENUM('table','file') NOT NULL DEFAULT 'table' AFTER pg_backend_pid"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_export_file_path := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'query_jobs'
    AND COLUMN_NAME = 'export_file_path'
);
SET @sql := IF(
  @has_export_file_path > 0,
  'SELECT 1',
  "ALTER TABLE query_jobs ADD COLUMN export_file_path VARCHAR(500) NULL AFTER output_mode"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_estimated_rows := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'query_jobs'
    AND COLUMN_NAME = 'estimated_rows'
);
SET @sql := IF(
  @has_estimated_rows > 0,
  'SELECT 1',
  "ALTER TABLE query_jobs ADD COLUMN estimated_rows INT NULL AFTER export_file_path"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_estimated_cost := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'query_jobs'
    AND COLUMN_NAME = 'estimated_cost'
);
SET @sql := IF(
  @has_estimated_cost > 0,
  'SELECT 1',
  "ALTER TABLE query_jobs ADD COLUMN estimated_cost DECIMAL(15,2) NULL AFTER estimated_rows"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_export_path_idx := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'query_jobs'
    AND INDEX_NAME = 'idx_export_file_path'
);
SET @sql := IF(
  @has_export_path_idx > 0,
  'SELECT 1',
  'ALTER TABLE query_jobs ADD INDEX idx_export_file_path (export_file_path)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
