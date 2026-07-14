# Budget Year Fund Report Fiscal-Year Options Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace campus-specific fiscal-year UUID choices with a dynamic `FY####` selector, infer the campus fiscal-year series from the selected acquisition unit, and return the approved concise 11-column fund report with explanatory help.

**Architecture:** A new idempotent migration 036 updates fixed report template 37 without changing already-applied migration 035. The report resolves `fiscalYearEndYear + acqUnitId` to the correct FOLIO fiscal-year row, then aggregates budgets, payments, and encumbrances independently. `MigrationService` gains a revision-specific completeness predicate so fresh or ledger-less databases cannot baseline migration 036 while retaining the old definition.

**Tech Stack:** MySQL 8, PostgreSQL SQL, PHP 7.2+, Yii2, Docker Compose

## Final-Review Amendment

Add idempotent migration `037_budget_year_fund_report_payment_distributions.sql`; do not edit applied migrations 035 or 036. In the payments CTE, calculate case-insensitive `amount` distributions from `fund_distributions__value` directly and calculate `percentage` distributions from invoice-line `total * value / 100`, treating null type as percentage for legacy compatibility. Preserve report ID 37, the exact two parameters, the exact 11 output columns, and eight numeric two-decimal monetary outputs.

Harden `MigrationService` with a 037-specific completeness predicate covering both full ordered parameter objects, dynamic option SQL, all output aliases, payment and encumbrance arithmetic/scope, calculated remaining, and help definitions. Add RED-first migration, deterministic payment-fixture, and negative recognition tests; then run focused tests and every self-contained backend test. The checksum-preserving delivery consists only of the new 037 migration plus service, test, and documentation changes.

## Global Constraints

- Report name remains `Budget Year Fund Report` and fixed identity remains `id = 37`, `slug = budget-year-fund-report`.
- Migration `035_budget_year_fund_report.sql` must retain SHA-256 `ad4aadbda3259f2bfd68f6e995c86da3f95c8d4fa86a9bd7a726ff1ab0c823ab`.
- Expose exactly two required dropdowns: `fiscalYearEndYear` and `acqUnitId`.
- Fiscal-year labels are distinct `FY####` values derived from `finance.fiscal_year__t.period_end`; no annual maintenance or hardcoded year is allowed.
- Resolve the fiscal-year series as `TRIM(acquisition unit name) || 'FY'`; never hardcode a campus code.
- Derive payment dates and encumbrance scope from the resolved FOLIO fiscal-year row; expose no date inputs.
- Include only funds assigned to the selected acquisition unit with a nonzero allocation in the resolved fiscal year.
- Return exactly the 11 approved columns in the approved order.
- Every monetary output must use PostgreSQL `ROUND(..., 2)`, remain numeric, and never use `TO_CHAR`.
- `Calculated Remaining = Allocated - Payments - Calculated Current Encumbrances`.
- Store explanations in reusable `help_text`; do not add page- or slug-specific frontend behavior.
- Do not change query execution, parameter binding, jobs, exports, charts, or the reusable modal component.

---

### Task 1: Seed the Revised Dynamic-Year Report Definition

**Files:**
- Create: `mysql/migrations/036_budget_year_fund_report_fiscal_year_options.sql`
- Create: `backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php`
- Read only: `mysql/migrations/035_budget_year_fund_report.sql`

**Interfaces:**
- Consumes: fixed report row 37 and nullable `report_templates.help_text` created by migration 035.
- Produces: report parameters `fiscalYearEndYear` and `acqUnitId`, the concise 11-column SQL template, and revised explanatory help metadata.

- [ ] **Step 1: Write the failing migration contract test**

Create `backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php`:

