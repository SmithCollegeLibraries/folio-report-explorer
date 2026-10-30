<?php

namespace app\services;

/** Recognizes the canonical Budget Year Fund Report across migrations 035-037 and 062. */
final class BudgetYearFundReportMigrationRecognitionService
{
    public static function budgetYearFundReportAppearsComplete($db): bool
    {
        return self::budgetYearFundReportEncumbranceStatusAppearComplete($db)
            || (self::hasColumn($db, 'report_templates', 'help_text') && self::rowExists(
                $db,
                'report_templates',
                'id = 37 AND slug = :slug AND name = :name AND description = :description'
                    . ' AND category = :category AND data_source = :data_source'
                    . ' AND default_limit = :default_limit AND is_active = 1 AND created_by = :created_by'
                    . ' AND sql_template LIKE :sql_marker AND sql_template LIKE :remaining_difference_marker'
                    . ' AND help_text LIKE :help_marker AND CAST(parameters AS CHAR) LIKE :fiscal_year_parameter'
                    . ' AND CAST(parameters AS CHAR) LIKE :acq_unit_parameter',
                [
                    ':slug' => 'budget-year-fund-report', ':name' => 'Budget Year Fund Report',
                    ':description' => 'Compares transaction-derived payments, current encumbrances, and remaining balances with FOLIO budget totals for every allocated fund in a selected fiscal year and acquisition unit.',
                    ':category' => 'finance', ':data_source' => 'folio', ':default_limit' => 1000,
                    ':created_by' => 'manual', ':sql_marker' => '%WITH selected_fiscal_year AS (%',
                    ':remaining_difference_marker' => '%AS "Remaining Difference"%',
                    ':help_marker' => '%Remaining Difference: Calculated Remaining minus FOLIO Available.%',
                    ':fiscal_year_parameter' => '%"fiscalYearId"%', ':acq_unit_parameter' => '%"acqUnitId"%',
                ]
            ));
    }

    public static function budgetYearFundReportFiscalYearOptionsAppearComplete($db): bool
    {
        return self::budgetYearFundReportEncumbranceStatusAppearComplete($db)
            || (self::hasColumn($db, 'report_templates', 'help_text') && self::rowExists(
                $db,
                'report_templates',
                'id = 37 AND slug = :slug AND name = :name AND description = :description'
                    . ' AND category = :category AND data_source = :data_source'
                    . ' AND default_limit = :default_limit AND is_active = 1 AND created_by = :created_by'
                    . ' AND sql_template LIKE :series_marker AND sql_template LIKE :remaining_marker'
                    . ' AND sql_template NOT LIKE :difference_marker AND help_text LIKE :help_marker'
                    . ' AND help_text NOT LIKE :difference_marker AND JSON_LENGTH(parameters) = 2'
                    . " AND BINARY JSON_UNQUOTE(JSON_EXTRACT(parameters, '$[0].name')) = BINARY :fiscal_year_parameter"
                    . " AND BINARY JSON_UNQUOTE(JSON_EXTRACT(parameters, '$[1].name')) = BINARY :acq_unit_parameter",
                [
                    ':slug' => 'budget-year-fund-report', ':name' => 'Budget Year Fund Report',
                    ':description' => 'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
                    ':category' => 'finance', ':data_source' => 'folio', ':default_limit' => 1000,
                    ':created_by' => 'manual', ':series_marker' => "%fy.series = au.code || 'FY'%",
                    ':remaining_marker' => '%AS "Calculated Remaining"%', ':difference_marker' => '%Remaining Difference%',
                    ':help_marker' => '%Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.%',
                    ':fiscal_year_parameter' => 'fiscalYearEndYear', ':acq_unit_parameter' => 'acqUnitId',
                ]
            ));
    }

    public static function budgetYearFundReportPaymentDistributionsAppearComplete($db): bool
    {
        return self::budgetYearFundReportEncumbranceStatusAppearComplete($db)
            || self::reportDefinitionAppearsComplete($db, self::paymentDistributionDefinition());
    }

