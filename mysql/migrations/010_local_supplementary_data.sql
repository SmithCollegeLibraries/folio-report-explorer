-- Local supplementary data tables for report explorer

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

ALTER TABLE query_log
    ADD COLUMN data_source ENUM('folio', 'local', 'composite') DEFAULT 'folio' AFTER source;

ALTER TABLE query_jobs
    ADD COLUMN data_source ENUM('folio', 'local', 'composite') DEFAULT 'folio' AFTER source;
