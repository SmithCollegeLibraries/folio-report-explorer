SET @rpt005_allocation_code_needs_widening := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'report_expense_allocations'
    AND COLUMN_NAME = 'expense_class_code'
    AND DATA_TYPE = 'varchar'
    AND CHARACTER_MAXIMUM_LENGTH < 32
);

SET @rpt005_allocation_code_ddl := IF(
  @rpt005_allocation_code_needs_widening > 0,
  'ALTER TABLE `report_expense_allocations` MODIFY COLUMN `expense_class_code` VARCHAR(32) NOT NULL',
  'SELECT 1'
);

PREPARE rpt005_allocation_code_stmt FROM @rpt005_allocation_code_ddl;
EXECUTE rpt005_allocation_code_stmt;
DEALLOCATE PREPARE rpt005_allocation_code_stmt;

UPDATE `report_templates`
SET `sql_template` = REPLACE(
  `sql_template`,
  'ect.code AS "Expense Code"',
  'UPPER(BTRIM(ect.code)) AS "Expense Code"'
)
WHERE `id` = 34
  AND `slug` = 'expense-class-allocation-vs-actual'
  AND LOCATE('ect.code AS "Expense Code"', `sql_template`) > 0;

START TRANSACTION;

INSERT INTO `report_templates`
  (`id`, `slug`, `name`, `description`, `help_text`, `category`, `sql_template`, `parameters`,
   `data_source`, `execution_config`, `composite_config`, `default_limit`, `is_active`, `created_by`)
