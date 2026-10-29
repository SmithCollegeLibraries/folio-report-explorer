<?php

$servicePath = __DIR__ . '/../services/MigrationService.php';
require_once $servicePath;

use app\services\MigrationService;

function acrlMigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

final class AcrlRetirementTestSchema
{
    private $tables;

    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function getTableSchema(string $table, bool $refresh = false)
    {
        if (!array_key_exists($table, $this->tables)) {
            return null;
        }
        return (object)['columns' => array_fill_keys($this->tables[$table], new stdClass())];
    }
}

final class AcrlRetirementTestDatabase
{
    public $schema;
    public $reportActive;
    public $rowCounts;

    public function __construct(array $tables, ?int $reportActive = null, array $rowCounts = [])
    {
        $this->schema = new AcrlRetirementTestSchema($tables);
        $this->reportActive = $reportActive;
        $this->rowCounts = $rowCounts;
    }

    public function createCommand(string $sql, array $params = []): AcrlRetirementTestCommand
    {
        return new AcrlRetirementTestCommand($this, $sql, $params);
    }
}

final class AcrlRetirementTestCommand
{
    private $database;
    private $sql;

    public function __construct(AcrlRetirementTestDatabase $database, string $sql, array $params)
    {
        $this->database = $database;
        $this->sql = $sql;
    }

    public function queryOne()
    {
        if (strpos($this->sql, 'SELECT is_active FROM report_templates') !== false) {
            return $this->database->reportActive === null
                ? false
                : ['is_active' => $this->database->reportActive];
        }
        return false;
    }

    public function queryScalar()
    {
        if (preg_match('/FROM ([a-z_]+)$/i', trim($this->sql), $matches)) {
            return $this->database->rowCounts[$matches[1]] ?? 0;
        }
        return 0;
    }
}

$migrationRecognition = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
$databaseCurrent = new ReflectionMethod(MigrationService::class, 'databaseAppearsCurrent');
if (PHP_VERSION_ID < 80100) {
    $migrationRecognition->setAccessible(true);
    $databaseCurrent->setAccessible(true);
}

$commonTables = [
    'report_templates' => ['slug', 'is_active'],
    'report_expense_allocations' => ['id'],
    'query_log' => ['data_source'],
    'query_jobs' => ['data_source'],
];
$oldTable = new AcrlRetirementTestDatabase($commonTables + ['acrl_statistics' => ['id']], 1, ['acrl_statistics' => 350]);
$retiredTable = new AcrlRetirementTestDatabase($commonTables + ['acrl_statistics_retired' => ['id']], 0, ['acrl_statistics_retired' => 350]);
$missingReport = new AcrlRetirementTestDatabase($commonTables + ['acrl_statistics_retired' => ['id']], null, ['acrl_statistics_retired' => 350]);
$bothTables = new AcrlRetirementTestDatabase($commonTables + [
    'acrl_statistics' => ['id'],
    'acrl_statistics_retired' => ['id'],
], 0, ['acrl_statistics_retired' => 350]);
$noTables = new AcrlRetirementTestDatabase($commonTables, null);

acrlMigrationAssert(
    !$migrationRecognition->invoke(null, $oldTable, '061_retire_acrl_statistics.sql'),
    'An active old table must not be recognized as migration 061 complete on an empty ledger.'
);
acrlMigrationAssert(
    !$databaseCurrent->invoke(null, $oldTable),
    'A database with the old table must not be baselined from an empty migration ledger before 061 runs.'
);
acrlMigrationAssert(
    $migrationRecognition->invoke(null, $retiredTable, '061_retire_acrl_statistics.sql'),
    'A renamed table and inactive report template must satisfy migration 061 recognition.'
);
acrlMigrationAssert(
    $migrationRecognition->invoke(null, $missingReport, '061_retire_acrl_statistics.sql'),
    'A renamed table with a missing report template must satisfy migration 061 recognition.'
);
acrlMigrationAssert(
    !$migrationRecognition->invoke(null, new AcrlRetirementTestDatabase($commonTables + ['acrl_statistics_retired' => ['id']], 1), '061_retire_acrl_statistics.sql'),
    'An active report template must prevent migration 061 recognition.'
);
acrlMigrationAssert(
    !$migrationRecognition->invoke(null, $bothTables, '061_retire_acrl_statistics.sql'),
    'Both table names present must prevent migration 061 recognition.'
);
acrlMigrationAssert(
    !$migrationRecognition->invoke(null, $noTables, '061_retire_acrl_statistics.sql'),
    'Neither table name present must not satisfy migration 061 recognition.'
);

acrlMigrationAssert($migrationRecognition->invoke(null, $oldTable, '010_local_supplementary_data.sql'), 'Migration 010 must recognize the original table name.');
acrlMigrationAssert($migrationRecognition->invoke(null, $retiredTable, '010_local_supplementary_data.sql'), 'Migration 010 must recognize the renamed table.');
acrlMigrationAssert($migrationRecognition->invoke(null, $oldTable, '011_seed_acrl_data.sql'), 'Migration 011 must recognize seeded rows under the original name.');
acrlMigrationAssert($migrationRecognition->invoke(null, $retiredTable, '011_seed_acrl_data.sql'), 'Migration 011 must recognize the retired table without reseeding it.');

$migrationPath = __DIR__ . '/../../mysql/migrations/061_retire_acrl_statistics.sql';
$sql = (string)file_get_contents($migrationPath);
$audit = MigrationService::auditDirectory(__DIR__ . '/../../mysql/migrations');
$risks = array_values(array_filter($audit['nonIdempotentRisks'], function ($risk) {
    return $risk['filename'] === '061_retire_acrl_statistics.sql';
}));
acrlMigrationAssert($sql !== '', 'Migration 061 must be present.');
acrlMigrationAssert($risks === [], 'Migration 061 must satisfy the repository migration audit.');
acrlMigrationAssert(strpos($sql, 'PREPARE acrl_retire_stmt') !== false, 'Migration 061 must use a prepared guarded rename.');
acrlMigrationAssert(strpos($sql, 'acrl_retire_conflict_both_tables_exist') !== false, 'Migration 061 must fail loudly if both names exist.');
acrlMigrationAssert(strpos($sql, 'UPDATE report_templates SET is_active = 0') !== false, 'Migration 061 must deactivate the retired report.');
acrlMigrationAssert(strpos($sql, 'DROP TABLE') === false, 'Migration 061 must preserve the historical rows.');

fwrite(STDOUT, "ACRL retirement migration test passed (SQL not executed)\n");
