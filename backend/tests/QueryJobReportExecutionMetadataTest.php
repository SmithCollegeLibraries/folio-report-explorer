<?php

namespace yii\db {
    class ActiveRecord
    {
        private array $attributes = [];
        public int $saveCount = 0;

        public function __get($name) { return $this->attributes[$name] ?? null; }
        public function __set($name, $value): void { $this->attributes[$name] = $value; }
        public function hasAttribute($name): bool { return true; }
        public function save($runValidation = true): bool { $this->saveCount++; return true; }
    }
}

namespace {
    require_once __DIR__ . '/../models/QueryJob.php';

    use app\models\QueryJob;

    function queryMetadataAssertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    $contract = [
        'reportTemplateId' => 38,
        'identifierExport' => ['sourceColumn' => 'Instance UUID', 'header' => 'UUID'],
        'downloadFilename' => 'marc-bibliographic-records-missing-tag-856-sc-main-worklist.csv',
    ];
    $job = new QueryJob();
    $job->id = 'report-job';
    $job->source = 'report';
    $job->sql_text = 'SELECT 1';
    $job->metadata = json_encode(['existing' => 'preserved', 'reportExecution' => $contract]);

    queryMetadataAssertSame(
        ['existing' => 'preserved', 'reportExecution' => $contract],
        $job->getDecodedMetadata(),
        'Job metadata must decode valid JSON and preserve existing keys.'
    );
    $job->markCompleted(['Instance UUID'], [['Instance UUID' => 'a']], 12, true);
    $metadata = $job->getDecodedMetadata();
    queryMetadataAssertSame(true, $metadata['reportExecution']['truncated'] ?? null, 'Table completion must persist truncation in report execution metadata.');
    queryMetadataAssertSame(1, $job->saveCount, 'Completion metadata must be saved with the terminal state update.');
    $status = $job->toStatusArray(true);
    queryMetadataAssertSame(true, $status['truncated'] ?? null, 'Completed job status must expose truncation as a boolean.');
    queryMetadataAssertSame(false, array_key_exists('identifierExport', $status), 'Job status must not expose the identifier source-column contract.');

    $export = new QueryJob();
    $export->id = 'report-export-job';
    $export->source = 'report';
    $export->sql_text = 'SELECT 1';
    $export->metadata = json_encode(['reportExecution' => $contract]);
    $export->markExportCompleted('/tmp/report.csv', 100000, 20, ['Instance UUID'], [['Instance UUID' => 'a']], false);
    queryMetadataAssertSame(false, $export->getDecodedMetadata()['reportExecution']['truncated'] ?? null, 'Export completion must persist a false truncation flag.');
    queryMetadataAssertSame(false, $export->toStatusArray()['truncated'] ?? null, 'Export status must expose false truncation as a boolean.');

    $ordinary = new QueryJob();
    $ordinary->id = 'ordinary-job';
    $ordinary->source = 'builder';
    $ordinary->sql_text = 'SELECT 1';
    $ordinary->status = 'completed';
    $ordinary->row_count = 0;
    $ordinary->execution_time_ms = 0;
    queryMetadataAssertSame(false, array_key_exists('truncated', $ordinary->toStatusArray()), 'Ordinary jobs must retain their existing status serialization.');

    fwrite(STDOUT, "Query job report execution metadata tests passed\n");
}
