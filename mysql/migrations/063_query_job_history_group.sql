SET @history_group_key_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'query_jobs'
      AND COLUMN_NAME = 'history_group_key'
);
SET @history_group_key_column_sql = IF(
    @history_group_key_column_exists = 0,
    'ALTER TABLE query_jobs ADD COLUMN history_group_key CHAR(40) NULL',
    'SELECT 1'
);
PREPARE history_group_key_column_stmt FROM @history_group_key_column_sql;
EXECUTE history_group_key_column_stmt;
DEALLOCATE PREPARE history_group_key_column_stmt;

SET @history_group_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'query_jobs'
      AND INDEX_NAME = 'idx_query_jobs_history_group'
);
SET @history_group_index_matches = (
    SELECT IF(
        COUNT(*) = 3
        AND MIN(NON_UNIQUE) = 1
        AND SUM(SUB_PART IS NOT NULL) = 0
        AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') = 'user_id,history_group_key,created_at',
        1,
        0
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'query_jobs'
      AND INDEX_NAME = 'idx_query_jobs_history_group'
);
SET @history_group_index_sql = IF(
    @history_group_index_exists = 0,
    'CREATE INDEX idx_query_jobs_history_group ON query_jobs (user_id, history_group_key, created_at)',
    IF(@history_group_index_matches = 1, 'SELECT 1', 'SELECT * FROM rpt016_history_group_index_conflict')
);
PREPARE history_group_index_stmt FROM @history_group_index_sql;
EXECUTE history_group_index_stmt;
DEALLOCATE PREPARE history_group_index_stmt;
