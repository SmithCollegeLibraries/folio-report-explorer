-- Migration 009: Add default_campus preference to users table
-- Run against: the DB configured in MYSQL_DATABASE (production: folio_report_explorer, dev: folio_reports)
-- Persists each user's preferred campus scope for Ask AI queries.
-- Defaults to 'Smith College' which matches the inventory.loccampus__t name value.

ALTER TABLE users
    ADD COLUMN default_campus VARCHAR(50) NOT NULL DEFAULT 'Smith College'
    AFTER receive_notifications;
