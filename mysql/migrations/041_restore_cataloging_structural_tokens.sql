UPDATE `report_templates`
SET `sql_template` = REPLACE(
  REPLACE(`sql_template`, '`location_from`', '{{location_from}}'),
  '`marc_table`',
  '{{marc_table}}'
)
WHERE `slug` = 'marc-bibliographic-records-missing-tag'
  AND (
    `sql_template` LIKE '%`location_from`%'
    OR `sql_template` LIKE '%`marc_table`%'
  );
