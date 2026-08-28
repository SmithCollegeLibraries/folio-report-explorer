<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use app\services\QueryMemoryService;
use yii\web\Application;
use yii\web\IdentityInterface;

final class QueryReplacementIdentity implements IdentityInterface
{
    private $id;
    public function __construct(int $id) { $this->id = $id; }
    public static function findIdentity($id) { return new self((int)$id); }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return $this->id; }
    public function getAuthKey() { return null; }
    public function validateAuthKey($authKey) { return false; }
}

final class QueryReplacementReviewRecorder
{
    public $records = [];
    public function recordGeneration(array $evidence): array
    {
        $this->records[] = $evidence;
        return [
            'generationId' => 'replacement-generation-' . count($this->records),
            'conversationId' => 'replacement-conversation',
        ];
    }
}

final class QueryReplacementController extends FolioQueryController
{
    public $replacementResult = [];
    public $captured = [];
    public $reviewRecorder;

    protected function generateReplacementAiSql(
        string $question,
        string $generationPrompt,
        ?string $campus,
        ?int $userId
    ): array {
        $this->captured = compact('question', 'generationPrompt', 'campus', 'userId');
        return $this->replacementResult;
    }

    protected function administratorReviewService()
    {
        return $this->reviewRecorder;
    }
}

new Application([
    'id' => 'query-replacement-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'query-replacement-test'],
        'user' => ['identityClass' => QueryReplacementIdentity::class, 'enableSession' => false],
    ],
    'params' => [
        'maxQueryRows' => 100,
        'queryTimeoutMs' => 1800000,
    ],
]);
Yii::$app->errorHandler->unregister();
Yii::$app->user->setIdentity(new QueryReplacementIdentity(17));

$db = Yii::$app->db;
$db->createCommand('CREATE TABLE query_jobs (id VARCHAR(36) PRIMARY KEY, user_id INTEGER, status VARCHAR(20), source VARCHAR(20), data_source VARCHAR(20), sql_text TEXT, sql_hash VARCHAR(64), metadata TEXT)')->execute();
$db->createCommand('CREATE TABLE ai_report_generations (id VARCHAR(36) PRIMARY KEY, query_job_id VARCHAR(36), user_id INTEGER, original_question TEXT, generated_sql TEXT, sql_hash VARCHAR(64), provenance_json TEXT)')->execute();
$db->createCommand('CREATE TABLE ai_query_feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, generation_id VARCHAR(36), query_job_id VARCHAR(36), generated_sql TEXT, sql_hash VARCHAR(64), result_accuracy VARCHAR(20), feedback_note TEXT, data_source VARCHAR(20), scope_fingerprint VARCHAR(64), reuse_suppressed INTEGER, replacement_generation_id VARCHAR(36))')->execute();

function replacementAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$question = 'Show vendor receipt time for the last three fiscal years';
$rejectedSql = 'SELECT category FROM acrl_statistics';
$scope = ['campus' => 'Smith College'];
$scopeFingerprint = QueryMemoryService::scopeFingerprint('local', $scope);
$db->createCommand()->insert('query_jobs', [
    'id' => 'job-rejected',
    'user_id' => 17,
    'status' => 'completed',
    'source' => 'nl',
    'data_source' => 'local',
    'sql_text' => $rejectedSql,
    'sql_hash' => hash('sha256', $rejectedSql),
    'metadata' => json_encode(['resolvedContext' => $scope]),
])->execute();
$db->createCommand()->insert('ai_report_generations', [
    'id' => 'generation-rejected',
    'query_job_id' => 'job-rejected',
    'user_id' => 17,
    'original_question' => $question,
    'generated_sql' => $rejectedSql,
    'sql_hash' => hash('sha256', $rejectedSql . ' legacy-mismatch'),
    'provenance_json' => json_encode(['generationProvenance' => 'ai_built']),
])->execute();
$db->createCommand()->insert('ai_query_feedback', [
    'user_id' => 17,
    'generation_id' => 'generation-rejected',
    'query_job_id' => 'job-rejected',
    'generated_sql' => $rejectedSql,
    'sql_hash' => hash('sha256', $rejectedSql . ' legacy-mismatch'),
    'result_accuracy' => 'inaccurate',
    'feedback_note' => 'The vendor grouping was wrong.',
    'data_source' => 'local',
    'scope_fingerprint' => $scopeFingerprint,
    'reuse_suppressed' => 1,
])->execute();
$feedbackId = (int)$db->getLastInsertID();

function replacementController(array $result): QueryReplacementController
{
    $controller = new QueryReplacementController('folio-query', Yii::$app);
    $controller->replacementResult = $result;
    $controller->reviewRecorder = new QueryReplacementReviewRecorder();
    return $controller;
}

