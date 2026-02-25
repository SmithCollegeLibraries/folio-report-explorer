-- Migration 002: Add NL prompt, source, and dashboard pin fields to saved_queries
-- Run this on existing installations to bring the schema up to date.

ALTER TABLE saved_queries
  ADD COLUMN source ENUM('builder', 'nl') DEFAULT 'builder' COMMENT 'Origin: query builder or AI' AFTER generated_sql;

ALTER TABLE saved_queries
  ADD COLUMN nl_prompt TEXT NULL COMMENT 'Original natural language question (for source=nl)' AFTER source;

ALTER TABLE saved_queries
  ADD COLUMN is_pinned TINYINT(1) DEFAULT 0 COMMENT 'Pinned to dashboard' AFTER nl_prompt;

ALTER TABLE saved_queries
  ADD INDEX idx_pinned (is_pinned);