```php
<?php

$migration035 = __DIR__ . '/../../mysql/migrations/035_budget_year_fund_report.sql';
$migration036 = __DIR__ . '/../../mysql/migrations/036_budget_year_fund_report_fiscal_year_options.sql';

function failRevisionTest(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function assertRevisionTrue(bool $condition, string $message): void
{
    if (!$condition) {
        failRevisionTest($message);
    }
}

function assertRevisionContains(string $needle, string $haystack, string $message): void
{
    assertRevisionTrue(strpos($haystack, $needle) !== false, $message);
}

assertRevisionTrue(
    hash_file('sha256', $migration035) === 'ad4aadbda3259f2bfd68f6e995c86da3f95c8d4fa86a9bd7a726ff1ab0c823ab',
    'Applied migration 035 must not change.'
);
assertRevisionTrue(file_exists($migration036), 'Migration 036 must exist.');

$sql = file_exists($migration036) ? (string)file_get_contents($migration036) : '';
assertRevisionContains("'budget-year-fund-report'", $sql, 'Migration 036 must update the fixed report slug.');
assertRevisionContains('ON DUPLICATE KEY UPDATE', $sql, 'Migration 036 must be idempotent.');
assertRevisionContains('SELECT DISTINCT EXTRACT(YEAR FROM period_end::date)::int AS value', $sql, 'Fiscal-year options must group campus rows by end year.');
assertRevisionContains("''FY'' || EXTRACT(YEAR FROM period_end::date)::int::text AS label", $sql, 'Fiscal-year labels must be generated as FY####.');
assertRevisionContains('TRIM(name) AS label', $sql, 'Acquisition-unit labels must be trimmed.');
assertRevisionContains("fy.series = au.code || ''FY''", $sql, 'The FOLIO fiscal-year series must be inferred from the acquisition unit.');
assertRevisionContains('CAST(:fiscalYearEndYear AS integer)', $sql, 'The selected year must remain a bound parameter.');
assertRevisionContains(':acqUnitId', $sql, 'The selected acquisition unit must remain a bound parameter.');
assertRevisionContains('COALESCE(b.allocated, 0) <> 0', $sql, 'Only allocated funds must be returned.');
assertRevisionContains('inv.payment_date::date BETWEEN fy.period_start AND fy.period_end', $sql, 'Payment dates must use FOLIO fiscal-year dates.');
assertRevisionContains("t.encumbrance__status IN (''Unreleased'', ''Active'')", $sql, 'Current encumbrances must use active and unreleased transactions.');
assertRevisionTrue(strpos($sql, 'SCFY') === false, 'Migration 036 must not hardcode a campus series.');
assertRevisionTrue(preg_match('/FY20[0-9]{2}/', $sql) !== 1, 'Migration 036 must not hardcode a fiscal-year label.');
assertRevisionTrue(preg_match('/DATE ''[0-9]{4}-[0-9]{2}-[0-9]{2}''/', $sql) !== 1, 'Migration 036 must not hardcode report dates.');

$parameterNames = [];
if (preg_match_all('/"name":"([^"]+)"/', $sql, $matches)) {
    $parameterNames = $matches[1];
}
assertRevisionTrue($parameterNames === ['fiscalYearEndYear', 'acqUnitId'], 'Migration 036 must expose exactly the two approved parameters.');
assertRevisionTrue(substr_count($sql, '"required":true') === 2, 'Both report parameters must be required.');
assertRevisionTrue(substr_count($sql, '"type":"select"') === 2, 'Both report parameters must be dropdowns.');

$columns = [
    'Fund Code',
    'Fund Name',
    'Fiscal Year',
    'Allocated',
    'Payments',
    'Calculated Current Encumbrances',
    'Total Committed',
    'Calculated Remaining',
    'FOLIO Expenditures',
    'FOLIO Encumbered',
    'FOLIO Available',
];
$lastPosition = -1;
foreach ($columns as $column) {
    $position = strpos($sql, 'AS "' . $column . '"');
    assertRevisionTrue($position !== false, "Missing output column {$column}.");
    assertRevisionTrue($position > $lastPosition, "Output column {$column} is out of order.");
    $lastPosition = $position;
}
assertRevisionTrue(substr_count($sql, 'ROUND(') === 8, 'Exactly eight monetary outputs must use ROUND.');
assertRevisionTrue(stripos($sql, 'TO_CHAR') === false, 'Monetary outputs must remain numeric.');
assertRevisionTrue(strpos($sql, 'Remaining Difference') === false, 'The concise report must omit difference columns.');
assertRevisionContains('Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.', $sql, 'Help text must explain calculated remaining.');
assertRevisionContains('FOLIO Available: the operational available balance stored on the FOLIO budget.', $sql, 'Help text must explain FOLIO available.');

fwrite(STDOUT, "BudgetYearFundReportFiscalYearOptionsMigration test passed\n");
```

