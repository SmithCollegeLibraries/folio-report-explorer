CREATE TABLE IF NOT EXISTS acrl_institution_members (
    user_id INT NOT NULL,
    institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role ENUM('member','admin') NOT NULL,
    granted_by VARCHAR(100) NULL,
    granted_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, institution_code),
    KEY idx_acrl_institution_members_institution_role (institution_code, role),
    CONSTRAINT fk_acrl_institution_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_acrl_institution_members_institution FOREIGN KEY (institution_code) REFERENCES acrl_institutions (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acrl_institution_member_backfills (
    institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    completed_at DATETIME NOT NULL,
    PRIMARY KEY (institution_code),
    CONSTRAINT fk_acrl_institution_member_backfill_institution FOREIGN KEY (institution_code) REFERENCES acrl_institutions (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

START TRANSACTION;

INSERT IGNORE INTO acrl_institution_members (user_id, institution_code, role, granted_by, granted_at)
SELECT DISTINCT u.id, 'SC', 'member', NULL, NOW()
FROM users u
WHERE u.is_approved = 1
    AND NOT EXISTS (
        SELECT 1 FROM acrl_institution_member_backfills marker WHERE marker.institution_code = 'SC'
    )
    AND (
        LOWER(u.email) LIKE '%@smith.edu'
        OR EXISTS (
            SELECT 1 FROM acrl_section_assignments section_assignment
            WHERE section_assignment.user_id = u.id AND section_assignment.institution_code = 'SC'
        )
        OR EXISTS (
            SELECT 1 FROM acrl_question_assignments question_assignment
            WHERE question_assignment.user_id = u.id AND question_assignment.institution_code = 'SC'
        )
        OR EXISTS (
            SELECT 1 FROM acrl_invites invite
            WHERE invite.accepted_user_id = u.id AND invite.institution_code = 'SC'
        )
    );

INSERT IGNORE INTO acrl_institution_member_backfills (institution_code, completed_at)
SELECT 'SC', NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM acrl_institution_member_backfills marker WHERE marker.institution_code = 'SC'
);

COMMIT;
