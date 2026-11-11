START TRANSACTION;

INSERT INTO `report_templates`
  (`slug`, `name`, `description`, `help_text`, `category`, `sql_template`, `parameters`,
   `data_source`, `execution_config`, `default_limit`, `is_active`, `created_by`, `composite_config`)
VALUES (
  'shared-record-check',
  'Shared Record Check — Cataloging',
  'Shows FOLIO instances by 998 owner or permanent holdings campus, with owner codes, holding colleges, and suppression status for review.',
  'Ownership is read from 998$a. RC counts as UMass, and DP is the Five College Depository. Holding colleges come from every holdings permanent location; RP appears as its own campus code. Shared means two or more owners. Single owner means one owner. No owner code, held at 2+ colleges means no owner code and holdings at two or more campuses. No owner code means no owner code and no holdings or holdings at one campus.

The report includes every instance source. A selected location filter uses permanent locations to decide which instances appear; Holding colleges still lists all permanent holding campuses for each result. Results stop at 100,000 rows. A run that reaches the cap is marked incomplete, so narrow large scopes by share status or selected locations. Export FOLIO UUID list gives the instance UUIDs for FOLIO batch work.',
  'cataloging',
  'WITH report_parameters AS MATERIALIZED (
  SELECT CAST(:scopeKind AS text) AS scope_kind,
         CAST(:scopeCode AS text) AS scope_code,
         CAST(:shareStatus AS text) AS share_status,
         CAST(:suppressionMode AS text) AS suppression_mode,
         CAST(:locationIds AS text) AS location_ids
),
scoped_instances AS MATERIALIZED (
  SELECT DISTINCT instance.id AS instance_uuid, instance.hrid, instance.title, instance.source,
         instance.discovery_suppress, instance.staff_suppress
  FROM inventory.instance__t AS instance
  CROSS JOIN report_parameters AS params
  WHERE (
    (params.scope_kind = ''owner'' AND EXISTS (
      SELECT 1
      FROM marctab.mt998 AS scope_owner
      WHERE scope_owner.instance_id = instance.id
        AND scope_owner.sf = ''a''
        AND (
          (params.scope_code = ''UM'' AND UPPER(BTRIM(scope_owner.content)) IN (''UM'', ''RC''))
          OR (params.scope_code <> ''UM'' AND UPPER(BTRIM(scope_owner.content)) = params.scope_code)
        )
      OFFSET 0
    ))
    OR (params.scope_kind = ''campus'' AND EXISTS (
      SELECT 1
      FROM inventory.holdings_record__t AS scope_holding
      JOIN inventory.location__t AS scope_location ON scope_location.id = scope_holding.permanent_location_id
      JOIN inventory.loclibrary__t AS scope_library ON scope_library.id = scope_location.library_id
      JOIN inventory.loccampus__t AS scope_campus ON scope_campus.id = scope_library.campus_id
      WHERE scope_holding.instance_id = instance.id
        AND scope_campus.code = params.scope_code
      OFFSET 0
    ))
  )
  AND (
    params.location_ids = ''''
    OR EXISTS (
      SELECT 1
      FROM inventory.holdings_record__t AS selected_holding
      WHERE selected_holding.instance_id = instance.id
        AND selected_holding.permanent_location_id = ANY(string_to_array(params.location_ids, '','')::uuid[])
      OFFSET 0
    )
  )
),
owner_counts AS MATERIALIZED (
  SELECT ownership.instance_id,
         COUNT(DISTINCT CASE
           WHEN UPPER(BTRIM(ownership.content)) IN (''SM'', ''AM'', ''MH'', ''UM'', ''HA'', ''YB'', ''DP'', ''RC'')
           THEN CASE WHEN UPPER(BTRIM(ownership.content)) = ''RC'' THEN ''UM'' ELSE UPPER(BTRIM(ownership.content)) END
         END) AS owner_count
  FROM marctab.mt998 AS ownership
  JOIN scoped_instances AS scoped ON scoped.instance_uuid = ownership.instance_id
  WHERE ownership.sf = ''a''
  GROUP BY ownership.instance_id
),
owner_codes AS MATERIALIZED (
  SELECT ownership.instance_id,
         STRING_AGG(DISTINCT UPPER(BTRIM(ownership.content)), ''; '' ORDER BY UPPER(BTRIM(ownership.content))) AS codes
  FROM marctab.mt998 AS ownership
  JOIN scoped_instances AS scoped ON scoped.instance_uuid = ownership.instance_id
  WHERE ownership.sf = ''a''
  GROUP BY ownership.instance_id
),
holding_campuses AS MATERIALIZED (
  SELECT holding.instance_id,
         STRING_AGG(DISTINCT campus.code, ''; '' ORDER BY campus.code) AS campus_codes,
         COUNT(DISTINCT campus.code) AS campus_count
  FROM inventory.holdings_record__t AS holding
  JOIN scoped_instances AS scoped ON scoped.instance_uuid = holding.instance_id
  JOIN inventory.location__t AS location ON location.id = holding.permanent_location_id
  JOIN inventory.loclibrary__t AS library ON library.id = location.library_id
  JOIN inventory.loccampus__t AS campus ON campus.id = library.campus_id
  GROUP BY holding.instance_id
),
classified_instances AS MATERIALIZED (
  SELECT scoped.instance_uuid, scoped.hrid, scoped.title, scoped.source,
         codes.codes, campuses.campus_codes,
         scoped.discovery_suppress, scoped.staff_suppress,
         CASE
           WHEN COALESCE(owners.owner_count, 0) >= 2 THEN ''shared''
           WHEN COALESCE(owners.owner_count, 0) = 1 THEN ''single''
           WHEN COALESCE(campuses.campus_count, 0) >= 2 THEN ''no_owner_multi''
           ELSE ''no_owner''
         END AS share_status_key,
         CASE
           WHEN COALESCE(owners.owner_count, 0) >= 2 THEN ''Shared''
           WHEN COALESCE(owners.owner_count, 0) = 1 THEN ''Single owner''
           WHEN COALESCE(campuses.campus_count, 0) >= 2 THEN ''No owner code, held at 2+ colleges''
           ELSE ''No owner code''
         END AS share_status
  FROM scoped_instances AS scoped
  LEFT JOIN owner_counts AS owners ON owners.instance_id = scoped.instance_uuid
  LEFT JOIN owner_codes AS codes ON codes.instance_id = scoped.instance_uuid
  LEFT JOIN holding_campuses AS campuses ON campuses.instance_id = scoped.instance_uuid
)
SELECT classified.instance_uuid AS "Instance UUID",
       classified.hrid AS "HRID",
       classified.title AS "Title",
       classified.source AS "Source",
       classified.codes AS "998 codes",
       classified.campus_codes AS "Holding colleges",
       classified.share_status AS "Share status",
       CASE WHEN classified.discovery_suppress IS TRUE THEN ''Yes'' ELSE ''No'' END AS "Discovery suppressed",
       CASE WHEN classified.staff_suppress IS TRUE THEN ''Yes'' ELSE ''No'' END AS "Staff suppressed"
FROM classified_instances AS classified
CROSS JOIN report_parameters AS params
WHERE (
  params.suppression_mode = ''all''
  OR (params.suppression_mode = ''exclude_discovery'' AND classified.discovery_suppress IS NOT TRUE)
  OR (params.suppression_mode = ''exclude_staff'' AND classified.staff_suppress IS NOT TRUE)
  OR (params.suppression_mode = ''exclude_both'' AND classified.discovery_suppress IS NOT TRUE AND classified.staff_suppress IS NOT TRUE)
)
AND (params.share_status = ''all'' OR classified.share_status_key = params.share_status)
ORDER BY "Title" NULLS LAST, "HRID" NULLS LAST, "Instance UUID" NULLS LAST
LIMIT 100001',
  '[{"name":"scope","type":"select","label":"Scope","required":true,"default":"owner:SM","description":"Choose an owner from 998$a or a campus with holdings.","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (1, ''owner:SM'', ''998 owner: Smith (SM)''), (2, ''owner:AM'', ''998 owner: Amherst (AM)''), (3, ''owner:MH'', ''998 owner: Mount Holyoke (MH)''), (4, ''owner:UM'', ''998 owner: UMass (UM or RC)''), (5, ''owner:HA'', ''998 owner: Hampshire (HA)''), (6, ''owner:YB'', ''998 owner: Yiddish Book Center (YB)''), (7, ''owner:DP'', ''998 owner: Five College Depository (DP)''), (8, ''campus:SC'', ''Held at: Smith College''), (9, ''campus:AC'', ''Held at: Amherst College''), (10, ''campus:MH'', ''Held at: Mount Holyoke College''), (11, ''campus:UM'', ''Held at: UMass''), (12, ''campus:HC'', ''Held at: Hampshire College''), (13, ''campus:RP'', ''Held at: Five Colleges Collections (RP)''), (14, ''campus:YB'', ''Held at: Yiddish Book Center'')) AS choices(sort_order, value, label) ORDER BY sort_order"},{"name":"shareStatus","type":"select","label":"Share status","required":true,"default":"all","options":[{"value":"all","label":"All statuses"},{"value":"shared","label":"Shared"},{"value":"single","label":"Single owner"},{"value":"no_owner_multi","label":"No owner code, held at 2+ colleges"},{"value":"no_owner","label":"No owner code"}]},{"name":"suppressionMode","type":"select","label":"Suppression","required":true,"default":"all","description":"Choose which suppression flags to exclude. Unset flags count as not suppressed.","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (1, ''all'', ''Include all instances''), (2, ''exclude_discovery'', ''Exclude discovery-suppressed instances''), (3, ''exclude_staff'', ''Exclude staff-suppressed instances''), (4, ''exclude_both'', ''Exclude both discovery and staff suppression'')) AS choices(sort_order, value, label) ORDER BY sort_order"},{"name":"locationIds","type":"multiselect","label":"Locations","required":false,"default":"","placeholder":"Search locations","description":"Only records with a holdings record at one of these permanent locations. Leave empty for no location filter.","max_selections":100,"groupSelect":true,"options_db":"folio","options_sql":"SELECT loc.id AS value, COALESCE(campus.name || '' — '', '''') || COALESCE(lib.name || '' — '', '''') || loc.name || COALESCE('' ['' || loc.code || '']'', '''') AS label FROM inventory.location__t loc LEFT JOIN inventory.loclibrary__t lib ON lib.id = loc.library_id LEFT JOIN inventory.loccampus__t campus ON campus.id = lib.campus_id WHERE COALESCE(loc.is_active, true) ORDER BY campus.name, lib.name, loc.name, loc.code"}]',
  'folio',
  '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"Instance UUID","header":"UUID"}}',
  100000,
  1,
  'manual',
  NULL
)
ON DUPLICATE KEY UPDATE
  `slug` = VALUES(`slug`),
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
  `created_by` = VALUES(`created_by`),
  `composite_config` = NULL;

COMMIT;
