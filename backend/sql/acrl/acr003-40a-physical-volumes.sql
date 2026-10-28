-- Counts distinct physical volumes (items), including bound serial volumes; snapshot query, no date parameters.
-- Campus scope: Smith College and Five College Repository.
-- Material types: Archival material, Book, Government Publication, Journal, Newspaper, Score, Serial, Supplement, Thesis/Dissertation.
-- Items in online and microform locations are excluded.
select count(distinct it.id) as value
from inventory.item__t it
    left join inventory.material_type__t mtt on mtt.id = it.material_type_id
    left join inventory.location__t lt on lt.id = it.effective_location_id
where lt.campus_id in ('7d02e46d-3e60-4eaf-a986-5aa3550e8cb5', 'b23a14ae-b3a8-4cbf-92fb-4b81d1a4b0a1')
    and it.status__name in ('Awaiting pickup', 'In transit', 'Checked out', 'Available', 'Restricted', 'Paged')
    and mtt.name in ('Archival material', 'Book', 'Government Publication', 'Journal', 'Newspaper', 'Score', 'Serial', 'Supplement', 'Thesis/Dissertation')
    and it.effective_location_id not in (
        '67204874-e4d7-495b-9247-62cd27d9ea31',
        '409b947f-d67d-4cda-846f-35c9344ae81b',
        'b0ecdd73-6416-4bd1-9cf3-483fd40b95cd',
        '81772d53-e7d0-40d4-8249-a380f3fd33f0',
        '270dceaa-8100-4e53-bf19-6decf7926f78',
        '3ffae749-2131-48e1-8bf8-7adf36a92b9c',
        '38fa998f-5044-4589-a182-2e027af0bb04'
    );
