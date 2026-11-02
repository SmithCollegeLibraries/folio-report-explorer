<?php
require_once __DIR__ . '/support/VisualizationFixture.php';
require_once __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;

visualizationCase('migration 065 creates one durable history version counter idempotently without privileged statements', function () {
    $path = __DIR__ . '/../../mysql/migrations/065_visualization_history_version.sql';
    visualizationAssert(is_file($path), 'Migration 065 must create the durable visualization history version counter.');
    $source = file_get_contents($path);
    visualizationAssert(is_string($source) && preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?visualization_history_version`?/i', $source) === 1, 'Create the version table idempotently.');
    visualizationAssert(preg_match('/INSERT\s+IGNORE\s+INTO\s+`?visualization_history_version`?/i', $source) === 1, 'Seed the single counter without resetting an existing value.');
    $statements = preg_replace('/--[^\n]*|\/\*.*?\*\//s', '', $source);
    visualizationAssert(preg_match('/\b(?:PROCEDURE|FUNCTION|RENAME|DROP|TRUNCATE|REPLACE)\b/i', $statements) !== 1, 'Migration needs no routine or DROP privileges.');

    $sql = preg_replace('/^\s*--[^\n]*(?:\n|$)/m', '', $source);
    $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
    $sql = preg_replace('/\)\s*ENGINE\s*=\s*\w+[^;]*;/i', ');', $sql);
    $db = new yii\db\Connection(['dsn' => 'sqlite::memory:']);
    $db->open();
    $db->pdo->exec($sql);
    $db->pdo->exec($sql);
    visualizationSame(1, (int)$db->createCommand('SELECT COUNT(*) FROM visualization_history_version')->queryScalar(), 'Reruns leave exactly one counter row.');
    visualizationSame(0, (int)$db->createCommand('SELECT version FROM visualization_history_version WHERE id = 1')->queryScalar(), 'Reruns do not reset the version.');
    $db->createCommand('UPDATE visualization_history_version SET version = version + 1 WHERE id = 1')->execute();
    $db->createCommand('UPDATE visualization_history_version SET version = version + 1 WHERE id = 1')->execute();
    visualizationSame(2, (int)$db->createCommand('SELECT version FROM visualization_history_version WHERE id = 1')->queryScalar(), 'The database supports atomic monotonic increments.');

    $runner = file_get_contents(__DIR__ . '/../services/MigrationService.php');
    visualizationAssert(strpos($runner, "'065_visualization_history_version.sql'") !== false, 'The runner recognizes and checks the version migration.');
});

visualizationFinish();
