<?php

require_once __DIR__ . '/../services/BudgetYearFundReportMigrationRecognitionService.php';
require_once __DIR__ . '/../services/MigrationService.php';

use app\services\BudgetYearFundReportMigrationRecognitionService;

$failures = [];

function budgetRecognitionAssert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function budgetRecognitionAliases(): array
{
    return [
        'Fund Code', 'Fund Name', 'Fiscal Year', 'Allocated', 'Payments',
        'Calculated Current Encumbrances', 'Unreleased Count', 'Released Encumbrances',
        'Released Count', 'Total Committed', 'Calculated Remaining', 'FOLIO Expenditures',
        'FOLIO Encumbered', 'FOLIO Available',
    ];
}

function budgetRecognitionSqlMarkers(): array
{
    return [
        'series_marker' => "fy.series = au.code || 'FY'",
        'payment_type_marker' => "LOWER(COALESCE(fd.fund_distributions__distribution_type, 'percentage'))",
        'payment_amount_marker' => "WHEN 'amount' THEN COALESCE(fd.fund_distributions__value, 0)",
        'payment_percentage_marker' => 'COALESCE(fd.total, 0) * (COALESCE(fd.fund_distributions__value, 0) * 0.01)',
        'encumbrance_scope_marker' => "t.encumbrance__status IN ('Unreleased', 'Active', 'Released')",
        'encumbrance_current_marker' => "SUM(CASE WHEN t.encumbrance__status IN ('Unreleased', 'Active') THEN COALESCE(t.encumbrance__initial_amount_encumbered, 0) - COALESCE(t.encumbrance__amount_expended, 0) - COALESCE(t.encumbrance__amount_awaiting_payment, 0) ELSE 0 END) AS current_encumbrance",
        'unreleased_count_marker' => "SUM(CASE WHEN t.encumbrance__status = 'Unreleased' THEN 1 ELSE 0 END) AS unreleased_count",
        'released_amount_marker' => "SUM(CASE WHEN t.encumbrance__status = 'Released' THEN COALESCE(t.encumbrance__initial_amount_encumbered, 0) ELSE 0 END) AS released_encumbrances",
        'released_count_marker' => "SUM(CASE WHEN t.encumbrance__status = 'Released' THEN 1 ELSE 0 END) AS released_count",
        'encumbrance_group_marker' => 'GROUP BY t.from_fund_id, t.fiscal_year_id',
        'fund_year_join_marker' => 'e.fund_id = b.fund_id AND e.fiscal_year_id = b.fiscal_year_id',
        'unreleased_count_output_marker' => 'COALESCE(e.unreleased_count, 0) AS "Unreleased Count"',
        'released_encumbrances_output_marker' => 'ROUND(COALESCE(e.released_encumbrances, 0), 2) AS "Released Encumbrances"',
        'released_count_output_marker' => 'COALESCE(e.released_count, 0) AS "Released Count"',
        'encumbrance_left_join_marker' => "LEFT JOIN encumbrances e\n  ON e.fund_id = b.fund_id\n AND e.fiscal_year_id = b.fiscal_year_id",
        'calculated_remaining_marker' => 'COALESCE(b.allocated, 0) - COALESCE(p.payment, 0) - COALESCE(e.current_encumbrance, 0)',
    ];
}

function budgetRecognitionHelpMarkers(): array
{
    return [
        'help_allocated' => 'Allocated: the allocation total stored on the FOLIO budget.',
        'help_payments' => 'Payments: paid invoice-line fund distributions whose invoice payment date falls inside the resolved FOLIO fiscal year.',
        'help_payment_semantics' => 'Percentage distributions contribute the invoice-line total multiplied by the distribution value divided by 100; amount distributions contribute the distribution value directly; distribution types are matched case-insensitively, and a missing type is treated as percentage.',
        'help_encumbrances' => 'Calculated Current Encumbrances: the unreleased amount, calculated as initial amount minus expended amount minus awaiting-payment amount.',
        'help_unreleased_count' => 'Unreleased Count: number of encumbrance transactions whose status is Unreleased.',
        'help_released_amount' => 'Released Encumbrances: sum of initial amounts for encumbrance transactions whose status is Released.',
        'help_released_count' => 'Released Count: number of encumbrance transactions whose status is Released.',
        'help_pending' => 'Pending transactions are excluded from the Unreleased Count, Released Encumbrances, and Released Count.',
        'help_total_committed' => 'Total Committed: Payments plus Calculated Current Encumbrances.',
        'help_calculated_remaining' => 'Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.',
        'help_folio_expenditures' => 'FOLIO Expenditures: the expenditure total currently stored on the FOLIO budget.',
        'help_folio_encumbered' => 'FOLIO Encumbered: the encumbrance total currently stored on the FOLIO budget.',
        'help_folio_available' => 'FOLIO Available: the operational available balance stored on the FOLIO budget.',
    ];
}

