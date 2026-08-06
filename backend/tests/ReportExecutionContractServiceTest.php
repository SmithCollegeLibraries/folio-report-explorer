<?php

namespace yii\db {
    class ActiveRecord
    {
        private $attributes = [];

        public function __get($name) { return $this->attributes[$name] ?? null; }
        public function __set($name, $value): void { $this->attributes[$name] = $value; }
        public function hasAttribute($name): bool { return true; }
    }
}

namespace {
    require_once __DIR__ . '/../models/ReportTemplate.php';
    require_once __DIR__ . '/../models/QueryJob.php';
    require_once __DIR__ . '/../services/ReportExecutionContractService.php';

    use app\models\QueryJob;
    use app\models\ReportTemplate;
    use app\services\ReportExecutionContractService;

    function executionAssertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    function executionAssertThrows(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException $exception) {
            return;
        }
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function executionReport(array $config = []): ReportTemplate
    {
        $report = new ReportTemplate();
        $report->id = 38;
        $report->slug = 'marc-bibliographic-records-missing-tag';
        $report->parameters = '[]';
        $report->execution_config = json_encode(array_replace_recursive([
            'public_row_cap' => 100000,
            'fetch_row_limit' => 100001,
            'preserve_export_order' => true,
            'identifier_export' => [
                'source_column' => 'Instance UUID',
                'header' => 'UUID',
            ],
        ], $config));
        return $report;
    }

    function executionReportWithExactConfig(array $config): ReportTemplate
    {
        $report = executionReport();
        $report->execution_config = $config;
        return $report;
    }

    $context = [
        'marcTag' => '856',
        'locationCode' => 'SC',
        'locationName' => 'Main',
        'exportKind' => 'worklist',
    ];
    $contract = ReportExecutionContractService::fromReport(executionReport(), $context);
    executionAssertSame([
        'reportTemplateId' => 38,
        'reportSlug' => 'marc-bibliographic-records-missing-tag',
        'publicRowCap' => 100000,
        'fetchRowLimit' => 100001,
        'preserveExportOrder' => true,
        'exportKind' => 'worklist',
        'identifierExport' => [
            'sourceColumn' => 'Instance UUID',
            'header' => 'UUID',
        ],
        'downloadFilename' => 'marc-bibliographic-records-missing-tag-856-sc-main-worklist.csv',
    ], $contract, 'Stored report metadata and compiler context must produce the canonical worklist contract.');

    executionAssertSame(true, executionReport()->hasIdentifierExport(), 'Identifier export capability must come from stored execution config.');
    executionAssertSame(true, executionReport()->toDetailArray()['identifierExportAvailable'], 'Report detail must expose identifier export capability without its source column.');
    executionAssertSame(false, array_key_exists('identifierExport', executionReport()->toDetailArray()), 'Report detail must not expose protected identifier export metadata.');

    foreach ([
        ['public_row_cap' => 99999, 'fetch_row_limit' => 100001],
        ['public_row_cap' => 100001, 'fetch_row_limit' => 100002],
        ['preserve_export_order' => false],
    ] as $invalidConfig) {
        executionAssertThrows(
            function () use ($invalidConfig, $context) {
                return ReportExecutionContractService::fromReport(executionReport($invalidConfig), $context);
            },
            'Invalid execution metadata must be rejected.'
        );
    }
    foreach ([
        ['public_row_cap' => true, 'fetch_row_limit' => 2, 'preserve_export_order' => true, 'identifier_export' => ['source_column' => 'Instance UUID', 'header' => 'UUID']],
        ['public_row_cap' => 100000.0, 'fetch_row_limit' => 100001, 'preserve_export_order' => true, 'identifier_export' => ['source_column' => 'Instance UUID', 'header' => 'UUID']],
        ['public_row_cap' => 100000, 'fetch_row_limit' => 100001, 'preserve_export_order' => true],
        ['public_row_cap' => 100000, 'fetch_row_limit' => 100001, 'preserve_export_order' => true, 'identifier_export' => ['header' => 'UUID']],
        ['public_row_cap' => 100000, 'fetch_row_limit' => 100001, 'preserve_export_order' => true, 'identifier_export' => ['source_column' => 'Instance UUID']],
    ] as $invalidExactConfig) {
        executionAssertThrows(
            function () use ($invalidExactConfig, $context) {
                return ReportExecutionContractService::fromReport(executionReportWithExactConfig($invalidExactConfig), $context);
            },
            'Execution metadata must reject non-integer values and missing identifier fields.'
        );
    }
    executionAssertThrows(
        function () use ($context) {
            return ReportExecutionContractService::fromReport(executionReport(), array_replace($context, ['exportKind' => 'csv']));
        },
        'Unsupported export kinds must be rejected.'
    );
    executionAssertThrows(
        function () use ($context) {
            return ReportExecutionContractService::fromReport(executionReport(), array_replace($context, ['sourceColumn' => 'Title']));
        },
        'Clients must not select an identifier export source column.'
    );
    executionAssertSame(
        ['rows' => array_fill(0, 100000, ['Instance UUID' => 'a']), 'truncated' => false],
        ReportExecutionContractService::trimRows(array_fill(0, 100000, ['Instance UUID' => 'a']), $contract),
        'A result set at the public cap must remain complete.'
    );
    $overCapRows = array_fill(0, 100001, ['Instance UUID' => 'a']);
    $trimmed = ReportExecutionContractService::trimRows($overCapRows, $contract);
    executionAssertSame(100000, count($trimmed['rows']), 'Rows beyond the public cap must be withheld.');
    executionAssertSame(true, $trimmed['truncated'], 'The sentinel row must mark a result as truncated.');