    public static function budgetYearFundReportEncumbranceStatusAppearComplete($db): bool
    {
        if (!self::hasColumn($db, 'report_templates', 'help_text')) {
            return false;
        }

        $definition = self::encumbranceStatusDefinition();
        if (self::reportDefinitionAppearsComplete($db, $definition)) {
            return true;
        }

        // Also recognize the equivalent single-line spelling used by canonical report fixtures.
        $definition['sql_markers']['encumbrance_current_marker'] = "SUM(CASE WHEN t.encumbrance__status IN ('Unreleased', 'Active') THEN COALESCE(t.encumbrance__initial_amount_encumbered, 0) - COALESCE(t.encumbrance__amount_expended, 0) - COALESCE(t.encumbrance__amount_awaiting_payment, 0) ELSE 0 END) AS current_encumbrance";
        $definition['sql_markers']['released_amount_marker'] = "SUM(CASE WHEN t.encumbrance__status = 'Released' THEN COALESCE(t.encumbrance__initial_amount_encumbered, 0) ELSE 0 END) AS released_encumbrances";
        $definition['sql_markers']['fund_year_join_marker'] = 'e.fund_id = b.fund_id AND e.fiscal_year_id = b.fiscal_year_id';
        return self::reportDefinitionAppearsComplete($db, $definition);
    }

    private static function reportDefinitionAppearsComplete($db, array $definition): bool
    {
        if (!self::hasColumn($db, 'report_templates', 'help_text')) {
            return false;
        }

        $where = 'id = 37 AND slug = :slug AND name = :name AND description = :description'
            . ' AND category = :category AND data_source = :data_source AND default_limit = :default_limit'
            . ' AND is_active = 1 AND created_by = :created_by AND JSON_LENGTH(parameters) = 2';
        $params = [
            ':slug' => 'budget-year-fund-report', ':name' => 'Budget Year Fund Report',
            ':description' => $definition['description'], ':category' => 'finance',
            ':data_source' => 'folio', ':default_limit' => 1000, ':created_by' => 'manual',
        ];

        if ($definition['parameter_names_only']) {
            foreach ([0 => 'fiscal_year', 1 => 'acq_unit'] as $index => $prefix) {
                $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(parameters, '$[{$index}].name')) = :{$prefix}_parameter";
                $params[":{$prefix}_parameter"] = $definition['parameters'][$index];
            }
        } else {
            $parameterFields = ['name', 'type', 'label', 'default', 'description', 'options_sql', 'options_db', 'placeholder'];
            foreach ([0 => 'fiscal_year', 1 => 'acq_unit'] as $index => $prefix) {
                foreach ($parameterFields as $field) {
                    $where .= " AND BINARY JSON_UNQUOTE(JSON_EXTRACT(parameters, '$[{$index}].{$field}')) = BINARY :{$prefix}_{$field}";
                    $params[":{$prefix}_{$field}"] = $definition['parameters'][$index][$field];
                }
                $where .= " AND JSON_EXTRACT(parameters, '$[{$index}].required') = true";
            }
        }

        foreach ($definition['aliases'] as $index => $alias) {
            $where .= " AND sql_template LIKE :output_alias_{$index}";
            $params[":output_alias_{$index}"] = '%AS "' . $alias . '"%';
        }
        $aliasLocations = [];
        foreach ($definition['aliases'] as $alias) {
            $aliasLocations[] = "LOCATE('AS \"{$alias}\"', sql_template)";
        }
        for ($index = 0; $index < count($aliasLocations) - 1; $index++) {
            $where .= ' AND ' . $aliasLocations[$index] . ' < ' . $aliasLocations[$index + 1];
        }
        foreach ($definition['sql_markers'] as $name => $marker) {
            $where .= " AND sql_template LIKE :{$name}";
            $params[":{$name}"] = '%' . $marker . '%';
        }
        foreach ($definition['help_markers'] as $name => $marker) {
            $where .= " AND help_text LIKE :{$name}";
            $params[":{$name}"] = '%' . $marker . '%';
        }

