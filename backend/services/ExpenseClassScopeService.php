<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';

/** Owns the college-to-expense-class and fund scope used by the budget report and Local Data. */
final class ExpenseClassScopeService
{
    private const UNIT_PREFIXES = ['AC' => 'AC', 'HC' => 'HC', 'MH' => 'MH', 'SC' => 'SC', 'UM' => 'UM'];

    public static function normalizeUnit($unit): string
    {
        if (!is_string($unit)) {
            throw new ReportParameterValidationException('acqUnit', 'Choose one of the listed colleges.');
        }
        $unit = strtoupper(trim($unit));
        if (!isset(self::UNIT_PREFIXES[$unit])) {
            throw new ReportParameterValidationException('acqUnit', 'Choose one of the listed colleges.');
        }
        return $unit;
    }

    public static function normalizePrefix($prefix): string
    {
        if (!is_string($prefix)) {
            throw new \InvalidArgumentException('Choose a college before managing allocations.');
        }
        $prefix = strtoupper(trim($prefix));
        if (!isset(self::UNIT_PREFIXES[$prefix])) {
            throw new \InvalidArgumentException('Choose a college before managing allocations.');
        }
        return self::UNIT_PREFIXES[$prefix];
    }

    public static function getOptions($folioDb, $unit): array
    {
        $unit = self::normalizeUnit($unit);
        $prefix = self::UNIT_PREFIXES[$unit];
        $sql = <<<'SQL'
WITH selected_unit AS (
    SELECT id
    FROM orders.acquisitions_unit__t
    WHERE UPPER(BTRIM(name)) = :unit
      AND NOT is_deleted
)
SELECT
    COALESCE((
        SELECT json_agg(json_build_object(
            'id', f.id,
            'code', BTRIM(f.code),
            'name', BTRIM(f.name)
        ) ORDER BY BTRIM(f.code), BTRIM(f.name))
        FROM finance.fund__t f
        JOIN finance.fund__t__acq_unit_ids fau ON fau.id = f.id
        JOIN selected_unit unit ON unit.id = fau.acq_unit_ids
    ), '[]'::json) AS funds,
    COALESCE((
        SELECT json_agg(json_build_object(
            'code', UPPER(BTRIM(ect.code)),
            'name', BTRIM(ect.name)
        ) ORDER BY UPPER(BTRIM(ect.code)))
        FROM finance.expense_class__t ect
        WHERE UPPER(BTRIM(ect.name)) LIKE :classPattern
    ), '[]'::json) AS expense_classes,
    COALESCE((
        SELECT json_agg(json_build_object(
            'year', RIGHT(BTRIM(fy.code), 4)::integer,
            'code', BTRIM(fy.code),
            'startDate', fy.period_start::date::text,
            'endDate', fy.period_end::date::text
        ) ORDER BY RIGHT(BTRIM(fy.code), 4)::integer DESC)
        FROM finance.fiscal_year__t fy
        WHERE UPPER(BTRIM(fy.code)) ~ :fiscalYearPattern
          AND fy.period_start IS NOT NULL
          AND fy.period_end IS NOT NULL
    ), '[]'::json) AS fiscal_years
FROM selected_unit
SQL;
        $row = $folioDb->createCommand($sql, [
            ':unit' => $unit,
            ':classPattern' => $prefix . '%',
            ':fiscalYearPattern' => '^' . $prefix . 'FY[0-9]{4}$',
        ])->queryOne();
        if (!is_array($row)) {
            throw new ReportParameterValidationException('acqUnit', 'The selected college is not available in the reporting schema.');
        }

        $funds = self::decodeJsonList($row['funds'] ?? []);
        foreach ($funds as &$fund) {
            $fund['id'] = (string) ($fund['id'] ?? '');
            $fund['code'] = trim((string) ($fund['code'] ?? ''));
            $fund['name'] = trim((string) ($fund['name'] ?? ''));
            $fund['label'] = $fund['code'] . ' — ' . $fund['name'];
            $fund['defaultSelected'] = $unit === 'SC' && in_array($fund['code'], ['SCBPS', 'SCBIS'], true);
        }
        unset($fund);

        $expenseClasses = self::decodeJsonList($row['expense_classes'] ?? []);
        foreach ($expenseClasses as &$expenseClass) {
            $expenseClass['code'] = strtoupper(trim((string) ($expenseClass['code'] ?? '')));
            $expenseClass['name'] = trim((string) ($expenseClass['name'] ?? ''));
        }
        unset($expenseClass);

        $fiscalYears = self::decodeJsonList($row['fiscal_years'] ?? []);
        foreach ($fiscalYears as &$fiscalYear) {
            $fiscalYear['year'] = (int) ($fiscalYear['year'] ?? 0);
            $fiscalYear['code'] = trim((string) ($fiscalYear['code'] ?? ''));
            $fiscalYear['startDate'] = trim((string) ($fiscalYear['startDate'] ?? ''));
            $fiscalYear['endDate'] = trim((string) ($fiscalYear['endDate'] ?? ''));
        }
        unset($fiscalYear);

        return [
            'prefix' => $prefix,
            'funds' => $funds,
            'expenseClasses' => $expenseClasses,
            'fiscalYears' => $fiscalYears,
        ];
    }

