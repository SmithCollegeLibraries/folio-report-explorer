<?php

$migrationServicePath = __DIR__ . '/../services/MigrationService.php';
$recognitionPath = __DIR__ . '/../services/AcrlQuestionAssignmentMigrationRecognitionService.php';
$failures = [];

function acrlInviteRecognitionCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

$migrationSource = (string)file_get_contents($migrationServicePath);
$recognitionSource = (string)file_get_contents($recognitionPath);
$baselineStart = strpos($recognitionSource, 'function baselineCurrentSchema');
$baselineSource = $baselineStart === false ? '' : substr($recognitionSource, $baselineStart);
$executeStart = strpos($migrationSource, 'function executeMigrationFile');
$executeEnd = strpos($migrationSource, 'function migrationAppearsApplied', $executeStart === false ? 0 : $executeStart);
$executeSource = $executeStart === false ? '' : substr($migrationSource, $executeStart, $executeEnd === false ? null : $executeEnd - $executeStart);

acrlInviteRecognitionCheck(strpos($migrationSource, "case '067_acrl_invites.sql'") !== false, 'MigrationService delegates migration 067 recognition.');
acrlInviteRecognitionCheck(strpos($recognitionSource, 'function acrlInvitesAppearComplete') !== false, 'Recognition service exposes the invite schema completeness check.');
foreach (['acrl_invites', 'acrl_invite_targets', 'COLUMN_TYPE', 'COLUMN_DEFAULT', 'uq_acrl_invite_target'] as $term) {
    acrlInviteRecognitionCheck(strpos($recognitionSource, $term) !== false, 'Invite recognition checks both tables, column metadata/defaults, and target uniqueness (' . $term . ').');
}
acrlInviteRecognitionCheck(strpos($baselineSource, '066_acrl_question_assignments.sql') !== false
    && strpos($baselineSource, '067_acrl_invites.sql') !== false,
    'Empty-ledger baseline recognition handles both 066 and 067 independently.');
acrlInviteRecognitionCheck($executeSource !== '' && strpos($executeSource, '066_acrl_question_assignments.sql') === false
    && strpos($executeSource, 'AcrlQuestionAssignmentMigrationRecognitionService') !== false,
    'Post-execution schema validation is table-driven instead of hard-coded to 066.');
acrlInviteRecognitionCheck(count(file($migrationServicePath)) <= 574, 'MigrationService.php remains within the 574-line cap.');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL invite migration recognition checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}

echo "PASS: ACRL invite migration recognition is registered and table-driven\n";