function budgetRecognitionDescription(): string
{
    return 'Shows allocation, payments, released and unreleased encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.';
}

function budgetRecognitionParameters(): array
{
    return [
        [
            'name' => 'fiscalYearEndYear', 'type' => 'select', 'label' => 'Fiscal Year', 'default' => '',
            'description' => 'Campus-neutral fiscal year; the selected acquisition unit determines the matching FOLIO fiscal-year series.',
            'options_sql' => "SELECT DISTINCT EXTRACT(YEAR FROM period_end::date)::int AS value, 'FY' || EXTRACT(YEAR FROM period_end::date)::int::text AS label FROM finance.fiscal_year__t WHERE period_end IS NOT NULL ORDER BY value DESC",
            'options_db' => 'folio', 'placeholder' => 'Select fiscal year', 'required' => true,
        ],
        [
            'name' => 'acqUnitId', 'type' => 'select', 'label' => 'Acquisition Unit', 'default' => '',
            'description' => 'Determines both fund membership and the campus fiscal-year series.',
            'options_sql' => 'SELECT id AS value, TRIM(name) AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY TRIM(name)',
            'options_db' => 'folio', 'placeholder' => 'Select acquisition unit', 'required' => true,
        ],
    ];
}

function budgetRecognitionAliasOrderFragments(): array
{
    $fragments = [];
    $aliases = budgetRecognitionAliases();
    for ($index = 0; $index < count($aliases) - 1; $index++) {
        $fragments[] = "AND LOCATE('AS \"{$aliases[$index]}\"', sql_template) < LOCATE('AS \"{$aliases[$index + 1]}\"', sql_template)";
    }
    return $fragments;
}

function budgetRecognitionDefinition(array $overrides = []): array
{
    $sqlMarkers = budgetRecognitionSqlMarkers();
    $helpMarkers = budgetRecognitionHelpMarkers();
    $select = [];
    $selectExpressions = [
        'Unreleased Count' => $sqlMarkers['unreleased_count_output_marker'],
        'Released Encumbrances' => $sqlMarkers['released_encumbrances_output_marker'],
        'Released Count' => $sqlMarkers['released_count_output_marker'],
    ];
    foreach (budgetRecognitionAliases() as $alias) {
        $select[] = $selectExpressions[$alias] ?? ('0 AS "' . $alias . '"');
    }
    $sqlDefinitionMarkers = $sqlMarkers;
    unset(
        $sqlDefinitionMarkers['unreleased_count_output_marker'],
        $sqlDefinitionMarkers['released_encumbrances_output_marker'],
        $sqlDefinitionMarkers['released_count_output_marker']
    );
    $sql = implode("\n", $sqlDefinitionMarkers) . "\nSELECT " . implode(",\n       ", $select)
        . "\nFROM budgets b\n{$sqlMarkers['encumbrance_left_join_marker']}";
    $help = implode(' ', $helpMarkers);
    return array_merge([
        'id' => 37,
        'slug' => 'budget-year-fund-report',
        'name' => 'Budget Year Fund Report',
        'description' => budgetRecognitionDescription(),
        'help_text' => $help,
        'category' => 'finance',
        'sql_template' => $sql,
        'parameters' => [
            budgetRecognitionParameters()[0],
            budgetRecognitionParameters()[1],
        ],
        'data_source' => 'folio',
        'default_limit' => 1000,
        'is_active' => 1,
        'created_by' => 'manual',
    ], $overrides);
}

