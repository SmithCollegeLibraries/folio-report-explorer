CREATE TABLE IF NOT EXISTS acrl_answers (
  survey_year SMALLINT NOT NULL,
  sheet VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  question_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  part VARCHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  value_text TEXT NULL,
  value_number DECIMAL(18,2) NULL,
  note TEXT NULL,
  source VARCHAR(12) NOT NULL DEFAULT 'manual',
  source_label VARCHAR(255) NULL,
  updated_by VARCHAR(100) NOT NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (survey_year, sheet, question_id, part),
  KEY idx_acrl_answers_question (survey_year, sheet, question_id, part),
  CONSTRAINT chk_acrl_answer_source CHECK (source IN ('manual', 'import')),
  CONSTRAINT fk_acrl_answer_question FOREIGN KEY (survey_year, sheet, question_id, part)
    REFERENCES acrl_questions(survey_year, sheet, question_id, part) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acrl_answer_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_year SMALLINT NOT NULL,
  sheet VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  question_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  part VARCHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  old_value_text TEXT NULL,
  old_value_number DECIMAL(18,2) NULL,
  old_note TEXT NULL,
  old_source VARCHAR(12) NULL,
  new_value_text TEXT NULL,
  new_value_number DECIMAL(18,2) NULL,
  new_note TEXT NULL,
  new_source VARCHAR(12) NOT NULL,
  changed_by VARCHAR(100) NOT NULL,
  changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_acrl_answer_history_item (survey_year, sheet, question_id, part, changed_at),
  KEY idx_acrl_answer_history_changed (changed_by, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acrl_section_status (
  survey_year SMALLINT NOT NULL,
  section VARCHAR(120) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'open',
  set_by VARCHAR(100) NULL,
  set_at DATETIME(6) NULL,
  reopened_by VARCHAR(100) NULL,
  reopened_at DATETIME(6) NULL,
  PRIMARY KEY (survey_year, section),
  CONSTRAINT chk_acrl_section_status CHECK (status IN ('open', 'ready')),
  CONSTRAINT fk_acrl_section_status_year FOREIGN KEY (survey_year)
    REFERENCES acrl_survey_years(survey_year) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
