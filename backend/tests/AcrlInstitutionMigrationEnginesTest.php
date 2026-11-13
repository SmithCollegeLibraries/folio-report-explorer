<?php

$engineConfigs = [
    'MySQL 8' => [
        'dsn' => getenv('ACRL073_MIGRATION_TEST_MYSQL_ADMIN_DSN'),
        'user' => getenv('ACRL073_MIGRATION_TEST_MYSQL_ADMIN_USER'),
        'password' => getenv('ACRL073_MIGRATION_TEST_MYSQL_ADMIN_PASSWORD'),
        'expected' => 'mysql',
    ],
    'MariaDB 10.3' => [
        'dsn' => getenv('ACRL073_MIGRATION_TEST_MARIADB_ADMIN_DSN'),
        'user' => getenv('ACRL073_MIGRATION_TEST_MARIADB_ADMIN_USER'),
        'password' => getenv('ACRL073_MIGRATION_TEST_MARIADB_ADMIN_PASSWORD'),
        'expected' => 'mariadb',
    ],
];
$configured = false;
foreach ($engineConfigs as $config) {
    foreach (['dsn', 'user', 'password'] as $key) {
        if ($config[$key] !== false && $config[$key] !== '') $configured = true;
    }
}
if (!$configured) {
    fwrite(STDOUT, "SKIP: ACRL-073 engine tests need disposable MySQL 8 and MariaDB 10.3 admin connections.\n");
    exit(0);
}
if (getenv('ACRL073_MIGRATION_TEST_ALLOW_SCHEMA_CREATE') !== '1') {
    fwrite(STDERR, "FAIL: Set ACRL073_MIGRATION_TEST_ALLOW_SCHEMA_CREATE=1 to authorize disposable schema creation.\n");
    exit(1);
}
foreach ($engineConfigs as $label => $config) {
    foreach (['dsn', 'user', 'password'] as $key) {
        if ($config[$key] === false || $config[$key] === '') {
            fwrite(STDERR, "FAIL: {$label} is partially configured; provide its admin DSN, user, and password.\n");
            exit(1);
        }
    }
    if (preg_match('/(?:^|;)dbname=/i', (string)$config['dsn']) === 1) {
        fwrite(STDERR, "FAIL: {$label} admin DSN must omit dbname so the test can create disposable schemas.\n");
        exit(1);
    }
}

$autoload = __DIR__ . '/../vendor/autoload.php';
$yii = __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
if (!is_file($autoload) || !is_file($yii)) {
    fwrite(STDERR, "FAIL: Install the existing backend Composer dependencies before the DB engine tests.\n");
    exit(1);
}
require_once $autoload;
require_once $yii;
require_once __DIR__ . '/../services/MigrationService.php';
require_once __DIR__ . '/../services/AcrlQuestionAssignmentMigrationRecognitionService.php';
require_once __DIR__ . '/../services/AcrlAnswerRepository.php';
require_once __DIR__ . '/../services/AcrlEntryService.php';
require_once __DIR__ . '/../services/AcrlImportService.php';
require_once __DIR__ . '/../services/AcrlDetailsRepository.php';
require_once __DIR__ . '/../services/AcrlQuestionNotesService.php';
require_once __DIR__ . '/../services/AcrlCatalogueReviewService.php';
require_once __DIR__ . '/../services/AcrlCatalogueService.php';

use app\services\AcrlQuestionAssignmentMigrationRecognitionService;
use app\services\MigrationService;
use app\services\AcrlAnswerRepository;
use app\services\AcrlEntryService;
use app\services\AcrlImportService;
use app\services\AcrlDetailsRepository;
use app\services\AcrlQuestionNotesService;
use app\services\AcrlCatalogueReviewService;
use app\services\AcrlCatalogueService;
use yii\db\Connection;

$migrationPath = __DIR__ . '/../../mysql/migrations/073_acrl_institutions.sql';
$temporaryMigrationDir = sys_get_temp_dir() . '/acrl073-migrations-' . bin2hex(random_bytes(8));
$createdSchemas = [];
$createdUsers = [];
$failures = [];

