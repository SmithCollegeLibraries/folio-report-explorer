-- Allow ordinary users to edit local expense allocations for assigned colleges.
SET @allocation_colleges_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'allocation_colleges'
);

SET @allocation_colleges_sql = IF(
  @allocation_colleges_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `allocation_colleges` VARCHAR(32) NULL AFTER `default_campus`',
  'SELECT 1'
);
PREPARE allocation_colleges_statement FROM @allocation_colleges_sql;
EXECUTE allocation_colleges_statement;
DEALLOCATE PREPARE allocation_colleges_statement;
