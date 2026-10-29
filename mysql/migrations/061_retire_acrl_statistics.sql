SET @acrl_source_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'acrl_statistics');
SET @acrl_retired_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'acrl_statistics_retired');
SET @sql = IF(
  @acrl_source_exists = 1 AND @acrl_retired_exists = 0,
  'RENAME TABLE `acrl_statistics` TO `acrl_statistics_retired`',
  IF(
    @acrl_source_exists = 1 AND @acrl_retired_exists = 1,
    'SELECT * FROM `acrl_retire_conflict_both_tables_exist`',
    'SELECT 1'
  )
);
PREPARE acrl_retire_stmt FROM @sql;
EXECUTE acrl_retire_stmt;
DEALLOCATE PREPARE acrl_retire_stmt;

UPDATE report_templates SET is_active = 0 WHERE slug = 'acrl-statistics';
