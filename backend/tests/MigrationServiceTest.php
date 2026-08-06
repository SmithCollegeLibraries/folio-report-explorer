<?php

$servicePath = __DIR__ . '/../services/MigrationService.php';
$migrationDir = __DIR__ . '/../../mysql/migrations';

if (!file_exists($servicePath)) {
    fwrite(STDERR, "MigrationService is missing at {$servicePath}\n");
    exit(1);
}

require_once $servicePath;

use app\services\MigrationService;

function assertMigrationTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertMigrationSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$schemaSql = MigrationService::schemaMigrationsTableSql();
assertMigrationTrue(strpos($schemaSql, 'CREATE TABLE IF NOT EXISTS schema_migrations') !== false, 'Migration runner should own schema_migrations table creation.');
assertMigrationTrue(strpos($schemaSql, 'checksum') !== false, 'schema_migrations should store migration checksums.');
assertMigrationTrue(strpos($schemaSql, 'filename') !== false, 'schema_migrations should store migration filenames.');

$repoAudit = MigrationService::auditDirectory($migrationDir);
assertMigrationSame([], $repoAudit['duplicateNumbers'], 'Checked-in migrations should not have duplicate numeric prefixes.');
assertMigrationTrue(
    !isset($repoAudit['duplicateNumbers']['040']),
    'Migration 040 must have a unique numeric prefix.'
);

$tempDir = sys_get_temp_dir() . '/migration-service-test-' . uniqid('', true);
mkdir($tempDir, 0775, true);
file_put_contents($tempDir . '/001_create_table.sql', 'CREATE TABLE sample (id INT);');
file_put_contents($tempDir . '/002_add_name.sql', 'ALTER TABLE sample ADD COLUMN name VARCHAR(255);');
file_put_contents($tempDir . '/002_add_code.sql', 'ALTER TABLE sample ADD COLUMN code VARCHAR(20);');

$audit = MigrationService::auditDirectory($tempDir);
assertMigrationSame(['002' => ['002_add_code.sql', '002_add_name.sql']], $audit['duplicateNumbers'], 'Audit should detect duplicate migration numbers.');
assertMigrationTrue(count($audit['nonIdempotentRisks']) >= 2, 'Audit should flag non-idempotent CREATE/ALTER statements.');
assertMigrationSame(['001_create_table.sql', '002_add_code.sql', '002_add_name.sql'], $audit['unapplied'], 'Audit should report unapplied migrations when no ledger rows are supplied.');

$checksumAudit = MigrationService::auditDirectory($tempDir, [
    ['filename' => '001_create_table.sql', 'checksum' => str_repeat('0', 64)],
]);
assertMigrationSame(1, count($checksumAudit['changedChecksums']), 'Audit should detect changed checksums for applied migrations.');
assertMigrationSame('001_create_table.sql', $checksumAudit['changedChecksums'][0]['filename'] ?? null, 'Changed checksum report should identify the changed migration filename.');

$files = MigrationService::discoverMigrationFiles($tempDir);
assertMigrationSame(['001_create_table.sql', '002_add_code.sql', '002_add_name.sql'], array_map(function ($file) {
    return $file['filename'];
}, $files), 'Migration discovery should sort deterministically by filename.');
assertMigrationTrue(strlen($files[0]['checksum']) === 64, 'Migration discovery should compute SHA-256 checksums.');

class MigrationServiceTransactionTestDatabase
{
    public $executed = [];

    public function createCommand($sql = null): MigrationServiceTransactionTestCommand
    {
        if (is_string($sql)) {
            $sql = preg_replace('/\{\{([^{}]+)\}\}/', '`$1`', $sql);
        }
        return new MigrationServiceTransactionTestCommand($this, $sql);
    }
}

class MigrationServiceTransactionTestCommand
{
    private $database;
    private $sql;

    public function __construct(MigrationServiceTransactionTestDatabase $database, $sql)
    {
        $this->database = $database;
        $this->sql = $sql;
    }

    public function setRawSql(string $sql): self
    {
        $this->sql = $sql;
        return $this;
    }

    public function execute(): void
    {
        $this->database->executed[] = $this->sql;
        if ($this->sql === 'FAIL RECONCILIATION') {
            throw new RuntimeException('simulated reconciliation failure');
        }
    }
}

