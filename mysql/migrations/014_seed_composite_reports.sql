-- Migration 014: Add composite and local-MySQL report templates
-- Expense Class Allocation vs. Actual (composite: FOLIO actuals + local allocations)
-- ACRL Statistics (local MySQL only)

INSERT IGNORE INTO `report_templates`
  (`id`, `slug`, `name`, `description`, `category`, `sql_template`, `parameters`,
   `data_source`, `composite_config`, `default_limit`, `is_active`, `created_by`)
VALUES (
  34,
  'expense-class-allocation-vs-actual',
  'Expense Class Allocation vs. Actual',
  'Compares FOLIO expense class payments against locally-managed budget allocations for a fiscal year. Merges FOLIO invoice payment data with the report_expense_allocations table.',
  'finance',
  'SELECT\n    COALESCE(ect.name, ''N/A'') AS "Expense Class",\n    ect.code AS "Expense Code",\n    COALESCE(mtte."name", mttp."name", '''') AS "Material Type",\n    ROUND(SUM(iltfd.total * (iltfd.fund_distributions__value * 0.01)), 2) AS "Total Paid"\nFROM invoice.invoice_lines__t__fund_distributions iltfd\nINNER JOIN invoice.invoices__t it\n    ON it.id = iltfd.invoice_id\n    AND it.payment_date::date BETWEEN :startDate AND :endDate\nINNER JOIN orders.po_line__t plt ON iltfd.po_line_id = plt.id\nINNER JOIN orders.purchase_order__t__acq_unit_ids potaui\n    ON plt.purchase_order_id = potaui.id\n    AND potaui.acq_unit_ids = :acqUnitId\nLEFT JOIN finance.expense_class__t ect\n    ON iltfd.fund_distributions__expense_class_id = ect.id\nLEFT JOIN inventory.material_type__t mtte ON mtte.id = plt.eresource__material_type\nLEFT JOIN inventory.material_type__t mttp ON mttp.id = plt.physical__material_type\nWHERE iltfd.invoice_line_status = ''Paid''\nGROUP BY ect.name, ect.code, COALESCE(mtte."name", mttp."name", '''')\nORDER BY "Expense Class", "Material Type"',
  '[{"name":"acqUnitId","type":"select","label":"Acquisitions Unit","default":"","required":true,"description":"Filter by acquisitions unit (institution)","options_sql":"SELECT id AS value, name AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY name","placeholder":"Select acquisitions unit"},{"name":"startDate","type":"date","label":"Start Date","default":"$fiscal_year_start","required":true,"description":"Beginning of fiscal period","placeholder":"YYYY-MM-DD"},{"name":"endDate","type":"date","label":"End Date","default":"$fiscal_year_end","required":true,"description":"End of fiscal period","placeholder":"YYYY-MM-DD"},{"name":"fiscalYear","type":"number","label":"Fiscal Year","default":"$current_year","required":true,"description":"Fiscal year for allocation lookup (e.g. 2026)","placeholder":"2026"}]',
  'composite',
  '{"secondary_sql":"SELECT expense_class_code, allocation_amount FROM report_expense_allocations WHERE fiscal_year = :fiscalYear","secondary_db":"local","merge_key":{"primary":"Expense Code","secondary":"expense_class_code"},"append_columns":["allocation_amount AS Allocation"]}',
  1000,
  1,
  'manual'
);

INSERT IGNORE INTO `report_templates`
  (`id`, `slug`, `name`, `description`, `category`, `sql_template`, `parameters`,
   `data_source`, `composite_config`, `default_limit`, `is_active`, `created_by`)
VALUES (
  35,
  'acrl-statistics',
  'ACRL Statistics',
  'View historical ACRL institutional statistics. Filter by year or category. Data is stored locally and updated annually.',
  'other',
  'SELECT category AS "Category", subcategory AS "Subcategory", year AS "Year", value AS "Value", notes AS "Notes"\nFROM acrl_statistics\nWHERE (:year = 0 OR year = :year)\n  AND (:category = '''' OR category = :category)\nORDER BY category, subcategory, year',
  '[{"name":"year","type":"number","label":"Year","default":"0","required":false,"description":"Filter by specific year. Enter 0 to show all years.","placeholder":"0 for all years"},{"name":"category","type":"select","label":"Category","default":"","required":false,"description":"Filter by ACRL category","options_sql":"SELECT DISTINCT category AS value, category AS label FROM acrl_statistics ORDER BY category","options_db":"local","placeholder":"All categories"}]',
  'local',
  NULL,
  5000,
  1,
  'manual'
);
