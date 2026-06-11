-- Migration 025: Widen estimated_cost column to DOUBLE
-- DECIMAL(15,2) overflows when PostgreSQL EXPLAIN returns very large plan costs
-- (e.g. 6.9E+22 for complex CTEs). DOUBLE handles the full float range.
ALTER TABLE query_jobs
    MODIFY COLUMN estimated_cost DOUBLE NULL;
