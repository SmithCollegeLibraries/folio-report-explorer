CREATE TABLE IF NOT EXISTS acrl_section_assignments (
  survey_year SMALLINT NOT NULL,
  section VARCHAR(120) NOT NULL,
  user_id INT NOT NULL,
  assigned_by INT NULL,
  assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_acrl_section_assignment (survey_year, section, user_id),
  KEY idx_acrl_section_assignment_user (user_id, survey_year),
  KEY idx_acrl_section_assignment_assigned_by (assigned_by),
  CONSTRAINT fk_acrl_section_assignment_year FOREIGN KEY (survey_year)
    REFERENCES acrl_survey_years(survey_year) ON DELETE CASCADE,
  CONSTRAINT fk_acrl_section_assignment_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_acrl_section_assignment_assigned_by FOREIGN KEY (assigned_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