- [ ] **Step 2: Run the contract test and verify RED**

Run:

```bash
php backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php
```

Expected: exit 1 with `Migration 036 must exist.`

- [ ] **Step 3: Create migration 036 with the revised report**

Create `mysql/migrations/036_budget_year_fund_report_fiscal_year_options.sql` with this structure and exact PostgreSQL report query:

```sql
INSERT INTO `report_templates`
  (`id`, `slug`, `name`, `description`, `help_text`, `category`, `sql_template`,
   `parameters`, `data_source`, `default_limit`, `is_active`, `created_by`)
VALUES (
  37,
  'budget-year-fund-report',
  'Budget Year Fund Report',
  'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
  'Allocated: the allocation total stored on the FOLIO budget.\n\nPayments: paid invoice-line fund distributions whose invoice payment date falls inside the resolved FOLIO fiscal year.\n\nCalculated Current Encumbrances: active or unreleased encumbrance transactions, calculated as initial amount minus expended amount minus awaiting-payment amount.\n\nTotal Committed: Payments plus Calculated Current Encumbrances.\n\nCalculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.\n\nFOLIO Expenditures: the expenditure total currently stored on the FOLIO budget.\n\nFOLIO Encumbered: the encumbrance total currently stored on the FOLIO budget.\n\nFOLIO Available: the operational available balance stored on the FOLIO budget. It may differ from Calculated Remaining because FOLIO can include transfers, credits, releases, rollover activity, adjustments, payment timing, and transaction synchronization.',
  'finance',
  'WITH selected_acquisition_unit AS (
    SELECT id, TRIM(name) AS code
    FROM orders.acquisitions_unit__t
    WHERE id = :acqUnitId
),
fiscal_years AS (
    SELECT fy.id,
           fy.period_start::date AS period_start,
           fy.period_end::date AS period_end
    FROM finance.fiscal_year__t fy
    CROSS JOIN selected_acquisition_unit au
    WHERE fy.series = au.code || ''FY''
      AND EXTRACT(YEAR FROM fy.period_end::date)::int = CAST(:fiscalYearEndYear AS integer)
),
selected_funds AS (
    SELECT f.id, f.code, f.name
    FROM finance.fund__t f
    CROSS JOIN selected_acquisition_unit au
    WHERE EXISTS (
        SELECT 1
        FROM finance.fund__t__acq_unit_ids fau
        WHERE fau.id = f.id
          AND fau.acq_unit_ids = au.id
    )
),
budgets AS (
    SELECT b.fund_id,
           b.fiscal_year_id,
           SUM(COALESCE(b.allocated, 0)) AS allocated,
           SUM(COALESCE(b.expenditures, 0)) AS folio_expenditures,
           SUM(COALESCE(b.encumbered, 0)) AS folio_encumbered,
           SUM(COALESCE(b.available, 0)) AS folio_available
    FROM finance.budget__t b
    JOIN selected_funds sf ON sf.id = b.fund_id
    JOIN fiscal_years fy ON fy.id = b.fiscal_year_id
    WHERE COALESCE(b.allocated, 0) <> 0
    GROUP BY b.fund_id, b.fiscal_year_id
),
encumbrances AS (
    SELECT t.from_fund_id AS fund_id,
           t.fiscal_year_id,
           SUM(COALESCE(t.encumbrance__initial_amount_encumbered, 0)
               - COALESCE(t.encumbrance__amount_expended, 0)
               - COALESCE(t.encumbrance__amount_awaiting_payment, 0)) AS current_encumbrance
    FROM finance.transaction__t t
    JOIN fiscal_years fy ON fy.id = t.fiscal_year_id
    WHERE t.transaction_type = ''Encumbrance''
      AND t.encumbrance__status IN (''Unreleased'', ''Active'')
    GROUP BY t.from_fund_id, t.fiscal_year_id
),
payments AS (
    SELECT fd.fund_distributions__fund_id AS fund_id,
           fy.id AS fiscal_year_id,
           SUM(COALESCE(fd.total, 0)
               * (COALESCE(fd.fund_distributions__value, 0) * 0.01)) AS payment
    FROM invoice.invoice_lines__t__fund_distributions fd
    JOIN invoice.invoices__t inv ON inv.id = fd.invoice_id
    JOIN fiscal_years fy
      ON inv.payment_date::date BETWEEN fy.period_start AND fy.period_end
    WHERE fd.invoice_line_status = ''Paid''
    GROUP BY fd.fund_distributions__fund_id, fy.id
)
SELECT sf.code AS "Fund Code",
       sf.name AS "Fund Name",
       ''FY'' || EXTRACT(YEAR FROM fy.period_end)::int::text AS "Fiscal Year",
       ROUND(COALESCE(b.allocated, 0), 2) AS "Allocated",
       ROUND(COALESCE(p.payment, 0), 2) AS "Payments",
       ROUND(COALESCE(e.current_encumbrance, 0), 2) AS "Calculated Current Encumbrances",
       ROUND(COALESCE(p.payment, 0) + COALESCE(e.current_encumbrance, 0), 2) AS "Total Committed",
       ROUND(COALESCE(b.allocated, 0) - COALESCE(p.payment, 0) - COALESCE(e.current_encumbrance, 0), 2) AS "Calculated Remaining",
       ROUND(COALESCE(b.folio_expenditures, 0), 2) AS "FOLIO Expenditures",
       ROUND(COALESCE(b.folio_encumbered, 0), 2) AS "FOLIO Encumbered",
       ROUND(COALESCE(b.folio_available, 0), 2) AS "FOLIO Available"
FROM budgets b
JOIN selected_funds sf ON sf.id = b.fund_id
JOIN fiscal_years fy ON fy.id = b.fiscal_year_id
LEFT JOIN payments p
  ON p.fund_id = b.fund_id
 AND p.fiscal_year_id = b.fiscal_year_id
LEFT JOIN encumbrances e
  ON e.fund_id = b.fund_id
 AND e.fiscal_year_id = b.fiscal_year_id
ORDER BY sf.code, fy.period_end',
  '[{"name":"fiscalYearEndYear","type":"select","label":"Fiscal Year","default":"","required":true,"description":"Campus-neutral fiscal year; the selected acquisition unit determines the matching FOLIO fiscal-year series.","options_sql":"SELECT DISTINCT EXTRACT(YEAR FROM period_end::date)::int AS value, ''FY'' || EXTRACT(YEAR FROM period_end::date)::int::text AS label FROM finance.fiscal_year__t WHERE period_end IS NOT NULL ORDER BY value DESC","options_db":"folio","placeholder":"Select fiscal year"},{"name":"acqUnitId","type":"select","label":"Acquisition Unit","default":"","required":true,"description":"Determines both fund membership and the campus fiscal-year series.","options_sql":"SELECT id AS value, TRIM(name) AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY TRIM(name)","options_db":"folio","placeholder":"Select acquisition unit"}]',
  'folio',
  1000,
  1,
  'manual'
)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `help_text` = VALUES(`help_text`),
  `category` = VALUES(`category`),
  `sql_template` = VALUES(`sql_template`),
  `parameters` = VALUES(`parameters`),
  `data_source` = VALUES(`data_source`),
  `default_limit` = VALUES(`default_limit`),
  `is_active` = VALUES(`is_active`),
  `created_by` = VALUES(`created_by`);
```

