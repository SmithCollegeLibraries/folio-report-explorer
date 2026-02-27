-- Migration 006: Per-user dashboard preferences and admin-global dashboard items
-- Adds is_global to saved_queries + user_dashboard_prefs for per-user ordering/hiding

ALTER TABLE saved_queries
    ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER is_pinned;

-- Stores per-user position + visibility overrides for dashboard items
CREATE TABLE IF NOT EXISTS user_dashboard_prefs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    saved_query_id INT NOT NULL,
    position       INT NOT NULL DEFAULT 0,
    hidden         TINYINT(1) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_item (user_id, saved_query_id),
    INDEX idx_udp_user_id        (user_id),
    INDEX idx_udp_saved_query_id (saved_query_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
