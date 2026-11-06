<?php

$engineConfigs = [
    'MySQL 8' => [
        'dsn' => getenv('RPT026_MIGRATION_TEST_MYSQL_ADMIN_DSN'),
        'user' => getenv('RPT026_MIGRATION_TEST_MYSQL_ADMIN_USER'),
        'password' => getenv('RPT026_MIGRATION_TEST_MYSQL_ADMIN_PASSWORD'),
        'expected' => 'mysql',
    ],
    'MariaDB 10.3' => [
        'dsn' => getenv('RPT026_MIGRATION_TEST_MARIADB_ADMIN_DSN'),
        'user' => getenv('RPT026_MIGRATION_TEST_MARIADB_ADMIN_USER'),
        'password' => getenv('RPT026_MIGRATION_TEST_MARIADB_ADMIN_PASSWORD'),
        'expected' => 'mariadb',
    ],
];
$configured = false;
foreach ($engineConfigs as $config) {
    foreach (['dsn', 'user', 'password'] as $key) {
        if ($config[$key] !== false && $config[$key] !== '') {
            $configured = true;
        }
    }
}
if (!$configured) {
    fwrite(STDOUT, "SKIP: RPT-028 migration engine checks need disposable MySQL 8 and MariaDB 10.3 admin connections.\n");
    exit(0);
}
if (getenv('RPT026_MIGRATION_TEST_ALLOW_SCHEMA_CREATE') !== '1') {
    fwrite(STDERR, "FAIL: Set RPT026_MIGRATION_TEST_ALLOW_SCHEMA_CREATE=1 to authorize disposable schema creation.\n");
    exit(1);
}
foreach ($engineConfigs as $label => $config) {
    foreach (['dsn', 'user', 'password'] as $key) {
        if ($config[$key] === false) {
            fwrite(STDERR, "FAIL: {$label} is partially configured; provide its admin DSN, user, and password.\n");
            exit(1);
        }
    }
    if (preg_match('/(?:^|;)dbname=/i', (string) $config['dsn']) === 1) {
        fwrite(STDERR, "FAIL: {$label} admin DSN must omit dbname so the test can create a disposable schema.\n");
        exit(1);
    }
}

$autoload = __DIR__ . '/../vendor/autoload.php';
$yii = __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
if (!is_file($autoload) || !is_file($yii)) {
    fwrite(STDERR, "FAIL: Install the existing backend Composer dependencies before running the DB engine test.\n");
    exit(1);
}
require_once $autoload;
require_once $yii;
require_once __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;
use yii\db\Connection;

$failures = [];
$migrationDir = __DIR__ . '/../../mysql/migrations';
$initSql = __DIR__ . '/../../mysql/init.sql';

function marcCombinationEngineCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function marcCombinationEngineSame($expected, $actual, string $message): void
{
    marcCombinationEngineCheck($expected === $actual, $message
        . "\nExpected: " . var_export($expected, true)
        . "\nActual: " . var_export($actual, true));
}

function marcCombinationEngineReport(Connection $db): array
{
    return $db->createCommand("SELECT * FROM report_templates WHERE slug = 'marc-field-combination-finder'")->queryOne();
}

function marcCombinationEngineRestoreReport(Connection $db, array $row): void
{
    unset($row['id']);
    $db->createCommand()->update('report_templates', $row, ['slug' => 'marc-field-combination-finder'])->execute();
}

function marcCombinationEngineConnection(string $dsn, string $username, string $password): Connection
{
    $db = new Connection(['dsn' => $dsn, 'username' => $username, 'password' => $password, 'charset' => 'utf8mb4']);
    $db->open();
    return $db;
}

function marcCombinationEngineReflection(string $methodName, array $arguments)
{
    $method = new ReflectionMethod(MigrationService::class, $methodName);
    if (PHP_VERSION_ID < 80500) {
        $method->setAccessible(true);
    }
    return $method->invokeArgs(null, $arguments);
}

