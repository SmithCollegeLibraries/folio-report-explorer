<?php

$engines = [
    'MySQL 8' => [
        'dsn' => getenv('ACRL074_MIGRATION_TEST_MYSQL_ADMIN_DSN'),
        'user' => getenv('ACRL074_MIGRATION_TEST_MYSQL_ADMIN_USER'),
        'password' => getenv('ACRL074_MIGRATION_TEST_MYSQL_ADMIN_PASSWORD'),
    ],
    'MariaDB 10.3' => [
        'dsn' => getenv('ACRL074_MIGRATION_TEST_MARIADB_ADMIN_DSN'),
        'user' => getenv('ACRL074_MIGRATION_TEST_MARIADB_ADMIN_USER'),
        'password' => getenv('ACRL074_MIGRATION_TEST_MARIADB_ADMIN_PASSWORD'),
    ],
];
$configured = false;
foreach ($engines as $config) foreach ($config as $value) if ($value !== false && $value !== '') $configured = true;
if (!$configured) {
    fwrite(STDOUT, "SKIP: ACRL-074 migration tests need disposable MySQL 8 and MariaDB 10.3 admin connections.\n");
    exit(0);
}
if (getenv('ACRL074_MIGRATION_TEST_ALLOW_SCHEMA_CREATE') !== '1') {
    fwrite(STDERR, "FAIL: Set ACRL074_MIGRATION_TEST_ALLOW_SCHEMA_CREATE=1 to authorize disposable schema creation.\n");
    exit(1);
}
foreach ($engines as $label => $config) {
    foreach ($config as $value) if ($value === false || $value === '') {
        fwrite(STDERR, "FAIL: {$label} is partially configured; provide its admin DSN, user, and password.\n");
        exit(1);
    }
    if (preg_match('/(?:^|;)dbname=/i', (string)$config['dsn']) === 1) {
        fwrite(STDERR, "FAIL: {$label} admin DSN must omit dbname so disposable schemas can be created.\n");
        exit(1);
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require_once __DIR__ . '/../services/MigrationService.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentMigrationRecognitionService.php';
require_once __DIR__ . '/../services/AcrlInstitutionMembershipService.php';

use app\services\MigrationService;
use app\services\AcrlInstitutionMembershipService;
use app\services\AcrlQuestionAssignmentMigrationRecognitionService;
use yii\db\Connection;

$failures = [];
function acrl074EngineCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) $failures[] = $message;
}

function acrl074EngineConnection(string $dsn, string $user, string $password): Connection
{
    $db = new Connection(['dsn' => $dsn, 'username' => $user, 'password' => $password, 'charset' => 'utf8mb4']);
    $db->open();
    return $db;
}

function acrl074EngineDsn(string $dsn, string $schema): string
{
    return rtrim($dsn, ';') . ';dbname=' . $schema;
}

function acrl074EngineExecMigration($db): void
{
    $path = __DIR__ . '/../../mysql/migrations/074_acrl_institution_members.sql';
    MigrationService::executeMigrationFile($db, [
        'filename' => '074_acrl_institution_members.sql',
        'path' => $path,
    ]);
}

