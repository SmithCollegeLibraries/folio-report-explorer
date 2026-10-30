<?php

require_once __DIR__ . '/../services/ReportRunTitleService.php';

final class SyntheticReportRunTitleReport
{
    public $name;
    public $slug;
    private $parameters;

    public function __construct($name, array $parameters, $slug = 'synthetic-report')
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->parameters = $parameters;
    }

    public function getDecodedParameters()
    {
        return $this->parameters;
    }
}

final class SyntheticReportRunTitleOptionSource
{
    public $selectOptions = [];
    public $lookupFailures = [];
    public $selectCalls = [];
    public $expenseClassOptions = [];
    public $expenseClassCalls = 0;

    public function getOptions($report, array $definition)
    {
        $name = $definition['name'];
        $this->selectCalls[] = $name;
        if (!empty($this->lookupFailures[$name])) {
            throw new RuntimeException('synthetic options lookup failure');
        }
        return $this->selectOptions[$name] ?? [];
    }

    public function getExpenseClassOptions($report, array $params)
    {
        $this->expenseClassCalls++;
        return $this->expenseClassOptions;
    }
}

$reportRunTitleFailures = [];

function reportRunTitleAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

function reportRunTitleCase($name, $callback)
{
    global $reportRunTitleFailures;
    try {
        $callback();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        $reportRunTitleFailures[] = $name;
        fwrite(STDERR, "FAIL: {$name}: " . $error->getMessage() . "\n");
    }
}

function reportRunTitleIdentityNormalizer($title)
{
    return $title;
}

reportRunTitleCase('select, text, number, date, empty optional, and up to three multi-select labels', function () {
    $report = new SyntheticReportRunTitleReport('Holdings Report', [
        ['name' => 'campus', 'type' => 'select', 'options_sql' => 'synthetic campus options'],
        ['name' => 'search', 'type' => 'text'],
        ['name' => 'fiscalYear', 'type' => 'number'],
        ['name' => 'asOf', 'type' => 'date'],
        ['name' => 'optionalNote', 'type' => 'text', 'required' => false],
        ['name' => 'funds', 'type' => 'multiselect', 'options_sql' => 'synthetic fund options'],
    ]);
    $optionSource = new SyntheticReportRunTitleOptionSource();
    $optionSource->selectOptions = [
        'campus' => [['value' => 'SC', 'label' => 'Smith College']],
        'funds' => [
            ['value' => 'fund-a', 'label' => 'General Fund'],
            ['value' => 'fund-b', 'label' => 'Special Collections'],
        ],
    ];
    $service = new \app\services\ReportRunTitleService($optionSource, 'reportRunTitleIdentityNormalizer');

    $title = $service->buildTitle($report, [
        'funds' => ['fund-a', 'fund-b'],
        'optionalNote' => '',
        'asOf' => '2026-06-30',
        'fiscalYear' => '2027',
        'search' => 'journals',
        'campus' => 'SC',
    ]);

    reportRunTitleAssertSame(
        'Holdings Report — Smith College, journals, 2027, 2026-06-30, General Fund, Special Collections',
        $title,
        'Use report parameter order and form labels while preserving scalar values and dates.'
    );
    reportRunTitleAssertSame(['campus', 'funds'], $optionSource->selectCalls, 'Look up options only for valued select parameters and at most once each.');
});

reportRunTitleCase('more than three multi-select values use a count', function () {
    $report = new SyntheticReportRunTitleReport('Fund Report', [
        ['name' => 'funds', 'type' => 'multiselect', 'options_sql' => 'synthetic fund options'],
    ]);
    $optionSource = new SyntheticReportRunTitleOptionSource();
    $service = new \app\services\ReportRunTitleService($optionSource, 'reportRunTitleIdentityNormalizer');

    reportRunTitleAssertSame(
        'Fund Report — 4 selected',
        $service->buildTitle($report, ['funds' => ['a', 'b', 'c', 'd']]),
        'Summarize a multi-select with more than three selections.'
    );
});

reportRunTitleCase('option lookup failure falls back to the raw value', function () {
    $report = new SyntheticReportRunTitleReport('Campus Report', [
        ['name' => 'campus', 'type' => 'select', 'options_sql' => 'synthetic campus options'],
    ]);
    $optionSource = new SyntheticReportRunTitleOptionSource();
    $optionSource->lookupFailures['campus'] = true;
    $service = new \app\services\ReportRunTitleService($optionSource, 'reportRunTitleIdentityNormalizer');

    reportRunTitleAssertSame(
        'Campus Report — unknown-campus',
        $service->buildTitle($report, ['campus' => 'unknown-campus']),
        'Keep the submitted value when its option label cannot be loaded.'
    );
});

reportRunTitleCase('Budget Year Expense Class college and fund labels use expense class scope options', function () {
    $report = new SyntheticReportRunTitleReport('Budget Year Expense Class Report', [
        ['name' => 'acqUnit', 'type' => 'select'],
        ['name' => 'funds', 'type' => 'multiselect'],
    ], 'budget-year-expense-class');
    $optionSource = new SyntheticReportRunTitleOptionSource();
    $optionSource->expenseClassOptions = [
        'prefix' => 'SC',
        'funds' => [['id' => 'synthetic-sc-fund', 'label' => 'SCBPS — Books']],
    ];
    $service = new \app\services\ReportRunTitleService($optionSource, 'reportRunTitleIdentityNormalizer');

    reportRunTitleAssertSame(
        'Budget Year Expense Class Report — SC, SCBPS — Books',
        $service->buildTitle($report, ['acqUnit' => 'SC', 'funds' => ['synthetic-sc-fund']]),
        'Render the college and fund values using ExpenseClassScopeService option labels.'
    );
    reportRunTitleAssertSame(1, $optionSource->expenseClassCalls, 'Load the special report scope options once.');
    reportRunTitleAssertSame([], $optionSource->selectCalls, 'Do not query generic options for the special report pickers.');
});

reportRunTitleCase('identifier export adds a distinguishing suffix', function () {
    $report = new SyntheticReportRunTitleReport('Identifier Export', []);
    $optionSource = new SyntheticReportRunTitleOptionSource();
    $service = new \app\services\ReportRunTitleService($optionSource, 'reportRunTitleIdentityNormalizer');

    reportRunTitleAssertSame(
        'Identifier Export (identifiers)',
        $service->buildTitle($report, [], true),
        'Mark identifier-only exports in the title.'
    );
});

reportRunTitleCase('reportTemplateId merges into metadata without losing reportExecution or composite_config', function () {
    $report = new SyntheticReportRunTitleReport('Metadata Report', []);
    $optionSource = new SyntheticReportRunTitleOptionSource();
    $service = new \app\services\ReportRunTitleService($optionSource, 'reportRunTitleIdentityNormalizer');
    $metadata = [
        'reportExecution' => ['exportKind' => 'identifier', 'truncated' => false],
        'composite_config' => ['secondary_sql' => 'SELECT 1'],
    ];

    reportRunTitleAssertSame(
        [
            'reportExecution' => ['exportKind' => 'identifier', 'truncated' => false],
            'composite_config' => ['secondary_sql' => 'SELECT 1'],
            'reportTemplateId' => 42,
        ],
        $service->mergeReportTemplateId($metadata, 42),
        'Add reportTemplateId while retaining metadata owned by report execution and composite reports.'
    );
});

if (!empty($reportRunTitleFailures)) {
    fwrite(STDERR, count($reportRunTitleFailures) . " report-run title service case(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "Report run title service tests passed (6 cases).\n");
