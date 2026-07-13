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
