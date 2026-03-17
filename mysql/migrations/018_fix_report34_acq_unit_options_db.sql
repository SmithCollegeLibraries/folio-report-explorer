-- Migration 018: Fix acqUnitId dropdown on report 34 (Expense Class Allocation vs. Actual).
-- The acqUnitId parameter queries a FOLIO Postgres table (orders.acquisitions_unit__t)
-- but was missing "options_db":"folio", causing the composite report to erroneously
-- run the options_sql against local MySQL (where the table doesn't exist) and
-- silently return an empty options array, leaving the dropdown blank.

UPDATE `report_templates` SET
  `parameters` = '[{"name":"acqUnitId","type":"select","label":"Acquisitions Unit","default":"","required":true,"description":"Filter by acquisitions unit (institution)","options_sql":"SELECT id AS value, name AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY name","options_db":"folio","placeholder":"Select acquisitions unit"},{"name":"startDate","type":"date","label":"Start Date","default":"$fiscal_year_start","required":true,"description":"Beginning of fiscal period","placeholder":"YYYY-MM-DD"},{"name":"endDate","type":"date","label":"End Date","default":"$fiscal_year_end","required":true,"description":"End of fiscal period","placeholder":"YYYY-MM-DD"},{"name":"fiscalYear","type":"number","label":"Fiscal Year","default":"$current_year","required":true,"description":"Fiscal year for allocation lookup (e.g. 2026)","placeholder":"2026"}]'
WHERE `id` = 34;
