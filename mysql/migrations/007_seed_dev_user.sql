-- Migration 007: Seed stable dev admin user + backfill NULL user_id rows

-- Create a non-Shibboleth dev admin so dev-mode user association works.
-- Uses smith_id='dev' as the unique key; safe to run multiple times (INSERT IGNORE).
INSERT IGNORE INTO users
    (id, smith_id, username, first_name, last_name, email, role, is_approved, created_at, updated_at)
VALUES
    (1, 'dev', 'dev', 'Dev', 'Admin', 'dev@localhost', 'admin', 1, NOW(), NOW());

-- Backfill any rows that were saved without an authenticated user (dev mode).
UPDATE saved_queries  SET user_id = 1 WHERE user_id IS NULL;
UPDATE query_jobs     SET user_id = 1 WHERE user_id IS NULL;
UPDATE query_log      SET user_id = 1 WHERE user_id IS NULL;
UPDATE ai_training_hints SET user_id = 1 WHERE user_id IS NULL;
