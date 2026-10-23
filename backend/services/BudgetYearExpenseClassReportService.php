<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;
use app\models\ReportTemplate;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/ExpenseClassScopeService.php';
require_once __DIR__ . '/ReportExecutionContractService.php';

/** Compiles the reviewed budget-year expense-class report with unit, year, date, and fund validation. */
final class BudgetYearExpenseClassReportService
{
    public const REPORT_SLUG = 'budget-year-expense-class';
    private const SQL_SHA256 = '7b1a73948301c9bacc14fc12b790120484110795d17af5acb4f953371c864bf0';
    private const PARAMETERS_SHA256 = 'b48c6159caa12ea566fb0d4d7b59a23968bb574677d2c7f7132aaa4986f8e2ef';
    private const COMPOSITE_SHA256 = '837c892c34381a4488ea427905587f6c0f2cdf07b94ef0743e5636a01700c47c';
    private const REQUIRED_TABLES = [
        'finance.expense_class__t',
        'finance.transaction__t',
        'finance.fiscal_year__t',
        'finance.fund__t__acq_unit_ids',
        'orders.acquisitions_unit__t',
        'invoice.invoice_lines__t__fund_distributions',
        'invoice.invoices__t',
        'orders.po_line__t',
        'inventory.material_type__t',
    ];

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        foreach ([
            'slug' => self::REPORT_SLUG,
            'name' => 'Budget Year Expense Class Report',
            'category' => 'finance',
            'data_source' => 'composite',
            'created_by' => 'manual',
            'default_limit' => '100000',
            'is_active' => '1',
        ] as $key => $expected) {
            if (!is_scalar($definition[$key] ?? null) || (string) $definition[$key] !== $expected) {
                return false;
            }
        }

        $sql = $definition['sql_template'] ?? null;
        $parameters = self::normalizedJson($definition['parameters'] ?? null);
        $executionConfig = self::normalizedJson($definition['execution_config'] ?? null);
        $compositeConfig = self::normalizedJson($definition['composite_config'] ?? null);
        return is_string($sql)
            && hash_equals(self::SQL_SHA256, hash('sha256', $sql))
            && is_array($parameters)
            && hash_equals(
                self::PARAMETERS_SHA256,
                hash('sha256', json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            )
            && $executionConfig === null
            && is_array($compositeConfig)
            && hash_equals(
                self::COMPOSITE_SHA256,
                hash('sha256', json_encode($compositeConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            );
    }

    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        $definition = [];
        foreach ([
            'slug', 'name', 'category', 'data_source', 'created_by', 'default_limit', 'is_active',
            'sql_template', 'parameters', 'execution_config', 'composite_config',
        ] as $key) {
            $definition[$key] = $report->$key;
        }
        if (!self::isCanonicalSeedDefinition($definition)) {
            throw new \InvalidArgumentException('Budget-year expense-class report definition does not match the reviewed seed contract.');
        }
        ReportExecutionContractService::assertStaticExportSql($report->sql_template, ['fetchRowLimit' => 100001]);

        $unit = ExpenseClassScopeService::normalizeUnit($inputs['acqUnit'] ?? null);
        $fiscalYear = self::fiscalYear($inputs['fiscalYear'] ?? null);
        self::assertRequiredTables($folioDb);

        $fiscalYearCode = $unit . 'FY' . $fiscalYear;
        $fiscalYearRow = $folioDb->createCommand(
            'SELECT id'
                . ' FROM finance.fiscal_year__t'
                . ' WHERE UPPER(BTRIM(code)) = :fiscalYearCode'
                . ' AND UPPER(BTRIM(series)) = :fiscalYearSeries'
                . ' LIMIT 1',
            [
                ':fiscalYearCode' => $fiscalYearCode,
                ':fiscalYearSeries' => $unit . 'FY',
            ]
        )->queryOne();
        if (!is_array($fiscalYearRow) || empty($fiscalYearRow['id'])) {
            throw new ReportParameterValidationException('fiscalYear', 'No fiscal year is available for this college and year.');
        }

        $startDate = self::optionalDate($inputs['startDate'] ?? '', 'startDate');
        $endDate = self::optionalDate($inputs['endDate'] ?? '', 'endDate');
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            throw new ReportParameterValidationException('endDate', 'End date must be on or after start date.');
        }

        $fundIds = self::fundIds($inputs['funds'] ?? '');
        ExpenseClassScopeService::assertFundsBelongToUnit($folioDb, $unit, $fundIds);

        $markers = [];
        $params = [
            ':expenseClassPattern' => $unit . '%',
            ':fiscalYearId' => (string) $fiscalYearRow['id'],
            ':invoiceFiscalYearId' => (string) $fiscalYearRow['id'],
            ':startDateEnabled' => $startDate === null ? 0 : 1,
            ':startDate' => $startDate,
            ':endDateEnabled' => $endDate === null ? 0 : 1,
            ':endDate' => $endDate,
        ];
        foreach ($fundIds as $index => $fundId) {
            $marker = ':fund_' . $index;
            $markers[] = $marker;
            $params[$marker] = $fundId;
        }

        $sql = str_replace(':fundIds', implode(', ', $markers), $report->sql_template);
        return [
            'sql' => $sql,
            'params' => $params,
            'compositeParams' => [':fiscalYear' => $fiscalYear],
            'marcTag' => 'budget-year-expense-class',
            'location' => ['id' => $unit, 'name' => $unit, 'code' => $unit],
            'locations' => [],
        ];
    }

