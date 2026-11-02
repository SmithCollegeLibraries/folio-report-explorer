<?php
require_once __DIR__ . '/support/VisualizationFixture.php';
require_once __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;
use app\services\VisualizationHistoryMigrationRecognitionService;

function visualizationMigrationSource(): string
{
    $path = __DIR__ . '/../../mysql/migrations/064_visualization_history.sql';
    visualizationAssert(is_file($path), 'Migration 064 must create and seed visualization_history; it is absent in the tests round.');
    $source = file_get_contents($path);
    visualizationAssert(is_string($source) && trim($source) !== '', 'Migration 064 must contain executable SQL.');
    return $source;
}

/** Only dialect syntax changes: execute the actual migration, never reconstructed INSERT fixtures. */
function visualizationMigrationSqliteSql(string $source): string
{
    $sql = preg_replace('/^\s*--[^\n]*(?:\n|$)/m', '', $source);
    $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
    $sql = preg_replace('/`?id`?\s+(?:BIGINT|INT|INTEGER)(?:\s+UNSIGNED)?\s+(?:NOT\s+NULL\s+)?AUTO_INCREMENT(?:\s+PRIMARY\s+KEY)?/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/,\s*PRIMARY\s+KEY\s*\(\s*`?id`?\s*\)/i', '', $sql);
    $sql = preg_replace('/\bENUM\s*\(\s*\'purchases\'\s*,\s*\'budget\'\s*\)/i', 'TEXT', $sql);
    $sql = preg_replace('/\bUNIQUE\s+(?:KEY|INDEX)\s+`?\w+`?\s*\(/i', 'UNIQUE (', $sql);
    $sql = preg_replace('/\)\s*ENGINE\s*=\s*\w+[^;]*;/i', ');', $sql);
    $sql = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\(\))?/i', '', $sql);
    return $sql;
}

function visualizationExecuteMigration($db, string $source)
{
    try {
        $db->pdo->exec(visualizationMigrationSqliteSql($source));
    } catch (Throwable $error) {
        throw new RuntimeException('SQLite could not execute the actual migration: ' . $error->getMessage() . '; orchestrator runs on MySQL/MariaDB');
    }
}

function visualizationExpectedSeedKeys(): array
{
    $keys = [];
    foreach (['purchases' => [2007, 2022], 'budget' => [2011, 2024]] as $kind => $range) {
        for ($year = $range[0]; $year <= $range[1]; $year++) $keys[] = 'SC|' . $kind . '|' . $year;
    }
    sort($keys);
    return $keys;
}

function visualizationActualSeedKeys($db): array
{
    $keys = $db->createCommand("SELECT acq_unit_code || '|' || kind || '|' || fiscal_year_end FROM visualization_history")->queryColumn();
    sort($keys);
    return $keys;
}

