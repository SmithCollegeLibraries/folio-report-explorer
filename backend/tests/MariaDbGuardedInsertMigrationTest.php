<?php

$migrationPaths = [
    __DIR__ . '/../../mysql/migrations/029_same_title_holdings_overlap_hint.sql',
    __DIR__ . '/../../mysql/migrations/030_collection_location_reference_hint.sql',
];

foreach ($migrationPaths as $migrationPath) {
    $sql = (string)file_get_contents($migrationPath);
    $guardCount = substr_count($sql, 'WHERE NOT EXISTS (');
    $dualGuardCount = preg_match_all('/FROM DUAL\s+WHERE NOT EXISTS\s*\(/i', $sql, $matches);

    if ($guardCount !== 2) {
        fwrite(STDERR, basename($migrationPath) . " must retain exactly two guarded inserts.\n");
        exit(1);
    }

    if ($dualGuardCount !== $guardCount) {
        fwrite(STDERR, basename($migrationPath) . " must use FROM DUAL before every WHERE NOT EXISTS insert guard.\n");
        exit(1);
    }
}

fwrite(STDOUT, "MariaDB guarded insert migration test passed\n");