Yii::$app->request->setBodyParams(['resolvedContext' => $scope]);
$sameController = replacementController([
    'sql' => $rejectedSql,
    'dataSource' => 'local',
    'mode' => 'exploratory',
    'generationProvenance' => 'ai_built',
]);
$same = $sameController->actionQueryFeedbackReplacement($feedbackId);
replacementAssert(($same['errorType'] ?? null) === 'sql_generation_failed', 'An identical replacement SQL hash must be rejected and exhaust this explicit replacement attempt.');
replacementAssert($db->createCommand('SELECT replacement_generation_id FROM ai_query_feedback WHERE id = :id', [':id' => $feedbackId])->queryScalar() === null, 'A rejected identical candidate must not create replacement lineage.');

$differentSql = 'SELECT subcategory FROM acrl_statistics';
Yii::$app->request->setBodyParams(['resolvedContext' => $scope]);
$controller = replacementController([
    'sql' => $differentSql,
    'dataSource' => 'local',
    'mode' => 'exploratory',
    'route' => 'exploratory_legacy_freeform',
    'routeReason' => 'feedback_replacement',
    'generationProvenance' => 'ai_built',
    'provenanceLabel' => 'AI-built',
]);
$replacement = $controller->actionQueryFeedbackReplacement($feedbackId);
replacementAssert(($replacement['sql'] ?? null) === $differentSql, 'A materially different safe replacement should pass the ordinary coordinator validation path.');
replacementAssert(($replacement['generationProvenance'] ?? null) === 'ai_built', 'Replacement provenance must remain AI-built.');
replacementAssert(($replacement['parentGenerationId'] ?? null) === 'generation-rejected', 'The response must expose rejected-generation parent lineage.');
replacementAssert(($controller->captured['question'] ?? null) === $question, 'The server must load the original question from feedback lineage.');
replacementAssert(($controller->captured['campus'] ?? null) === 'Smith College', 'The replacement must use the current authorized campus scope.');
replacementAssert(($controller->captured['userId'] ?? null) === 17, 'The replacement AI call must use authenticated user identity.');
replacementAssert(strpos($controller->captured['generationPrompt'] ?? '', hash('sha256', $rejectedSql)) !== false, 'Provider context must contain the stored rejected SQL hash.');
replacementAssert(strpos($controller->captured['generationPrompt'] ?? '', 'The vendor grouping was wrong.') !== false, 'Provider context must contain the stored feedback note.');
replacementAssert(stripos($controller->captured['generationPrompt'] ?? '', 'materially different') !== false, 'Provider context must explicitly require materially different SQL.');
replacementAssert(($controller->reviewRecorder->records[0]['parentGenerationId'] ?? null) === 'generation-rejected', 'Persisted replacement evidence must link to the rejected generation.');
replacementAssert($db->createCommand('SELECT replacement_generation_id FROM ai_query_feedback WHERE id = :id', [':id' => $feedbackId])->queryScalar() === ($replacement['generationId'] ?? null), 'Successful replacement generation must be stored on the feedback row.');

Yii::$app->user->setIdentity(new QueryReplacementIdentity(18));
Yii::$app->request->setBodyParams(['resolvedContext' => $scope]);
$forbidden = replacementController(['sql' => $differentSql, 'dataSource' => 'local'])
    ->actionQueryFeedbackReplacement($feedbackId);
replacementAssert(Yii::$app->response->statusCode === 403 && isset($forbidden['error']), 'Only the feedback owner may request replacement SQL.');

Yii::$app->user->setIdentity(new QueryReplacementIdentity(17));
$db->createCommand()->insert('ai_query_feedback', [
    'user_id' => 17,
    'generation_id' => 'generation-rejected',
    'query_job_id' => 'job-rejected',
    'generated_sql' => $rejectedSql,
    'sql_hash' => hash('sha256', $rejectedSql),
    'result_accuracy' => 'accurate',
    'feedback_note' => null,
    'data_source' => 'local',
    'scope_fingerprint' => $scopeFingerprint,
    'reuse_suppressed' => 0,
])->execute();
$accurateId = (int)$db->getLastInsertID();
Yii::$app->request->setBodyParams(['resolvedContext' => $scope]);
$notInaccurate = replacementController(['sql' => $differentSql, 'dataSource' => 'local'])
    ->actionQueryFeedbackReplacement($accurateId);
replacementAssert(Yii::$app->response->statusCode === 409 && isset($notInaccurate['error']), 'Only Inaccurate suppressed feedback may request replacement SQL.');

echo "FolioQueryController query replacement test passed\n";