- [ ] **Step 4: Run the migration contract and verify GREEN**

Run:

```bash
php backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php
php backend/tests/BudgetYearFundReportMigrationTest.php
```

Expected: both print their passed messages and exit 0. The second command proves migration 035 and its original contract remain unchanged.

- [ ] **Step 5: Commit the revised fixed report definition**

```bash
git add mysql/migrations/036_budget_year_fund_report_fiscal_year_options.sql backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php
git commit -m "feat: simplify budget report fiscal year selection"
```

---

### Task 2: Recognize the Revised Report Definition Safely

**Files:**
- Modify: `backend/services/MigrationService.php:247-380`
- Create: `backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php`
- Test: `backend/tests/MigrationServiceTest.php`

**Interfaces:**
- Consumes: migration 036's exact parameter names, SQL markers, description, and help markers.
- Produces: `budgetYearFundReportFiscalYearOptionsAppearComplete($db): bool`, used by database-current detection and migration-036 recognition.

- [ ] **Step 1: Write the failing migration-recognition test**

Create `backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php`:

```php
<?php

require_once __DIR__ . '/../services/MigrationService.php';

use app\services\MigrationService;

function assertRecognitionSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

class FiscalYearOptionsColumnSchema
{
    public $columns;

    public function __construct(array $columns)
    {
        $this->columns = [];
        foreach ($columns as $column) {
            $this->columns[$column] = new stdClass();
        }
    }
}

class FiscalYearOptionsSchema
{
    public function getTableSchema(string $table, bool $refresh = false)
    {
        $tables = ['users', 'query_jobs', 'report_templates', 'ai_clarification_events', 'ai_query_feedback', 'folio_reference_tables'];
        if (!in_array($table, $tables, true)) {
            return null;
        }
        return new FiscalYearOptionsColumnSchema($table === 'report_templates' ? ['help_text'] : []);
    }
}

class FiscalYearOptionsDatabase
{
    public $schema;
    public $revised;

    public function __construct(bool $revised)
    {
        $this->schema = new FiscalYearOptionsSchema();
        $this->revised = $revised;
    }

    public function createCommand(string $sql = '', array $params = [])
    {
        return new FiscalYearOptionsCommand($this->revised, $sql, $params);
    }
}

class FiscalYearOptionsCommand
{
    private $revised;
    private $sql;
    private $params;

    public function __construct(bool $revised, string $sql, array $params)
    {
        $this->revised = $revised;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryScalar(): int
    {
        $isRevisionPredicate = strpos($this->sql, 'FROM report_templates') !== false
            && strpos($this->sql, 'sql_template LIKE :series_marker') !== false
            && strpos($this->sql, 'CAST(parameters AS CHAR) LIKE :fiscal_year_parameter') !== false
            && ($this->params[':fiscal_year_parameter'] ?? null) === '%"fiscalYearEndYear"%';
        return $isRevisionPredicate && $this->revised ? 1 : 0;
    }
}

$migrationApplied = new ReflectionMethod(MigrationService::class, 'migrationAppearsApplied');
$databaseCurrent = new ReflectionMethod(MigrationService::class, 'databaseAppearsCurrent');

$oldDefinition = new FiscalYearOptionsDatabase(false);
assertRecognitionSame(false, $migrationApplied->invoke(null, $oldDefinition, '036_budget_year_fund_report_fiscal_year_options.sql'), 'Migration 036 must not appear applied for the old report definition.');
assertRecognitionSame(false, $databaseCurrent->invoke(null, $oldDefinition), 'The old report definition must not make a ledger-less database appear current.');

$revisedDefinition = new FiscalYearOptionsDatabase(true);
assertRecognitionSame(true, $migrationApplied->invoke(null, $revisedDefinition, '036_budget_year_fund_report_fiscal_year_options.sql'), 'Migration 036 must appear applied for the revised report definition.');
assertRecognitionSame(true, $databaseCurrent->invoke(null, $revisedDefinition), 'The revised report definition should make an otherwise-current database appear current.');

fwrite(STDOUT, "BudgetYearFundReportFiscalYearOptionsRecognition test passed\n");
```

