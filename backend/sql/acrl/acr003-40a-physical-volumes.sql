-- Physical volumes (items) at Smith College and the Five College Repository; snapshot query, no date parameters.
-- Non-serial print types are counted. Journal, Newspaper and Serial items are counted only when their instance is cataloged as a single unit (monograph), because question 43 counts serial titles.
-- Online and microform locations are left out.
with scope_items as (
    select it.id, it.holdings_record_id, mtt.name as material_type
    from inventory.item__t it
        join inventory.material_type__t mtt on mtt.id = it.material_type_id
        join inventory.location__t lt on lt.id = it.effective_location_id
    where lt.campus_id in ('7d02e46d-3e60-4eaf-a986-5aa3550e8cb5', 'b23a14ae-b3a8-4cbf-92fb-4b81d1a4b0a1')
        and it.status__name in ('Awaiting pickup', 'In transit', 'Checked out', 'Available', 'Restricted', 'Paged')
        and it.effective_location_id not in (
            '67204874-e4d7-495b-9247-62cd27d9ea31', '409b947f-d67d-4cda-846f-35c9344ae81b', 'b0ecdd73-6416-4bd1-9cf3-483fd40b95cd',
            '81772d53-e7d0-40d4-8249-a380f3fd33f0', '270dceaa-8100-4e53-bf19-6decf7926f78', '3ffae749-2131-48e1-8bf8-7adf36a92b9c',
            '38fa998f-5044-4589-a182-2e027af0bb04')
        and mtt.name in ('Archival material', 'Book', 'Government Publication', 'Score', 'Supplement', 'Thesis/Dissertation',
                         'Journal', 'Newspaper', 'Serial')
)
select
    (select count(distinct id) from scope_items
     where material_type in ('Archival material', 'Book', 'Government Publication', 'Score', 'Supplement', 'Thesis/Dissertation'))
  + (select count(distinct si.id)
     from scope_items si
         join inventory.holdings_record__t hrt on hrt.id = si.holdings_record_id
         join inventory.instance__t inst on inst.id = hrt.instance_id
         join inventory.mode_of_issuance__t moi on moi.id = inst.mode_of_issuance_id and moi.name = 'single unit'
     where si.material_type in ('Journal', 'Newspaper', 'Serial')) as value;
