-- Migration 030: Add NL2SQL guidance for named collections that are FOLIO locations.

UPDATE ai_training_hints
SET hint_value = 'When a user asks for records, holdings, or items in a named collection, first check Resolved Location References. If the resolved name is inventory.location__t, filter the location alias (loc.name), not loclibrary__t. Many user-facing collection names such as SC Special Collections Browsing and SC Rare Book Collection Reference are inventory.location__t.name values even though users call them collections.',
    is_active = 1,
    updated_at = NOW()
WHERE type = 'vocabulary'
  AND hint_key = 'collection location scope';

INSERT INTO ai_training_hints
    (type, hint_key, hint_value, example_question, example_sql, original_sql, notes, is_active, user_id, created_at, updated_at)
SELECT
    'vocabulary',
    'collection location scope',
    'When a user asks for records, holdings, or items in a named collection, first check Resolved Location References. If the resolved name is inventory.location__t, filter the location alias (loc.name), not loclibrary__t. Many user-facing collection names such as SC Special Collections Browsing and SC Rare Book Collection Reference are inventory.location__t.name values even though users call them collections.',
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
      AND hint_key = 'collection location scope'
);

UPDATE ai_training_hints
SET example_sql = 'WITH collection_records AS MATERIALIZED (\n  SELECT\n    inst.id AS instance_id,\n    inst.hrid,\n    hr.call_number_prefix,\n    hr.call_number,\n    inst.title\n  FROM inventory.holdings_record__t hr\n  JOIN inventory.instance__t inst ON inst.id = hr.instance_id\n  JOIN inventory.location__t loc ON loc.id = hr.effective_location_id\n  WHERE loc.name ILIKE \'SC Special Collections Browsing\'\n)\nSELECT\n  collection_records.hrid,\n  collection_records.call_number_prefix,\n  collection_records.call_number,\n  (\n    SELECT con.contributors__name\n    FROM inventory.instance__t__contributors con\n    WHERE con.id = collection_records.instance_id\n    ORDER BY con.contributors__primary DESC NULLS LAST, con.contributors__o\n    LIMIT 1\n  ) AS author,\n  collection_records.title,\n  (\n    SELECT COUNT(*)\n    FROM inventory.holdings_record__t other_hr\n    WHERE other_hr.instance_id = collection_records.instance_id\n  ) > 1 AS has_multiple_holdings,\n  EXISTS (\n    SELECT 1\n    FROM inventory.instance__t__identifiers source_ident\n    JOIN inventory.identifier_type__t source_type ON source_type.id = source_ident.identifiers__identifier_type_id\n    JOIN inventory.instance__t__identifiers other_ident ON other_ident.identifiers__value = source_ident.identifiers__value\n    JOIN inventory.identifier_type__t other_type ON other_type.id = other_ident.identifiers__identifier_type_id\n    WHERE source_ident.id = collection_records.instance_id\n      AND other_ident.id <> collection_records.instance_id\n      AND source_type.name ILIKE \'%OCLC%\'\n      AND other_type.name ILIKE \'%OCLC%\'\n  ) AS has_same_oclc_on_different_record\nFROM collection_records\nORDER BY collection_records.title\nLIMIT 100;',
    notes = 'Collection names are often inventory.location__t.name values; filter loc.name, not lib.name. The location resolver should emit SC Special Collections Browsing as inventory.location__t.',
    is_active = 1,
    updated_at = NOW()
WHERE type IN ('example', 'correction')
  AND example_question = 'List the records in the SC Special Collections Browsing collection, with their HRID, Call Number Prefix, Call Number, Author, Title, whether there are multiple holdings on the record, and whether the same OCLC number appears on a different record.';

INSERT INTO ai_training_hints
    (type, hint_key, hint_value, example_question, example_sql, original_sql, notes, is_active, user_id, created_at, updated_at)
SELECT
    'correction',
    NULL,
    NULL,
    'List the records in the SC Special Collections Browsing collection, with their HRID, Call Number Prefix, Call Number, Author, Title, whether there are multiple holdings on the record, and whether the same OCLC number appears on a different record.',
    'WITH collection_records AS MATERIALIZED (\n  SELECT\n    inst.id AS instance_id,\n    inst.hrid,\n    hr.call_number_prefix,\n    hr.call_number,\n    inst.title\n  FROM inventory.holdings_record__t hr\n  JOIN inventory.instance__t inst ON inst.id = hr.instance_id\n  JOIN inventory.location__t loc ON loc.id = hr.effective_location_id\n  WHERE loc.name ILIKE \'SC Special Collections Browsing\'\n)\nSELECT\n  collection_records.hrid,\n  collection_records.call_number_prefix,\n  collection_records.call_number,\n  (\n    SELECT con.contributors__name\n    FROM inventory.instance__t__contributors con\n    WHERE con.id = collection_records.instance_id\n    ORDER BY con.contributors__primary DESC NULLS LAST, con.contributors__o\n    LIMIT 1\n  ) AS author,\n  collection_records.title,\n  (\n    SELECT COUNT(*)\n    FROM inventory.holdings_record__t other_hr\n    WHERE other_hr.instance_id = collection_records.instance_id\n  ) > 1 AS has_multiple_holdings,\n  EXISTS (\n    SELECT 1\n    FROM inventory.instance__t__identifiers source_ident\n    JOIN inventory.identifier_type__t source_type ON source_type.id = source_ident.identifiers__identifier_type_id\n    JOIN inventory.instance__t__identifiers other_ident ON other_ident.identifiers__value = source_ident.identifiers__value\n    JOIN inventory.identifier_type__t other_type ON other_type.id = other_ident.identifiers__identifier_type_id\n    WHERE source_ident.id = collection_records.instance_id\n      AND other_ident.id <> collection_records.instance_id\n      AND source_type.name ILIKE \'%OCLC%\'\n      AND other_type.name ILIKE \'%OCLC%\'\n  ) AS has_same_oclc_on_different_record\nFROM collection_records\nORDER BY collection_records.title\nLIMIT 100;',
    NULL,
    'Collection names are often inventory.location__t.name values; filter loc.name, not lib.name. The location resolver should emit SC Special Collections Browsing as inventory.location__t.',
    1,
    NULL,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_training_hints
    WHERE type IN ('example', 'correction')
      AND example_question = 'List the records in the SC Special Collections Browsing collection, with their HRID, Call Number Prefix, Call Number, Author, Title, whether there are multiple holdings on the record, and whether the same OCLC number appears on a different record.'
);
