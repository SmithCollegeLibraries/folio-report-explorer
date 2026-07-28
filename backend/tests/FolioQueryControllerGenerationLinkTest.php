<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use app\services\AdministratorReviewService;
use yii\web\Application;
use yii\web\IdentityInterface;

final class GenerationLinkPreflightDb extends yii\db\Connection
{
    public $commandCount = 0;

    public function createCommand($sql = null, $params = [])
    {
        $this->commandCount++;
        return parent::createCommand($sql, $params);
    }
}

final class GenerationLinkIdentity implements IdentityInterface
{
    private $id;

    public function __construct($id) { $this->id = (int)$id; }
    public static function findIdentity($id) { return new self($id); }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return $this->id; }
    public function getAuthKey() { return null; }
    public function validateAuthKey($authKey) { return false; }
}

new Application([
    'id' => 'folio-query-generation-link-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => GenerationLinkPreflightDb::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'generation-link-test'],
        'user' => ['identityClass' => GenerationLinkIdentity::class, 'enableSession' => false],
    ],
    'params' => [
        'maxQueryRows' => 100,
        'queryTimeoutMs' => 1800000,
        'derivedPath' => dirname(__DIR__) . '/data/folio_derived_tables.json',
    ],
]);
Yii::$app->errorHandler->unregister();
Yii::$app->user->setIdentity(new GenerationLinkIdentity(7));

$db = Yii::$app->db;
$db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    sql_text TEXT NOT NULL,
    sql_hash VARCHAR(64),
    params TEXT,
    source VARCHAR(20),
    data_source VARCHAR(20),
    name TEXT,
    user_id INTEGER,
    status VARCHAR(20),
    progress_message VARCHAR(255),
    output_mode VARCHAR(20),
    export_file_path VARCHAR(500),
    estimated_rows INTEGER,
    estimated_cost DECIMAL(20, 4),
    pg_backend_pid INTEGER,
    metadata TEXT,
    result_columns TEXT,
    result_rows TEXT,
    row_count INTEGER,
    execution_time_ms INTEGER,
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME,
    completed_at DATETIME
)
SQL
)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_generations (
    id VARCHAR(36) PRIMARY KEY,
    conversation_id VARCHAR(36) NOT NULL,
    parent_generation_id VARCHAR(36),
    query_job_id VARCHAR(36),
    user_id INTEGER,
    prompt_fingerprint VARCHAR(16) NOT NULL,
    original_question TEXT NOT NULL,
    follow_up_context TEXT,
    response_mode VARCHAR(32),
    execution_mode VARCHAR(32),
    route VARCHAR(128),
    route_reason VARCHAR(255),
    validation_status VARCHAR(32),
    generated_sql TEXT,
    sql_hash VARCHAR(64),
    assumptions_json TEXT,
    user_notice_json TEXT,
    confidence_evidence_json TEXT NOT NULL,
    initial_structure_json TEXT,
    final_structure_json TEXT,
    provenance_json TEXT NOT NULL,
    review_required INTEGER NOT NULL DEFAULT 0,
    review_reasons_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    linked_at DATETIME,
    updated_at DATETIME NOT NULL
)
SQL
)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_reviews (
    id VARCHAR(36) PRIMARY KEY,
    generation_id VARCHAR(36) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL,
    disposition VARCHAR(40),
    advisory_state VARCHAR(20) NOT NULL,
    superseded_by_job_id VARCHAR(36),
    administrator_notes TEXT,
    reviewed_by INTEGER,
    claimed_at DATETIME,
    resolved_at DATETIME,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
)
SQL
)->execute();

function generationLinkAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function seedGeneration(AdministratorReviewService $service, $userId, $sql, array $overrides = [])
{
    return $service->recordGeneration(array_replace([
        'userId' => $userId,
        'promptFingerprint' => '0123456789abcdef',
        'originalQuestion' => 'Show the report',
        'mode' => 'exploratory',
        'executionMode' => 'exploratory',
        'route' => 'gemini',
        'routeReason' => 'long_tail',
        'validationStatus' => 'validated',
        'generatedSql' => $sql,
        'sqlHash' => hash('sha256', $sql),
        'confidenceEvidence' => ['classification' => 'reviewable'],
        'provenance' => ['model' => 'gemini-2.5-pro', 'schemaArtifact' => 'schema-v4'],
        'reviewRequired' => false,
        'reviewReasons' => [],
    ], $overrides));
}

