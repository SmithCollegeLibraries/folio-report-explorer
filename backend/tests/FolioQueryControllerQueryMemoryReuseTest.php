<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\services\AdministratorReviewService;
use app\services\FolioSchemaService;
use app\services\QueryMemoryService;
use app\controllers\FolioQueryController;
use yii\web\Application;
use yii\web\IdentityInterface;

final class QueryMemoryReuseIdentity implements IdentityInterface
{
    private $id;

    public function __construct(int $id) { $this->id = $id; }
    public static function findIdentity($id) { return new self((int)$id); }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return $this->id; }
    public function getAuthKey() { return null; }
    public function validateAuthKey($authKey) { return false; }
}

new Application([
    'id' => 'query-memory-reuse-lineage-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'query-memory-test'],
        'user' => ['identityClass' => QueryMemoryReuseIdentity::class, 'enableSession' => false],
    ],
    'params' => [
        'schemaPath' => dirname(__DIR__) . '/data/folio_schema.json',
        'derivedPath' => dirname(__DIR__) . '/data/folio_derived_tables.json',
        'maxQueryRows' => 100,
        'queryTimeoutMs' => 1800000,
    ],
]);
Yii::$app->errorHandler->unregister();
Yii::$app->user->setIdentity(new QueryMemoryReuseIdentity(17));

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
    created_at DATETIME,
    started_at DATETIME,
    completed_at DATETIME
)
SQL)->execute();
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
SQL)->execute();
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
SQL)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_query_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    generation_id VARCHAR(36),
    query_job_id VARCHAR(36),
    sql_hash VARCHAR(64),
    result_accuracy VARCHAR(20),
    direct_reuse_schema_fingerprint VARCHAR(64),
    schema_version_fingerprint VARCHAR(64),
    scope_fingerprint VARCHAR(64),
    reuse_suppressed INTEGER DEFAULT 0,
    admin_reuse_approved_at DATETIME,
    created_at DATETIME
)
SQL)->execute();

function queryMemoryReuseAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$service = new AdministratorReviewService($db);
$source = $service->recordGeneration([
    'userId' => 22,
    'originalQuestion' => 'Count inventory items',
    'responseMode' => 'canonical',
    'executionMode' => 'deterministic',
    'route' => 'canonical',
    'routeReason' => 'query_execution',
    'validationStatus' => 'validated',
    'generatedSql' => 'SELECT COUNT(*) FROM inventory.item__t',
    'confidenceEvidence' => ['verified' => true],
    'provenance' => [
        'generationProvenance' => 'verified_pattern',
        'schemaMetadata' => ['version' => 'schema-v1', 'contextHash' => 'prompt-a'],
    ],
    'reviewRequired' => false,
    'reviewReasons' => [],
]);

$reused = $service->createTrustedReuseChild(
    $source['generationId'],
    17,
    'Please count inventory items',
    'SELECT COUNT(*) FROM inventory.item__t',
    false,
    'verified_global'
);
$reusedRow = $db->createCommand('SELECT * FROM ai_report_generations WHERE id = :id', [':id' => $reused['generationId']])->queryOne();
$reusedProvenance = json_decode((string)$reusedRow['provenance_json'], true);
queryMemoryReuseAssert((int)$reusedRow['user_id'] === 17, 'A reused child must belong to the executing user, not the source user.');
queryMemoryReuseAssert($reusedRow['parent_generation_id'] === $source['generationId'], 'A reused child must retain source-generation lineage.');
queryMemoryReuseAssert($reusedRow['conversation_id'] === $source['conversationId'], 'A reused child must retain the source conversation.');
queryMemoryReuseAssert($reusedRow['original_question'] === 'Please count inventory items', 'A reused child must store the new request.');
queryMemoryReuseAssert(($reusedProvenance['generationProvenance'] ?? null) === 'verified_pattern', 'Unedited reuse must preserve immutable Verified provenance.');
queryMemoryReuseAssert(($reusedProvenance['queryMemory']['reuseTrust'] ?? null) === 'verified_global', 'Reuse lineage must retain the server-selected trust source.');

$edited = $service->createTrustedReuseChild(
    $source['generationId'],
    17,
    'Please count inventory items',
    'SELECT COUNT(*) FROM inventory.item__t WHERE true',
    true,
    'verified_global'
);
$editedRow = $db->createCommand('SELECT * FROM ai_report_generations WHERE id = :id', [':id' => $edited['generationId']])->queryOne();
$editedProvenance = json_decode((string)$editedRow['provenance_json'], true);
queryMemoryReuseAssert(($editedProvenance['generationProvenance'] ?? null) === 'ai_built', 'Edited reuse must create AI-built lineage.');
queryMemoryReuseAssert((int)$editedRow['review_required'] === 1, 'Edited reuse must remain reviewable.');