- [ ] **Step 2: Run the recognition test and verify RED**

Run:

```bash
php backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php
```

Expected: exit 1 because migration 036 is not recognized and the old 035 predicate still controls `databaseAppearsCurrent()`.

- [ ] **Step 3: Add revision-specific completeness detection**

In `MigrationService::databaseAppearsCurrent()`, replace the final return with:

```php
return self::budgetYearFundReportFiscalYearOptionsAppearComplete($db);
```

Keep migration 035 mapped to the existing `budgetYearFundReportAppearsComplete()` method. Add this switch case:

```php
case '036_budget_year_fund_report_fiscal_year_options.sql':
    return self::budgetYearFundReportFiscalYearOptionsAppearComplete($db);
```

Add the new method beside the existing report predicate:

```php
private static function budgetYearFundReportFiscalYearOptionsAppearComplete($db): bool
{
    return self::hasColumn($db, 'report_templates', 'help_text')
        && self::rowExists(
            $db,
            'report_templates',
            'id = 37 AND slug = :slug'
                . ' AND name = :name'
                . ' AND description = :description'
                . ' AND category = :category'
                . ' AND data_source = :data_source'
                . ' AND default_limit = :default_limit'
                . ' AND is_active = 1'
                . ' AND created_by = :created_by'
                . ' AND sql_template LIKE :series_marker'
                . ' AND sql_template LIKE :remaining_marker'
                . ' AND sql_template NOT LIKE :difference_marker'
                . ' AND help_text LIKE :help_marker'
                . ' AND CAST(parameters AS CHAR) LIKE :fiscal_year_parameter'
                . ' AND CAST(parameters AS CHAR) LIKE :acq_unit_parameter'
                . ' AND CAST(parameters AS CHAR) NOT LIKE :legacy_fiscal_year_parameter',
            [
                ':slug' => 'budget-year-fund-report',
                ':name' => 'Budget Year Fund Report',
                ':description' => 'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
                ':category' => 'finance',
                ':data_source' => 'folio',
                ':default_limit' => 1000,
                ':created_by' => 'manual',
                ':series_marker' => "%fy.series = au.code || 'FY'%",
                ':remaining_marker' => '%AS "Calculated Remaining"%',
                ':difference_marker' => '%Remaining Difference%',
                ':help_marker' => '%Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.%',
                ':fiscal_year_parameter' => '%"fiscalYearEndYear"%',
                ':acq_unit_parameter' => '%"acqUnitId"%',
                ':legacy_fiscal_year_parameter' => '%"fiscalYearId"%',
            ]
        );
}
```

