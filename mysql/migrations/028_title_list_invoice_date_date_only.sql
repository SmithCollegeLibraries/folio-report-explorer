-- Migration 028: Normalize title-list invoice dates to date-only values.
-- Existing templates may already include Invoice Date, but as a full timestamp.
UPDATE report_templates
SET sql_template = REPLACE(
    REPLACE(
        REPLACE(
            sql_template,
            'inv.invoice_date AS "Invoice Date"',
            'inv.invoice_date::date AS "Invoice Date"'
        ),
        'it.invoice_date AS invoice_date',
        'it.invoice_date::date AS invoice_date'
    ),
    'GROUP BY it.invoice_date, po_line_id, ftaui."name", iltfd.invoice_line_status',
    'GROUP BY it.invoice_date::date, po_line_id, ftaui."name", iltfd.invoice_line_status'
),
updated_at = NOW()
WHERE (id = 3 OR slug = 'title-list-report')
  AND (
      sql_template LIKE '%inv.invoice_date AS "Invoice Date"%'
      OR sql_template LIKE '%it.invoice_date AS invoice_date%'
      OR sql_template LIKE '%GROUP BY it.invoice_date, po_line_id, ftaui."name", iltfd.invoice_line_status%'
  );
