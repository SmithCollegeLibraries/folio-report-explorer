USE folio_reports;

CREATE TABLE IF NOT EXISTS saved_queries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    query_definition JSON NOT NULL COMMENT 'Structured query builder state',
    generated_sql TEXT COMMENT 'Last generated SQL',
    source ENUM('builder', 'nl') DEFAULT 'builder' COMMENT 'Origin: query builder or AI',
    nl_prompt TEXT NULL COMMENT 'Original natural language question (for source=nl)',
    is_pinned TINYINT(1) DEFAULT 0 COMMENT 'Pinned to dashboard',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_created (created_at),
    INDEX idx_pinned (is_pinned)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS query_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sql_text TEXT NOT NULL,
    params JSON,
    source ENUM('builder', 'nl', 'manual', 'report') DEFAULT 'builder',
    row_count INT,
    execution_time_ms INT,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS query_jobs (
    id CHAR(36) PRIMARY KEY COMMENT 'UUID',
    sql_text TEXT NOT NULL,
    params JSON,
    source ENUM('builder', 'nl', 'manual', 'report') DEFAULT 'builder',
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    result_columns JSON COMMENT 'Column names array',
    result_rows LONGTEXT COMMENT 'JSON-encoded row data',
    row_count INT,
    execution_time_ms INT,
    error_message TEXT,
    progress_message VARCHAR(255) DEFAULT 'Queued',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category ENUM('acquisitions', 'circulation', 'inventory', 'finance', 'users', 'other') DEFAULT 'other',
    sql_template LONGTEXT NOT NULL COMMENT 'SQL with :param placeholders',
    parameters JSON NOT NULL COMMENT 'Array of parameter definitions',
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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_active (is_active),
    INDEX idx_type_active (type, is_active),
    INDEX idx_hint_key (hint_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
