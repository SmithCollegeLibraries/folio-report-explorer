CREATE TABLE IF NOT EXISTS acrl_instruction_blocks (
  survey_year SMALLINT NOT NULL,
  block_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  heading VARCHAR(255) NOT NULL,
  block_text MEDIUMTEXT NOT NULL,
  block_kind VARCHAR(16) NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by VARCHAR(100) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (survey_year, block_key),
  KEY idx_acrl_instruction_blocks_year_kind (survey_year, block_kind),
  CONSTRAINT chk_acrl_instruction_block_kind CHECK (block_kind IN ('question', 'group', 'definition')),
  CONSTRAINT fk_acrl_instruction_block_year FOREIGN KEY (survey_year)
    REFERENCES acrl_survey_years(survey_year) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acrl_instruction_links (
  survey_year SMALLINT NOT NULL,
  sheet VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  question_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  part VARCHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  block_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (survey_year, sheet, question_id, part, block_key),
  KEY idx_acrl_instruction_links_block (survey_year, block_key),
  CONSTRAINT fk_acrl_instruction_link_question FOREIGN KEY (survey_year, sheet, question_id, part)
    REFERENCES acrl_questions(survey_year, sheet, question_id, part) ON DELETE CASCADE,
  CONSTRAINT fk_acrl_instruction_link_block FOREIGN KEY (survey_year, block_key)
    REFERENCES acrl_instruction_blocks(survey_year, block_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acrl_question_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_year SMALLINT NOT NULL,
  sheet VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  question_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  part VARCHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  note TEXT NOT NULL,
  source VARCHAR(255) NOT NULL,
  origin_year SMALLINT NOT NULL,
  created_by VARCHAR(100) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  edited_by VARCHAR(100) NULL,
  edited_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY idx_acrl_question_notes_key (survey_year, sheet, question_id, part, created_at),
  KEY idx_acrl_question_notes_origin (origin_year, created_at),
  CONSTRAINT chk_acrl_question_note_sheet CHECK (sheet IN ('survey', 'trends')),
  CONSTRAINT chk_acrl_question_note_origin CHECK (origin_year BETWEEN 1900 AND 2100),
  CONSTRAINT fk_acrl_question_note_question FOREIGN KEY (survey_year, sheet, question_id, part)
    REFERENCES acrl_questions(survey_year, sheet, question_id, part) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
