-- Migration 021: Add ROUND(..., 2) to all monetary columns in report 36
-- (Budget Year Expense Class Report) so results export cleanly to 2 dp.
-- The client-side ResultsTable.tsx already caps display at 2 dp, but this
-- fixes the raw stored SQL template for CSV export consistency.

-- Book Payments
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'THEN payments.payment ELSE 0 END), 0) AS "Book Payments"',
    'THEN payments.payment ELSE 0 END), 0), 2) AS "Book Payments"')
WHERE id = 36
  AND sql_template LIKE '%"Book Payments"%'
  AND sql_template NOT LIKE '%ROUND%"Book Payments"%';

-- Prefix ROUND( before the Book Payments COALESCE
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''Book''
             AND payments.fund_id = ''6330d805-1772-4c14-b25d-5f4599964dd9''
        THEN payments.payment ELSE 0 END), 0), 2) AS "Book Payments"',
    'ROUND(COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''Book''
             AND payments.fund_id = ''6330d805-1772-4c14-b25d-5f4599964dd9''
        THEN payments.payment ELSE 0 END), 0), 2) AS "Book Payments"')
WHERE id = 36;

-- E-Book Payments: add ), 2) suffix
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'THEN payments.payment ELSE 0 END), 0) AS "E-Book Payments"',
    'THEN payments.payment ELSE 0 END), 0), 2) AS "E-Book Payments"')
WHERE id = 36
  AND sql_template NOT LIKE '%ROUND%"E-Book Payments"%';

-- Prefix ROUND( before the E-Book Payments COALESCE
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''E-Book''
             AND payments.fund_id = ''83d5d13c-8c9a-4ff2-89dc-e61120f5025f''
        THEN payments.payment ELSE 0 END), 0), 2) AS "E-Book Payments"',
    'ROUND(COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''E-Book''
             AND payments.fund_id = ''83d5d13c-8c9a-4ff2-89dc-e61120f5025f''
        THEN payments.payment ELSE 0 END), 0), 2) AS "E-Book Payments"')
WHERE id = 36;

-- Total Payments
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'COALESCE(SUM(payments.payment), 0) AS "Total Payments"',
    'ROUND(COALESCE(SUM(payments.payment), 0), 2) AS "Total Payments"')
WHERE id = 36;

-- Book Encumbrances: add ), 2) suffix
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'THEN encumbrances.current_encumbrance ELSE 0 END), 0) AS "Book Encumbrances"',
    'THEN encumbrances.current_encumbrance ELSE 0 END), 0), 2) AS "Book Encumbrances"')
WHERE id = 36
  AND sql_template NOT LIKE '%ROUND%"Book Encumbrances"%';

-- Prefix ROUND( before Book Encumbrances COALESCE
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''Book''
             AND encumbrances.from_fund_id = ''6330d805-1772-4c14-b25d-5f4599964dd9''
        THEN encumbrances.current_encumbrance ELSE 0 END), 0), 2) AS "Book Encumbrances"',
    'ROUND(COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''Book''
             AND encumbrances.from_fund_id = ''6330d805-1772-4c14-b25d-5f4599964dd9''
        THEN encumbrances.current_encumbrance ELSE 0 END), 0), 2) AS "Book Encumbrances"')
WHERE id = 36;

-- E-Book Encumbrances: add ), 2) suffix
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'THEN encumbrances.current_encumbrance ELSE 0 END), 0) AS "E-Book Encumbrances"',
    'THEN encumbrances.current_encumbrance ELSE 0 END), 0), 2) AS "E-Book Encumbrances"')
WHERE id = 36
  AND sql_template NOT LIKE '%ROUND%"E-Book Encumbrances"%';

-- Prefix ROUND( before E-Book Encumbrances COALESCE
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''E-Book''
             AND encumbrances.from_fund_id = ''83d5d13c-8c9a-4ff2-89dc-e61120f5025f''
        THEN encumbrances.current_encumbrance ELSE 0 END), 0), 2) AS "E-Book Encumbrances"',
    'ROUND(COALESCE(SUM(CASE
        WHEN COALESCE(mtte.name, mttp.name, '''') = ''E-Book''
             AND encumbrances.from_fund_id = ''83d5d13c-8c9a-4ff2-89dc-e61120f5025f''
        THEN encumbrances.current_encumbrance ELSE 0 END), 0), 2) AS "E-Book Encumbrances"')
WHERE id = 36;

-- Total Encumbrances
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    'COALESCE(SUM(encumbrances.current_encumbrance), 0) AS "Total Encumbrances"',
    'ROUND(COALESCE(SUM(encumbrances.current_encumbrance), 0), 2) AS "Total Encumbrances"')
WHERE id = 36;

-- Total Spent (multi-line expression)
UPDATE report_templates
SET sql_template = REPLACE(sql_template,
    '    (
        COALESCE(SUM(payments.payment), 0)
        + COALESCE(SUM(encumbrances.current_encumbrance), 0)
    ) AS "Total Spent"',
    '    ROUND(
        COALESCE(SUM(payments.payment), 0)
        + COALESCE(SUM(encumbrances.current_encumbrance), 0)
    , 2) AS "Total Spent"')
WHERE id = 36;
