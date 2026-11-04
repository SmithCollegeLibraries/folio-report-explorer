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

use app\services\MigrationService;
use app\services\AcrlQuestionAssignmentRepository;
use app\services\AcrlQuestionAssignmentService;
use app\services\AcrlSectionAssignmentRepository;
use app\services\AcrlSectionAssignmentService;
use yii\db\Connection;

$failures = [];
$createdDatabases = [];
$createdRuntimeUser = null;
$runtimePassword = bin2hex(random_bytes(20));
$runtimeUser = 'acrl028_' . strtolower(substr(bin2hex(random_bytes(8)), 0, 12));
$migrationDir = __DIR__ . '/../../mysql/migrations';
$temporaryMigrationDir = sys_get_temp_dir() . '/acrl028-migrations-' . bin2hex(random_bytes(8));
$allMigrationFiles = array_map('basename', glob(rtrim($migrationDir, '/') . '/*.sql') ?: []);
sort($allMigrationFiles, SORT_STRING);
$migrationFilesExcept066 = array_values(array_filter($allMigrationFiles, function ($filename) {
    return $filename !== '066_acrl_question_assignments.sql';
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

    return [$adminDb, $dsn];
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
    echo 'DB engine under test: ' . $version . "\n";
    acrlMigrationEngineCheck(
        stripos($version, 'mariadb') !== false ? preg_match('/^10\.3\./', $version) === 1 : preg_match('/^8\./', $version) === 1,
        'Run this script once against MySQL 8.0 and once against MariaDB 10.3; server version was ' . $version . '.'
    );

    $createdRuntimeUser = $runtimeUser;
    $admin->exec("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePassword}'");
    $prefix = 'acrl028_' . strtolower(substr(bin2hex(random_bytes(8)), 0, 10));

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
    acrlMigrationEngineCheck(!in_array('066_acrl_question_assignments.sql', $dryRunA['baselined'], true), 'Scenario (a) must not baseline missing 066.');
    $dryBaselinedA = $dryRunA['baselined'];
    sort($dryBaselinedA, SORT_STRING);
    acrlMigrationEngineSame($migrationFilesExcept066, $dryBaselinedA, 'Scenario (a) dry run must baseline every existing migration other than the missing 066.');
    $resultA = MigrationService::run($appDbA, $migrationDir, false);
    acrlMigrationEngineCheck(in_array('066_acrl_question_assignments.sql', $resultA['applied'], true), 'Scenario (a) must execute and record missing 066.');
    $appliedA = $resultA['applied'];
    sort($appliedA, SORT_STRING);
    acrlMigrationEngineSame(['066_acrl_question_assignments.sql'], $appliedA, 'Scenario (a) must execute only 066 instead of replaying any previously applied migration.');
    $baselinedA = $resultA['baselined'];
    sort($baselinedA, SORT_STRING);
    acrlMigrationEngineSame($migrationFilesExcept066, $baselinedA, 'Scenario (a) must baseline every previously applied migration.');
    $questionCountA = (int)$appDbA->createCommand("SELECT COUNT(*) FROM acrl_questions WHERE survey_year = 2025 AND sheet = 'survey' AND question_id = '60' AND part = 'A'")->queryScalar();
    acrlMigrationEngineSame(0, $questionCountA, 'Scenario (a) must keep the deliberately deleted seed question deleted.');

    $scenarioB = $prefix . '_b';
    list($adminDbB, $dsnB) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioB, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
    $migration066 = (string)file_get_contents($migrationDir . '/066_acrl_question_assignments.sql');
    acrlMigrationEngineRaw($adminDbB, rtrim(trim($migration066), ';'));
    $deletedB = (int)$adminDbB->createCommand("DELETE FROM acrl_questions WHERE survey_year = 2025 AND sheet = 'survey' AND question_id = '60' AND part = 'A'")->execute();
    acrlMigrationEngineSame(1, $deletedB, 'Scenario (b) must remove the same 054 seed before testing a complete 066 schema.');
    $adminDbB->createCommand('DELETE FROM schema_migrations')->execute();
    $appDbB = acrlMigrationEngineConnection($dsnB, $runtimeUser, $runtimePassword);
    acrlMigrationEngineCheck(acrlMigrationEngineApplied($appDbB, '066_acrl_question_assignments.sql'), 'Scenario (b) must recognize 066 only when the expected table, columns, and unique key exist.');
    $dryRunB = MigrationService::run($appDbB, $migrationDir, true);
    acrlMigrationEngineCheck(in_array('066_acrl_question_assignments.sql', $dryRunB['baselined'], true), 'Scenario (b) must baseline complete 066 from an empty ledger.');
    acrlMigrationEngineCheck(!in_array('066_acrl_question_assignments.sql', $dryRunB['applied'], true), 'Scenario (b) must not rerun a complete 066 table.');
    $resultB = MigrationService::run($appDbB, $migrationDir, false);
    acrlMigrationEngineCheck(in_array('066_acrl_question_assignments.sql', $resultB['baselined'], true), 'Scenario (b) must record 066 as baselined.');
    $baselinedB = $resultB['baselined'];
    sort($baselinedB, SORT_STRING);
    acrlMigrationEngineSame($allMigrationFiles, $baselinedB, 'Scenario (b) must baseline every migration when the complete 066 schema is present.');
    acrlMigrationEngineSame([], $resultB['applied'], 'Scenario (b) must execute no migration when 066 is already complete.');
    $questionCountB = (int)$appDbB->createCommand("SELECT COUNT(*) FROM acrl_questions WHERE survey_year = 2025 AND sheet = 'survey' AND question_id = '60' AND part = 'A'")->queryScalar();
    acrlMigrationEngineSame(0, $questionCountB, 'Scenario (b) must keep the deleted seed question deleted.');

    $scenarioC = $prefix . '_c';
    list($adminDbC, $dsnC) = acrlMigrationEnginePrepareCurrentSchema($admin, $adminDsn, $adminUser, $adminPassword === false ? '' : $adminPassword, $scenarioC, $migrationDir, $temporaryMigrationDir, $createdDatabases, $runtimeUser, $runtimePassword);
    $appDbC = acrlMigrationEngineConnection($dsnC, $runtimeUser, $runtimePassword);
    $firstRunC = MigrationService::run($appDbC, $migrationDir, false);
    acrlMigrationEngineSame(['066_acrl_question_assignments.sql'], $firstRunC['applied'], 'Scenario (c) must run only 066 once after a normal ledger through 065.');
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
