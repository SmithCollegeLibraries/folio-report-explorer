<?php

$initSqlPath = __DIR__ . '/../../mysql/init.sql';

if (!file_exists($initSqlPath)) {
    fwrite(STDERR, "mysql/init.sql is missing at {$initSqlPath}\n");
    exit(1);
}

$initSql = file_get_contents($initSqlPath);
if ($initSql === false) {
    fwrite(STDERR, "Failed to read mysql/init.sql\n");
    exit(1);
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing text: {$needle}\n");
        exit(1);
    }
}

assertContainsText(
    'is_global TINYINT(1) DEFAULT 0',
    $initSql,
    'mysql/init.sql should bootstrap saved_queries with the is_global column required by the dashboard query path.'
);

assertContainsText(
    'CREATE TABLE IF NOT EXISTS user_dashboard_prefs',
    $initSql,
    'mysql/init.sql should bootstrap the user_dashboard_prefs table required by per-user dashboard preferences.'
);

fwrite(STDOUT, "MySQL dashboard bootstrap schema test passed\n");