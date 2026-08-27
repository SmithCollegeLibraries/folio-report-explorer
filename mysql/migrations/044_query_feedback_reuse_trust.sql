-- Migration 044: Store explicit query-memory trust, compatibility, and weak signals.
-- Safe to re-run after a partial DDL application.

DELIMITER //
DROP PROCEDURE IF EXISTS fre_add_query_feedback_reuse_trust//
CREATE PROCEDURE fre_add_query_feedback_reuse_trust()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'generation_id') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN generation_id CHAR(36) NULL AFTER user_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'query_job_id') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN query_job_id CHAR(36) NULL AFTER generation_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'generation_provenance') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN generation_provenance ENUM('verified_pattern','ai_built') NULL AFTER query_job_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'direct_reuse_schema_fingerprint') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN direct_reuse_schema_fingerprint CHAR(64) NULL AFTER generation_provenance;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'schema_version_fingerprint') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN schema_version_fingerprint CHAR(64) NULL AFTER direct_reuse_schema_fingerprint;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'scope_fingerprint') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN scope_fingerprint CHAR(64) NULL AFTER schema_version_fingerprint;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'reuse_suppressed') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN reuse_suppressed TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_fingerprint;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'admin_reuse_approved_at') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN admin_reuse_approved_at DATETIME NULL AFTER reuse_suppressed;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'admin_reuse_approved_by') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN admin_reuse_approved_by INT NULL AFTER admin_reuse_approved_at;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND COLUMN_NAME = 'replacement_generation_id') THEN
        ALTER TABLE ai_query_feedback ADD COLUMN replacement_generation_id CHAR(36) NULL AFTER admin_reuse_approved_by;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_report_generations' AND COLUMN_NAME = 'saved_count') THEN
        ALTER TABLE ai_report_generations ADD COLUMN saved_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER review_reasons_json;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_report_generations' AND COLUMN_NAME = 'downloaded_count') THEN
        ALTER TABLE ai_report_generations ADD COLUMN downloaded_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER saved_count;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_report_generations' AND COLUMN_NAME = 'rerun_count') THEN
        ALTER TABLE ai_report_generations ADD COLUMN rerun_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER downloaded_count;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_report_generations' AND COLUMN_NAME = 'follow_up_count') THEN
        ALTER TABLE ai_report_generations ADD COLUMN follow_up_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rerun_count;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND INDEX_NAME = 'idx_feedback_prompt_source_accuracy') THEN
        ALTER TABLE ai_query_feedback ADD INDEX idx_feedback_prompt_source_accuracy (prompt_fingerprint, data_source, result_accuracy);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND INDEX_NAME = 'idx_feedback_generation') THEN
        ALTER TABLE ai_query_feedback ADD INDEX idx_feedback_generation (generation_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND INDEX_NAME = 'idx_feedback_query_job') THEN
        ALTER TABLE ai_query_feedback ADD INDEX idx_feedback_query_job (query_job_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND INDEX_NAME = 'idx_feedback_direct_schema') THEN
        ALTER TABLE ai_query_feedback ADD INDEX idx_feedback_direct_schema (direct_reuse_schema_fingerprint);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND INDEX_NAME = 'idx_feedback_schema_version') THEN
        ALTER TABLE ai_query_feedback ADD INDEX idx_feedback_schema_version (schema_version_fingerprint);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND INDEX_NAME = 'idx_feedback_scope') THEN
        ALTER TABLE ai_query_feedback ADD INDEX idx_feedback_scope (scope_fingerprint);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND INDEX_NAME = 'idx_feedback_suppressed') THEN
        ALTER TABLE ai_query_feedback ADD INDEX idx_feedback_suppressed (reuse_suppressed);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND CONSTRAINT_NAME = 'fk_query_feedback_generation') THEN
        ALTER TABLE ai_query_feedback ADD CONSTRAINT fk_query_feedback_generation FOREIGN KEY (generation_id) REFERENCES ai_report_generations(id) ON DELETE SET NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND CONSTRAINT_NAME = 'fk_query_feedback_job') THEN
        ALTER TABLE ai_query_feedback ADD CONSTRAINT fk_query_feedback_job FOREIGN KEY (query_job_id) REFERENCES query_jobs(id) ON DELETE SET NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND CONSTRAINT_NAME = 'fk_query_feedback_approver') THEN
        ALTER TABLE ai_query_feedback ADD CONSTRAINT fk_query_feedback_approver FOREIGN KEY (admin_reuse_approved_by) REFERENCES users(id) ON DELETE SET NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_query_feedback' AND CONSTRAINT_NAME = 'fk_query_feedback_replacement') THEN
        ALTER TABLE ai_query_feedback ADD CONSTRAINT fk_query_feedback_replacement FOREIGN KEY (replacement_generation_id) REFERENCES ai_report_generations(id) ON DELETE SET NULL;
    END IF;
END//
CALL fre_add_query_feedback_reuse_trust()//
DROP PROCEDURE IF EXISTS fre_add_query_feedback_reuse_trust//
DELIMITER ;
