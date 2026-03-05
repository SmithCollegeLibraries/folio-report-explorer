-- Migration 015: Add pg_backend_pid to query_jobs for real query cancellation.
-- When a FOLIO (Postgres) query is running, the worker stores the backend PID
-- so the cancel endpoint can issue pg_cancel_backend() to actually terminate it.

ALTER TABLE query_jobs
    ADD COLUMN pg_backend_pid INT NULL COMMENT 'Postgres backend PID while query is executing'
    AFTER progress_message;
