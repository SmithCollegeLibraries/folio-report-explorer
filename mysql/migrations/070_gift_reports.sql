START TRANSACTION;

INSERT INTO report_templates
  (slug, name, description, help_text, category, sql_template, parameters, data_source, execution_config, default_limit, is_active, created_by)
VALUES
(
  'gift-items-one-time',
  'Gift items (one-time)',
  'Gift items (one-time) lists Smith College campus items with an item note of type Gift note, created during the selected date range. Accession date is the item record created date in Eastern time.',
  'Includes items with an item note of type "Gift note" whose effective location belongs to the Smith College campus. Accession date is the item record created date converted to America/New_York (Eastern time). Start and end dates are inclusive.',
  'acquisitions',
  'SELECT it.title AS "Title",
       lt."name" AS "Location",
       hrt.call_number AS "Call number",
       itn.barcode AS "Barcode",
       itn.volume AS "Description",
       mtt."name" AS "Format",
       itn.notes__note AS "Gift note",
       (it2.metadata__created_date AT TIME ZONE ''America/New_York'')::date AS "Accession date"
FROM inventory.item__t__notes itn
LEFT JOIN inventory.location__t lt ON itn.effective_location_id = lt.id
LEFT JOIN inventory.material_type__t mtt ON itn.material_type_id = mtt.id
LEFT JOIN inventory.holdings_record__t hrt ON itn.holdings_record_id = hrt.id
LEFT JOIN inventory.instance__t it ON hrt.instance_id = it.id
LEFT JOIN inventory.item__t it2 ON it2.id = itn.id
WHERE itn.notes__item_note_type_id = ''a28539e9-21ce-40f1-9b64-b1faf4e189c0''
  AND lt.campus_id = ''7d02e46d-3e60-4eaf-a986-5aa3550e8cb5''
  AND (it2.metadata__created_date AT TIME ZONE ''America/New_York'')::date BETWEEN CAST(:startDate AS date) AND CAST(:endDate AS date)
GROUP BY hrt.call_number, itn.volume, lt."name", it.title, itn.barcode, mtt."name", itn.notes__note, it2.metadata__created_date
ORDER BY it.title',
  '[{"name":"startDate","type":"date","label":"Start date","required":true,"default":"$previous_fiscal_year_start"},{"name":"endDate","type":"date","label":"End date","required":true,"default":"$previous_fiscal_year_end"}]',
  'folio',
  NULL,
  100000,
  1,
  'manual'
),
(
  'gift-ongoing',
  'Ongoing gifts',
  'Ongoing gifts lists items created during the selected date range and linked through order pieces to gift purchase orders in the SC acquisitions unit.',
  'Includes items created in the selected date range that are linked through order pieces to purchase-order lines with the Gift acquisition method on purchase orders assigned to acquisitions unit SC. Results have one row per title, location, call number, and format, with the count of distinct item HRIDs as Number of issues. There is no receipt status or order type filter. Start and end dates are inclusive.',
  'acquisitions',
  'SELECT it2.title AS "Title",
       lt.name AS "Location",
       hrt.call_number AS "Call number",
       COUNT(DISTINCT it.hrid) AS "Number of issues",
       mtt.name AS "Format"
FROM inventory.item__t it
LEFT JOIN orders.pieces__t pt ON it.id = pt.item_id
LEFT JOIN orders.po_line__t plt ON plt.id = pt.po_line_id
LEFT JOIN orders.purchase_order__t__acq_unit_ids potaui ON potaui.id = plt.purchase_order_id
LEFT JOIN inventory.holdings_record__t hrt ON hrt.id = it.holdings_record_id
LEFT JOIN inventory.instance__t it2 ON it2.id = hrt.instance_id
LEFT JOIN inventory.location__t lt ON it.effective_location_id = lt.id
LEFT JOIN inventory.material_type__t mtt ON it.material_type_id = mtt.id
WHERE plt.acquisition_method = ''0a4163a5-d225-4007-ad90-2fb41b73efab''
  AND potaui.acq_unit_ids = ''b17b9e6b-82bb-4f97-b3e7-757e4e5aeb61''
  AND (it.metadata__created_date AT TIME ZONE ''America/New_York'')::date BETWEEN CAST(:startDate AS date) AND CAST(:endDate AS date)
GROUP BY hrt.call_number, it2.title, lt.name, mtt.name
ORDER BY it2.title',
  '[{"name":"startDate","type":"date","label":"Start date","required":true,"default":"$previous_fiscal_year_start"},{"name":"endDate","type":"date","label":"End date","required":true,"default":"$previous_fiscal_year_end"}]',
  'folio',
  NULL,
  100000,
  1,
  'manual'
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  help_text = VALUES(help_text),
  category = VALUES(category),
  sql_template = VALUES(sql_template),
  parameters = VALUES(parameters),
  data_source = VALUES(data_source),
  execution_config = VALUES(execution_config),
  default_limit = VALUES(default_limit),
  is_active = VALUES(is_active),
  created_by = VALUES(created_by);

COMMIT;
