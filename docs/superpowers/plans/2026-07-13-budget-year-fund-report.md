# Budget Year Fund Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a fixed Budget Year Fund Report that reconciles calculated and FOLIO fund balances, with two-decimal numeric outputs and a reusable explanatory modal.

**Architecture:** A MySQL migration and fresh-install schema add optional report help metadata and fixed report ID 37. The existing report model serializes that metadata as `helpText`; a focused React component renders the optional accessible modal on report detail pages. The report remains a normal FOLIO report using the existing secure parameter binding and async job pipeline.

**Tech Stack:** MySQL 8, PostgreSQL SQL, PHP 7.2+, Yii2, React 18, TypeScript, Vitest, Testing Library

## Global Constraints

- Report name: `Budget Year Fund Report`.
- Include every fund assigned to the selected acquisition unit with a nonzero allocation for the selected FOLIO fiscal year.
- Expose exactly two required dropdowns: `fiscalYearId` and `acqUnitId`.
- Derive payment dates and encumbrance scope from the selected FOLIO fiscal year; expose no separate date inputs.
- Every monetary output must use PostgreSQL `ROUND(..., 2)`, remain numeric, and never use `TO_CHAR`.
- Keep all values parameterized; never interpolate user values into SQL.
- Store report help content as reusable template metadata, not slug-specific frontend code.
- Reports without help text must retain existing behavior.
- The help modal must close by button, backdrop, or Escape and restore focus to its trigger.
- Do not change existing query execution, preflight, job, export, or chart behavior.

---

### Task 1: Persist and Seed the Fixed Report

**Files:**
- Create: `mysql/migrations/035_budget_year_fund_report.sql`
- Create: `backend/tests/BudgetYearFundReportMigrationTest.php`
- Modify: `mysql/init.sql:101-121`
- Modify: `backend/services/MigrationService.php:224-320`
- Test: `backend/tests/MigrationServiceTest.php`

**Interfaces:**
- Consumes: existing `report_templates` schema and `MigrationService` migration ledger.
- Produces: nullable `report_templates.help_text` and fixed report row `id = 37`, `slug = budget-year-fund-report`.

- [ ] **Step 1: Write the failing migration contract test**

Create a standalone PHP test that reads `035_budget_year_fund_report.sql` and asserts:

```php
$sql = file_get_contents(__DIR__ . '/../../mysql/migrations/035_budget_year_fund_report.sql');
assertContainsText('ADD COLUMN IF NOT EXISTS `help_text` LONGTEXT NULL', $sql, 'Migration must add reusable report help metadata.');
assertContainsText("'budget-year-fund-report'", $sql, 'Migration must seed the fixed report slug.');
assertContainsText(':fiscalYearId', $sql, 'Report must bind one fiscal year.');
assertContainsText(':acqUnitId', $sql, 'Report must bind one acquisition unit.');
assertContainsText('WHERE EXISTS', $sql, 'Fund acquisition-unit filtering must avoid duplicate budgets.');
assertContainsText("tt.encumbrance__status IN (''Unreleased'', ''Active'')", $sql, 'Report must calculate active encumbrances.');
assertContainsText('inv.payment_date::date BETWEEN fy.period_start AND fy.period_end', $sql, 'Payment dates must come from the selected fiscal year.');
assertContainsText('"Remaining Difference"', $sql, 'Report must include reconciliation differences.');
assertTrueValue(substr_count($sql, 'ROUND(') >= 13, 'All monetary outputs must be rounded.');
assertTrueValue(stripos($sql, 'TO_CHAR') === false, 'Monetary outputs must remain numeric.');
```

Also assert `mysql/init.sql` contains `help_text LONGTEXT NULL`, and use reflection to verify `MigrationService::migrationAppearsApplied()` recognizes migration 035 only when the column and report row both exist.

- [ ] **Step 2: Run the contract tests and verify RED**

Run: `php backend/tests/BudgetYearFundReportMigrationTest.php`

Expected: FAIL because migration 035 and `help_text` do not exist.

- [ ] **Step 3: Add schema and migration recognition**

Add this fresh-install column after `description` in `mysql/init.sql`:

```sql
help_text LONGTEXT NULL COMMENT 'Optional explanatory content shown in the report help modal',
```