        return self::rowExists($db, 'report_templates', $where, $params);
    }

    private static function encumbranceStatusDefinition(): array
    {
        return [
            'parameter_names_only' => false,
            'parameters' => self::paymentDistributionDefinition()['parameters'],
            'description' => 'Shows allocation, payments, released and unreleased encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
            'aliases' => ['Fund Code', 'Fund Name', 'Fiscal Year', 'Allocated', 'Payments', 'Calculated Current Encumbrances', 'Unreleased Count', 'Released Encumbrances', 'Released Count', 'Total Committed', 'Calculated Remaining', 'FOLIO Expenditures', 'FOLIO Encumbered', 'FOLIO Available'],
            'sql_markers' => [
                'series_marker' => "fy.series = au.code || 'FY'",
                'payment_type_marker' => "LOWER(COALESCE(fd.fund_distributions__distribution_type, 'percentage'))",
                'payment_amount_marker' => "WHEN 'amount' THEN COALESCE(fd.fund_distributions__value, 0)",
                'payment_percentage_marker' => 'COALESCE(fd.total, 0) * (COALESCE(fd.fund_distributions__value, 0) * 0.01)',
                'encumbrance_scope_marker' => "t.encumbrance__status IN ('Unreleased', 'Active', 'Released')",
                'encumbrance_current_marker' => "SUM(CASE WHEN t.encumbrance__status IN ('Unreleased', 'Active')\n                    THEN COALESCE(t.encumbrance__initial_amount_encumbered, 0)\n                       - COALESCE(t.encumbrance__amount_expended, 0)\n                       - COALESCE(t.encumbrance__amount_awaiting_payment, 0)\n                    ELSE 0 END) AS current_encumbrance",
                'unreleased_count_marker' => "SUM(CASE WHEN t.encumbrance__status = 'Unreleased' THEN 1 ELSE 0 END) AS unreleased_count",
                'released_amount_marker' => "SUM(CASE WHEN t.encumbrance__status = 'Released'\n                    THEN COALESCE(t.encumbrance__initial_amount_encumbered, 0) ELSE 0 END) AS released_encumbrances",
                'released_count_marker' => "SUM(CASE WHEN t.encumbrance__status = 'Released' THEN 1 ELSE 0 END) AS released_count",
                'encumbrance_group_marker' => 'GROUP BY t.from_fund_id, t.fiscal_year_id',
                'fund_year_join_marker' => "e.fund_id = b.fund_id\n AND e.fiscal_year_id = b.fiscal_year_id",
                'unreleased_count_output_marker' => 'COALESCE(e.unreleased_count, 0) AS "Unreleased Count"',
                'released_encumbrances_output_marker' => 'ROUND(COALESCE(e.released_encumbrances, 0), 2) AS "Released Encumbrances"',
                'released_count_output_marker' => 'COALESCE(e.released_count, 0) AS "Released Count"',
                'encumbrance_left_join_marker' => "LEFT JOIN encumbrances e\n  ON e.fund_id = b.fund_id\n AND e.fiscal_year_id = b.fiscal_year_id",
                'calculated_remaining_marker' => 'COALESCE(b.allocated, 0) - COALESCE(p.payment, 0) - COALESCE(e.current_encumbrance, 0)',
            ],
            'help_markers' => [
                'help_allocated' => 'Allocated: the allocation total stored on the FOLIO budget.',
                'help_payments' => 'Payments: paid invoice-line fund distributions whose invoice payment date falls inside the resolved FOLIO fiscal year.',
                'help_payment_semantics' => 'Percentage distributions contribute the invoice-line total multiplied by the distribution value divided by 100; amount distributions contribute the distribution value directly; distribution types are matched case-insensitively, and a missing type is treated as percentage.',
                'help_encumbrances' => 'Calculated Current Encumbrances: the unreleased amount, calculated as initial amount minus expended amount minus awaiting-payment amount.',
                'help_unreleased_count' => 'Unreleased Count: number of encumbrance transactions whose status is Unreleased.',
                'help_released_amount' => 'Released Encumbrances: sum of initial amounts for encumbrance transactions whose status is Released.',
                'help_released_count' => 'Released Count: number of encumbrance transactions whose status is Released.',
                'help_pending' => 'Pending transactions are excluded from the Unreleased Count, Released Encumbrances, and Released Count.',
                'help_total_committed' => 'Total Committed: Payments plus Calculated Current Encumbrances.',
                'help_calculated_remaining' => 'Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.',
                'help_folio_expenditures' => 'FOLIO Expenditures: the expenditure total currently stored on the FOLIO budget.',
                'help_folio_encumbered' => 'FOLIO Encumbered: the encumbrance total currently stored on the FOLIO budget.',
                'help_folio_available' => 'FOLIO Available: the operational available balance stored on the FOLIO budget.',
            ],
        ];
    }

    private static function paymentDistributionDefinition(): array
    {
        return [
            'parameter_names_only' => false,
            'parameters' => [
                ['name' => 'fiscalYearEndYear', 'type' => 'select', 'label' => 'Fiscal Year', 'default' => '', 'description' => 'Campus-neutral fiscal year; the selected acquisition unit determines the matching FOLIO fiscal-year series.', 'options_sql' => "SELECT DISTINCT EXTRACT(YEAR FROM period_end::date)::int AS value, 'FY' || EXTRACT(YEAR FROM period_end::date)::int::text AS label FROM finance.fiscal_year__t WHERE period_end IS NOT NULL ORDER BY value DESC", 'options_db' => 'folio', 'placeholder' => 'Select fiscal year'],
                ['name' => 'acqUnitId', 'type' => 'select', 'label' => 'Acquisition Unit', 'default' => '', 'description' => 'Determines both fund membership and the campus fiscal-year series.', 'options_sql' => 'SELECT id AS value, TRIM(name) AS label FROM orders.acquisitions_unit__t WHERE NOT is_deleted ORDER BY TRIM(name)', 'options_db' => 'folio', 'placeholder' => 'Select acquisition unit'],
            ],
            'description' => 'Shows allocation, paid invoice distributions, current encumbrances, calculated remaining, and FOLIO budget balances for every allocated fund in a selected fiscal year and acquisition unit.',
            'aliases' => ['Fund Code', 'Fund Name', 'Fiscal Year', 'Allocated', 'Payments', 'Calculated Current Encumbrances', 'Total Committed', 'Calculated Remaining', 'FOLIO Expenditures', 'FOLIO Encumbered', 'FOLIO Available'],
            'sql_markers' => [
                'series_marker' => "fy.series = au.code || 'FY'",
                'payment_type_marker' => "LOWER(COALESCE(fd.fund_distributions__distribution_type, 'percentage'))",
                'payment_amount_marker' => "WHEN 'amount' THEN COALESCE(fd.fund_distributions__value, 0)",
                'payment_percentage_marker' => 'ELSE COALESCE(fd.total, 0) * (COALESCE(fd.fund_distributions__value, 0) * 0.01)',
                'encumbrance_scope_marker' => "t.encumbrance__status IN ('Unreleased', 'Active')",
                'encumbrance_arithmetic_marker' => 'COALESCE(t.encumbrance__initial_amount_encumbered, 0)',
                'encumbrance_expended_marker' => '- COALESCE(t.encumbrance__amount_expended, 0)',
                'encumbrance_awaiting_marker' => '- COALESCE(t.encumbrance__amount_awaiting_payment, 0)',
                'calculated_remaining_marker' => 'COALESCE(b.allocated, 0) - COALESCE(p.payment, 0) - COALESCE(e.current_encumbrance, 0)',
            ],
            'help_markers' => [
                'help_allocated' => 'Allocated: the allocation total stored on the FOLIO budget.',
                'help_payments' => 'Payments: paid invoice-line fund distributions whose invoice payment date falls inside the resolved FOLIO fiscal year.',
                'help_payment_semantics' => 'Percentage distributions contribute the invoice-line total multiplied by the distribution value divided by 100; amount distributions contribute the distribution value directly; distribution types are matched case-insensitively, and a missing type is treated as percentage.',
                'help_encumbrances' => 'Calculated Current Encumbrances: active or unreleased encumbrance transactions, calculated as initial amount minus expended amount minus awaiting-payment amount.',
                'help_total_committed' => 'Total Committed: Payments plus Calculated Current Encumbrances.',
                'help_calculated_remaining' => 'Calculated Remaining: Allocated minus Payments minus Calculated Current Encumbrances.',
                'help_folio_expenditures' => 'FOLIO Expenditures: the expenditure total currently stored on the FOLIO budget.',
                'help_folio_encumbered' => 'FOLIO Encumbered: the encumbrance total currently stored on the FOLIO budget.',
                'help_folio_available' => 'FOLIO Available: the operational available balance stored on the FOLIO budget.',
            ],
        ];
    }

    private static function hasColumn($db, string $table, string $column): bool
    {
        $schema = $db->schema->getTableSchema($table, true);
        return $schema !== null && isset($schema->columns[$column]);
    }

    private static function rowExists($db, string $table, string $where, array $params = []): bool
    {
        return $db->schema->getTableSchema($table, true) !== null
            && (int)$db->createCommand("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params)->queryScalar() > 0;
    }
}
