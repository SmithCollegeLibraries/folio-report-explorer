<?php

$migrationPath = __DIR__ . '/../../mysql/migrations/066_acrl_question_assignments.sql';
$failures = [];

function acrlQuestionMigrationSchemaCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

if (!file_exists($migrationPath)) {
    acrlQuestionMigrationSchemaCheck(false, '066_acrl_question_assignments.sql must be present.');
} else {
    $sql = (string)file_get_contents($migrationPath);
    $normalized = strtolower(preg_replace('/\s+/', ' ', str_replace('`', '', $sql)));
    $expectations = [
        'idempotently creates acrl_question_assignments' => strpos($normalized, 'create table if not exists acrl_question_assignments') !== false,
        'uses a SMALLINT survey_year' => preg_match('/survey_year\s+smallint\s+not null/', $normalized) === 1,
        'keeps sheet ASCII and case-sensitive' => preg_match('/sheet\s+varchar\(8\)\s+character set ascii\s+collate ascii_bin\s+not null/', $normalized) === 1,
        'keeps question_id ASCII and case-sensitive' => preg_match('/question_id\s+varchar\(32\)\s+character set ascii\s+collate ascii_bin\s+not null/', $normalized) === 1,
        'keeps the empty part distinct from other part values' => preg_match('/part\s+varchar\(1\)\s+character set ascii\s+collate ascii_bin\s+not null\s+default\s+\'\'/s', $normalized) === 1,
        'stores user_id and nullable assigned_by' => preg_match('/user_id\s+int\s+not null/', $normalized) === 1 && preg_match('/assigned_by\s+int\s+null/', $normalized) === 1,
        'records microsecond assignment timestamps' => preg_match('/assigned_at\s+datetime\(6\)\s+not null\s+default current_timestamp\(6\)/', $normalized) === 1,
        'uniquely identifies each assignment by full tuple and user' => preg_match('/unique key[^,]*\(survey_year,\s*sheet,\s*question_id,\s*part,\s*user_id\)/', $normalized) === 1,
        'indexes user lookups by survey year' => preg_match('/key[^,]*\(user_id,\s*survey_year\)/', $normalized) === 1,
        'references survey years and users with cascading deletes' => preg_match('/references\s+acrl_survey_years\s*\(\s*survey_year\s*\)\s+on delete cascade/', $normalized) === 1 && preg_match('/references\s+users\s*\(\s*id\s*\)\s+on delete cascade/', $normalized) === 1,
        'sets deleted assigning users to null' => preg_match('/references\s+users\s*\(\s*id\s*\)\s+on delete set null/', $normalized) === 1,
        'does not add a foreign key to acrl_questions' => strpos($normalized, 'references acrl_questions') === false,
        'does not drop or truncate data' => strpos($normalized, 'drop table') === false && strpos($normalized, 'truncate') === false,
    ];
    foreach ($expectations as $message => $condition) {
        acrlQuestionMigrationSchemaCheck($condition, $message . '.');
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL question assignment migration schema checks failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "PASS: ACRL question assignment migration schema contract\n";
