-- Migration 004: Add email and notification preferences for admin notifications
-- Run against: the DB configured in MYSQL_DATABASE (production: folio_report_explorer, dev: folio_reports)

ALTER TABLE users
    ADD COLUMN email VARCHAR(255) NULL AFTER affiliation,
    ADD COLUMN receive_notifications TINYINT(1) DEFAULT 1 AFTER is_approved;