function acrl074EngineCheckRemovalRace(string $label, array $config, string $schema, string $restrictedUser, string $password): void
{
    $admin = acrl074EngineConnection(acrl074EngineDsn((string)$config['dsn'], $schema), (string)$config['user'], (string)$config['password']);
    $writer = acrl074EngineConnection(acrl074EngineDsn((string)$config['dsn'], $schema), $restrictedUser, $password);
    try {
        $admin->createCommand()->insert('acrl_institution_members', [
            'user_id' => 1, 'institution_code' => 'SC', 'role' => 'member', 'granted_by' => null, 'granted_at' => date('Y-m-d H:i:s'),
        ])->execute();
        $writer->createCommand('SET SESSION innodb_lock_wait_timeout = 1')->execute();
        $writerTx = $writer->beginTransaction();
        $eligible = (new AcrlInstitutionMembershipService($writer))->lockAssignmentEligibility(1, 'SC');
        acrl074EngineCheck($eligible, $label . ' assignment-first writer locks an eligible member.');
        $writer->createCommand()->insert('acrl_section_assignments', ['institution_code' => 'SC', 'user_id' => 1])->execute();
        $admin->createCommand('SET SESSION innodb_lock_wait_timeout = 1')->execute();
        $removeTx = $admin->beginTransaction();
        $removeWaited = false;
        try {
            $admin->createCommand("SELECT user_id FROM acrl_institution_members WHERE user_id = 1 AND institution_code = 'SC' FOR UPDATE")->queryOne();
        } catch (Throwable $error) {
            $removeWaited = stripos($error->getMessage(), 'lock wait timeout') !== false;
        }
        acrl074EngineCheck($removeWaited, $label . ' removal-first lock waits while the assignment writer holds the member row.');
        if ($removeTx->isActive) $removeTx->rollBack();
        $writerTx->commit();
        $removeTx = $admin->beginTransaction();
        $admin->createCommand("SELECT user_id FROM acrl_institution_members WHERE user_id = 1 AND institution_code = 'SC' FOR UPDATE")->queryOne();
        $admin->createCommand()->delete('acrl_institution_members', ['user_id' => 1, 'institution_code' => 'SC'])->execute();
        $admin->createCommand()->delete('acrl_section_assignments', ['user_id' => 1, 'institution_code' => 'SC'])->execute();
        $removeTx->commit();
        acrl074EngineCheck((int)$admin->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE user_id = 1')->queryScalar() === 0,
            $label . ' assignment-first removal deletes the committed assignment.');

        $admin->createCommand()->insert('acrl_institution_members', [
            'user_id' => 1, 'institution_code' => 'SC', 'role' => 'member', 'granted_by' => null, 'granted_at' => date('Y-m-d H:i:s'),
        ])->execute();
        $removeTx = $admin->beginTransaction();
        $admin->createCommand("SELECT user_id FROM acrl_institution_members WHERE user_id = 1 AND institution_code = 'SC' FOR UPDATE")->queryOne();
        $admin->createCommand()->delete('acrl_institution_members', ['user_id' => 1, 'institution_code' => 'SC'])->execute();
        $writerTx = $writer->beginTransaction();
        $writerWaited = false;
        try {
            (new AcrlInstitutionMembershipService($writer))->lockAssignmentEligibility(1, 'SC');
        } catch (Throwable $error) {
            $writerWaited = stripos($error->getMessage(), 'lock wait timeout') !== false;
        }
        acrl074EngineCheck($writerWaited, $label . ' member-first removal blocks an assignment writer on the locked row.');
        if ($writerTx->isActive) $writerTx->rollBack();
        $admin->createCommand()->delete('acrl_section_assignments', ['user_id' => 1, 'institution_code' => 'SC'])->execute();
        $removeTx->commit();
        $writerTx = $writer->beginTransaction();
        $eligible = (new AcrlInstitutionMembershipService($writer))->lockAssignmentEligibility(1, 'SC');
        $writerTx->commit();
        acrl074EngineCheck(!$eligible && (int)$admin->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE user_id = 1')->queryScalar() === 0,
            $label . ' removal-first retry sees no membership and writes no assignment.');
    } finally {
        $writer->close();
        $admin->close();
    }
}

