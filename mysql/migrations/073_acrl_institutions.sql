CREATE TABLE IF NOT EXISTS acrl_institutions (
  code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(100) NOT NULL,
  sort_order TINYINT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO acrl_institutions (code, name, sort_order, active) VALUES
  ('AC', 'Amherst College', 1, 1),
  ('HC', 'Hampshire College', 2, 1),
  ('MH', 'Mount Holyoke College', 3, 1),
  ('SC', 'Smith College', 4, 1),
  ('UM', 'University of Massachusetts Amherst', 5, 1);

-- Add the institution columns separately so a rerun can resume after any table.
SET @acrl073_add_answers = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answers' AND COLUMN_NAME = 'institution_code') = 0,
  'ALTER TABLE acrl_answers ADD COLUMN institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''SC''', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_add_answers;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

SET @acrl073_add_history = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answer_history' AND COLUMN_NAME = 'institution_code') = 0,
  'ALTER TABLE acrl_answer_history ADD COLUMN institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''SC''', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_add_history;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

SET @acrl073_add_status = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_status' AND COLUMN_NAME = 'institution_code') = 0,
  'ALTER TABLE acrl_section_status ADD COLUMN institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''SC''', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_add_status;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

SET @acrl073_add_section_assignments = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_assignments' AND COLUMN_NAME = 'institution_code') = 0,
  'ALTER TABLE acrl_section_assignments ADD COLUMN institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''SC''', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_add_section_assignments;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

SET @acrl073_add_question_assignments = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_assignments' AND COLUMN_NAME = 'institution_code') = 0,
  'ALTER TABLE acrl_question_assignments ADD COLUMN institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''SC''', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_add_question_assignments;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

SET @acrl073_add_notes = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_notes' AND COLUMN_NAME = 'institution_code') = 0,
  'ALTER TABLE acrl_question_notes ADD COLUMN institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''SC''', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_add_notes;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

SET @acrl073_add_invites = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_invites' AND COLUMN_NAME = 'institution_code') = 0,
  'ALTER TABLE acrl_invites ADD COLUMN institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''SC''', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_add_invites;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

-- Replacement indexes preserve the existing year foreign keys during key changes.
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_status' AND INDEX_NAME = 'idx_acrl_section_status_year') = 0,
  'ALTER TABLE acrl_section_status ADD KEY idx_acrl_section_status_year (survey_year)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_assignments' AND INDEX_NAME = 'idx_acrl_section_assignment_year') = 0,
  'ALTER TABLE acrl_section_assignments ADD KEY idx_acrl_section_assignment_year (survey_year)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_assignments' AND INDEX_NAME = 'idx_acrl_question_assignments_year') = 0,
  'ALTER TABLE acrl_question_assignments ADD KEY idx_acrl_question_assignments_year (survey_year)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answer_history' AND INDEX_NAME = 'idx_acrl_answer_history_institution') = 0,
  'ALTER TABLE acrl_answer_history ADD KEY idx_acrl_answer_history_institution (institution_code, survey_year, sheet, question_id, part, changed_at)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_notes' AND INDEX_NAME = 'idx_acrl_question_notes_institution') = 0,
  'ALTER TABLE acrl_question_notes ADD KEY idx_acrl_question_notes_institution (institution_code, survey_year, sheet, question_id, part, created_at)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_invites' AND INDEX_NAME = 'idx_acrl_invites_institution_year') = 0,
  'ALTER TABLE acrl_invites ADD KEY idx_acrl_invites_institution_year (institution_code, survey_year)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

-- Add college-specific uniqueness, then remove keys that previously blocked duplicates.
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_assignments' AND INDEX_NAME = 'uq_acrl_section_assignment_institution') = 0,
  'ALTER TABLE acrl_section_assignments ADD UNIQUE KEY uq_acrl_section_assignment_institution (institution_code, survey_year, section, user_id)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_assignments' AND INDEX_NAME = 'uq_acrl_question_assignment_institution') = 0,
  'ALTER TABLE acrl_question_assignments ADD UNIQUE KEY uq_acrl_question_assignment_institution (institution_code, survey_year, sheet, question_id, part, user_id)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_assignments' AND INDEX_NAME = 'uq_acrl_section_assignment') > 0,
  'ALTER TABLE acrl_section_assignments DROP INDEX uq_acrl_section_assignment', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_assignments' AND INDEX_NAME = 'uq_acrl_question_assignment') > 0,
  'ALTER TABLE acrl_question_assignments DROP INDEX uq_acrl_question_assignment', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