$transactionMigration = $tempDir . '/003_transactional_reconciliation.sql';
file_put_contents($transactionMigration, "ALTER TABLE sample ADD COLUMN help_text TEXT;\nSTART TRANSACTION;\nUPDATE sample SET name = 'changed';\nFAIL RECONCILIATION;\nCOMMIT;\n");
$executeSqlFile = new ReflectionMethod(MigrationService::class, 'executeSqlFile');
$transactionDatabase = new MigrationServiceTransactionTestDatabase();
$transactionFailure = null;
try {
    $executeSqlFile->invoke(null, $transactionDatabase, $transactionMigration);
} catch (ReflectionException $exception) {
    throw $exception;
} catch (Throwable $exception) {
    $transactionFailure = $exception;
}
assertMigrationTrue($transactionFailure instanceof RuntimeException, 'Transactional migration failures must be rethrown.');
assertMigrationSame('simulated reconciliation failure', $transactionFailure->getMessage(), 'Rollback must preserve the original migration failure.');
assertMigrationSame(
    [
        'ALTER TABLE sample ADD COLUMN help_text TEXT',
        'START TRANSACTION',
        "UPDATE sample SET name = 'changed'",
        'FAIL RECONCILIATION',
        'ROLLBACK',
    ],
    $transactionDatabase->executed,
    'Failed explicit transactions must roll back without undoing the preceding DDL statement.'
);

@unlink($transactionMigration);

$structuralTokenMigration = $tempDir . '/004_structural_token.sql';
file_put_contents($structuralTokenMigration, "INSERT INTO sample (template) VALUES ('{{location_from}} {{marc_table}}');\n");
$structuralTokenDatabase = new MigrationServiceTransactionTestDatabase();
$executeSqlFile->invoke(null, $structuralTokenDatabase, $structuralTokenMigration);
assertMigrationSame(
    ["INSERT INTO sample (template) VALUES ('{{location_from}} {{marc_table}}')"],
    $structuralTokenDatabase->executed,
    'Migration SQL must execute raw so reviewed structural tokens are not rewritten as Yii table placeholders.'
);
@unlink($structuralTokenMigration);

class MigrationServiceRetryTestTableSchema
{
    public $columns;

    public function __construct(array $columns = [])
    {
        $this->columns = [];
        foreach ($columns as $column) {
            $this->columns[$column] = new stdClass();
        }
    }
}

class MigrationServiceRetryTestSchema
{
    private $database;

    public function __construct(MigrationServiceRetryTestDatabase $database)
    {
        $this->database = $database;
    }

    public function getTableSchema(string $table, bool $refresh = false)
    {
        $tables = $this->database->tables;
        if (!in_array($table, $tables, true)) {
            return null;
        }

        $columns = $table === 'report_templates' && $this->database->hasHelpText ? ['help_text'] : [];
        return new MigrationServiceRetryTestTableSchema($columns);
    }
}

class MigrationServiceRetryTestDatabase
{
    public $schema;
    public $hasHelpText = false;
    public $reportComplete = false;
    public $ledger = [];
    public $executed = [];
    public $failReconciliationOnce = true;
    public $transactionActive = false;
    public $transactionSnapshot = null;
    public $tables = [
        'schema_migrations',
        'users',
        'query_jobs',
        'report_templates',
        'ai_clarification_events',
        'ai_query_feedback',
        'folio_reference_tables',
        'dashboard_widget_templates',
        'ai_report_generations',
        'ai_report_reviews',
    ];

    public function __construct()
    {
        $this->schema = new MigrationServiceRetryTestSchema($this);
    }

    public function createCommand(string $sql = '', array $params = []): MigrationServiceRetryTestCommand
    {
        return new MigrationServiceRetryTestCommand($this, $sql, $params);
    }
}

class MigrationServiceRetryTestCommand
{
    private $database;
    private $sql;
    private $params;
    private $insertTable;
    private $insertRow;