In `MigrationService::databaseAppearsCurrent()`, require both `report_templates.help_text` and fixed report row 37. This prevents a fresh `mysql/init.sql` database from baselining migration 035 before its seed row is inserted. Add migration recognition:

```php
case '035_budget_year_fund_report.sql':
    return self::hasColumn($db, 'report_templates', 'help_text')
        && self::rowExists(
            $db,
            'report_templates',
            'id = 37 OR slug = :slug',
            [':slug' => 'budget-year-fund-report']
        );
```

- [ ] **Step 4: Create the idempotent migration and fixed report**

The migration must use:

```sql
ALTER TABLE `report_templates`
  ADD COLUMN IF NOT EXISTS `help_text` LONGTEXT NULL
  COMMENT 'Optional explanatory content shown in the report help modal'
  AFTER `description`;

INSERT INTO `report_templates`
  (`id`, `slug`, `name`, `description`, `help_text`, `category`, `sql_template`,
   `parameters`, `data_source`, `default_limit`, `is_active`, `created_by`)
VALUES (
  37,
  'budget-year-fund-report',
  'Budget Year Fund Report',
  'Compares transaction-derived payments, current encumbrances, and remaining balances with FOLIO budget totals for every allocated fund in a selected fiscal year and acquisition unit.',
  'Calculated values are reconciliation aids; FOLIO values are the authoritative operational budget balances.\n\nCalculated Payments: paid invoice-line fund distributions dated inside the selected FOLIO fiscal year.\n\nFOLIO Expenditures: the expenditure total currently stored on the FOLIO budget.\n\nExpenditure Difference: Calculated Payments minus FOLIO Expenditures.\n\nCalculated Current Encumbrances: active or unreleased encumbrance transactions, calculated as initial amount minus expended amount minus awaiting-payment amount.\n\nFOLIO Encumbered: the encumbrance total currently stored on the FOLIO budget.\n\nEncumbrance Difference: Calculated Current Encumbrances minus FOLIO Encumbered.\n\nCalculated Total Committed: Calculated Payments plus Calculated Current Encumbrances.\n\nCalculated Remaining: Total Funding minus Calculated Payments minus Calculated Current Encumbrances.\n\nFOLIO Available: the available balance currently stored on the FOLIO budget.\n\nRemaining Difference: Calculated Remaining minus FOLIO Available. Differences can result from transfers, credits, releases, rollover activity, adjustments, payment timing, and transaction synchronization.',
  'finance',
  'WITH selected_fiscal_year AS (
    SELECT id, name, period_start::date AS period_start, period_end::date AS period_end
    FROM finance.fiscal_year__t
    WHERE id = :fiscalYearId
),
selected_funds AS (
    SELECT f.id, f.code, f.name
    FROM finance.fund__t f
    WHERE EXISTS (
        SELECT 1
        FROM finance.fund__t__acq_unit_ids fau
        WHERE fau.id = f.id
          AND fau.acq_unit_ids = :acqUnitId
    )
),
budgets AS (
    SELECT b.fund_id, b.fiscal_year_id,
           SUM(COALESCE(b.allocated, 0)) AS allocated,
           SUM(COALESCE(b.net_transfers, 0)) AS net_transfers,
           SUM(COALESCE(b.total_funding, 0)) AS total_funding,
           SUM(COALESCE(b.expenditures, 0)) AS folio_expenditures,
           SUM(COALESCE(b.encumbered, 0)) AS folio_encumbered,
           SUM(COALESCE(b.available, 0)) AS folio_available
    FROM finance.budget__t b
    JOIN selected_funds sf ON sf.id = b.fund_id
    WHERE b.fiscal_year_id = :fiscalYearId
      AND COALESCE(b.allocated, 0) <> 0
    GROUP BY b.fund_id, b.fiscal_year_id
),
payments AS (
    SELECT fd.fund_distributions__fund_id AS fund_id,
           SUM(COALESCE(fd.total, 0) * (COALESCE(fd.fund_distributions__value, 0) * 0.01)) AS calculated_payments
    FROM invoice.invoice_lines__t__fund_distributions fd
    JOIN invoice.invoices__t inv ON inv.id = fd.invoice_id
    CROSS JOIN selected_fiscal_year fy
    WHERE fd.invoice_line_status = ''Paid''
      AND inv.payment_date::date BETWEEN fy.period_start AND fy.period_end
    GROUP BY fd.fund_distributions__fund_id
),
encumbrances AS (
    SELECT tt.from_fund_id AS fund_id,
           SUM(COALESCE(tt.encumbrance__initial_amount_encumbered, 0)
               - COALESCE(tt.encumbrance__amount_expended, 0)
               - COALESCE(tt.encumbrance__amount_awaiting_payment, 0)) AS calculated_encumbrances
    FROM finance.transaction__t tt
    WHERE tt.transaction_type = ''Encumbrance''
      AND tt.encumbrance__status IN (''Unreleased'', ''Active'')
      AND tt.fiscal_year_id = :fiscalYearId
    GROUP BY tt.from_fund_id
)
SELECT sf.code AS "Fund Code",
       sf.name AS "Fund Name",
       fy.name AS "Fiscal Year",
       ROUND(COALESCE(b.allocated, 0), 2) AS "Allocated",
       ROUND(COALESCE(b.net_transfers, 0), 2) AS "Net Transfers",
       ROUND(COALESCE(b.total_funding, 0), 2) AS "Total Funding",
       ROUND(COALESCE(p.calculated_payments, 0), 2) AS "Calculated Payments",
       ROUND(COALESCE(b.folio_expenditures, 0), 2) AS "FOLIO Expenditures",
       ROUND(COALESCE(p.calculated_payments, 0) - COALESCE(b.folio_expenditures, 0), 2) AS "Expenditure Difference",
       ROUND(COALESCE(e.calculated_encumbrances, 0), 2) AS "Calculated Current Encumbrances",
       ROUND(COALESCE(b.folio_encumbered, 0), 2) AS "FOLIO Encumbered",
       ROUND(COALESCE(e.calculated_encumbrances, 0) - COALESCE(b.folio_encumbered, 0), 2) AS "Encumbrance Difference",
       ROUND(COALESCE(p.calculated_payments, 0) + COALESCE(e.calculated_encumbrances, 0), 2) AS "Calculated Total Committed",
       ROUND(COALESCE(b.total_funding, 0) - COALESCE(p.calculated_payments, 0) - COALESCE(e.calculated_encumbrances, 0), 2) AS "Calculated Remaining",
       ROUND(COALESCE(b.folio_available, 0), 2) AS "FOLIO Available",
       ROUND(COALESCE(b.total_funding, 0) - COALESCE(p.calculated_payments, 0) - COALESCE(e.calculated_encumbrances, 0) - COALESCE(b.folio_available, 0), 2) AS "Remaining Difference"
FROM budgets b
JOIN selected_funds sf ON sf.id = b.fund_id
JOIN selected_fiscal_year fy ON fy.id = b.fiscal_year_id
LEFT JOIN payments p ON p.fund_id = b.fund_id
LEFT JOIN encumbrances e ON e.fund_id = b.fund_id
ORDER BY sf.code, sf.name',
  '[{"name":"fiscalYearId","type":"select","label":"Fiscal Year","default":"","required":true,"description":"FOLIO fiscal year; its stored period dates determine payment and encumbrance scope.","options_sql":"SELECT id AS value, name || CASE WHEN series IS NOT NULL THEN '' ('' || series || '')'' ELSE '''' END AS label FROM finance.fiscal_year__t ORDER BY period_start DESC","options_db":"folio","placeholder":"Select fiscal year"},{"name":"acqUnitId","type":"select","label":"Acquisition Unit","default":"","required":true,"description":"Includes every allocated fund assigned to this acquisition unit.","options_sql":"SELECT id AS value, name AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY name","options_db":"folio","placeholder":"Select acquisition unit"}]',
  'folio', 1000, 1, 'manual'
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

- [ ] **Step 5: Run migration tests and verify GREEN**

Run: `php backend/tests/BudgetYearFundReportMigrationTest.php && php backend/tests/MigrationServiceTest.php`

Expected: both print their `... test passed` messages and exit 0.

- [ ] **Step 6: Commit persistence and report SQL**

```bash
git add mysql/init.sql mysql/migrations/035_budget_year_fund_report.sql backend/services/MigrationService.php backend/tests/BudgetYearFundReportMigrationTest.php backend/tests/MigrationServiceTest.php
git commit -m "feat: seed budget year fund report"
```

---

### Task 2: Expose Reusable Report Help Metadata

**Files:**
- Modify: `backend/models/ReportTemplate.php:8-55,269-288`
- Modify: `backend/controllers/FolioQueryController.php:2631-2695`
- Create: `backend/tests/ReportTemplateHelpTextTest.php`
- Modify: `frontend/src/types/schema.ts:515-530`

**Interfaces:**
- Consumes: nullable MySQL `report_templates.help_text` from Task 1.
- Produces: `ReportTemplate.helpText?: string | null` in report detail API responses and create/update payload support.

- [ ] **Step 1: Write the failing serialization test**

Create a standalone Yii ActiveRecord stub test, instantiate `ReportTemplate`, assign `help_text`, call `toDetailArray()`, and assert:

```php
assertSameValue(
    'Calculated values explain reconciliation differences.',
    $detail['helpText'] ?? null,
    'Report detail should expose help_text as helpText.'
);
```

Set a second instance's `hasAttribute('help_text')` to false and assert `helpText` is null for backward-compatible schemas.

- [ ] **Step 2: Run the serialization test and verify RED**

Run: `php backend/tests/ReportTemplateHelpTextTest.php`

Expected: FAIL because `toDetailArray()` has no `helpText` key.

- [ ] **Step 3: Add model and API support**

Update the model property/rules and detail serialization:

```php
/** @property string|null $help_text */

