<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\services\AdministratorReviewService;
use yii\console\Application;

class ReviewTrackingConnection extends yii\db\Connection
{
    public $executedSql = [];
}

class ReviewTrackingCommand extends yii\db\Command
{
    private function recordSql()
    {
        if ($this->db instanceof ReviewTrackingConnection) {
            $this->db->executedSql[] = preg_replace('/\s+/', ' ', trim($this->getRawSql()));
        }
    }

    public function execute()
    {
        $this->recordSql();
        return parent::execute();
    }

    public function queryScalar()
    {
        $this->recordSql();
        return parent::queryScalar();
    }
}

new Application([
    'id' => 'administrator-review-service-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => [
            'class' => ReviewTrackingConnection::class,
            'commandClass' => ReviewTrackingCommand::class,
            'dsn' => 'sqlite::memory:',
        ],
    ],
]);
Yii::$app->errorHandler->unregister();

$db = Yii::$app->db;
$db->createCommand('PRAGMA foreign_keys = ON')->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    user_id INTEGER NULL,
    status VARCHAR(20) NOT NULL
)
SQL)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_generations (
    id VARCHAR(36) PRIMARY KEY,
    conversation_id VARCHAR(36) NOT NULL,
    parent_generation_id VARCHAR(36) NULL,
    query_job_id VARCHAR(36) NULL,
    user_id INTEGER NULL,
    prompt_fingerprint VARCHAR(16) NOT NULL,
    original_question TEXT NOT NULL,
    follow_up_context TEXT NULL,
    response_mode VARCHAR(32) NULL,
    execution_mode VARCHAR(32) NULL,
    route VARCHAR(128) NULL,
    route_reason VARCHAR(255) NULL,
    validation_status VARCHAR(32) NULL,
    generated_sql TEXT NULL,
    sql_hash VARCHAR(64) NULL,
    assumptions_json TEXT NULL,
    user_notice_json TEXT NULL,
    confidence_evidence_json TEXT NOT NULL,
    initial_structure_json TEXT NULL,
    final_structure_json TEXT NULL,
    provenance_json TEXT NOT NULL,
    review_required INTEGER NOT NULL DEFAULT 0,
    review_reasons_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    linked_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (parent_generation_id) REFERENCES ai_report_generations(id) ON DELETE SET NULL
)
SQL)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_reviews (
    id VARCHAR(36) PRIMARY KEY,
    generation_id VARCHAR(36) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    disposition VARCHAR(40) NULL,
    advisory_state VARCHAR(20) NOT NULL DEFAULT 'none',
    superseded_by_job_id VARCHAR(36) NULL,
    administrator_notes TEXT NULL,
    reviewed_by INTEGER NULL,
    claimed_at DATETIME NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (generation_id) REFERENCES ai_report_generations(id) ON DELETE CASCADE
)
SQL)->execute();

function reviewAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function reviewExpectException($class, callable $callback, $message)
{
    try {
        $callback();
    } catch (Throwable $exception) {
        reviewAssert($exception instanceof $class, $message . ' Got ' . get_class($exception) . '.');
        return $exception;
    }
    reviewAssert(false, $message . ' No exception was thrown.');
}

function reviewSqlIndex(array $statements, $fragment)
{
    foreach ($statements as $index => $statement) {
        if (strpos($statement, $fragment) !== false) {
            return $index;
        }
    }
    return null;
}

function generationContext(array $overrides = [])
{
    return array_replace([
        'userId' => 7,
        'promptFingerprint' => '0123456789abcdef',
        'originalQuestion' => 'Which funds have the highest spend?',
        'followUpContext' => ['answer' => null, 'selection' => ['b' => 2, 'a' => 1]],
        'mode' => 'exploratory',
        'executionMode' => 'exploratory',
        'route' => 'gemini',
        'routeReason' => 'long_tail',
        'validationStatus' => 'validated',
        'generatedSql' => 'SELECT 1',
        'sqlHash' => hash('sha256', 'SELECT 1'),
        'assumptions' => [['key' => 'fiscal_year', 'value' => null]],
        'userNotice' => null,
        'confidenceEvidence' => ['z' => 1, 'a' => ['y' => null, 'x' => 2]],
        'initialStructure' => null,
        'finalStructure' => ['relations' => []],
        'provenance' => ['schemaArtifact' => null, 'model' => 'gemini', 'compiler' => null],
        'reviewRequired' => false,
        'reviewReasons' => [],
    ], $overrides);
}

