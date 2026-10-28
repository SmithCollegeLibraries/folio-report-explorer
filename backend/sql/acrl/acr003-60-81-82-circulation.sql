-- Counts Q60 Smith-owned physical loans at Smith service points, Q81 Smith-owned physical loans at non-Smith service points,
-- and Q82 partner-owned physical loans at Smith service points. Bind fy_start inclusive and fy_end exclusive.
-- Campus scope: Smith College and Five College Repository for Smith-owned items; partner-owned items are outside those campuses.
-- Material types: Book, Journal, Serial, Newspaper, Score, Thesis/Dissertation, Government Publication, Microform,
-- DVD/Blu-ray, Audio CD, CD-ROM, Videocassette, LP Phonorecord, Audiocassette, Film.
-- Rules: UUID ids are compared as text; FY end is exclusive at July 1; one row per checkout, so no renewal filter.
with smith_sp as (
  select unnest(array[
    '2c0764b7-63b3-4254-9950-0c730b7e438b', -- SC Neilson Library
    '6b6b9f00-aac7-4e20-a819-fb24386e48bb', -- SC Self Check
    '25d98f21-ef4d-4846-955e-17840062b1f0', -- SC Hillyer Art Library
    '602aba21-73ae-4e43-a92e-91f2dee34c8f', -- SC Josten Library
    '9e4b06c8-0cb0-4011-ab1d-a23af57d190c'  -- SC Annex
  ]) as id
), smith_campus as (
  select unnest(array[
    '7d02e46d-3e60-4eaf-a986-5aa3550e8cb5', -- Smith College
    'b23a14ae-b3a8-4cbf-92fb-4b81d1a4b0a1'  -- Five College Repository
  ]) as id
), keep_materials as (
  select unnest(array['Book', 'Journal', 'Serial', 'Newspaper', 'Score', 'Thesis/Dissertation',
    'Government Publication', 'Microform', 'DVD/Blu-ray', 'Audio CD', 'CD-ROM', 'Videocassette',
    'LP Phonorecord', 'Audiocassette', 'Film']) as name
), base as (
  select l.id,
         l.checkout_service_point_id::text as co_sp,
         l.checkin_service_point_id::text  as ci_sp,
         loc.campus_id::text               as campus
  from circulation.loan__t l
  join inventory.location__t loc on loc.id = l.item_effective_location_id_at_check_out
  join inventory.item__t it on it.id = l.item_id
  join inventory.material_type__t mt on mt.id = it.material_type_id
  where l.loan_date::timestamptz >= cast(:fy_start as date)
    and l.loan_date::timestamptz <  cast(:fy_end as date)
    and mt.name in (select name from keep_materials)
)
select
  sum(case when campus in (select id from smith_campus) and co_sp in (select id from smith_sp)
            and (ci_sp is null or ci_sp in (select id from smith_sp)) then 1 else 0 end) as q60_physical_circulation,
  sum(case when campus in (select id from smith_campus) and co_sp is not null
            and co_sp not in (select id from smith_sp) then 1 else 0 end) as q81_consortial_lending,
  sum(case when campus not in (select id from smith_campus) and co_sp in (select id from smith_sp)
            then 1 else 0 end) as q82_consortial_borrowing
from base;
