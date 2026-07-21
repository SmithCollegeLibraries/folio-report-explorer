<?php

$devAuthProbe = in_array('--dev-auth-probe', $_SERVER['argv'] ?? [], true);
defined('YII_ENV') or define('YII_ENV', $devAuthProbe ? 'dev' : 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use app\models\DummyIdentity;
use app\models\User;
use yii\web\Application;

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'controller-report-review-' . bin2hex(random_bytes(8));
$runtimePath = $temporaryRoot . DIRECTORY_SEPARATOR . 'runtime';
mkdir($runtimePath, 0700, true);

register_shutdown_function(static function () use ($temporaryRoot): void {
    if (!is_dir($temporaryRoot)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entry->getPathname());
        } else {
            rmdir($entry->getPathname());
        }
    }
    rmdir($temporaryRoot);
});

new Application([
    'id' => 'folio-query-controller-report-review-test',
    'basePath' => dirname(__DIR__),
    'runtimePath' => $runtimePath,
    'components' => [
        'request' => ['cookieValidationKey' => 'test-key', 'enableCsrfValidation' => false],
        'response' => ['class' => yii\web\Response::class],
        'user' => ['identityClass' => User::class, 'enableSession' => false],
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'folioDb' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
]);

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    smith_id VARCHAR(50),
    username VARCHAR(100),
    email VARCHAR(255),
    role VARCHAR(20),
    is_approved INTEGER
)
SQL)->execute();

Yii::$app->db->createCommand(<<<'SQL'
CREATE TABLE query_jobs (
    id VARCHAR(36) PRIMARY KEY,
    user_id INTEGER NULL,
    status VARCHAR(20) NOT NULL,
    created_at DATETIME NULL,
    completed_at DATETIME NULL
)
SQL)->execute();

Yii::$app->db->createCommand(<<<'SQL'
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
    execution_mode VARCHAR(20) NULL,
    route VARCHAR(128) NULL,
    route_reason VARCHAR(255) NULL,
    validation_status VARCHAR(20) NULL,
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
    updated_at DATETIME NOT NULL
)
SQL)->execute();

Yii::$app->db->createCommand(<<<'SQL'
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
    updated_at DATETIME NOT NULL
)
SQL)->execute();

Yii::$app->db->createCommand()->batchInsert(
    'users',
    ['id', 'smith_id', 'username', 'email', 'role', 'is_approved'],
    [
        [1, 'smith-1', 'admin', 'admin@example.test', 'admin', 1],
        [2, 'smith-2', 'second-admin', 'second-admin@example.test', 'admin', 1],
        [7, 'smith-7', 'user', 'user@example.test', 'user', 1],
    ]
)->execute();

function reviewControllerAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function reviewControllerIdentity($id, $role)
{
    $identity = new User();
    $identity->setAttributes([
        'id' => $id,
        'smith_id' => 'smith-' . $id,
        'username' => 'user-' . $id,
        'email' => 'user-' . $id . '@example.test',
        'role' => $role,
        'is_approved' => 1,
    ], false);
    Yii::$app->user->setIdentity($identity);
}