function submitGenerationLinkedQuery(array $body)
{
    Yii::$app->request->setBodyParams($body);
    Yii::$app->response->statusCode = 200;
    return (new FolioQueryController('folio-query', Yii::$app))->actionQuerySubmit();
}

function captureGenerationLinkedQuery(array $body)
{
    try {
        return [
            'result' => submitGenerationLinkedQuery($body),
            'statusCode' => Yii::$app->response->statusCode,
            'exception' => null,
        ];
    } catch (\Throwable $exception) {
        return [
            'result' => null,
            'statusCode' => Yii::$app->response->statusCode,
            'exception' => $exception,
        ];
    }
}

function generationLinkPersistenceSnapshot($db)
{
    return [
        'jobs' => $db->createCommand(
            'SELECT * FROM query_jobs ORDER BY id'
        )->queryAll(),
        'generations' => $db->createCommand(
            'SELECT * FROM ai_report_generations ORDER BY id'
        )->queryAll(),
        'reviews' => $db->createCommand(
            'SELECT * FROM ai_report_reviews ORDER BY id'
        )->queryAll(),
    ];
}

function generationLinkCollectAtomicityFailure(
    $condition,
    $message,
    array &$failures
) {
    if (!$condition) {
        $failures[] = $message;
    }
}

$service = new AdministratorReviewService($db);
$exactSql = 'SELECT 1 AS exact_value';
$exact = seedGeneration($service, 7, $exactSql, [
    'reviewRequired' => true,
    'reviewReasons' => ['documented_default'],
]);
$exactFirstResult = submitGenerationLinkedQuery([
    'sql' => $exactSql,
    'source' => 'nl',
    'dataSource' => 'local',
    'generationId' => $exact['generationId'],
    'provenance' => ['model' => 'client-forged'],
]);
generationLinkAssert(Yii::$app->response->statusCode === 202, 'An owned exact generation should submit successfully.');
$exactSecondResult = submitGenerationLinkedQuery([
    'sql' => $exactSql,
    'source' => 'nl',
    'dataSource' => 'local',
    'generationId' => $exact['generationId'],
]);
generationLinkAssert(Yii::$app->response->statusCode === 202, 'An owned exact generation should support a second execution.');
$exactRow = $db->createCommand('SELECT * FROM ai_report_generations WHERE id = :id', [':id' => $exact['generationId']])->queryOne();
generationLinkAssert(
    $exactRow['query_job_id'] === null,
    'The reviewed source generation must remain stable instead of being overwritten by exact reruns.'
);
$exactChildren = $db->createCommand(
    'SELECT * FROM ai_report_generations WHERE parent_generation_id = :parentId ORDER BY created_at, id',
    [':parentId' => $exact['generationId']]
)->queryAll();
generationLinkAssert(count($exactChildren) === 2, 'Each exact execution must create its own linked child generation.');
$linkedExactJobIds = array_values(array_unique(array_column($exactChildren, 'query_job_id')));
$expectedExactJobIds = [$exactFirstResult['jobId'], $exactSecondResult['jobId']];
sort($linkedExactJobIds, SORT_STRING);
sort($expectedExactJobIds, SORT_STRING);
generationLinkAssert(
    $linkedExactJobIds === $expectedExactJobIds,
    'Both exact rerun jobs must retain independent generation links.'
);
generationLinkAssert(
    (int)$db->createCommand(
        'SELECT COUNT(*) FROM ai_report_reviews WHERE generation_id IN (:first, :second)',
        [':first' => $exactChildren[0]['id'], ':second' => $exactChildren[1]['id']]
    )->queryScalar() === 0,
    'Execution-link children must reuse the source review instead of creating duplicate administrator work.'
);
$exactFirstChild = $db->createCommand(
    'SELECT * FROM ai_report_generations WHERE query_job_id = :jobId',
    [':jobId' => $exactFirstResult['jobId']]
)->queryOne();
$exactMetadata = json_decode((string)$db->createCommand('SELECT metadata FROM query_jobs WHERE id = :id', [':id' => $exactFirstResult['jobId']])->queryScalar(), true);
generationLinkAssert(($exactMetadata['askAiProvenance']['generationId'] ?? null) === $exactFirstChild['id'], 'Job metadata must identify its unique execution child.');
generationLinkAssert(($exactMetadata['askAiProvenance']['sourceGenerationId'] ?? null) === $exact['generationId'], 'Job metadata must identify the reviewed source generation.');
generationLinkAssert(($exactMetadata['askAiProvenance']['reviewRequired'] ?? null) === true, 'Job metadata must retain the source generation review signal.');
generationLinkAssert(($exactMetadata['askAiProvenance']['provenance']['model'] ?? null) === 'gemini-2.5-pro', 'Job metadata must copy server-stored provenance.');
generationLinkAssert(($exactMetadata['askAiProvenance']['provenance']['model'] ?? null) !== 'client-forged', 'Job metadata must ignore client provenance.');

