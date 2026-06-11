-- Migration 031: Allow full query request titles in history.

ALTER TABLE query_jobs
    MODIFY COLUMN name TEXT NULL;