function budgetRecognitionRequiredWhereFragments(): array
{
    $fragments = [
        'id = 37 AND slug = :slug',
        'AND name = :name',
        'AND description = :description',
        'AND category = :category',
        'AND data_source = :data_source',
        'AND default_limit = :default_limit',
        'AND is_active = 1',
        'AND created_by = :created_by',
        'AND JSON_LENGTH(parameters) = 2',
    ];
    $parameterFields = ['name', 'type', 'label', 'default', 'description', 'options_sql', 'options_db', 'placeholder'];
    foreach ([0 => 'fiscal_year', 1 => 'acq_unit'] as $index => $prefix) {
        foreach ($parameterFields as $field) {
            $fragments[] = "AND BINARY JSON_UNQUOTE(JSON_EXTRACT(parameters, '$[{$index}].{$field}')) = BINARY :{$prefix}_{$field}";
        }
        $fragments[] = "AND JSON_EXTRACT(parameters, '$[{$index}].required') = true";
    }
    foreach (budgetRecognitionAliasOrderFragments() as $fragment) {
        $fragments[] = $fragment;
    }
    foreach (budgetRecognitionAliases() as $index => $alias) {
        $fragments[] = 'AND sql_template LIKE :output_alias_' . $index;
    }
    foreach (budgetRecognitionSqlMarkers() as $marker => $text) {
        $fragments[] = 'AND sql_template LIKE :' . $marker;
    }
    foreach (budgetRecognitionHelpMarkers() as $marker => $text) {
        $fragments[] = 'AND help_text LIKE :' . $marker;
    }
    return $fragments;
}

function budgetRecognitionExpectedParameters(): array
{
    $params = [
        ':slug' => 'budget-year-fund-report',
        ':name' => 'Budget Year Fund Report',
        ':description' => budgetRecognitionDescription(),
        ':category' => 'finance',
        ':data_source' => 'folio',
        ':default_limit' => 1000,
        ':created_by' => 'manual',
    ];
    $parameterFields = ['name', 'type', 'label', 'default', 'description', 'options_sql', 'options_db', 'placeholder'];
    foreach ([0 => 'fiscal_year', 1 => 'acq_unit'] as $index => $prefix) {
        foreach ($parameterFields as $field) {
            $params[":" . $prefix . '_' . $field] = budgetRecognitionParameters()[$index][$field];
        }
    }
    foreach (budgetRecognitionAliases() as $index => $alias) {
        $params[':output_alias_' . $index] = '%AS "' . $alias . '"%';
    }
    foreach (budgetRecognitionSqlMarkers() as $marker => $text) {
        $params[':' . $marker] = '%' . $text . '%';
    }
    foreach (budgetRecognitionHelpMarkers() as $marker => $text) {
        $params[':' . $marker] = '%' . $text . '%';
    }
    return $params;
}

function budgetRecognitionExpectedQuery(): string
{
    return 'SELECT COUNT(*) FROM report_templates WHERE '
        . implode(' ', budgetRecognitionRequiredWhereFragments());
}

final class BudgetRecognitionTestSchema
{
    public function getTableSchema(string $table, bool $refresh = false)
    {
        return $table === 'report_templates' ? (object)['columns' => ['help_text' => new stdClass()]] : null;
    }
}

final class BudgetRecognitionTestDatabase
{
    public $schema;
    public $definition;
    public $queries = [];
    public $acceptedQueries = 0;

    public function __construct(array $definition)
    {
        $this->schema = new BudgetRecognitionTestSchema();
        $this->definition = $definition;
    }

    public function createCommand(string $sql = '', array $params = [])
    {
        return new BudgetRecognitionTestCommand($this, $sql, $params);
    }

    public function evaluateRecognitionQuery(string $sql, array $params): bool
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        foreach (budgetRecognitionRequiredWhereFragments() as $fragment) {
            if (strpos($sql, $fragment) === false) {
                return false;
            }
        }

        foreach (budgetRecognitionExpectedParameters() as $name => $expected) {
            if (!array_key_exists($name, $params) || $params[$name] !== $expected) {
                return false;
            }
        }