$editedParent = seedGeneration($service, 7, 'SELECT 2 AS original_value');
$editedResult = submitGenerationLinkedQuery([
    'sql' => 'SELECT 3 AS edited_value',
    'source' => 'nl',
    'dataSource' => 'local',
    'generationId' => $editedParent['generationId'],
]);
$editedChild = $db->createCommand(
    'SELECT * FROM ai_report_generations WHERE parent_generation_id = :parentId',
    [':parentId' => $editedParent['generationId']]
)->queryOne();
generationLinkAssert($editedChild !== false, 'Edited SQL must create a derivative generation.');
generationLinkAssert($editedChild['conversation_id'] === $editedParent['conversationId'], 'An edited derivative must retain the trusted conversation.');
generationLinkAssert($editedChild['route_reason'] === 'user_edited_sql', 'An edited derivative must use the user_edited_sql route reason.');
generationLinkAssert((int)$editedChild['review_required'] === 1, 'An edited derivative must require review.');
generationLinkAssert($editedChild['query_job_id'] === $editedResult['jobId'], 'The job must link to the edited derivative, not its parent.');
generationLinkAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_reviews WHERE generation_id = :id', [':id' => $editedChild['id']])->queryScalar() === 1, 'An edited derivative must create its review row.');

$missingJobSql = 'SELECT 31 AS missing_job_value';
$missingJobParent = seedGeneration($service, 7, $missingJobSql);
$missingJobExecution = $service->resolveExecutionGeneration(
    $missingJobParent['generationId'],
    7,
    $missingJobSql
);
$beforeMissingJobLink = generationLinkPersistenceSnapshot($db);
$missingJobRejected = false;
try {
    $service->linkExecutionGeneration(
        $missingJobExecution['generation'],
        'missing-query-job',
        $missingJobExecution['provenanceGeneration']
    );
} catch (\RuntimeException $exception) {
    $missingJobRejected = true;
}
generationLinkAssert(
    $missingJobRejected,
    'A missing query job must fail generation linking instead of committing a phantom link.'
);
generationLinkAssert(
    generationLinkPersistenceSnapshot($db) === $beforeMissingJobLink,
    'A failed missing-job link must leave generation, review, and job state unchanged.'
);

$beforeJobs = (int)$db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar();
Yii::$app->folioDb->commandCount = 0;
$unknownPolicyBlocked = submitGenerationLinkedQuery([
    'sql' => 'DELETE FROM inventory.items',
    'source' => 'nl',
    'dataSource' => 'folio',
    'generationId' => 'unknown-policy-generation',
]);
generationLinkAssert(Yii::$app->response->statusCode === 403, 'Unknown generation ownership must be checked before SQL policy.');
generationLinkAssert(($unknownPolicyBlocked['error'] ?? null) === 'This generated query is not available for execution.', 'Unknown generation ownership must return the stable generation 403 even for blocked SQL.');
generationLinkAssert(Yii::$app->folioDb->commandCount === 0, 'Unknown generation ownership must not reach FOLIO preflight.');
generationLinkAssert((int)$db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar() === $beforeJobs, 'Unknown policy-blocked generation input must not create a job.');

