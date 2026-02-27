-- Migration 008: Cached dashboard results + per-user display preferences

-- Store the most recent completed job for instant cache retrieval on dashboard load.
ALTER TABLE saved_queries
    ADD COLUMN last_job_id CHAR(36) NULL AFTER is_global;

-- Per-user display mode (table vs chart type) and chart axis config.
ALTER TABLE user_dashboard_prefs
    ADD COLUMN display_type VARCHAR(20) NOT NULL DEFAULT 'table' AFTER hidden,
    ADD COLUMN chart_config JSON NULL AFTER display_type;
