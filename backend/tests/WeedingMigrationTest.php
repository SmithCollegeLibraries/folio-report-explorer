<?php

require_once __DIR__ . '/../services/WeedingMigrationRecognitionService.php';
require_once __DIR__ . '/../services/MigrationService.php';

function weedingMigrationAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../config/weeding.php';
$listKeys = [
    'lc_call_number_type_ids',
    'included_material_type_ids',
    'excluded_mode_of_issuance_ids',
    'excluded_location_codes',
    'excluded_item_statuses',
    'five_college_symbols',
    'east_retention_partners',
    'east_symbol_aliases',
];
foreach ($listKeys as $key) {
    weedingMigrationAssert(isset($config[$key]) && is_array($config[$key]) && count($config[$key]) > 0, "Config list {$key} must not be empty.");
}
foreach ([
    'legacy_circ_note_type_id' => 'f765f19f-9f1c-4688-8c79-ec366a730842',
    'folio_circ_start' => '2022-07-01',
    'oclc_identifier_type_id' => '439bfbae-75bc-4f74-9fc7-b2a2d47ce3ef',
    'exclude_multivolume' => true,
    'cache_days' => 183,
    'daily_call_cap' => 125000,
    'monthly_call_cap' => 500000,
    'provider_reserve_ratio' => 0.10,
    'inches_per_item' => 1.0,
    'smith_symbol' => 'SNN',
    'ma_state_code' => 'US-MA',
] as $key => $expected) {
    weedingMigrationAssert(isset($config[$key]) && $config[$key] === $expected, "Config value {$key} must match the WED-001 probe disposition.");
}
foreach ([
    'lc_call_number_type_ids' => ['95467209-6d7b-468b-94df-0f5d7ad2747d', '6aa19546-a392-42a3-989f-561bd68966f8'],
    'included_material_type_ids' => ['2d72aa13-2451-41fe-afc7-b3dc7c131389', 'aaac2bef-8bc1-4f60-8a39-c354eb5032d9'],
    'excluded_mode_of_issuance_ids' => ['926ff973-ee50-4fb6-9e59-80947f5aca69', '4f53775c-8a4b-4176-9989-0cbcd18a59a5'],
    'five_college_symbols' => ['AMH', 'MTH', 'HAM', 'AUM'],
    'east_symbol_aliases' => ['FLARE' => 'FLARE (Florida Shared Print Storage)'],
] as $key => $expected) {
    weedingMigrationAssert($config[$key] === $expected, "Config list {$key} must match the WED-001 probe disposition.");
}

