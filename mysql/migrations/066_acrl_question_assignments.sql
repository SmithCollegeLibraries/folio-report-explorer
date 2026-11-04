CREATE TABLE IF NOT EXISTS acrl_question_assignments (
    survey_year SMALLINT NOT NULL,
    sheet VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    question_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    part VARCHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    user_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_acrl_question_assignment (survey_year, sheet, question_id, part, user_id),
    KEY idx_acrl_question_assignments_user_year (user_id, survey_year),
    CONSTRAINT fk_acrl_question_assignments_year
        FOREIGN KEY (survey_year) REFERENCES acrl_survey_years (survey_year) ON DELETE CASCADE,
    CONSTRAINT fk_acrl_question_assignments_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_acrl_question_assignments_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
