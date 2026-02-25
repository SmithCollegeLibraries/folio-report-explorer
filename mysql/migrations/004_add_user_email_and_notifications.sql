-- Migration 004: Add email and notification preferences for admin notifications
-- Run against: folio_reports database

USE folio_reports;

ALTER TABLE users
    ADD COLUMN email VARCHAR(255) NULL AFTER affiliation,
    ADD COLUMN receive_notifications TINYINT(1) DEFAULT 1 AFTER is_approved;
