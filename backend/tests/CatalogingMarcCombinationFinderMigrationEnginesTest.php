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
    fwrite(STDOUT, "SKIP: RPT-026 migration engine checks need disposable MySQL 8 and MariaDB 10.3 admin connections.\n");
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
    $databaseName = 'rpt026_' . strtolower(str_replace([' ', '.'], '_', $label)) . '_' . strtolower(substr(bin2hex(random_bytes(8)), 0, 12));
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
            marcCombinationEngineCase($label . ' 068 installs from a ledger through 067', function () use ($adminDb, $migrationDir) {
                $before = (bool) marcCombinationEngineReflection('databaseAppearsCurrent', [$adminDb]);
                marcCombinationEngineSame(false, $before, 'A current schema missing report 068 must not enter the empty-ledger current-schema baseline branch.');
                $result = MigrationService::run($adminDb, $migrationDir, false);
                marcCombinationEngineCheck(in_array('068_marc_field_combination_finder.sql', $result['applied'], true), 'Install must execute migration 068.');
                marcCombinationEngineSame(1, (int) $adminDb->createCommand("SELECT COUNT(*) FROM report_templates WHERE slug = 'marc-field-combination-finder'")->queryScalar(), 'Install creates exactly one canonical report row.');
                marcCombinationEngineSame(true, (bool) marcCombinationEngineReflection('migrationAppearsApplied', [$adminDb, '068_marc_field_combination_finder.sql']), 'Installed migration 068 must be recognized.');
            });

            marcCombinationEngineCase($label . ' 068 reruns without duplicating or changing the seed', function () use ($adminDb, $migrationDir) {
                $before = (string) $adminDb->createCommand("SELECT name FROM report_templates WHERE slug = 'marc-field-combination-finder'")->queryScalar();
                $result = MigrationService::run($adminDb, $migrationDir, false);
                marcCombinationEngineCheck(!in_array('068_marc_field_combination_finder.sql', $result['applied'], true), 'A second run must not execute 068 again.');
                marcCombinationEngineSame($before, (string) $adminDb->createCommand("SELECT name FROM report_templates WHERE slug = 'marc-field-combination-finder'")->queryScalar(), 'Rerun leaves the canonical seed unchanged.');
                marcCombinationEngineSame(1, (int) $adminDb->createCommand("SELECT COUNT(*) FROM report_templates WHERE slug = 'marc-field-combination-finder'")->queryScalar(), 'Rerun leaves one report row.');
            });

            marcCombinationEngineCase($label . ' 068 drift is not overwritten or recognized', function () use ($adminDb, $migrationDir) {
                $adminDb->createCommand("UPDATE report_templates SET name = 'Drifted synthetic definition' WHERE slug = 'marc-field-combination-finder'")->execute();
                $adminDb->createCommand("DELETE FROM schema_migrations WHERE filename = '068_marc_field_combination_finder.sql'")->execute();
                $error = null;
                try {
                    MigrationService::run($adminDb, $migrationDir, false);
                } catch (Throwable $caught) {
                    $error = $caught;
                }
                marcCombinationEngineCheck($error !== null, 'A guarded insert must not treat a drifted same-slug row as successful recognition.');
                if ($error !== null) {
                    marcCombinationEngineCheck(stripos($error->getMessage(), 'marc-field-combination-finder') !== false || stripos($error->getMessage(), '068') !== false, 'The drift error must identify migration 068 or its report slug.');
                }
                marcCombinationEngineSame('Drifted synthetic definition', (string) $adminDb->createCommand("SELECT name FROM report_templates WHERE slug = 'marc-field-combination-finder'")->queryScalar(), 'The guarded insert must not overwrite the pre-existing row.');
                marcCombinationEngineSame(false, (bool) marcCombinationEngineReflection('migrationAppearsApplied', [$adminDb, '068_marc_field_combination_finder.sql']), 'The drifted row must not be recognized as applied.');
                marcCombinationEngineSame(0, (int) $adminDb->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '068_marc_field_combination_finder.sql'")->queryScalar(), 'Failed recognition must not record migration 068.');
            });

            marcCombinationEngineCase($label . ' empty-ledger current-schema run enters the baseline branch for 068', function () use ($adminDb, $migrationDir) {
                $adminDb->createCommand("UPDATE report_templates SET name = 'MARC Field Combination Finder' WHERE slug = 'marc-field-combination-finder'")->execute();
                marcCombinationEngineSame(true, (bool) marcCombinationEngineReflection('databaseAppearsCurrent', [$adminDb]), 'The full production-shaped database plus canonical 068 seed satisfies the current-schema prerequisite.');
                $adminDb->createCommand('DELETE FROM schema_migrations')->execute();
                marcCombinationEngineSame(0, (int) $adminDb->createCommand('SELECT COUNT(*) FROM schema_migrations')->queryScalar(), 'The baseline scenario begins with a provably empty ledger.');
                marcCombinationEngineSame(true, (bool) marcCombinationEngineReflection('databaseAppearsCurrent', [$adminDb]), 'The actual empty-ledger state still passes databaseAppearsCurrent().');
                $result = MigrationService::run($adminDb, $migrationDir, false);
                marcCombinationEngineCheck(in_array('068_marc_field_combination_finder.sql', $result['baselined'], true), 'The baseline branch must baseline 068 from its canonical seed.');
                marcCombinationEngineCheck(!in_array('068_marc_field_combination_finder.sql', $result['applied'], true), 'The empty-ledger baseline must not execute 068.');
                marcCombinationEngineSame(1, (int) $adminDb->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '068_marc_field_combination_finder.sql'")->queryScalar(), 'The empty-ledger baseline records migration 068 exactly once.');
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
    fwrite(STDERR, 'FAIL: ' . count($failures) . " RPT-026 migration engine checks failed.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: RPT-026 migration 068 install, rerun, drift, and baseline checks passed on MySQL 8 and MariaDB 10.3.\n");