function marcCombinationEnginePrepareSchema(Connection $adminDb, string $migrationDir, string $initSql, string $label): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rpt026-migrations-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0700, true)) {
        throw new RuntimeException('Could not create a temporary migration directory.');
    }
    foreach (glob(rtrim($migrationDir, '/') . '/*.sql') ?: [] as $source) {
        if (preg_match('/^(\d+)_/', basename($source), $match) !== 1 || (int) $match[1] > 67) {
            continue;
        }
        if (!copy($source, $path . DIRECTORY_SEPARATOR . basename($source))) {
            throw new RuntimeException('Could not copy the pre-068 migration set.');
        }
    }
    marcCombinationEngineReflection('executeSqlFile', [$adminDb, $initSql]);
    $setup = MigrationService::run($adminDb, $path, false);
    $expectedLast = '067_acrl_invites.sql';
    $installed = in_array($expectedLast, $setup['applied'], true) || in_array($expectedLast, $setup['baselined'], true);
    if (!$installed) {
        throw new RuntimeException($label . ' setup did not reach the pre-068 migration state.');
    }
    $adminDb->createCommand()->insert('report_templates', [
        'id' => 11,
        'slug' => 'pol-report',
        'name' => 'Synthetic purchase order line report',
        'description' => 'Synthetic fixture for current-schema recognition.',
        'category' => 'acquisitions',
        'sql_template' => 'SELECT 1',
        'parameters' => '[]',
        'data_source' => 'folio',
        'is_active' => 0,
        'created_by' => 'manual',
    ])->execute();
    return $path;
}

function marcCombinationEngineCase(string $name, callable $callback): void
{
    try {
        $callback();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        marcCombinationEngineCheck(false, $name . ': ' . get_class($error) . ': ' . $error->getMessage());
    }
}

