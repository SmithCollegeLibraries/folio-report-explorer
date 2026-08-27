<?php

if (!class_exists('Yii')) {
    class Yii
    {
        public static $messages = [];

        public static function info($message, $category = null): void
        {
            self::$messages[] = (string)$message;
        }
    }
}

require_once __DIR__ . '/../exceptions/ExploratorySqlValidationException.php';
require_once __DIR__ . '/../services/AskRequestPolicyService.php';
require_once __DIR__ . '/../services/AskGenerationCoordinatorService.php';

use app\services\AskGenerationCoordinatorService;

function telemetryAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$question = 'Summarize vendor receipt time.';
$rejectedSql = 'DELETE FROM orders.pieces__t';
$resultRows = [['vendor' => 'Sensitive test vendor', 'received_line_count' => 42]];

AskGenerationCoordinatorService::run(
    $question,
    function () use ($rejectedSql): array {
        return [
            'state' => 'candidate_rejected',
            'reason' => 'non select',
            'candidateSqlHash' => hash('sha256', $rejectedSql),
        ];
    },
    function (): array {
        return [
            'state' => 'handled',
            'reason' => 'replacement_validated',
            'result' => ['sql' => 'SELECT vendor FROM orders.pieces__t', 'generationProvenance' => 'ai_built'],
        ];
    }
);

$records = [];
foreach (Yii::$messages as $message) {
    if (strpos($message, 'NL2SQL telemetry: ') !== 0) {
        continue;
    }
    $record = json_decode(substr($message, strlen('NL2SQL telemetry: ')), true);
    if (($record['event'] ?? null) === 'nl2sql.coordinator_transition') {
        $records[] = $record;
    }
}

telemetryAssertTrue(count($records) >= 3, 'Coordinator telemetry must record request, rejection, and recovery transitions.');
$rejection = null;
$recovery = null;
foreach ($records as $record) {
    if (($record['toState'] ?? null) === 'candidate_rejected') {
        $rejection = $record;
    }
    if (($record['fromState'] ?? null) === 'candidate_rejected'
        && ($record['toState'] ?? null) === 'handled'
    ) {
        $recovery = $record;
    }
}
telemetryAssertTrue(is_array($rejection), 'Candidate rejection transition telemetry is required.');
telemetryAssertTrue(($rejection['reason'] ?? null) === 'non_select', 'Telemetry reasons must be normalized.');
telemetryAssertTrue(($rejection['candidateSqlHash'] ?? null) === hash('sha256', $rejectedSql), 'Rejected SQL must be represented only by its hash.');
telemetryAssertTrue(is_array($recovery), 'Candidate rejection must be followed by a handled transition.');
telemetryAssertTrue(preg_match('/^[a-f0-9]{16}$/', (string)($recovery['promptFingerprint'] ?? '')) === 1, 'Coordinator telemetry needs a prompt fingerprint.');

$encodedLogs = json_encode(Yii::$messages);
telemetryAssertTrue(strpos($encodedLogs, $question) === false, 'Raw prompts must not appear in coordinator telemetry.');
telemetryAssertTrue(strpos($encodedLogs, $rejectedSql) === false, 'Raw rejected SQL must not appear in coordinator telemetry.');
telemetryAssertTrue(strpos($encodedLogs, json_encode($resultRows)) === false, 'Result rows must not appear in coordinator telemetry.');

fwrite(STDOUT, "AskGenerationCoordinator telemetry test passed\n");
