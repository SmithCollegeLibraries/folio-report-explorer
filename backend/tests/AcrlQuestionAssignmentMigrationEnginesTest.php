<?php

$adminDsn = getenv('ACRL_MIGRATION_TEST_ADMIN_DSN');
$adminUser = getenv('ACRL_MIGRATION_TEST_ADMIN_USER');
$adminPassword = getenv('ACRL_MIGRATION_TEST_ADMIN_PASSWORD');
$allowSchemaCreate = getenv('ACRL_MIGRATION_TEST_ALLOW_SCHEMA_CREATE');
if ($adminDsn === false && $adminUser === false && $adminPassword === false) {
    echo "SKIP: ACRL migration engine tests need an explicitly configured throwaway MySQL/MariaDB endpoint.\n";
    exit(0);
}
if ($adminDsn === false || $adminUser === false || $allowSchemaCreate !== '1') {
    fwrite(STDERR, "FAIL: Set ACRL_MIGRATION_TEST_ADMIN_DSN, ACRL_MIGRATION_TEST_ADMIN_USER, and ACRL_MIGRATION_TEST_ALLOW_SCHEMA_CREATE=1 for a throwaway DB container.\n");
    exit(1);
}
if (preg_match('/(?:^|;)dbname=/i', $adminDsn) === 1) {
    fwrite(STDERR, "FAIL: ACRL_MIGRATION_TEST_ADMIN_DSN must omit dbname; the test creates and drops random disposable schemas.\n");
    exit(1);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
$yii = __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
if (!file_exists($autoload) || !file_exists($yii)) {
    fwrite(STDERR, "FAIL: Install the existing backend Composer dependencies before the DB-engine tests.\n");
    exit(1);
}
require_once $autoload;
require_once $yii;
require_once __DIR__ . '/../services/MigrationService.php';
require_once __DIR__ . '/../services/AcrlSectionAssignmentRepository.php';
require_once __DIR__ . '/../services/AcrlSectionAssignmentService.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentRepository.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentService.php';
require_once __DIR__ . '/../services/AcrlInviteMailer.php';
require_once __DIR__ . '/../services/AcrlInviteService.php';

use app\services\MigrationService;
use app\models\User;
use app\services\AcrlInviteMailer;
use app\services\AcrlInviteService;
use app\services\AcrlQuestionAssignmentRepository;
use app\services\AcrlQuestionAssignmentService;
use app\services\AcrlSectionAssignmentRepository;
use app\services\AcrlSectionAssignmentService;
use yii\db\Connection;
use yii\web\Application;

$failures = [];
$createdDatabases = [];
$createdRuntimeUser = null;
$runtimePassword = bin2hex(random_bytes(20));
$runtimeUser = 'acrl029_' . strtolower(substr(bin2hex(random_bytes(8)), 0, 12));
$migrationDir = __DIR__ . '/../../mysql/migrations';
$temporaryMigrationDir = sys_get_temp_dir() . '/acrl029-migrations-' . bin2hex(random_bytes(8));
$allMigrationFiles = array_map('basename', glob(rtrim($migrationDir, '/') . '/*.sql') ?: []);
sort($allMigrationFiles, SORT_STRING);
$migrationFilesExcept066And067 = array_values(array_filter($allMigrationFiles, function ($filename) {
    return $filename !== '066_acrl_question_assignments.sql' && $filename !== '067_acrl_invites.sql';
}));

function acrlMigrationEngineCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function acrlMigrationEngineSame($expected, $actual, string $message): void
{
    acrlMigrationEngineCheck($expected === $actual, $message
        . "\nExpected: " . var_export($expected, true)
        . "\nActual: " . var_export($actual, true));
}

function acrlMigrationEngineIsLockWaitTimeout(Throwable $error): bool
{
    return stripos($error->getMessage(), 'lock wait timeout exceeded') !== false;
}

function acrlMigrationEngineRollbackActiveTransaction($db): void
{
    $transaction = $db->getTransaction();
    if ($transaction !== null && $transaction->getIsActive()) {
        $transaction->rollBack();
    }
}

function acrlMigrationEngineHasActiveTransaction($db): bool
{
    $transaction = $db->getTransaction();
    return $transaction !== null && $transaction->getIsActive();
}

function acrlMigrationEngineConnection(string $dsn, string $username, string $password): Connection
{
    $connection = new Connection(['dsn' => $dsn, 'username' => $username, 'password' => $password, 'charset' => 'utf8mb4']);
    $connection->open();
    return $connection;
}

function acrlMigrationEngineRaw($db, string $sql): void
{
    $db->createCommand()->setRawSql($sql)->execute();
}

function acrlMigrationEngineCreateMalformedAssignmentTable($db): void
{
    $db->createCommand("CREATE TABLE acrl_question_assignments (\n"
        . "    survey_year SMALLINT NOT NULL,\n"
        . "    sheet VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
        . "    question_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
        . "    part VARCHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',\n"
        . "    user_id INT NOT NULL,\n"
        . "    assigned_by INT NULL,\n"
        . "    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")->execute();
}

function acrlMigrationEngineCreateAssignmentTableWithoutTimestampDefault($db, string $migrationDir): void
{
    $sql = (string)file_get_contents($migrationDir . '/066_acrl_question_assignments.sql');
    $malformedSql = str_replace(
        'assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)',
        'assigned_at DATETIME(6) NOT NULL',
        $sql,
        $replacementCount
    );
    if ($replacementCount !== 1) {
        throw new RuntimeException('Setup could not remove only the assigned_at default from migration 066.');
    }
    acrlMigrationEngineRaw($db, rtrim(trim($malformedSql), ';'));
}

function acrlMigrationEngineCreateInviteTables($db, string $migrationDir): void
{
    $sql = (string)file_get_contents($migrationDir . '/067_acrl_invites.sql');
    foreach (preg_split('/;\\s*(?:\\r?\\n|$)/', trim($sql)) as $statement) {
        if (trim($statement) !== '') {
            acrlMigrationEngineRaw($db, trim($statement));
        }
    }
}

function acrlMigrationEngineRestoreAcrlSchemas($db, string $migrationDir): void
{
    $db->createCommand('DROP TABLE IF EXISTS acrl_invite_targets')->execute();
    $db->createCommand('DROP TABLE IF EXISTS acrl_invites')->execute();
    $db->createCommand('DROP TABLE IF EXISTS acrl_question_assignments')->execute();
    acrlMigrationEngineRaw($db, rtrim(trim((string)file_get_contents($migrationDir . '/066_acrl_question_assignments.sql')), ';'));
    acrlMigrationEngineCreateInviteTables($db, $migrationDir);
    $db->createCommand('DELETE FROM schema_migrations')->execute();
}

function acrlMigrationEngineCheckMalformedRecognition($label, $adminDb, $appDb, string $migrationDir, string $filename, callable $mutate): void
{
    try {
        $mutate($adminDb);
        acrlMigrationEngineCheck(!acrlMigrationEngineApplied($appDb, $filename), $label . ' is not recognized as an applied migration.');
        $error = null;
        try {
            MigrationService::run($appDb, $migrationDir, false);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        acrlMigrationEngineCheck($error !== null, $label . ' fails migration recognition during execution.');
        $recorded = (int)$appDb->createCommand('SELECT COUNT(*) FROM schema_migrations WHERE filename = :filename', [':filename' => $filename])->queryScalar();
        acrlMigrationEngineSame(0, $recorded, $label . ' is not recorded in schema_migrations.');
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, $label . ' test threw ' . get_class($error) . ': ' . $error->getMessage());
    } finally {
        try {
            acrlMigrationEngineRestoreAcrlSchemas($adminDb, $migrationDir);
        } catch (Throwable $restoreError) {
            acrlMigrationEngineCheck(false, $label . ' could not restore complete ACRL schemas for later checks: ' . $restoreError->getMessage());
        }
    }
}

function acrlMigrationEngineCreatePartialInviteSchema($db, string $migrationDir): void
{
    $sql = (string)file_get_contents($migrationDir . '/067_acrl_invites.sql');
    $malformedSql = str_replace('accepted_at DATETIME(6) NULL,', '', $sql, $replacementCount);
    if ($replacementCount !== 1) {
        throw new RuntimeException('Setup could not remove only the accepted_at column from migration 067.');
    }
    foreach (preg_split('/;\\s*(?:\\r?\\n|$)/', trim($malformedSql)) as $statement) {
        if (trim($statement) !== '') {
            acrlMigrationEngineRaw($db, trim($statement));
        }
    }
}

function acrlMigrationEngineMalformedMigrationError($db, string $path, string $filename, string $table): void
{
    $error = null;
    try {
        MigrationService::run($db, $path, false);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    acrlMigrationEngineCheck($error !== null && strpos($error->getMessage(), $table) !== false,
        'Executing ' . $filename . ' against a malformed existing table must throw an error naming ' . $table . '.');
    $recorded = (int)$db->createCommand('SELECT COUNT(*) FROM schema_migrations WHERE filename = :filename', [':filename' => $filename])->queryScalar();
    acrlMigrationEngineSame(0, $recorded, 'A malformed ' . $table . ' table must not record migration ' . $filename . '.');
}

function acrlMigrationEngineCheckVeaGateZoneInstall($db, string $migrationDir, string $label): void
{
    $first = MigrationService::run($db, $migrationDir, false);
    acrlMigrationEngineCheck(in_array('071_vea_gate_zones.sql', $first['applied'], true), $label . ' must apply missing 071.');
    acrlMigrationEngineCheck(acrlMigrationEngineApplied($db, '071_vea_gate_zones.sql'), $label . ' must recognize 071 after applying it.');
    $recorded = (int)$db->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '071_vea_gate_zones.sql'")->queryScalar();
    acrlMigrationEngineSame(1, $recorded, $label . ' must record 071 once.');
    $second = MigrationService::run($db, $migrationDir, false);
    acrlMigrationEngineCheck(!in_array('071_vea_gate_zones.sql', $second['applied'], true), $label . ' rerun must not apply 071 again.');
}

function acrlMigrationEngineCheckMalformedVeaGateZone($adminDb, $appDb, string $migrationDir, string $label): void
{
    $error = null;
    try {
        MigrationService::run($appDb, $migrationDir, false);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    acrlMigrationEngineCheck($error !== null && strpos($error->getMessage(), 'vea_gate_zones') !== false,
        $label . ' must reject a malformed vea_gate_zones table.');
    $recorded = (int)$adminDb->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '071_vea_gate_zones.sql'")->queryScalar();
    acrlMigrationEngineSame(0, $recorded, $label . ' must not record malformed migration 071.');
}

function acrlMigrationEnginePrepareCurrentSchema(PDO $admin, string $adminDsn, string $adminUser, string $adminPassword, string $databaseName, string $migrationDir, string $temporaryMigrationDir, array &$createdDatabases, string $runtimeUser, string $runtimePassword): array
{
    $admin->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $createdDatabases[] = $databaseName;
    $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON `{$databaseName}`.* TO '{$runtimeUser}'@'%'");

    $dsn = $adminDsn . ';dbname=' . $databaseName;
    $adminDb = acrlMigrationEngineConnection($dsn, $adminUser, $adminPassword);
    $executeSqlFile = new ReflectionMethod(MigrationService::class, 'executeSqlFile');
    $executeSqlFile->setAccessible(true);
    $executeSqlFile->invoke(null, $adminDb, __DIR__ . '/../../mysql/init.sql');

    if (!is_dir($temporaryMigrationDir)) {
        mkdir($temporaryMigrationDir, 0775, true);
    }
    foreach (glob(rtrim($migrationDir, '/') . '/*.sql') ?: [] as $path) {
        if (preg_match('/^(\d+)_/', basename($path), $match) !== 1 || (int)$match[1] > 65) {
            continue;
        }
        copy($path, $temporaryMigrationDir . '/' . basename($path));
    }
    $prepared = MigrationService::run($adminDb, $temporaryMigrationDir, false);
    if (!in_array('065_visualization_history_version.sql', $prepared['baselined'], true)
        && !in_array('065_visualization_history_version.sql', $prepared['applied'], true)) {
        throw new RuntimeException('The setup did not reach migration 065.');
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
    $executeSqlFile->invoke(null, $adminDb, $migrationDir . '/068_marc_field_combination_finder.sql');
    $executeSqlFile->invoke(null, $adminDb, $migrationDir . '/069_marc_combination_finder_record_scopes.sql');
    if (!acrlMigrationEngineDatabaseAppearsCurrent($adminDb)) {
        throw new RuntimeException('Setup check failed: databaseAppearsCurrent() must not require migration 070 gift reports.');
    }
    $executeSqlFile->invoke(null, $adminDb, $migrationDir . '/070_gift_reports.sql');
    if (!acrlMigrationEngineDatabaseAppearsCurrent($adminDb)) {
        throw new RuntimeException('Setup check failed: databaseAppearsCurrent() must accept the production-shaped schema including reports 068, 069, and 070 before schema_migrations is cleared.');
    }
    $executeSqlFile->invoke(null, $adminDb, $migrationDir . '/071_vea_gate_zones.sql');

    return [$adminDb, $dsn];
}

function acrlMigrationEngineDatabaseAppearsCurrent($db): bool
{
    $method = new ReflectionMethod(MigrationService::class, 'databaseAppearsCurrent');
    $method->setAccessible(true);
    return (bool)$method->invoke(null, $db);
}

function acrlMigrationEngineApplied($db, string $filename): bool
{
    $method = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
    $method->setAccessible(true);
    return (bool)$method->invoke(null, $db, $filename);
}

try {
    $admin = new PDO($adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = (string)$admin->query('SELECT VERSION()')->fetchColumn();
    $isMariaDb = stripos($version, 'mariadb') !== false;
    echo 'DB engine under test: ' . $version . "\n";
    acrlMigrationEngineCheck(
        $isMariaDb ? preg_match('/^10\.3\./', $version) === 1 : preg_match('/^8\./', $version) === 1,
        'Run this script once against MySQL 8.0 and once against MariaDB 10.3; server version was ' . $version . '.'
    );

    $createdRuntimeUser = $runtimeUser;
    $admin->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePassword}'");
    $prefix = 'acrl029_' . strtolower(substr(bin2hex(random_bytes(8)), 0, 10));

    try {
        $scenarioA = $prefix . '_a';
        list($adminDbA, $dsnA) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioA, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
        $deleted = (int)$adminDbA->createCommand("DELETE FROM acrl_questions WHERE survey_year = 2025 AND sheet = 'survey' AND question_id = '60' AND part = 'A'")->execute();
        acrlMigrationEngineSame(1, $deleted, 'Scenario (a) must delete the known 054 seed question before the empty-ledger baseline run.');
        $adminDbA->createCommand('DELETE FROM schema_migrations')->execute();
        $appDbA = acrlMigrationEngineConnection($dsnA, $runtimeUser, $runtimePassword);
        $grants = $admin->query("SHOW GRANTS FOR '{$runtimeUser}'@'%'")->fetchAll(PDO::FETCH_COLUMN);
        acrlMigrationEngineCheck(count($grants) > 0 && stripos(implode(' ', $grants), 'DROP') === false, 'The runtime migration user must have no DROP privilege.');
        $dryRunA = MigrationService::run($appDbA, $migrationDir, true);
        acrlMigrationEngineCheck(in_array('066_acrl_question_assignments.sql', $dryRunA['applied'], true), 'Scenario (a) dry run must list missing 066 as to-apply.');
        acrlMigrationEngineCheck(in_array('067_acrl_invites.sql', $dryRunA['applied'], true), 'Scenario (a) dry run must list missing 067 as to-apply.');
        acrlMigrationEngineCheck(!in_array('066_acrl_question_assignments.sql', $dryRunA['baselined'], true), 'Scenario (a) must not baseline missing 066.');
        acrlMigrationEngineCheck(!in_array('067_acrl_invites.sql', $dryRunA['baselined'], true), 'Scenario (a) must not baseline missing 067.');
        $dryBaselinedA = $dryRunA['baselined'];
        sort($dryBaselinedA, SORT_STRING);
        acrlMigrationEngineSame($migrationFilesExcept066And067, $dryBaselinedA, 'Scenario (a) dry run must baseline every existing migration other than the missing 066 and 067.');
        $resultA = MigrationService::run($appDbA, $migrationDir, false);
        acrlMigrationEngineCheck(in_array('066_acrl_question_assignments.sql', $resultA['applied'], true), 'Scenario (a) must execute and record missing 066.');
        acrlMigrationEngineCheck(in_array('067_acrl_invites.sql', $resultA['applied'], true), 'Scenario (a) must execute and record missing 067.');
        acrlMigrationEngineCheck(acrlMigrationEngineApplied($appDbA, '067_acrl_invites.sql'), 'Scenario (a) must re-recognize both 067 tables, columns, defaults, and target uniqueness after executing the migration.');
        $appliedA = $resultA['applied'];
        sort($appliedA, SORT_STRING);
        acrlMigrationEngineSame(['066_acrl_question_assignments.sql', '067_acrl_invites.sql'], $appliedA, 'Scenario (a) must execute only 066 and 067 instead of replaying any previously applied migration.');
        $baselinedA = $resultA['baselined'];
        sort($baselinedA, SORT_STRING);
        acrlMigrationEngineSame($migrationFilesExcept066And067, $baselinedA, 'Scenario (a) must baseline every previously applied migration except the missing 066 and 067.');
        $questionCountA = (int)$appDbA->createCommand("SELECT COUNT(*) FROM acrl_questions WHERE survey_year = 2025 AND sheet = 'survey' AND question_id = '60' AND part = 'A'")->queryScalar();
        acrlMigrationEngineSame(0, $questionCountA, 'Scenario (a) must keep the deliberately deleted seed question deleted.');
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'Scenario (a) failed before later scenarios: ' . get_class($error) . ': ' . $error->getMessage());
    }

    try {
        $scenarioB = $prefix . '_b';
        list($adminDbB, $dsnB) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioB, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
        $migration066 = (string)file_get_contents($migrationDir . '/066_acrl_question_assignments.sql');
        acrlMigrationEngineRaw($adminDbB, rtrim(trim($migration066), ';'));
        acrlMigrationEngineCreateInviteTables($adminDbB, $migrationDir);
        $deletedB = (int)$adminDbB->createCommand("DELETE FROM acrl_questions WHERE survey_year = 2025 AND sheet = 'survey' AND question_id = '60' AND part = 'A'")->execute();
        acrlMigrationEngineSame(1, $deletedB, 'Scenario (b) must remove the same 054 seed before testing a complete 066 schema.');
        $adminDbB->createCommand('DELETE FROM schema_migrations')->execute();
        $appDbB = acrlMigrationEngineConnection($dsnB, $runtimeUser, $runtimePassword);
        acrlMigrationEngineCheck(acrlMigrationEngineApplied($appDbB, '066_acrl_question_assignments.sql'), 'Scenario (b) must recognize 066 only when the expected table, columns, and unique key exist.');
        acrlMigrationEngineCheck(acrlMigrationEngineApplied($appDbB, '067_acrl_invites.sql'), 'Scenario (b) must recognize 067 only when both expected tables, columns, and unique keys exist.');
        $dryRunB = MigrationService::run($appDbB, $migrationDir, true);
        acrlMigrationEngineCheck(in_array('066_acrl_question_assignments.sql', $dryRunB['baselined'], true), 'Scenario (b) must baseline complete 066 from an empty ledger.');
        acrlMigrationEngineCheck(in_array('067_acrl_invites.sql', $dryRunB['baselined'], true), 'Scenario (b) must baseline complete 067 from an empty ledger.');
        acrlMigrationEngineCheck(!in_array('066_acrl_question_assignments.sql', $dryRunB['applied'], true), 'Scenario (b) must not rerun a complete 066 table.');
        acrlMigrationEngineCheck(!in_array('067_acrl_invites.sql', $dryRunB['applied'], true), 'Scenario (b) must not rerun complete 067 tables.');
        $resultB = MigrationService::run($appDbB, $migrationDir, false);
        acrlMigrationEngineCheck(in_array('066_acrl_question_assignments.sql', $resultB['baselined'], true), 'Scenario (b) must record 066 as baselined.');
        $baselinedB = $resultB['baselined'];
        sort($baselinedB, SORT_STRING);
        acrlMigrationEngineSame($allMigrationFiles, $baselinedB, 'Scenario (b) must baseline every migration when both complete ACRL schemas are present.');
        acrlMigrationEngineSame([], $resultB['applied'], 'Scenario (b) must execute no migration when both ACRL schemas are already complete.');
        $questionCountB = (int)$appDbB->createCommand("SELECT COUNT(*) FROM acrl_questions WHERE survey_year = 2025 AND sheet = 'survey' AND question_id = '60' AND part = 'A'")->queryScalar();
        acrlMigrationEngineSame(0, $questionCountB, 'Scenario (b) must keep the deleted seed question deleted.');

        $adminDbB->createCommand('DROP TABLE acrl_question_assignments')->execute();
        acrlMigrationEngineCreateMalformedAssignmentTable($adminDbB);
        $adminDbB->createCommand("DELETE FROM schema_migrations WHERE filename = '066_acrl_question_assignments.sql'")->execute();
        acrlMigrationEngineMalformedMigrationError($appDbB, $migrationDir, '066_acrl_question_assignments.sql', 'acrl_question_assignments');
        $adminDbB->createCommand('DELETE FROM schema_migrations')->execute();
        acrlMigrationEngineCheck(acrlMigrationEngineDatabaseAppearsCurrent($appDbB), 'Malformed-table baseline setup must still satisfy the legacy current-schema check.');
        acrlMigrationEngineMalformedMigrationError($appDbB, $migrationDir, '066_acrl_question_assignments.sql', 'acrl_question_assignments');

        $adminDbB->createCommand('DROP TABLE acrl_question_assignments')->execute();
        acrlMigrationEngineCreateAssignmentTableWithoutTimestampDefault($adminDbB, $migrationDir);
        acrlMigrationEngineMalformedMigrationError($appDbB, $migrationDir, '066_acrl_question_assignments.sql', 'acrl_question_assignments');
        $adminDbB->createCommand('DELETE FROM schema_migrations')->execute();
        acrlMigrationEngineCheck(acrlMigrationEngineDatabaseAppearsCurrent($appDbB), 'Missing-timestamp-default baseline setup must satisfy the legacy current-schema check.');
        acrlMigrationEngineMalformedMigrationError($appDbB, $migrationDir, '066_acrl_question_assignments.sql', 'acrl_question_assignments');

        $adminDbB->createCommand('DROP TABLE acrl_question_assignments')->execute();
        $migration066 = (string)file_get_contents($migrationDir . '/066_acrl_question_assignments.sql');
        acrlMigrationEngineRaw($adminDbB, rtrim(trim($migration066), ';'));
        $adminDbB->createCommand('DROP TABLE acrl_invite_targets')->execute();
        $adminDbB->createCommand('DROP TABLE acrl_invites')->execute();
        acrlMigrationEngineCreatePartialInviteSchema($adminDbB, $migrationDir);
        acrlMigrationEngineCheck(!acrlMigrationEngineApplied($appDbB, '067_acrl_invites.sql'), 'A partial 067 schema must not be recognized as applied.');
        acrlMigrationEngineMalformedMigrationError($appDbB, $migrationDir, '067_acrl_invites.sql', 'acrl_invites');
        $adminDbB->createCommand('DELETE FROM schema_migrations')->execute();
        acrlMigrationEngineMalformedMigrationError($appDbB, $migrationDir, '067_acrl_invites.sql', 'acrl_invites');
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'Scenario (b) failed before later scenarios: ' . get_class($error) . ': ' . $error->getMessage());
    }

    try {
        $scenarioD = $prefix . '_d';
        list($adminDbD, $dsnD) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioD, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
        acrlMigrationEngineCreateInviteTables($adminDbD, $migrationDir);
        $adminDbD->createCommand('DELETE FROM schema_migrations')->execute();
        $appDbD = acrlMigrationEngineConnection($dsnD, $runtimeUser, $runtimePassword);
        acrlMigrationEngineCheck(acrlMigrationEngineApplied($appDbD, '067_acrl_invites.sql'), 'Scenario (d) must recognize complete 067 independently of missing 066.');
        $resultD = MigrationService::run($appDbD, $migrationDir, false);
        acrlMigrationEngineSame(['066_acrl_question_assignments.sql'], $resultD['applied'], 'Scenario (d) must apply missing 066 while keeping complete 067 baselined.');
        acrlMigrationEngineCheck(in_array('067_acrl_invites.sql', $resultD['baselined'], true), 'Scenario (d) must baseline complete 067 in the empty-ledger path.');
        $appliedD = (int)$appDbD->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '067_acrl_invites.sql'")->queryScalar();
        acrlMigrationEngineSame(1, $appliedD, 'Scenario (d) must record exactly one baseline ledger row for 067.');
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'Scenario (d) failed before later scenarios: ' . get_class($error) . ': ' . $error->getMessage());
    }

    try {
        $scenarioE = $prefix . '_e';
        list($adminDbE, $dsnE) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioE, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
        acrlMigrationEngineCreatePartialInviteSchema($adminDbE, $migrationDir);
        $appDbE = acrlMigrationEngineConnection($dsnE, $runtimeUser, $runtimePassword);
        $partialNormalLedgerError = null;
        try {
            MigrationService::run($appDbE, $migrationDir, false);
        } catch (Throwable $error) {
            $partialNormalLedgerError = $error;
        }
        acrlMigrationEngineCheck($partialNormalLedgerError !== null && strpos($partialNormalLedgerError->getMessage(), 'acrl_invites') !== false, 'A partial 067 schema on the normal-ledger path must throw an error naming acrl_invites.');
        $partialNormalLedgerRecorded = (int)$appDbE->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '067_acrl_invites.sql'")->queryScalar();
        acrlMigrationEngineSame(0, $partialNormalLedgerRecorded, 'A partial 067 schema on the normal-ledger path must not be recorded.');
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'Scenario (e) failed before later scenarios: ' . get_class($error) . ': ' . $error->getMessage());
    }

    try {
        $scenarioF = $prefix . '_f';
        list($adminDbF, $dsnF) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioF, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
        acrlMigrationEngineRaw($adminDbF, rtrim(trim((string)file_get_contents($migrationDir . '/066_acrl_question_assignments.sql')), ';'));
        acrlMigrationEngineCreateInviteTables($adminDbF, $migrationDir);
        $adminDbF->createCommand('DELETE FROM schema_migrations')->execute();
        $appDbF = acrlMigrationEngineConnection($dsnF, $runtimeUser, $runtimePassword);

        if (!$isMariaDb) {
            acrlMigrationEngineCheckMalformedRecognition('MySQL email DEFAULT literal NULL', $adminDbF, $appDbF, $migrationDir,
                '067_acrl_invites.sql', function ($db) {
                    $db->createCommand("ALTER TABLE acrl_invites MODIFY email VARCHAR(191) NOT NULL DEFAULT 'NULL'")->execute();
                });
            acrlMigrationEngineCheckMalformedRecognition('MySQL status DEFAULT containing quote characters around pending', $adminDbF, $appDbF, $migrationDir,
                '067_acrl_invites.sql', function ($db) {
                    $db->createCommand("ALTER TABLE acrl_invites MODIFY status VARCHAR(10) NOT NULL DEFAULT '''pending'''")->execute();
                });
        }

        acrlMigrationEngineCheckMalformedRecognition('067 missing invited_by foreign key', $adminDbF, $appDbF, $migrationDir,
            '067_acrl_invites.sql', function ($db) {
                $db->createCommand('ALTER TABLE acrl_invites DROP FOREIGN KEY fk_acrl_invites_invited_by')->execute();
            });
        acrlMigrationEngineCheckMalformedRecognition('067 invited_by foreign key with the wrong delete rule', $adminDbF, $appDbF, $migrationDir,
            '067_acrl_invites.sql', function ($db) {
                $db->createCommand('ALTER TABLE acrl_invites DROP FOREIGN KEY fk_acrl_invites_invited_by')->execute();
                $db->createCommand('ALTER TABLE acrl_invites ADD CONSTRAINT fk_acrl_invites_invited_by'
                    . ' FOREIGN KEY (invited_by) REFERENCES users (id) ON DELETE CASCADE')->execute();
            });
        acrlMigrationEngineCheckMalformedRecognition('066 missing assigned_by foreign key', $adminDbF, $appDbF, $migrationDir,
            '066_acrl_question_assignments.sql', function ($db) {
                $db->createCommand('ALTER TABLE acrl_question_assignments DROP FOREIGN KEY fk_acrl_question_assignments_assigned_by')->execute();
            });
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'Scenario (f) metadata and foreign-key recognition checks failed before later scenarios: ' . get_class($error) . ': ' . $error->getMessage());
    }

    try {
        $scenarioC = $prefix . '_c';
        list($adminDbC, $dsnC) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioC, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
        $appDbC = acrlMigrationEngineConnection($dsnC, $runtimeUser, $runtimePassword);
        $firstRunC = MigrationService::run($appDbC, $migrationDir, false);
        acrlMigrationEngineSame(['066_acrl_question_assignments.sql', '067_acrl_invites.sql'], $firstRunC['applied'], 'Scenario (c) must run only 066 and 067 once after a normal ledger through 065.');
        acrlMigrationEngineCheck(acrlMigrationEngineApplied($appDbC, '067_acrl_invites.sql'), 'Scenario (c) must re-recognize complete 067 after applying it on the normal-ledger path.');
        $secondRunC = MigrationService::run($appDbC, $migrationDir, false);
        acrlMigrationEngineSame([], $secondRunC['applied'], 'Scenario (c) rerun must be a no-op for applied migrations.');
        $ledgerCountC = (int)$appDbC->createCommand("SELECT COUNT(*) FROM schema_migrations WHERE filename = '066_acrl_question_assignments.sql'")->queryScalar();
        acrlMigrationEngineSame(1, $ledgerCountC, 'Scenario (c) must record exactly one ledger row for 066.');

        $appDbC->createCommand("UPDATE acrl_survey_years SET status = 'published' WHERE survey_year = 2025")->execute();
        $appDbC->createCommand("INSERT INTO acrl_survey_years (survey_year, status, created_by) VALUES (2026, 'published', 'ACR-028 test')")->execute();
        $nextUserId = (int)$adminDbC->createCommand('SELECT COALESCE(MAX(id), 0) + 100 FROM users')->queryScalar();
        $actorId = $nextUserId + 1;
        $adminDbC->createCommand()->insert('users', [
            'id' => $nextUserId, 'smith_id' => 'acrl028-user-' . $nextUserId, 'username' => 'acrl028-user-' . $nextUserId,
            'role' => 'user', 'is_approved' => 1,
        ])->execute();
        $adminDbC->createCommand()->insert('users', [
            'id' => $actorId, 'smith_id' => 'acrl028-admin-' . $actorId, 'username' => 'acrl028-admin-' . $actorId,
            'email' => 'acrl029-admin-' . $actorId . '@smith.example', 'first_name' => 'Synthetic', 'last_name' => 'Admin',
            'role' => 'admin', 'is_approved' => 1,
        ])->execute();
        foreach ([2025, 2026] as $year) {
            $appDbC->createCommand()->insert('acrl_questions', [
                'survey_year' => $year, 'sheet' => 'survey', 'question_id' => 'ACR28', 'part' => '',
                'section' => 'ACR-028 test section', 'prompt' => 'Synthetic assignment test prompt',
                'answer_type' => 'number', 'required_status' => 'no', 'survey_order' => 1,
            ])->execute();
        }
        $appDbC->createCommand()->insert('acrl_question_links', [
            'previous_year' => 2025, 'current_year' => 2026, 'sheet' => 'survey',
            'previous_question_id' => 'ACR28', 'previous_part' => '', 'current_question_id' => 'ACR28', 'current_part' => '',
            'match_kind' => 'same', 'score' => 1, 'decision' => 'approved', 'decided_by' => 'ACR-028 test',
        ])->execute();
        $adminDbC->createCommand()->insert('acrl_section_assignments', [
            'survey_year' => 2025, 'section' => 'ACR-028 test section', 'user_id' => $nextUserId, 'assigned_by' => $actorId,
        ])->execute();
        $adminDbC->createCommand()->insert('acrl_question_assignments', [
            'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR28', 'part' => '', 'user_id' => $nextUserId, 'assigned_by' => $actorId,
        ])->execute();

        $users = new class($nextUserId, $actorId) {
            private $users;
            public function __construct(int $userId, int $actorId)
            {
                $this->users = [
                    $userId => ['id' => $userId, 'displayName' => 'Synthetic Editor'],
                    $actorId => ['id' => $actorId, 'displayName' => 'Synthetic Admin'],
                ];
            }
            public function findByIds(array $ids): array
            {
                $found = [];
                foreach ($ids as $id) if (isset($this->users[(int)$id])) $found[] = $this->users[(int)$id];
                return $found;
            }
        };
        $questionRepository = new AcrlQuestionAssignmentRepository($appDbC);
        $questionService = new AcrlQuestionAssignmentService($questionRepository, $users);
        $sectionRepository = new AcrlSectionAssignmentRepository($appDbC);
        $sectionService = new AcrlSectionAssignmentService($sectionRepository, $users, $questionService);

        $adminDbC->createCommand("UPDATE acrl_survey_years SET status = 'draft' WHERE survey_year = 2025")->execute();
        $adminDbC->createCommand()->insert('acrl_section_assignments', [
            'survey_year' => 2026, 'section' => 'ACR-028 test section', 'user_id' => $nextUserId, 'assigned_by' => $actorId,
        ])->execute();
        $copyWithUnpublishedSource = $sectionService->copyOnPublish(2026, (string)$actorId);
        acrlMigrationEngineSame(true, $copyWithUnpublishedSource['alreadyAssigned'] ?? null, 'Existing section assignments retain their success result when the previous year is unpublished.');
        acrlMigrationEngineSame(false, $copyWithUnpublishedSource['failed'] ?? null, 'Existing section assignments preserve the nonfatal success result when the previous year is unpublished.');
        acrlMigrationEngineSame([], $copyWithUnpublishedSource['copiedSections'] ?? null, 'An existing section assignment is not overwritten when the previous year is unpublished.');
        acrlMigrationEngineSame(['ACR-028 test section'], $copyWithUnpublishedSource['skippedSections'] ?? null, 'The existing section remains reported as skipped when the previous year is unpublished.');
        acrlMigrationEngineSame([], $copyWithUnpublishedSource['copiedQuestions'] ?? null, 'Question copy is empty when the previous year is unpublished, even when section rows already exist.');
        acrlMigrationEngineSame(false, $copyWithUnpublishedSource['questionsAlreadyAssigned'] ?? null, 'An unpublished source year does not set the question-copy guard.');
        $adminDbC->createCommand('DELETE FROM acrl_section_assignments WHERE survey_year = 2026')->execute();
        $adminDbC->createCommand("UPDATE acrl_survey_years SET status = 'published' WHERE survey_year = 2025")->execute();

        $adminDbC->createCommand()->insert('acrl_section_assignments', [
            'survey_year' => 2026, 'section' => 'ACR-028 test section', 'user_id' => $nextUserId, 'assigned_by' => $actorId,
        ])->execute();
        $copyWithSectionGuard = $sectionService->copyOnPublish(2026, (string)$actorId);
        acrlMigrationEngineSame(true, $copyWithSectionGuard['alreadyAssigned'] ?? null, 'A target section assignment keeps the existing alreadyAssigned meaning.');
        acrlMigrationEngineSame([], $copyWithSectionGuard['copiedSections'] ?? null, 'A section guard may skip sections while the question copy remains independent.');
        acrlMigrationEngineSame([['sheet' => 'survey', 'questionId' => 'ACR28', 'part' => '', 'label' => 'QACR28']], $copyWithSectionGuard['copiedQuestions'] ?? null, 'A section guard does not stop eligible question copying.');
        acrlMigrationEngineSame(false, $copyWithSectionGuard['questionsAlreadyAssigned'] ?? null, 'Question copy has its own guard result.');

        $adminDbC->createCommand('DELETE FROM acrl_section_assignments WHERE survey_year = 2026')->execute();
        $adminDbC->createCommand('DELETE FROM acrl_question_assignments WHERE survey_year = 2026')->execute();
        $triggerName = 'acrl028_fail_question_insert';
        $adminDbC->createCommand()->setRawSql(
            'CREATE TRIGGER `' . $triggerName . '` BEFORE INSERT ON acrl_question_assignments FOR EACH ROW '
                . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACR-028 injected question insert failure'"
        )->execute();
        $copyFailure = null;
        try {
            $sectionService->copyOnPublish(2026, (string)$actorId);
        } catch (Throwable $error) {
            $copyFailure = $error;
        }
        acrlMigrationEngineCheck($copyFailure !== null, 'A failing question-assignment insert must fail the combined publish copy.');
        $sectionRowsAfterFailure = (int)$adminDbC->createCommand("SELECT COUNT(*) FROM acrl_section_assignments WHERE survey_year = 2026 AND section = 'ACR-028 test section'")->queryScalar();
        $questionRowsAfterFailure = (int)$adminDbC->createCommand("SELECT COUNT(*) FROM acrl_question_assignments WHERE survey_year = 2026 AND question_id = 'ACR28'")->queryScalar();
        acrlMigrationEngineSame(0, $sectionRowsAfterFailure, 'The failing question insert rolls the section copy back from the same transaction.');
        acrlMigrationEngineSame(0, $questionRowsAfterFailure, 'The failing question insert leaves no partial question rows.');
        $adminDbC->createCommand()->setRawSql('DROP TRIGGER `' . $triggerName . '`')->execute();

        $adminDbC->createCommand()->insert('acrl_question_assignments', [
            'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'ACR28', 'part' => '', 'user_id' => $nextUserId, 'assigned_by' => $actorId,
        ])->execute();
        $copyWithQuestionGuard = $sectionService->copyOnPublish(2026, (string)$actorId);
        acrlMigrationEngineSame(false, $copyWithQuestionGuard['alreadyAssigned'] ?? null, 'Existing question rows do not change the section alreadyAssigned field.');
        acrlMigrationEngineSame(['ACR-028 test section'], $copyWithQuestionGuard['copiedSections'] ?? null, 'A question guard does not stop eligible section copying.');
        acrlMigrationEngineSame(true, $copyWithQuestionGuard['questionsAlreadyAssigned'] ?? null, 'Question assignments use their independent publish guard.');
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'Scenario (c) setup and ACR-028 assignment checks failed before invite scenarios: ' . get_class($error) . ': ' . $error->getMessage());
    }

    try {
        $scenarioG = $prefix . '_g';
        list($adminDbG, $dsnG) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioG, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
        $adminDbG->createCommand('DROP TABLE vea_gate_zones')->execute();
        $appDbG = acrlMigrationEngineConnection($dsnG, $runtimeUser, $runtimePassword);
        acrlMigrationEngineCheckVeaGateZoneInstall($appDbG, $migrationDir, 'Scenario (g) normal-ledger path');

        $scenarioH = $prefix . '_h';
        list($adminDbH, $dsnH) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioH, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
        $adminDbH->createCommand('DROP TABLE vea_gate_zones')->execute();
        $adminDbH->createCommand('DELETE FROM schema_migrations')->execute();
        $appDbH = acrlMigrationEngineConnection($dsnH, $runtimeUser, $runtimePassword);
        acrlMigrationEngineCheckVeaGateZoneInstall($appDbH, $migrationDir, 'Scenario (h) empty-ledger path');
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'Scenarios (g) and (h) failed before later scenarios: ' . get_class($error) . ': ' . $error->getMessage());
    }

    try {
        $scenarioI = $prefix . '_i';
        list($adminDbI, $dsnI) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioI, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
        $adminDbI->createCommand('ALTER TABLE vea_gate_zones ADD COLUMN unexpected INT NOT NULL DEFAULT 0')->execute();
        $appDbI = acrlMigrationEngineConnection($dsnI, $runtimeUser, $runtimePassword);
        acrlMigrationEngineCheckMalformedVeaGateZone($adminDbI, $appDbI, $migrationDir, 'Scenario (i) normal-ledger path');
        $adminDbI->createCommand('DELETE FROM schema_migrations')->execute();
        acrlMigrationEngineCheckMalformedVeaGateZone($adminDbI, $appDbI, $migrationDir, 'Scenario (i) empty-ledger path');
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'Scenario (i) malformed 071 checks failed: ' . get_class($error) . ': ' . $error->getMessage());
    }

    try {
        $yiiApp = new Application([
            'id' => 'acrl-invite-migration-engine-test',
            'basePath' => dirname(__DIR__),
            'components' => [
                'db' => ['class' => Connection::class, 'dsn' => $dsnC, 'username' => $runtimeUser, 'password' => $runtimePassword],
                'request' => ['cookieValidationKey' => 'acrl029-test-key'],
                'response' => ['class' => yii\web\Response::class],
                'log' => ['targets' => []],
            ],
        ]);
        $yiiApp->errorHandler->unregister();
        $yiiApp->set('db', $appDbC);
        $inviteeId = $actorId + 10;
        $rollbackInviteeId = $actorId + 11;
        $raceInviteeId = $actorId + 12;
        foreach ([[$inviteeId, 'acrl029-rollback-user'], [$rollbackInviteeId, 'acrl029-race-revoke-user'], [$raceInviteeId, 'acrl029-race-create-user']] as $userRow) {
            $appDbC->createCommand()->insert('users', [
                'id' => $userRow[0], 'smith_id' => $userRow[1], 'username' => $userRow[1],
                'email' => null, 'role' => 'user', 'is_approved' => 0,
            ])->execute();
        }

        $appDbC->createCommand()->delete('acrl_section_assignments', ['survey_year' => 2026])->execute();
        $appDbC->createCommand()->delete('acrl_question_assignments', ['survey_year' => 2026])->execute();
        $appDbC->createCommand()->insert('acrl_questions', [
            'survey_year' => 2026, 'sheet' => 'survey', 'question_id' => 'ACR29R', 'part' => '',
            'section' => 'ACR-029 rollback section', 'prompt' => 'Synthetic rollback target',
            'answer_type' => 'text', 'required_status' => 'no', 'survey_order' => 2,
        ])->execute();

        $appDbC->createCommand()->insert('acrl_invites', [
            'email' => 'acrl029-rollback@smith.example', 'survey_year' => 2026, 'invited_by' => $actorId,
            'link_origin' => 'https://reports.example.test', 'status' => 'pending', 'send_count' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ])->execute();
        $rollbackInviteId = (int)$appDbC->getLastInsertID();
        $appDbC->createCommand()->batchInsert('acrl_invite_targets', ['invite_id', 'kind', 'section', 'sheet', 'question_id', 'part'], [
            [$rollbackInviteId, 'section', 'ACR-029 rollback section', '', '', ''],
            [$rollbackInviteId, 'question', 'ACR-029 rollback section', 'survey', 'ACR29R', ''],
        ])->execute();

        $inviteService = new AcrlInviteService(
            $appDbC,
            new AcrlSectionAssignmentRepository($appDbC),
            new AcrlQuestionAssignmentRepository($appDbC),
            new AcrlInviteMailer(function () { return false; })
        );

        try {
            $paddedCreateError = null;
            try {
                $inviteService->createInvite(2026, 'acrl029-padded-create@smith.example', [
                    ['kind' => 'question', 'sheet' => 'survey', 'questionId' => 'ACR28 ', 'part' => ''],
                ], User::findOne($actorId), 'https://reports.example.test');
            } catch (Throwable $error) {
                $paddedCreateError = $error;
            }
            acrlMigrationEngineCheck($paddedCreateError instanceof InvalidArgumentException
                && strpos($paddedCreateError->getMessage(), 'not in the published survey') !== false,
                'Create rejects a question ID with trailing PAD SPACE characters as an unknown target.');
            $paddedCreateRows = (int)$adminDbC->createCommand(
                'SELECT COUNT(*) FROM acrl_invites WHERE email = :email', [':email' => 'acrl029-padded-create@smith.example']
            )->queryScalar();
            acrlMigrationEngineSame(0, $paddedCreateRows, 'Rejected padded question IDs create no invite row.');
            if ($paddedCreateRows > 0) {
                $adminDbC->createCommand()->delete('acrl_invites', ['email' => 'acrl029-padded-create@smith.example'])->execute();
            }
        } catch (Throwable $error) {
            acrlMigrationEngineCheck(false, 'Padded question ID create check threw ' . get_class($error) . ': ' . $error->getMessage());
        }

        try {
            $paddedInviteeId = $actorId + 13;
            $appDbC->createCommand()->insert('users', [
                'id' => $paddedInviteeId, 'smith_id' => 'acrl029-padded-accept-user', 'username' => 'acrl029-padded-accept-user',
                'email' => null, 'role' => 'user', 'is_approved' => 0,
            ])->execute();
            $appDbC->createCommand()->insert('acrl_invites', [
                'email' => 'acrl029-padded-accept@smith.example', 'survey_year' => 2026, 'invited_by' => $actorId,
                'link_origin' => 'https://reports.example.test', 'status' => 'pending', 'send_count' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            ])->execute();
            $paddedAcceptInviteId = (int)$appDbC->getLastInsertID();
            $appDbC->createCommand()->insert('acrl_invite_targets', [
                'invite_id' => $paddedAcceptInviteId, 'kind' => 'question', 'section' => 'ACR-028 test section',
                'sheet' => 'survey', 'question_id' => 'ACR28 ', 'part' => '',
            ])->execute();
            $paddedAcceptResult = $inviteService->acceptOnSignIn(
                User::findOne($paddedInviteeId), 'acrl029-padded-accept@smith.example', 'acrl029-padded-accept-user'
            );
            acrlMigrationEngineSame(false, $paddedAcceptResult, 'Acceptance rejects a stored question ID that only matches after PAD SPACE comparison.');
            acrlMigrationEngineSame('pending', (string)$adminDbC->createCommand(
                'SELECT status FROM acrl_invites WHERE id = :id', [':id' => $paddedAcceptInviteId]
            )->queryScalar(), 'A stored padded question ID leaves its invite pending.');
            acrlMigrationEngineSame(0, (int)$adminDbC->createCommand(
                'SELECT is_approved FROM users WHERE id = :id', [':id' => $paddedInviteeId]
            )->queryScalar(), 'A stored padded question ID does not approve its invitee.');
            acrlMigrationEngineSame(0, (int)$adminDbC->createCommand(
                'SELECT COUNT(*) FROM acrl_question_assignments WHERE user_id = :id', [':id' => $paddedInviteeId]
            )->queryScalar(), 'A stored padded question ID creates no assignment.');
        } catch (Throwable $error) {
            acrlMigrationEngineCheck(false, 'Padded question ID acceptance check threw ' . get_class($error) . ': ' . $error->getMessage());
        }

        $rollbackTrigger = 'acrl029_fail_invite_accept';
        $adminDbC->createCommand()->setRawSql(
            'CREATE TRIGGER `' . $rollbackTrigger . '` BEFORE UPDATE ON acrl_invites FOR EACH ROW '
                . "BEGIN IF NEW.status = 'accepted' AND OLD.status = 'pending' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACR-029 injected acceptance failure'; END IF; END"
        )->execute();
        $rollbackError = null;
        try {
            $inviteService->acceptOnSignIn(User::findOne($inviteeId), 'acrl029-rollback@smith.example', 'acrl029-rollback-user');
        } catch (Throwable $error) {
            $rollbackError = $error;
        }
        acrlMigrationEngineCheck($rollbackError !== null, 'A failed final invite write must make acceptance fail and roll back the transaction.');
        acrlMigrationEngineCheck($rollbackError !== null && !($rollbackError instanceof TypeError), 'The acceptance rollback scenario must reach the real service transaction, not a load-only stub.');
        acrlMigrationEngineSame('pending', (string)$appDbC->createCommand('SELECT status FROM acrl_invites WHERE id = :id', [':id' => $rollbackInviteId])->queryScalar(), 'Acceptance rollback leaves the invite pending.');
        acrlMigrationEngineSame(0, (int)$appDbC->createCommand('SELECT is_approved FROM users WHERE id = :id', [':id' => $inviteeId])->queryScalar(), 'Acceptance rollback leaves the user unapproved.');
        acrlMigrationEngineSame(0, (int)$appDbC->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE survey_year = 2026 AND section = :section', [':section' => 'ACR-029 rollback section'])->queryScalar(), 'Acceptance rollback removes the section assignment.');
        acrlMigrationEngineSame(0, (int)$appDbC->createCommand('SELECT COUNT(*) FROM acrl_question_assignments WHERE survey_year = 2026 AND question_id = :question', [':question' => 'ACR29R'])->queryScalar(), 'Acceptance rollback removes the question assignment.');
        $adminDbC->createCommand()->setRawSql('DROP TRIGGER `' . $rollbackTrigger . '`')->execute();
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'ACR-029 acceptance rollback scenario failed before concurrency scenarios: ' . get_class($error) . ': ' . $error->getMessage());
    }

    try {
        $raceInviteId = $appDbC->createCommand()->insert('acrl_invites', [
            'email' => 'acrl029-race-revoke@smith.example', 'survey_year' => 2026, 'invited_by' => $actorId,
            'link_origin' => 'https://reports.example.test', 'status' => 'pending', 'send_count' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ])->execute();
        $raceInviteId = (int)$appDbC->getLastInsertID();
        $appDbC->createCommand()->insert('acrl_invite_targets', [
            'invite_id' => $raceInviteId, 'kind' => 'section', 'section' => 'ACR-029 rollback section',
            'sheet' => '', 'question_id' => '', 'part' => '',
        ])->execute();

        $acceptLockDbA = acrlMigrationEngineConnection($dsnC, $runtimeUser, $runtimePassword);
        $appDbC->createCommand('SET SESSION innodb_lock_wait_timeout = 1')->execute();
        $acceptLockDbA->beginTransaction();
        $lockedYear = (int)$acceptLockDbA->createCommand(
            'SELECT survey_year FROM acrl_survey_years WHERE survey_year = 2026 FOR UPDATE'
        )->queryScalar();
        acrlMigrationEngineSame(2026, $lockedYear, 'Connection A locks the invite survey year for accept/revoke.');
        $lockedInviteId = (int)$acceptLockDbA->createCommand(
            'SELECT id FROM acrl_invites WHERE id = :id AND status = :status FOR UPDATE',
            [':id' => $raceInviteId, ':status' => 'pending']
        )->queryScalar();
        acrlMigrationEngineSame($raceInviteId, $lockedInviteId, 'Connection A locks the pending invite before the accept attempt.');

        $acceptServiceB = new AcrlInviteService(
            $appDbC,
            new AcrlSectionAssignmentRepository($appDbC),
            new AcrlQuestionAssignmentRepository($appDbC),
            new AcrlInviteMailer(function () { return false; })
        );
        $acceptError = null;
        $acceptResult = null;
        try {
            $acceptResult = $acceptServiceB->acceptOnSignIn(
                User::findOne($rollbackInviteeId),
                'acrl029-race-revoke@smith.example',
                'acrl029-race-revoke-user'
            );
        } catch (Throwable $error) {
            $acceptError = $error;
        }
        acrlMigrationEngineCheck(
            $acceptError !== null && acrlMigrationEngineIsLockWaitTimeout($acceptError),
            'Connection B accept must fail with an InnoDB lock-wait timeout while connection A holds the year/invite locks.'
        );
        $acceptTransactionActive = acrlMigrationEngineHasActiveTransaction($appDbC);
        acrlMigrationEngineCheck(!$acceptTransactionActive, 'Connection B rolls back the acceptance transaction after its lock-wait timeout.');
        if ($acceptTransactionActive) {
            acrlMigrationEngineRollbackActiveTransaction($appDbC);
        }
        acrlMigrationEngineSame('pending', (string)$acceptLockDbA->createCommand(
            'SELECT status FROM acrl_invites WHERE id = :id',
            [':id' => $raceInviteId]
        )->queryScalar(), 'A timed-out acceptance leaves the invite pending.');
        acrlMigrationEngineSame(0, (int)$acceptLockDbA->createCommand(
            'SELECT is_approved FROM users WHERE id = :id',
            [':id' => $rollbackInviteeId]
        )->queryScalar(), 'A timed-out acceptance leaves the invitee unapproved.');
        acrlMigrationEngineSame(0, (int)$acceptLockDbA->createCommand(
            'SELECT COUNT(*) FROM acrl_section_assignments WHERE user_id = :id AND survey_year = 2026',
            [':id' => $rollbackInviteeId]
        )->queryScalar(), 'A timed-out acceptance creates no section assignments.');
        acrlMigrationEngineSame(0, (int)$acceptLockDbA->createCommand(
            'SELECT COUNT(*) FROM acrl_question_assignments WHERE user_id = :id AND survey_year = 2026',
            [':id' => $rollbackInviteeId]
        )->queryScalar(), 'A timed-out acceptance creates no question assignments.');

        $revokedRows = $acceptLockDbA->createCommand(
            "UPDATE acrl_invites SET status = 'revoked', revoked_at = CURRENT_TIMESTAMP(6)"
                . " WHERE id = :id AND status = 'pending'",
            [':id' => $raceInviteId]
        )->execute();
        acrlMigrationEngineSame(1, $revokedRows, 'Connection A revokes the invite while holding the locks.');
        $acceptLockDbA->getTransaction()->commit();

        $acceptRetryError = null;
        $acceptRetryResult = null;
        try {
            $acceptRetryResult = $acceptServiceB->acceptOnSignIn(
                User::findOne($rollbackInviteeId),
                'acrl029-race-revoke@smith.example',
                'acrl029-race-revoke-user'
            );
        } catch (Throwable $error) {
            $acceptRetryError = $error;
        }
        acrlMigrationEngineCheck($acceptRetryError === null, 'Connection B retries acceptance after connection A commits the revoke.');
        acrlMigrationEngineSame(false, $acceptRetryResult, 'A retry observes the revoked invite and returns false.');
        acrlMigrationEngineSame('revoked', (string)$acceptLockDbA->createCommand(
            'SELECT status FROM acrl_invites WHERE id = :id',
            [':id' => $raceInviteId]
        )->queryScalar(), 'The concurrent acceptance retry leaves the invite revoked.');
        acrlMigrationEngineSame(0, (int)$acceptLockDbA->createCommand(
            'SELECT is_approved FROM users WHERE id = :id',
            [':id' => $rollbackInviteeId]
        )->queryScalar(), 'A revoked invite retry leaves the user unapproved.');
        acrlMigrationEngineSame(0, (int)$acceptLockDbA->createCommand(
            'SELECT COUNT(*) FROM acrl_section_assignments WHERE user_id = :id AND survey_year = 2026',
            [':id' => $rollbackInviteeId]
        )->queryScalar(), 'A revoked invite retry creates no section assignments.');
        acrlMigrationEngineSame(0, (int)$acceptLockDbA->createCommand(
            'SELECT COUNT(*) FROM acrl_question_assignments WHERE user_id = :id AND survey_year = 2026',
            [':id' => $rollbackInviteeId]
        )->queryScalar(), 'A revoked invite retry creates no question assignments.');
        $acceptLockDbA->close();
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'ACR-029 accept/revoke lock-timeout scenario failed before concurrent create: ' . get_class($error) . ': ' . $error->getMessage());
        if (isset($acceptLockDbA)) {
            $acceptLockDbA->close();
        }
        if (isset($appDbC)) {
            try {
                acrlMigrationEngineRollbackActiveTransaction($appDbC);
            } catch (Throwable $cleanupError) {
                acrlMigrationEngineCheck(false, 'Could not clear the accept/revoke retry transaction: ' . $cleanupError->getMessage());
            }
        }
    }

    try {
        $createLockDbA = acrlMigrationEngineConnection($dsnC, $runtimeUser, $runtimePassword);
        $appDbC->createCommand('SET SESSION innodb_lock_wait_timeout = 1')->execute();
        $createServiceB = new AcrlInviteService(
            $appDbC,
            new AcrlSectionAssignmentRepository($appDbC),
            new AcrlQuestionAssignmentRepository($appDbC),
            new AcrlInviteMailer(function () { return false; })
        );
        $createAdminB = User::findOne($actorId);
        $createLockDbA->beginTransaction();
        $createLockedYear = (int)$createLockDbA->createCommand(
            'SELECT survey_year FROM acrl_survey_years WHERE survey_year = 2026 FOR UPDATE'
        )->queryScalar();
        acrlMigrationEngineSame(2026, $createLockedYear, 'Connection A locks the survey year for concurrent create.');

        $createTarget = [['kind' => 'question', 'sheet' => 'survey', 'questionId' => 'ACR28', 'part' => '']];
        $createTimeoutError = null;
        try {
            $createServiceB->createInvite(
                2026,
                'acrl029-race-create@smith.example',
                $createTarget,
                $createAdminB,
                'https://reports.example.test'
            );
        } catch (Throwable $error) {
            $createTimeoutError = $error;
        }
        acrlMigrationEngineCheck(
            $createTimeoutError !== null && acrlMigrationEngineIsLockWaitTimeout($createTimeoutError),
            'Connection B create must fail with an InnoDB lock-wait timeout while connection A holds the survey-year lock.'
        );
        $createTransactionActive = acrlMigrationEngineHasActiveTransaction($appDbC);
        acrlMigrationEngineCheck(!$createTransactionActive, 'Connection B rolls back the create transaction after its lock-wait timeout.');
        if ($createTransactionActive) {
            acrlMigrationEngineRollbackActiveTransaction($appDbC);
        }
        acrlMigrationEngineSame(0, (int)$createLockDbA->createCommand(
            'SELECT COUNT(*) FROM acrl_invites WHERE email = :email AND survey_year = 2026',
            [':email' => 'acrl029-race-create@smith.example']
        )->queryScalar(), 'A timed-out create leaves no invite row.');
        acrlMigrationEngineSame(0, (int)$createLockDbA->createCommand(
            'SELECT COUNT(*) FROM acrl_invite_targets t'
                . ' INNER JOIN acrl_invites i ON i.id = t.invite_id'
                . ' WHERE i.email = :email AND i.survey_year = 2026',
            [':email' => 'acrl029-race-create@smith.example']
        )->queryScalar(), 'A timed-out create leaves no target rows.');

        $createLockDbA->createCommand()->insert('acrl_invites', [
            'email' => 'acrl029-race-create@smith.example',
            'survey_year' => 2026,
            'invited_by' => $actorId,
            'link_origin' => 'https://reports.example.test',
            'status' => 'pending',
            'send_count' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ])->execute();
        $createInviteId = (int)$createLockDbA->getLastInsertID();
        $createLockDbA->createCommand()->insert('acrl_invite_targets', [
            'invite_id' => $createInviteId,
            'kind' => 'section',
            'section' => 'ACR-029 rollback section',
            'sheet' => '',
            'question_id' => '',
            'part' => '',
        ])->execute();
        $createLockDbA->getTransaction()->commit();

        $createRetryError = null;
        $createRetryResponse = null;
        try {
            $createRetryResponse = $createServiceB->createInvite(
                2026,
                'acrl029-race-create@smith.example',
                $createTarget,
                $createAdminB,
                'https://reports.example.test'
            );
        } catch (Throwable $error) {
            $createRetryError = $error;
        }
        acrlMigrationEngineCheck($createRetryError === null, 'Connection B retries create after connection A commits the pending invite.');
        $createResponseInviteId = is_array($createRetryResponse) && isset($createRetryResponse['invite']['id'])
            ? (int)$createRetryResponse['invite']['id']
            : null;
        acrlMigrationEngineSame($createInviteId, $createResponseInviteId, 'A repeated create returns the pending invite created by connection A.');
        $createPendingCount = (int)$createLockDbA->createCommand(
            "SELECT COUNT(*) FROM acrl_invites WHERE email = :email AND survey_year = 2026 AND status = 'pending'",
            [':email' => 'acrl029-race-create@smith.example']
        )->queryScalar();
        acrlMigrationEngineSame(1, $createPendingCount, 'Concurrent create leaves one pending invite row.');
        $createTargetCount = (int)$createLockDbA->createCommand(
            'SELECT COUNT(*) FROM acrl_invite_targets WHERE invite_id = :id',
            [':id' => $createInviteId]
        )->queryScalar();
        acrlMigrationEngineSame(2, $createTargetCount, 'A repeated create merges its target into the one pending invite.');
        $createSectionCount = (int)$createLockDbA->createCommand(
            "SELECT COUNT(*) FROM acrl_invite_targets WHERE invite_id = :id AND kind = 'section'",
            [':id' => $createInviteId]
        )->queryScalar();
        $createQuestionCount = (int)$createLockDbA->createCommand(
            "SELECT COUNT(*) FROM acrl_invite_targets WHERE invite_id = :id AND kind = 'question' AND sheet = 'survey' AND question_id = 'ACR28' AND part = ''",
            [':id' => $createInviteId]
        )->queryScalar();
        acrlMigrationEngineSame(1, $createSectionCount, 'The connection A section target remains on the invite.');
        acrlMigrationEngineSame(1, $createQuestionCount, 'The connection B question target is added to the invite.');
        $createLockDbA->close();
    } catch (Throwable $error) {
        acrlMigrationEngineCheck(false, 'ACR-029 concurrent create lock-timeout scenario failed: ' . get_class($error) . ': ' . $error->getMessage());
        if (isset($createLockDbA)) {
            $createLockDbA->close();
        }
        if (isset($appDbC)) {
            try {
                acrlMigrationEngineRollbackActiveTransaction($appDbC);
            } catch (Throwable $cleanupError) {
                acrlMigrationEngineCheck(false, 'Could not clear the concurrent create retry transaction: ' . $cleanupError->getMessage());
            }
        }
    }
} catch (Throwable $error) {
    acrlMigrationEngineCheck(false, 'DB-engine scenarios threw ' . get_class($error) . ': ' . $error->getMessage());
} finally {
    if (isset($admin)) {
        foreach ($createdDatabases as $databaseName) {
            try {
                $admin->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
            } catch (Throwable $cleanupError) {
                $failures[] = 'Could not clean disposable schema ' . $databaseName . ': ' . $cleanupError->getMessage();
            }
        }
        if ($createdRuntimeUser !== null) {
            try {
                $admin->exec("DROP USER IF EXISTS '{$createdRuntimeUser}'@'%'");
            } catch (Throwable $cleanupError) {
                $failures[] = 'Could not clean disposable runtime user: ' . $cleanupError->getMessage();
            }
        }
    }
    foreach (glob($temporaryMigrationDir . '/*') ?: [] as $temporaryFile) {
        @unlink($temporaryFile);
    }
    @rmdir($temporaryMigrationDir);
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL migration engine checks failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "PASS: ACRL migration recognition and full-directory runs on this DB engine\n";
