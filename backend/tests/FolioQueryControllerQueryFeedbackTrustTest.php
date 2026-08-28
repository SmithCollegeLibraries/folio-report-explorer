<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use app\services\FolioSchemaService;
use app\services\QueryMemoryService;
use yii\web\Application;
use yii\web\IdentityInterface;

final class QueryFeedbackTrustIdentity implements IdentityInterface
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
    'id' => 'query-feedback-trust-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'query-feedback-trust-test'],
        'user' => ['identityClass' => QueryFeedbackTrustIdentity::class, 'enableSession' => false],
    ],
    'params' => [
        'schemaPath' => dirname(__DIR__) . '/data/folio_schema.json',
        'derivedPath' => dirname(__DIR__) . '/data/folio_derived_tables.json',
    ],
]);
Yii::$app->errorHandler->unregister();
Yii::$app->user->setIdentity(new QueryFeedbackTrustIdentity(17));

$db = Yii::$app->db;
$db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    user_id INTEGER,
    status VARCHAR(20),
    source VARCHAR(20),
    data_source VARCHAR(20),
    sql_text TEXT,
    sql_hash VARCHAR(64),
    metadata TEXT,
    completed_at DATETIME
)
SQL)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_generations (
    id VARCHAR(36) PRIMARY KEY,
    query_job_id VARCHAR(36),
    user_id INTEGER,
    original_question TEXT,
    route VARCHAR(128),
    route_reason VARCHAR(255),
    response_mode VARCHAR(32),
    generated_sql TEXT,
    sql_hash VARCHAR(64),
    provenance_json TEXT,
    created_at DATETIME
)
SQL)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_query_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    generation_id VARCHAR(36),
    query_job_id VARCHAR(36),
    original_question TEXT,
    prompt_fingerprint VARCHAR(64),
    generated_sql TEXT,
    sql_hash VARCHAR(64),
    route VARCHAR(128),
    route_reason VARCHAR(255),
    mode VARCHAR(32),
    data_source VARCHAR(20),
    result_accuracy VARCHAR(20),
    feedback_note TEXT,
    generation_provenance VARCHAR(32),
    direct_reuse_schema_fingerprint VARCHAR(64),
    schema_version_fingerprint VARCHAR(64),
    scope_fingerprint VARCHAR(64),
    reuse_suppressed INTEGER NOT NULL DEFAULT 0,
    admin_reuse_approved_at DATETIME,
    admin_reuse_approved_by INTEGER,
    replacement_generation_id VARCHAR(36),
    created_at DATETIME
)
SQL)->execute();

function feedbackTrustAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function seedFeedbackTrustExecution(
    string $suffix,
    int $userId,
    string $provenance = 'ai_built',
    string $sql = 'SELECT title FROM inventory.instance__t',
    ?string $executedSql = null,
    bool $augmentedSchemaContext = false
): array {
    $question = "Trusted question {$suffix}";
    $jobId = "job-{$suffix}";
    $generationId = "generation-{$suffix}";
    $scope = ['campus' => 'Smith College'];
    $metadata = FolioSchemaService::getMetadata();
    $schemaPrompt = $augmentedSchemaContext
        ? $question . "\n\nReference resolver guidance:\n- library: Neilson Library"
        : $question;
    $metadata['contextHash'] = substr(
        hash('sha256', (string)FolioSchemaService::buildSchemaContext($schemaPrompt)),
        0,
        16
    );
    $executedSql = $executedSql ?? $sql;
    Yii::$app->db->createCommand()->insert('query_jobs', [
        'id' => $jobId,
        'user_id' => $userId,
        'status' => 'completed',
        'source' => 'nl',
        'data_source' => 'folio',
        'sql_text' => $executedSql,
        'sql_hash' => hash('sha256', $executedSql),
        'metadata' => json_encode(['resolvedContext' => $scope]),
        'completed_at' => '2026-08-27 09:00:00',
    ])->execute();
    Yii::$app->db->createCommand()->insert('ai_report_generations', [
        'id' => $generationId,
        'query_job_id' => $jobId,
        'user_id' => $userId,
        'original_question' => $question,
        'route' => 'exploratory_legacy_freeform',
        'route_reason' => 'unsupported_query_family',
        'response_mode' => 'exploratory',
        'generated_sql' => $sql,
        'sql_hash' => hash('sha256', $sql),
        'provenance_json' => json_encode([
            'generationProvenance' => $provenance,
            'schemaMetadata' => $metadata,
        ]),
        'created_at' => '2026-08-27 08:59:00',
    ])->execute();
    return compact('question', 'jobId', 'generationId', 'scope', 'metadata', 'sql', 'executedSql');
}

