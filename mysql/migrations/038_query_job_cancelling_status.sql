-- Keep running jobs active while PostgreSQL confirms their cancellation.
-- Safe to re-run by inspecting the current enum definition.

SET @has_cancelling := (
  SELECT IF(LOCATE("'cancelling'", COLUMN_TYPE) > 0, 1, 0)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'query_jobs'
    AND COLUMN_NAME = 'status'
  LIMIT 1
);

SET @sql := IF(
  @has_cancelling = 1,
  'SELECT 1',
  "ALTER TABLE query_jobs MODIFY status ENUM('pending','running','cancelling','completed','failed','cancelled','pending_export') DEFAULT 'pending'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
