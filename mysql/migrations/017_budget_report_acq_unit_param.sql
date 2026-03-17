-- Migration 017: Add acquisition unit dropdown parameter to Budget Year Expense Class Report.
-- Replaces the hardcoded SC acquisition unit UUID in the po_lines CTE with a
-- :acqUnit parameter so users can select any acquisition unit from the dropdown.
-- Also fixes the composite report to correctly default to Smith College (SC).

UPDATE `report_templates` SET
  `sql_template` = 'WITH fiscal_years AS (
    SELECT id
    FROM finance.fiscal_year__t
    WHERE series = ''SCFY''
      AND (
          (:startDate::date BETWEEN period_start::date AND period_end::date)
          OR
          (:endDate::date BETWEEN period_start::date AND period_end::date)
      )
),
encumbrances AS (
    SELECT
        tt.encumbrance__source_po_line_id AS po_line_id,
        tt.expense_class_id,
        tt.from_fund_id,
        SUM(
            tt.encumbrance__initial_amount_encumbered
            - tt.encumbrance__amount_expended
            - tt.encumbrance__amount_awaiting_payment
        ) AS current_encumbrance
    FROM finance.transaction__t tt
    WHERE tt.transaction_type = ''Encumbrance''
      AND tt.encumbrance__status IN (''Unreleased'', ''Active'')
      AND tt.fiscal_year_id IN (SELECT id FROM fiscal_years)
      AND tt.from_fund_id IN (
          ''6330d805-1772-4c14-b25d-5f4599964dd9'',
          ''83d5d13c-8c9a-4ff2-89dc-e61120f5025f''
      )
    GROUP BY tt.encumbrance__source_po_line_id, tt.expense_class_id, tt.from_fund_id
),
payments AS (
    SELECT
        SUM(iltfd.total * (iltfd.fund_distributions__value * 0.01)) AS payment,
        iltfd.po_line_id,
        iltfd.fund_distributions__expense_class_id AS expense_class_id,
        iltfd.fund_distributions__fund_id AS fund_id
    FROM invoice.invoice_lines__t__fund_distributions iltfd
    INNER JOIN invoice.invoices__t it ON it.id = iltfd.invoice_id
    WHERE iltfd.invoice_line_status = ''Paid''
      AND it.payment_date::date BETWEEN :startDate::date AND :endDate::date
      AND iltfd.fund_distributions__fund_id IN (
          ''6330d805-1772-4c14-b25d-5f4599964dd9'',
          ''83d5d13c-8c9a-4ff2-89dc-e61120f5025f''
      )
    GROUP BY iltfd.po_line_id, iltfd.fund_distributions__expense_class_id, iltfd.fund_distributions__fund_id
),
po_lines AS (
    SELECT plt.*, potaui.acq_unit_ids
    FROM orders.po_line__t plt
    INNER JOIN orders.purchase_order__t__acq_unit_ids potaui
        ON plt.purchase_order_id = potaui.id
    WHERE potaui.acq_unit_ids = :acqUnit
),
material_types AS (
    SELECT id, name FROM inventory.material_type__t
)
SELECT
    ect.name AS "Expense Class Name",
    ect.code AS "Expense Class Code",
    COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''Book''
             AND payments.fund_id = ''6330d805-1772-4c14-b25d-5f4599964dd9''
        THEN payments.payment ELSE 0 END), 0) AS "Book Payments",
    COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''E-Book''
             AND payments.fund_id = ''83d5d13c-8c9a-4ff2-89dc-e61120f5025f''
        THEN payments.payment ELSE 0 END), 0) AS "E-Book Payments",
    COALESCE(SUM(payments.payment), 0) AS "Total Payments",
    COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''Book''
             AND encumbrances.from_fund_id = ''6330d805-1772-4c14-b25d-5f4599964dd9''
        THEN encumbrances.current_encumbrance ELSE 0 END), 0) AS "Book Encumbrances",
    COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''E-Book''
             AND encumbrances.from_fund_id = ''83d5d13c-8c9a-4ff2-89dc-e61120f5025f''
        THEN encumbrances.current_encumbrance ELSE 0 END), 0) AS "E-Book Encumbrances",
    COALESCE(SUM(encumbrances.current_encumbrance), 0) AS "Total Encumbrances",
    (
        COALESCE(SUM(payments.payment), 0)
        + COALESCE(SUM(encumbrances.current_encumbrance), 0)
    ) AS "Total Spent"
FROM finance.expense_class__t ect
LEFT JOIN po_lines plt ON 1=1
LEFT JOIN payments
    ON plt.id = payments.po_line_id
    AND payments.expense_class_id = ect.id
LEFT JOIN encumbrances
    ON plt.id = encumbrances.po_line_id
    AND encumbrances.expense_class_id = ect.id
LEFT JOIN material_types mtte ON mtte.id = plt.eresource__material_type
LEFT JOIN material_types mttp ON mttp.id = plt.physical__material_type
WHERE ect.name LIKE ''SC%''
GROUP BY ect.name, ect.code
ORDER BY ect.name ASC',
  `parameters` = '[{"name":"fiscalYear","type":"number","label":"Fiscal Year","default":"$current_year","required":true,"description":"Fiscal year for allocation lookup (e.g. 2026). July 1 of the prior year through June 30 of this year.","placeholder":"2026"},{"name":"startDate","type":"date","label":"Start Date","default":"$fiscal_year_start","required":true,"description":"Beginning of fiscal period (defaults to July 1 of prior year)","placeholder":"YYYY-MM-DD"},{"name":"endDate","type":"date","label":"End Date","default":"$fiscal_year_end","required":true,"description":"End of fiscal period (defaults to June 30 of this year)","placeholder":"YYYY-MM-DD"},{"name":"acqUnit","type":"select","label":"Acquisition Unit","default":"b17b9e6b-82bb-4f97-b3e7-757e4e5aeb61","required":true,"description":"Filter orders by acquisition unit. Defaults to Smith College (SC).","options_sql":"SELECT id, name FROM orders.acquisitions_unit__t ORDER BY name","options_db":"folio"}]'
WHERE `id` = 36;
