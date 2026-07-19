<?php

namespace app\services;

/**
 * Compiles the governed physical-only acquisitions/circulation ROI report.
 */
class HardenedPhysicalRoiSqlCompilerService
{
    private const EXPECTED_REQUIREMENTS = [
        'purchase_date_basis' => 'payment_date',
        'investment_cost_basis' => 'actual_paid_fund_distribution',
        'circulation_window' => 'same_as_purchase_window',
        'call_number_grouping' => 'primary_call_number_class',
        'roi_formula' => 'checkouts_per_dollar_with_cost_per_use',
    ];

    private const MATERIAL_TYPES = ['dvd'];

    public static function compile(array $contract): ?array
    {
        if (empty($contract['applicable'])) {
            return null;
        }

        $values = self::requirementValues($contract['requirements'] ?? []);
        foreach (self::EXPECTED_REQUIREMENTS as $key => $expected) {
            if (($values[$key] ?? null) !== $expected) {
                return null;
            }
        }

        $policy = $contract['reportPolicy'] ?? [];
        $campus = trim((string)($values['campus_scope'] ?? ''));
        if (empty($policy['physicalOnly'])
            || ($policy['acquisitionUnitCode'] ?? null) !== 'SC'
            || $campus === '') {
            return null;
        }

        $materialType = $policy['materialType'] ?? null;
        if ($materialType !== null
            && (!is_string($materialType) || !in_array(strtolower($materialType), self::MATERIAL_TYPES, true))) {
            return null;
        }
        $materialType = is_string($materialType) ? strtolower($materialType) : null;
        $permittedMaterial = $contract['permittedFilters']['material_type']['value'] ?? null;
        if ($materialType !== null && strtolower((string)$permittedMaterial) !== $materialType) {
            return null;
        }

        $campusLiteral = self::quoteLiteralValue($campus);
        $materialJoin = '';
        $materialPredicate = '';
        if ($materialType !== null) {
            $materialJoin = "\n    JOIN inventory.material_type__t material_type ON material_type.id = current_item.material_type_id";
            $materialPredicate = "\n      AND LOWER(material_type.name) = " . self::quoteLiteralValue($materialType);
        }

        $sql = <<<SQL
WITH current_smith_items AS (
    SELECT current_item.id AS item_id,
           current_holdings.instance_id AS instance_id,
           current_item.holdings_record_id AS holdings_record_id,
           current_item.purchase_order_line_identifier AS purchase_order_line_identifier,
           current_item.effective_call_number_components__call_number AS call_number
    FROM inventory.item__t current_item
    JOIN inventory.holdings_record__t current_holdings ON current_holdings.id = current_item.holdings_record_id
    JOIN inventory.location__t current_location ON current_location.id = current_item.effective_location_id
    JOIN inventory.loclibrary__t current_library ON current_library.id = current_location.library_id
    JOIN inventory.loccampus__t current_campus ON current_campus.id = current_library.campus_id{$materialJoin}
    WHERE current_campus.name = {$campusLiteral}{$materialPredicate}
), current_smith_instances AS (
    SELECT current_smith_items.instance_id
    FROM current_smith_items
    GROUP BY current_smith_items.instance_id
), funded_invoice_lines AS (
    SELECT invoice_line.id AS invoice_line_id,
           invoice_line.po_line_id AS po_line_id,
           invoice_line.quantity AS quantity,
           invoice.currency AS currency,
           SUM(fd.total * (fd.fund_distributions__value * 0.01)) AS spend
    FROM invoice.invoice_lines__t invoice_line
    JOIN invoice.invoice_lines__t__fund_distributions fd ON fd.id = invoice_line.id
    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id
    WHERE invoice.status = 'Paid'
      AND invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'
    GROUP BY invoice_line.id, invoice_line.po_line_id, invoice_line.quantity, invoice.currency
), paid_po_lines AS (
    SELECT purchase_line.id AS po_line_id,
           purchase_line.instance_id AS instance_id,
           funded_line.currency AS currency,
           SUM(funded_line.quantity) AS quantity,
           SUM(funded_line.spend) AS spend
    FROM funded_invoice_lines funded_line
    JOIN orders.po_line__t purchase_line ON purchase_line.id = funded_line.po_line_id
    JOIN orders.purchase_order__t purchase_order ON purchase_order.id = purchase_line.purchase_order_id
    JOIN orders.purchase_order__t__acq_unit_ids purchase_order_unit ON purchase_order_unit.id = purchase_order.id
    JOIN orders.acquisitions_unit__t acquisition_unit ON acquisition_unit.id = purchase_order_unit.acq_unit_ids
    WHERE purchase_line.cost__quantity_physical > 0
      AND TRIM(acquisition_unit.name) = 'SC'
    GROUP BY purchase_line.id, purchase_line.instance_id, funded_line.currency
), exact_item_links AS (
    SELECT DISTINCT exact_paid_line.po_line_id AS po_line_id,
           CASE
               WHEN receiving_piece.item_id IS NOT NULL THEN eligible_item.item_id
               WHEN eligible_item.purchase_order_line_identifier = exact_paid_line.po_line_id THEN eligible_item.item_id
               ELSE NULL
           END AS item_id
    FROM paid_po_lines exact_paid_line
    JOIN current_smith_items eligible_item ON eligible_item.instance_id = eligible_item.instance_id
    LEFT JOIN orders.pieces__t receiving_piece
      ON receiving_piece.po_line_id = exact_paid_line.po_line_id
     AND receiving_piece.item_id = eligible_item.item_id
), exact_link_counts AS (
    SELECT exact_item_links.po_line_id,
           COUNT(DISTINCT exact_item_links.item_id) AS exact_item_count
    FROM exact_item_links
    GROUP BY exact_item_links.po_line_id
), linkage_by_po_line AS (
    SELECT paid_line.po_line_id,
           paid_line.instance_id,
           paid_line.currency,
           paid_line.quantity,
           paid_line.spend,
           LEAST(paid_line.quantity, COALESCE(exact_links.exact_item_count, 0)) AS exact_linked_copies,
           CASE
               WHEN fallback_eligible.instance_id IS NOT NULL
               THEN GREATEST(paid_line.quantity - LEAST(paid_line.quantity, COALESCE(exact_links.exact_item_count, 0)), 0)
               ELSE 0
           END AS fallback_linked_copies,
           LEAST(paid_line.quantity, COALESCE(exact_links.exact_item_count, 0))
               + CASE
                   WHEN fallback_eligible.instance_id IS NOT NULL
                   THEN GREATEST(paid_line.quantity - LEAST(paid_line.quantity, COALESCE(exact_links.exact_item_count, 0)), 0)
                   ELSE 0
                 END AS allocated_physical_copies
    FROM paid_po_lines paid_line
    LEFT JOIN exact_link_counts exact_links ON exact_links.po_line_id = paid_line.po_line_id
    LEFT JOIN current_smith_instances fallback_eligible ON fallback_eligible.instance_id = paid_line.instance_id
), eligible_acquisitions AS (
    SELECT linkage_by_po_line.po_line_id,
           linkage_by_po_line.instance_id,
           linkage_by_po_line.currency,
           linkage_by_po_line.quantity,
           linkage_by_po_line.spend,
           linkage_by_po_line.exact_linked_copies,
           linkage_by_po_line.fallback_linked_copies,
           linkage_by_po_line.allocated_physical_copies
    FROM linkage_by_po_line
    WHERE linkage_by_po_line.allocated_physical_copies > 0
), acquisitions_by_instance AS (
    SELECT allocated_line.instance_id AS instance_id,
           allocated_line.currency AS currency,
           SUM(allocated_line.allocated_physical_copies) AS purchase_count,
           SUM(allocated_line.allocated_physical_copies) AS physical_copies_purchased,
           COUNT(DISTINCT allocated_line.instance_id) AS distinct_titles,
           SUM(allocated_line.spend) AS spend,
           SUM(allocated_line.exact_linked_copies) AS exact_linked_copies,
           SUM(allocated_line.fallback_linked_copies) AS fallback_linked_copies
    FROM eligible_acquisitions allocated_line
    GROUP BY allocated_line.instance_id, allocated_line.currency
), item_classes AS (
    SELECT current_smith_items.item_id,
           current_smith_items.instance_id,
           CASE
               WHEN TRIM(COALESCE(current_smith_items.call_number, '')) = '' THEN 'Unclassified'
               WHEN current_smith_items.call_number ~* '^[A-Z]{1,3}[0-9]' THEN UPPER(REGEXP_REPLACE(current_smith_items.call_number, '^([A-Za-z]{1,3})[0-9].*', '\1'))
               WHEN current_smith_items.call_number ~ '^[0-9]' THEN LPAD(CAST(FLOOR(CAST(REGEXP_REPLACE(current_smith_items.call_number, '^([0-9]+).*', '\1') AS NUMERIC) / 100) * 100 AS TEXT), 3, '0')
               ELSE 'Local/Other'
           END AS call_number_class
    FROM current_smith_items
), class_counts AS (
    SELECT item_classes.instance_id,
           item_classes.call_number_class,
           COUNT(DISTINCT item_classes.item_id) AS eligible_item_count
    FROM item_classes
    GROUP BY item_classes.instance_id, item_classes.call_number_class
), ranked_classes AS (
    SELECT class_counts.instance_id,
           class_counts.call_number_class,
           ROW_NUMBER() OVER (
               PARTITION BY class_counts.instance_id
               ORDER BY class_counts.eligible_item_count DESC, class_counts.call_number_class ASC
           ) AS class_rank
    FROM class_counts
), dominant_class AS (
    SELECT ranked_classes.instance_id,
           ranked_classes.call_number_class
    FROM ranked_classes
    WHERE ranked_classes.class_rank = 1
), circulation_by_item AS (
    SELECT item.item_id AS item_id,
           item.holdings_record_id AS holdings_record_id,
           COUNT(DISTINCT audit_loan.loan__id) AS checkouts
    FROM current_smith_items item
    LEFT JOIN circulation.audit_loan__t audit_loan
      ON audit_loan.loan__item_id = item.item_id
     AND audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')
     AND audit_loan.loan__loan_date >= CURRENT_DATE - INTERVAL '5 years'
    GROUP BY item.item_id, item.holdings_record_id
), circulation_by_instance AS (
    SELECT holdings.instance_id,
           SUM(circulation_by_item.checkouts) AS circulation
    FROM circulation_by_item
    JOIN inventory.holdings_record__t holdings ON holdings.id = circulation_by_item.holdings_record_id
    GROUP BY holdings.instance_id
)
SELECT dominant_class.call_number_class,
       acquisitions_by_instance.currency AS currency,
       SUM(acquisitions_by_instance.purchase_count) AS purchase_count,
       SUM(acquisitions_by_instance.physical_copies_purchased) AS physical_copies_purchased,
       SUM(acquisitions_by_instance.distinct_titles) AS distinct_titles,
       SUM(acquisitions_by_instance.exact_linked_copies) AS exact_linked_copies,
       SUM(acquisitions_by_instance.fallback_linked_copies) AS fallback_linked_copies,
       SUM(acquisitions_by_instance.spend) AS spend,
       SUM(circulation_by_instance.circulation) AS circulation,
       SUM(circulation_by_instance.circulation) / NULLIF(SUM(acquisitions_by_instance.spend), 0) AS checkouts_per_dollar,
       SUM(acquisitions_by_instance.spend) / NULLIF(SUM(circulation_by_instance.circulation), 0) AS cost_per_checkout,
       SUM(acquisitions_by_instance.fallback_linked_copies) / NULLIF(SUM(acquisitions_by_instance.physical_copies_purchased), 0) AS fallback_percentage
FROM acquisitions_by_instance
JOIN dominant_class ON dominant_class.instance_id = acquisitions_by_instance.instance_id
LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = acquisitions_by_instance.instance_id
GROUP BY dominant_class.call_number_class, acquisitions_by_instance.currency
ORDER BY physical_copies_purchased DESC, spend DESC, call_number_class ASC
LIMIT 100
SQL;

        return [
            'sql' => $sql,
            'explanation' => 'Compares paid physical acquisitions and current-campus item circulation by primary call-number class over the same five-year period.',
            'dataSource' => 'folio',
            'compilerVersion' => 'physical_roi_v2',
            'reportDisclosures' => [
                'Physical purchases and current Smith physical holdings only.',
                'Purchases and circulation use the same five-year period.',
                'Exact receiving links are preferred; fallback-linked copies and percentage are shown.',
                'Electronic-resource ROI is out of scope because usage statistics are unavailable.',
            ],
        ];
    }

    private static function requirementValues(array $requirements): array
    {
        $values = [];
        foreach ($requirements as $requirement) {
            if (is_array($requirement) && is_string($requirement['key'] ?? null)) {
                $values[$requirement['key']] = $requirement['parameters']['value'] ?? null;
            }
        }
        return $values;
    }

    private static function quoteLiteralValue(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