function visualizationRecognitionDatabase()
{
    $f = new VisualizationFixture();
    $db = $f->db;
    $db->createCommand("ATTACH DATABASE ':memory:' AS information_schema")->execute();
    $db->pdo->sqliteCreateFunction('DATABASE', function () { return 'synthetic'; }, 0);
    $db->createCommand('CREATE TABLE information_schema.COLUMNS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT, COLUMN_TYPE TEXT, DATA_TYPE TEXT, IS_NULLABLE TEXT, CHARACTER_MAXIMUM_LENGTH INT, NUMERIC_PRECISION INT, NUMERIC_SCALE INT, COLUMN_DEFAULT TEXT, EXTRA TEXT)')->execute();
    $db->createCommand('CREATE TABLE information_schema.STATISTICS (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, INDEX_NAME TEXT, NON_UNIQUE INT, SEQ_IN_INDEX INT, COLUMN_NAME TEXT, SUB_PART INT)')->execute();
    $db->createCommand('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)')->execute();
    $f->insert('information_schema.TABLES', ['TABLE_SCHEMA' => 'synthetic', 'TABLE_NAME' => 'visualization_history']);
    foreach ([
        'id' => ['int(11)', 'int', 'NO', null, 10, 0],
        'acq_unit_code' => ['varchar(50)', 'varchar', 'NO', 50, null, null],
        'kind' => ["enum('purchases','budget')", 'enum', 'NO', 9, null, null],
        'fiscal_year_end' => ['smallint(6)', 'smallint', 'NO', null, 5, 0],
        'total_amount' => ['decimal(14,2)', 'decimal', 'NO', null, 14, 2],
        'electronic_amount' => ['decimal(14,2)', 'decimal', 'YES', null, 14, 2],
        'physical_amount' => ['decimal(14,2)', 'decimal', 'YES', null, 14, 2],
        'note' => ['varchar(255)', 'varchar', 'YES', 255, null, null],
        'updated_by' => ['int(11)', 'int', 'YES', null, 10, 0],
        'updated_at' => ['timestamp', 'timestamp', 'NO', null, null, null],
        'deleted_at' => ['datetime', 'datetime', 'YES', null, null, null],
        'deleted_by' => ['int(11)', 'int', 'YES', null, 10, 0],
    ] as $column => $shape) {
        $f->insert('information_schema.COLUMNS', ['TABLE_SCHEMA' => 'synthetic', 'TABLE_NAME' => 'visualization_history', 'COLUMN_NAME' => $column, 'COLUMN_TYPE' => $shape[0], 'DATA_TYPE' => $shape[1], 'IS_NULLABLE' => $shape[2], 'CHARACTER_MAXIMUM_LENGTH' => $shape[3], 'NUMERIC_PRECISION' => $shape[4], 'NUMERIC_SCALE' => $shape[5], 'EXTRA' => $column === 'id' ? 'auto_increment' : '']);
    }
    foreach (['acq_unit_code', 'kind', 'fiscal_year_end'] as $index => $column) $f->insert('information_schema.STATISTICS', ['TABLE_SCHEMA' => 'synthetic', 'TABLE_NAME' => 'visualization_history', 'INDEX_NAME' => 'uq_visualization_history', 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => $index + 1, 'COLUMN_NAME' => $column, 'SUB_PART' => null]);
    foreach (visualizationExpectedSeedKeys() as $key) {
        list($code, $kind, $year) = explode('|', $key);
        // Recognition uses keys only, including edited and soft-deleted rows.
        $f->history((int)$year, $kind, 111, $kind === 'purchases' ? 30 : null, $kind === 'purchases' ? 40 : null);
    }
    $db->createCommand("UPDATE visualization_history SET total_amount = 222, deleted_at = '2026-01-01', deleted_by = 7 WHERE fiscal_year_end = 2009")->execute();
    return $db;
}

