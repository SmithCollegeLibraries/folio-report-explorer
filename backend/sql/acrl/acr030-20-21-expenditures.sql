-- Sums Smith payments minus credits for one-time and ongoing purchase-order lines in one Smith fiscal year.
-- The fiscal year is selected by SCFY code using the year of the bound fiscal-year end.
-- Invoice lines without a PO line are left out; the purchase-order line supplies the order and material types.
-- A missing material type is included. Admin material types are excluded from every total.
with parameters as (
    select cast(:fy_start as date) as fy_start
), transaction_lines as (
    select
        po.order_type,
        coalesce(eresource_mt.name, physical_mt.name) as material_type,
        case
            when t.transaction_type = 'Credit' then -(t.amount::numeric)
            else t.amount::numeric
        end as amount
    from finance.transaction__t t
        join finance.fiscal_year__t fy on fy.id = t.fiscal_year_id
        join invoice.invoice_lines__t il on il.id = t.source_invoice_line_id
        join orders.po_line__t pol on pol.id = il.po_line_id
        join orders.purchase_order__t po on po.id = pol.purchase_order_id
        left join inventory.material_type__t eresource_mt
            on eresource_mt.id::text = pol.eresource__material_type::text
        left join inventory.material_type__t physical_mt
            on physical_mt.id::text = pol.physical__material_type::text
        cross join parameters p
    where fy.code = 'SCFY' || extract(year from cast(:fy_end as date))::integer::text
        and p.fy_start is not null
        and t.transaction_type in ('Payment', 'Credit')
), eligible_lines as (
    select order_type, material_type, amount
    from transaction_lines
    where coalesce(material_type, '') <> 'Admin'
)
select
    coalesce(round(sum(amount) filter (where order_type = 'One-Time'), 2), 0::numeric) as q20_one_time,
    coalesce(round(sum(amount) filter (where order_type = 'One-Time' and material_type in ('E-Book', 'E-Book Package')), 2), 0::numeric) as q20a_one_time_ebooks,
    coalesce(round(sum(amount) filter (where order_type = 'Ongoing'), 2), 0::numeric) as q21_ongoing,
    coalesce(round(sum(amount) filter (where order_type = 'Ongoing' and material_type in ('E-Book', 'E-Book Package')), 2), 0::numeric) as q21a_ongoing_ebooks,
    coalesce(round(sum(amount) filter (where order_type = 'Ongoing' and material_type in ('E-Journal', 'E-Journal Package')), 2), 0::numeric) as q21b_ongoing_ejournals
from eligible_lines;
