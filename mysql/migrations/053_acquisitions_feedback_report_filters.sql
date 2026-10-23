START TRANSACTION;

INSERT INTO report_templates
  (slug, name, description, help_text, category, sql_template, parameters, data_source, execution_config, default_limit, is_active, created_by)
VALUES (
  'quarterly-open-orders', 'Open Order Report',
  'Review open and pending Smith acquisitions one-time orders not yet received, or received and not yet paid, with vendor and funding details for follow-up.',
  'Shows one row per purchase-order line for Open or Pending one-time orders that are not yet received or have been received but are not yet paid. The existing inclusion rule continues to exclude Cancelled, Receipt Not Required, receipt-status Ongoing, fully received and fully paid lines, and Ongoing orders. Receipt status and Payment status filters narrow eligible lines; both default to all listed statuses.

The report date is the order date for Open orders. Pending orders use the PO last-updated date even when a date_ordered value is present. Days open is measured from that report date; no expected-arrival date is used. The Smith fiscal year runs July through June (Q1 is July–September). The start and end dates apply inclusively to the report date.

Choose All order formats to include physical, electronic, mixed, and other formats. Re-encumber Any includes both checked and unchecked PO values; Off also includes an unset value. Split fund codes and expense classes are joined with semicolon-space while preserving one row per PO line. The worklist CSV includes both the PO UUID and PO line UUID at the end of each row. The separate FOLIO UUID export contains the PO line UUID.',
  'acquisitions',
  'WITH params AS (
  SELECT CAST(:acqUnit AS text) AS acq_unit,
         CAST(:startDate AS date) AS start_date,
         CAST(:endDate AS date) AS end_date,
         CAST(:orderFormat AS text) AS order_format,
         CAST(:reEncumber AS text) AS re_encumber
),
scoped_orders AS (
  SELECT po.id AS purchase_order_id,
         po.po_number,
         po.workflow_status,
         po.order_type,
         po.re_encumber,
         CAST(po.date_ordered AS date) AS date_ordered,
         CAST(po.metadata__updated_date AS date) AS po_updated_date,
         po.vendor AS vendor_id,
         org.name AS vendor_name,
         org.code AS vendor_code,
         p.start_date,
         p.end_date,
         p.order_format,
         p.re_encumber AS re_encumber_filter
  FROM orders.purchase_order__t AS po
  CROSS JOIN params AS p
  LEFT JOIN organizations.organizations__t AS org ON org.id = po.vendor
  WHERE po.workflow_status IN (''Pending'', ''Open'')
    AND po.order_type = ''One-Time''
    AND EXISTS (
      SELECT 1
      FROM orders.purchase_order__t__acq_unit_ids AS pau
      JOIN orders.acquisitions_unit__t AS au ON au.id = pau.acq_unit_ids
      WHERE pau.id = po.id AND au.name = p.acq_unit
    )
    AND (
      p.re_encumber = ''Any''
      OR (p.re_encumber = ''On'' AND po.re_encumber IS TRUE)
      OR (p.re_encumber = ''Off'' AND po.re_encumber IS NOT TRUE)
    )
),
candidate_lines AS (
  SELECT pol.id AS po_line_id,
         pol.po_line_number,
         pol.title_or_package AS title,
         pol.receipt_status,
         pol.payment_status,
         pol.order_format,
         pol.physical__material_type,
         pol.eresource__material_type,
         pol.vendor_detail__vendor_account AS vendor_account_number,
         pol.metadata__updated_date AS line_updated_date,
         so.purchase_order_id,
         so.po_number,
         so.workflow_status,
         so.order_type,
         so.re_encumber,
         so.vendor_id,
         so.vendor_name,
         so.vendor_code,
         so.start_date,
         so.end_date,
         so.order_format AS order_format_filter,
         CASE WHEN so.workflow_status = ''Pending''
              THEN so.po_updated_date
              ELSE so.date_ordered
         END AS report_date,
         CASE WHEN so.workflow_status = ''Pending''
              THEN ''Last updated (pending)''
              ELSE ''Opened''
         END AS date_used
  FROM scoped_orders AS so
  JOIN orders.po_line__t AS pol ON pol.purchase_order_id = so.purchase_order_id
  WHERE (
       pol.receipt_status IN (''Pending'', ''Awaiting Receipt'', ''Partially Received'')
       OR (pol.receipt_status = ''Fully Received''
           AND pol.payment_status IN (''Awaiting Payment'', ''Partially Paid''))
  )
    AND (/*__RECEIPT_STATUS_FILTER__*/TRUE)
    AND (/*__PAYMENT_STATUS_FILTER__*/TRUE)
    AND (so.order_format = ''All'' OR pol.order_format = so.order_format)
),
included_lines AS (
  SELECT *
  FROM candidate_lines
  WHERE report_date BETWEEN start_date AND end_date
),
line_distributions AS (
  SELECT fd.id AS po_line_id,
         STRING_AGG(DISTINCT f.code, ''; '' ORDER BY f.code) AS fund_codes,
         STRING_AGG(DISTINCT ec.code, ''; '' ORDER BY ec.code) AS expense_classes
  FROM orders.po_line__t__fund_distribution AS fd
  JOIN included_lines AS il ON il.po_line_id = fd.id
  LEFT JOIN finance.fund__t AS f ON f.id = fd.fund_distribution__fund_id
  LEFT JOIN finance.expense_class__t AS ec
    ON ec.id = fd.fund_distribution__expense_class_id
  GROUP BY fd.id
),
vendor_reference_values AS (
  SELECT r.id AS po_line_id,
         NULLIF(BTRIM(r.vendor_detail__reference_numbers__ref_number), '''') AS reference_number
  FROM orders.po_line__t__vendor_detail__reference_numbers AS r
  WHERE r.vendor_detail__reference_numbers__ref_number_type = ''Vendor order reference number''
),
vendor_references AS (
  SELECT rv.po_line_id,
         STRING_AGG(DISTINCT rv.reference_number, ''; '' ORDER BY rv.reference_number) AS vendor_reference_number
  FROM vendor_reference_values AS rv
  JOIN included_lines AS il ON il.po_line_id = rv.po_line_id
  WHERE rv.reference_number IS NOT NULL
  GROUP BY rv.po_line_id
),
vendor_email_values AS (
  SELECT oc.id AS vendor_id,
         NULLIF(BTRIM(ce.emails__value), '''') AS email
  FROM organizations.organizations__t__contacts AS oc
  JOIN organizations.contacts__t__emails AS ce ON ce.id = oc.contacts
  WHERE NULLIF(BTRIM(ce.emails__value), '''') IS NOT NULL
),
vendor_contacts AS (
  SELECT ev.vendor_id,
         STRING_AGG(DISTINCT ev.email, ''; '' ORDER BY ev.email) AS contact_emails
  FROM vendor_email_values AS ev
  JOIN (SELECT DISTINCT vendor_id FROM included_lines WHERE vendor_id IS NOT NULL) AS iv
    ON iv.vendor_id = ev.vendor_id
  GROUP BY ev.vendor_id
)
SELECT il.po_number AS "PO number",
       il.po_line_number AS "PO line number",
       il.workflow_status AS "PO status",
       il.title AS "Title",
       il.receipt_status AS "Receipt status",
       il.payment_status AS "Payment status",
       il.report_date AS "Report date",
       il.date_used AS "Date used",
       CURRENT_DATE - il.report_date AS "Days open",
       ''FY'' || (CAST(EXTRACT(YEAR FROM (il.report_date - INTERVAL ''6 months'')) AS integer) + 1)::text
         || '' Q'' || CAST(EXTRACT(QUARTER FROM (il.report_date - INTERVAL ''6 months'')) AS integer)::text
         AS "Fiscal quarter",
       ld.fund_codes AS "Fund codes",
       ld.expense_classes AS "Expense classes",
       il.order_format AS "Order format",
       il.order_type AS "Order type",
       COALESCE(physical_mt.name, eresource_mt.name) AS "Material type",
       il.vendor_name AS "Vendor name",
       il.vendor_code AS "Vendor code",
       il.vendor_account_number AS "Vendor account number",
       vr.vendor_reference_number AS "Vendor order reference number",
       vc.contact_emails AS "Vendor contact email(s)",
       il.re_encumber AS "Re-encumber",
       il.line_updated_date AS "Last updated",
       il.purchase_order_id AS "PO UUID",
       il.po_line_id AS "PO Line UUID"
FROM included_lines AS il
LEFT JOIN line_distributions AS ld ON ld.po_line_id = il.po_line_id
LEFT JOIN vendor_references AS vr ON vr.po_line_id = il.po_line_id
LEFT JOIN vendor_contacts AS vc ON vc.vendor_id = il.vendor_id
LEFT JOIN inventory.material_type__t AS physical_mt
  ON physical_mt.id = il.physical__material_type
LEFT JOIN inventory.material_type__t AS eresource_mt
  ON eresource_mt.id = il.eresource__material_type
ORDER BY il.report_date, il.po_number, il.po_line_number
LIMIT 100001',
  '[{"name":"acqUnit","type":"select","label":"Acquisitions unit","required":true,"default":"SC","options_db":"folio","options_sql":"SELECT name AS value, name AS label FROM orders.acquisitions_unit__t ORDER BY name"},{"name":"startDate","type":"date","label":"Start date","required":true,"default":"$fiscal_year_start"},{"name":"endDate","type":"date","label":"End date","required":true,"default":"$today"},{"name":"orderFormat","type":"select","label":"Order format","required":true,"default":"All","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''All'', ''All formats''), (''Electronic Resource'', ''Electronic Resource''), (''Physical Resource'', ''Physical Resource''), (''P/E Mix'', ''P/E Mix''), (''Other'', ''Other'')) AS choices(value, label)"},{"name":"reEncumber","type":"select","label":"Re-encumber","required":true,"default":"Any","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''Any'', ''Any''), (''On'', ''On''), (''Off'', ''Off'')) AS choices(value, label)"},{"name":"receiptStatuses","type":"multiselect","label":"Receipt status","required":true,"default":"Pending,Awaiting Receipt,Partially Received,Fully Received","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''Pending'',''Pending''), (''Awaiting Receipt'',''Awaiting Receipt''), (''Partially Received'',''Partially Received''), (''Fully Received'',''Fully Received'')) AS choices(value, label)"},{"name":"paymentStatuses","type":"multiselect","label":"Payment status","required":true,"default":"Pending,Awaiting Payment,Partially Paid,Fully Paid,Ongoing,Payment Not Required,Cancelled","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''Pending'',''Pending''), (''Awaiting Payment'',''Awaiting Payment''), (''Partially Paid'',''Partially Paid''), (''Fully Paid'',''Fully Paid''), (''Ongoing'',''Ongoing''), (''Payment Not Required'',''Payment Not Required''), (''Cancelled'',''Cancelled'')) AS choices(value, label)"}]', 'folio', '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"PO Line UUID","header":"PO Line UUID"}}', 100000, 1, 'manual'
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), help_text = VALUES(help_text),
  category = VALUES(category), sql_template = VALUES(sql_template), parameters = VALUES(parameters),
  data_source = VALUES(data_source), execution_config = VALUES(execution_config),
  default_limit = VALUES(default_limit), is_active = VALUES(is_active), created_by = VALUES(created_by);

INSERT INTO report_templates
  (slug, name, description, help_text, category, sql_template, parameters, data_source, execution_config, default_limit, is_active, created_by)
VALUES (
  'orders-missing-acquisitions-unit', 'Orders Missing an Acquisitions Unit',
  'Finds purchase orders with no non-null acquisitions unit whose order numbers begin with SC or contain digits only, and shows vendor and fund-code evidence that can help staff identify likely Smith orders.',
  'Finds purchase orders with no non-null acquisitions unit: either no acquisition-unit row or only rows with null unit IDs. Includes all workflow statuses for order numbers with an SC prefix (case-insensitive) or numbers only (digits only). Orders with any non-null acquisition-unit ID and other order-number patterns are excluded.

“Likely Smith” is an evidence flag, not a definitive ownership decision. It says Yes when the vendor code starts with SC or any order line has a fund code starting with SC. A blank value means neither signal matched; the order-number pattern alone does not identify Smith. Staff should review the vendor name, vendor code, fund codes, and status before acting.

Fund codes are distinct across all order lines. Results list likely Smith orders first, then sort by creation date newest first. The PO UUID is included for FOLIO follow-up and in the identifier export.

Date created start and end filters are optional and inclusive on metadata creation date. Workflow status and Order type default to all listed values and can be narrowed independently.',
  'acquisitions',
  'WITH params AS (
  SELECT CAST(:createdStartDate AS date) AS created_start_date,
         CAST(:createdEndDate AS date) AS created_end_date
),
missing_unit_orders AS (
  SELECT
    po.id,
    po.po_number,
    po.vendor,
    po.workflow_status,
    po.order_type,
    po.date_ordered,
    po.metadata__created_date AS created_date
  FROM orders.purchase_order__t AS po
  CROSS JOIN params AS report_params
  WHERE NOT EXISTS (
    SELECT 1
    FROM orders.purchase_order__t__acq_unit_ids AS po_unit
    WHERE po_unit.id = po.id
      AND po_unit.acq_unit_ids IS NOT NULL
  )
    AND (
      UPPER(COALESCE(po.po_number, '''')) LIKE ''SC%''
      OR po.po_number ~ ''^[0-9]+$''
    )
    AND (report_params.created_start_date IS NULL OR CAST(po.metadata__created_date AS date) >= report_params.created_start_date)
    AND (report_params.created_end_date IS NULL OR CAST(po.metadata__created_date AS date) <= report_params.created_end_date)
    AND (/*__WORKFLOW_STATUS_FILTER__*/TRUE)
    AND (/*__ORDER_TYPE_FILTER__*/TRUE)
),
line_counts AS (
  SELECT pol.purchase_order_id, COUNT(DISTINCT pol.id) AS po_line_count
  FROM orders.po_line__t AS pol
  GROUP BY pol.purchase_order_id
),
distinct_fund_codes AS (
  SELECT DISTINCT
    pol.purchase_order_id,
    NULLIF(BTRIM(fd.fund_distribution__code), '''') AS fund_code
  FROM orders.po_line__t AS pol
  JOIN orders.po_line__t__fund_distribution AS fd ON fd.id = pol.id
  WHERE NULLIF(BTRIM(fd.fund_distribution__code), '''') IS NOT NULL
),
fund_summary AS (
  SELECT
    fund_codes.purchase_order_id,
    STRING_AGG(fund_codes.fund_code, ''; '' ORDER BY fund_codes.fund_code) AS fund_codes,
    MAX(CASE WHEN UPPER(fund_codes.fund_code) LIKE ''SC%'' THEN 1 ELSE 0 END) AS has_smith_fund_code
  FROM distinct_fund_codes AS fund_codes
  GROUP BY fund_codes.purchase_order_id
),
classified_orders AS (
  SELECT
    po.id,
    po.po_number,
    po.workflow_status,
    po.order_type,
    vendor.name AS vendor_name,
    vendor.code AS vendor_code,
    po.date_ordered,
    po.created_date,
    COALESCE(line_counts.po_line_count, 0) AS po_line_count,
    fund_summary.fund_codes,
    (
      UPPER(COALESCE(vendor.code, '''')) LIKE ''SC%''
      OR COALESCE(fund_summary.has_smith_fund_code, 0) = 1
    ) AS is_likely_smith
  FROM missing_unit_orders AS po
  LEFT JOIN organizations.organizations__t AS vendor ON vendor.id = po.vendor
  LEFT JOIN line_counts ON line_counts.purchase_order_id = po.id
  LEFT JOIN fund_summary ON fund_summary.purchase_order_id = po.id
)
SELECT
  report.po_number AS "PO Number",
  CASE WHEN UPPER(COALESCE(report.po_number, '''')) LIKE ''SC%'' THEN ''SC prefix'' ELSE ''Numbers only'' END AS "Number Pattern",
  CASE WHEN report.is_likely_smith THEN ''Yes'' ELSE '''' END AS "Likely Smith",
  report.workflow_status AS "Workflow Status",
  report.order_type AS "Order Type",
  report.vendor_name AS "Vendor Name",
  report.vendor_code AS "Vendor Code",
  report.date_ordered AS "Date Ordered",
  report.created_date AS "Created Date",
  report.po_line_count AS "Number of PO Lines",
  report.fund_codes AS "Fund Codes",
  report.id AS "PO UUID"
FROM classified_orders AS report
ORDER BY report.is_likely_smith DESC, report.created_date DESC NULLS LAST, report.po_number, report.id
LIMIT 100001',
  '[{"name":"createdStartDate","type":"date","label":"Date created start","required":false,"default":"","description":"Include orders created on or after this date.","placeholder":"YYYY-MM-DD"},{"name":"createdEndDate","type":"date","label":"Date created end","required":false,"default":"","description":"Include orders created on or before this date.","placeholder":"YYYY-MM-DD"},{"name":"workflowStatuses","type":"multiselect","label":"Workflow status","required":true,"default":"Pending,Open,Closed","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''Pending'',''Pending''), (''Open'',''Open''), (''Closed'',''Closed'')) AS choices(value, label)"},{"name":"orderTypes","type":"multiselect","label":"Order type","required":true,"default":"One-Time,Ongoing","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''One-Time'',''One-Time''), (''Ongoing'',''Ongoing'')) AS choices(value, label)"}]', 'folio', '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"PO UUID","header":"UUID"}}', 100000, 1, 'manual'
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description), help_text = VALUES(help_text),
  category = VALUES(category), sql_template = VALUES(sql_template), parameters = VALUES(parameters),
  data_source = VALUES(data_source), execution_config = VALUES(execution_config),
  default_limit = VALUES(default_limit), is_active = VALUES(is_active), created_by = VALUES(created_by);

UPDATE report_templates SET is_active = 0 WHERE id = 11 AND slug = 'pol-report';

COMMIT;
