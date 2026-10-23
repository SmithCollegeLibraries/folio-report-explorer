START TRANSACTION;

INSERT INTO `report_templates`
  (`slug`, `name`, `description`, `help_text`, `category`, `sql_template`, `parameters`,
   `data_source`, `execution_config`, `default_limit`, `is_active`, `created_by`)
VALUES (
  'acquisitions-invoice-status-report',
  'Acquisitions Invoice Status Report',
  'Review invoices by acquisitions unit, invoice date, status, and vendor.',
  'Shows one row per invoice for the selected acquisitions unit and inclusive invoice-date range. The default unit is SC and the date range starts at the current Smith fiscal year and ends today. The default includes Open, Reviewed, Approved, and Paid invoices; select Cancelled when needed. Vendor options include every vendor appearing on SC invoices. Leave Vendor empty to include all vendors and invoices without a matching vendor record.

Invoice Number is the vendor invoice number. Fiscal Year is the stored FOLIO fiscal-year code. Total and Lock Total are shown in the invoice currency. Lock Total Mismatch is blank when Lock Total is unset or equals Total, and shows Mismatch when the set values differ. Invoice UUID is included for CSV reconciliation. Approval Date and Payment Date are included; approver names are not shown.

The report can return at most 100,000 invoices. Narrow the date range or filters if the results are truncated.',
  'acquisitions',
  'SELECT i.vendor_invoice_no AS "Invoice Number",
       vendor.name AS "Vendor Name",
       vendor.code AS "Vendor Code",
       i.status AS "Invoice Status",
       i.payment_method AS "Payment Method",
       i.invoice_date AS "Invoice Date",
       i.approval_date AS "Approval Date",
       i.payment_date AS "Payment Date",
       fiscal_year.code AS "Fiscal Year",
       i.total AS "Total Amount",
       i.currency AS "Currency",
       i.lock_total AS "Lock Total",
       CASE WHEN i.lock_total IS NULL THEN ''''
            WHEN i.lock_total IS DISTINCT FROM i.total THEN ''Mismatch''
            ELSE ''''
       END AS "Lock Total Mismatch",
       CAST(i.id AS text) AS "Invoice UUID"
FROM invoice.invoices__t AS i
LEFT JOIN organizations.organizations__t AS vendor ON vendor.id = i.vendor_id
LEFT JOIN finance.fiscal_year__t AS fiscal_year ON fiscal_year.id = i.fiscal_year_id
WHERE i.invoice_date >= CAST(:startDate AS date)
  AND i.invoice_date < CAST(:endDate AS date) + INTERVAL ''1 day''
  AND i.status IN (:statuses)
  AND EXISTS (
    SELECT 1
    FROM invoice.invoices__t__acq_unit_ids AS unit_link
    JOIN orders.acquisitions_unit__t AS acquisition_unit
      ON acquisition_unit.id = unit_link.acq_unit_ids
    WHERE unit_link.id = i.id
      AND acquisition_unit.name = :acqUnit
  )
  AND :vendorFilter
ORDER BY i.invoice_date DESC NULLS LAST,
         i.vendor_invoice_no ASC NULLS LAST,
         i.id
LIMIT 100001',
  '[{"name":"acqUnit","type":"select","label":"Acquisitions Unit","required":true,"default":"SC","description":"Choose the FOLIO acquisitions unit. Invoice scope follows the invoice acquisitions-unit link.","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''AC'', ''AC''), (''HC'', ''HC''), (''MH'', ''MH''), (''SC'', ''SC''), (''UM'', ''UM'')) AS choices(value, label)"},{"name":"startDate","type":"date","label":"Start Date","required":true,"default":"$fiscal_year_start","description":"Inclusive start of the invoice-date range."},{"name":"endDate","type":"date","label":"End Date","required":true,"default":"$today","description":"Inclusive end of the invoice-date range."},{"name":"statuses","type":"multiselect","label":"Invoice Status","required":true,"default":"Open,Reviewed,Approved,Paid","max_selections":5,"description":"Select one or more FOLIO invoice statuses. The default excludes Cancelled.","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''Open'', ''Open''), (''Reviewed'', ''Reviewed''), (''Approved'', ''Approved''), (''Paid'', ''Paid''), (''Cancelled'', ''Cancelled'')) AS choices(value, label)"},{"name":"vendorIds","type":"multiselect","label":"Vendor","required":false,"default":"","placeholder":"Search vendors","max_selections":1000,"description":"Every vendor with an SC invoice is listed. Leave empty to include all vendors, including invoices without a matching vendor record.","options_db":"folio","options_sql":"SELECT DISTINCT vendor.id::text AS value, COALESCE(vendor.name, '''') || CASE WHEN vendor.code IS NULL THEN '''' ELSE '' ('' || vendor.code || '')'' END AS label FROM invoice.invoices__t AS i JOIN invoice.invoices__t__acq_unit_ids AS unit_link ON unit_link.id = i.id JOIN orders.acquisitions_unit__t AS acquisition_unit ON acquisition_unit.id = unit_link.acq_unit_ids JOIN organizations.organizations__t AS vendor ON vendor.id = i.vendor_id WHERE acquisition_unit.name = ''SC'' ORDER BY label, value"}]',
  'folio',
  '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"Invoice UUID","header":"UUID"}}',
  100000,
  1,
  'manual'
)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `help_text` = VALUES(`help_text`),
  `category` = VALUES(`category`), `sql_template` = VALUES(`sql_template`), `parameters` = VALUES(`parameters`),
  `data_source` = VALUES(`data_source`), `execution_config` = VALUES(`execution_config`),
  `default_limit` = VALUES(`default_limit`), `is_active` = VALUES(`is_active`), `created_by` = VALUES(`created_by`);

COMMIT;
