<?php

defined('YII_ENV') or define('YII_ENV', 'test');
defined('YII_DEBUG') or define('YII_DEBUG', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

use app\commands\CleanupController;
use app\services\SettingsService;
use yii\console\Application;

class FixedReviewCleanupController extends CleanupController
{
    protected function reviewCleanupNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-21 12:00:00', new DateTimeZone('UTC'));
    }
}

new Application([
    'id' => 'ask-ai-review-retention-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
    ],
]);
Yii::$app->errorHandler->unregister();

$db = Yii::$app->db;
$db->createCommand('PRAGMA foreign_keys = ON')->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_generations (
    id VARCHAR(36) PRIMARY KEY,
    query_job_id VARCHAR(36) NULL,
    created_at DATETIME NOT NULL
)
SQL)->execute();
$db->createCommand(<<<'SQL'
CREATE TABLE ai_report_reviews (
    id VARCHAR(36) PRIMARY KEY,
    generation_id VARCHAR(36) NOT NULL,
    status VARCHAR(20) NOT NULL,
    resolved_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (generation_id) REFERENCES ai_report_generations(id) ON DELETE CASCADE
)
SQL)->execute();

function retentionAssert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function insertRetentionReview($ageDays)
{
    $generationId = 'generation-' . $ageDays;
    $timestamp = (new DateTimeImmutable('2026-07-21 12:00:00', new DateTimeZone('UTC')))
        ->modify('-' . $ageDays . ' days')
        ->format('Y-m-d H:i:s');
    Yii::$app->db->createCommand()->insert('ai_report_generations', [
        'id' => $generationId,
        'query_job_id' => 'linked-job-' . $ageDays,
        'created_at' => $timestamp,
    ])->execute();
    Yii::$app->db->createCommand()->insert('ai_report_reviews', [
        'id' => 'review-' . $ageDays,
        'generation_id' => $generationId,
        'status' => 'resolved',
        'resolved_at' => $timestamp,
        'updated_at' => $timestamp,
    ])->execute();
}

foreach ([89, 90, 91] as $ageDays) {
    insertRetentionReview($ageDays);
}

$cache = new ReflectionProperty(SettingsService::class, 'cache');
if (PHP_VERSION_ID < 80100) {
    $cache->setAccessible(true);
}
$cache->setValue(null, ['ai_report_review_retention_days' => 90]);

$controller = new FixedReviewCleanupController('cleanup', Yii::$app);
$result = $controller->actionReviews();
retentionAssert($result === yii\console\ExitCode::OK, 'Review cleanup should complete successfully.');
retentionAssert(
    (int) $db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => 'generation-89'])->queryScalar() === 1,
    'A review resolved 89 days ago must remain.'
);
retentionAssert(
    (int) $db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => 'generation-90'])->queryScalar() === 1,
    'A review exactly at the 90-day cutoff must remain.'
);
retentionAssert(
    (int) $db->createCommand('SELECT COUNT(*) FROM ai_report_generations WHERE id = :id', [':id' => 'generation-91'])->queryScalar() === 0,
    'A review resolved 91 days ago must be purged.'
);
retentionAssert(
    (int) $db->createCommand('SELECT COUNT(*) FROM ai_report_reviews WHERE id = :id', [':id' => 'review-91'])->queryScalar() === 0,
    'Retention must remove sensitive review notes with the expired generation.'
);

$cache->setValue(null, null);

fwrite(STDOUT, "Ask AI review retention test passed\n");