-- Replace primary keys only after year-leading foreign-key indexes exist.
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answers' AND INDEX_NAME = 'PRIMARY' AND SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'institution_code') = 0,
  'ALTER TABLE acrl_answers DROP PRIMARY KEY, ADD PRIMARY KEY (institution_code, survey_year, sheet, question_id, part)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_status' AND INDEX_NAME = 'PRIMARY' AND SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'institution_code') = 0,
  'ALTER TABLE acrl_section_status DROP PRIMARY KEY, ADD PRIMARY KEY (institution_code, survey_year, section)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

-- Install institution foreign keys after every legacy row has the SC seed.
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answers' AND CONSTRAINT_NAME = 'fk_acrl_answers_institution') = 0,
  'ALTER TABLE acrl_answers ADD CONSTRAINT fk_acrl_answers_institution FOREIGN KEY (institution_code) REFERENCES acrl_institutions (code)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answer_history' AND CONSTRAINT_NAME = 'fk_acrl_answer_history_institution') = 0,
  'ALTER TABLE acrl_answer_history ADD CONSTRAINT fk_acrl_answer_history_institution FOREIGN KEY (institution_code) REFERENCES acrl_institutions (code)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_status' AND CONSTRAINT_NAME = 'fk_acrl_section_status_institution') = 0,
  'ALTER TABLE acrl_section_status ADD CONSTRAINT fk_acrl_section_status_institution FOREIGN KEY (institution_code) REFERENCES acrl_institutions (code)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_assignments' AND CONSTRAINT_NAME = 'fk_acrl_section_assignment_institution') = 0,
  'ALTER TABLE acrl_section_assignments ADD CONSTRAINT fk_acrl_section_assignment_institution FOREIGN KEY (institution_code) REFERENCES acrl_institutions (code)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_assignments' AND CONSTRAINT_NAME = 'fk_acrl_question_assignments_institution') = 0,
  'ALTER TABLE acrl_question_assignments ADD CONSTRAINT fk_acrl_question_assignments_institution FOREIGN KEY (institution_code) REFERENCES acrl_institutions (code)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_notes' AND CONSTRAINT_NAME = 'fk_acrl_question_notes_institution') = 0,
  'ALTER TABLE acrl_question_notes ADD CONSTRAINT fk_acrl_question_notes_institution FOREIGN KEY (institution_code) REFERENCES acrl_institutions (code)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_invites' AND CONSTRAINT_NAME = 'fk_acrl_invites_institution') = 0,
  'ALTER TABLE acrl_invites ADD CONSTRAINT fk_acrl_invites_institution FOREIGN KEY (institution_code) REFERENCES acrl_institutions (code)', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;

-- New rows must provide the college; the migration default only performs the SC backfill.
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answers' AND COLUMN_NAME = 'institution_code' AND COLUMN_DEFAULT IS NOT NULL) > 0,
  'ALTER TABLE acrl_answers ALTER COLUMN institution_code DROP DEFAULT', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_answer_history' AND COLUMN_NAME = 'institution_code' AND COLUMN_DEFAULT IS NOT NULL) > 0,
  'ALTER TABLE acrl_answer_history ALTER COLUMN institution_code DROP DEFAULT', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_status' AND COLUMN_NAME = 'institution_code' AND COLUMN_DEFAULT IS NOT NULL) > 0,
  'ALTER TABLE acrl_section_status ALTER COLUMN institution_code DROP DEFAULT', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_section_assignments' AND COLUMN_NAME = 'institution_code' AND COLUMN_DEFAULT IS NOT NULL) > 0,
  'ALTER TABLE acrl_section_assignments ALTER COLUMN institution_code DROP DEFAULT', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_assignments' AND COLUMN_NAME = 'institution_code' AND COLUMN_DEFAULT IS NOT NULL) > 0,
  'ALTER TABLE acrl_question_assignments ALTER COLUMN institution_code DROP DEFAULT', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_question_notes' AND COLUMN_NAME = 'institution_code' AND COLUMN_DEFAULT IS NOT NULL) > 0,
  'ALTER TABLE acrl_question_notes ALTER COLUMN institution_code DROP DEFAULT', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
SET @acrl073_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'acrl_invites' AND COLUMN_NAME = 'institution_code' AND COLUMN_DEFAULT IS NOT NULL) > 0,
  'ALTER TABLE acrl_invites ALTER COLUMN institution_code DROP DEFAULT', 'SELECT 1');
PREPARE acrl073_stmt FROM @acrl073_sql;
EXECUTE acrl073_stmt;
DEALLOCATE PREPARE acrl073_stmt;
