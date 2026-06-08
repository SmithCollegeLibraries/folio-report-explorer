-- ── Users ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    smith_id VARCHAR(50) NOT NULL UNIQUE COMMENT 'fcIdNumber from Shibboleth',
    username VARCHAR(100) NOT NULL COMMENT 'uid from Shibboleth',
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    affiliation VARCHAR(100) NULL COMMENT 'fcPersonAffiliation from Shibboleth',
    email VARCHAR(255) NULL COMMENT 'eppn from Shibboleth',
    role ENUM('admin', 'user') DEFAULT 'user',
    is_approved TINYINT(1) DEFAULT 0 COMMENT 'Must be approved by admin to use app',
    receive_notifications TINYINT(1) DEFAULT 1 COMMENT 'Receive email when new users sign up (admins only)',
    last_login DATETIME NULL,
    refresh_token VARCHAR(512) NULL COMMENT 'Current refresh token (for revocation)',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_approved (is_approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Saved Queries ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS saved_queries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    user_id INT NULL,
    description TEXT,
    query_definition JSON NOT NULL COMMENT 'Structured query builder state',
    generated_sql TEXT COMMENT 'Last generated SQL',
    source ENUM('builder', 'nl', 'report') DEFAULT 'builder' COMMENT 'Origin: query builder, AI, or widget gallery',
    nl_prompt TEXT NULL COMMENT 'Original natural language question (for source=nl)',
    is_pinned TINYINT(1) DEFAULT 0 COMMENT 'Pinned to dashboard',
    is_global TINYINT(1) DEFAULT 0 COMMENT 'Visible to all users on the dashboard',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_created (created_at),
    INDEX idx_pinned (is_pinned),
    INDEX idx_user_id (user_id),
    CONSTRAINT fk_saved_queries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_dashboard_prefs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    saved_query_id INT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_item (user_id, saved_query_id),
    INDEX idx_udp_user_id (user_id),
    INDEX idx_udp_saved_query_id (saved_query_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS query_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sql_text TEXT NOT NULL,
    params JSON,
    source ENUM('builder', 'nl', 'manual', 'report') DEFAULT 'builder',
    data_source ENUM('folio', 'local') DEFAULT 'folio',
    user_id INT NULL,
    row_count INT,
    execution_time_ms INT,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_source (source),
    INDEX idx_user_id (user_id),
    CONSTRAINT fk_query_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS query_jobs (
    id CHAR(36) PRIMARY KEY COMMENT 'UUID',
    sql_text TEXT NOT NULL,
    sql_hash CHAR(64) NULL COMMENT 'SHA-256 hash for dedup',
    params JSON,
    source ENUM('builder', 'nl', 'manual', 'report') DEFAULT 'builder',
    name TEXT NULL COMMENT 'Human-readable query/request title',
    data_source ENUM('folio', 'local') DEFAULT 'folio',
    user_id INT NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    result_columns JSON COMMENT 'Column names array',
    result_rows LONGTEXT COMMENT 'JSON-encoded row data',
    row_count INT,
    execution_time_ms INT,
    error_message TEXT,
    progress_message VARCHAR(255) DEFAULT 'Queued',
    pg_backend_pid INT NULL COMMENT 'Postgres backend PID while query is executing',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    metadata JSON NULL COMMENT 'Extra job metadata (e.g. composite_config for cross-DB reports)',
    INDEX idx_status (status),
    INDEX idx_created (created_at),
    INDEX idx_user_id (user_id),
    INDEX idx_sql_hash (sql_hash),
    CONSTRAINT fk_query_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category ENUM('acquisitions', 'circulation', 'inventory', 'finance', 'users', 'other') DEFAULT 'other',
    sql_template LONGTEXT NOT NULL COMMENT 'SQL with :param placeholders',
    parameters JSON NOT NULL COMMENT 'Array of parameter definitions',
    data_source ENUM('folio', 'local', 'composite') NOT NULL DEFAULT 'folio' COMMENT 'Which DB this report targets',
    composite_config JSON NULL COMMENT 'For composite reports: secondary query, merge key, append columns',
    default_limit INT DEFAULT 100,
    is_active TINYINT(1) DEFAULT 1,
    created_by ENUM('manual', 'ai') DEFAULT 'manual',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_active (is_active),
    INDEX idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_training_hints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('table_description', 'vocabulary', 'example', 'correction') NOT NULL,
    hint_key VARCHAR(255) NULL COMMENT 'Table name (descriptions) or business term (vocabulary)',
    hint_value TEXT NULL COMMENT 'Description or mapping text',
    example_question TEXT NULL COMMENT 'NL question (examples/corrections)',
    example_sql TEXT NULL COMMENT 'Reference SQL (examples/corrections)',
    original_sql TEXT NULL COMMENT 'AI original wrong SQL (corrections only)',
    notes TEXT NULL COMMENT 'User notes explaining the correction',
    is_active TINYINT(1) DEFAULT 1,
    user_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_active (is_active),
    INDEX idx_type_active (type, is_active),
    INDEX idx_hint_key (hint_key),
    INDEX idx_user_id (user_id),
    CONSTRAINT fk_training_hints_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS ai_query_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    original_question TEXT NOT NULL,
    prompt_fingerprint CHAR(16) NOT NULL,
    generated_sql MEDIUMTEXT NULL,
    sql_hash CHAR(64) NULL,
    route VARCHAR(128) NULL,
    route_reason VARCHAR(255) NULL,
    mode VARCHAR(64) NULL,
    data_source ENUM('folio', 'local', 'composite') DEFAULT 'folio',
    result_accuracy ENUM('accurate', 'inaccurate', 'unsure') NOT NULL,
    feedback_note TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prompt_fingerprint (prompt_fingerprint),
    INDEX idx_sql_hash (sql_hash),
    INDEX idx_route (route),
    INDEX idx_result_accuracy (result_accuracy),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_query_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acrl_statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(255) NOT NULL,
    subcategory VARCHAR(255) NOT NULL,
    year INT NOT NULL,
    value DECIMAL(18,2) NULL,
    notes VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_acrl_stat (category, subcategory, year),
    INDEX idx_acrl_year (year),
    INDEX idx_acrl_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_expense_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year INT NOT NULL,
    expense_class_code VARCHAR(10) NOT NULL,
    allocation_amount DECIMAL(10,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_allocation (fiscal_year, expense_class_code),
    INDEX idx_alloc_year (fiscal_year),
    INDEX idx_alloc_code (expense_class_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