function reviewControllerSeed($suffix, $createdAt, $status = 'pending', $disposition = null)
{
    $generationId = '10000000-0000-4000-8000-' . str_pad((string)$suffix, 12, '0', STR_PAD_LEFT);
    $reviewId = '20000000-0000-4000-8000-' . str_pad((string)$suffix, 12, '0', STR_PAD_LEFT);
    Yii::$app->db->createCommand()->insert('ai_report_generations', [
        'id' => $generationId,
        'conversation_id' => '30000000-0000-4000-8000-000000000001',
        'query_job_id' => null,
        'user_id' => 7,
        'prompt_fingerprint' => 'abcdef0123456789',
        'original_question' => 'Question ' . $suffix,
        'follow_up_context' => json_encode(['priorQuestion' => 'Earlier']),
        'response_mode' => 'exploratory',
        'execution_mode' => 'exploratory',
        'route' => 'exploratory',
        'route_reason' => 'generated',
        'validation_status' => 'validated',
        'generated_sql' => 'SELECT secret_' . $suffix,
        'sql_hash' => hash('sha256', 'SELECT secret_' . $suffix),
        'assumptions_json' => json_encode(['Used current holdings']),
        'user_notice_json' => json_encode(['limitations' => ['May omit deleted rows']]),
        'confidence_evidence_json' => json_encode(['failureCategory' => 'semantic_gap', 'validatorStage' => 'schema']),
        'initial_structure_json' => json_encode(['relations' => ['inventory.item__t']]),
        'final_structure_json' => json_encode(['relations' => ['inventory.item__t']]),
        'provenance_json' => json_encode(['model' => 'test-model', 'schemaArtifactVersion' => 'v1']),
        'review_required' => 1,
        'review_reasons_json' => json_encode(['unable_to_validate']),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ])->execute();
    Yii::$app->db->createCommand()->insert('ai_report_reviews', [
        'id' => $reviewId,
        'generation_id' => $generationId,
        'status' => $status,
        'disposition' => $disposition,
        'advisory_state' => 'none',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ])->execute();
    return $reviewId;
}

function reviewControllerInvoke($action, array $arguments = [], array $query = [], $body = [])
{
    Yii::$app->response->statusCode = 200;
    Yii::$app->request->setQueryParams($query);
    Yii::$app->request->setBodyParams($body);
    $controller = new FolioQueryController('folio-query', Yii::$app);
    return call_user_func_array([$controller, $action], $arguments);
}

if ($devAuthProbe) {
    Yii::$app->user->setIdentity(null);
    $response = reviewControllerInvoke('actionReportReviewList');
    reviewControllerAssert(Yii::$app->response->statusCode === 403, 'An unauthenticated dev request must not inherit DummyIdentity administrator access.');
    reviewControllerAssert(($response['error'] ?? null) === 'Forbidden', 'An unauthenticated dev request must receive stable forbidden copy.');
    fwrite(STDOUT, "Dev guest report review authorization probe passed\n");
    exit(0);
}

$devProbeOutput = [];
$devProbeExit = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --dev-auth-probe 2>&1', $devProbeOutput, $devProbeExit);
reviewControllerAssert($devProbeExit === 0, "Dev guest authorization subprocess failed:\n" . implode("\n", $devProbeOutput));

$oldestPending = reviewControllerSeed(1, '2026-07-21 08:00:00');
$newestPending = reviewControllerSeed(2, '2026-07-21 10:00:00');
$resolved = reviewControllerSeed(3, '2026-07-21 07:00:00', 'resolved', 'acceptable');

$controllerBehaviors = (new FolioQueryController('folio-query', Yii::$app))->behaviors();
$accessRules = $controllerBehaviors['access']['rules'] ?? [];
$reviewActions = ['report-review-list', 'report-review-detail', 'report-review-claim', 'report-review-update'];
$adminRuleActions = $accessRules[0]['actions'] ?? [];
foreach ($reviewActions as $reviewAction) {
    reviewControllerAssert(in_array($reviewAction, $adminRuleActions, true), $reviewAction . ' must be registered in the framework administrator rule.');
}
$stableDenyRule = null;
foreach ($accessRules as $rule) {
    if (($rule['allow'] ?? null) === false && in_array('report-review-list', $rule['actions'] ?? [], true)) {
        $stableDenyRule = $rule;
        break;
    }
}
reviewControllerAssert(is_callable($stableDenyRule['denyCallback'] ?? null), 'The framework administrator rule must preserve stable review API denial copy.');