    public function __construct(MigrationServiceRetryTestDatabase $database, string $sql, array $params)
    {
        $this->database = $database;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function setRawSql(string $sql): self
    {
        $this->sql = $sql;
        $this->params = [];
        return $this;
    }

    public function insert(string $table, array $row): self
    {
        $this->insertTable = $table;
        $this->insertRow = $row;
        return $this;
    }

    public function queryAll(): array
    {
        return $this->database->ledger;
    }

    public function queryScalar(): int
    {
        if (strpos($this->sql, 'FROM report_templates') === false) {
            return 0;
        }

        if (strpos($this->sql, 'sql_template LIKE :series_marker') !== false) {
            return 0;
        }

        $requiresCompleteSeed = strpos($this->sql, 'name = :name') !== false
            && strpos($this->sql, 'sql_template LIKE :sql_marker') !== false
            && strpos($this->sql, 'help_text LIKE :help_marker') !== false;
        return $requiresCompleteSeed && !$this->database->reportComplete ? 0 : 1;
    }

    public function execute(): void
    {
        if ($this->insertTable === 'schema_migrations') {
            $this->database->ledger[] = $this->insertRow;
            return;
        }

        $this->database->executed[] = $this->sql;
        if (strpos($this->sql, 'ALTER TABLE `report_templates`') === 0
            || $this->sql === 'EXECUTE budget_year_fund_report_help_text_stmt') {
            $this->database->hasHelpText = true;
            return;
        }
        if ($this->sql === 'START TRANSACTION') {
            $this->database->transactionActive = true;
            $this->database->transactionSnapshot = $this->database->reportComplete;
            return;
        }
        if (strpos($this->sql, 'SET @budget_year_fund_report_displaced_id') === 0
            && $this->database->failReconciliationOnce) {
            $this->database->failReconciliationOnce = false;
            throw new RuntimeException('simulated post-DDL reconciliation failure');
        }
        if (strpos($this->sql, 'INSERT INTO `report_templates`') === 0) {
            $this->database->reportComplete = true;
            return;
        }
        if ($this->sql === 'ROLLBACK') {
            $this->database->reportComplete = (bool)$this->database->transactionSnapshot;
            $this->database->transactionActive = false;
            return;
        }
        if ($this->sql === 'COMMIT') {
            $this->database->transactionActive = false;
        }
    }
}

$retryMigrationDir = $tempDir . '/retry';
mkdir($retryMigrationDir, 0775, true);
$retryMigrationPath = $retryMigrationDir . '/035_budget_year_fund_report.sql';
file_put_contents($retryMigrationPath, file_get_contents($migrationDir . '/035_budget_year_fund_report.sql'));
$retryDatabase = new MigrationServiceRetryTestDatabase();
$migrationAppearsApplied = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80100) {
    $migrationAppearsApplied->setAccessible(true);
}
assertMigrationTrue(
    $migrationAppearsApplied->invoke(null, $retryDatabase, '039_ask_ai_report_review.sql'),
    'Migration 039 should look applied only when both report persistence tables exist.'
);
$retryDatabase->tables = array_values(array_diff($retryDatabase->tables, ['ai_report_reviews']));
assertMigrationTrue(
    !$migrationAppearsApplied->invoke(null, $retryDatabase, '039_ask_ai_report_review.sql'),
    'Migration 039 must not look applied when the review table is absent.'
);
$retryDatabase->tables[] = 'ai_report_reviews';
$firstFailure = null;
try {
    MigrationService::run($retryDatabase, $retryMigrationDir);
} catch (Throwable $exception) {
    $firstFailure = $exception;
}
assertMigrationSame('simulated post-DDL reconciliation failure', $firstFailure ? $firstFailure->getMessage() : null, 'First migration run should fail after the DDL has committed.');
assertMigrationTrue($retryDatabase->hasHelpText, 'DDL must remain applied after reconciliation rollback.');
assertMigrationTrue(!$retryDatabase->reportComplete, 'Failed reconciliation must leave the exact report row stale after rollback.');
assertMigrationSame([], $retryDatabase->ledger, 'Failed reconciliation must not be recorded as applied.');

$retryResult = MigrationService::run($retryDatabase, $retryMigrationDir);
assertMigrationSame(['035_budget_year_fund_report.sql'], $retryResult['applied'], 'Retry must execute migration 035 reconciliation for an exact-but-stale row.');
assertMigrationSame([], $retryResult['baselined'], 'Retry must not baseline an exact-but-stale row.');
assertMigrationTrue($retryDatabase->reportComplete, 'Retry must restore the complete seeded report definition.');
assertMigrationSame('035_budget_year_fund_report.sql', $retryDatabase->ledger[0]['filename'] ?? null, 'Successful retry must record migration 035.');

@unlink($retryMigrationPath);
@rmdir($retryMigrationDir);

@unlink($tempDir . '/001_create_table.sql');
@unlink($tempDir . '/002_add_name.sql');
@unlink($tempDir . '/002_add_code.sql');
@rmdir($tempDir);

class MarcMigrationRecognitionSchema
{
    public function getTableSchema($table, $refresh = false)
    {
        if ($table !== 'report_templates') {
            return null;
        }

        $schema = new stdClass();
        $schema->columns = ['execution_config' => new stdClass()];
        return $schema;
    }
}

class MarcMigrationRecognitionDatabase
{
    public $schema;
    public $report;

    public function __construct(array $report)
    {
        $this->schema = new MarcMigrationRecognitionSchema();
        $this->report = $report;
    }

    public function createCommand($sql = '', array $params = [])
    {
        return new MarcMigrationRecognitionCommand($this, $sql, $params);
    }
}

class MarcMigrationRecognitionCommand
{
    private $database;
    private $sql;

    public function __construct(MarcMigrationRecognitionDatabase $database, $sql, array $params)
    {
        $this->database = $database;
        $this->sql = $sql;
    }

    public function queryOne()
    {
        if (strpos($this->sql, 'information_schema.COLUMNS') !== false) {
            return ['COLUMN_TYPE' => "enum('acquisitions','cataloging','other')"];
        }
        if (strpos($this->sql, 'FROM report_templates') !== false) {
            return $this->database->report;
        }

        throw new RuntimeException('Unexpected MARC migration recognition query: ' . $this->sql);
    }

