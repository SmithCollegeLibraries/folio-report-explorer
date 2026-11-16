<?php

$migrationPath = __DIR__ . '/../../mysql/migrations/074_acrl_institution_members.sql';
$recognitionPath = __DIR__ . '/../services/AcrlQuestionAssignmentMigrationRecognitionService.php';
$failures = [];

function acrlInstitutionMemberMigrationCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

if (!file_exists($migrationPath)) {
    acrlInstitutionMemberMigrationCheck(false, '074_acrl_institution_members.sql must be present.');
} else {
    $sql = (string)file_get_contents($migrationPath);
    $normalized = strtolower(preg_replace('/\s+/', ' ', str_replace('`', '', $sql)));
    foreach (['acrl_institution_members', 'acrl_institution_member_backfills'] as $table) {
        acrlInstitutionMemberMigrationCheck(
            strpos($normalized, 'create table if not exists ' . $table) !== false,
            $table . ' is created idempotently.'
        );
    }
    foreach ([
        'user_id int not null',
        'institution_code char(2) character set ascii collate ascii_bin not null',
        "role enum('member','admin') not null",
        'granted_by varchar(100) null',
        'granted_at datetime not null',
        'primary key (user_id, institution_code)',
        'key idx_acrl_institution_members_institution_role (institution_code, role)',
        'references users (id) on delete cascade',
        'references acrl_institutions (code) on delete cascade',
    ] as $definition) {
        acrlInstitutionMemberMigrationCheck(strpos($normalized, $definition) !== false, 'Member schema includes ' . $definition . '.');
    }
    foreach ([
        'primary key (institution_code)',
        'completed_at datetime not null',
        'references acrl_institutions (code) on delete cascade',
    ] as $definition) {
        acrlInstitutionMemberMigrationCheck(strpos($normalized, $definition) !== false, 'Backfill marker schema includes ' . $definition . '.');
    }
    foreach ([
        'start transaction',
        'commit',
        'insert ignore into acrl_institution_members',
        'insert ignore into acrl_institution_member_backfills',
        'u.is_approved = 1',
        "lower(u.email) like '%@smith.edu'",
        'acrl_section_assignments',
        'acrl_question_assignments',
        'accepted_user_id = u.id',
        'not exists ( select 1 from acrl_institution_member_backfills',
    ] as $rule) {
        acrlInstitutionMemberMigrationCheck(strpos($normalized, $rule) !== false, 'Backfill includes ' . $rule . '.');
    }
    acrlInstitutionMemberMigrationCheck(strpos($normalized, 'invited_by = u.id') === false, 'Accepted invite qualification uses accepted_user_id, not invited_by.');
    acrlInstitutionMemberMigrationCheck(stripos($sql, 'create procedure') === false && stripos($sql, 'delimiter ') === false, 'Migration is routine-free.');
    acrlInstitutionMemberMigrationCheck(stripos($sql, 'drop table') === false && stripos($sql, 'truncate ') === false && stripos($sql, 'rename table') === false, 'Migration does not require DROP, TRUNCATE, or RENAME privileges.');
}

$recognition = file_exists($recognitionPath) ? (string)file_get_contents($recognitionPath) : '';
acrlInstitutionMemberMigrationCheck(strpos($recognition, "'074_acrl_institution_members.sql'") !== false, 'Migration 074 participates in normal and empty-ledger recognition.');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL institution member migration checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}

echo "PASS: ACRL institution member migration schema and backfill contract\n";