        $rowValues = [
            ':slug' => $this->definition['slug'] ?? null,
            ':name' => $this->definition['name'] ?? null,
            ':description' => $this->definition['description'] ?? null,
            ':category' => $this->definition['category'] ?? null,
            ':data_source' => $this->definition['data_source'] ?? null,
            ':default_limit' => $this->definition['default_limit'] ?? null,
            ':created_by' => $this->definition['created_by'] ?? null,
        ];
        foreach ($rowValues as $name => $actual) {
            if ($actual !== $params[$name]) {
                return false;
            }
        }
        if (($this->definition['id'] ?? null) !== 37 || ($this->definition['is_active'] ?? null) !== 1) {
            return false;
        }
        $actualAliases = [];
        if (preg_match_all('/AS "([^"]+)"/', (string)($this->definition['sql_template'] ?? ''), $aliasMatches)) {
            $actualAliases = $aliasMatches[1];
        }
        if ($actualAliases !== budgetRecognitionAliases()) {
            return false;
        }

        $parameters = $this->definition['parameters'] ?? [];
        if (count($parameters) !== 2) {
            return false;
        }
        $parameterFields = ['name', 'type', 'label', 'default', 'description', 'options_sql', 'options_db', 'placeholder'];
        foreach ([0 => 'fiscal_year', 1 => 'acq_unit'] as $index => $prefix) {
            foreach ($parameterFields as $field) {
                if (($parameters[$index][$field] ?? null) !== ($params[":" . $prefix . '_' . $field] ?? null)) {
                    return false;
                }
            }
            if (($parameters[$index]['required'] ?? null) !== true) {
                return false;
            }
        }

        foreach (budgetRecognitionAliases() as $index => $alias) {
            $name = ':output_alias_' . $index;
            $pattern = '%AS "' . $alias . '"%';
            if (strpos($sql, 'sql_template LIKE ' . $name) === false
                || ($params[$name] ?? null) !== $pattern
                || !$this->likeSubstring((string)($this->definition['sql_template'] ?? ''), $pattern)) {
                return false;
            }
        }

        foreach (budgetRecognitionSqlMarkers() as $marker => $text) {
            $name = ':' . $marker;
            $pattern = '%' . $text . '%';
            if (strpos($sql, 'sql_template LIKE ' . $name) === false
                || ($params[$name] ?? null) !== $pattern
                || !$this->likeSubstring((string)($this->definition['sql_template'] ?? ''), $pattern)) {
                return false;
            }
        }
        foreach (budgetRecognitionHelpMarkers() as $marker => $text) {
            $name = ':' . $marker;
            $pattern = '%' . $text . '%';
            if (strpos($sql, 'help_text LIKE ' . $name) === false
                || ($params[$name] ?? null) !== $pattern
                || !$this->likeSubstring((string)($this->definition['help_text'] ?? ''), $pattern)) {
                return false;
            }
        }

        $this->acceptedQueries++;
        return true;
    }

    private function likeSubstring(string $value, string $pattern): bool
    {
        return stripos($value, trim($pattern, '%')) !== false;
    }
}

final class BudgetRecognitionTestCommand
{
    private $db;
    private $sql;
    private $params;

