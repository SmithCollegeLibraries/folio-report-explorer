CREATE TABLE IF NOT EXISTS acrl_invites (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    survey_year SMALLINT NOT NULL,
    invited_by INT NULL,
    link_origin VARCHAR(255) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    send_count INT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    last_sent_at DATETIME(6) NULL,
    accepted_user_id INT NULL,
    accepted_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    KEY idx_acrl_invites_email_status (email, status),
    KEY idx_acrl_invites_survey_year (survey_year),
    CONSTRAINT fk_acrl_invites_year
        FOREIGN KEY (survey_year) REFERENCES acrl_survey_years (survey_year) ON DELETE CASCADE,
    CONSTRAINT fk_acrl_invites_invited_by
        FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_acrl_invites_accepted_user
        FOREIGN KEY (accepted_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acrl_invite_targets (
    invite_id INT NOT NULL,
    kind VARCHAR(8) NOT NULL,
    section VARCHAR(120) NOT NULL DEFAULT '',
    sheet VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    question_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    part VARCHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    UNIQUE KEY uq_acrl_invite_target (invite_id, kind, section, sheet, question_id, part),
    CONSTRAINT fk_acrl_invite_targets_invite
        FOREIGN KEY (invite_id) REFERENCES acrl_invites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
