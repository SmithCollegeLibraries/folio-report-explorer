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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_created (created_at),
    INDEX idx_pinned (is_pinned),
    INDEX idx_user_id (user_id),
    CONSTRAINT fk_saved_queries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