function postFeedback(array $body): array
{
    Yii::$app->request->setBodyParams($body);
    Yii::$app->response->statusCode = 200;
    return (new FolioQueryController('folio-query', Yii::$app))->actionQueryFeedback();
}

$executionSql = 'SELECT title FROM inventory.instance__t';
$execution = seedFeedbackTrustExecution(
    'owned',
    17,
    'ai_built',
    $executionSql,
    $executionSql . "\nLIMIT 100",
    true
);
$forgedSql = 'DELETE FROM inventory.item__t';
$response = postFeedback([
    'generationId' => $execution['generationId'],
    'queryJobId' => $execution['jobId'],
    'resultAccuracy' => 'accurate',
    'feedbackNote' => 'Looks right',
    'generatedSql' => $forgedSql,
    'generationProvenance' => 'verified_pattern',
    'route' => 'forged-route',
    'userId' => 999,
    'directReuseSchemaFingerprint' => 'forged-direct',
    'schemaVersionFingerprint' => 'forged-schema',
    'scopeFingerprint' => 'forged-scope',
]);
$stored = $db->createCommand('SELECT * FROM ai_query_feedback WHERE id = :id', [
    ':id' => $response['feedbackId'] ?? 0,
])->queryOne();
feedbackTrustAssert(($response['resultAccuracy'] ?? null) === 'accurate', 'Feedback response must return the accepted accuracy.');
feedbackTrustAssert(($response['reuseSuppressed'] ?? null) === false, 'Accurate feedback must not suppress reuse.');
feedbackTrustAssert((int)$stored['user_id'] === 17, 'Feedback ownership must come from the authenticated user.');
feedbackTrustAssert($stored['original_question'] === $execution['question'], 'The question must come from the stored generation.');
feedbackTrustAssert($stored['generated_sql'] === $execution['executedSql'], 'Feedback must bind to the SQL that actually produced the rated results.');
feedbackTrustAssert(
    $stored['sql_hash'] === hash('sha256', $execution['executedSql']),
    'Feedback must fingerprint the normalized SQL that actually produced the rated results.'
);
feedbackTrustAssert($stored['generation_provenance'] === 'ai_built', 'Client-authored provenance must be ignored.');
feedbackTrustAssert($stored['route'] === 'exploratory_legacy_freeform', 'Client-authored route must be ignored.');
feedbackTrustAssert(
    $stored['direct_reuse_schema_fingerprint'] === QueryMemoryService::currentDirectReuseSchemaFingerprint($execution['question']),
    'The strict schema fingerprint must use the original question rather than augmented model guidance.'
);
feedbackTrustAssert(
    $stored['schema_version_fingerprint'] === QueryMemoryService::schemaVersionFingerprint($execution['metadata']),
    'The global schema fingerprint must be derived from stored generation evidence.'
);
feedbackTrustAssert(
    $stored['scope_fingerprint'] === QueryMemoryService::scopeFingerprint('folio', $execution['scope']),
    'The scope fingerprint must be derived from stored job metadata.'
);
$sameUserReuse = QueryMemoryService::findDirectReuse([
    'question' => $execution['question'],
    'dataSource' => 'folio',
    'userId' => 17,
    'directReuseSchemaFingerprint' => $stored['direct_reuse_schema_fingerprint'],
    'scopeFingerprint' => $stored['scope_fingerprint'],
], [[
    'id' => $execution['generationId'],
    'question' => $execution['question'],
    'sql' => $execution['executedSql'],
    'dataSource' => 'folio',
    'userId' => 17,
    'generationProvenance' => $stored['generation_provenance'],
    'resultAccuracy' => $stored['result_accuracy'],
    'accurateFeedbackUserIds' => [17],
    'directReuseSchemaFingerprint' => $stored['direct_reuse_schema_fingerprint'],
    'scopeFingerprint' => $stored['scope_fingerprint'],
    'status' => 'completed',
]]);
feedbackTrustAssert(
    ($sameUserReuse['reuseTrust'] ?? null) === 'same_user_accurate',
    'Accurate AI-built feedback must enable same-user reuse only after trusted persistence.'
);