$missingRejected = false;
try {
    $service->createTrustedReuseChild('missing-generation', 17, 'Question', 'SELECT 1', false, 'verified_global');
} catch (DomainException $exception) {
    $missingRejected = true;
}
queryMemoryReuseAssert($missingRejected, 'A missing server-owned source generation must be rejected.');

$prompt = 'Show ACRL categories';
$schemaContext = FolioSchemaService::buildSchemaContext($prompt);
$schemaMetadata = FolioSchemaService::getMetadata();
$schemaMetadata = [
    'version' => $schemaMetadata['version'] ?? $schemaMetadata['scraped_at'] ?? null,
    'contextHash' => substr(hash('sha256', (string)$schemaContext), 0, 16),
];
$jobId = 'job-verified-reuse';
$jobSql = 'SELECT category FROM acrl_statistics';
$db->createCommand()->insert('query_jobs', [
    'id' => $jobId,
    'user_id' => 22,
    'status' => 'completed',
    'source' => 'nl',
    'data_source' => 'local',
    'sql_text' => $jobSql,
    'sql_hash' => hash('sha256', $jobSql),
    'name' => $prompt,
    'metadata' => json_encode(['originalPrompt' => $prompt, 'resolvedContext' => ['campus' => 'Smith College']]),
    'row_count' => 3,
    'execution_time_ms' => 12,
    'created_at' => '2026-08-26 10:00:00',
    'completed_at' => '2026-08-26 10:00:01',
])->execute();
$endpointSource = $service->recordGeneration([
    'userId' => 22,
    'queryJobId' => $jobId,
    'originalQuestion' => $prompt,
    'responseMode' => 'canonical',
    'executionMode' => 'deterministic',
    'route' => 'canonical',
    'routeReason' => 'query_execution',
    'validationStatus' => 'validated',
    'generatedSql' => $jobSql,
    'confidenceEvidence' => ['verified' => true],
    'provenance' => ['generationProvenance' => 'verified_pattern', 'schemaMetadata' => $schemaMetadata],
    'reviewRequired' => false,
    'reviewReasons' => [],
]);
Yii::$app->request->setBodyParams([
    'prompt' => $prompt,
    'dataSource' => 'local',
    'resolvedContext' => ['campus' => 'Smith College'],
    'sourceGenerationId' => 'client-forged',
    'reuseTrust' => 'client-forged',
]);
$endpointResponse = (new FolioQueryController('folio-query', Yii::$app))->actionQueryReuseCandidate();
$endpointMatch = $endpointResponse['match'] ?? null;
queryMemoryReuseAssert(is_array($endpointMatch), 'A compatible Verified cross-user candidate should be returned by the endpoint.');
queryMemoryReuseAssert(($endpointMatch['sourceGenerationId'] ?? null) === $endpointSource['generationId'], 'The endpoint must derive source lineage server-side.');
queryMemoryReuseAssert(($endpointMatch['reuseTrust'] ?? null) === 'verified_global', 'The endpoint must derive Verified trust server-side.');
queryMemoryReuseAssert(($endpointMatch['generationProvenance'] ?? null) === 'verified_pattern', 'The endpoint must preserve stored provenance.');

Yii::$app->request->setBodyParams([
    'sql' => $jobSql,
    'source' => 'nl',
    'dataSource' => 'local',
    'name' => $prompt,
    'resolvedContext' => ['campus' => 'Smith College'],
    'queryReuse' => [
        'candidateJobId' => $jobId,
        'edited' => true,
        'sourceGenerationId' => 'client-forged',
        'reuseTrust' => 'client-forged',
    ],
]);
Yii::$app->response->statusCode = 200;
$submitResponse = (new FolioQueryController('folio-query', Yii::$app))->actionQuerySubmit();
$submittedGenerationId = $submitResponse['generationId'] ?? null;
$submittedGeneration = $submittedGenerationId === null
    ? false
    : $db->createCommand('SELECT * FROM ai_report_generations WHERE id = :id', [':id' => $submittedGenerationId])->queryOne();