    $validSql = 'SELECT id FROM inventory.instance__t ORDER BY id LIMIT 100001';
    executionAssertSame($validSql, ReportExecutionContractService::assertStaticExportSql($validSql, $contract), 'Validated governed SQL must be returned unchanged.');
    $quotedIdentifierSql = 'SELECT "limit" FROM inventory.instance__t ORDER BY id LIMIT 100001';
    executionAssertSame($quotedIdentifierSql, ReportExecutionContractService::assertStaticExportSql($quotedIdentifierSql, $contract), 'Quoted identifiers must not be interpreted as governed SQL clauses.');
    foreach ([
        'SELECT id FROM inventory.instance__t LIMIT 100001',
        'SELECT id FROM inventory.instance__t ORDER BY id LIMIT 1 LIMIT 100001',
        'SELECT id FROM inventory.instance__t ORDER BY id LIMIT :limit',
        'SELECT id FROM inventory.instance__t ORDER BY id LIMIT 100000',
        'SELECT id FROM inventory.instance__t ORDER BY id LIMIT 100001 OFFSET 1',
    ] as $unsafeSql) {
        executionAssertThrows(
            function () use ($unsafeSql, $contract) {
                return ReportExecutionContractService::assertStaticExportSql($unsafeSql, $contract);
            },
            'Only one terminal numeric sentinel limit after one top-level ORDER BY is permitted.'
        );
    }

    $job = new QueryJob();
    $job->metadata = json_encode([ReportExecutionContractService::METADATA_KEY => $contract]);
    executionAssertSame($contract, ReportExecutionContractService::fromJob($job), 'A job must recover its stored execution contract.');
    $job->metadata = json_encode([ReportExecutionContractService::METADATA_KEY => array_replace($contract, ['downloadFilename' => '../escape.csv'])]);
    executionAssertThrows(function () use ($job) { return ReportExecutionContractService::fromJob($job); }, 'Unsafe persisted filenames must be rejected.');
    $job->metadata = json_encode([ReportExecutionContractService::METADATA_KEY => array_replace($contract, ['reportTemplateId' => 0])]);
    executionAssertThrows(function () use ($job) { return ReportExecutionContractService::fromJob($job); }, 'Persisted contracts must require a positive report template ID.');
    $job->metadata = json_encode([ReportExecutionContractService::METADATA_KEY => array_replace($contract, ['publicRowCap' => true])]);
    executionAssertThrows(function () use ($job) { return ReportExecutionContractService::fromJob($job); }, 'Persisted contracts must reject non-integer row caps.');
    $job->metadata = json_encode([ReportExecutionContractService::METADATA_KEY => array_replace($contract, ['exportKind' => 'identifier'])]);
    executionAssertThrows(function () use ($job) { return ReportExecutionContractService::fromJob($job); }, 'Identifier contracts must use the identifier filename suffix.');
    foreach ([
        ['reportSlug' => ' marc-bibliographic-records-missing-tag'],
        ['identifierExport' => ['sourceColumn' => 'Instance UUID ', 'header' => 'UUID']],
        ['identifierExport' => ['sourceColumn' => 'Instance UUID', 'header' => ' UUID']],
    ] as $nonCanonicalPatch) {
        $job->metadata = json_encode([ReportExecutionContractService::METADATA_KEY => array_replace($contract, $nonCanonicalPatch)]);
        executionAssertThrows(function () use ($job) { return ReportExecutionContractService::fromJob($job); }, 'Persisted contract fields must already be canonical and unpadded.');
    }

    fwrite(STDOUT, "Report execution contract service tests passed\n");
}
