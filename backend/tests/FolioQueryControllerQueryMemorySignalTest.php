<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\controllers\FolioQueryController;
use yii\web\Application;
use yii\web\IdentityInterface;

final class QueryMemorySignalIdentity implements IdentityInterface
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
    'id' => 'query-memory-signal-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'query-memory-signal-test'],
        'user' => ['identityClass' => QueryMemorySignalIdentity::class, 'enableSession' => false],
    ],
]);
Yii::$app->errorHandler->unregister();
Yii::$app->user->setIdentity(new QueryMemorySignalIdentity(17));

$db = Yii::$app->db;
$db->createCommand('CREATE TABLE query_jobs (id VARCHAR(36) PRIMARY KEY, user_id INTEGER, status VARCHAR(20), source VARCHAR(20))')->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_generations (
    id VARCHAR(36) PRIMARY KEY,
    query_job_id VARCHAR(36),
    user_id INTEGER,
    provenance_json TEXT,
    saved_count INTEGER NOT NULL DEFAULT 0,
    downloaded_count INTEGER NOT NULL DEFAULT 0,
    rerun_count INTEGER NOT NULL DEFAULT 0,
    follow_up_count INTEGER NOT NULL DEFAULT 0
)
SQL)->execute();
$db->createCommand('CREATE TABLE ai_query_feedback (id INTEGER PRIMARY KEY, generation_id VARCHAR(36), result_accuracy VARCHAR(20), reuse_suppressed INTEGER, admin_reuse_approved_at DATETIME)')->execute();
$db->createCommand()->insert('query_jobs', [
    'id' => 'job-1',
    'user_id' => 17,
    'status' => 'completed',
    'source' => 'nl',
])->execute();
$db->createCommand()->insert('ai_report_generations', [
    'id' => 'generation-1',
    'query_job_id' => 'job-1',
    'user_id' => 17,
    'provenance_json' => json_encode(['generationProvenance' => 'ai_built']),
])->execute();
$db->createCommand()->insert('ai_query_feedback', [
    'id' => 1,
    'generation_id' => 'generation-1',
    'result_accuracy' => null,
    'reuse_suppressed' => 0,
    'admin_reuse_approved_at' => null,
])->execute();

function signalAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function postSignal(string $signal): array
{
    Yii::$app->request->setBodyParams([
        'generationId' => 'generation-1',
        'queryJobId' => 'job-1',
        'signal' => $signal,
    ]);
    Yii::$app->response->statusCode = 200;
    return (new FolioQueryController('folio-query', Yii::$app))->actionQueryMemorySignal();
}

foreach ([
    'saved' => 'saved_count',
    'downloaded' => 'downloaded_count',
    'rerun' => 'rerun_count',
    'follow_up' => 'follow_up_count',
] as $signal => $column) {
    $response = postSignal($signal);
    signalAssert(($response['count'] ?? null) === 1, "{$signal} must atomically increment its allowlisted counter.");
    signalAssert((int)$db->createCommand("SELECT {$column} FROM ai_report_generations WHERE id = 'generation-1'")->queryScalar() === 1, "{$signal} must update only {$column}.");
}
$duplicate = postSignal('saved');
signalAssert(($duplicate['count'] ?? null) === 2, 'A duplicate browser retry may increment the weak counter again.');

$invalid = postSignal('accurate');
signalAssert(Yii::$app->response->statusCode === 422 && isset($invalid['error']), 'Signals outside the allowlist must be rejected.');

Yii::$app->user->setIdentity(new QueryMemorySignalIdentity(18));
$forbidden = postSignal('saved');
signalAssert(Yii::$app->response->statusCode === 403 && isset($forbidden['error']), 'A user must not update another user\'s generation signals.');

$generation = $db->createCommand("SELECT * FROM ai_report_generations WHERE id = 'generation-1'")->queryOne();
$feedback = $db->createCommand('SELECT * FROM ai_query_feedback WHERE id = 1')->queryOne();
signalAssert(json_decode($generation['provenance_json'], true)['generationProvenance'] === 'ai_built', 'Weak signals must not change immutable provenance.');
signalAssert($feedback['result_accuracy'] === null, 'Weak signals must not assert accuracy.');
signalAssert((int)$feedback['reuse_suppressed'] === 0, 'Weak signals must not change suppression.');
signalAssert($feedback['admin_reuse_approved_at'] === null, 'Weak signals must not create administrator approval.');

echo "FolioQueryController query-memory signal test passed\n";