queryMemoryReuseAssert(Yii::$app->response->statusCode === 202, 'A trusted reuse should submit through the ordinary job endpoint.');
queryMemoryReuseAssert(is_array($submittedGeneration), 'Reuse submission must return and persist its execution generation.');
queryMemoryReuseAssert($submittedGeneration['parent_generation_id'] === $endpointSource['generationId'], 'Submission must ignore forged client lineage and link the trusted source generation.');
queryMemoryReuseAssert((int)$submittedGeneration['user_id'] === 17, 'The submitted reuse generation must belong to the executing user.');
$submittedProvenance = json_decode((string)$submittedGeneration['provenance_json'], true);
queryMemoryReuseAssert(($submittedProvenance['generationProvenance'] ?? null) === 'verified_pattern', 'Client-authored edited state must not downgrade unchanged trusted SQL.');
queryMemoryReuseAssert(($submittedProvenance['queryMemory']['reuseTrust'] ?? null) === 'verified_global', 'Submission must persist the server-revalidated trust source.');
$submittedJobMetadata = json_decode((string)$db->createCommand(
    'SELECT metadata FROM query_jobs WHERE id = :id',
    [':id' => $submitResponse['jobId']]
)->queryScalar(), true);
queryMemoryReuseAssert(($submittedJobMetadata['askAiProvenance']['generationId'] ?? null) === $submittedGenerationId, 'Job metadata must identify the new execution child.');
queryMemoryReuseAssert(($submittedJobMetadata['askAiProvenance']['sourceGenerationId'] ?? null) === $endpointSource['generationId'], 'Job metadata must retain the original trusted reuse source.');

function seedAiEndpointCandidate(
    AdministratorReviewService $service,
    $db,
    string $suffix,
    string $prompt,
    int $userId,
    ?string $accuracy,
    bool $approved = false,
    array $overrides = []
): array {
    $schemaContext = FolioSchemaService::buildSchemaContext($prompt);
    $liveMetadata = FolioSchemaService::getMetadata();
    $schemaMetadata = [
        'version' => $liveMetadata['version'] ?? $liveMetadata['scraped_at'] ?? null,
        'contextHash' => substr(hash('sha256', (string)$schemaContext), 0, 16),
    ];
    $jobId = 'job-ai-' . $suffix;
    $sql = (string)($overrides['sql'] ?? ('SELECT category AS category_' . $suffix . ' FROM acrl_statistics'));
    $scope = $overrides['scope'] ?? ['campus' => 'Smith College'];
    $db->createCommand()->insert('query_jobs', [
        'id' => $jobId,
        'user_id' => $userId,
        'status' => 'completed',
        'source' => 'nl',
        'data_source' => 'local',
        'sql_text' => $sql,
        'sql_hash' => hash('sha256', $sql),
        'name' => $prompt,
        'metadata' => json_encode(['originalPrompt' => $prompt, 'resolvedContext' => $scope]),
        'row_count' => 3,
        'execution_time_ms' => 12,
        'created_at' => '2026-08-26 11:00:00',
        'completed_at' => '2026-08-26 11:00:01',
    ])->execute();
    $generation = $service->recordGeneration([
        'userId' => $userId,
        'queryJobId' => $jobId,
        'originalQuestion' => $prompt,
        'responseMode' => 'exploratory',
        'executionMode' => 'exploratory',
        'route' => 'gemini',
        'routeReason' => 'query_execution',
        'validationStatus' => 'validated',
        'generatedSql' => $sql,
        'confidenceEvidence' => ['verified' => false],
        'provenance' => ['generationProvenance' => 'ai_built', 'schemaMetadata' => $schemaMetadata],
        'reviewRequired' => false,
        'reviewReasons' => [],
    ]);
    if ($accuracy !== null) {
        $db->createCommand()->insert('ai_query_feedback', [
            'generation_id' => $generation['generationId'],
            'query_job_id' => $jobId,
            'sql_hash' => hash('sha256', $sql),
            'result_accuracy' => $accuracy,
            'direct_reuse_schema_fingerprint' => $overrides['directFingerprint']
                ?? QueryMemoryService::directReuseSchemaFingerprint($schemaMetadata),
            'schema_version_fingerprint' => QueryMemoryService::schemaVersionFingerprint($schemaMetadata),
            'scope_fingerprint' => QueryMemoryService::scopeFingerprint('local', $scope),
            'reuse_suppressed' => !empty($overrides['suppressed']) ? 1 : 0,
            'admin_reuse_approved_at' => $approved ? '2026-08-26 12:00:00' : null,
            'created_at' => '2026-08-26 12:00:00',
        ])->execute();
    }
    return ['jobId' => $jobId, 'generationId' => $generation['generationId']];
}