visualizationCase('migration 064 shape, thirty seed keys, preservation and positive/negative recognition', function () {
    $source = visualizationMigrationSource();
    visualizationAssert(preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?visualization_history`?/i', $source) === 1, 'Create the history table idempotently.');
    foreach ([
        'acq_unit_code' => 'VARCHAR\s*\(\s*50\s*\)', 'kind' => "ENUM\s*\(\s*'purchases'\s*,\s*'budget'\s*\)",
        'fiscal_year_end' => 'SMALLINT', 'total_amount' => 'DECIMAL\s*\(\s*14\s*,\s*2\s*\)',
        'electronic_amount' => 'DECIMAL\s*\(\s*14\s*,\s*2\s*\)\s+NULL', 'physical_amount' => 'DECIMAL\s*\(\s*14\s*,\s*2\s*\)\s+NULL',
        'note' => 'VARCHAR\s*\(\s*255\s*\)\s+NULL', 'updated_by' => 'INT\s+NULL', 'deleted_at' => 'DATETIME\s+NULL', 'deleted_by' => 'INT\s+NULL',
    ] as $column => $type) visualizationAssert(preg_match('/`?' . $column . '`?\s+' . $type . '/i', $source) === 1, 'Migration declares correct ' . $column . ' type/nullability.');
    $statements = preg_replace('/--[^\n]*|\/\*.*?\*\//s', '', $source);
    visualizationAssert(preg_match('/\b(?:PROCEDURE|FUNCTION|RENAME|DROP|TRUNCATE|REPLACE)\b/i', $statements) !== 1, 'Migration needs neither routine nor DROP privileges and cannot replace edits.');
    visualizationAssert(preg_match('/INSERT\s+IGNORE\s+INTO/i', $source) === 1, 'Seed with INSERT IGNORE to preserve existing keys.');
    $db = new yii\db\Connection(['dsn' => 'sqlite::memory:']);
    $db->open();
    visualizationExecuteMigration($db, $source);
    visualizationSame(visualizationExpectedSeedKeys(), visualizationActualSeedKeys($db), 'Exactly sixteen purchases and fourteen budget keys, including FY2009, are seeded.');
    $recognitionDb = visualizationRecognitionDatabase();
    visualizationSame(true, VisualizationHistoryMigrationRecognitionService::visualizationHistoryAppearsComplete($recognitionDb), 'Complete shape plus all keys recognizes edited/deleted seeds.');
    foreach ([
        "DELETE FROM information_schema.TABLES; DELETE FROM information_schema.COLUMNS; DELETE FROM information_schema.STATISTICS; DROP TABLE visualization_history",
        "DELETE FROM information_schema.COLUMNS WHERE COLUMN_NAME = 'deleted_by'",
        "UPDATE information_schema.COLUMNS SET COLUMN_TYPE = 'decimal(12,2)', NUMERIC_PRECISION = 12 WHERE COLUMN_NAME = 'total_amount'",
        "UPDATE information_schema.COLUMNS SET IS_NULLABLE = 'NO' WHERE COLUMN_NAME = 'physical_amount'",
        "UPDATE information_schema.COLUMNS SET COLUMN_TYPE = 'varchar(40)', CHARACTER_MAXIMUM_LENGTH = 40 WHERE COLUMN_NAME = 'acq_unit_code'",
        "UPDATE information_schema.STATISTICS SET NON_UNIQUE = 1",
        "UPDATE information_schema.STATISTICS SET SUB_PART = 5 WHERE SEQ_IN_INDEX = 1",
        "UPDATE information_schema.STATISTICS SET COLUMN_NAME = 'id' WHERE SEQ_IN_INDEX = 3",
        "DELETE FROM visualization_history WHERE kind = 'budget' AND fiscal_year_end = 2024",
    ] as $mutation) {
        $bad = visualizationRecognitionDatabase();
        $bad->pdo->exec($mutation);
        visualizationSame(false, VisualizationHistoryMigrationRecognitionService::visualizationHistoryAppearsComplete($bad), 'Reject missing table/column/key or wrong schema shape: ' . $mutation);
    }
    $method = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
    if (PHP_VERSION_ID < 80500) $method->setAccessible(true);
    visualizationSame(true, $method->invoke(null, $recognitionDb, '064_visualization_history.sql'), 'Runner delegates 064 to history recognition.');
    $runner = file_get_contents(__DIR__ . '/../services/MigrationService.php');
    visualizationAssert(substr_count($runner, 'VisualizationHistoryMigrationRecognitionService::visualizationHistoryAppearsComplete') >= 2, 'Both migrationAppearsApplied and databaseAppearsCurrent must require 064 recognition.');
});

visualizationCase('direct migration SQL double execution, partial seeds and edited/deleted seed preservation independently of the runner', function () {
    $source = visualizationMigrationSource();
    foreach (['empty', 'partial'] as $state) {
        $db = new yii\db\Connection(['dsn' => 'sqlite::memory:']);
        $db->open();
        visualizationExecuteMigration($db, $source);
        if ($state === 'partial') {
            $db->createCommand("DELETE FROM visualization_history WHERE kind <> 'purchases' OR fiscal_year_end NOT IN (2009, 2010)")->execute();
            visualizationAssert((int)$db->createCommand('SELECT COUNT(*) FROM visualization_history')->queryScalar() < 30, 'Start the rerun with a genuinely partial seed set.');
        }
        $db->createCommand("UPDATE visualization_history SET total_amount = 123.45, note = 'Synthetic admin edit', updated_by = 7 WHERE kind = 'purchases' AND fiscal_year_end = 2009")->execute();
        $db->createCommand("UPDATE visualization_history SET deleted_at = '2026-01-01 00:00:00', deleted_by = 8 WHERE kind = 'purchases' AND fiscal_year_end = 2010")->execute();
        $before = $db->createCommand("SELECT * FROM visualization_history WHERE kind = 'purchases' AND fiscal_year_end IN (2009, 2010) ORDER BY fiscal_year_end")->queryAll();
        visualizationExecuteMigration($db, $source);
        visualizationSame(visualizationExpectedSeedKeys(), visualizationActualSeedKeys($db), 'A direct rerun completes all thirty keys, without duplicates.');
        $after = $db->createCommand("SELECT * FROM visualization_history WHERE kind = 'purchases' AND fiscal_year_end IN (2009, 2010) ORDER BY fiscal_year_end")->queryAll();
        visualizationSame($before, $after, 'A rerun does not overwrite edited amounts/notes/actors or resurrect deleted rows.');
        $rows = $db->createCommand('SELECT * FROM visualization_history ORDER BY id')->queryAll();
        visualizationExecuteMigration($db, $source);
        visualizationSame($rows, $db->createCommand('SELECT * FROM visualization_history ORDER BY id')->queryAll(), 'An additional direct execution inserts nothing and changes no seed values.');
    }
    fwrite(STDOUT, "Actual 064 SQL executed on SQLite; orchestrator runs on MySQL/MariaDB for native dialect and restricted-user gates.\n");
});

visualizationFinish();