$emptyDb = new class {
    public function createCommand($sql, $params = [])
    {
        return new class {
            public function queryAll() { return []; }
            public function queryOne() { return false; }
            public function queryScalar() { return 0; }
        };
    }
};
weedingMigrationAssert(
    !app\services\WeedingMigrationRecognitionService::schemaAppearsComplete($emptyDb),
    'A database without the weeding schema must not be recognized as migration 075.'
);
$metadataDb = new class {
    public $columnRows = [];
    public $indexRows = [];
    public function createCommand($sql, $params = [])
    {
        $rows = stripos($sql, 'information_schema.COLUMNS') !== false ? $this->columnRows : $this->indexRows;
        return new class($rows) {
            private $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function queryAll() { return $this->rows; }
        };
    }
};
$metadataDb->columnRows = [
    ['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int(10) unsigned', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => 'NULL', 'EXTRA' => 'auto_increment'],
    ['COLUMN_NAME' => 'weeding_access', 'COLUMN_TYPE' => 'tinyint(1)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => '0', 'EXTRA' => ''],
];
$columnCheck = new ReflectionMethod(app\services\WeedingMigrationRecognitionService::class, 'columnsAppearComplete');
$indexCheck = new ReflectionMethod(app\services\WeedingMigrationRecognitionService::class, 'indexesAppearComplete');
if (PHP_VERSION_ID < 80500) {
    $columnCheck->setAccessible(true);
    $indexCheck->setAccessible(true);
}
$expectedColumns = [
    'id' => ['int unsigned', 'NO', null, 'auto_increment'],
    'weeding_access' => ['tinyint(1)', 'NO', '0', ''],
];
weedingMigrationAssert(
    $columnCheck->invoke(null, $metadataDb, 'users', $expectedColumns),
    'MariaDB integer widths and string NULL defaults must match the expected schema while tinyint(1) stays distinct.'
);
$metadataDb->columnRows[0]['COLUMN_TYPE'] = 'bigint(20) unsigned';
weedingMigrationAssert(!$columnCheck->invoke(null, $metadataDb, 'users', $expectedColumns), 'A wrong integer type must not be recognized.');
$metadataDb->columnRows[0]['COLUMN_TYPE'] = 'int(10) unsigned';
$metadataDb->columnRows[0]['IS_NULLABLE'] = 'YES';
weedingMigrationAssert(!$columnCheck->invoke(null, $metadataDb, 'users', $expectedColumns), 'Wrong nullability must not be recognized.');
$metadataDb->columnRows[0]['IS_NULLABLE'] = 'NO';
$metadataDb->columnRows[1]['COLUMN_DEFAULT'] = '1';
weedingMigrationAssert(!$columnCheck->invoke(null, $metadataDb, 'users', $expectedColumns), 'A wrong default must not be recognized.');
$metadataDb->indexRows = [['NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'id', 'SUB_PART' => null]];
weedingMigrationAssert(
    $indexCheck->invoke(null, $metadataDb, 'users', ['PRIMARY' => [['id'], true]])
        && !$indexCheck->invoke(null, $metadataDb, 'users', ['PRIMARY' => [['other'], true]]),
    'A missing or wrong key must not be recognized.'
);
$postMigrationDb = new class {
    public function createCommand($sql = null, $params = [])
    {
        return new class {
            public function setRawSql($sql) { return $this; }
            public function execute() { return 0; }
            public function queryAll() { return []; }
            public function queryOne() { return false; }
            public function queryScalar() { return 0; }
        };
    }
};
$emptyMigration = tempnam(sys_get_temp_dir(), 'wed001-empty-');
file_put_contents($emptyMigration, "SELECT 1;\n");
$incompleteInstallRejected = false;
try {
    app\services\MigrationService::executeMigrationFile($postMigrationDb, [
        'filename' => '075_weeding.sql', 'path' => $emptyMigration,
    ]);
} catch (RuntimeException $error) {
    $incompleteInstallRejected = strpos($error->getMessage(), 'Migration 075') !== false;
}
unlink($emptyMigration);
weedingMigrationAssert($incompleteInstallRejected, 'Migration 075 must be validated after its SQL executes.');

echo "PASS: Weeding config and migration recognition checks passed\n";

$engineConfigs = [
    'MySQL' => [
        'dsn' => getenv('WED001_MIGRATION_TEST_MYSQL_ADMIN_DSN'),
        'user' => getenv('WED001_MIGRATION_TEST_MYSQL_ADMIN_USER'),
        'password' => getenv('WED001_MIGRATION_TEST_MYSQL_ADMIN_PASSWORD'),
    ],
    'MariaDB 10.3' => [
        'dsn' => getenv('WED001_MIGRATION_TEST_MARIADB_ADMIN_DSN'),
        'user' => getenv('WED001_MIGRATION_TEST_MARIADB_ADMIN_USER'),
        'password' => getenv('WED001_MIGRATION_TEST_MARIADB_ADMIN_PASSWORD'),
    ],
];
$configuredEngineConfigs = [];
$unconfiguredEngines = [];
foreach ($engineConfigs as $label => $config) {
    $configuredValues = 0;
    foreach ($config as $value) {
        if ($value !== false && $value !== '') {
            $configuredValues++;
        }
    }
    if ($configuredValues === 0) {
        $unconfiguredEngines[] = $label;
    } elseif ($configuredValues !== count($config)) {
        fwrite(STDERR, "FAIL: {$label} is partially configured; provide its disposable admin connection.\n");
        exit(1);
    } else {
        $configuredEngineConfigs[$label] = $config;
    }
}
foreach ($unconfiguredEngines as $label) {
    fwrite(STDOUT, "SKIP: WED-001 {$label} engine checks need a disposable admin connection.\n");
}
if (empty($configuredEngineConfigs)) {
    exit(0);
}
if (getenv('WED001_MIGRATION_TEST_ALLOW_SCHEMA_CREATE') !== '1') {
    fwrite(STDERR, "FAIL: Set WED001_MIGRATION_TEST_ALLOW_SCHEMA_CREATE=1 to authorize disposable schema creation.\n");
    exit(1);
}
foreach ($configuredEngineConfigs as $label => $config) {
    foreach ($config as $key => $value) {
        if ($value === false || $value === '') {
            fwrite(STDERR, "FAIL: {$label} is partially configured; provide its disposable admin connection.\n");
            exit(1);
        }
    }
    if (preg_match('/(?:^|;)dbname=/i', (string)$config['dsn']) === 1) {
        fwrite(STDERR, "FAIL: {$label} admin DSN must omit dbname so the test can create disposable schemas.\n");
        exit(1);
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

function weedingEngineConnection(string $dsn, string $user, string $password): yii\db\Connection
{
    $db = new yii\db\Connection(['dsn' => $dsn, 'username' => $user, 'password' => $password, 'charset' => 'utf8mb4']);
    $db->open();
    return $db;
}

function weedingEngineDsn(string $dsn, string $schema): string
{
    return rtrim($dsn, ';') . ';dbname=' . $schema;
}

function weedingEngineCreateSchema($admin, string $schema, string $user, string $password, bool $allowAlter = true): void
{
    $admin->createCommand('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4')->execute();
    $admin->createCommand("CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'")->execute();
    $privileges = 'CREATE, SELECT, INSERT, UPDATE, DELETE, REFERENCES' . ($allowAlter ? ', ALTER' : '');
    $admin->createCommand("GRANT {$privileges} ON `{$schema}`.* TO '{$user}'@'%'")->execute();
}

function weedingEngineCreateUsersTable($db): void
{
    $db->createCommand('CREATE TABLE users (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
}

function weedingEngineInstall($db, string $migrationDir): array
{
    return app\services\MigrationService::run($db, $migrationDir, false);
}

function weedingEngineMigrationAudit(string $dsn, string $schema, string $user, string $password): void
{
    $host = 'localhost';
    $port = '3306';
    $socket = null;
    if (preg_match('/(?:^|[;:])host=([^;]+)/i', $dsn, $matches) === 1) $host = $matches[1];
    if (preg_match('/(?:^|[;:])port=([^;]+)/i', $dsn, $matches) === 1) $port = $matches[1];
    if (preg_match('/(?:^|[;:])unix_socket=([^;]+)/i', $dsn, $matches) === 1) $socket = $matches[1];
    $command = escapeshellarg(PHP_BINARY);
    if ($socket !== null) $command .= ' -d ' . escapeshellarg('pdo_mysql.default_socket=' . $socket);
    $command .= ' ' . escapeshellarg(__DIR__ . '/../yii') . ' migration/audit';
    $inheritedEnvironment = getenv();
    $environment = array_merge(is_array($inheritedEnvironment) ? $inheritedEnvironment : [], [
        'MYSQL_HOST' => $host, 'MYSQL_PORT' => $port, 'MYSQL_DATABASE' => $schema,
        'MYSQL_USER' => $user, 'MYSQL_PASSWORD' => $password,
    ]);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start migration/audit against the throwaway schema.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || strpos($stdout, 'Duplicate numbers: 0') === false
        || strpos($stdout, 'Changed checksums: 0') === false || strpos($stdout, 'Risk 075_weeding.sql:') !== false) {
        throw new RuntimeException('migration/audit failed on the throwaway schema (exit ' . $exitCode . ').'
            . "\nstdout:\n" . trim($stdout) . "\nstderr:\n" . trim($stderr));
    }
}

function weedingEngineAssert($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$migrationPath = __DIR__ . '/../../mysql/migrations/075_weeding.sql';
$temporaryMigrationDir = sys_get_temp_dir() . '/weeding075-migrations-' . bin2hex(random_bytes(8));
mkdir($temporaryMigrationDir, 0700, true);
copy($migrationPath, $temporaryMigrationDir . '/075_weeding.sql');
$engineFailures = [];
foreach ($configuredEngineConfigs as $label => $config) {
    $admin = null;
    $restricted = null;
    $engineSchemas = [];
    $engineUsers = [];
    try {
        $admin = weedingEngineConnection((string)$config['dsn'], (string)$config['user'], (string)$config['password']);
        $schema = 'wed001_' . bin2hex(random_bytes(6));
        $user = 'wed001_' . bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        $engineSchemas[] = $schema;
        $engineUsers[] = $user;
        weedingEngineCreateSchema($admin, $schema, $user, $password);
        $restricted = weedingEngineConnection(weedingEngineDsn((string)$config['dsn'], $schema), $user, $password);
        weedingEngineCreateUsersTable($restricted);
        weedingEngineMigrationAudit((string)$config['dsn'], $schema, $user, $password);
        $installed = weedingEngineInstall($restricted, $temporaryMigrationDir);
        weedingEngineAssert($installed['applied'] === ['075_weeding.sql'], $label . ' applies 075 to a fresh empty-ledger schema.');
        weedingEngineAssert(app\services\WeedingMigrationRecognitionService::schemaAppearsComplete($restricted), $label . ' recognizes the complete 075 schema.');
        $migrationRecognition = new ReflectionMethod(app\services\MigrationService::class, 'migrationAppearsApplied');
        if (PHP_VERSION_ID < 80500) $migrationRecognition->setAccessible(true);
        weedingEngineAssert($migrationRecognition->invoke(null, $restricted, '075_weeding.sql'), $label . ' routes 075 recognition through MigrationService.');

        $restricted->createCommand('INSERT INTO users (id, weeding_access) VALUES (1, 1)')->execute();
        app\services\MigrationService::executeMigrationFile($restricted, [
            'filename' => '075_weeding.sql', 'path' => $migrationPath,
        ]);
        weedingEngineAssert((int)$restricted->createCommand('SELECT weeding_access FROM users WHERE id = 1')->queryScalar() === 1, $label . ' rerunning 075 preserves existing weeding_access grants.');
        $restricted->createCommand("DELETE FROM schema_migrations WHERE filename = '075_weeding.sql'")->execute();
        $baseline = app\services\WeedingMigrationRecognitionService::baselineCurrentSchema($restricted, [[
            'filename' => '075_weeding.sql', 'path' => $migrationPath, 'checksum' => hash_file('sha256', $migrationPath),
        ]], false);
        weedingEngineAssert($baseline['baselined'] === ['075_weeding.sql']
            && (int)$restricted->createCommand('SELECT weeding_access FROM users WHERE id = 1')->queryScalar() === 1, $label . ' baselines a complete empty-ledger schema without resetting access grants.');

        $restricted->createCommand()->insert('weeding_areas', [
            'id' => 1, 'name' => 'Synthetic area', 'created_by' => 1, 'created_at' => '2026-01-01 00:00:00',
        ])->execute();
        $restricted->createCommand()->insert('weeding_area_titles', [
            'area_id' => 1, 'instance_id' => '11111111-1111-4111-8111-111111111111', 'instance_hrid' => 'in000001',
            'title' => 'Synthetic title', 'smith_items' => 1, 'area_items' => 1, 'total_checkouts' => 0,
        ])->execute();
        $restricted->createCommand()->insert('weeding_area_items', [
            'area_id' => 1, 'instance_id' => '11111111-1111-4111-8111-111111111111',
            'item_id' => '22222222-2222-4222-8222-222222222222', 'item_hrid' => 'it000001',
            'location_code' => 'SXSTK', 'status' => 'Available',
        ])->execute();
        $restricted->createCommand()->insert('weeding_area_title_checkouts', [
            'area_id' => 1, 'instance_id' => '11111111-1111-4111-8111-111111111111', 'year' => 2023, 'checkouts' => 1,
        ])->execute();
        $restricted->createCommand()->insert('weeding_scenarios', [
            'area_id' => 1, 'name' => 'Synthetic scenario', 'criteria' => '{}', 'created_by' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ])->execute();
        $restricted->createCommand()->insert('weeding_worldcat_cache', [
            'oclc_number' => 123456, 'status' => 'ok', 'fetched_at' => '2026-01-01 00:00:00',
        ])->execute();
        $duplicateScenarioFailed = false;
        try {
            $restricted->createCommand()->insert('weeding_scenarios', [
                'area_id' => 1, 'name' => 'Synthetic scenario', 'criteria' => '{}',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ])->execute();
        } catch (Throwable $error) {
            $duplicateScenarioFailed = true;
        }
        weedingEngineAssert($duplicateScenarioFailed, $label . ' rejects a duplicate scenario name within an area.');
        $restricted->createCommand('DELETE FROM users WHERE id = 1')->execute();
        weedingEngineAssert((int)$restricted->createCommand('SELECT COUNT(*) FROM weeding_areas WHERE created_by IS NULL')->queryScalar() === 1
            && (int)$restricted->createCommand('SELECT COUNT(*) FROM weeding_scenarios WHERE created_by IS NULL')->queryScalar() === 1, $label . ' nulls creator references after user deletion.');
        $restricted->createCommand('DELETE FROM weeding_areas WHERE id = 1')->execute();
        foreach (['weeding_area_titles', 'weeding_area_items', 'weeding_area_title_checkouts', 'weeding_scenarios'] as $table) {
            weedingEngineAssert((int)$restricted->createCommand('SELECT COUNT(*) FROM ' . $table)->queryScalar() === 0, $label . ' cascades area deletion to ' . $table . '.');
        }
        weedingEngineAssert((int)$restricted->createCommand('SELECT COUNT(*) FROM weeding_worldcat_cache')->queryScalar() === 1, $label . ' leaves the shared WorldCat cache intact after area deletion.');

        $partialSchema = 'wed001_' . bin2hex(random_bytes(6));
        $partialUser = 'wed001_' . bin2hex(random_bytes(4));
        $partialPassword = bin2hex(random_bytes(16));
        $engineSchemas[] = $partialSchema;
        $engineUsers[] = $partialUser;
        weedingEngineCreateSchema($admin, $partialSchema, $partialUser, $partialPassword);
        $partial = weedingEngineConnection(weedingEngineDsn((string)$config['dsn'], $partialSchema), $partialUser, $partialPassword);
        weedingEngineCreateUsersTable($partial);
        $partial->createCommand(app\services\MigrationService::splitSqlStatements((string)file_get_contents($migrationPath))[0])->execute();
        $partialRun = weedingEngineInstall($partial, $temporaryMigrationDir);
        weedingEngineAssert($partialRun['applied'] === ['075_weeding.sql']
            && app\services\WeedingMigrationRecognitionService::schemaAppearsComplete($partial), $label . ' resumes a partial schema with an empty ledger.');
        $partial->close();

        $populatedSchema = 'wed001_' . bin2hex(random_bytes(6));
        $populatedUser = 'wed001_' . bin2hex(random_bytes(4));
        $populatedPassword = bin2hex(random_bytes(16));
        $engineSchemas[] = $populatedSchema;
        $engineUsers[] = $populatedUser;
        weedingEngineCreateSchema($admin, $populatedSchema, $populatedUser, $populatedPassword);
        $populated = weedingEngineConnection(weedingEngineDsn((string)$config['dsn'], $populatedSchema), $populatedUser, $populatedPassword);
        weedingEngineCreateUsersTable($populated);
        app\services\MigrationService::ensureSchemaTable($populated);
        $populated->createCommand()->insert('schema_migrations', [
            'filename' => '001_previous.sql', 'checksum' => str_repeat('0', 64), 'execution_ms' => 0,
        ])->execute();
        $populatedRun = weedingEngineInstall($populated, $temporaryMigrationDir);
        weedingEngineAssert($populatedRun['applied'] === ['075_weeding.sql']
            && app\services\WeedingMigrationRecognitionService::schemaAppearsComplete($populated), $label . ' installs a partial schema with a populated ledger.');
        $populated->close();

        $partialPopulatedSchema = 'wed001_' . bin2hex(random_bytes(6));
        $partialPopulatedUser = 'wed001_' . bin2hex(random_bytes(4));
        $partialPopulatedPassword = bin2hex(random_bytes(16));
        $engineSchemas[] = $partialPopulatedSchema;
        $engineUsers[] = $partialPopulatedUser;
        weedingEngineCreateSchema($admin, $partialPopulatedSchema, $partialPopulatedUser, $partialPopulatedPassword);
        $partialPopulated = weedingEngineConnection(weedingEngineDsn((string)$config['dsn'], $partialPopulatedSchema), $partialPopulatedUser, $partialPopulatedPassword);
        weedingEngineCreateUsersTable($partialPopulated);
        app\services\MigrationService::ensureSchemaTable($partialPopulated);
        $partialPopulated->createCommand()->insert('schema_migrations', [
            'filename' => '001_previous.sql', 'checksum' => str_repeat('0', 64), 'execution_ms' => 0,
        ])->execute();
        $partialPopulated->createCommand(app\services\MigrationService::splitSqlStatements((string)file_get_contents($migrationPath))[0])->execute();
        $partialPopulatedRun = weedingEngineInstall($partialPopulated, $temporaryMigrationDir);
        weedingEngineAssert($partialPopulatedRun['applied'] === ['075_weeding.sql']
            && app\services\WeedingMigrationRecognitionService::schemaAppearsComplete($partialPopulated), $label . ' resumes a partial schema with a populated ledger.');
        $partialPopulated->close();

        $failedSchema = 'wed001_' . bin2hex(random_bytes(6));
        $failedUser = 'wed001_' . bin2hex(random_bytes(4));
        $failedPassword = bin2hex(random_bytes(16));
        $engineSchemas[] = $failedSchema;
        $engineUsers[] = $failedUser;
        weedingEngineCreateSchema($admin, $failedSchema, $failedUser, $failedPassword, false);
        $failed = weedingEngineConnection(weedingEngineDsn((string)$config['dsn'], $failedSchema), $failedUser, $failedPassword);
        weedingEngineCreateUsersTable($failed);
        $installFailed = false;
        try {
            weedingEngineInstall($failed, $temporaryMigrationDir);
        } catch (Throwable $error) {
            $installFailed = true;
        }
        weedingEngineAssert($installFailed && (int)$failed->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '075_weeding.sql'")->queryScalar() === 0, $label . ' does not ledger an unsuccessful install.');
        $failed->close();
        $restricted->close();
    } catch (Throwable $error) {
        $engineFailures[] = $label . ': ' . get_class($error) . ': ' . $error->getMessage();
        fwrite(STDERR, 'FAIL: ' . end($engineFailures) . "\n");
        if ($restricted !== null) $restricted->close();
    } finally {
        if ($admin !== null) {
            foreach ($engineUsers as $user) {
                try { $admin->createCommand("DROP USER IF EXISTS '" . $user . "'@'%'")->execute(); } catch (Throwable $ignored) {}
            }
            foreach ($engineSchemas as $schema) {
                try { $admin->createCommand('DROP DATABASE IF EXISTS `' . $schema . '`')->execute(); } catch (Throwable $ignored) {}
            }
            $admin->close();
        }
    }
}
unlink($temporaryMigrationDir . '/075_weeding.sql');
rmdir($temporaryMigrationDir);
if (!empty($engineFailures)) {
    exit(1);
}
fwrite(STDOUT, 'PASS: WED-001 migration engine checks passed on ' . implode(', ', array_keys($configuredEngineConfigs)) . ".\n");
