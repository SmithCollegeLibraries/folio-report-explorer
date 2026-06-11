-- Local FOLIO reference cache for deterministic pre-AI term resolution.

CREATE TABLE IF NOT EXISTS folio_reference_tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_table VARCHAR(255) NOT NULL UNIQUE,
    source_schema VARCHAR(128) NOT NULL,
    source_name VARCHAR(128) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    classification ENUM('cacheable_reference', 'manual_review', 'do_not_cache') NOT NULL DEFAULT 'manual_review',
    estimated_rows BIGINT NULL,
    total_bytes BIGINT NULL,
    row_count INT NULL,
    checksum_hash CHAR(64) NULL,
    last_refreshed_at DATETIME NULL,
    last_refresh_status ENUM('never', 'success', 'failed') NOT NULL DEFAULT 'never',
    last_error TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ref_tables_enabled (enabled),
    INDEX idx_ref_tables_classification (classification),
    INDEX idx_ref_tables_refresh_status (last_refresh_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS folio_reference_values (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    reference_table_id INT NULL,
    source_table VARCHAR(255) NOT NULL,
    source_id VARCHAR(255) NOT NULL,
    name VARCHAR(512) NULL,
    code VARCHAR(255) NULL,
    normalized_name VARCHAR(512) NULL,
    normalized_code VARCHAR(255) NULL,
    parent_source_id VARCHAR(255) NULL,
    parent_name VARCHAR(512) NULL,
    metadata_json JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    refreshed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ref_value_source (source_table, source_id),
    INDEX idx_ref_value_table_name (source_table, normalized_name),
    INDEX idx_ref_value_table_code (source_table, normalized_code),
    INDEX idx_ref_value_active (is_active),
    INDEX idx_ref_value_reference_table_id (reference_table_id),
    CONSTRAINT fk_ref_values_table FOREIGN KEY (reference_table_id) REFERENCES folio_reference_tables(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS folio_reference_aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alias VARCHAR(255) NOT NULL,
    normalized_alias VARCHAR(255) NOT NULL,
    alias_scope ENUM('user', 'organization', 'global') NOT NULL DEFAULT 'user',
    user_id INT NULL,
    source_table VARCHAR(255) NOT NULL,
    source_id VARCHAR(255) NULL,
    resolved_value VARCHAR(512) NOT NULL,
    confidence VARCHAR(64) NULL,
    promotion_status ENUM('none', 'candidate', 'promoted', 'rejected') NOT NULL DEFAULT 'none',
    metadata_json JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ref_alias_scope (normalized_alias, alias_scope, user_id, source_table, source_id),
    INDEX idx_ref_alias_normalized (normalized_alias),
    INDEX idx_ref_alias_scope (alias_scope),
    INDEX idx_ref_alias_user_id (user_id),
    INDEX idx_ref_alias_promotion_status (promotion_status),
    CONSTRAINT fk_ref_alias_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS folio_reference_refresh_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source_table VARCHAR(255) NOT NULL,
    status ENUM('success', 'failed', 'skipped') NOT NULL,
    row_count INT NULL,
    duration_ms INT NULL,
    error_message TEXT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ref_refresh_table (source_table),
    INDEX idx_ref_refresh_status (status),
    INDEX idx_ref_refresh_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_clarification_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    clarification_batch_id CHAR(36) NULL,
    original_question TEXT NOT NULL,
    clarification_key VARCHAR(255) NOT NULL,
    term VARCHAR(255) NULL,
    detected_terms_json JSON NULL,
    options_json JSON NULL,
    selected_option_ids_json JSON NULL,
    free_text_response TEXT NULL,
    resolved_filter_json JSON NULL,
    selected_source_table VARCHAR(255) NULL,
    selected_source_id VARCHAR(255) NULL,
    selected_value VARCHAR(512) NULL,
    confidence VARCHAR(64) NULL,
    promotion_status ENUM('none', 'candidate', 'promoted', 'rejected') NOT NULL DEFAULT 'none',
    generated_sql MEDIUMTEXT NULL,
    result_status VARCHAR(64) NULL,
    promoted_hint_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clarification_batch_id (clarification_batch_id),
    INDEX idx_clarification_key (clarification_key),
    INDEX idx_term (term),
    INDEX idx_selected_source (selected_source_table, selected_source_id),
    INDEX idx_promotion_status (promotion_status),
    INDEX idx_user_id (user_id),
    INDEX idx_promoted_hint_id (promoted_hint_id),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_clarification_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_clarification_events_hint FOREIGN KEY (promoted_hint_id) REFERENCES ai_training_hints(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER //
DROP PROCEDURE IF EXISTS fre_add_clarification_reference_columns//
CREATE PROCEDURE fre_add_clarification_reference_columns()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND COLUMN_NAME = 'clarification_batch_id') THEN
        ALTER TABLE ai_clarification_events ADD COLUMN clarification_batch_id CHAR(36) NULL AFTER user_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND COLUMN_NAME = 'term') THEN
        ALTER TABLE ai_clarification_events ADD COLUMN term VARCHAR(255) NULL AFTER clarification_key;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND COLUMN_NAME = 'selected_source_table') THEN
        ALTER TABLE ai_clarification_events ADD COLUMN selected_source_table VARCHAR(255) NULL AFTER resolved_filter_json;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND COLUMN_NAME = 'selected_source_id') THEN
        ALTER TABLE ai_clarification_events ADD COLUMN selected_source_id VARCHAR(255) NULL AFTER selected_source_table;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND COLUMN_NAME = 'selected_value') THEN
        ALTER TABLE ai_clarification_events ADD COLUMN selected_value VARCHAR(512) NULL AFTER selected_source_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND COLUMN_NAME = 'confidence') THEN
        ALTER TABLE ai_clarification_events ADD COLUMN confidence VARCHAR(64) NULL AFTER selected_value;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND COLUMN_NAME = 'promotion_status') THEN
        ALTER TABLE ai_clarification_events ADD COLUMN promotion_status ENUM('none', 'candidate', 'promoted', 'rejected') NOT NULL DEFAULT 'none' AFTER confidence;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND INDEX_NAME = 'idx_clarification_batch_id') THEN
        ALTER TABLE ai_clarification_events ADD INDEX idx_clarification_batch_id (clarification_batch_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND INDEX_NAME = 'idx_term') THEN
        ALTER TABLE ai_clarification_events ADD INDEX idx_term (term);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND INDEX_NAME = 'idx_selected_source') THEN
        ALTER TABLE ai_clarification_events ADD INDEX idx_selected_source (selected_source_table, selected_source_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_clarification_events' AND INDEX_NAME = 'idx_promotion_status') THEN
        ALTER TABLE ai_clarification_events ADD INDEX idx_promotion_status (promotion_status);
    END IF;
END//
CALL fre_add_clarification_reference_columns()//
DROP PROCEDURE IF EXISTS fre_add_clarification_reference_columns//
DELIMITER ;