- [ ] **Step 4: Run focused recognition and migration-service tests**

Run:

```bash
php backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php
php backend/tests/MigrationServiceTest.php
php -l backend/services/MigrationService.php
php -l backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php
```

Expected: both tests print passed messages and both lint commands report no syntax errors.

- [ ] **Step 5: Commit safe migration recognition**

```bash
git add backend/services/MigrationService.php backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php
git commit -m "fix: recognize revised budget report definition"
```

---

### Task 3: Verify the Revision and Existing Docker Volume

**Files:**
- Verify only; no source changes expected.

**Interfaces:**
- Consumes: migrations 035/036, revision recognition, the running `folio-report-explorer-main-clean` Docker stack, and report API 37.
- Produces: evidence that the dynamic options, report SQL, migration ledger, and a real `FY#### + acquisition unit` run work end to end.

- [ ] **Step 1: Run focused and complete self-contained backend verification**

Run from the feature worktree:

```bash
php backend/tests/BudgetYearFundReportMigrationTest.php
php backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php
php backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php
php backend/tests/MigrationServiceTest.php

for test in backend/tests/*Test.php; do
  if rg -q 'vendor/autoload\.php' "$test"; then
    continue
  fi
  php "$test" || exit 1
done
```

Record `git status --short` before and after the full loop. Restore only a tracked cache that was clean before and regenerated by the tests; never overwrite pre-existing user changes.

Expected: all focused tests and all self-contained backend tests pass. The vendor-dependent concurrency test remains the only permitted exclusion.

- [ ] **Step 2: Run syntax and diff validation**

```bash
php -l backend/services/MigrationService.php
php -l backend/tests/BudgetYearFundReportFiscalYearOptionsMigrationTest.php
php -l backend/tests/BudgetYearFundReportFiscalYearOptionsRecognitionTest.php
git diff --check main..HEAD
git diff --name-status main..HEAD
```

Expected: no syntax or whitespace errors; scope is limited to the approved spec/plan, migration 036, MigrationService, and focused tests.

