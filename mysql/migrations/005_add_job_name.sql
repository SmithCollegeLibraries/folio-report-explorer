-- Migration 005: Add name column to query_jobs
-- Stores a human-readable label for each job (auto-generated from NL prompt or Builder tables)
USE folio_reports;

ALTER TABLE query_jobs
    ADD COLUMN name VARCHAR(255) NULL AFTER source;
