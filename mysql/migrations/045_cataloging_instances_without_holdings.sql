START TRANSACTION;

INSERT INTO `report_templates`
  (`slug`, `name`, `description`, `help_text`, `category`, `sql_template`, `parameters`,
   `data_source`, `execution_config`, `default_limit`, `is_active`, `created_by`)
VALUES (
  'instances-without-holdings',
  'Instances Without Holdings — Cataloging Review',
  'Review instances with no holdings across the consortium, with ownership, suppression, and optional MARC marker filters.',
  'Finds instances with zero holdings anywhere in the consortium. A holdings record at any college excludes the instance, even if that holding is suppressed or has no items. This is a cataloging review worklist; a result is not automatically an error.

Ownership is independent of holdings: institution choices match exact 998$a bibliographic ownership markers. Smith uses SM and Amherst uses AM, not the campus location codes SC and AC. Shared records may carry several ownership markers. All colleges includes instances with absent, malformed, or unrecognized ownership markers and all instance sources.

The default includes all colleges, excludes both discovery- and staff-suppressed instances, and excludes no 035 markers. Change Suppression to Include all instances for a full audit. Unset suppression flags are treated as not suppressed and remain blank in the evidence columns.

Optional 035$a exclusions are comma-separated, case-insensitive literal prefixes. A matching prefix on any 035$a excludes the whole instance. The Smith example is (SMHASLKR), (SMISLKR). Selecting these prefixes does not select Smith ownership; choose Smith separately. No wildcard syntax is used.

Review UUID, HRID, title, source, ownership markers, 035$a values, and suppression flags. Download the worklist for reconciliation with staff spreadsheets. Export FOLIO UUID list produces a one-column UUID file for your approved FOLIO workflow. At most 100,000 instances are exported; if truncated, narrow the scope before treating the list as complete.

This report does not infer LKRS status or missing child relationships. LKRS instances with holdings require a separate, verified relationship query.',
  'cataloging',
  'WITH candidates AS MATERIALIZED (
  SELECT inst.id, inst.hrid, inst.title, inst.source,
         inst.discovery_suppress, inst.staff_suppress
  FROM inventory.instance__t AS inst
  WHERE NOT EXISTS (
    SELECT 1 FROM inventory.holdings_record__t AS holdings
    WHERE holdings.instance_id = inst.id
  )
  AND (
    :suppressionMode = ''all''
    OR (:suppressionMode = ''exclude_discovery'' AND inst.discovery_suppress IS NOT TRUE)
    OR (:suppressionMode = ''exclude_staff'' AND inst.staff_suppress IS NOT TRUE)
    OR (:suppressionMode = ''exclude_both'' AND inst.discovery_suppress IS NOT TRUE AND inst.staff_suppress IS NOT TRUE)
  )

),
report_instances AS MATERIALIZED (
  SELECT inst.* FROM candidates AS inst
  WHERE (:ownershipCode = ''all'' OR EXISTS (
    SELECT 1 FROM marctab.mt998 AS ownership
    WHERE ownership.instance_id = inst.id
      AND ownership.sf = ''a''
      AND UPPER(BTRIM(ownership.content)) = :ownershipCode
    OFFSET 0
  ))
  AND (:excluded035Prefixes = '''' OR NOT EXISTS (
    SELECT 1 FROM marctab.mt035 AS marker
    CROSS JOIN unnest(string_to_array(:excluded035Prefixes, '','')) AS excluded(prefix)
    WHERE marker.instance_id = inst.id
      AND marker.sf = ''a''
      AND excluded.prefix <> ''''
      AND STRPOS(LOWER(LTRIM(marker.content)), LOWER(excluded.prefix)) = 1
    OFFSET 0
  ))
  ORDER BY inst.hrid NULLS LAST, inst.id
  LIMIT 100001
)
SELECT
  candidates.id AS "Instance UUID",
  candidates.hrid AS "Instance HRID",
  candidates.title AS "Title",
  candidates.source AS "Source",
  (SELECT STRING_AGG(DISTINCT BTRIM(m.content), ''; '' ORDER BY BTRIM(m.content))
   FROM marctab.mt998 AS m
   WHERE m.instance_id = candidates.id AND m.sf = ''a'') AS "Ownership Markers (998$a)",
  (SELECT STRING_AGG(DISTINCT BTRIM(m.content), ''; '' ORDER BY BTRIM(m.content))
   FROM marctab.mt035 AS m
   WHERE m.instance_id = candidates.id AND m.sf = ''a'') AS "035$a Values",
  candidates.discovery_suppress AS "Discovery Suppressed",
  candidates.staff_suppress AS "Staff Suppressed",
  :ownershipCode AS "Selected Ownership Code",
  :suppressionMode AS "Suppression Filter",
  :excluded035Prefixes AS "Excluded 035$a Prefixes"
FROM report_instances AS candidates
ORDER BY candidates.hrid NULLS LAST, candidates.id
LIMIT 100001',
  '[{"name":"ownershipCode","type":"select","label":"Institution / bibliographic ownership","required":true,"default":"all","description":"Uses exact 998$a ownership codes, not holdings locations. All colleges also includes records with no ownership marker. Codes DP and RC are shown without assigning an institution.","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''all'', ''All colleges — includes missing or unrecognized ownership''), (''SM'', ''Smith College — SM''), (''AM'', ''Amherst College — AM''), (''MH'', ''Mount Holyoke College — MH''), (''UM'', ''University of Massachusetts — UM''), (''HA'', ''Hampshire College — HA''), (''YB'', ''National Yiddish Book Center — YB''), (''DP'', ''Ownership code DP''), (''RC'', ''Ownership code RC'')) AS choices(value, label)"},{"name":"suppressionMode","type":"select","label":"Suppression","required":true,"default":"exclude_both","description":"Choose which suppression flags to exclude. Unset flags count as not suppressed.","options_db":"folio","options_sql":"SELECT value, label FROM (VALUES (''all'', ''Include all instances''), (''exclude_discovery'', ''Exclude discovery-suppressed instances''), (''exclude_staff'', ''Exclude staff-suppressed instances''), (''exclude_both'', ''Exclude both discovery and staff suppression'')) AS choices(value, label)"},{"name":"excluded035Prefixes","type":"text","label":"Exclude 035$a prefixes (optional)","required":false,"default":"","placeholder":"(SMHASLKR), (SMISLKR)","max_length":2000,"description":"Comma-separated literal prefixes, up to 20. Matching ignores capitalization and leading spaces. Leave empty to exclude no markers. Smith prefixes do not limit ownership to Smith."}]',
  'folio',
  '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"Instance UUID","header":"UUID"}}',
  100000,
  1,
  'manual'
)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `help_text` = VALUES(`help_text`), `category` = VALUES(`category`), `sql_template` = VALUES(`sql_template`), `parameters` = VALUES(`parameters`), `data_source` = VALUES(`data_source`), `execution_config` = VALUES(`execution_config`), `default_limit` = VALUES(`default_limit`), `is_active` = VALUES(`is_active`), `created_by` = VALUES(`created_by`);

COMMIT;