VALUES (
  36,
  'budget-year-expense-class',
  'Budget Year Expense Class Report',
  'Shows paid invoice distributions and current encumbrances by expense class for one college, fiscal year, optional payment-date range, and selected funds, with locally entered allocation and remaining amounts.',
  'Payments are assigned to the selected budget year when the invoice fiscal-year ID matches the exact selected college fiscal-year row. Optional start and end dates narrow those payments by payment date, inclusively; leave either or both blank for no bound. Payment-date filters are not checked against the FOLIO fiscal-year period. Book and E-Book amounts are classified only by the purchase-order line material type, using electronic material type before physical material type. Other material types remain in Total Payments and Total Encumbrances.

Encumbrances use the selected unit fiscal-year row only and are calculated as initial amount minus expended amount minus awaiting-payment amount. Payments and encumbrances are filtered by the selected funds, regardless of the purchase order acquisitions-unit link. Expense classes are selected by the normalized college prefix; their output codes are uppercased and trimmed to match local allocations.

Allocation is stored locally by fiscal year and expense-class code. Remaining is Allocation minus Total Payments minus Total Encumbrances. The report returns all in-scope expense classes, including classes without activity.',
  'finance',
  'WITH expense_classes AS (
    SELECT ect.id,
           BTRIM(ect.name) AS expense_class_name,
           UPPER(BTRIM(ect.code)) AS expense_class_code
    FROM finance.expense_class__t ect
    WHERE UPPER(BTRIM(ect.name)) LIKE :expenseClassPattern
),
payment_rows AS (
    SELECT fd.fund_distributions__expense_class_id AS expense_class_id,
           COALESCE(mtte.name, mttp.name, '''') AS material_type,
           SUM(COALESCE(fd.total, 0) * (COALESCE(fd.fund_distributions__value, 0) * 0.01)) AS payment
    FROM invoice.invoice_lines__t__fund_distributions fd
    JOIN invoice.invoices__t inv ON inv.id = fd.invoice_id
    LEFT JOIN orders.po_line__t plt ON plt.id = fd.po_line_id
    LEFT JOIN inventory.material_type__t mtte ON mtte.id = plt.eresource__material_type
    LEFT JOIN inventory.material_type__t mttp ON mttp.id = plt.physical__material_type
    WHERE fd.invoice_line_status = ''Paid''
      AND inv.fiscal_year_id = :invoiceFiscalYearId
      AND (CAST(:startDateEnabled AS integer) = 0 OR CAST(inv.payment_date AS date) >= CAST(:startDate AS date))
      AND (CAST(:endDateEnabled AS integer) = 0 OR CAST(inv.payment_date AS date) <= CAST(:endDate AS date))
      AND fd.fund_distributions__fund_id IN (:fundIds)
    GROUP BY fd.fund_distributions__expense_class_id, COALESCE(mtte.name, mttp.name, '''')
),
payment_totals AS (
    SELECT expense_class_id,
           ROUND(COALESCE(SUM(CASE WHEN material_type = ''Book'' THEN payment ELSE 0 END), 0), 2) AS book_payments,
           ROUND(COALESCE(SUM(CASE WHEN material_type = ''E-Book'' THEN payment ELSE 0 END), 0), 2) AS ebook_payments,
           ROUND(COALESCE(SUM(payment), 0), 2) AS total_payments
    FROM payment_rows
    GROUP BY expense_class_id
),
encumbrance_rows AS (
    SELECT t.expense_class_id,
           COALESCE(mtte.name, mttp.name, '''') AS material_type,
           SUM(COALESCE(t.encumbrance__initial_amount_encumbered, 0)
               - COALESCE(t.encumbrance__amount_expended, 0)
               - COALESCE(t.encumbrance__amount_awaiting_payment, 0)) AS current_encumbrance
    FROM finance.transaction__t t
    LEFT JOIN orders.po_line__t plt ON plt.id = t.encumbrance__source_po_line_id
    LEFT JOIN inventory.material_type__t mtte ON mtte.id = plt.eresource__material_type
    LEFT JOIN inventory.material_type__t mttp ON mttp.id = plt.physical__material_type
    WHERE t.transaction_type = ''Encumbrance''
      AND t.encumbrance__status IN (''Unreleased'', ''Active'')
      AND t.fiscal_year_id = :fiscalYearId
      AND t.from_fund_id IN (:fundIds)
    GROUP BY t.expense_class_id, COALESCE(mtte.name, mttp.name, '''')
),
encumbrance_totals AS (
    SELECT expense_class_id,
           ROUND(COALESCE(SUM(CASE WHEN material_type = ''Book'' THEN current_encumbrance ELSE 0 END), 0), 2) AS book_encumbrances,
           ROUND(COALESCE(SUM(CASE WHEN material_type = ''E-Book'' THEN current_encumbrance ELSE 0 END), 0), 2) AS ebook_encumbrances,
           ROUND(COALESCE(SUM(current_encumbrance), 0), 2) AS total_encumbrances
    FROM encumbrance_rows
    GROUP BY expense_class_id
)
SELECT ect.expense_class_name AS "Expense Class Name",
       ect.expense_class_code AS "Expense Class Code",
       COALESCE(pt.book_payments, 0) AS "Book Payments",
       COALESCE(pt.ebook_payments, 0) AS "E-Book Payments",
       COALESCE(pt.total_payments, 0) AS "Total Payments",
       COALESCE(et.book_encumbrances, 0) AS "Book Encumbrances",
       COALESCE(et.ebook_encumbrances, 0) AS "E-Book Encumbrances",
       COALESCE(et.total_encumbrances, 0) AS "Total Encumbrances",
       ROUND(COALESCE(pt.total_payments, 0) + COALESCE(et.total_encumbrances, 0), 2) AS "Total Spent"
FROM expense_classes ect
LEFT JOIN payment_totals pt ON pt.expense_class_id = ect.id
LEFT JOIN encumbrance_totals et ON et.expense_class_id = ect.id
ORDER BY ect.expense_class_name, ect.expense_class_code
LIMIT 100001',
  '[{"name":"acqUnit","type":"select","label":"Acquisitions Unit","default":"","required":true,"description":"Choose the college whose expense classes, funds, and fiscal-year series should be used.","options_sql":"SELECT unit_code AS value, unit_code AS label FROM (VALUES (''AC''), (''HC''), (''MH''), (''SC''), (''UM'')) AS units(unit_code) ORDER BY unit_code","options_db":"folio","placeholder":"Select college"},{"name":"fiscalYear","type":"number","label":"Fiscal Year","default":"","required":true,"description":"Uses the exact unit fiscal-year code, such as SCFY2026.","placeholder":"Select fiscal year"},{"name":"startDate","type":"date","label":"Start Date","default":"","required":false,"description":"Optional inclusive payment-date lower bound. Leave blank for no lower bound; the invoice fiscal year selects the budget year.","placeholder":"YYYY-MM-DD"},{"name":"endDate","type":"date","label":"End Date","default":"","required":false,"description":"Optional inclusive payment-date upper bound. Leave blank for no upper bound; the invoice fiscal year selects the budget year.","placeholder":"YYYY-MM-DD"},{"name":"funds","type":"multiselect","label":"Funds","default":"","required":true,"description":"Select funds associated with the chosen college.","placeholder":"Search funds"}]',
  'composite',
  NULL,
  '{"secondary_sql":"SELECT expense_class_code, allocation_amount FROM report_expense_allocations WHERE fiscal_year = :fiscalYear","secondary_db":"local","merge_key":{"primary":"Expense Class Code","secondary":"expense_class_code"},"append_columns":["allocation_amount AS Allocation"],"computed_columns":[{"name":"Remaining","formula":"Allocation - Total Payments - Total Encumbrances"}]}',
  100000,
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
  `execution_config` = VALUES(`execution_config`),
  `composite_config` = VALUES(`composite_config`),
  `default_limit` = VALUES(`default_limit`),
  `is_active` = VALUES(`is_active`),
  `created_by` = VALUES(`created_by`);

COMMIT;