// Every action enforces the same administrator-only boundary, even in test/dev environments.
reviewControllerIdentity(7, 'user');
$accessController = new FolioQueryController('folio-query', Yii::$app);
$accessFilter = Yii::createObject($controllerBehaviors['access']);
Yii::$app->response->statusCode = 200;
Yii::$app->response->data = null;
$accessAllowed = $accessFilter->beforeAction($accessController->createAction('report-review-list'));
reviewControllerAssert($accessAllowed === false, 'Framework AccessControl must stop a regular user before the review action.');
reviewControllerAssert(Yii::$app->response->statusCode === 403, 'Framework AccessControl denial must use HTTP 403.');
reviewControllerAssert((Yii::$app->response->data['error'] ?? null) === 'Forbidden', 'Framework AccessControl denial must preserve stable forbidden copy.');
$forbiddenCalls = [
    ['actionReportReviewList'],
    ['actionReportReviewDetail', [$oldestPending]],
    ['actionReportReviewClaim', [$oldestPending]],
    ['actionReportReviewUpdate', [$oldestPending], [], ['status' => 'resolved', 'disposition' => 'acceptable']],
];
foreach ($forbiddenCalls as $call) {
    $response = reviewControllerInvoke($call[0], $call[1] ?? [], $call[2] ?? [], $call[3] ?? []);
    reviewControllerAssert(Yii::$app->response->statusCode === 403, $call[0] . ' must reject a regular user with HTTP 403.');
    reviewControllerAssert(($response['error'] ?? null) === 'Forbidden', $call[0] . ' must use stable forbidden copy.');
}

Yii::$app->user->setIdentity(new DummyIdentity());
foreach ($forbiddenCalls as $call) {
    $response = reviewControllerInvoke($call[0], $call[1] ?? [], $call[2] ?? [], $call[3] ?? []);
    reviewControllerAssert(Yii::$app->response->statusCode === 403, $call[0] . ' must reject DummyIdentity with HTTP 403.');
    reviewControllerAssert(($response['error'] ?? null) === 'Forbidden', $call[0] . ' must reject DummyIdentity with stable forbidden copy.');
}

$forgedAdministrator = new User();
$forgedAdministrator->setAttributes([
    'id' => 7,
    'smith_id' => 'smith-7',
    'username' => 'forged-admin',
    'email' => 'forged-admin@example.test',
    'role' => 'admin',
    'is_approved' => 1,
], false);
Yii::$app->user->setIdentity($forgedAdministrator);
$forgedResponse = reviewControllerInvoke('actionReportReviewList');
reviewControllerAssert(Yii::$app->response->statusCode === 403, 'An in-memory administrator role must not override the persisted regular-user role.');
reviewControllerAssert(($forgedResponse['error'] ?? null) === 'Forbidden', 'A forged administrator identity must receive stable forbidden copy.');

reviewControllerIdentity(1, 'admin');
$page = reviewControllerInvoke('actionReportReviewList', [], ['limit' => 1, 'offset' => 0]);
reviewControllerAssert(Yii::$app->response->statusCode === 200, 'An administrator should list review items.');
reviewControllerAssert(($page['pagination']['limit'] ?? null) === 1, 'The list should report the requested page limit.');
reviewControllerAssert(($page['pagination']['total'] ?? null) === 2, 'The default list should contain pending reviews only.');
reviewControllerAssert(($page['items'][0]['id'] ?? null) === $oldestPending, 'Pending reviews must be ordered oldest first.');
reviewControllerAssert(!array_key_exists('generatedSql', $page['items'][0]), 'List summaries must not expose generated SQL.');
reviewControllerAssert(!array_key_exists('confidenceEvidence', $page['items'][0]), 'List summaries must not expose technical evidence.');
reviewControllerAssert(!array_key_exists('administratorNotes', $page['items'][0]), 'List summaries must reserve administrator notes for detail.');

