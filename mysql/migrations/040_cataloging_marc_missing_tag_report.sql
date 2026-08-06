SET @marc_missing_tag_report_has_cataloging_category := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'report_templates'
    AND COLUMN_NAME = 'category'
    AND COLUMN_TYPE LIKE '%''cataloging''%'
);

SET @marc_missing_tag_report_category_ddl := IF(
  @marc_missing_tag_report_has_cataloging_category > 0,
  'SELECT 1',
  'ALTER TABLE `report_templates` MODIFY COLUMN `category` ENUM(''acquisitions'', ''circulation'', ''inventory'', ''finance'', ''users'', ''cataloging'', ''other'') DEFAULT ''other'''
);

PREPARE marc_missing_tag_report_category_stmt FROM @marc_missing_tag_report_category_ddl;
EXECUTE marc_missing_tag_report_category_stmt;
DEALLOCATE PREPARE marc_missing_tag_report_category_stmt;

SET @marc_missing_tag_report_has_execution_config := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'report_templates'
    AND COLUMN_NAME = 'execution_config'
);

SET @marc_missing_tag_report_execution_config_ddl := IF(
  @marc_missing_tag_report_has_execution_config > 0,
  'SELECT 1',
  'ALTER TABLE `report_templates` ADD COLUMN execution_config JSON NULL AFTER `composite_config`'
);

PREPARE marc_missing_tag_report_execution_config_stmt FROM @marc_missing_tag_report_execution_config_ddl;
EXECUTE marc_missing_tag_report_execution_config_stmt;
DEALLOCATE PREPARE marc_missing_tag_report_execution_config_stmt;

START TRANSACTION;

INSERT INTO `report_templates`
  (`slug`, `name`, `description`, `help_text`, `category`, `sql_template`, `parameters`,
   `data_source`, `execution_config`, `default_limit`, `is_active`, `created_by`)
VALUES (
  'marc-bibliographic-records-missing-tag',
  'MARC Bibliographic Records Missing a Tag',
  'Finds MARC-sourced bibliographic instances in a selected location that do not contain a selected three-digit MARC tag.',
  'This report finds MARC-sourced bibliographic instances associated with the selected location that do not contain the selected three-digit MARC tag. A missing tag is a factual finding, not automatically a MARC, RDA, or local-policy error; interpret the worklist in the context of record type, cataloging rules, legacy practice, and local policy.\n\nLocation basis controls the collection scope: Effective item uses item effective location; Permanent item uses item permanent location; Permanent holdings uses holdings permanent location and can include holdings without items.\n\nWorkflow: run the report, review the six-column worklist, and download it for assignment or annotations. Use Export FOLIO UUID list to create a one-column Instance UUID file, upload that file to the institution''s approved FOLIO Data Export job profile, export the underlying MARC records, review or edit them in MarcEdit, and reimport corrections only through the institution''s approved FOLIO Data Import profile.\n\nThe report publishes at most 100,000 records. If a truncation warning appears, narrow the location before treating the exported list as complete.',
  'cataloging',
  'WITH target_instances AS MATERIALIZED (
    SELECT DISTINCT
        instance.id AS instance_uuid,
        instance.hrid AS instance_hrid,
        instance.title,
        location.name AS selected_location
    {{location_from}}
    WHERE location.id = :locationId
      AND instance.source = ''MARC''
)
SELECT
    target_instances.instance_uuid AS "Instance UUID",
    target_instances.instance_hrid AS "Instance HRID",
    target_instances.title AS "Title",
    target_instances.selected_location AS "Selected Location",
    CASE :locationBasis
        WHEN ''effective_item'' THEN ''Effective item''
        WHEN ''permanent_item'' THEN ''Permanent item''
        WHEN ''permanent_holdings'' THEN ''Permanent holdings''
    END AS "Location Basis",
    :marcTag AS "Missing MARC Tag"
FROM target_instances
WHERE NOT EXISTS (
    SELECT 1
    FROM {{marc_table}} AS marc_tag
    WHERE marc_tag.instance_id = target_instances.instance_uuid
)
ORDER BY target_instances.title NULLS LAST,
         target_instances.instance_hrid NULLS LAST,
         target_instances.instance_uuid
LIMIT 100001',
  '[{"name":"locationId","type":"select","label":"Location","required":true,"default":"","placeholder":"Select location","description":"The FOLIO location used to scope candidate bibliographic records.","options_db":"folio","options_sql":"SELECT loc.id AS value, COALESCE(campus.name || '' — '', '''') || COALESCE(lib.name || '' — '', '''') || loc.name || COALESCE('' ['' || loc.code || '']'', '''') AS label FROM inventory.location__t loc LEFT JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id LEFT JOIN inventory.loccampus__t campus ON campus.id = loc.campus_id WHERE COALESCE(loc.is_active, true) ORDER BY campus.name, lib.name, loc.name, loc.code"},{"name":"locationBasis","type":"select","label":"Location basis","required":true,"default":"effective_item","placeholder":"Select location basis","description":"Use item effective location, item permanent location, or holdings permanent location.","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''effective_item'', ''Effective item''), (''permanent_item'', ''Permanent item''), (''permanent_holdings'', ''Permanent holdings'')) AS basis(value, label)"},{"name":"marcTag","type":"text","label":"MARC tag","required":true,"default":"","placeholder":"856","description":"Enter exactly three digits from 001 through 999.","input_mode":"numeric","pattern":"[0-9]{3}","max_length":3}]',
  'folio',
  '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"Instance UUID","header":"UUID"}}',
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