function acrl073EngineCheck($condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function acrl073EngineSame($expected, $actual, string $message): void
{
    acrl073EngineCheck($expected === $actual, $message
        . "\nExpected: " . var_export($expected, true)
        . "\nActual: " . var_export($actual, true));
}

function acrl073EngineHasActiveTransaction($db): bool
{
    $transaction = $db->getTransaction();
    return $transaction !== null && $transaction->getIsActive();
}

function acrl073EngineRollbackActiveTransaction($db): void
{
    if (acrl073EngineHasActiveTransaction($db)) {
        $db->getTransaction()->rollBack();
    }
}

function acrl073EngineConnection(string $dsn, string $user, string $password): Connection
{
    $db = new Connection(['dsn' => $dsn, 'username' => $user, 'password' => $password, 'charset' => 'utf8mb4']);
    $db->open();
    return $db;
}

function acrl073EngineDsn(string $dsn, string $database): string
{
    return rtrim($dsn, ';') . ';dbname=' . $database;
}

class Acrl073EngineUploadValidator
{
    public function validateUpload($year, $fileName, $sha256, $items): array
    {
        return $items;
    }
}

function acrl073EngineCatalogueService($db): AcrlCatalogueService
{
    return new AcrlCatalogueService(
        $db,
        null,
        new Acrl073EngineUploadValidator(),
        null,
        new AcrlCatalogueReviewService($db, null, null, new stdClass())
    );
}

function acrl073EngineAddRaceQuestion($db, int $year, string $questionId): void
{
    $db->createCommand()->insert('acrl_survey_years', ['survey_year' => $year, 'status' => 'published', 'created_by' => 'ACRL-073 synthetic'])->execute();
    $db->createCommand()->insert('acrl_questions', [
        'survey_year' => $year,
        'sheet' => 'survey',
        'question_id' => $questionId,
        'part' => '',
        'section' => 'Synthetic lock test',
        'prompt' => 'Synthetic lock test question',
        'answer_type' => 'number',
        'required_status' => 'no',
        'survey_order' => 1,
    ])->execute();
}

function acrl073EngineRunRaceWriter($db, string $writer, int $year, string $questionId)
{
    $answers = new AcrlAnswerRepository($db);
    if ($writer === 'answer') {
        $service = new AcrlEntryService($answers, null, null, null, null, new stdClass(), new stdClass());
        return $service->saveAnswer($year, 'survey', $questionId, '', '12', null, null, 'ACRL-073 synthetic', true, 'SC');
    }
    if ($writer === 'answer import') {
        return (new AcrlImportService($answers))->confirm($year, [[
            'sheet' => 'survey', 'question_id' => $questionId, 'part' => '', 'value' => '12',
        ]], 'Synthetic ACRL-073 import', false, 'ACRL-073 synthetic', 'SC');
    }
    $notes = new AcrlQuestionNotesService(new AcrlDetailsRepository($db), $answers);
    if ($writer === 'note') {
        return $notes->addNote($year, 'survey', $questionId, '', 'Synthetic collection note', 'Synthetic ACRL-073', $year, 'ACRL-073 synthetic', 'SC');
    }
    return $notes->confirmImport($year, [[
        'sheet' => 'survey', 'question_id' => $questionId, 'part' => '',
        'note' => 'Synthetic imported collection note', 'source' => 'Synthetic ACRL-073 import', 'origin_year' => $year,
    ]], 'ACRL-073 synthetic', 'SC');
}

function acrl073EngineReplaceQuestion($db, int $year): void
{
    acrl073EngineCatalogueService($db)->importWorksheet($year, 'synthetic.xlsx', str_repeat('a', 64), [], true, 'ACRL-073 synthetic');
}

function acrl073EngineCheckCatalogueLockOrder(string $label, array $config, string $schema, int $year, string $writer): void
{
    $dsn = acrl073EngineDsn((string)$config['dsn'], $schema);
    $writerDb = acrl073EngineConnection($dsn, (string)$config['user'], (string)$config['password']);
    $replacementDb = acrl073EngineConnection($dsn, (string)$config['user'], (string)$config['password']);
    $questionId = 'RACE' . ($year - 2029);
    acrl073EngineAddRaceQuestion($writerDb, $year, $questionId);

    try {
        $writerTransaction = $writerDb->beginTransaction();
        $result = acrl073EngineRunRaceWriter($writerDb, $writer, $year, $questionId);
        acrl073EngineCheck($result !== null, $label . ' ' . $writer . ' writer completes while holding a shared year lock.');
        $replacementDb->createCommand('SET SESSION innodb_lock_wait_timeout = 1')->execute();
        $replacementError = null;
        try {
            acrl073EngineReplaceQuestion($replacementDb, $year);
        } catch (Throwable $error) {
            $replacementError = $error;
        }
        acrl073EngineCheck(
            $replacementError !== null && stripos($replacementError->getMessage(), 'lock wait timeout exceeded') !== false,
            $label . ' replacement waits for the ' . $writer . ' writer transaction before checking dependencies.'
        );
        $writerTransaction->commit();
        $dependencyTable = strpos($writer, 'note') === 0 ? 'acrl_question_notes' : 'acrl_answers';
        acrl073EngineSame(1, (int)$replacementDb->createCommand(
            'SELECT COUNT(*) FROM ' . $dependencyTable . ' WHERE survey_year = :year', [':year' => $year]
        )->queryScalar(), $label . ' writer-first ' . $writer . ' dependency is committed before replacement retries.');
        $dependencyError = null;
        try {
            acrl073EngineReplaceQuestion($replacementDb, $year);
        } catch (Throwable $error) {
            $dependencyError = $error;
        }
        acrl073EngineCheck(
            $dependencyError instanceof DomainException && $dependencyError->getCode() === 409,
            $label . ' replacement rejects the committed ' . $writer . ' dependency after the writer-first interleaving.'
        );
        acrl073EngineSame('published', (string)$replacementDb->createCommand(
            'SELECT status FROM acrl_survey_years WHERE survey_year = :year', [':year' => $year]
        )->queryScalar(), $label . ' refused writer-first replacement preserves the published year.');
        acrl073EngineSame(1, (int)$replacementDb->createCommand(
            'SELECT COUNT(*) FROM acrl_questions WHERE survey_year = :year', [':year' => $year]
        )->queryScalar(), $label . ' refused writer-first replacement preserves the question.');
    } catch (Throwable $error) {
        acrl073EngineCheck(false, $label . ' writer-first ' . $writer . ' interleaving failed: ' . get_class($error) . ': ' . $error->getMessage());
        if (acrl073EngineHasActiveTransaction($writerDb)) acrl073EngineRollbackActiveTransaction($writerDb);
    }
    $writerDb->close();
    $replacementDb->close();

    $writerDb = acrl073EngineConnection($dsn, (string)$config['user'], (string)$config['password']);
    $replacementDb = acrl073EngineConnection($dsn, (string)$config['user'], (string)$config['password']);
    $year += 10;
    $questionId = 'RACE' . ($year - 2029);
    acrl073EngineAddRaceQuestion($replacementDb, $year, $questionId);
    $replacementTransaction = $replacementDb->beginTransaction();
    try {
        acrl073EngineReplaceQuestion($replacementDb, $year);
        $writerDb->createCommand('SET SESSION innodb_lock_wait_timeout = 1')->execute();
        $writerError = null;
        try {
            acrl073EngineRunRaceWriter($writerDb, $writer, $year, $questionId);
        } catch (Throwable $error) {
            $writerError = $error;
        }
        acrl073EngineCheck(
            $writerError !== null && stripos($writerError->getMessage(), 'lock wait timeout exceeded') !== false,
            $label . ' ' . $writer . ' writer waits for replacement to commit before checking or writing.'
        );
        $replacementTransaction->commit();
        $lateWriterError = null;
        try {
            acrl073EngineRunRaceWriter($writerDb, $writer, $year, $questionId);
        } catch (Throwable $error) {
            $lateWriterError = $error;
        }
        acrl073EngineCheck($lateWriterError !== null, $label . ' ' . $writer . ' writer cannot recreate a removed question after replacement commits.');
        acrl073EngineSame(0, (int)$writerDb->createCommand(
            'SELECT COUNT(*) FROM acrl_answers WHERE survey_year = :year', [':year' => $year]
        )->queryScalar(), $label . ' replacement-first ' . $writer . ' interleaving leaves no answer.');
        acrl073EngineSame(0, (int)$writerDb->createCommand(
            'SELECT COUNT(*) FROM acrl_answer_history WHERE survey_year = :year', [':year' => $year]
        )->queryScalar(), $label . ' replacement-first ' . $writer . ' interleaving leaves no answer history.');
        acrl073EngineSame(0, (int)$writerDb->createCommand(
            'SELECT COUNT(*) FROM acrl_question_notes WHERE survey_year = :year', [':year' => $year]
        )->queryScalar(), $label . ' replacement-first ' . $writer . ' interleaving leaves no note.');
        acrl073EngineSame(0, (int)$writerDb->createCommand(
            'SELECT COUNT(*) FROM acrl_questions WHERE survey_year = :year', [':year' => $year]
        )->queryScalar(), $label . ' replacement-first ' . $writer . ' interleaving removes the question only after locking.');
    } catch (Throwable $error) {
        acrl073EngineCheck(false, $label . ' replacement-first ' . $writer . ' interleaving failed: ' . get_class($error) . ': ' . $error->getMessage());
        if (acrl073EngineHasActiveTransaction($replacementDb)) acrl073EngineRollbackActiveTransaction($replacementDb);
        if (acrl073EngineHasActiveTransaction($writerDb)) acrl073EngineRollbackActiveTransaction($writerDb);
    }
    $writerDb->close();
    $replacementDb->close();
}

function acrl073EngineRaw($db, string $sql): void
{
    $db->createCommand()->setRawSql($sql)->execute();
}

function acrl073EngineApplySql($db, string $path): void
{
    foreach (MigrationService::splitSqlStatements((string)file_get_contents($path)) as $statement) {
        acrl073EngineRaw($db, $statement);
    }
}

function acrl073EngineCreateLegacySchema($db, string $migrationDir): void
{
    acrl073EngineRaw($db, 'CREATE TABLE users (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    acrl073EngineRaw($db, "CREATE TABLE acrl_survey_years (survey_year SMALLINT NOT NULL PRIMARY KEY, status VARCHAR(12) NOT NULL DEFAULT 'draft', source_file_name VARCHAR(255) NULL, source_sha256 CHAR(64) NULL, survey_prompt_count INT NOT NULL DEFAULT 0, trends_prompt_count INT NOT NULL DEFAULT 0, survey_item_count INT NOT NULL DEFAULT 0, trends_item_count INT NOT NULL DEFAULT 0, created_by VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, published_by VARCHAR(100) NULL, published_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    acrl073EngineRaw($db, "CREATE TABLE acrl_questions (id INT NOT NULL AUTO_INCREMENT, survey_year SMALLINT NOT NULL, sheet VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, question_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, part VARCHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '', section VARCHAR(120) NOT NULL, prompt TEXT NOT NULL, answer_type VARCHAR(12) NOT NULL, answer_options JSON NULL, required_status VARCHAR(12) NOT NULL, calculated_from TEXT NULL, survey_order INT NOT NULL, PRIMARY KEY(id), UNIQUE KEY uq_acrl_question(survey_year, sheet, question_id, part)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['055_acrl_answers.sql', '058_acrl_section_assignments.sql', '059_acrl_question_details.sql', '066_acrl_question_assignments.sql', '067_acrl_invites.sql'] as $filename) {
        acrl073EngineApplySql($db, $migrationDir . '/' . $filename);
    }
    $db->createCommand()->insert('users', ['id' => 7])->execute();
    $db->createCommand()->insert('acrl_survey_years', ['survey_year' => 2025, 'status' => 'published', 'created_by' => 'ACRL-073 synthetic'])->execute();
    $db->createCommand()->insert('acrl_questions', [
        'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => '',
        'section' => 'Synthetic ACRL-073', 'prompt' => 'Synthetic migration test', 'answer_type' => 'number',
        'required_status' => 'no', 'survey_order' => 1,
    ])->execute();
    $db->createCommand()->insert('acrl_questions', [
        'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => 'A',
        'section' => 'Synthetic ACRL-073', 'prompt' => 'Synthetic default test', 'answer_type' => 'number',
        'required_status' => 'no', 'survey_order' => 2,
    ])->execute();
    $db->createCommand()->insert('acrl_answers', [
        'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => '',
        'value_text' => '12', 'value_number' => 12, 'source' => 'manual', 'updated_by' => 'ACRL-073 synthetic',
    ])->execute();
    $db->createCommand()->insert('acrl_answer_history', [
        'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => '',
        'new_value_text' => '12', 'new_value_number' => 12, 'new_source' => 'manual', 'changed_by' => 'ACRL-073 synthetic',
    ])->execute();
    $db->createCommand()->insert('acrl_section_status', [
        'survey_year' => 2025, 'section' => 'Synthetic ACRL-073', 'status' => 'ready',
    ])->execute();
    $db->createCommand()->insert('acrl_section_assignments', [
        'survey_year' => 2025, 'section' => 'Synthetic ACRL-073', 'user_id' => 7,
    ])->execute();
    $db->createCommand()->insert('acrl_question_assignments', [
        'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => '', 'user_id' => 7,
    ])->execute();
    $db->createCommand()->insert('acrl_question_notes', [
        'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => '',
        'note' => 'Synthetic legacy note', 'source' => 'Synthetic migration fixture', 'origin_year' => 2025, 'created_by' => 'ACRL-073 synthetic',
    ])->execute();
    $db->createCommand()->insert('acrl_invites', [
        'email' => 'acrl073-synthetic@smith.example', 'survey_year' => 2025, 'invited_by' => 7,
        'link_origin' => 'https://example.test', 'status' => 'pending', 'send_count' => 0,
        'expires_at' => date('Y-m-d H:i:s', time() + 86400),
    ])->execute();
}

function acrl073EngineCheckMigratedData($db, string $label): void
{
    foreach (['acrl_answers', 'acrl_answer_history', 'acrl_section_status', 'acrl_section_assignments', 'acrl_question_assignments', 'acrl_question_notes', 'acrl_invites'] as $table) {
        $count = (int)$db->createCommand('SELECT COUNT(*) FROM ' . $table . ' WHERE institution_code = :institution', [':institution' => 'SC'])->queryScalar();
        acrl073EngineSame(1, $count, $label . ' backfills the legacy ' . $table . ' row to SC.');
    }
    acrl073EngineSame(5, (int)$db->createCommand('SELECT COUNT(*) FROM acrl_institutions')->queryScalar(), $label . ' seeds the five supported colleges.');
    $db->createCommand()->insert('acrl_section_assignments', [
        'institution_code' => 'HC', 'survey_year' => 2025, 'section' => 'Synthetic ACRL-073', 'user_id' => 7,
    ])->execute();
    $db->createCommand()->insert('acrl_question_assignments', [
        'institution_code' => 'HC', 'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => '', 'user_id' => 7,
    ])->execute();
    $db->createCommand()->insert('acrl_answers', [
        'institution_code' => 'HC', 'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => '',
        'value_text' => '99', 'value_number' => 99, 'source' => 'manual', 'updated_by' => 'ACRL-073 synthetic',
    ])->execute();
    acrl073EngineSame(2, (int)$db->createCommand('SELECT COUNT(*) FROM acrl_answers WHERE survey_year = 2025 AND sheet = :sheet AND question_id = :question AND part = :part', [':sheet' => 'survey', ':question' => 'ACR73', ':part' => ''])->queryScalar(), $label . ' allows the same answer key at two institutions.');
    acrl073EngineSame(2, (int)$db->createCommand('SELECT COUNT(*) FROM acrl_section_assignments WHERE survey_year = 2025 AND section = :section AND user_id = 7', [':section' => 'Synthetic ACRL-073'])->queryScalar(), $label . ' allows the same section assignment at two institutions.');
    acrl073EngineSame(2, (int)$db->createCommand('SELECT COUNT(*) FROM acrl_question_assignments WHERE survey_year = 2025 AND question_id = :question AND user_id = 7', [':question' => 'ACR73'])->queryScalar(), $label . ' allows the same question assignment at two institutions.');
    acrl073EngineCheckInstitutionWrites($db, $label);
}

function acrl073EngineCheckInstitutionWrites($db, string $label): void
{
    $expires = date('Y-m-d H:i:s', time() + 86400);
    $tables = [
        'acrl_answers' => [
            'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => 'A',
            'value_text' => '1', 'source' => 'manual', 'updated_by' => 'ACRL-073 synthetic',
        ],
        'acrl_answer_history' => [
            'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => 'A',
            'new_source' => 'manual', 'changed_by' => 'ACRL-073 synthetic',
        ],
        'acrl_section_status' => ['survey_year' => 2025, 'section' => 'Synthetic ACRL-073 writes'],
        'acrl_section_assignments' => ['survey_year' => 2025, 'section' => 'Synthetic ACRL-073 writes', 'user_id' => 7],
        'acrl_question_assignments' => [
            'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => 'A', 'user_id' => 7,
        ],
        'acrl_question_notes' => [
            'survey_year' => 2025, 'sheet' => 'survey', 'question_id' => 'ACR73', 'part' => 'A',
            'note' => 'Synthetic institution constraint', 'source' => 'ACRL-073 synthetic', 'origin_year' => 2025,
            'created_by' => 'ACRL-073 synthetic',
        ],
        'acrl_invites' => [
            'email' => 'acrl073-constraint@example.test', 'survey_year' => 2025, 'invited_by' => 7,
            'link_origin' => 'https://example.test', 'expires_at' => $expires,
        ],
    ];
    foreach ($tables as $table => $base) {
        foreach (['omitted', 'NULL', 'unknown'] as $kind) {
            $values = $base;
            if ($kind !== 'omitted') {
                $values = ['institution_code' => $kind === 'NULL' ? null : 'ZZ'] + $values;
            }
            if ($table === 'acrl_section_status' || $table === 'acrl_section_assignments') {
                $values['section'] .= ' ' . $kind;
            }
            if ($table === 'acrl_invites') {
                $values['email'] = 'acrl073-' . strtolower($kind) . '@example.test';
            }
            $rejected = false;
            try {
                $db->createCommand()->insert($table, $values)->execute();
            } catch (Throwable $error) {
                $rejected = true;
            }
            acrl073EngineCheck($rejected, $label . ' rejects ' . $kind . ' institution_code for ' . $table . '.');
        }
    }
}

function acrl073EngineApplyUntil($db, string $migrationPath, int $targetExecute): void
{
    $executed = 0;
    foreach (MigrationService::splitSqlStatements((string)file_get_contents($migrationPath)) as $statement) {
        acrl073EngineRaw($db, $statement);
        if (preg_match('/^EXECUTE\s+acrl073_stmt$/i', trim($statement)) === 1) {
            $executed++;
            if ($executed === $targetExecute) {
                return;
            }
        }
    }
    throw new RuntimeException('The requested ACRL-073 interruption point was not reached.');
}

function acrl073EngineCheckPartialRecovery($admin, array $config, string $label, string $migrationPath, string $temporaryMigrationDir, array &$createdSchemas): void
{
    $ddlSteps = 0;
    foreach (MigrationService::splitSqlStatements((string)file_get_contents($migrationPath)) as $statement) {
        if (preg_match('/^EXECUTE\s+acrl073_stmt$/i', trim($statement)) === 1) $ddlSteps++;
    }
    for ($step = 1; $step < $ddlSteps; $step++) {
        $schema = 'acrl073_cut_' . strtolower(str_replace([' ', '.'], ['', ''], $label)) . '_' . $step . '_' . strtolower(substr(bin2hex(random_bytes(4)), 0, 6));
        $createdSchemas[] = [$admin, $schema];
        acrl073EngineRaw($admin, 'CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $dsn = acrl073EngineDsn((string)$config['dsn'], $schema);
        $db = acrl073EngineConnection($dsn, (string)$config['user'], (string)$config['password']);
        acrl073EngineCreateLegacySchema($db, __DIR__ . '/../../mysql/migrations');
        acrl073EngineApplyUntil($db, $migrationPath, $step);
        $db->close();
        $db = acrl073EngineConnection($dsn, (string)$config['user'], (string)$config['password']);
        acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($db, '066_acrl_question_assignments.sql'), $label . ' 066 accepts interrupted 073 DDL step ' . $step . '.');
        acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($db, '067_acrl_invites.sql'), $label . ' 067 accepts interrupted 073 DDL step ' . $step . '.');
        MigrationService::ensureSchemaTable($db);
        if ($step === 20) {
            $files = MigrationService::discoverMigrationFiles($temporaryMigrationDir);
            $resumed = AcrlQuestionAssignmentMigrationRecognitionService::baselineCurrentSchema($db, $files, false);
            acrl073EngineSame(['073_acrl_institutions.sql'], $resumed['applied'], $label . ' empty-ledger baseline resumes interrupted 073 DDL step ' . $step . '.');
        } else {
            $db->createCommand()->insert('schema_migrations', [
                'filename' => '072_shared_record_check.sql', 'checksum' => str_repeat('0', 64), 'execution_ms' => 0,
            ])->execute();
            $resumed = MigrationService::run($db, $temporaryMigrationDir, false);
            acrl073EngineSame(['073_acrl_institutions.sql'], $resumed['applied'], $label . ' populated-ledger rerun resumes interrupted 073 DDL step ' . $step . '.');
        }
        acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($db, '073_acrl_institutions.sql'), $label . ' recognizes 073 after resuming DDL step ' . $step . '.');
        $db->close();
    }
}

