START TRANSACTION;

INSERT INTO `report_templates`
  (`slug`, `name`, `description`, `help_text`, `category`, `sql_template`, `parameters`,
   `data_source`, `execution_config`, `default_limit`, `is_active`, `created_by`)
VALUES (
  'marc-field-indicator-content-finder',
  'MARC Field, Indicator, and Content Finder',
  'Finds MARC field occurrences or bibliographic instances missing a matching MARC field occurrence in selected locations.',
  'This report identifies factual MARC field, indicator, subfield, and content findings; a result is not by itself a MARC, RDA, or local-policy judgment. Review each finding in the context of record type, cataloging rules, legacy practice, and local policy.\n\nSearch active locations by campus, library, location name, or location code, select up to 100 locations, and choose Effective item or Permanent item as the location basis. The worklist presents matching subfield rows, or one missing matching occurrence finding per instance. Download the worklist for assignment or annotations. Use Export FOLIO UUID list to create a one-column Instance UUID file, upload it to the institution''s approved FOLIO Data Export job profile, export the underlying MARC records, review or edit them in MarcEdit, and reimport corrections only through the institution''s approved FOLIO Data Import profile.\n\nThe 100,000-row cap counts matching subfield rows, not deduplicated instances. A truncated worklist can therefore correspond to a much smaller deduplicated UUID export file; narrow the search before treating any export as complete.',
  'cataloging',
  'WITH target_instances AS MATERIALIZED (
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
),
matching_rows AS MATERIALIZED (
  SELECT
    target_instances.instance_uuid,
    target_instances.instance_hrid,
    target_instances.title,
    target_instances.selected_locations,
    CASE
      WHEN TRIM(COALESCE(marc_row.ind1, '''')) = '''' OR marc_row.ind1 = CHR(92) THEN ''#''
      ELSE marc_row.ind1
    END AS first_indicator,
    CASE
      WHEN TRIM(COALESCE(marc_row.ind2, '''')) = '''' OR marc_row.ind2 = CHR(92) THEN ''#''
      ELSE marc_row.ind2
    END AS second_indicator,
    marc_row.ord AS field_occurrence, /* marc_row.ord AS "Field Occurrence" */
    marc_row.sf AS subfield,
    marc_row.content
  FROM target_instances
  JOIN {{marc_table}} marc_row
    ON marc_row.instance_id = target_instances.instance_uuid
  WHERE (
    :firstIndicator = ''any''
    OR (:firstIndicator = ''blank'' AND (
      TRIM(COALESCE(marc_row.ind1, '''')) = '''' OR marc_row.ind1 = CHR(92)
    ))
    OR (LEFT(:firstIndicator, 5) = ''char:'' AND marc_row.ind1 = SUBSTRING(:firstIndicator FROM 6))
  )
  AND (
    :secondIndicator = ''any''
    OR (:secondIndicator = ''blank'' AND (
      TRIM(COALESCE(marc_row.ind2, '''')) = '''' OR marc_row.ind2 = CHR(92)
    ))
    OR (LEFT(:secondIndicator, 5) = ''char:'' AND marc_row.ind2 = SUBSTRING(:secondIndicator FROM 6))
  )
  AND (:subfieldCode = '''' OR COALESCE(marc_row.sf, '''') = :subfieldCode)
  AND CASE :contentRule
    WHEN ''any'' THEN TRUE
    WHEN ''contains'' THEN STRPOS(CASE WHEN :caseExact = ''true'' THEN COALESCE(marc_row.content, '''') ELSE LOWER(COALESCE(marc_row.content, '''')) END, CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END) > 0
    WHEN ''not_contains'' THEN STRPOS(CASE WHEN :caseExact = ''true'' THEN COALESCE(marc_row.content, '''') ELSE LOWER(COALESCE(marc_row.content, '''')) END, CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END) = 0
    WHEN ''equals'' THEN CASE WHEN :caseExact = ''true'' THEN COALESCE(marc_row.content, '''') ELSE LOWER(COALESCE(marc_row.content, '''')) END = CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END
    WHEN ''not_equals'' THEN CASE WHEN :caseExact = ''true'' THEN COALESCE(marc_row.content, '''') ELSE LOWER(COALESCE(marc_row.content, '''')) END <> CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END
    WHEN ''begins'' THEN LEFT(CASE WHEN :caseExact = ''true'' THEN COALESCE(marc_row.content, '''') ELSE LOWER(COALESCE(marc_row.content, '''')) END, CHAR_LENGTH(:searchValue)) = CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END
    WHEN ''not_begins'' THEN LEFT(CASE WHEN :caseExact = ''true'' THEN COALESCE(marc_row.content, '''') ELSE LOWER(COALESCE(marc_row.content, '''')) END, CHAR_LENGTH(:searchValue)) <> CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END
    WHEN ''blank'' THEN TRIM(COALESCE(marc_row.content, '''')) = ''''
    WHEN ''not_blank'' THEN TRIM(COALESCE(marc_row.content, '''')) <> ''''
    WHEN ''has_lowercase'' THEN COALESCE(marc_row.content, '''') ~ ''[a-z]''
    WHEN ''has_non_alphanumeric'' THEN COALESCE(marc_row.content, '''') ~ ''[^A-Za-z0-9]''
    ELSE FALSE
  END
),
report_rows AS (
  SELECT
    matching_rows.instance_uuid AS "Instance UUID",
    matching_rows.instance_hrid AS "Instance HRID",
    matching_rows.title AS "Title",
    matching_rows.selected_locations AS "Selected Location(s)",
    CASE :locationBasis WHEN ''effective_item'' THEN ''Effective item'' WHEN ''permanent_item'' THEN ''Permanent item'' END AS "Location Basis",
    :marcTag AS "MARC Tag",
    matching_rows.first_indicator AS "First Indicator",
    matching_rows.second_indicator AS "Second Indicator",
    matching_rows.field_occurrence AS "Field Occurrence",
    matching_rows.subfield AS "Subfield",
    matching_rows.content AS "Content",
    CASE :contentRule
      WHEN ''any'' THEN ''Present matching occurrence'' WHEN ''contains'' THEN ''Content contains search text'' WHEN ''not_contains'' THEN ''Content does not contain search text'' WHEN ''equals'' THEN ''Content equals search text'' WHEN ''not_equals'' THEN ''Content does not equal search text'' WHEN ''begins'' THEN ''Content begins with search text'' WHEN ''not_begins'' THEN ''Content does not begin with search text'' WHEN ''blank'' THEN ''Content is blank'' WHEN ''not_blank'' THEN ''Content is not blank'' WHEN ''has_lowercase'' THEN ''Content contains lowercase characters'' WHEN ''has_non_alphanumeric'' THEN ''Content contains non-alphanumeric characters''
    END AS "Finding"
  FROM matching_rows
  WHERE :occurrenceCondition = ''has''
  UNION ALL
  SELECT target_instances.instance_uuid, target_instances.instance_hrid, target_instances.title, target_instances.selected_locations,
    CASE :locationBasis WHEN ''effective_item'' THEN ''Effective item'' WHEN ''permanent_item'' THEN ''Permanent item'' END, :marcTag,
    NULL::text, NULL::text, NULL::integer, NULL::text, NULL::text, ''Missing matching occurrence''
  FROM target_instances
  WHERE :occurrenceCondition = ''missing''
    AND NOT EXISTS (
      SELECT 1
      FROM {{marc_table}} missing_row
      WHERE missing_row.instance_id = target_instances.instance_uuid
        AND (:firstIndicator = ''any'' OR (:firstIndicator = ''blank'' AND (TRIM(COALESCE(missing_row.ind1, '''')) = '''' OR missing_row.ind1 = CHR(92))) OR (LEFT(:firstIndicator, 5) = ''char:'' AND missing_row.ind1 = SUBSTRING(:firstIndicator FROM 6)))
        AND (:secondIndicator = ''any'' OR (:secondIndicator = ''blank'' AND (TRIM(COALESCE(missing_row.ind2, '''')) = '''' OR missing_row.ind2 = CHR(92))) OR (LEFT(:secondIndicator, 5) = ''char:'' AND missing_row.ind2 = SUBSTRING(:secondIndicator FROM 6)))
        AND (:subfieldCode = '''' OR COALESCE(missing_row.sf, '''') = :subfieldCode)
        AND CASE :contentRule
          WHEN ''any'' THEN TRUE
          WHEN ''contains'' THEN STRPOS(CASE WHEN :caseExact = ''true'' THEN COALESCE(missing_row.content, '''') ELSE LOWER(COALESCE(missing_row.content, '''')) END, CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END) > 0
          WHEN ''not_contains'' THEN STRPOS(CASE WHEN :caseExact = ''true'' THEN COALESCE(missing_row.content, '''') ELSE LOWER(COALESCE(missing_row.content, '''')) END, CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END) = 0
          WHEN ''equals'' THEN CASE WHEN :caseExact = ''true'' THEN COALESCE(missing_row.content, '''') ELSE LOWER(COALESCE(missing_row.content, '''')) END = CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END
          WHEN ''not_equals'' THEN CASE WHEN :caseExact = ''true'' THEN COALESCE(missing_row.content, '''') ELSE LOWER(COALESCE(missing_row.content, '''')) END <> CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END
          WHEN ''begins'' THEN LEFT(CASE WHEN :caseExact = ''true'' THEN COALESCE(missing_row.content, '''') ELSE LOWER(COALESCE(missing_row.content, '''')) END, CHAR_LENGTH(:searchValue)) = CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END
          WHEN ''not_begins'' THEN LEFT(CASE WHEN :caseExact = ''true'' THEN COALESCE(missing_row.content, '''') ELSE LOWER(COALESCE(missing_row.content, '''')) END, CHAR_LENGTH(:searchValue)) <> CASE WHEN :caseExact = ''true'' THEN :searchValue ELSE LOWER(:searchValue) END
          WHEN ''blank'' THEN TRIM(COALESCE(missing_row.content, '''')) = ''''
          WHEN ''not_blank'' THEN TRIM(COALESCE(missing_row.content, '''')) <> ''''
          WHEN ''has_lowercase'' THEN COALESCE(missing_row.content, '''') ~ ''[a-z]''
          WHEN ''has_non_alphanumeric'' THEN COALESCE(missing_row.content, '''') ~ ''[^A-Za-z0-9]''
          ELSE FALSE
        END
    )
)
SELECT * FROM report_rows
ORDER BY "Title" NULLS LAST, "Instance HRID" NULLS LAST, "Instance UUID", "Field Occurrence" NULLS LAST, "Subfield" NULLS LAST, "Content" NULLS LAST
LIMIT 100001',
  '[{"name":"locationIds","type":"multiselect","label":"Locations","required":true,"default":"","placeholder":"Search locations","description":"The active FOLIO locations used to scope candidate bibliographic records.","max_selections":100,"options_db":"folio","options_sql":"SELECT loc.id AS value, COALESCE(campus.name || '' — '', '''') || COALESCE(lib.name || '' — '', '''') || loc.name || COALESCE('' ['' || loc.code || '']'', '''') AS label FROM inventory.location__t loc LEFT JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id LEFT JOIN inventory.loccampus__t campus ON campus.id = lib.campus_id WHERE COALESCE(loc.is_active, true) ORDER BY campus.name, lib.name, loc.name, loc.code"},{"name":"locationBasis","type":"select","label":"Location basis","required":true,"default":"effective_item","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''effective_item'', ''Effective item''), (''permanent_item'', ''Permanent item'')) AS basis(value, label)"},{"name":"marcTag","type":"text","label":"MARC tag","required":true,"default":"","placeholder":"856","input_mode":"numeric","pattern":"[0-9]{3}","max_length":3},{"name":"occurrenceCondition","type":"select","label":"Occurrence condition","required":true,"default":"has","options":[{"value":"has","label":"Has matching occurrence"},{"value":"missing","label":"Missing matching occurrence"}]},{"name":"firstIndicator","type":"select","label":"First indicator","required":true,"default":"any","options":[{"value":"any","label":"Any"},{"value":"blank","label":"Blank (#)"},{"value":"char:0","label":"0"},{"value":"char:1","label":"1"},{"value":"char:2","label":"2"},{"value":"char:3","label":"3"},{"value":"char:4","label":"4"},{"value":"char:5","label":"5"},{"value":"char:6","label":"6"},{"value":"char:7","label":"7"},{"value":"char:8","label":"8"},{"value":"char:9","label":"9"}]},{"name":"secondIndicator","type":"select","label":"Second indicator","required":true,"default":"any","options":[{"value":"any","label":"Any"},{"value":"blank","label":"Blank (#)"},{"value":"char:0","label":"0"},{"value":"char:1","label":"1"},{"value":"char:2","label":"2"},{"value":"char:3","label":"3"},{"value":"char:4","label":"4"},{"value":"char:5","label":"5"},{"value":"char:6","label":"6"},{"value":"char:7","label":"7"},{"value":"char:8","label":"8"},{"value":"char:9","label":"9"}]},{"name":"subfieldCode","type":"text","label":"Subfield code","required":false,"default":"","placeholder":"a","max_length":1},{"name":"contentRule","type":"select","label":"Content rule","required":true,"default":"any","options":[{"value":"any","label":"Any"},{"value":"contains","label":"Contains"},{"value":"not_contains","label":"Does not contain"},{"value":"equals","label":"Equals"},{"value":"not_equals","label":"Does not equal"},{"value":"begins","label":"Begins with"},{"value":"not_begins","label":"Does not begin with"},{"value":"blank","label":"Blank"},{"value":"not_blank","label":"Not blank"},{"value":"has_lowercase","label":"Has lowercase"},{"value":"has_non_alphanumeric","label":"Has non-alphanumeric"}]},{"name":"searchValue","type":"text","label":"Search text","required":false,"default":""},{"name":"caseExact","type":"select","label":"Case matching","required":true,"default":"false","options":[{"value":"false","label":"Case-insensitive"},{"value":"true","label":"Case-exact"}]}]',
  'folio',
  '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"Instance UUID","header":"UUID"}}',
  100000,
  1,
  'manual'
)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `help_text` = VALUES(`help_text`),
  `category` = VALUES(`category`), `sql_template` = VALUES(`sql_template`), `parameters` = VALUES(`parameters`),
  `data_source` = VALUES(`data_source`), `execution_config` = VALUES(`execution_config`), `default_limit` = VALUES(`default_limit`),
  `is_active` = VALUES(`is_active`), `created_by` = VALUES(`created_by`);

COMMIT;
