-- Migration 025: Restore the Invoice Date column in the title-list report.
-- The seeded template was missing invoice_date from the invoice aggregation subquery.
UPDATE report_templates
SET sql_template = REPLACE(
    REPLACE(
        REPLACE(
            sql_template,
            '    potaui.order_type AS "PO Type",\n    ROUND(inv.payment, 2) AS "Sum of Invoice Payments",',
            '    potaui.order_type AS "PO Type",\n    inv.invoice_date::date AS "Invoice Date",\n    ROUND(inv.payment, 2) AS "Sum of Invoice Payments",'
        ),
        'SELECT ROUND(SUM(iltfd.total * (iltfd.fund_distributions__value * .01)), 2) AS payment,\n           po_line_id, ftaui."name" AS fund, iltfd.invoice_line_status AS status',
        'SELECT ROUND(SUM(iltfd.total * (iltfd.fund_distributions__value * .01)), 2) AS payment,\n           it.invoice_date::date AS invoice_date,\n           po_line_id, ftaui."name" AS fund, iltfd.invoice_line_status AS status'
    ),
    'GROUP BY po_line_id, ftaui."name", iltfd.invoice_line_status',
    'GROUP BY it.invoice_date::date, po_line_id, ftaui."name", iltfd.invoice_line_status'
),
description = REPLACE(
    description,
    'payment totals, fund names, and invoice line status',
    'invoice dates, payment totals, fund names, and invoice line status'
),
updated_at = NOW()
WHERE (id = 3 OR slug = 'title-list-report')
  AND sql_template NOT LIKE '%"Invoice Date"%';