function acrl073EngineRun(string $label, array $config, string $migrationPath, string $temporaryMigrationDir, array &$createdSchemas, array &$createdUsers): void
{
    $admin = acrl073EngineConnection((string)$config['dsn'], (string)$config['user'], (string)$config['password']);
    $version = (string)$admin->createCommand('SELECT VERSION()')->queryScalar();
    if ($config['expected'] === 'mysql') {
        acrl073EngineCheck(stripos($version, 'MariaDB') === false && preg_match('/^8\./', $version) === 1, $label . ' endpoint is MySQL 8.');
    } else {
        acrl073EngineCheck(stripos($version, 'MariaDB') !== false && preg_match('/^10\.3\./', $version) === 1, $label . ' endpoint is MariaDB 10.3.');
    }

    $schema = 'acrl073_' . strtolower(str_replace([' ', '.'], ['', ''], $label)) . '_' . strtolower(substr(bin2hex(random_bytes(8)), 0, 10));
    $createdSchemas[] = [$admin, $schema];
    acrl073EngineRaw($admin, 'CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = acrl073EngineConnection(acrl073EngineDsn((string)$config['dsn'], $schema), (string)$config['user'], (string)$config['password']);
    acrl073EngineCreateLegacySchema($db, __DIR__ . '/../../mysql/migrations');
    acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($db, '066_acrl_question_assignments.sql'), $label . ' 066 recognizes the legacy assignment schema before 073.');
    acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($db, '067_acrl_invites.sql'), $label . ' 067 recognizes the legacy invite schema before 073.');
    acrl073EngineCheck(!AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($db, '073_acrl_institutions.sql'), $label . ' does not recognize the legacy schema as migrated 073.');

    if (!mkdir($temporaryMigrationDir, 0700, true)) throw new RuntimeException('Could not create temporary migration directory.');
    if (!copy($migrationPath, $temporaryMigrationDir . '/073_acrl_institutions.sql')) throw new RuntimeException('Could not stage migration 073.');
    MigrationService::ensureSchemaTable($db);
    $db->createCommand()->insert('schema_migrations', [
        'filename' => '072_shared_record_check.sql', 'checksum' => str_repeat('0', 64), 'execution_ms' => 0,
    ])->execute();
    $run = MigrationService::run($db, $temporaryMigrationDir, false);
    acrl073EngineSame(['073_acrl_institutions.sql'], $run['applied'], $label . ' applies 073 on the existing-ledger path.');
    acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($db, '073_acrl_institutions.sql'), $label . ' recognizes the complete 073 schema.');
    acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($db, '066_acrl_question_assignments.sql'), $label . ' 066 recognizes the institution-aware assignment schema.');
    acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($db, '067_acrl_invites.sql'), $label . ' 067 recognizes the institution-aware invite schema.');
    acrl073EngineCheckMigratedData($db, $label);
    foreach (['answer', 'answer import', 'note', 'note import'] as $index => $writer) {
        acrl073EngineCheckCatalogueLockOrder($label, $config, $schema, 2030 + $index, $writer);
    }
    $rerun = MigrationService::run($db, $temporaryMigrationDir, false);
    acrl073EngineSame(['073_acrl_institutions.sql'], $rerun['skipped'], $label . ' migration runner skips the recorded migration on rerun.');
    acrl073EngineCheckPartialRecovery($admin, $config, $label, $migrationPath, $temporaryMigrationDir, $createdSchemas);

    $db->createCommand('DELETE FROM schema_migrations')->execute();
    $files = MigrationService::discoverMigrationFiles($temporaryMigrationDir);
    $baseline = AcrlQuestionAssignmentMigrationRecognitionService::baselineCurrentSchema($db, $files, false);
    acrl073EngineSame(['073_acrl_institutions.sql'], $baseline['baselined'], $label . ' empty-ledger baseline records a complete 073 schema.');

    $restrictedSchema = $schema . '_restricted';
    $createdSchemas[] = [$admin, $restrictedSchema];
    acrl073EngineRaw($admin, 'CREATE DATABASE `' . $restrictedSchema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $restricted = acrl073EngineConnection(acrl073EngineDsn((string)$config['dsn'], $restrictedSchema), (string)$config['user'], (string)$config['password']);
    acrl073EngineCreateLegacySchema($restricted, __DIR__ . '/../../mysql/migrations');
    $runtimeUser = 'acrl073_' . strtolower(substr(bin2hex(random_bytes(8)), 0, 12));
    $runtimePassword = bin2hex(random_bytes(20));
    $createdUsers[] = [$admin, $runtimeUser];
    acrl073EngineRaw($admin, "CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePassword}'");
    acrl073EngineRaw($admin, "GRANT ALTER, CREATE, INSERT, INDEX, REFERENCES ON `{$restrictedSchema}`.* TO '{$runtimeUser}'@'%'");
    $runtimeDb = acrl073EngineConnection(acrl073EngineDsn((string)$config['dsn'], $restrictedSchema), $runtimeUser, $runtimePassword);
    acrl073EngineApplySql($runtimeDb, $migrationPath);
    acrl073EngineApplySql($runtimeDb, $migrationPath);
    acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($restricted, '073_acrl_institutions.sql'), $label . ' restricted privilege account applies and reruns 073.');
    $restricted->createCommand("ALTER TABLE acrl_answers ALTER COLUMN institution_code SET DEFAULT 'SC'")->execute();
    acrl073EngineCheck(!AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($restricted, '073_acrl_institutions.sql'), $label . ' recognizer rejects a retained migration backfill default.');
    acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($restricted, '066_acrl_question_assignments.sql'), $label . ' 066 accepts a partial 073 column while it retains the backfill default.');
    acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($restricted, '067_acrl_invites.sql'), $label . ' 067 accepts a partial 073 column while it retains the backfill default.');
    acrl073EngineApplySql($runtimeDb, $migrationPath);
    acrl073EngineCheck(AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($restricted, '073_acrl_institutions.sql'), $label . ' restricted rerun resumes the partial default-removal state.');
    $restricted->createCommand('ALTER TABLE acrl_answer_history MODIFY institution_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL')->execute();
    acrl073EngineCheck(!AcrlQuestionAssignmentMigrationRecognitionService::migrationSchemaAppearsComplete($restricted, '073_acrl_institutions.sql'), $label . ' recognizer rejects a nullable institution column.');
    $dropDenied = false;
    try {
        $runtimeDb->createCommand('DROP TABLE acrl_institutions')->execute();
    } catch (Throwable $error) {
        $dropDenied = true;
    }
    acrl073EngineCheck($dropDenied, $label . ' migration account cannot drop tables.');
}

try {
    foreach ($engineConfigs as $label => $config) {
        acrl073EngineRun($label, $config, $migrationPath, $temporaryMigrationDir . '-' . strtolower(str_replace([' ', '.'], ['', ''], $label)), $createdSchemas, $createdUsers);
    }
} catch (Throwable $error) {
    acrl073EngineCheck(false, 'Engine setup or migration test threw ' . get_class($error) . ': ' . $error->getMessage());
} finally {
    foreach (array_reverse($createdUsers) as $created) {
        try { acrl073EngineRaw($created[0], "DROP USER IF EXISTS '{$created[1]}'@'%'"); } catch (Throwable $ignored) { }
    }
    foreach (array_reverse($createdSchemas) as $created) {
        try { acrl073EngineRaw($created[0], 'DROP DATABASE IF EXISTS `' . $created[1] . '`'); } catch (Throwable $ignored) { }
    }
    foreach (glob($temporaryMigrationDir . '-*') ?: [] as $directory) {
        foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($directory);
    }
}

if (!empty($failures)) exit(1);
fwrite(STDOUT, "PASS: ACRL-073 migration engine checks passed for MySQL 8 and MariaDB 10.3.\n");