$otherUserPreflight = seedGeneration($service, 8, 'SELECT 6 AS preflight_value');
$beforeJobs = (int)$db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar();
Yii::$app->folioDb->commandCount = 0;
$otherUserPreflightBlocked = submitGenerationLinkedQuery([
    'sql' => 'SELECT 6 AS preflight_value',
    'source' => 'nl',
    'dataSource' => 'folio',
    'generationId' => $otherUserPreflight['generationId'],
]);
generationLinkAssert(Yii::$app->response->statusCode === 403, 'Other-user generation ownership must be checked before FOLIO preflight.');
generationLinkAssert(($otherUserPreflightBlocked['error'] ?? null) === 'This generated query is not available for execution.', 'Other-user generation ownership must win over preflight errors.');
generationLinkAssert(Yii::$app->folioDb->commandCount === 0, 'Other-user generation ownership must not issue preflight commands.');
generationLinkAssert((int)$db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar() === $beforeJobs, 'Other-user preflight input must not create a job.');

$validPolicyGeneration = seedGeneration($service, 7, 'DELETE FROM inventory.items');
$validPolicyBlocked = submitGenerationLinkedQuery([
    'sql' => 'DELETE FROM inventory.items',
    'source' => 'nl',
    'dataSource' => 'folio',
    'generationId' => $validPolicyGeneration['generationId'],
]);
generationLinkAssert(($validPolicyBlocked['error'] ?? null) === 'This query is blocked by reporting data policy.', 'A valid owned generation must still reach normal SQL policy validation.');

$validPreflightGeneration = seedGeneration($service, 7, 'SELECT 7 AS preflight_value');
Yii::$app->folioDb->commandCount = 0;
$validPreflightBlocked = submitGenerationLinkedQuery([
    'sql' => 'SELECT 7 AS preflight_value',
    'source' => 'nl',
    'dataSource' => 'folio',
    'generationId' => $validPreflightGeneration['generationId'],
]);
generationLinkAssert(Yii::$app->response->statusCode === 422, 'A valid owned generation must still reach FOLIO preflight behavior.');
generationLinkAssert(Yii::$app->folioDb->commandCount > 0, 'A valid owned generation must issue FOLIO preflight commands.');

foreach (['unknown-generation', seedGeneration($service, 8, 'SELECT 4')['generationId']] as $unownedId) {
    $beforeJobs = (int)$db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar();
    $blocked = submitGenerationLinkedQuery([
        'sql' => 'SELECT 4',
        'source' => 'nl',
        'dataSource' => 'local',
        'generationId' => $unownedId,
    ]);
    generationLinkAssert(Yii::$app->response->statusCode === 403, 'Unknown and other-user generation IDs must be forbidden for NL execution.');
    generationLinkAssert(($blocked['error'] ?? null) === 'This generated query is not available for execution.', 'Forbidden generation responses must use safe copy.');
    generationLinkAssert((int)$db->createCommand('SELECT COUNT(*) FROM query_jobs')->queryScalar() === $beforeJobs, 'A forbidden generation must not create a job.');
}

foreach (['manual', 'builder'] as $source) {
    $result = submitGenerationLinkedQuery([
        'sql' => 'SELECT 5',
        'source' => $source,
        'dataSource' => 'local',
        'generationId' => 'ignored-for-non-nl',
    ]);
    generationLinkAssert(Yii::$app->response->statusCode === 202 && !empty($result['jobId']), ucfirst($source) . ' submissions must remain unchanged.');
}

$atomicityFailures = [];

