START TRANSACTION;

INSERT INTO `report_templates`
  (`slug`, `name`, `description`, `help_text`, `category`, `sql_template`, `parameters`,
   `data_source`, `execution_config`, `default_limit`, `is_active`, `created_by`)
VALUES (
  'orders-missing-acquisitions-unit',
  'Orders Missing an Acquisitions Unit',
  'Finds purchase orders with no non-null acquisitions unit whose order numbers begin with SC or contain digits only, and shows vendor and fund-code evidence that can help staff identify likely Smith orders.',
  'Finds purchase orders with no non-null acquisitions unit: either no acquisition-unit row or only rows with null unit IDs. Includes all workflow statuses for order numbers with an SC prefix (case-insensitive) or numbers only (digits only). Orders with any non-null acquisition-unit ID and other order-number patterns are excluded.

“Likely Smith” is an evidence flag, not a definitive ownership decision. It says Yes when the vendor code starts with SC or any order line has a fund code starting with SC. A blank value means neither signal matched; the order-number pattern alone does not identify Smith. Staff should review the vendor name, vendor code, fund codes, and status before acting.

Fund codes are distinct across all order lines. Results list likely Smith orders first, then sort by creation date newest first. The PO UUID is included for FOLIO follow-up and in the identifier export.',
  'acquisitions',
  'WITH missing_unit_orders AS (
  SELECT
    po.id,
    po.po_number,
    po.vendor,
    po.workflow_status,
    po.order_type,
    po.date_ordered,
    po.metadata__created_date AS created_date
  FROM orders.purchase_order__t AS po
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
  '[]',
  'folio',
  '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"PO UUID","header":"UUID"}}',
  100000,
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
  `execution_config` = VALUES(`execution_config`),
  `default_limit` = VALUES(`default_limit`),
  `is_active` = VALUES(`is_active`),
  `created_by` = VALUES(`created_by`);

COMMIT;