    public function queryScalar()
    {
        return 1;
    }
}

function marcMigrationSeedDefinition($migrationPath)
{
    $migration = (string) file_get_contents($migrationPath);
    $matched = preg_match(
        "/VALUES \\(\n  'marc-bibliographic-records-missing-tag',\n  '([^']+)',.*?\n  'cataloging',\n  '(WITH target_instances AS MATERIALIZED \\(.*?LIMIT 100001)',\n  '(\\[.*?\\])',\n  'folio',\n  '(\\{.*?\\})',\n  100000,\n  1,\n  'manual'\n\\)/s",
        $migration,
        $matches
    );
    if ($matched !== 1) {
        throw new RuntimeException('Could not load the MARC migration seed fixture.');
    }

    return [
        'slug' => 'marc-bibliographic-records-missing-tag',
        'name' => $matches[1],
        'category' => 'cataloging',
        'sql_template' => str_replace("''", "'", $matches[2]),
        'parameters' => str_replace("''", "'", $matches[3]),
        'data_source' => 'folio',
        'execution_config' => str_replace("''", "'", $matches[4]),
        'default_limit' => 100000,
        'is_active' => 1,
        'created_by' => 'manual',
    ];
}

$marcMigrationAppearsApplied = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80100) {
    $marcMigrationAppearsApplied->setAccessible(true);
}
$marcSeed = marcMigrationSeedDefinition($migrationDir . '/040_cataloging_marc_missing_tag_report.sql');
assertMigrationTrue(
    $marcMigrationAppearsApplied->invoke(null, new MarcMigrationRecognitionDatabase($marcSeed), '040_cataloging_marc_missing_tag_report.sql'),
    'Migration 040 must recognize the complete reviewed MARC report seed.'
);
assertMigrationTrue(
    $marcMigrationAppearsApplied->invoke(null, new MarcMigrationRecognitionDatabase($marcSeed), '041_restore_cataloging_structural_tokens.sql'),
    'Migration 041 must recognize a report whose structural tokens already match the reviewed contract.'
);
$normalizedParameterSeed = $marcSeed;
$normalizedParameters = json_decode($normalizedParameterSeed['parameters'], true);
foreach ($normalizedParameters as &$parameter) {
    ksort($parameter, SORT_STRING);
}
unset($parameter);
$normalizedParameterSeed['parameters'] = json_encode($normalizedParameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
assertMigrationTrue(
    $marcMigrationAppearsApplied->invoke(null, new MarcMigrationRecognitionDatabase($normalizedParameterSeed), '040_cataloging_marc_missing_tag_report.sql'),
    'Migration 040 must recognize MySQL-normalized JSON object key ordering without accepting parameter drift.'
);
$normalizedExecutionConfigSeed = $marcSeed;
$normalizedExecutionConfig = json_decode($normalizedExecutionConfigSeed['execution_config'], true);
$normalizedIdentifierExport = $normalizedExecutionConfig['identifier_export'];
ksort($normalizedIdentifierExport, SORT_STRING);
$normalizedExecutionConfig['identifier_export'] = $normalizedIdentifierExport;
ksort($normalizedExecutionConfig, SORT_STRING);
$normalizedExecutionConfigSeed['execution_config'] = json_encode($normalizedExecutionConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
assertMigrationTrue(
    $marcMigrationAppearsApplied->invoke(null, new MarcMigrationRecognitionDatabase($normalizedExecutionConfigSeed), '040_cataloging_marc_missing_tag_report.sql'),
    'Migration 040 must recognize reordered execution config object keys without accepting execution-config drift.'
);

foreach ([
    'name' => 'MARC Bibliographic Records Missing Tag',
    'sql_template' => str_replace("AND instance.source = 'MARC'", '', $marcSeed['sql_template']),
    'uuid anti-join' => str_replace('marc_tag.instance_id = target_instances.instance_uuid', 'marc_tag.instance_id = target_instances.instance_hrid', $marcSeed['sql_template']),
    'second limit' => $marcSeed['sql_template'] . "\nLIMIT 100000",
    'parameters' => '[]',
    'execution_config' => '{}',
] as $field => $replacement) {
    $alteredSeed = $marcSeed;
    if ($field === 'uuid anti-join' || $field === 'second limit') {
        $alteredSeed['sql_template'] = $replacement;
    } else {
        $alteredSeed[$field] = $replacement;
    }
    assertMigrationTrue(
        !$marcMigrationAppearsApplied->invoke(null, new MarcMigrationRecognitionDatabase($alteredSeed), '040_cataloging_marc_missing_tag_report.sql'),
        'Migration 040 must not baseline an incomplete MARC report with altered ' . $field . '.'
    );
}

fwrite(STDOUT, "MigrationService test passed\n");