foreach ($engines as $label => $config) {
    $admin = acrl074EngineConnection((string)$config['dsn'], (string)$config['user'], (string)$config['password']);
    $schema = 'acrl074_' . bin2hex(random_bytes(6));
    $user = 'acrl074_' . bin2hex(random_bytes(5));
    $password = bin2hex(random_bytes(16));
    $schemaCreated = false;
    $userCreated = false;
    try {
        $admin->createCommand('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')->execute();
        $schemaCreated = true;
        $db = acrl074EngineConnection(acrl074EngineDsn((string)$config['dsn'], $schema), (string)$config['user'], (string)$config['password']);
        $db->createCommand("CREATE TABLE users (id INT NOT NULL PRIMARY KEY, email VARCHAR(255) NULL, role VARCHAR(50) NOT NULL DEFAULT 'user', is_approved TINYINT(1) NOT NULL, default_campus VARCHAR(50) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")->execute();
        $db->createCommand('CREATE TABLE acrl_institutions (code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $db->createCommand()->insert('acrl_institutions', ['code' => 'SC'])->execute();
        $db->createCommand('CREATE TABLE acrl_section_assignments (institution_code CHAR(2) NOT NULL, user_id INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $db->createCommand('CREATE TABLE acrl_question_assignments (institution_code CHAR(2) NOT NULL, user_id INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $db->createCommand('CREATE TABLE acrl_invites (institution_code CHAR(2) NOT NULL, accepted_user_id INT NULL, invited_by INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $db->createCommand()->batchInsert('users', ['id', 'email', 'is_approved', 'default_campus'], [
            [1, 'smith@smith.edu', 1, 'Smith College'],
            [2, 'amherst@amherst.edu', 1, 'Smith College'],
            [3, 'unapproved@smith.edu', 0, 'Smith College'],
            [4, null, 1, 'Other'], [5, null, 1, 'Other'], [6, null, 1, 'Other'],
            [7, null, 1, 'Other'], [8, null, 0, 'Other'],
        ])->execute();
        $db->createCommand()->insert('acrl_section_assignments', ['institution_code' => 'SC', 'user_id' => 4])->execute();
        $db->createCommand()->insert('acrl_question_assignments', ['institution_code' => 'SC', 'user_id' => 5])->execute();
        $db->createCommand()->batchInsert('acrl_invites', ['institution_code', 'accepted_user_id', 'invited_by'], [
            ['SC', 6, 7], ['SC', null, 7], ['SC', 8, 7],
        ])->execute();

        $admin->createCommand("CREATE USER '" . $user . "'@'%' IDENTIFIED BY '" . $password . "'")->execute();
        $userCreated = true;
        $admin->createCommand("GRANT CREATE, SELECT, INSERT, REFERENCES ON `" . $schema . "`.* TO '" . $user . "'@'%'")->execute();
        $restricted = acrl074EngineConnection(acrl074EngineDsn((string)$config['dsn'], $schema), $user, $password);
        $file = file_get_contents(__DIR__ . '/../../mysql/migrations/074_acrl_institution_members.sql');
        $statements = array_values(array_filter(array_map('trim', explode(';', (string)$file))));
        $restricted->createCommand($statements[0])->execute();
        $restricted->createCommand($statements[1])->execute();
        $admin->createCommand('CREATE TRIGGER `' . $schema . '`.fail_backfill_marker BEFORE INSERT ON `' . $schema . '`.acrl_institution_member_backfills FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'synthetic marker failure\'')->execute();
        $failed = false;
        try {
            acrl074EngineExecMigration($restricted);
        } catch (Throwable $error) {
            $failed = true;
        }
        acrl074EngineCheck($failed, $label . ' marker failure interrupts the backfill transaction.');
        acrl074EngineCheck((int)$restricted->createCommand('SELECT COUNT(*) FROM acrl_institution_members')->queryScalar() === 0
            && (int)$restricted->createCommand('SELECT COUNT(*) FROM acrl_institution_member_backfills')->queryScalar() === 0,
            $label . ' interruption rolls back membership and marker together.');
        $admin->createCommand('DROP TRIGGER `' . $schema . '`.fail_backfill_marker')->execute();
        acrl074EngineExecMigration($restricted);
        $eligible = $restricted->createCommand('SELECT user_id FROM acrl_institution_members ORDER BY user_id')->queryColumn();
        acrl074EngineCheck(array_map('intval', $eligible) === [1, 4, 5, 6], $label . ' backfill includes approved email, section, question, and accepted-invite users only.');
        acrl074EngineCheck((int)$restricted->createCommand("SELECT COUNT(*) FROM acrl_institution_member_backfills WHERE institution_code = 'SC'")->queryScalar() === 1,
            $label . ' records the SC completion marker.');
        $db->createCommand("DELETE FROM acrl_institution_members WHERE institution_code = 'SC'")->execute();
        acrl074EngineExecMigration($restricted);
        acrl074EngineCheck((int)$restricted->createCommand('SELECT COUNT(*) FROM acrl_institution_members')->queryScalar() === 0,
            $label . ' recognition and rerun do not regrant members after removal.');
        MigrationService::ensureSchemaTable($restricted);
        $migrationPath = __DIR__ . '/../../mysql/migrations/074_acrl_institution_members.sql';
        $baseline = AcrlQuestionAssignmentMigrationRecognitionService::baselineCurrentSchema($restricted, [[
            'filename' => '074_acrl_institution_members.sql',
            'path' => $migrationPath,
            'checksum' => hash_file('sha256', $migrationPath),
        ]], false);
        acrl074EngineCheck($baseline['baselined'] === ['074_acrl_institution_members.sql']
            && (int)$restricted->createCommand('SELECT COUNT(*) FROM acrl_institution_members')->queryScalar() === 0,
            $label . ' empty-ledger baseline recognizes the marker without regranting removed members.');
        $db->createCommand('DELETE FROM acrl_institution_member_backfills')->execute();
        $db->createCommand('UPDATE users SET email = NULL')->execute();
        $db->createCommand('DELETE FROM acrl_section_assignments')->execute();
        $db->createCommand('DELETE FROM acrl_question_assignments')->execute();
        $db->createCommand('DELETE FROM acrl_invites')->execute();
        acrl074EngineExecMigration($restricted);
        acrl074EngineCheck((int)$restricted->createCommand('SELECT COUNT(*) FROM acrl_institution_members')->queryScalar() === 0
            && (int)$restricted->createCommand('SELECT COUNT(*) FROM acrl_institution_member_backfills')->queryScalar() === 1,
            $label . ' completes the backfill marker with zero eligible users.');
        acrl074EngineCheckRemovalRace($label, $config, $schema, $user, $password);
        $restricted->close();
        $db->close();
    } catch (Throwable $error) {
        acrl074EngineCheck(false, $label . ' migration test failed: ' . get_class($error) . ': ' . $error->getMessage());
    } finally {
        if (isset($restricted)) $restricted->close();
        if (isset($db)) $db->close();
        if ($userCreated) {
            try { $admin->createCommand("DROP USER IF EXISTS '" . $user . "'@'%'")->execute(); } catch (Throwable $ignored) {}
        }
        if ($schemaCreated) {
            try { $admin->createCommand('DROP DATABASE IF EXISTS `' . $schema . '`')->execute(); } catch (Throwable $ignored) {}
        }
        $admin->close();
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " ACRL-074 migration engine checks failed:\n");
    foreach ($failures as $failure) fwrite(STDERR, ' - ' . $failure . "\n");
    exit(1);
}
echo "PASS: ACRL-074 migration on MySQL 8 and MariaDB 10.3\n";
