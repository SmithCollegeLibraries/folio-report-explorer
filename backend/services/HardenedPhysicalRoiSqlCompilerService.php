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
        $eligibleMaterialJoin = '';
        $eligibleMaterialPredicate = '';
        $materialJoin = '';
        $materialPredicate = '';
        if ($materialType !== null) {
            $eligibleMaterialJoin = "\n    JOIN inventory.material_type__t material_type ON material_type.id = eligible_item.material_type_id";
            $eligibleMaterialPredicate = "\n      AND LOWER(material_type.name) = " . self::quoteLiteralValue($materialType);
            $materialJoin = "\n    JOIN inventory.material_type__t material_type ON material_type.id = fallback_item.material_type_id";
            $materialPredicate = "\n      AND LOWER(material_type.name) = " . self::quoteLiteralValue($materialType);
        }

        $sql = <<<SQL
WITH paid_invoice_lines AS (
    SELECT invoice_line.id AS invoice_line_id,
           invoice_line.po_line_id AS po_line_id,
           invoice_line.quantity AS quantity,
           invoice.currency AS currency
    FROM invoice.invoice_lines__t invoice_line
    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id
    WHERE invoice.status = 'Paid'
      AND invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'
), eligible_current_smith_items AS (
    SELECT eligible_item.id AS id,
           eligible_item.purchase_order_line_identifier AS purchase_order_line_identifier
    FROM inventory.item__t eligible_item
    JOIN inventory.location__t eligible_location ON eligible_location.id = eligible_item.effective_location_id
    JOIN inventory.loclibrary__t eligible_library ON eligible_library.id = eligible_location.library_id
    JOIN inventory.loccampus__t eligible_campus ON eligible_campus.id = eligible_library.campus_id{$eligibleMaterialJoin}
    WHERE eligible_campus.name = {$campusLiteral}{$eligibleMaterialPredicate}
    GROUP BY eligible_item.id, eligible_item.purchase_order_line_identifier
), current_smith_instances AS (
    SELECT fallback_holdings.instance_id AS instance_id
    FROM inventory.item__t fallback_item
    JOIN inventory.holdings_record__t fallback_holdings ON fallback_holdings.id = fallback_item.holdings_record_id
    JOIN inventory.location__t fallback_location ON fallback_location.id = fallback_item.effective_location_id
    JOIN inventory.loclibrary__t fallback_library ON fallback_library.id = fallback_location.library_id
    JOIN inventory.loccampus__t fallback_campus ON fallback_campus.id = fallback_library.campus_id{$materialJoin}
    WHERE fallback_campus.name = {$campusLiteral}{$materialPredicate}
    GROUP BY fallback_holdings.instance_id
), piece_item_links AS (
    SELECT DISTINCT receiving_piece.po_line_id AS po_line_id,
           eligible_piece_item.id AS item_id
    FROM orders.pieces__t receiving_piece
    JOIN eligible_current_smith_items eligible_piece_item ON eligible_piece_item.id = receiving_piece.item_id
), direct_item_links AS (
    SELECT DISTINCT eligible_direct_item.purchase_order_line_identifier AS po_line_id,
           eligible_direct_item.id AS item_id
    FROM eligible_current_smith_items eligible_direct_item
    WHERE eligible_direct_item.purchase_order_line_identifier IS NOT NULL
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
), linkage_by_po_line AS (
    SELECT funded_line.invoice_line_id AS invoice_line_id,
           paid_line.instance_id AS instance_id,
           funded_line.quantity AS quantity,
           funded_line.currency AS currency,
           funded_line.spend AS spend,
           LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)) AS exact_linked_copies,
           GREATEST(funded_line.quantity - LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)), 0) AS fallback_linked_copies,
           LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)) + GREATEST(funded_line.quantity - LEAST(funded_line.quantity, COUNT(DISTINCT eligible_exact_item.id)), 0) AS allocated_physical_copies
    FROM funded_invoice_lines funded_line
    JOIN orders.po_line__t paid_line ON paid_line.id = funded_line.po_line_id
    JOIN orders.purchase_order__t purchase_order ON purchase_order.id = paid_line.purchase_order_id
    JOIN orders.purchase_order__t__acq_unit_ids purchase_order_unit ON purchase_order_unit.id = purchase_order.id
    JOIN orders.acquisitions_unit__t acquisition_unit ON acquisition_unit.id = purchase_order_unit.acq_unit_ids
    JOIN current_smith_instances fallback_eligible ON fallback_eligible.instance_id = paid_line.instance_id
    LEFT JOIN orders.pieces__t receiving_piece ON receiving_piece.po_line_id = paid_line.id
    LEFT JOIN eligible_current_smith_items eligible_exact_item ON eligible_exact_item.id = receiving_piece.item_id
    WHERE paid_line.cost__quantity_physical > 0
      AND TRIM(acquisition_unit.name) = 'SC'
    GROUP BY funded_line.invoice_line_id, paid_line.instance_id, funded_line.quantity, funded_line.currency, funded_line.spend
), acquisitions_by_instance AS (
    SELECT allocated_line.instance_id AS instance_id,
           allocated_line.currency AS currency,
           SUM(allocated_line.allocated_physical_copies) AS purchase_count,
           SUM(allocated_line.allocated_physical_copies) AS physical_copies_purchased,
           COUNT(DISTINCT allocated_line.instance_id) AS distinct_titles,
           SUM(allocated_line.spend) AS spend,
           SUM(allocated_line.exact_linked_copies) AS exact_linked_copies,
           SUM(allocated_line.fallback_linked_copies) AS fallback_linked_copies
    FROM linkage_by_po_line allocated_line
    GROUP BY allocated_line.instance_id, allocated_line.currency
), item_classes AS (
    SELECT class_item.id AS item_id,
           class_holdings.instance_id AS instance_id,
           CASE
               WHEN TRIM(COALESCE(class_item.effective_call_number_components__call_number, '')) = '' THEN 'Unclassified'
               WHEN class_item.effective_call_number_components__call_number ~ '^[A-Z]{1,3}[0-9]' THEN REGEXP_REPLACE(class_item.effective_call_number_components__call_number, '^([A-Z]{1,3})[0-9].*', '\1')
               WHEN class_item.effective_call_number_components__call_number ~ '^[0-9]' THEN LPAD(CAST(FLOOR(CAST(REGEXP_REPLACE(class_item.effective_call_number_components__call_number, '^([0-9]+).*', '\1') AS NUMERIC) / 100) * 100 AS TEXT), 3, '0')
               ELSE 'Local/Other'
           END AS call_number_class
    FROM inventory.item__t class_item
    JOIN inventory.holdings_record__t class_holdings ON class_holdings.id = class_item.holdings_record_id
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
    SELECT item.id AS item_id,
           item.holdings_record_id,
           COUNT(DISTINCT audit_loan.loan__id) AS checkouts
    FROM inventory.item__t item
    LEFT JOIN circulation.audit_loan__t audit_loan
      ON audit_loan.loan__item_id = item.id
     AND audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')
     AND audit_loan.loan__loan_date >= CURRENT_DATE - INTERVAL '5 years'
    JOIN inventory.location__t circ_location ON circ_location.id = item.effective_location_id
    JOIN inventory.loclibrary__t circ_library ON circ_library.id = circ_location.library_id
    JOIN inventory.loccampus__t selected_scope ON selected_scope.id = circ_library.campus_id
    WHERE selected_scope.name = {$campusLiteral}
    GROUP BY item.id, item.holdings_record_id
), circulation_by_instance AS (
    SELECT holdings.instance_id,
           SUM(circulation_by_item.checkouts) AS circulation
    FROM circulation_by_item
    JOIN inventory.holdings_record__t holdings ON holdings.id = circulation_by_item.holdings_record_id
    GROUP BY holdings.instance_id
), class_by_instance AS (
    SELECT instance.id AS instance_id,
           MIN(SUBSTRING(class_source_item.effective_call_number_components__call_number FROM '^[A-Za-z]+')) AS call_number_class
    FROM inventory.instance__t instance
    JOIN inventory.holdings_record__t holdings ON holdings.instance_id = instance.id
    JOIN inventory.item__t class_source_item ON class_source_item.holdings_record_id = holdings.id
    GROUP BY instance.id
)
SELECT class_by_instance.call_number_class,
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
JOIN class_by_instance ON class_by_instance.instance_id = acquisitions_by_instance.instance_id
JOIN current_smith_instances campus_instances ON campus_instances.instance_id = acquisitions_by_instance.instance_id
LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = acquisitions_by_instance.instance_id
GROUP BY class_by_instance.call_number_class, acquisitions_by_instance.currency
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