    public function __construct(BudgetRecognitionTestDatabase $db, string $sql, array $params)
    {
        $this->db = $db;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryScalar(): int
    {
        return $this->db->evaluateRecognitionQuery($this->sql, $this->params) ? 1 : 0;
    }

    public function queryOne()
    {
        return $this->db->evaluateRecognitionQuery($this->sql, $this->params)
            ? $this->db->definition
            : null;
    }
}

function budgetRecognitionInvoke(string $method, array $definition, string $case): array
{
    global $failures;
    $db = new BudgetRecognitionTestDatabase($definition);
    try {
        $result = BudgetYearFundReportMigrationRecognitionService::$method($db);
    } catch (Throwable $exception) {
        $failures[] = "{$case}: recognition threw '{$exception->getMessage()}'.";
        $result = null;
    }
    if ($result === true && $db->acceptedQueries === 0) {
        $failures[] = "{$case}: recognition returned true without a query accepted against the complete definition.";
    }
    return ['result' => $result, 'db' => $db];
}

$servicePath = __DIR__ . '/../services/BudgetYearFundReportMigrationRecognitionService.php';
$migrationServicePath = __DIR__ . '/../services/MigrationService.php';
$serviceSource = (string)file_get_contents($servicePath);
$migrationServiceSource = (string)file_get_contents($migrationServicePath);
$methodCases = [
    '035_budget_year_fund_report.sql' => 'budgetYearFundReportAppearsComplete',
    '036_budget_year_fund_report_fiscal_year_options.sql' => 'budgetYearFundReportFiscalYearOptionsAppearComplete',
    '037_budget_year_fund_report_payment_distributions.sql' => 'budgetYearFundReportPaymentDistributionsAppearComplete',
    '062_budget_year_fund_report_encumbrance_status.sql' => 'budgetYearFundReportEncumbranceStatusAppearComplete',
];
foreach ($methodCases as $filename => $method) {
    budgetRecognitionAssert(
        strpos($migrationServiceSource, "case '{$filename}': return BudgetYearFundReportMigrationRecognitionService::{$method}(" . '$db);') !== false,
        "MigrationService must delegate {$filename} recognition to the extracted service."
    );
    budgetRecognitionAssert(
        strpos($serviceSource, "function {$method}(" . '$db): bool') !== false,
        "The recognition service must expose {$method} with a bool result."
    );
}

if (preg_match('/private static function databaseAppearsCurrent\(\$db\): bool(.*?)\n    }\n\n    private static function migrationAppearsApplied/s', $migrationServiceSource, $baselineMatch) === 1) {
    budgetRecognitionAssert(
        strpos($baselineMatch[1], 'BudgetYearFundReportMigrationRecognitionService::budgetYearFundReportEncumbranceStatusAppearComplete($db)') !== false,
        'databaseAppearsCurrent must require the complete 062 report definition before baselining.'
    );
} else {
    budgetRecognitionAssert(false, 'Could not inspect databaseAppearsCurrent for 062 baseline recognition.');
}

$serviceLineCount = count(file($servicePath));
$migrationServiceLines = count(file($migrationServicePath));
budgetRecognitionAssert($serviceLineCount < 300, 'The extracted Budget Year Fund recognition service must stay below 300 lines.');
budgetRecognitionAssert($migrationServiceLines <= 771, 'MigrationService.php must remain at or below its 771-line limit.');

$canonical = budgetRecognitionDefinition();
$sqlMarkers = budgetRecognitionSqlMarkers();
$helpMarkers = budgetRecognitionHelpMarkers();
$legacySql = str_replace(
    [$sqlMarkers['encumbrance_scope_marker'], $sqlMarkers['encumbrance_current_marker'], $sqlMarkers['unreleased_count_marker'], $sqlMarkers['released_amount_marker'], $sqlMarkers['released_count_marker']],
    ["t.encumbrance__status IN ('Unreleased', 'Active')", 'SUM(COALESCE(t.encumbrance__initial_amount_encumbered, 0) - COALESCE(t.encumbrance__amount_expended, 0) - COALESCE(t.encumbrance__amount_awaiting_payment, 0)) AS current_encumbrance', '', '', ''],
    $canonical['sql_template']
);
$legacyHelp = str_replace(
    [$helpMarkers['help_unreleased_count'], $helpMarkers['help_released_amount'], $helpMarkers['help_released_count'], $helpMarkers['help_pending']],
    ['', '', '', ''],
    $canonical['help_text']
);
$legacyOutputExpressions = [
    $sqlMarkers['unreleased_count_output_marker'],
    $sqlMarkers['released_encumbrances_output_marker'],
    $sqlMarkers['released_count_output_marker'],
];
$legacySql = str_replace(implode(",\n       ", $legacyOutputExpressions) . ",\n       ", '', $legacySql);
$legacy037 = budgetRecognitionDefinition([
    'description' => 'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
    'sql_template' => $legacySql,
    'help_text' => $legacyHelp,
    'parameters' => [
        ['name' => 'fiscalYearEndYear'],
        ['name' => 'acqUnitId'],
    ],
]);
$legacyAliases = [];
if (preg_match_all('/AS "([^"]+)"/', $legacy037['sql_template'], $legacyAliasMatches)) {
    $legacyAliases = $legacyAliasMatches[1];
}
budgetRecognitionAssert(
    $legacyAliases === [
        'Fund Code', 'Fund Name', 'Fiscal Year', 'Allocated', 'Payments',
        'Calculated Current Encumbrances', 'Total Committed', 'Calculated Remaining',
        'FOLIO Expenditures', 'FOLIO Encumbered', 'FOLIO Available',
    ],
    'The legacy fixture must represent the 037 definition with its 11 historical aliases.'
);
$missingAlias = budgetRecognitionDefinition([
    'sql_template' => str_replace('AS "Released Count"', 'AS "Removed Count"', $canonical['sql_template']),
]);
$wrongDescription = budgetRecognitionDefinition(['description' => 'Wrong report description.']);
$missingCurrentSum = budgetRecognitionDefinition([
    'sql_template' => str_replace($sqlMarkers['encumbrance_current_marker'], 'SUM(0) AS current_encumbrance', $canonical['sql_template']),
]);
$missingReleasedAggregate = budgetRecognitionDefinition([
    'sql_template' => str_replace($sqlMarkers['released_amount_marker'], '', $canonical['sql_template']),
]);
$missingPendingHelp = budgetRecognitionDefinition([
    'help_text' => str_replace($helpMarkers['help_pending'], '', $canonical['help_text']),
]);
$wrongParameterOrder = budgetRecognitionDefinition([
    'parameters' => array_reverse($canonical['parameters']),
]);
$renamedExistingAlias = budgetRecognitionDefinition([
    'sql_template' => str_replace('AS "Fund Name"', 'AS "Renamed Fund Name"', $canonical['sql_template']),
]);
$wrongAliasOrder = budgetRecognitionDefinition([
    'sql_template' => str_replace(
        "0 AS \"Fund Code\",\n       0 AS \"Fund Name\"",
        "0 AS \"Fund Name\",\n       0 AS \"Fund Code\"",
        $canonical['sql_template']
    ),
]);
$wrongReleasedCountExpression = budgetRecognitionDefinition([
    'sql_template' => str_replace(
        $sqlMarkers['released_count_output_marker'],
        '0 AS "Released Count"',
        $canonical['sql_template']
    ),
]);
$wrongEncumbranceJoin = budgetRecognitionDefinition([
    'sql_template' => str_replace(
        $sqlMarkers['encumbrance_left_join_marker'],
        str_replace('LEFT JOIN', 'JOIN', $sqlMarkers['encumbrance_left_join_marker']),
        $canonical['sql_template']
    ),
]);
$parametersMissingOptionsSql = $canonical['parameters'];
$parametersMissingOptionsSql[0]['options_sql'] = '';
$missingOptionsSql = budgetRecognitionDefinition(['parameters' => $parametersMissingOptionsSql]);
$parametersWithWrongType = $canonical['parameters'];
$parametersWithWrongType[0]['type'] = 'text';
$wrongParameterType = budgetRecognitionDefinition(['parameters' => $parametersWithWrongType]);
$parametersNotRequired = $canonical['parameters'];
$parametersNotRequired[0]['required'] = false;
$parameterNotRequired = budgetRecognitionDefinition(['parameters' => $parametersNotRequired]);

$fakeComplete = new BudgetRecognitionTestDatabase($canonical);
$expectedQuery = budgetRecognitionExpectedQuery();
$expectedParams = budgetRecognitionExpectedParameters();
$negativeDefinitions = [
    'missing 062 alias' => $missingAlias,
    'wrong 062 description' => $wrongDescription,
    'missing conditional current sum' => $missingCurrentSum,
    'missing Released amount aggregate' => $missingReleasedAggregate,
    'missing Pending help' => $missingPendingHelp,
    'wrong parameter order' => $wrongParameterOrder,
    'renamed existing alias' => $renamedExistingAlias,
    'wrong alias order' => $wrongAliasOrder,
    'missing options_sql' => $missingOptionsSql,
    'changed parameter type' => $wrongParameterType,
    'required false' => $parameterNotRequired,
    'wrong Released Count output expression' => $wrongReleasedCountExpression,
    'encumbrances JOIN instead of LEFT JOIN' => $wrongEncumbranceJoin,
    '037-only definition' => $legacy037,
];
$completeFixtureResult = $fakeComplete->createCommand($expectedQuery, $expectedParams)->queryScalar();
budgetRecognitionAssert($completeFixtureResult === 1, 'The fake database must accept a complete 062 WHERE query against the canonical fixture.');
budgetRecognitionAssert(
    ($fakeComplete->queries[0]['sql'] ?? null) === $expectedQuery
        && ($fakeComplete->queries[0]['params'] ?? null) === $expectedParams,
    'The fake database must record the SQL and all bound parameters it evaluates.'
);
foreach ($negativeDefinitions as $case => $definition) {
    $fake = new BudgetRecognitionTestDatabase($definition);
    $actual = $fake->createCommand($expectedQuery, $expectedParams)->queryScalar();
    budgetRecognitionAssert($actual === 0, "The fake WHERE evaluator must reject {$case}.");
    budgetRecognitionAssert($fake->acceptedQueries === 0, "The fake WHERE evaluator must not accept {$case}.");
}

$fakeNoCheck = new BudgetRecognitionTestDatabase($canonical);
$ignoredDefinitionResult = $fakeNoCheck->createCommand(
    'SELECT COUNT(*) FROM report_templates WHERE id = 37 AND slug = :slug',
    [':slug' => 'budget-year-fund-report']
)->queryScalar();
budgetRecognitionAssert($ignoredDefinitionResult === 0, 'The fake database must reject an id/slug-only query that ignores the 062 definition markers.');
budgetRecognitionAssert($fakeNoCheck->acceptedQueries === 0, 'A query missing 062 markers must not be recorded as accepted.');
$queryWithoutAliasOrdering = str_replace(budgetRecognitionAliasOrderFragments(), '', $expectedQuery);
$fakeWithoutAliasOrdering = new BudgetRecognitionTestDatabase($canonical);
$unorderedQueryResult = $fakeWithoutAliasOrdering->createCommand($queryWithoutAliasOrdering, $expectedParams)->queryScalar();
budgetRecognitionAssert($unorderedQueryResult === 0, 'The fake database must reject a query missing the submitted LOCATE alias-order predicates.');
$wrongDefinitionCases = [
    'missing options_sql' => $missingOptionsSql,
    'changed parameter type' => $wrongParameterType,
    'required false' => $parameterNotRequired,
    'wrong Released Count output expression' => $wrongReleasedCountExpression,
    'encumbrances JOIN instead of LEFT JOIN' => $wrongEncumbranceJoin,
];

$recognitionMethod = 'budgetYearFundReportEncumbranceStatusAppearComplete';
$completeCall = budgetRecognitionInvoke($recognitionMethod, $canonical, 'A complete 062 definition must be recognized');
if ($completeCall['result'] !== null) {
    budgetRecognitionAssert($completeCall['result'] === true, 'A complete 062 definition must be recognized.');
    budgetRecognitionAssert($completeCall['db']->acceptedQueries > 0, 'Complete 062 recognition must query and match the report definition.');
}
foreach ($negativeDefinitions as $case => $definition) {
    $call = budgetRecognitionInvoke($recognitionMethod, $definition, "062 recognition must reject {$case}");
    if ($call['result'] !== null) {
        budgetRecognitionAssert($call['result'] === false, "062 recognition must reject {$case}.");
        budgetRecognitionAssert($call['db']->acceptedQueries === 0, "The {$case} fixture must not satisfy the 062 WHERE checks.");
    }
}

foreach ([
    'budgetYearFundReportAppearsComplete',
    'budgetYearFundReportFiscalYearOptionsAppearComplete',
    'budgetYearFundReportPaymentDistributionsAppearComplete',
] as $method) {
    $call = budgetRecognitionInvoke($method, $canonical, "{$method} must accept the complete 062 successor");
    if ($call['result'] !== null) {
        budgetRecognitionAssert($call['result'] === true, "Migration recognition {$method} must accept the complete 062 successor.");
        budgetRecognitionAssert($call['db']->acceptedQueries > 0, "Migration recognition {$method} must validate the 062 definition.");
    }
    foreach ($wrongDefinitionCases as $case => $definition) {
        $driftCall = budgetRecognitionInvoke($method, $definition, "{$method} must reject {$case}");
        if ($driftCall['result'] !== null) {
            budgetRecognitionAssert($driftCall['result'] === false, "Migration recognition {$method} must reject {$case}.");
            budgetRecognitionAssert($driftCall['db']->acceptedQueries === 0, "Migration recognition {$method} must not accept {$case}.");
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: Budget Year Fund Report migration recognition\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: Budget Year Fund Report migration recognition\n");