function invokeReuseEndpoint(string $prompt, array $scope = ['campus' => 'Smith College']): array
{
    Yii::$app->request->setBodyParams([
        'prompt' => $prompt,
        'dataSource' => 'local',
        'resolvedContext' => $scope,
    ]);
    return (new FolioQueryController('folio-query', Yii::$app))->actionQueryReuseCandidate();
}

$sameUserPrompt = 'Show ACRL categories for same-user memory';
$sameUserSource = seedAiEndpointCandidate($service, $db, 'same', $sameUserPrompt, 17, 'accurate');
$sameUserMatch = invokeReuseEndpoint($sameUserPrompt)['match'] ?? null;
queryMemoryReuseAssert(($sameUserMatch['reuseTrust'] ?? null) === 'same_user_accurate', 'Same-user Accurate AI-built SQL should be directly reusable.');
queryMemoryReuseAssert(($sameUserMatch['sourceGenerationId'] ?? null) === $sameUserSource['generationId'], 'Same-user reuse must return server-owned lineage.');
$sameUserFeedback = $db->createCommand(
    'SELECT * FROM ai_query_feedback WHERE generation_id = :generationId',
    [':generationId' => $sameUserSource['generationId']]
)->queryOne();
$db->createCommand()->insert('ai_query_feedback', [
    'generation_id' => null,
    'query_job_id' => null,
    'sql_hash' => $sameUserFeedback['sql_hash'],
    'result_accuracy' => 'inaccurate',
    'direct_reuse_schema_fingerprint' => $sameUserFeedback['direct_reuse_schema_fingerprint'],
    'schema_version_fingerprint' => $sameUserFeedback['schema_version_fingerprint'],
    'scope_fingerprint' => $sameUserFeedback['scope_fingerprint'],
    'reuse_suppressed' => 1,
    'admin_reuse_approved_at' => null,
    'created_at' => '2026-08-26 12:05:00',
])->execute();
queryMemoryReuseAssert((invokeReuseEndpoint($sameUserPrompt)['match'] ?? null) === null, 'Exact SQL-hash suppression in the same schema and scope must reject reuse even when attached elsewhere.');

$otherUserPrompt = 'Show ACRL categories for other-user memory';
seedAiEndpointCandidate($service, $db, 'other', $otherUserPrompt, 22, 'accurate');
queryMemoryReuseAssert((invokeReuseEndpoint($otherUserPrompt)['match'] ?? null) === null, 'Other-user Accurate AI-built SQL must not be directly reused without approval.');

$approvedPrompt = 'Show ACRL categories for approved memory';
seedAiEndpointCandidate($service, $db, 'admin', $approvedPrompt, 22, 'accurate', true);
queryMemoryReuseAssert((invokeReuseEndpoint($approvedPrompt)['match']['reuseTrust'] ?? null) === 'administrator_approved', 'Administrator-approved AI-built SQL should be reusable across users.');

$inaccuratePrompt = 'Show ACRL categories for inaccurate memory';
seedAiEndpointCandidate($service, $db, 'bad', $inaccuratePrompt, 17, 'inaccurate', true);
queryMemoryReuseAssert((invokeReuseEndpoint($inaccuratePrompt)['match'] ?? null) === null, 'Inaccurate feedback must override approval and suppress direct reuse.');

$stalePrompt = 'Show ACRL categories for stale memory';
seedAiEndpointCandidate($service, $db, 'stale', $stalePrompt, 17, 'accurate', false, ['directFingerprint' => 'stale-fingerprint']);
queryMemoryReuseAssert((invokeReuseEndpoint($stalePrompt)['match'] ?? null) === null, 'A changed strict prompt-scoped fingerprint must reject direct reuse.');

$unsafePrompt = 'Show ACRL categories for unsafe memory';
seedAiEndpointCandidate($service, $db, 'unsafe', $unsafePrompt, 17, 'accurate', false, ['sql' => 'DELETE FROM acrl_statistics']);
queryMemoryReuseAssert((invokeReuseEndpoint($unsafePrompt)['match'] ?? null) === null, 'Unsafe stored SQL must be rejected as a candidate without blocking the request.');

$unknownTablePrompt = 'Show ACRL categories for unknown-table memory';
seedAiEndpointCandidate($service, $db, 'unknown', $unknownTablePrompt, 17, 'accurate', false, ['sql' => 'SELECT value FROM nonexistent_reporting_table']);
queryMemoryReuseAssert((invokeReuseEndpoint($unknownTablePrompt)['match'] ?? null) === null, 'Live schema validation must reject stale table references without blocking the request.');

echo "FolioQueryControllerQueryMemoryReuseTest passed\n";