$clamped = reviewControllerInvoke('actionReportReviewList', [], ['limit' => 0, 'status' => 'not-a-status']);
reviewControllerAssert(($clamped['pagination']['limit'] ?? null) === 1, 'List limits below one must clamp to one.');
reviewControllerAssert(($clamped['pagination']['total'] ?? null) === 2, 'A non-allowlisted status must not broaden the default pending queue.');
$maxClamped = reviewControllerInvoke('actionReportReviewList', [], ['limit' => 999, 'status' => 'resolved', 'disposition' => 'acceptable']);
reviewControllerAssert(($maxClamped['pagination']['limit'] ?? null) === 100, 'List limits above 100 must clamp to 100.');
reviewControllerAssert(count($maxClamped['items'] ?? []) === 1 && $maxClamped['items'][0]['id'] === $resolved, 'Allowlisted status and disposition filters should be applied.');

$detail = reviewControllerInvoke('actionReportReviewDetail', [$oldestPending]);
reviewControllerAssert(($detail['generatedSql'] ?? null) === 'SELECT secret_1', 'Administrator detail should include generated SQL.');
reviewControllerAssert(($detail['confidenceEvidence']['validatorStage'] ?? null) === 'schema', 'Administrator detail should decode technical confidence evidence.');
reviewControllerAssert(($detail['provenance']['model'] ?? null) === 'test-model', 'Administrator detail should decode trusted provenance.');

$missing = reviewControllerInvoke('actionReportReviewDetail', ['missing-review']);
reviewControllerAssert(Yii::$app->response->statusCode === 404 && ($missing['error'] ?? null) === 'Review not found', 'Missing review detail should return stable HTTP 404.');

$claimed = reviewControllerInvoke('actionReportReviewClaim', [$oldestPending]);
reviewControllerAssert(Yii::$app->response->statusCode === 200, 'The first administrator should claim a pending review.');
reviewControllerAssert(($claimed['status'] ?? null) === 'in_review' && (int)($claimed['reviewedBy'] ?? 0) === 1, 'Claim should atomically assign the administrator.');
reviewControllerIdentity(2, 'admin');
$claimConflict = reviewControllerInvoke('actionReportReviewClaim', [$oldestPending]);
reviewControllerAssert(Yii::$app->response->statusCode === 409, 'A second conditional claim should return HTTP 409.');
reviewControllerAssert(($claimConflict['error'] ?? null) === 'Review is no longer available to claim', 'Claim conflict should use stable copy.');

reviewControllerIdentity(1, 'admin');
$invalidTerminal = reviewControllerInvoke('actionReportReviewUpdate', [$oldestPending], [], ['status' => 'pending', 'disposition' => 'acceptable']);
reviewControllerAssert(Yii::$app->response->statusCode === 422, 'PATCH must accept only resolved or dismissed terminal states.');
$missingDisposition = reviewControllerInvoke('actionReportReviewUpdate', [$oldestPending], [], ['status' => 'resolved']);
reviewControllerAssert(Yii::$app->response->statusCode === 422, 'Resolving a review must require a disposition.');
$invalidDisposition = reviewControllerInvoke('actionReportReviewUpdate', [$oldestPending], [], ['status' => 'resolved', 'disposition' => 'guess']);
reviewControllerAssert(Yii::$app->response->statusCode === 422, 'Resolving a review must reject a non-allowlisted disposition.');
$invalidAdvisory = reviewControllerInvoke('actionReportReviewUpdate', [$oldestPending], [], ['status' => 'resolved', 'disposition' => 'acceptable', 'advisoryState' => 'warning']);
reviewControllerAssert(Yii::$app->response->statusCode === 422, 'Resolving a review must reject a non-allowlisted advisory state.');
$scalarBody = reviewControllerInvoke('actionReportReviewUpdate', [$oldestPending], [], 'invalid-json-shape');
reviewControllerAssert(Yii::$app->response->statusCode === 422, 'PATCH must reject a non-object body without raising an offset error.');
$listBody = reviewControllerInvoke('actionReportReviewUpdate', [$oldestPending], [], [['status' => 'resolved']]);
reviewControllerAssert(Yii::$app->response->statusCode === 422, 'PATCH must reject a JSON list body.');
$invalidTakeoverReview = reviewControllerSeed(6, '2026-07-21 10:15:00');
reviewControllerInvoke('actionReportReviewClaim', [$invalidTakeoverReview]);
$invalidTakeover = reviewControllerInvoke('actionReportReviewUpdate', [$invalidTakeoverReview], [], [
    'status' => 'resolved', 'disposition' => 'acceptable', 'takeover' => 'sometimes',
]);
reviewControllerAssert(Yii::$app->response->statusCode === 422, 'PATCH must reject a non-boolean takeover value.');

