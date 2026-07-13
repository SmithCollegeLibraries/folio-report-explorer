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