$service = new AdministratorReviewService($db);

$plain = $service->recordGeneration(generationContext(['conversationId' => 'client-controlled']));
reviewAssert($plain['reviewId'] === null, 'An unflagged generation must not create a review.');
reviewAssert($plain['conversationId'] !== 'client-controlled', 'Root conversation IDs must be generated server-side.');
$plainRow = $db->createCommand('SELECT * FROM ai_report_generations WHERE id = :id', [':id' => $plain['generationId']])->queryOne();
reviewAssert($plainRow !== false, 'The generation should be inserted.');
reviewAssert(
    $plainRow['confidence_evidence_json'] === '{"a":{"x":2,"y":null},"z":1}',
    'Evidence JSON must use recursively stable object-key ordering and retain nulls.'
);
reviewAssert(
    $plainRow['provenance_json'] === '{"compiler":null,"model":"gemini","schemaArtifact":null}',
    'Unavailable provenance must remain explicit null JSON values.'
);
reviewAssert($plainRow['review_reasons_json'] === '[]', 'Empty JSON lists must remain lists, not objects.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_reviews')->queryScalar() === 0, 'No review row should be present.');

$flagged = $service->recordGeneration(generationContext([
    'reviewRequired' => true,
    'reviewReasons' => ['documented_default'],
]));
reviewAssert($flagged['reviewId'] !== null, 'A flagged generation must create a review in the same transaction.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_reviews')->queryScalar() === 1, 'Exactly one review should be created.');

$insertReview = new ReflectionMethod(AdministratorReviewService::class, 'insertReview');
$insertReview->setAccessible(true);
$duplicateReviewId = $insertReview->invoke($service, $flagged['generationId']);
reviewAssert($duplicateReviewId === $flagged['reviewId'], 'Duplicate review creation must return the existing review id.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_reviews')->queryScalar() === 1, 'Duplicate review creation must be idempotent.');

$child = $service->recordGeneration(generationContext([
    'parentGenerationId' => $plain['generationId'],
    'originalQuestion' => 'Now only the current fiscal year',
]));
reviewAssert($child['conversationId'] === $plain['conversationId'], 'An owned child must inherit its parent conversation.');
$childParent = $db->createCommand('SELECT parent_generation_id FROM ai_report_generations WHERE id = :id', [':id' => $child['generationId']])->queryScalar();
reviewAssert($childParent === $plain['generationId'], 'The owned parent generation must be persisted.');

$anonymousRoot = $service->recordGeneration(generationContext(['userId' => null]));
$beforeAnonymousChild = (int)$db->createCommand('SELECT COUNT(*) FROM ai_report_generations')->queryScalar();
reviewExpectException(DomainException::class, static function () use ($service, $anonymousRoot): void {
    $service->recordGeneration(generationContext([
        'userId' => null,
        'parentGenerationId' => $anonymousRoot['generationId'],
    ]));
}, 'An anonymous request must not claim an anonymous parent conversation.');
reviewAssert(
    (int)$db->createCommand('SELECT COUNT(*) FROM ai_report_generations')->queryScalar() === $beforeAnonymousChild,
    'Rejected anonymous parent linkage must not persist a child generation.'
);

reviewExpectException(DomainException::class, static function () use ($service, $plain): void {
    $service->recordGeneration(generationContext(['userId' => 8, 'parentGenerationId' => $plain['generationId']]));
}, 'A generation must not inherit an unowned parent conversation.');

