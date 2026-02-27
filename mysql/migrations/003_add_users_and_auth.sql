-- Migration 003: Add users table, user_id columns, sql_hash for dedup, and data retention support
-- Run against: the DB configured in MYSQL_DATABASE (production: folio_report_explorer, dev: folio_reports)

-- ── Users table ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    smith_id VARCHAR(50) NOT NULL UNIQUE COMMENT 'fcIdNumber from Shibboleth',
    username VARCHAR(100) NOT NULL COMMENT 'uid from Shibboleth',
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    affiliation VARCHAR(100) NULL COMMENT 'fcPersonAffiliation from Shibboleth',
    role ENUM('admin', 'user') DEFAULT 'user',
    is_approved TINYINT(1) DEFAULT 0 COMMENT 'Must be approved by admin to use app',
    last_login DATETIME NULL,
    refresh_token VARCHAR(512) NULL COMMENT 'Current refresh token (for revocation)',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_approved (is_approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Add user_id to existing tables ────────────────────────────────

ALTER TABLE query_jobs
    ADD COLUMN user_id INT NULL AFTER source,
    ADD COLUMN sql_hash CHAR(64) NULL AFTER sql_text,
    ADD INDEX idx_user_id (user_id),
    ADD INDEX idx_sql_hash (sql_hash),
    ADD CONSTRAINT fk_query_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE saved_queries
    ADD COLUMN user_id INT NULL AFTER name,
    ADD INDEX idx_user_id (user_id),
    ADD CONSTRAINT fk_saved_queries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE query_log
    ADD COLUMN user_id INT NULL AFTER source,
    ADD INDEX idx_user_id (user_id),
    ADD CONSTRAINT fk_query_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE ai_training_hints
    ADD COLUMN user_id INT NULL AFTER is_active,
    ADD INDEX idx_user_id (user_id),
    ADD CONSTRAINT fk_training_hints_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