$policyFailureSource = seedGeneration(
    $service,
    7,
    'SELECT 81 AS policy_original'
);
$beforePolicyFailure = generationLinkPersistenceSnapshot($db);
$policyFailure = captureGenerationLinkedQuery([
    'sql' => 'DELETE FROM inventory.items',
    'source' => 'nl',
    'dataSource' => 'folio',
    'generationId' => $policyFailureSource['generationId'],
]);
generationLinkCollectAtomicityFailure(
    $policyFailure['exception'] === null
        && $policyFailure['statusCode'] === 403
        && ($policyFailure['result']['error'] ?? null)
            === 'This query is blocked by reporting data policy.'
        && generationLinkPersistenceSnapshot($db) === $beforePolicyFailure,
    'Policy rejection changed generation, review, or job persistence.',
    $atomicityFailures
);

$preflightFailureSource = seedGeneration(
    $service,
    7,
    'SELECT 82 AS preflight_original'
);
$beforePreflightFailure = generationLinkPersistenceSnapshot($db);
$preflightFailure = captureGenerationLinkedQuery([
    'sql' => 'SELECT 83 AS preflight_edited',
    'source' => 'nl',
    'dataSource' => 'folio',
    'generationId' => $preflightFailureSource['generationId'],
]);
generationLinkCollectAtomicityFailure(
    $preflightFailure['exception'] === null
        && $preflightFailure['statusCode'] === 422
        && ($preflightFailure['result']['error'] ?? null)
            === 'Query validation failed before execution.'
        && generationLinkPersistenceSnapshot($db) === $beforePreflightFailure,
    'Preflight failure changed generation, review, or job persistence.',
    $atomicityFailures
);

$jobSaveFailureSource = seedGeneration(
    $service,
    7,
    'SELECT 84 AS save_original'
);
$db->pdo->exec(<<<'SQL'
CREATE TRIGGER fail_query_job_insert
BEFORE INSERT ON query_jobs
BEGIN
    SELECT RAISE(ABORT, 'forced query job save failure');
END
SQL
);
$beforeJobSaveFailure = generationLinkPersistenceSnapshot($db);
$jobSaveFailure = captureGenerationLinkedQuery([
    'sql' => 'SELECT 85 AS save_edited',
    'source' => 'nl',
    'dataSource' => 'local',
    'generationId' => $jobSaveFailureSource['generationId'],
]);
$afterJobSaveFailure = generationLinkPersistenceSnapshot($db);
$db->createCommand('DROP TRIGGER fail_query_job_insert')->execute();
generationLinkCollectAtomicityFailure(
    $jobSaveFailure['exception'] === null
        && $jobSaveFailure['statusCode'] === 500
        && ($jobSaveFailure['result']['error'] ?? null)
            === 'Failed to create job'
        && $afterJobSaveFailure === $beforeJobSaveFailure,
    'Query-job save failure changed persistence or escaped the stable 500 response.',
    $atomicityFailures
);

$linkFailureSource = seedGeneration(
    $service,
    7,
    'SELECT 86 AS link_original'
);
$db->pdo->exec(<<<'SQL'
CREATE TRIGGER fail_generation_link
BEFORE UPDATE OF query_job_id ON ai_report_generations
WHEN NEW.query_job_id IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'forced generation link failure');
END
SQL
);
$beforeLinkFailure = generationLinkPersistenceSnapshot($db);
$linkFailure = captureGenerationLinkedQuery([
    'sql' => 'SELECT 87 AS link_edited',
    'source' => 'nl',
    'dataSource' => 'local',
    'generationId' => $linkFailureSource['generationId'],
]);
$afterLinkFailure = generationLinkPersistenceSnapshot($db);
$db->createCommand('DROP TRIGGER fail_generation_link')->execute();
generationLinkCollectAtomicityFailure(
    $linkFailure['exception'] === null
        && $linkFailure['statusCode'] === 500
        && ($linkFailure['result']['error'] ?? null)
            === 'Failed to create job'
        && $afterLinkFailure === $beforeLinkFailure,
    'Generation-link failure changed persistence or escaped the stable 500 response.',
    $atomicityFailures
);

if ($atomicityFailures !== []) {
    fwrite(
        STDERR,
        "Atomic generation persistence regressions failed:\n- "
            . implode("\n- ", $atomicityFailures)
            . "\n"
    );
    exit(1);
}

fwrite(STDOUT, "FolioQueryController generation link test passed\n");
