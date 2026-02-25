-- Seed all report templates converted from Yii2 models.
-- Uses INSERT ... ON DUPLICATE KEY UPDATE to be idempotent.
--
-- v2 changes:
--   - Replaced hardcoded acq_unit_ids UUID with :acqUnitId select param
--   - Replaced hardcoded fiscal year series with :fiscalYearSeries select param
--   - Replaced hardcoded PO prefix with :poPrefix select param
--   - Replaced hardcoded library/location names with select params
--   - Added ROUND(..., 2) to all money SUM calculations
--   - Added item status / library / campus select params with options_sql

-- 1. Budget Report
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('budget-report',
 'Budget Report by Material Type',
 'Summarizes acquisitions expenditures by material type, broken down by order type (one-time, standing order, serial). Shows payments from paid invoices within the specified fiscal date range.',
 'acquisitions',
 'SELECT
    COALESCE(mtte.name, mttp.name, ''Unknown'') AS "Material Type",
    ROUND(SUM(CASE WHEN pot.order_type = ''One-Time'' THEN inv.payment ELSE 0 END), 2) AS "BK",
    ROUND(SUM(CASE WHEN pot.order_type = ''Ongoing'' AND NOT pot.ongoing__is_subscription THEN inv.payment ELSE 0 END), 2) AS "SO",
    ROUND(SUM(CASE WHEN pot.order_type = ''Ongoing'' AND pot.ongoing__is_subscription THEN inv.payment ELSE 0 END), 2) AS "SE",
    ROUND(SUM(inv.AP), 2) AS "AP",
    ROUND(SUM(inv.payment), 2) AS "Total"
FROM orders.po_line__t plt
INNER JOIN orders.purchase_order__t__acq_unit_ids potaui
    ON (plt.purchase_order_id = potaui.id AND potaui.acq_unit_ids = :acqUnitId)
LEFT JOIN orders.purchase_order__t pot ON plt.purchase_order_id = pot.id
INNER JOIN (
    SELECT
        SUM(iltfd.total * (iltfd.fund_distributions__value * .01)) AS payment,
        po_line_id,
        ftaui."name" AS fund,
        SUM(CASE WHEN (iltfd.fund_distributions__code = ''SCBPA'' OR iltfd.fund_distributions__code = ''SCBIA'') THEN iltfd.total ELSE 0 END) AS AP
    FROM invoice.invoice_lines__t__fund_distributions iltfd
    INNER JOIN invoice.invoices__t it ON it.id = iltfd.invoice_id AND it.payment_date::date BETWEEN :startDate AND :endDate
    LEFT JOIN finance.fund__t__acq_unit_ids ftaui ON iltfd.fund_distributions__fund_id = ftaui.id
    WHERE iltfd.invoice_line_status = ''Paid''
    GROUP BY po_line_id, ftaui."name"
) AS inv ON plt.id = inv.po_line_id
LEFT JOIN inventory.material_type__t mtte ON mtte.id = plt.eresource__material_type
LEFT JOIN inventory.material_type__t mttp ON mttp.id = plt.physical__material_type
WHERE COALESCE(mtte.name, mttp.name, ''Unknown'') LIKE :materialType
GROUP BY COALESCE(mtte.name, mttp.name, ''Unknown'')',
 '[{"name":"acqUnitId","type":"select","label":"Acquisitions Unit","required":true,"default":"","placeholder":"Select acquisitions unit","description":"Filter by acquisitions unit (institution)","options_sql":"SELECT id AS value, name AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY name"},{"name":"startDate","type":"date","label":"Start Date","required":true,"default":"$fiscal_year_start","placeholder":"YYYY-MM-DD","description":"Beginning of fiscal period"},{"name":"endDate","type":"date","label":"End Date","required":true,"default":"$fiscal_year_end","placeholder":"YYYY-MM-DD","description":"End of fiscal period"},{"name":"materialType","type":"text","label":"Material Type","required":false,"default":"","placeholder":"Filter by material type","description":"Filter results by material type name (partial match)","wrap":"like"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 2. Title List Report
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('title-list-report',
 'Title List Report',
 'Lists purchase order lines with title, format, material type, PO status, payment totals, fund names, and invoice line status. Filterable by multiple fields.',
 'acquisitions',
 'SELECT
    plt.po_line_number AS "POL #",
    plt.title_or_package AS "Title or Package",
    plt.order_format AS "POL format",
    COALESCE(mtte."name", mttp."name", ''Unknown'') AS "Material Type",
    potaui.workflow_status AS "PO Status",
    potaui.order_type AS "PO Type",
    ROUND(inv.payment, 2) AS "Sum of Invoice Payments",
    inv.fund AS "Fund name",
    inv.status AS "Invoice Line Status"
FROM orders.po_line__t plt
INNER JOIN orders.purchase_order__t__acq_unit_ids potaui
    ON (plt.purchase_order_id = potaui.id AND potaui.acq_unit_ids = :acqUnitId)
INNER JOIN (
    SELECT ROUND(SUM(iltfd.total * (iltfd.fund_distributions__value * .01)), 2) AS payment,
           po_line_id, ftaui."name" AS fund, iltfd.invoice_line_status AS status
    FROM invoice.invoice_lines__t__fund_distributions iltfd
    INNER JOIN invoice.invoices__t it ON it.id = iltfd.invoice_id
        AND CAST(substring(it.payment_date, 0, 11) AS date) BETWEEN :startDate AND :endDate
    LEFT JOIN finance.fund__t__acq_unit_ids ftaui ON iltfd.fund_distributions__fund_id = ftaui.id
    GROUP BY po_line_id, ftaui."name", iltfd.invoice_line_status
) AS inv ON plt.id = inv.po_line_id
LEFT JOIN inventory.material_type__t mtte ON mtte.id = plt.eresource__material_type
LEFT JOIN inventory.material_type__t mttp ON mttp.id = plt.physical__material_type
WHERE plt.po_line_number LIKE :polNumberFilter
  AND plt.title_or_package LIKE :titleFilter
  AND potaui.order_type LIKE :poTypeFilter
  AND potaui.workflow_status LIKE :poStatusFilter
  AND COALESCE(mtte."name", mttp."name", ''Unknown'') LIKE :materialTypeFilter
  AND inv.status LIKE :invoiceLineStatusFilter
ORDER BY plt.po_line_number ASC',
 '[{"name":"acqUnitId","type":"select","label":"Acquisitions Unit","required":true,"default":"","placeholder":"Select acquisitions unit","description":"Filter by acquisitions unit (institution)","options_sql":"SELECT id AS value, name AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY name"},{"name":"startDate","type":"date","label":"Start Date","required":true,"default":"$fiscal_year_start","placeholder":"YYYY-MM-DD","description":"Beginning of fiscal period"},{"name":"endDate","type":"date","label":"End Date","required":true,"default":"$fiscal_year_end","placeholder":"YYYY-MM-DD","description":"End of fiscal period"},{"name":"polNumberFilter","type":"text","label":"POL Number","required":false,"default":"","placeholder":"Filter by POL #","description":"Partial match on purchase order line number","wrap":"like"},{"name":"titleFilter","type":"text","label":"Title","required":false,"default":"","placeholder":"Filter by title","description":"Partial match on title or package name","wrap":"like"},{"name":"poTypeFilter","type":"text","label":"PO Type","required":false,"default":"","placeholder":"e.g. One-Time, Ongoing","description":"Filter by purchase order type","wrap":"like"},{"name":"poStatusFilter","type":"text","label":"PO Status","required":false,"default":"","placeholder":"e.g. Open, Closed","description":"Filter by purchase order status","wrap":"like"},{"name":"materialTypeFilter","type":"text","label":"Material Type","required":false,"default":"","placeholder":"e.g. Book, E-Journal","description":"Filter by material type name","wrap":"like"},{"name":"invoiceLineStatusFilter","type":"text","label":"Invoice Line Status","required":false,"default":"","placeholder":"e.g. Paid, Approved","description":"Filter by invoice line status","wrap":"like"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 3. Material Type Totals
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('material-type-totals',
 'Material Type Totals',
 'Shows total paid invoice amounts grouped by material type for a selected acquisitions unit. No date filtering - shows all-time totals.',
 'acquisitions',
 'SELECT
    COALESCE(mtte.name, mttp.name, ''Unknown'') AS "Material Type",
    ROUND(SUM(iltfd.total * (iltfd.fund_distributions__value * .01)), 2) AS "Total"
FROM orders.po_line__t plt
INNER JOIN orders.purchase_order__t__acq_unit_ids potaui
    ON plt.purchase_order_id = potaui.id
    AND potaui.acq_unit_ids = :acqUnitId
LEFT JOIN orders.purchase_order__t pot ON plt.purchase_order_id = pot.id
INNER JOIN invoice.invoice_lines__t__fund_distributions iltfd ON plt.id = iltfd.po_line_id
INNER JOIN invoice.invoices__t it ON it.id = iltfd.invoice_id
LEFT JOIN inventory.material_type__t mtte ON mtte.id = plt.eresource__material_type
LEFT JOIN inventory.material_type__t mttp ON mttp.id = plt.physical__material_type
WHERE iltfd.invoice_line_status = ''Paid''
GROUP BY COALESCE(mtte.name, mttp.name, ''Unknown'')
ORDER BY "Material Type"',
 '[{"name":"acqUnitId","type":"select","label":"Acquisitions Unit","required":true,"default":"","placeholder":"Select acquisitions unit","description":"Filter by acquisitions unit (institution)","options_sql":"SELECT id AS value, name AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY name"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 4. Material Category by Fiscal Year
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('material-category-by-fiscal-year',
 'Material Category by Fiscal Year',
 'Breaks down acquisitions spending into Electronic, Physical, and Other categories for each fiscal year within a date range. Useful for tracking format spending trends over time.',
 'finance',
 'WITH categorized AS (
    SELECT
        CONCAT(
            CASE WHEN extract(month FROM it.payment_date::date) >= 7 THEN extract(year FROM it.payment_date::date)
                 ELSE extract(year FROM it.payment_date::date) - 1 END,
            ''-'',
            CASE WHEN extract(month FROM it.payment_date::date) >= 7 THEN extract(year FROM it.payment_date::date) + 1
                 ELSE extract(year FROM it.payment_date::date) END
        ) AS fiscal_year,
        COALESCE(mtte.name, mttp.name, ''Unknown'') AS material_type,
        SUM(iltfd.total * (iltfd.fund_distributions__value * .01)) AS total
    FROM orders.po_line__t plt
    INNER JOIN orders.purchase_order__t__acq_unit_ids potaui
        ON plt.purchase_order_id = potaui.id
        AND potaui.acq_unit_ids = :acqUnitId
    LEFT JOIN orders.purchase_order__t pot ON plt.purchase_order_id = pot.id
    INNER JOIN invoice.invoice_lines__t__fund_distributions iltfd ON plt.id = iltfd.po_line_id
    INNER JOIN invoice.invoices__t it ON it.id = iltfd.invoice_id
        AND it.payment_date::date BETWEEN :startDate AND :endDate
    LEFT JOIN inventory.material_type__t mtte ON mtte.id = plt.eresource__material_type
    LEFT JOIN inventory.material_type__t mttp ON mttp.id = plt.physical__material_type
    WHERE iltfd.invoice_line_status = ''Paid''
    GROUP BY fiscal_year, COALESCE(mtte.name, mttp.name, ''Unknown'')
)
SELECT
    fiscal_year AS "Fiscal Year",
    ROUND(SUM(CASE WHEN material_type IN (''Data File'',''Database'',''E-Book'',''E-Book Package'',''E-Journal'',''E-Journal Package'',''E-Newspaper'',''Streaming Video'') THEN total ELSE 0 END), 2) AS "Electronic",
    ROUND(SUM(CASE WHEN material_type IN (''Audio CD'',''Book'',''DVD/Blu-ray'',''Journal'',''Newspaper'',''Score'',''Serial'') THEN total ELSE 0 END), 2) AS "Physical",
    ROUND(SUM(CASE WHEN material_type IN (''Admin'',''Unknown'',''unspecified'') THEN total ELSE 0 END), 2) AS "Other",
    ROUND(SUM(total), 2) AS "Total"
FROM categorized
GROUP BY fiscal_year
ORDER BY fiscal_year',
 '[{"name":"acqUnitId","type":"select","label":"Acquisitions Unit","required":true,"default":"","placeholder":"Select acquisitions unit","description":"Filter by acquisitions unit (institution)","options_sql":"SELECT id AS value, name AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY name"},{"name":"startDate","type":"date","label":"Start Date","required":true,"default":"$90_days_ago","placeholder":"YYYY-MM-DD","description":"Start of date range for fiscal year calculation"},{"name":"endDate","type":"date","label":"End Date","required":true,"default":"$today","placeholder":"YYYY-MM-DD","description":"End of date range for fiscal year calculation"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 5. Fund Allocation by Fiscal Year
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('fund-allocation',
 'Fund Allocation by Fiscal Year',
 'Shows total allocated budget amounts per fiscal year for a selected fiscal year series.',
 'finance',
 'SELECT
    fyt."name" AS "Fiscal Year",
    ROUND(SUM(bt.allocated), 2) AS "Total Allocated"
FROM finance.budget__t bt
INNER JOIN finance.fund__t ft ON bt.fund_id = ft.id
INNER JOIN finance.fiscal_year__t fyt ON bt.fiscal_year_id = fyt.id
WHERE fyt.series = :fiscalYearSeries
GROUP BY fyt."name"
ORDER BY fyt."name"',
 '[{"name":"fiscalYearSeries","type":"select","label":"Fiscal Year Series","required":true,"default":"","placeholder":"Select fiscal year series","description":"Filter by fiscal year series (e.g. SCFY, ACFY)","options_sql":"SELECT DISTINCT series AS value, series AS label FROM finance.fiscal_year__t ORDER BY series"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 6. Remaining Invoices (Ongoing Subscriptions)
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('remaining-invoices',
 'Remaining Invoices (Ongoing Subscriptions)',
 'Lists ongoing subscription purchase orders with unreleased encumbrances, showing invoice details, vendor info, and subscription dates. Useful for tracking outstanding payments.',
 'acquisitions',
 'SELECT DISTINCT
    plt.po_line_number AS "POL #",
    plt.title_or_package AS "Title or Package",
    ROUND(tt.encumbrance__initial_amount_encumbered, 2) AS "Initial Encumbrance",
    tt.encumbrance__status AS "Encumbrance Status",
    ot.name AS "Vendor Name",
    plt.details__receiving_note AS "POL Receiving Note",
    plt.description AS "POL Internal Note",
    ot.code AS "Vendor Code",
    it.vendor_invoice_no AS "Vendor Invoice #",
    it.invoice_date AS "Invoice Date",
    it.approval_date AS "Approval Date",
    ilt.invoice_line_number AS "Invoice Line #",
    ilt.comment AS "Invoice Line Comment",
    ilt.subscription_info AS "Subscription Info",
    ilt.subscription_start AS "Subscription Start",
    ilt.subscription_end AS "Subscription End",
    plt.order_format AS "Order Format",
    pot.workflow_status AS "Workflow Status",
    pot.ongoing__is_subscription AS "Is Subscription"
FROM orders.purchase_order__t pot
INNER JOIN orders.po_line__t plt ON pot.id = plt.purchase_order_id
LEFT JOIN invoice.invoice_lines__t ilt ON ilt.po_line_id = plt.id
LEFT JOIN invoice.invoice_lines__t__fund_distributions iltfd ON iltfd.po_line_id = plt.id
LEFT JOIN invoice.invoices__t it ON it.id = ilt.invoice_id
LEFT JOIN finance.fund__t ft ON ft.id = plt.id
LEFT JOIN finance.budget__t bt ON bt.fund_id = iltfd.fund_distributions__fund_id
LEFT JOIN finance.transaction__t tt ON tt.encumbrance__source_po_line_id = plt.id
LEFT JOIN organizations.organizations__t ot ON pot.vendor = ot.id
WHERE pot.order_type = ''Ongoing''
  AND pot.ongoing__is_subscription = ''true''
  AND pot.po_number_prefix = :poPrefix
  AND tt.encumbrance__status = ''Unreleased''
  AND pot.workflow_status <> ''Closed''
  AND it.status <> ''Cancelled''
  AND plt.po_line_number LIKE :polNumberFilter
  AND plt.title_or_package LIKE :titleFilter
  AND ot.name LIKE :vendorFilter
GROUP BY plt.po_line_number, plt.title_or_package, ft.code, bt.name, ilt.total,
    tt.encumbrance__initial_amount_encumbered, tt.encumbrance__status,
    ot.name, plt.details__receiving_note, plt.description, ot.code,
    it.vendor_invoice_no, it.invoice_date, it.approval_date,
    ilt.invoice_line_number, ilt.comment, ilt.subscription_info,
    ilt.subscription_start, ilt.subscription_end,
    plt.order_format, plt.id, pot.workflow_status, pot.ongoing__is_subscription
ORDER BY plt.po_line_number ASC, ot.name ASC',
 '[{"name":"poPrefix","type":"select","label":"PO Number Prefix","required":true,"default":"","placeholder":"Select PO prefix","description":"Filter by purchase order number prefix (institution code)","options_sql":"SELECT DISTINCT po_number_prefix AS value, po_number_prefix AS label FROM orders.purchase_order__t WHERE po_number_prefix IS NOT NULL AND po_number_prefix <> '''' ORDER BY po_number_prefix"},{"name":"polNumberFilter","type":"text","label":"POL Number","required":false,"default":"","placeholder":"Filter by POL #","description":"Partial match on purchase order line number","wrap":"like"},{"name":"titleFilter","type":"text","label":"Title","required":false,"default":"","placeholder":"Filter by title","description":"Partial match on title or package name","wrap":"like"},{"name":"vendorFilter","type":"text","label":"Vendor Name","required":false,"default":"","placeholder":"Filter by vendor","description":"Partial match on vendor/organization name","wrap":"like"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 7. Item Count by Library
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('item-count-by-library',
 'Item Count by Library',
 'Counts items grouped by library name, filtered by item status. Select a library or leave blank to see all.',
 'inventory',
 'SELECT
    lt.name AS "Library",
    COUNT(it.id) AS "Item Count"
FROM inventory.item__t it
LEFT JOIN inventory.location__t llt ON it.effective_location_id = llt.id
LEFT JOIN inventory.loclibrary__t lt ON llt.library_id = lt.id
WHERE lt.name LIKE :libraryName
  AND it.status__name LIKE :itemStatus
GROUP BY lt.name
ORDER BY lt.name',
 '[{"name":"libraryName","type":"select","label":"Library","required":false,"default":"","placeholder":"All libraries","description":"Filter by library name","wrap":"like","options_sql":"SELECT DISTINCT name AS value, name AS label FROM inventory.loclibrary__t ORDER BY name"},{"name":"itemStatus","type":"select","label":"Item Status","required":false,"default":"","placeholder":"All statuses","description":"Filter by item status","wrap":"like","options_sql":"SELECT DISTINCT status__name AS value, status__name AS label FROM inventory.item__t WHERE status__name IS NOT NULL ORDER BY status__name"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 8. Item Count by Location
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('item-count-by-location',
 'Item Count by Location',
 'Counts items grouped by effective location, filtered by item status. Select a library to narrow locations.',
 'inventory',
 'SELECT
    llt.name AS "Location",
    COUNT(DISTINCT it.id) AS "Item Count"
FROM inventory.item__t it
LEFT JOIN inventory.location__t llt ON it.effective_location_id = llt.id
LEFT JOIN inventory.loclibrary__t lt ON llt.library_id = lt.id
WHERE lt.name LIKE :libraryName
  AND it.status__name LIKE :itemStatus
GROUP BY llt.name
ORDER BY llt.name',
 '[{"name":"libraryName","type":"select","label":"Library","required":false,"default":"","placeholder":"All libraries","description":"Filter locations by their parent library","wrap":"like","options_sql":"SELECT DISTINCT name AS value, name AS label FROM inventory.loclibrary__t ORDER BY name"},{"name":"itemStatus","type":"select","label":"Item Status","required":false,"default":"","placeholder":"All statuses","description":"Filter by item status","wrap":"like","options_sql":"SELECT DISTINCT status__name AS value, status__name AS label FROM inventory.item__t WHERE status__name IS NOT NULL ORDER BY status__name"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 9. Item Count by Material Type and Campus
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('item-count-by-material-and-campus',
 'Item Count by Material Type and Campus',
 'Counts items grouped by material type and campus. Select a campus or filter by material type.',
 'inventory',
 'SELECT
    mtt.name AS "Material Type",
    lct.name AS "Campus",
    COUNT(it.id) AS "Item Count"
FROM inventory.item__t it
LEFT JOIN inventory.material_type__t mtt ON it.material_type_id = mtt.id
LEFT JOIN inventory.location__t llt ON it.effective_location_id = llt.id
LEFT JOIN inventory."loc-campus__t" lct ON llt.campus_id = lct.id
WHERE mtt.name LIKE :materialType
  AND lct.name LIKE :campusName
GROUP BY mtt.name, lct.name
ORDER BY mtt.name',
 '[{"name":"materialType","type":"text","label":"Material Type","required":false,"default":"","placeholder":"Filter by material type","description":"Partial match on material type name","wrap":"like"},{"name":"campusName","type":"select","label":"Campus","required":false,"default":"","placeholder":"All campuses","description":"Filter by campus name","wrap":"like","options_sql":"SELECT DISTINCT name AS value, name AS label FROM inventory.\\\"loc-campus__t\\\" ORDER BY name"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 10. POL Report
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('pol-report',
 'Purchase Order Line Report',
 'Detailed purchase order line report showing POL number, title, expense class, format, material type, PO status/type, and total paid amount within the selected date range.',
 'acquisitions',
 'SELECT
    plt.po_line_number AS "POL #",
    plt.title_or_package AS "Title or Package",
    COALESCE(inv.expense, ''N/A'') AS "Expense Class Name",
    plt.order_format AS "POL format",
    COALESCE(mtte."name", mttp."name", '''') AS "Material Type",
    potaui.workflow_status AS "PO Status",
    potaui.order_type AS "PO Type",
    inv.invoice_date AS "Invoice Date",
    ROUND(SUM(inv.payment), 2) AS "Total Paid"
FROM orders.po_line__t plt
INNER JOIN orders.purchase_order__t__acq_unit_ids potaui
    ON (plt.purchase_order_id = potaui.id AND potaui.acq_unit_ids = :acqUnitId)
INNER JOIN (
    SELECT ROUND(SUM(iltfd.total * (iltfd.fund_distributions__value * .01)), 2) AS payment,
           po_line_id, ftaui."name" AS fund,
           COALESCE(ect.name, ''N/A'') AS expense,
           it.payment_date, it.invoice_date
    FROM invoice.invoice_lines__t__fund_distributions iltfd
    INNER JOIN invoice.invoices__t it ON it.id = iltfd.invoice_id
        AND it.payment_date::date BETWEEN :startDate AND :endDate
    LEFT JOIN finance.fund__t__acq_unit_ids ftaui ON iltfd.fund_distributions__fund_id = ftaui.id
    LEFT JOIN finance.expense_class__t ect ON iltfd.fund_distributions__expense_class_id = ect.id
    WHERE iltfd.invoice_line_status = ''Paid''
    GROUP BY po_line_id, ftaui."name", ect.name, it.payment_date, it.invoice_date
) AS inv ON plt.id = inv.po_line_id
LEFT JOIN inventory.material_type__t mtte ON mtte.id = plt.eresource__material_type
LEFT JOIN inventory.material_type__t mttp ON mttp.id = plt.physical__material_type
WHERE plt.po_line_number LIKE :polNumberFilter
  AND plt.title_or_package LIKE :titleFilter
  AND COALESCE(inv.expense, ''N/A'') LIKE :expenseClassFilter
  AND plt.order_format LIKE :polFormatFilter
  AND (mtte."name" LIKE :materialTypeFilter OR mttp."name" LIKE :materialTypeFilter)
  AND potaui.workflow_status LIKE :poStatusFilter
  AND potaui.order_type LIKE :poTypeFilter
GROUP BY plt.po_line_number, plt.title_or_package, inv.expense, plt.order_format,
         mtte."name", mttp."name", potaui.workflow_status, potaui.order_type, inv.invoice_date
ORDER BY plt.po_line_number ASC',
 '[{"name":"acqUnitId","type":"select","label":"Acquisitions Unit","required":true,"default":"","placeholder":"Select acquisitions unit","description":"Filter by acquisitions unit (institution)","options_sql":"SELECT id AS value, name AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY name"},{"name":"startDate","type":"date","label":"Start Date","required":true,"default":"$fiscal_year_start","placeholder":"YYYY-MM-DD","description":"Beginning of fiscal period"},{"name":"endDate","type":"date","label":"End Date","required":true,"default":"$fiscal_year_end","placeholder":"YYYY-MM-DD","description":"End of fiscal period"},{"name":"polNumberFilter","type":"text","label":"POL Number","required":false,"default":"","placeholder":"Filter by POL #","description":"Partial match on POL number","wrap":"like"},{"name":"titleFilter","type":"text","label":"Title","required":false,"default":"","placeholder":"Filter by title","description":"Partial match on title or package name","wrap":"like"},{"name":"expenseClassFilter","type":"text","label":"Expense Class","required":false,"default":"","placeholder":"Filter by expense class","description":"Partial match on expense class name","wrap":"like"},{"name":"polFormatFilter","type":"text","label":"POL Format","required":false,"default":"","placeholder":"e.g. Physical Resource","description":"Partial match on order format","wrap":"like"},{"name":"materialTypeFilter","type":"text","label":"Material Type","required":false,"default":"","placeholder":"e.g. Book, E-Journal","description":"Partial match on material type","wrap":"like"},{"name":"poStatusFilter","type":"text","label":"PO Status","required":false,"default":"","placeholder":"e.g. Open, Closed","description":"Filter by purchase order workflow status","wrap":"like"},{"name":"poTypeFilter","type":"text","label":"PO Type","required":false,"default":"","placeholder":"e.g. One-Time, Ongoing","description":"Filter by purchase order type","wrap":"like"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);

-- 11. Expense Class Report
INSERT INTO report_templates (slug, name, description, category, sql_template, parameters, default_limit, created_by) VALUES
('expense-class-report',
 'Expense Class Report',
 'Shows total paid amounts grouped by expense class and material type within a date range. Useful for analyzing spending by expense classification.',
 'finance',
 'SELECT
    COALESCE(inv.expense, ''N/A'') AS "Expense Class Name",
    COALESCE(mtte."name", mttp."name", '''') AS "Material Type",
    ROUND(SUM(inv.payment), 2) AS "Total Paid"
FROM orders.po_line__t plt
INNER JOIN orders.purchase_order__t__acq_unit_ids potaui
    ON (plt.purchase_order_id = potaui.id AND potaui.acq_unit_ids = :acqUnitId)
INNER JOIN (
    SELECT SUM(iltfd.total * (iltfd.fund_distributions__value * 0.01)) AS payment,
           po_line_id, ftaui."name" AS fund,
           COALESCE(ect.name, ''N/A'') AS expense,
           it.payment_date
    FROM invoice.invoice_lines__t__fund_distributions iltfd
    INNER JOIN invoice.invoices__t it ON it.id = iltfd.invoice_id
        AND it.payment_date::date BETWEEN :startDate AND :endDate
    LEFT JOIN finance.fund__t__acq_unit_ids ftaui ON iltfd.fund_distributions__fund_id = ftaui.id
    LEFT JOIN finance.expense_class__t ect ON iltfd.fund_distributions__expense_class_id = ect.id
    WHERE iltfd.invoice_line_status = ''Paid''
    GROUP BY po_line_id, ftaui."name", ect.name, it.payment_date
) AS inv ON plt.id = inv.po_line_id
LEFT JOIN inventory.material_type__t mtte ON mtte.id = plt.eresource__material_type
LEFT JOIN inventory.material_type__t mttp ON mttp.id = plt.physical__material_type
GROUP BY inv.expense, COALESCE(mtte."name", mttp."name", '''')
ORDER BY "Expense Class Name", "Material Type"',
 '[{"name":"acqUnitId","type":"select","label":"Acquisitions Unit","required":true,"default":"","placeholder":"Select acquisitions unit","description":"Filter by acquisitions unit (institution)","options_sql":"SELECT id AS value, name AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY name"},{"name":"startDate","type":"date","label":"Start Date","required":true,"default":"$fiscal_year_start","placeholder":"YYYY-MM-DD","description":"Beginning of fiscal period"},{"name":"endDate","type":"date","label":"End Date","required":true,"default":"$fiscal_year_end","placeholder":"YYYY-MM-DD","description":"End of fiscal period"}]',
 10000, 'manual')
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), sql_template=VALUES(sql_template), parameters=VALUES(parameters);