Yii::$app->user->setIdentity(new QueryFeedbackTrustIdentity(18));
$forbidden = postFeedback([
    'generationId' => $execution['generationId'],
    'queryJobId' => $execution['jobId'],
    'resultAccuracy' => 'accurate',
]);
feedbackTrustAssert(Yii::$app->response->statusCode === 403, 'A user must not rate another user\'s execution.');
feedbackTrustAssert(isset($forbidden['error']), 'Ownership rejection must return a safe error.');
Yii::$app->user->setIdentity(new QueryFeedbackTrustIdentity(17));

$badSql = 'SELECT title FROM inventory.instance__t';
$bad = seedFeedbackTrustExecution('bad', 17, 'ai_built', $badSql, $badSql . "\nLIMIT 100");
$badSchema = QueryMemoryService::schemaVersionFingerprint($bad['metadata']);
$badScope = QueryMemoryService::scopeFingerprint('folio', $bad['scope']);
$db->createCommand()->insert('ai_query_feedback', [
    'user_id' => 22,
    'original_question' => 'Different prompt and context',
    'prompt_fingerprint' => 'different-prompt',
    'generated_sql' => $bad['executedSql'],
    'sql_hash' => hash('sha256', $bad['executedSql']),
    'data_source' => 'folio',
    'result_accuracy' => 'accurate',
    'generation_provenance' => 'ai_built',
    'direct_reuse_schema_fingerprint' => 'different-prompt-context',
    'schema_version_fingerprint' => $badSchema,
    'scope_fingerprint' => $badScope,
    'admin_reuse_approved_at' => '2026-08-27 09:30:00',
    'admin_reuse_approved_by' => 1,
])->execute();
$badResponse = postFeedback([
    'generationId' => $bad['generationId'],
    'queryJobId' => $bad['jobId'],
    'resultAccuracy' => 'inaccurate',
]);
$suppressedRows = $db->createCommand(
    'SELECT reuse_suppressed, admin_reuse_approved_at FROM ai_query_feedback WHERE sql_hash = :sqlHash',
    [':sqlHash' => hash('sha256', $bad['executedSql'])]
)->queryAll();
feedbackTrustAssert(($badResponse['reuseSuppressed'] ?? null) === true, 'Inaccurate feedback must report immediate suppression.');
feedbackTrustAssert(count($suppressedRows) >= 2, 'The suppression test must cover the new row and an existing approval.');
foreach ($suppressedRows as $row) {
    feedbackTrustAssert((int)$row['reuse_suppressed'] === 1, 'Every exact SQL match in the schema and scope must be suppressed.');
    feedbackTrustAssert($row['admin_reuse_approved_at'] === null, 'Suppression must clear administrator approval.');
}

$unsure = seedFeedbackTrustExecution('unsure', 17);
$unsureResponse = postFeedback([
    'generationId' => $unsure['generationId'],
    'queryJobId' => $unsure['jobId'],
    'resultAccuracy' => 'unsure',
]);
$unsureRow = $db->createCommand('SELECT * FROM ai_query_feedback WHERE id = :id', [
    ':id' => $unsureResponse['feedbackId'] ?? 0,
])->queryOne();
feedbackTrustAssert((int)$unsureRow['reuse_suppressed'] === 0, 'Unsure feedback must remain neutral.');

$verified = seedFeedbackTrustExecution('verified', 17, 'verified_pattern');
$verifiedResponse = postFeedback([
    'generationId' => $verified['generationId'],
    'queryJobId' => $verified['jobId'],
    'resultAccuracy' => 'accurate',
]);
$verifiedRow = $db->createCommand('SELECT * FROM ai_query_feedback WHERE id = :id', [
    ':id' => $verifiedResponse['feedbackId'] ?? 0,
])->queryOne();
feedbackTrustAssert($verifiedRow['generation_provenance'] === 'verified_pattern', 'Feedback must not convert Verified provenance to AI-built.');

echo "FolioQueryController query feedback trust test passed\n";