- [ ] **Step 3: Apply migration 036 to the existing Docker volume**

The Compose PHP service does not mount the repository-level migration directory. Copy only migration 036 from the feature worktree into the already-running PHP container, preserving the existing MySQL volume:

```bash
FEATURE_ROOT="$(git rev-parse --show-toplevel)"
cd /Users/roconnell/Projects/work/folio-report-explorer-main-clean
docker compose cp "$FEATURE_ROOT/mysql/migrations/036_budget_year_fund_report_fiscal_year_options.sql" php:/tmp/migrations/036_budget_year_fund_report_fiscal_year_options.sql
docker compose exec -T php php yii migration/audit --path=/tmp/migrations
docker compose exec -T php php yii migration/run --path=/tmp/migrations
docker compose exec -T php php yii migration/audit --path=/tmp/migrations
```

Expected before run: 1 unapplied, 0 changed checksums. Expected run: migration 036 applied, 035 skipped. Expected final audit: 0 unapplied, 0 changed checksums, 0 duplicate numbers.

- [ ] **Step 4: Verify live options and help metadata**

```bash
curl -sS --max-time 30 http://localhost:8080/api/reports/37 | jq '{
  parameterNames: [.parameters[].name],
  fiscalYears: .selectOptions.fiscalYearEndYear,
  acquisitionUnits: .selectOptions.acqUnitId,
  helpTextPresent: ((.helpText // "") | length > 0)
}'
```

Expected:

- `parameterNames` is exactly `["fiscalYearEndYear", "acqUnitId"]`.
- Fiscal-year labels are unique `FY####` values, newest first.
- Acquisition-unit labels contain no leading/trailing whitespace.
- Help text is present.

- [ ] **Step 5: Run the newest fiscal year for acquisition unit SC**

```bash
REPORT_JSON="$(curl -sS --max-time 30 http://localhost:8080/api/reports/37)"
YEAR="$(printf '%s' "$REPORT_JSON" | jq -r '.selectOptions.fiscalYearEndYear[0].value')"
ACQ_ID="$(printf '%s' "$REPORT_JSON" | jq -r '.selectOptions.acqUnitId[] | select(.label == "SC") | .value')"
RUN_JSON="$(curl -sS --max-time 30 -X POST http://localhost:8080/api/reports/37/run -H 'Content-Type: application/json' --data "$(jq -nc --arg year "$YEAR" --arg acq "$ACQ_ID" '{params:{fiscalYearEndYear:$year,acqUnitId:$acq}}')")"
JOB_ID="$(printf '%s' "$RUN_JSON" | jq -r '.jobId')"

for attempt in $(seq 1 30); do
  STATUS_JSON="$(curl -sS --max-time 30 "http://localhost:8080/api/query/status/$JOB_ID")"
  STATUS="$(printf '%s' "$STATUS_JSON" | jq -r '.status')"
  if [ "$STATUS" = "completed" ] || [ "$STATUS" = "failed" ]; then
    break
  fi
  sleep 2
done

printf '%s' "$STATUS_JSON" | jq '{status, rowCount, columns, error}'
```

Expected: status `completed`; columns exactly match the 11-column contract; row data uses an `FY####` fiscal-year label and numeric monetary values. If the selected campus/year has no allocated budgets, repeat with the next listed year; do not change the report SQL to manufacture rows.

- [ ] **Step 6: Inspect the report in the browser**

Open `http://localhost:8080/reports/37` and confirm:

- The fiscal-year dropdown is a short list such as `FY2027`, `FY2026`, and `FY2025`, with no campus-prefixed duplicates.
- The acquisition-unit dropdown shows trimmed campus codes.
- `Calculated Remaining` is visible immediately after `Total Committed`.
- The help modal explains every financial column and closes by Escape, backdrop, icon button, and footer button.
- The result table contains the approved 11 columns and two-decimal numeric values.

- [ ] **Step 7: Record final branch evidence**

```bash
git status --short --branch
git log --oneline main..HEAD
git diff --check main..HEAD
```

Expected: clean feature worktree, task commits only, and no diff errors.