$beforeFailure = (int)$db->createCommand('SELECT COUNT(*) FROM ai_report_generations')->queryScalar();
$db->open();
$db->pdo->exec("CREATE TRIGGER fail_review BEFORE INSERT ON ai_report_reviews BEGIN SELECT RAISE(FAIL, 'forced review failure'); END");
reviewExpectException(Throwable::class, static function () use ($service): void {
    $service->recordGeneration(generationContext(['reviewRequired' => true]));
}, 'A forced review insert failure should escape the transaction.');
$db->createCommand('DROP TRIGGER fail_review')->execute();
reviewAssert(
    (int)$db->createCommand('SELECT COUNT(*) FROM ai_report_generations')->queryScalar() === $beforeFailure,
    'A review insert failure must roll back its generation.'
);

$claimed = $service->claim($flagged['reviewId'], 101);
reviewAssert($claimed['status'] === 'in_review' && (int)$claimed['reviewed_by'] === 101, 'The first administrator should atomically claim a pending review.');
reviewExpectException(DomainException::class, static function () use ($service, $flagged): void {
    $service->claim($flagged['reviewId'], 202);
}, 'A second administrator must not claim an already claimed review.');

foreach (['unknown', ''] as $invalidDisposition) {
    reviewExpectException(InvalidArgumentException::class, static function () use ($service, $flagged, $invalidDisposition): void {
        $service->resolve($flagged['reviewId'], 101, $invalidDisposition, 'notes');
    }, 'Resolve must reject an invalid disposition.');
}
reviewExpectException(InvalidArgumentException::class, static function () use ($service, $flagged): void {
    $service->resolve($flagged['reviewId'], 101, 'acceptable', 'notes', 'none', 'job-1');
}, 'A superseding job is forbidden unless advisory state is superseded.');
reviewExpectException(InvalidArgumentException::class, static function () use ($service, $flagged): void {
    $service->resolve($flagged['reviewId'], 101, 'acceptable', 'notes', 'superseded');
}, 'A superseded advisory must identify its replacement job.');
reviewExpectException(DomainException::class, static function () use ($service, $flagged): void {
    $service->resolve($flagged['reviewId'], 202, 'acceptable', 'takeover without claim');
}, 'Resolve must reject a different administrator unless ownership has been transferred.');

$db->createCommand()->batchInsert('query_jobs', ['id', 'user_id', 'status'], [
    ['job-running', 7, 'running'],
    ['job-unrelated', 8, 'completed'],
    ['job-2', 7, 'completed'],
])->execute();
reviewExpectException(InvalidArgumentException::class, static function () use ($service, $flagged): void {
    $service->resolve($flagged['reviewId'], 101, 'generation_defect', 'not complete', 'superseded', 'job-running');
}, 'Supersession must require a completed replacement job.');
reviewExpectException(InvalidArgumentException::class, static function () use ($service, $flagged): void {
    $service->resolve($flagged['reviewId'], 101, 'generation_defect', 'wrong owner', 'superseded', 'job-unrelated');
}, 'Supersession must reject an unrelated user’s completed job.');

$takeoverReview = $service->recordGeneration(generationContext(['reviewRequired' => true]));
$service->claim($takeoverReview['reviewId'], 303);
$takenOver = $service->resolve($takeoverReview['reviewId'], 404, 'acceptable', 'explicit takeover', 'none', null, true);
reviewAssert((int)$takenOver['reviewed_by'] === 404, 'An explicit takeover should transfer review ownership before resolution.');

