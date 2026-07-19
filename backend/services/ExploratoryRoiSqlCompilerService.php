<?php

namespace app\services;

/**
 * Compiles the fully documented default acquisitions/circulation ROI contract
 * into the same SQL shape accepted by semantic conformance.
 */
class ExploratoryRoiSqlCompilerService
{
    public static function compile(array $contract): ?array
    {
        if (empty($contract['applicable'])) {
            return null;
        }

        $values = [];
        foreach (($contract['requirements'] ?? []) as $requirement) {
            if (!is_array($requirement) || empty($requirement['key'])) {
                continue;
            }
            $values[(string)$requirement['key']] = $requirement['parameters']['value'] ?? null;
        }

        $expected = [
            'purchase_date_basis' => 'payment_date',
            'investment_cost_basis' => 'actual_paid_fund_distribution',
            'circulation_window' => 'same_as_purchase_window',
            'call_number_grouping' => 'primary_call_number_class',
            'roi_formula' => 'checkouts_per_dollar_with_cost_per_use',
        ];
        foreach ($expected as $key => $value) {
            if (($values[$key] ?? null) !== $value) {
                return null;
            }
        }

        $campus = trim((string)($values['campus_scope'] ?? ''));
        if ($campus === '') {
            return null;
        }
        $campusLiteral = str_replace("'", "''", $campus);

        $sql = <<<SQL
WITH spend_by_instance AS (
    SELECT pol.instance_id,
           COUNT(DISTINCT pol.id) AS purchase_count,
           SUM(fd.total * fd.fund_distributions__value * 0.01) AS spend
    FROM invoice.invoice_lines__t__fund_distributions fd
    JOIN invoice.invoice_lines__t invoice_line ON invoice_line.id = fd.id
    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id
    JOIN orders.po_line__t pol ON pol.id = fd.po_line_id
    WHERE invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'
    GROUP BY pol.instance_id
), circulation_by_item AS (
    SELECT item.id AS item_id,
           item.holdings_record_id,
           COUNT(audit_loan.created_date) AS checkouts
    FROM inventory.item__t item
    LEFT JOIN circulation.audit_loan__t audit_loan
      ON audit_loan.loan__item_id = item.id
     AND audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')
     AND audit_loan.created_date >= CURRENT_DATE - INTERVAL '5 years'
    JOIN inventory.location__t circ_location ON circ_location.id = item.effective_location_id
    JOIN inventory.loclibrary__t circ_library ON circ_library.id = circ_location.library_id
    JOIN inventory.loccampus__t selected_scope ON selected_scope.id = circ_library.campus_id
    WHERE selected_scope.name = '{$campusLiteral}'
    GROUP BY item.id, item.holdings_record_id
), circulation_by_instance AS (
    SELECT holdings.instance_id,
           SUM(circulation_by_item.checkouts) AS circulation
    FROM circulation_by_item
    JOIN inventory.holdings_record__t holdings ON holdings.id = circulation_by_item.holdings_record_id
    GROUP BY holdings.instance_id
), campus_instances AS (
    SELECT scope_holdings.instance_id AS instance_id
    FROM inventory.item__t scope_item
    JOIN inventory.holdings_record__t scope_holdings ON scope_holdings.id = scope_item.holdings_record_id
    JOIN inventory.location__t scope_location ON scope_location.id = scope_item.effective_location_id
    JOIN inventory.loclibrary__t scope_library ON scope_library.id = scope_location.library_id
    JOIN inventory.loccampus__t selected_scope ON selected_scope.id = scope_library.campus_id
    WHERE selected_scope.name = '{$campusLiteral}'
    GROUP BY scope_holdings.instance_id
), class_by_instance AS (
    SELECT instance.id AS instance_id,
           MIN(SUBSTRING(holdings.effective_call_number_components__call_number FROM '^[A-Za-z]+')) AS call_number_class
    FROM inventory.instance__t instance
    JOIN inventory.holdings_record__t holdings ON holdings.instance_id = instance.id
    GROUP BY instance.id
)
SELECT class_by_instance.call_number_class,
       SUM(spend_by_instance.purchase_count) AS purchase_count,
       SUM(spend_by_instance.spend) AS spend,
       SUM(circulation_by_instance.circulation) AS circulation,
       SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0) AS checkouts_per_dollar,
       SUM(spend_by_instance.spend) / NULLIF(SUM(circulation_by_instance.circulation), 0) AS cost_per_checkout
FROM spend_by_instance
JOIN class_by_instance ON class_by_instance.instance_id = spend_by_instance.instance_id
JOIN campus_instances ON campus_instances.instance_id = spend_by_instance.instance_id
LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = spend_by_instance.instance_id
GROUP BY class_by_instance.call_number_class
ORDER BY purchase_count DESC
LIMIT 100
SQL;

        return [
            'sql' => $sql,
            'explanation' => 'Compares paid acquisitions and item-level checkout activity by primary call-number class using the documented five-year ROI defaults.',
            'dataSource' => 'folio',
        ];
    }
}
