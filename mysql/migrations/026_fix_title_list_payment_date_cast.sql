-- Migration 026: Fix title-list report payment date cast for PostgreSQL timestamp columns.
-- Legacy template used substring(timestamp, 0, 11), which fails with SQLSTATE[42883].
UPDATE report_templates
SET sql_template = REPLACE(
    sql_template,
    'CAST(substring(it.payment_date, 0, 11) AS date)',
    'it.payment_date::date'
),
updated_at = NOW()
WHERE id = 3
   OR slug = 'title-list-report';