$incompleteJobId = '40000000-0000-4000-8000-000000000001';
$completeJobId = '40000000-0000-4000-8000-000000000002';
Yii::$app->db->createCommand()->batchInsert('query_jobs', ['id', 'user_id', 'status', 'created_at', 'completed_at'], [
    [$incompleteJobId, 7, 'running', '2026-07-21 10:30:00', null],
    [$completeJobId, 7, 'completed', '2026-07-21 10:30:00', '2026-07-21 10:31:00'],
])->execute();
$incompleteSupersession = reviewControllerInvoke('actionReportReviewUpdate', [$oldestPending], [], [
    'status' => 'resolved', 'disposition' => 'generation_defect', 'advisoryState' => 'superseded', 'supersededByJobId' => $incompleteJobId,
]);
reviewControllerAssert(Yii::$app->response->statusCode === 422, 'Supersession must reject a replacement job that is not completed.');

$superseded = reviewControllerInvoke('actionReportReviewUpdate', [$oldestPending], [], [
    'status' => 'resolved', 'disposition' => 'generation_defect', 'notes' => 'Use corrected output',
    'advisoryState' => 'superseded', 'supersededByJobId' => $completeJobId,
]);
reviewControllerAssert(Yii::$app->response->statusCode === 200, 'A completed replacement job should allow supersession.');
reviewControllerAssert(($superseded['status'] ?? null) === 'resolved' && ($superseded['supersededByJobId'] ?? null) === $completeJobId, 'Supersession should preserve the corrected job id.');
reviewControllerAssert((string)Yii::$app->db->createCommand('SELECT status FROM query_jobs WHERE id = :id', [':id' => $completeJobId])->queryScalar() === 'completed', 'Review updates must never mutate query job execution status.');

$cautionReview = reviewControllerSeed(4, '2026-07-21 11:00:00');
reviewControllerInvoke('actionReportReviewClaim', [$cautionReview]);
$cautioned = reviewControllerInvoke('actionReportReviewUpdate', [$cautionReview], [], [
    'status' => 'resolved', 'disposition' => 'assumption_change', 'advisoryState' => 'cautioned', 'notes' => 'Explain scope',
]);
reviewControllerAssert(($cautioned['advisoryState'] ?? null) === 'cautioned', 'Resolution should support a caution advisory.');

$dismissReview = reviewControllerSeed(5, '2026-07-21 12:00:00');
reviewControllerInvoke('actionReportReviewClaim', [$dismissReview]);
$dismissed = reviewControllerInvoke('actionReportReviewUpdate', [$dismissReview], [], [
    'status' => 'dismissed', 'disposition' => 'data_unavailable', 'notes' => 'No actionable result',
]);
reviewControllerAssert(Yii::$app->response->statusCode === 200 && ($dismissed['status'] ?? null) === 'dismissed', 'A claimed review should support validated dismissal.');

$webConfig = file_get_contents(__DIR__ . '/../config/web.php');
foreach ([
    "'GET admin/report-reviews' => 'folio-query/report-review-list'",
    "'GET admin/report-reviews/<id:[\\w-]+>' => 'folio-query/report-review-detail'",
    "'POST admin/report-reviews/<id:[\\w-]+>/claim' => 'folio-query/report-review-claim'",
    "'PATCH admin/report-reviews/<id:[\\w-]+>' => 'folio-query/report-review-update'",
] as $route) {
    reviewControllerAssert(strpos($webConfig, $route) !== false, 'Missing administrator report review route: ' . $route);
}

fwrite(STDOUT, "Folio query controller report review test passed\n");