    public static function assertFundsBelongToUnit($folioDb, string $unit, array $fundIds): void
    {
        $unit = self::normalizeUnit($unit);
        if (empty($fundIds)) {
            throw new ReportParameterValidationException('funds', 'Select at least one fund for this college.');
        }

        $placeholders = [];
        $params = [':acqUnit' => $unit];
        foreach ($fundIds as $index => $fundId) {
            $placeholder = ':fund_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $fundId;
        }

        $rows = $folioDb->createCommand(
            'SELECT DISTINCT fau.id AS fund_id'
                . ' FROM finance.fund__t__acq_unit_ids fau'
                . ' JOIN orders.acquisitions_unit__t au ON au.id = fau.acq_unit_ids'
                . ' WHERE UPPER(BTRIM(au.name)) = :acqUnit'
                . ' AND fau.id IN (' . implode(', ', $placeholders) . ')',
            $params
        )->queryAll();
        $found = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['fund_id'])) {
                $found[] = strtolower((string) $row['fund_id']);
            }
        }

        if (count(array_unique($found)) !== count($fundIds)) {
            throw new ReportParameterValidationException('funds', 'Choose only funds associated with the selected college.');
        }
    }

    public static function assertExpenseClassesBelongToPrefix($folioDb, $prefix, array $codes): void
    {
        $prefix = self::normalizePrefix($prefix);
        if (empty($codes)) {
            throw new \InvalidArgumentException('Choose at least one expense class.');
        }

        $placeholders = [];
        $params = [':classPattern' => $prefix . '%'];
        foreach ($codes as $index => $code) {
            $normalized = strtoupper(trim((string) $code));
            if ($normalized === '' || strlen($normalized) > 32) {
                throw new \InvalidArgumentException('Choose an expense class from the selected college.');
            }
            $placeholder = ':class_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $normalized;
        }

        $rows = $folioDb->createCommand(
            'SELECT DISTINCT UPPER(BTRIM(code)) AS expense_class_code'
                . ' FROM finance.expense_class__t'
                . ' WHERE UPPER(BTRIM(name)) LIKE :classPattern'
                . ' AND UPPER(BTRIM(code)) IN (' . implode(', ', $placeholders) . ')',
            $params
        )->queryAll();
        $found = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['expense_class_code'])) {
                $found[] = strtoupper(trim((string) $row['expense_class_code']));
            }
        }

        $requested = array_values(array_unique(array_map('strtoupper', array_map('trim', $codes))));
        if (array_diff($requested, $found) !== [] || count($requested) !== count($found)) {
            throw new \InvalidArgumentException('Choose only expense classes associated with the selected college.');
        }
    }

    private static function decodeJsonList($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }
}
