<?php

$root = dirname(__DIR__, 2);
$migrationPath = $root . '/mysql/migrations/039_ask_ai_report_review.sql';
$initPath = $root . '/mysql/init.sql';
$generationModelPath = $root . '/backend/models/AiReportGeneration.php';
$reviewModelPath = $root . '/backend/models/AiReportReview.php';
$settingsPath = $root . '/backend/services/SettingsService.php';
$paramsPath = $root . '/backend/config/params.php';

function assertAskSchemaTrue($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

function assertAskSchemaContains($needle, $haystack, $message)
{
    assertAskSchemaTrue(strpos($haystack, $needle) !== false, $message . "\nMissing text: " . $needle);
}

assertAskSchemaTrue(file_exists($migrationPath), 'Migration 039 must exist.');
$migration = file_get_contents($migrationPath);
$init = file_get_contents($initPath);

foreach ([$migration, $init] as $schema) {
    assertAskSchemaContains('CREATE TABLE IF NOT EXISTS ai_report_generations', $schema, 'Generation table must be idempotently created.');
    assertAskSchemaContains('CREATE TABLE IF NOT EXISTS ai_report_reviews', $schema, 'Review table must be idempotently created.');

    foreach ([
        'id CHAR(36) PRIMARY KEY', 'conversation_id CHAR(36) NOT NULL', 'parent_generation_id CHAR(36) NULL',
        'query_job_id CHAR(36) NULL', 'user_id INT NULL', 'prompt_fingerprint CHAR(16) NOT NULL',
        'original_question TEXT NOT NULL', 'follow_up_context JSON NULL', 'response_mode VARCHAR(32) NULL',
        "execution_mode ENUM('deterministic','exploratory') NULL", 'route VARCHAR(128) NULL',
        'route_reason VARCHAR(255) NULL', "validation_status ENUM('validated','exhausted','rejected') NULL",
        'generated_sql MEDIUMTEXT NULL', 'sql_hash CHAR(64) NULL', 'assumptions_json JSON NULL',
        'user_notice_json JSON NULL', 'confidence_evidence_json JSON NOT NULL', 'initial_structure_json JSON NULL',
        'final_structure_json JSON NULL', 'provenance_json JSON NOT NULL',
        'review_required TINYINT(1) NOT NULL DEFAULT 0', 'review_reasons_json JSON NOT NULL',
        'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'linked_at DATETIME NULL',
        'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ] as $definition) {
        assertAskSchemaContains($definition, $schema, 'Generation schema is incomplete.');
    }

    foreach ([
        'generation_id CHAR(36) NOT NULL UNIQUE',
        "status ENUM('pending','in_review','resolved','dismissed') NOT NULL DEFAULT 'pending'",
        "disposition ENUM('acceptable','assumption_change','deterministic_candidate','generation_defect','data_unavailable','specialist_interpretation') NULL",
        "advisory_state ENUM('none','cautioned','superseded') NOT NULL DEFAULT 'none'",
        'superseded_by_job_id CHAR(36) NULL', 'administrator_notes TEXT NULL', 'reviewed_by INT NULL',
        'claimed_at DATETIME NULL', 'resolved_at DATETIME NULL',
    ] as $definition) {
        assertAskSchemaContains($definition, $schema, 'Review schema is incomplete.');
    }

    foreach ([
        'FOREIGN KEY (parent_generation_id) REFERENCES ai_report_generations(id) ON DELETE SET NULL',
        'FOREIGN KEY (query_job_id) REFERENCES query_jobs(id) ON DELETE SET NULL',
        'FOREIGN KEY (generation_id) REFERENCES ai_report_generations(id) ON DELETE CASCADE',
        'FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL',
        'FOREIGN KEY (superseded_by_job_id) REFERENCES query_jobs(id) ON DELETE SET NULL',
        'INDEX idx_ai_report_generations_conversation (conversation_id)',
        'INDEX idx_ai_report_generations_parent (parent_generation_id)',
        'INDEX idx_ai_report_generations_job (query_job_id)',
        'INDEX idx_ai_report_generations_user (user_id)',
        'INDEX idx_ai_report_generations_created (created_at)',
        'INDEX idx_ai_report_generations_review_required (review_required)',
        'INDEX idx_ai_report_reviews_status_created (status, created_at)',
        'INDEX idx_ai_report_reviews_disposition (disposition)',
        'INDEX idx_ai_report_reviews_advisory_state (advisory_state)',
    ] as $constraint) {
        assertAskSchemaContains($constraint, $schema, 'Schema constraint or index is missing.');
    }
}

assertAskSchemaTrue(file_exists($generationModelPath), 'AiReportGeneration model must exist.');
assertAskSchemaTrue(file_exists($reviewModelPath), 'AiReportReview model must exist.');
$generationModel = file_get_contents($generationModelPath);
$reviewModel = file_get_contents($reviewModelPath);
assertAskSchemaContains("return 'ai_report_generations';", $generationModel, 'Generation model must use the generation table.');
assertAskSchemaContains("return 'ai_report_reviews';", $reviewModel, 'Review model must use the review table.');
assertAskSchemaContains('function generateUuid()', $generationModel, 'Generation model must provide UUID v4 generation.');
assertAskSchemaContains('function generateUuid()', $reviewModel, 'Review model must provide UUID v4 generation.');
assertAskSchemaContains("'deterministic', 'exploratory'", $generationModel, 'Generation model must validate execution modes.');
assertAskSchemaContains("'validated', 'exhausted', 'rejected'", $generationModel, 'Generation model must validate validation statuses.');
assertAskSchemaContains("'pending', 'in_review', 'resolved', 'dismissed'", $reviewModel, 'Review model must validate review statuses.');
assertAskSchemaContains("'none', 'cautioned', 'superseded'", $reviewModel, 'Review model must validate advisory states.');

require_once $settingsPath;
$cache = new ReflectionProperty(app\services\SettingsService::class, 'cache');
if (PHP_VERSION_ID < 80100) {
    $cache->setAccessible(true);
}
$retentionEnvironment = getenv('AI_REPORT_REVIEW_RETENTION_DAYS');
putenv('AI_REPORT_REVIEW_RETENTION_DAYS');
foreach ([[null, 90], [0, 1], [-5, 1], [3651, 3650], [180, 180]] as $case) {
    list($configured, $expected) = $case;
    $cache->setValue(null, $configured === null ? [] : ['ai_report_review_retention_days' => $configured]);
    assertAskSchemaTrue(
        app\services\SettingsService::getAiReportReviewRetentionDays() === $expected,
        'Retention setting must default to 90 and clamp to 1..3650.'
    );
}
$cache->setValue(null, null);
if ($retentionEnvironment === false) {
    putenv('AI_REPORT_REVIEW_RETENTION_DAYS');
} else {
    putenv('AI_REPORT_REVIEW_RETENTION_DAYS=' . $retentionEnvironment);
}
$params = file_get_contents($paramsPath);
assertAskSchemaContains("'aiReportReviewRetentionDays' => SettingsService::getAiReportReviewRetentionDays()", $params, 'Application params must expose review retention.');

fwrite(STDOUT, "Ask AI report review schema test passed\n");
