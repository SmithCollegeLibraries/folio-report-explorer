<?php

$migrationPath = __DIR__ . '/../../mysql/migrations/067_acrl_invites.sql';
$failures = [];

function acrlInviteMigrationCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function acrlInviteMigrationTable(string $sql, string $table): string
{
    if (preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\\s*\\((.*?)\\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/s', $sql, $match) !== 1) {
        return '';
    }
    return strtolower(preg_replace('/\\s+/', ' ', str_replace('`', '', $match[1])));
}

if (!file_exists($migrationPath)) {
    acrlInviteMigrationCheck(false, '067_acrl_invites.sql must be present.');
} else {
    $sql = (string)file_get_contents($migrationPath);
    $normalized = strtolower(preg_replace('/\\s+/', ' ', str_replace('`', '', $sql)));
    $invites = acrlInviteMigrationTable($sql, 'acrl_invites');
    $targets = acrlInviteMigrationTable($sql, 'acrl_invite_targets');
    foreach (['acrl_invites', 'acrl_invite_targets'] as $table) {
        acrlInviteMigrationCheck(strpos($normalized, 'create table if not exists ' . $table) !== false, $table . ' is created idempotently.');
    }
    foreach ([
        'id int not null auto_increment primary key', 'email varchar(191) not null', 'survey_year smallint not null',
        'invited_by int null', 'link_origin varchar(255) not null', "status varchar(10) not null default 'pending'",
        'send_count int not null default 0', 'created_at datetime(6) not null default current_timestamp(6)',
        'expires_at datetime(6) not null', 'last_sent_at datetime(6) null', 'accepted_user_id int null',
        'accepted_at datetime(6) null', 'revoked_at datetime(6) null',
    ] as $column) {
        acrlInviteMigrationCheck(strpos($invites, $column) !== false, 'acrl_invites has exact column definition: ' . $column . '.');
    }
    acrlInviteMigrationCheck(strpos($invites, 'key idx_acrl_invites_email_status (email, status)') !== false, 'Invites index email and status.');
    acrlInviteMigrationCheck(preg_match('/key idx_acrl_invites_[a-z_]+ \\(survey_year\\)/', $invites) === 1, 'Invites index survey_year.');
    acrlInviteMigrationCheck(strpos($invites, 'references acrl_survey_years (survey_year) on delete cascade') !== false, 'Invites cascade with their survey year.');
    acrlInviteMigrationCheck(substr_count($invites, 'references users (id) on delete set null') === 2, 'Deleted inviter and accepted user references become NULL.');
    foreach ([
        'invite_id int not null', 'kind varchar(8) not null', "section varchar(120) not null default ''",
        "sheet varchar(8) character set ascii collate ascii_bin not null default ''",
        "question_id varchar(32) character set ascii collate ascii_bin not null default ''",
        "part varchar(1) character set ascii collate ascii_bin not null default ''",
    ] as $column) {
        acrlInviteMigrationCheck(strpos($targets, $column) !== false, 'acrl_invite_targets has exact column definition: ' . $column . '.');
    }
    acrlInviteMigrationCheck(strpos($targets, 'unique key uq_acrl_invite_target (invite_id, kind, section, sheet, question_id, part)') !== false, 'Invite targets use the complete target tuple as their unique key.');
    acrlInviteMigrationCheck(strpos($targets, 'references acrl_invites (id) on delete cascade') !== false, 'Targets cascade with their invite.');
    acrlInviteMigrationCheck(strpos($normalized, 'drop ') === false && strpos($normalized, 'truncate ') === false, 'Migration does not drop or truncate data.');
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL invite migration schema checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}

echo "PASS: ACRL invite migration schema contract\n";