function marcCombinationEngineRun(string $label, array $config, string $migrationDir, string $initSql): void
{
    global $failures;
    $admin = null;
    $databaseName = 'rpt028_' . strtolower(str_replace([' ', '.'], '_', $label)) . '_' . strtolower(substr(bin2hex(random_bytes(8)), 0, 12));
    $dbDsn = rtrim((string) $config['dsn'], ';') . ';dbname=' . $databaseName;
    $temporaryMigrationDir = null;

    try {
        $admin = new PDO((string) $config['dsn'], (string) $config['user'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
        $isMariaDb = stripos($version, 'mariadb') !== false;
        marcCombinationEngineCheck(
            $config['expected'] === 'mariadb'
                ? ($isMariaDb && preg_match('/^10\.3\./', $version) === 1)
                : (!$isMariaDb && preg_match('/^8\./', $version) === 1),
            $label . ' endpoint must be the requested engine version; received ' . $version . '.'
        );

        $admin->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $adminDb = marcCombinationEngineConnection($dbDsn, (string) $config['user'], (string) $config['password']);

        try {
            $temporaryMigrationDir = marcCombinationEnginePrepareSchema($adminDb, $migrationDir, $initSql, $label);
            $legacyDefinition = [];
            $migration068 = rtrim($migrationDir, '/') . '/068_marc_field_combination_finder.sql';
            if (!copy($migration068, $temporaryMigrationDir . DIRECTORY_SEPARATOR . basename($migration068))) {
                throw new RuntimeException('Could not add migration 068 to the disposable pre-069 directory.');
            }
            marcCombinationEngineCase($label . ' 068 installs from a ledger through 067', function () use ($adminDb, $temporaryMigrationDir, &$legacyDefinition) {
                $result = MigrationService::run($adminDb, $temporaryMigrationDir, false);
                marcCombinationEngineCheck(in_array('068_marc_field_combination_finder.sql', $result['applied'], true), 'Install must execute migration 068.');
                $legacyDefinition = marcCombinationEngineReport($adminDb);
                marcCombinationEngineSame(1, (int) $adminDb->createCommand("SELECT COUNT(*) FROM report_templates WHERE slug = 'marc-field-combination-finder'")->queryScalar(), 'Install creates exactly one canonical report row.');
                marcCombinationEngineSame(true, (bool) marcCombinationEngineReflection('migrationAppearsApplied', [$adminDb, '068_marc_field_combination_finder.sql']), 'Installed migration 068 must be recognized.');
            });

            marcCombinationEngineCase($label . ' 069 updates canonical 068 and is recorded', function () use ($adminDb, $migrationDir) {
                $result = MigrationService::run($adminDb, $migrationDir, false);
                marcCombinationEngineCheck(in_array('069_marc_combination_finder_record_scopes.sql', $result['applied'], true), 'Upgrade must execute migration 069.');
                marcCombinationEngineSame(1, (int) $adminDb->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '069_marc_combination_finder_record_scopes.sql'")->queryScalar(), 'Upgrade records migration 069 exactly once.');
                marcCombinationEngineSame(true, (bool) marcCombinationEngineReflection('migrationAppearsApplied', [$adminDb, '068_marc_field_combination_finder.sql']), 'Current 069 definition keeps migration 068 recognized.');
                marcCombinationEngineSame(true, (bool) marcCombinationEngineReflection('migrationAppearsApplied', [$adminDb, '069_marc_combination_finder_record_scopes.sql']), 'Installed migration 069 is recognized.');
                marcCombinationEngineSame(1, (int) $adminDb->createCommand("SELECT COUNT(*) FROM report_templates WHERE slug = 'marc-field-combination-finder'")->queryScalar(), 'Rerun leaves one report row.');
            });

            $canonicalDefinition = marcCombinationEngineReport($adminDb);
            foreach ([
                'parameters' => '[]', 'help_text' => 'Drifted help text', 'execution_config' => '{}',
                'name' => 'Drifted synthetic definition', 'sql_template' => $canonicalDefinition['sql_template'] . ' ',
            ] as $field => $changedValue) {
                marcCombinationEngineCase($label . ' 069 refuses altered ' . $field . ' without changing the row', function () use ($adminDb, $migrationDir, $canonicalDefinition, $field, $changedValue) {
                    marcCombinationEngineRestoreReport($adminDb, $canonicalDefinition);
                    $adminDb->createCommand()->update('report_templates', [$field => $changedValue], ['slug' => 'marc-field-combination-finder'])->execute();
                    $adminDb->createCommand("DELETE FROM schema_migrations WHERE filename = '069_marc_combination_finder_record_scopes.sql'")->execute();
                    $before = marcCombinationEngineReport($adminDb);
                    $error = null;
                    try { MigrationService::run($adminDb, $migrationDir, false); } catch (Throwable $caught) { $error = $caught; }
                    marcCombinationEngineCheck($error !== null && strpos($error->getMessage(), '069') !== false && strpos($error->getMessage(), 'marc-field-combination-finder') !== false, 'Guard rejects drift and names migration 069 and its report.');
                    marcCombinationEngineSame($before, marcCombinationEngineReport($adminDb), 'Failed pre-execution guard leaves the entire row unchanged.');
                    marcCombinationEngineSame(false, (bool) marcCombinationEngineReflection('migrationAppearsApplied', [$adminDb, '069_marc_combination_finder_record_scopes.sql']), 'Drifted row is not recognized as migration 069.');
                    marcCombinationEngineSame(0, (int) $adminDb->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '069_marc_combination_finder_record_scopes.sql'")->queryScalar(), 'Failed guard does not record migration 069.');
                    marcCombinationEngineRestoreReport($adminDb, $canonicalDefinition);
                    $restored = MigrationService::run($adminDb, $migrationDir, false);
                    marcCombinationEngineCheck(in_array('069_marc_combination_finder_record_scopes.sql', $restored['baselined'], true), 'Restoring the canonical 069 definition makes it recognizable.');
                });
            }

            marcCombinationEngineCase($label . ' 069 reruns without changing its canonical definition', function () use ($adminDb, $migrationDir) {
                $before = marcCombinationEngineReport($adminDb);
                $result = MigrationService::run($adminDb, $migrationDir, false);
                marcCombinationEngineCheck(!in_array('069_marc_combination_finder_record_scopes.sql', $result['applied'], true), 'A second run must not execute 069 again.');
                marcCombinationEngineSame($before, marcCombinationEngineReport($adminDb), 'Rerun leaves the complete seed unchanged.');
                marcCombinationEngineSame(1, (int) $adminDb->createCommand("SELECT COUNT(*) FROM report_templates WHERE slug = 'marc-field-combination-finder'")->queryScalar(), 'Rerun leaves one report row.');
            });

            marcCombinationEngineCase($label . ' empty-ledger baseline upgrades canonical 068 and recognizes 069', function () use ($adminDb, $migrationDir, $legacyDefinition) {
                marcCombinationEngineRestoreReport($adminDb, $legacyDefinition);
                $adminDb->createCommand('DELETE FROM schema_migrations')->execute();
                marcCombinationEngineSame(true, (bool) marcCombinationEngineReflection('databaseAppearsCurrent', [$adminDb]), 'Canonical historical 068 row satisfies the empty-ledger prerequisite.');
                $result = MigrationService::run($adminDb, $migrationDir, false);
                marcCombinationEngineCheck(in_array('068_marc_field_combination_finder.sql', $result['baselined'], true), 'Baseline recognizes the historical 068 definition.');
                marcCombinationEngineCheck(in_array('069_marc_combination_finder_record_scopes.sql', $result['applied'], true), 'Baseline branch applies 069 after recognizing 068.');
                marcCombinationEngineSame(true, (bool) marcCombinationEngineReflection('migrationAppearsApplied', [$adminDb, '069_marc_combination_finder_record_scopes.sql']), 'The post-baseline 069 definition is recognized.');
            });

            marcCombinationEngineCase($label . ' empty-ledger baseline recognizes canonical 069 and rejects drift', function () use ($adminDb, $migrationDir) {
                $adminDb->createCommand('DELETE FROM schema_migrations')->execute();
                marcCombinationEngineSame(true, (bool) marcCombinationEngineReflection('databaseAppearsCurrent', [$adminDb]), 'Canonical 069 row satisfies the empty-ledger prerequisite.');
                $result = MigrationService::run($adminDb, $migrationDir, false);
                marcCombinationEngineCheck(in_array('068_marc_field_combination_finder.sql', $result['baselined'], true)
                    && in_array('069_marc_combination_finder_record_scopes.sql', $result['baselined'], true), 'Empty-ledger baseline records both canonical report definitions.');
                $adminDb->createCommand("UPDATE report_templates SET name = 'Drifted baseline definition' WHERE slug = 'marc-field-combination-finder'")->execute();
                $adminDb->createCommand('DELETE FROM schema_migrations')->execute();
                marcCombinationEngineSame(false, (bool) marcCombinationEngineReflection('databaseAppearsCurrent', [$adminDb]), 'A non-canonical report row fails the empty-ledger baseline prerequisite.');
                $dryRun = MigrationService::run($adminDb, $migrationDir, true);
                marcCombinationEngineCheck(!in_array('068_marc_field_combination_finder.sql', $dryRun['baselined'], true)
                    && !in_array('069_marc_combination_finder_record_scopes.sql', $dryRun['baselined'], true), 'A drifted report is not baseline-recognized as 068 or 069.');
                marcCombinationEngineSame(0, (int) $adminDb->createCommand('SELECT COUNT(*) FROM schema_migrations')->queryScalar(), 'Rejected baseline remains unrecorded.');
            });
        } catch (Throwable $error) {
            marcCombinationEngineCheck(false, $label . ' setup failed before migration scenarios: ' . get_class($error) . ': ' . $error->getMessage());
        }
    } catch (Throwable $error) {
        marcCombinationEngineCheck(false, $label . ' endpoint/setup failed: ' . get_class($error) . ': ' . $error->getMessage());
    } finally {
        if ($temporaryMigrationDir !== null && is_dir($temporaryMigrationDir)) {
            foreach (glob($temporaryMigrationDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($temporaryMigrationDir);
        }
        if ($admin !== null) {
            try {
                $admin->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
            } catch (Throwable $error) {
                marcCombinationEngineCheck(false, $label . ' cleanup could not drop its disposable schema: ' . $error->getMessage());
            }
        }
    }
}

foreach ($engineConfigs as $label => $config) {
    marcCombinationEngineRun($label, $config, $migrationDir, $initSql);
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " RPT-028 migration engine checks failed.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: RPT-028 migrations 068 and 069 install, rerun, drift, and baseline checks passed on MySQL 8 and MariaDB 10.3.\n");
