-- Migration 027: Add NL2SQL guidance for efficient same-title holdings overlap reports.

UPDATE ai_training_hints
SET hint_value = 'CRITICAL PERFORMANCE RULE for reports that ask for holdings at one Smith location and other Five Colleges institutions with the same title. First build a small target_rare_titles AS MATERIALIZED CTE scoped to the requested location/campus and compute LOWER(inst.title) AS title_key. Then join other institutions only through those target title keys. Do NOT materialize all non-Smith holdings first. Do NOT use OR in the title join. Do NOT use a correlated IN subquery against inventory.instance__t. Use equality on a normalized title key, for example other_inst.title_key = target_rare_titles.title_key, and keep the final join LEFT JOIN when every Smith holding should appear even if no other institution has attached holdings.',
    is_active = 1,
    updated_at = NOW()
WHERE type = 'vocabulary'
  AND hint_key = 'same-title holdings overlap';

INSERT INTO ai_training_hints
    (type, hint_key, hint_value, example_question, example_sql, original_sql, notes, is_active, user_id, created_at, updated_at)
SELECT
    'vocabulary',
    'same-title holdings overlap',
    'CRITICAL PERFORMANCE RULE for reports that ask for holdings at one Smith location and other Five Colleges institutions with the same title. First build a small target_rare_titles AS MATERIALIZED CTE scoped to the requested location/campus and compute LOWER(inst.title) AS title_key. Then join other institutions only through those target title keys. Do NOT materialize all non-Smith holdings first. Do NOT use OR in the title join. Do NOT use a correlated IN subquery against inventory.instance__t. Use equality on a normalized title key, for example other_inst.title_key = target_rare_titles.title_key, and keep the final join LEFT JOIN when every Smith holding should appear even if no other institution has attached holdings.',
    NULL,
    NULL,
    NULL,
    NULL,
    1,
    NULL,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_training_hints
    WHERE type = 'vocabulary'
      AND hint_key = 'same-title holdings overlap'
);

UPDATE ai_training_hints
SET example_sql = 'WITH target_rare_titles AS MATERIALIZED (\n  SELECT\n    hr.id AS sc_holdings_id,\n    hr.instance_id AS sc_instance_id,\n    inst.title AS sc_title,\n    LOWER(inst.title) AS title_key\n  FROM inventory.holdings_record__t hr\n  JOIN inventory.instance__t inst ON hr.instance_id = inst.id\n  JOIN inventory.location__t loc ON hr.effective_location_id = loc.id\n  JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id\n  JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id\n  WHERE loc.name ILIKE \'SC Rare Book Collection Reference\'\n    AND camp.code = \'SC\'\n),\nother_inst AS MATERIALIZED (\n  SELECT DISTINCT\n    LOWER(inst.title) AS title_key,\n    hr.id AS other_holdings_id,\n    hr.instance_id AS other_instance_id,\n    inst.title AS other_title,\n    camp.name AS campus_name,\n    lib.name AS library_name,\n    loc.name AS location_name\n  FROM inventory.instance__t inst\n  JOIN target_rare_titles ON LOWER(inst.title) = target_rare_titles.title_key\n  JOIN inventory.holdings_record__t hr ON hr.instance_id = inst.id\n  JOIN inventory.location__t loc ON hr.effective_location_id = loc.id\n  JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id\n  JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id\n  WHERE camp.code <> \'SC\'\n)\nSELECT\n  target_rare_titles.sc_holdings_id,\n  target_rare_titles.sc_instance_id,\n  target_rare_titles.sc_title,\n  other_inst.other_holdings_id,\n  other_inst.other_instance_id,\n  other_inst.other_title,\n  other_inst.campus_name,\n  other_inst.library_name,\n  other_inst.location_name\nFROM target_rare_titles\nLEFT JOIN other_inst ON other_inst.title_key = target_rare_titles.title_key\nLIMIT 100;',
    notes = 'For same-title overlap reports, scope the target location first and join other institutions through normalized title keys. Avoid broad non-Smith materialized CTEs, OR join predicates, and correlated IN subqueries on instance titles.',
    is_active = 1,
    updated_at = NOW()
WHERE type IN ('example', 'correction')
  AND example_question = 'I am looking for a report of all holdings with the location SC Rare Book Collection Reference. For each book, show which other institutions in the 5 Colleges also hold the same title.';

INSERT INTO ai_training_hints
    (type, hint_key, hint_value, example_question, example_sql, original_sql, notes, is_active, user_id, created_at, updated_at)
SELECT
    'correction',
    NULL,
    NULL,
    'I am looking for a report of all holdings with the location SC Rare Book Collection Reference. For each book, show which other institutions in the 5 Colleges also hold the same title.',
    'WITH target_rare_titles AS MATERIALIZED (\n  SELECT\n    hr.id AS sc_holdings_id,\n    hr.instance_id AS sc_instance_id,\n    inst.title AS sc_title,\n    LOWER(inst.title) AS title_key\n  FROM inventory.holdings_record__t hr\n  JOIN inventory.instance__t inst ON hr.instance_id = inst.id\n  JOIN inventory.location__t loc ON hr.effective_location_id = loc.id\n  JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id\n  JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id\n  WHERE loc.name ILIKE \'SC Rare Book Collection Reference\'\n    AND camp.code = \'SC\'\n),\nother_inst AS MATERIALIZED (\n  SELECT DISTINCT\n    LOWER(inst.title) AS title_key,\n    hr.id AS other_holdings_id,\n    hr.instance_id AS other_instance_id,\n    inst.title AS other_title,\n    camp.name AS campus_name,\n    lib.name AS library_name,\n    loc.name AS location_name\n  FROM inventory.instance__t inst\n  JOIN target_rare_titles ON LOWER(inst.title) = target_rare_titles.title_key\n  JOIN inventory.holdings_record__t hr ON hr.instance_id = inst.id\n  JOIN inventory.location__t loc ON hr.effective_location_id = loc.id\n  JOIN inventory.loclibrary__t lib ON loc.library_id = lib.id\n  JOIN inventory.loccampus__t camp ON lib.campus_id = camp.id\n  WHERE camp.code <> \'SC\'\n)\nSELECT\n  target_rare_titles.sc_holdings_id,\n  target_rare_titles.sc_instance_id,\n  target_rare_titles.sc_title,\n  other_inst.other_holdings_id,\n  other_inst.other_instance_id,\n  other_inst.other_title,\n  other_inst.campus_name,\n  other_inst.library_name,\n  other_inst.location_name\nFROM target_rare_titles\nLEFT JOIN other_inst ON other_inst.title_key = target_rare_titles.title_key\nLIMIT 100;',
    NULL,
    'For same-title overlap reports, scope the target location first and join other institutions through normalized title keys. Avoid broad non-Smith materialized CTEs, OR join predicates, and correlated IN subqueries on instance titles.',
    1,
    NULL,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_training_hints
    WHERE type IN ('example', 'correction')
      AND example_question = 'I am looking for a report of all holdings with the location SC Rare Book Collection Reference. For each book, show which other institutions in the 5 Colleges also hold the same title.'
);