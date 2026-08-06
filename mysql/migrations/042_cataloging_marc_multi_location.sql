START TRANSACTION;

UPDATE `report_templates`
SET
  `description` = 'Finds MARC-sourced bibliographic instances in one or more selected locations that do not contain a selected three-digit MARC tag.',
  `help_text` = 'This report finds MARC-sourced bibliographic instances associated with one or more selected locations that do not contain the selected three-digit MARC tag. A missing tag is a factual finding, not automatically a MARC, RDA, or local-policy error; interpret the worklist in the context of record type, cataloging rules, legacy practice, and local policy.\n\nSearch locations by campus, library, location name, or location code, then select up to 100 locations. If an instance matches several selected locations, the worklist combines those locations and returns the instance once.\n\nLocation basis controls the collection scope: Effective item uses item effective location; Permanent item uses item permanent location; Permanent holdings uses holdings permanent location and can include holdings without items.\n\nWorkflow: run the report, review the six-column worklist, and download it for assignment or annotations. Use Export FOLIO UUID list to create a one-column Instance UUID file, upload that file to the institution''s approved FOLIO Data Export job profile, export the underlying MARC records, review or edit them in MarcEdit, and reimport corrections only through the institution''s approved FOLIO Data Import profile.\n\nThe report publishes at most 100,000 records. If a truncation warning appears, narrow the selected locations before treating the exported list as complete.',
  `sql_template` = 'WITH target_instances AS MATERIALIZED (
    SELECT
        instance.id AS instance_uuid,
        instance.hrid AS instance_hrid,
        instance.title,
        STRING_AGG(
            DISTINCT location.name || COALESCE('' ['' || location.code || '']'', ''''),
            ''; '' ORDER BY location.name || COALESCE('' ['' || location.code || '']'', '''')
        ) AS selected_locations
    {{location_from}}
    WHERE location.id = ANY(string_to_array(:locationIds, '','')::uuid[])
      AND instance.source = ''MARC''
    GROUP BY instance.id, instance.hrid, instance.title
)
SELECT
    target_instances.instance_uuid AS "Instance UUID",
    target_instances.instance_hrid AS "Instance HRID",
    target_instances.title AS "Title",
    target_instances.selected_locations AS "Selected Locations",
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
  `parameters` = '[{"name":"locationIds","type":"multiselect","label":"Locations","required":true,"default":"","placeholder":"Search locations","description":"The active FOLIO locations used to scope candidate bibliographic records.","max_selections":100,"options_db":"folio","options_sql":"SELECT loc.id AS value, COALESCE(campus.name || '' — '', '''') || COALESCE(lib.name || '' — '', '''') || loc.name || COALESCE('' ['' || loc.code || '']'', '''') AS label FROM inventory.location__t loc LEFT JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id LEFT JOIN inventory.loccampus__t campus ON campus.id = lib.campus_id WHERE COALESCE(loc.is_active, true) ORDER BY campus.name, lib.name, loc.name, loc.code"},{"name":"locationBasis","type":"select","label":"Location basis","required":true,"default":"effective_item","placeholder":"Select location basis","description":"Use item effective location, item permanent location, or holdings permanent location.","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''effective_item'', ''Effective item''), (''permanent_item'', ''Permanent item''), (''permanent_holdings'', ''Permanent holdings'')) AS basis(value, label)"},{"name":"marcTag","type":"text","label":"MARC tag","required":true,"default":"","placeholder":"856","description":"Enter exactly three digits from 001 through 999.","input_mode":"numeric","pattern":"[0-9]{3}","max_length":3}]',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `slug` = 'marc-bibliographic-records-missing-tag';

COMMIT;