    private static function assertRequiredTables($folioDb): void
    {
        $selects = [];
        $params = [];
        foreach (self::REQUIRED_TABLES as $index => $table) {
            $marker = ':requiredTable' . $index;
            $selects[] = 'to_regclass(' . $marker . ') AS required_table_' . $index;
            $params[$marker] = $table;
        }
        $row = $folioDb->createCommand('SELECT ' . implode(', ', $selects), $params)->queryOne();
        foreach (self::REQUIRED_TABLES as $index => $table) {
            if (!is_array($row) || empty($row['required_table_' . $index])) {
                throw new \InvalidArgumentException('Reporting schema is missing the expected table ' . $table . '.');
            }
        }
    }

    private static function fiscalYear($value): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/\A\d{4}\z/D', trim($value)) !== 1
            || (int) trim($value) < 1900 || (int) trim($value) > 3000) {
            throw new ReportParameterValidationException('fiscalYear', 'Enter a valid four-digit fiscal year.');
        }
        return trim($value);
    }

    private static function optionalDate($value, string $field)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::date($value, $field);
    }

    private static function date($value, string $field): string
    {
        if (!is_string($value)) {
            throw new ReportParameterValidationException($field, 'Enter a valid date in YYYY-MM-DD format.');
        }
        $value = trim($value);
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        $errors = \DateTime::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new ReportParameterValidationException($field, 'Enter a valid date in YYYY-MM-DD format.');
        }
        return $value;
    }

    private static function fundIds($value): array
    {
        if (!is_string($value)) {
            throw new ReportParameterValidationException('funds', 'Choose funds from the selected college.');
        }
        $value = trim($value);
        if ($value === '') {
            throw new ReportParameterValidationException('funds', 'Select at least one fund for this college.');
        }
        $ids = array_map('trim', explode(',', $value));
        if (count($ids) > 500 || in_array('', $ids, true) || count(array_unique(array_map('strtolower', $ids))) !== count($ids)) {
            throw new ReportParameterValidationException('funds', 'Selected funds must be unique and within the selection limit.');
        }
        foreach ($ids as &$id) {
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $id) !== 1) {
                throw new ReportParameterValidationException('funds', 'Choose funds from the selected college.');
            }
            $id = strtolower($id);
        }
        unset($id);
        return $ids;
    }

    private static function normalizedJson($value)
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? self::normalizeJson($value) : null;
    }

    private static function normalizeJson($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }
        return array_map([self::class, 'normalizeJson'], $value);
    }
}
