CREATE TABLE IF NOT EXISTS weeding_areas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    lc_from VARCHAR(20) NULL,
    lc_to VARCHAR(20) NULL,
    location_ids TEXT NULL,
    status ENUM('snapshotting', 'looking_up', 'ready', 'paused', 'failed') NOT NULL DEFAULT 'snapshotting',
    title_count INT NOT NULL DEFAULT 0,
    item_count INT NOT NULL DEFAULT 0,
    non_lc_excluded INT NOT NULL DEFAULT 0,
    no_oclc_count INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL,
    snapshot_at DATETIME NULL,
    error TEXT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_weeding_areas_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS weeding_area_titles (
    area_id INT UNSIGNED NOT NULL,
    instance_id CHAR(36) NOT NULL,
    instance_hrid VARCHAR(50) NOT NULL,
    oclc_number BIGINT UNSIGNED NULL,
    title VARCHAR(500) NOT NULL,
    author VARCHAR(255) NULL,
    pub_year SMALLINT NULL,
    smith_items SMALLINT NOT NULL,
    area_items SMALLINT NOT NULL,
    total_checkouts INT NOT NULL,
    last_folio_checkout DATE NULL,
    PRIMARY KEY (area_id, instance_id),
    KEY idx_weeding_area_titles_oclc (area_id, oclc_number),
    CONSTRAINT fk_weeding_area_titles_area FOREIGN KEY (area_id) REFERENCES weeding_areas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS weeding_area_items (
    area_id INT UNSIGNED NOT NULL,
    instance_id CHAR(36) NOT NULL,
    item_id CHAR(36) NOT NULL,
    item_hrid VARCHAR(50) NOT NULL,
    barcode VARCHAR(50) NULL,
    location_code VARCHAR(50) NOT NULL,
    call_number VARCHAR(150) NULL,
    call_number_sort VARCHAR(160) NULL,
    volume VARCHAR(100) NULL,
    status VARCHAR(50) NOT NULL,
    PRIMARY KEY (area_id, item_id),
    KEY idx_weeding_area_items_instance (area_id, instance_id),
    CONSTRAINT fk_weeding_area_items_title FOREIGN KEY (area_id, instance_id)
        REFERENCES weeding_area_titles (area_id, instance_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS weeding_area_title_checkouts (
    area_id INT UNSIGNED NOT NULL,
    instance_id CHAR(36) NOT NULL,
    year SMALLINT NOT NULL,
    checkouts INT NOT NULL,
    PRIMARY KEY (area_id, instance_id, year),
    CONSTRAINT fk_weeding_area_title_checkouts_title FOREIGN KEY (area_id, instance_id)
        REFERENCES weeding_area_titles (area_id, instance_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS weeding_worldcat_cache (
    oclc_number BIGINT UNSIGNED NOT NULL,
    resolved_oclc BIGINT UNSIGNED NULL,
    status ENUM('ok', 'not_found', 'error') NOT NULL,
    total_count INT NULL,
    shared_print_count SMALLINT NULL,
    five_college_count SMALLINT NULL,
    ma_count SMALLINT NULL,
    east_count SMALLINT NULL,
    smith_retains TINYINT(1) NULL,
    retainers TEXT NULL,
    fetched_at DATETIME NOT NULL,
    error TEXT NULL,
    PRIMARY KEY (oclc_number),
    KEY idx_weeding_worldcat_cache_fetched_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS weeding_oclc_calls (
    day DATE NOT NULL,
    calls INT NOT NULL DEFAULT 0,
    PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS weeding_oclc_block (
    id TINYINT NOT NULL,
    blocked_until DATETIME NULL,
    reason ENUM('daily_cap', 'monthly_cap', 'provider_low', 'rate_limited') NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS weeding_scenarios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    area_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    criteria TEXT NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_weeding_scenarios_area_name (area_id, name),
    CONSTRAINT fk_weeding_scenarios_area FOREIGN KEY (area_id) REFERENCES weeding_areas (id) ON DELETE CASCADE,
    CONSTRAINT fk_weeding_scenarios_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @weeding075_add_access = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users' AND COLUMN_NAME = 'weeding_access') = 0,
    'ALTER TABLE users ADD COLUMN weeding_access TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE weeding075_stmt FROM @weeding075_add_access;
EXECUTE weeding075_stmt;
DEALLOCATE PREPARE weeding075_stmt;
