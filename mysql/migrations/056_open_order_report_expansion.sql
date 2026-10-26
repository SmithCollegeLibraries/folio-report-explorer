START TRANSACTION;

INSERT INTO report_templates
  (slug, name, description, help_text, category, sql_template, parameters, data_source, execution_config, default_limit, is_active, created_by)
VALUES (
  'quarterly-open-orders',
  'Open Order Report',
  'Review Smith acquisitions PO lines across every PO status and order type, with selectable line rules, optional dates, and title, number, material, and expense filters.',
  'Shows one row per purchase-order line in the selected acquisitions unit. PO status defaults to Pending, Open, and Closed; order type defaults to One-Time and Ongoing. The default line view preserves the previous rule: pending, awaiting receipt, or partially received lines, plus fully received lines that are awaiting payment or partially paid. Choose All lines to include every receipt and payment state. Receipt and payment status selections narrow either line view.

The report date is the PO last-updated date for Pending orders and date ordered for Open or Closed orders. Start and end dates are optional and inclusive; leaving both blank includes all dates. Title and PO line number searches are case-insensitive partial matches; percent and underscore characters are treated literally. Material type choices reflect values used on Smith PO lines. Expense class matches if any fund distribution has a selected class; (none) matches a line with no expense class.

Total paid sums paid invoice-line totals across all time, independent of report dates. Currency totals are shown separately on one line when currencies differ. Last invoice and payment dates come from those paid invoices. Fund distributions, invoice totals, vendor details, and material types are aggregated so the report keeps one row per PO line. The worklist CSV includes both the PO UUID and PO line UUID at the end of each row. The separate FOLIO UUID export contains the PO line UUID.',
  'acquisitions',
  'WITH params AS (
  SELECT CAST(:acqUnit AS text) AS acq_unit,
         CAST(:lineView AS text) AS line_view,
         CAST(:titleSearch AS text) AS title_search,
         CAST(:poLineNumberSearch AS text) AS po_line_number_search,
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
         p.line_view,
         p.title_search,
         p.po_line_number_search,
         p.order_format,
         p.re_encumber AS re_encumber_filter
  FROM orders.purchase_order__t AS po
  CROSS JOIN params AS p
  LEFT JOIN organizations.organizations__t AS org ON org.id = po.vendor
  WHERE (/*__WORKFLOW_STATUS_FILTER__*/TRUE)
    AND (/*__ORDER_TYPE_FILTER__*/TRUE)
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
         so.line_view,
         so.title_search,
         so.po_line_number_search,
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
       so.line_view = ''All lines''
       OR (
         pol.receipt_status IN (''Pending'', ''Awaiting Receipt'', ''Partially Received'')
         OR (pol.receipt_status = ''Fully Received''
             AND pol.payment_status IN (''Awaiting Payment'', ''Partially Paid''))
       )
  )
    AND (/*__RECEIPT_STATUS_FILTER__*/TRUE)
    AND (/*__PAYMENT_STATUS_FILTER__*/TRUE)
    AND (so.order_format = ''All'' OR pol.order_format = so.order_format)
    AND (so.title_search IS NULL OR pol.title_or_package ILIKE so.title_search ESCAPE CHR(92))
    AND (so.po_line_number_search IS NULL OR pol.po_line_number ILIKE so.po_line_number_search ESCAPE CHR(92))
),
included_lines AS (
  SELECT *
  FROM candidate_lines
  WHERE (start_date IS NULL OR report_date >= start_date)
    AND (end_date IS NULL OR report_date <= end_date)
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
),
paid_invoice_values AS (
  SELECT il.po_line_id,
         il.total,
         i.currency,
         i.invoice_date,
         i.payment_date
  FROM invoice.invoice_lines__t AS il
  JOIN invoice.invoices__t AS i ON i.id = il.invoice_id
  WHERE il.invoice_line_status = ''Paid''
),
paid_amounts_by_currency AS (
  SELECT po_line_id,
         currency,
         SUM(COALESCE(total, 0)) AS total_paid,
         MAX(invoice_date) AS last_invoice_date,
         MAX(payment_date) AS last_payment_date
  FROM paid_invoice_values
  GROUP BY po_line_id, currency
),
paid_totals AS (
  SELECT po_line_id,
         STRING_AGG(ROUND(total_paid, 2)::text || '' '' || COALESCE(currency, ''Unknown''), ''; '' ORDER BY COALESCE(currency, ''Unknown'')) AS total_paid,
         CAST(MAX(last_invoice_date) AS date) AS last_invoice_date,
         CAST(MAX(last_payment_date) AS date) AS last_payment_date
  FROM paid_amounts_by_currency
  GROUP BY po_line_id
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
       pt.total_paid AS "Total paid",
       pt.last_invoice_date AS "Last invoice date",
       pt.last_payment_date AS "Last payment date",
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
LEFT JOIN paid_totals AS pt ON pt.po_line_id = il.po_line_id
WHERE (/*__MATERIAL_TYPE_FILTER__*/TRUE)
  AND (/*__EXPENSE_CLASS_FILTER__*/TRUE)
ORDER BY il.report_date, il.po_number, il.po_line_number
LIMIT 100001',
  '[{"name":"acqUnit","type":"select","label":"Acquisitions unit","required":true,"default":"SC","options_db":"folio","options_sql":"SELECT name AS value, name AS label FROM orders.acquisitions_unit__t ORDER BY name","placeholder":"Select acquisitions unit"},{"name":"workflowStatuses","type":"multiselect","label":"PO status","required":true,"default":"Pending,Open,Closed","options_sql":"SELECT value, label FROM (VALUES (''Pending'', ''Pending''), (''Open'', ''Open''), (''Closed'', ''Closed'')) AS choices(value, label) ORDER BY value","options_db":"folio"},{"name":"orderTypes","type":"multiselect","label":"Order type","required":true,"default":"One-Time,Ongoing","options_sql":"SELECT value, label FROM (VALUES (''One-Time'', ''One-Time''), (''Ongoing'', ''Ongoing'')) AS choices(value, label) ORDER BY value","options_db":"folio"},{"name":"lineView","type":"select","label":"Line view","required":true,"default":"Not yet received or not yet paid","options_sql":"SELECT value, label FROM (VALUES (''Not yet received or not yet paid'', ''Not yet received or not yet paid''), (''All lines'', ''All lines'')) AS choices(value, label) ORDER BY value","options_db":"folio"},{"name":"titleSearch","type":"text","label":"Title search","required":false,"default":"","placeholder":"All titles","description":"Case-insensitive partial match. Percent and underscore are treated literally.","max_length":200},{"name":"poLineNumberSearch","type":"text","label":"PO line number search","required":false,"default":"","placeholder":"All PO line numbers","description":"Case-insensitive partial match. Percent and underscore are treated literally.","max_length":100},{"name":"startDate","type":"date","label":"Start date","required":false,"default":"","description":"Optional inclusive lower bound on the report date."},{"name":"endDate","type":"date","label":"End date","required":false,"default":"","description":"Optional inclusive upper bound on the report date."},{"name":"receiptStatuses","type":"multiselect","label":"Receipt status","required":true,"default":"Pending,Awaiting Receipt,Partially Received,Fully Received,Receipt Not Required,Ongoing,Cancelled","options_sql":"SELECT value, label FROM (VALUES (''Pending'', ''Pending''), (''Awaiting Receipt'', ''Awaiting Receipt''), (''Partially Received'', ''Partially Received''), (''Fully Received'', ''Fully Received''), (''Receipt Not Required'', ''Receipt Not Required''), (''Ongoing'', ''Ongoing''), (''Cancelled'', ''Cancelled'')) AS choices(value, label) ORDER BY value","options_db":"folio"},{"name":"paymentStatuses","type":"multiselect","label":"Payment status","required":true,"default":"Pending,Awaiting Payment,Partially Paid,Fully Paid,Ongoing,Payment Not Required,Cancelled","options_sql":"SELECT value, label FROM (VALUES (''Pending'', ''Pending''), (''Awaiting Payment'', ''Awaiting Payment''), (''Partially Paid'', ''Partially Paid''), (''Fully Paid'', ''Fully Paid''), (''Ongoing'', ''Ongoing''), (''Payment Not Required'', ''Payment Not Required''), (''Cancelled'', ''Cancelled'')) AS choices(value, label) ORDER BY value","options_db":"folio"},{"name":"materialTypes","type":"multiselect","label":"Material type","required":false,"default":"","placeholder":"All material types","description":"Select values to narrow the report; leave empty to include all material types.","options_db":"folio","options_sql":"SELECT DISTINCT mt.name AS value, mt.name AS label FROM inventory.material_type__t AS mt JOIN orders.po_line__t AS pol ON mt.id IN (pol.physical__material_type, pol.eresource__material_type) WHERE mt.name IS NOT NULL AND EXISTS (SELECT 1 FROM orders.purchase_order__t AS po JOIN orders.purchase_order__t__acq_unit_ids AS pau ON pau.id = po.id JOIN orders.acquisitions_unit__t AS au ON au.id = pau.acq_unit_ids WHERE po.id = pol.purchase_order_id AND au.name = ''SC'') ORDER BY mt.name"},{"name":"expenseClasses","type":"multiselect","label":"Expense class","required":false,"default":"","placeholder":"All expense classes","description":"Select values to narrow the report; (none) matches lines without an expense class.","options_db":"folio","options_sql":"SELECT value, label FROM (SELECT id::text AS value, name AS label FROM finance.expense_class__t UNION ALL SELECT ''__none__'' AS value, ''(none)'' AS label) AS options ORDER BY label"},{"name":"orderFormat","type":"select","label":"Order format","required":true,"default":"All","options_sql":"SELECT value, label FROM (VALUES (''All'', ''All''), (''Electronic Resource'', ''Electronic Resource''), (''Physical Resource'', ''Physical Resource''), (''P/E Mix'', ''P/E Mix''), (''Other'', ''Other'')) AS choices(value, label) ORDER BY value","options_db":"folio"},{"name":"reEncumber","type":"select","label":"Re-encumber","required":true,"default":"Any","options_sql":"SELECT value, label FROM (VALUES (''Any'', ''Any''), (''On'', ''On''), (''Off'', ''Off'')) AS choices(value, label) ORDER BY value","options_db":"folio"}]',
  'folio',
  '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"PO Line UUID","header":"PO Line UUID"}}',
  '100000',
  '1',
  'manual'
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  help_text = VALUES(help_text),
  category = VALUES(category),
  sql_template = VALUES(sql_template),
  parameters = VALUES(parameters),
  data_source = VALUES(data_source),
  execution_config = VALUES(execution_config),
  default_limit = VALUES(default_limit),
  is_active = VALUES(is_active),
  created_by = VALUES(created_by);

COMMIT;