[['description', 'help_text', 'sql_template'], 'string'],

'helpText' => $this->hasAttribute('help_text') ? $this->help_text : null,
```

In report create/update, accept optional metadata:

```php
$report->help_text = $body['helpText'] ?? null;

if (array_key_exists('helpText', $body)) {
    $report->help_text = $body['helpText'];
}
```

Add the frontend detail type:

```ts
helpText?: string | null;
```

- [ ] **Step 4: Run serialization and PHP syntax verification**

Run: `php backend/tests/ReportTemplateHelpTextTest.php && php -l backend/models/ReportTemplate.php && php -l backend/controllers/FolioQueryController.php`

Expected: test PASS and no syntax errors.

- [ ] **Step 5: Commit metadata support**

```bash
git add backend/models/ReportTemplate.php backend/controllers/FolioQueryController.php backend/tests/ReportTemplateHelpTextTest.php frontend/src/types/schema.ts
git commit -m "feat: expose report help metadata"
```

---

### Task 3: Add the Accessible Report Help Modal

**Files:**
- Create: `frontend/src/components/ReportHelp.tsx`
- Create: `frontend/src/components/ReportHelp.test.tsx`
- Modify: `frontend/src/pages/ReportDetail.tsx:1-25,230-255`
- Modify: `frontend/src/pages/Reports.test.tsx`

**Interfaces:**
- Consumes: `report.helpText?: string | null` from Task 2.
- Produces: reusable `<ReportHelp reportName={string} helpText={string} />` interaction.

- [ ] **Step 1: Write failing component and integration tests**

Test these behaviors:

```tsx
expect(rendered.queryByRole('button', { name: /how to read this report/i })).not.toBeInTheDocument();