$db->executedSql = [];
$resolved = $service->resolve($flagged['reviewId'], 101, 'generation_defect', 'corrected', 'superseded', 'job-2');
reviewAssert($resolved['status'] === 'resolved', 'A claimed review should resolve.');
reviewAssert($resolved['superseded_by_job_id'] === 'job-2', 'A superseded review should retain its replacement job id.');
$queryJobLockIndex = reviewSqlIndex($db->executedSql, 'UPDATE query_jobs SET id=id');
$generationReadIndex = reviewSqlIndex($db->executedSql, 'SELECT g.id FROM ai_report_reviews r INNER JOIN ai_report_generations g');
$generationLockIndex = reviewSqlIndex($db->executedSql, 'UPDATE ai_report_generations SET id=id');
$validationIndex = reviewSqlIndex($db->executedSql, 'SELECT q.id FROM query_jobs q');
$terminalUpdateIndex = reviewSqlIndex($db->executedSql, 'UPDATE `ai_report_reviews` SET');
reviewAssert($queryJobLockIndex !== null, 'Supersession must lock the replacement query job row.');
reviewAssert($generationReadIndex !== null, 'Supersession must read the reviewed generation with current-read semantics after locking the query job.');
reviewAssert($generationLockIndex !== null, 'Supersession must lock the reviewed generation row.');
reviewAssert(
    $queryJobLockIndex < $generationReadIndex
        && $generationReadIndex < $generationLockIndex
        && $generationLockIndex < $validationIndex
        && $validationIndex < $terminalUpdateIndex,
    'Supersession locks must precede invariant validation and the conditional terminal update.'
);
$serviceSource = file_get_contents(__DIR__ . '/../services/AdministratorReviewService.php');
reviewAssert(strpos($serviceSource, ' FOR UPDATE') !== false, 'MySQL supersession validation must use a current locking read rather than a repeatable-read snapshot.');
reviewExpectException(DomainException::class, static function () use ($service, $flagged): void {
    $service->resolve($flagged['reviewId'], 101, 'acceptable', 'again');
}, 'A resolved review must not be resolved twice.');

$pending = $service->recordGeneration(generationContext(['reviewRequired' => true]));
reviewExpectException(DomainException::class, static function () use ($service, $pending): void {
    $service->resolve($pending['reviewId'], 101, 'acceptable', 'not claimed');
}, 'Resolve must require in_review status.');

$old = '2026-01-01 00:00:00';
$db->createCommand()->update('ai_report_generations', ['created_at' => $old, 'updated_at' => $old], ['id' => $plain['generationId']])->execute();
$db->createCommand()->update('ai_report_reviews', ['resolved_at' => $old, 'updated_at' => $old], ['id' => $flagged['reviewId']])->execute();
$atCutoff = $service->recordGeneration(generationContext());
$beforeCutoff = $service->recordGeneration(generationContext());
$db->createCommand()->update('ai_report_generations', ['created_at' => '2026-06-21 00:00:00'], ['id' => $atCutoff['generationId']])->execute();
$db->createCommand()->update('ai_report_generations', ['created_at' => '2026-06-20 23:59:59'], ['id' => $beforeCutoff['generationId']])->execute();
$purged = $service->purgeExpired(30, new DateTimeImmutable('2026-07-21 00:00:00', new DateTimeZone('UTC')));
reviewAssert($purged === 3, 'Retention should report each purged raw generation record.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id IN (:a, :b)', [':a' => $plain['generationId'], ':b' => $flagged['generationId']])->queryScalar() === 0, 'Old unlinked and terminal-review generations should be removed.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_reviews WHERE id = :id', [':id' => $flagged['reviewId']])->queryScalar() === 0, 'Purging a terminal review must remove its raw notes and evidence.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => $atCutoff['generationId']])->queryScalar() === 1, 'A generation exactly at the retention cutoff must remain.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => $beforeCutoff['generationId']])->queryScalar() === 0, 'A generation older than the retention cutoff must be removed.');

$userGeneration = $service->recordGeneration(generationContext(['userId' => 44, 'reviewRequired' => true]));
$otherGeneration = $service->recordGeneration(generationContext(['userId' => 45, 'reviewRequired' => true]));
$userPurged = $service->purgeUserContent(44);
reviewAssert($userPurged === 1, 'User purge should report deleted generation records.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => $userGeneration['generationId']])->queryScalar() === 0, 'User purge must delete raw generation content.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_reviews WHERE generation_id = :id', [':id' => $userGeneration['generationId']])->queryScalar() === 0, 'User purge must delete administrator notes.');
reviewAssert((int)$db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => $otherGeneration['generationId']])->queryScalar() === 1, 'User purge must preserve other users.');

fwrite(STDOUT, "Administrator review service test passed\n");