await user.click(screen.getByRole('button', { name: /how to read this report/i }));
expect(screen.getByRole('dialog', { name: /budget year fund report help/i })).toBeInTheDocument();
expect(screen.getByText(/calculated current encumbrances/i)).toBeInTheDocument();

await user.keyboard('{Escape}');
expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
expect(screen.getByRole('button', { name: /how to read this report/i })).toHaveFocus();
```

Also open again, click the backdrop, confirm it closes, then open and use the visible Close button. In `Reports.test.tsx`, mock `getReport()` with `helpText` and assert ReportDetail renders the trigger; omit it in the existing parameter-collapse case and assert no trigger.

- [ ] **Step 2: Run focused frontend tests and verify RED**

Run: `cd frontend && npm test -- src/components/ReportHelp.test.tsx src/pages/Reports.test.tsx`

Expected: FAIL because `ReportHelp` and the trigger do not exist.

- [ ] **Step 3: Implement the reusable component**

Implement `ReportHelp.tsx` as:

```tsx
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Info, X } from 'lucide-react';

export default function ReportHelp({ reportName, helpText }: {
  reportName: string;
  helpText: string;
}) {
  const [open, setOpen] = useState(false);
  const titleId = useId();
  const triggerRef = useRef<HTMLButtonElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const wasOpenRef = useRef(false);

  const close = useCallback(() => setOpen(false), []);

  useEffect(() => {
    if (open) {
      wasOpenRef.current = true;
      closeRef.current?.focus();
      return;
    }
    if (wasOpenRef.current) {
      wasOpenRef.current = false;
      triggerRef.current?.focus();
    }
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') close();
    };
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [close, open]);

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        onClick={() => setOpen(true)}
        className="inline-flex items-center gap-1.5 rounded-xl border border-stone-200 px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-stone-50"
      >
        <Info size={16} /> How to read this report
      </button>

      {open && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) close();
          }}
        >
          <section
            role="dialog"
            aria-modal="true"
            aria-labelledby={titleId}
            className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"
          >
            <header className="flex items-center justify-between border-b border-stone-200 px-6 py-4">
              <h2 id={titleId} className="text-lg font-semibold text-gray-900">
                {reportName} help
              </h2>
              <button ref={closeRef} type="button" onClick={close} aria-label="Close report help">
                <X size={18} />
              </button>
            </header>
            <div className="whitespace-pre-line px-6 py-5 text-sm leading-6 text-gray-700">
              {helpText}
            </div>
            <footer className="flex justify-end border-t border-stone-200 px-6 py-4">
              <button type="button" onClick={close} className="rounded-xl bg-folio-600 px-4 py-2 text-sm font-medium text-white hover:bg-folio-700">
                Close
              </button>
            </footer>
          </section>
        </div>
      )}
    </>
  );
}
```

Use the existing rounded stone/folio visual language and `Info`/`X` icons. Render it beside the report title metadata:

```tsx
{report.helpText?.trim() && (
  <ReportHelp reportName={report.name} helpText={report.helpText} />
)}
```

- [ ] **Step 4: Run focused tests and verify GREEN**

Run: `cd frontend && npm test -- src/components/ReportHelp.test.tsx src/pages/Reports.test.tsx`

Expected: both test files PASS.

- [ ] **Step 5: Run the full frontend suite and build**

Run: `cd frontend && npm test && npm run build`

Expected: all Vitest suites and the production build pass; the existing Vite chunk-size advisory may remain.

- [ ] **Step 6: Commit the modal**

```bash
git add frontend/src/components/ReportHelp.tsx frontend/src/components/ReportHelp.test.tsx frontend/src/pages/ReportDetail.tsx frontend/src/pages/Reports.test.tsx
git commit -m "feat: add report help modal"
```

---

### Task 4: Verify the Complete Feature

**Files:**
- Verify only; no source changes expected.

**Interfaces:**
- Consumes: Tasks 1-3.
- Produces: evidence that the fixed report, metadata contract, and modal work together without regressions.

- [ ] **Step 1: Run focused backend contracts**

Run:

```bash
php backend/tests/BudgetYearFundReportMigrationTest.php
php backend/tests/ReportTemplateHelpTextTest.php
php backend/tests/MigrationServiceTest.php
```

Expected: all pass.

- [ ] **Step 2: Run PHP syntax checks**

Run:

```bash
php -l backend/services/MigrationService.php
php -l backend/models/ReportTemplate.php
php -l backend/controllers/FolioQueryController.php
php -l backend/tests/BudgetYearFundReportMigrationTest.php
php -l backend/tests/ReportTemplateHelpTextTest.php
```

Expected: no syntax errors.

- [ ] **Step 3: Run all self-contained backend tests**

Run every self-contained backend test:

```bash
for test in backend/tests/*Test.php; do
  if rg -q 'vendor/autoload\.php' "$test"; then
    continue
  fi
  php "$test" || exit 1
done
```

Record `git status --short` before and after the run. If a test regenerates a tracked cache that was clean before the run, restore that exact generated-only change before final verification; never overwrite a pre-existing user modification.

Expected: all runnable tests pass, with any existing PHP 8.5 deprecation/fake-app warnings documented.

- [ ] **Step 4: Run frontend validation**

Run: `cd frontend && npm test && npm run build`

Expected: all tests and production build pass.

- [ ] **Step 5: Inspect migration and branch scope**

Run:

```bash
git diff --check 82160f4..HEAD
git diff --name-status 82160f4..HEAD
git log --oneline 82160f4..HEAD
```

Confirm the branch contains only the approved design/plan, migration/schema, report model/API/type, modal/tests, and no formatted monetary SQL.
