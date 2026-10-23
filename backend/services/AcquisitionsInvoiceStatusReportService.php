<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;
use app\models\ReportTemplate;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/ReportExecutionContractService.php';

/** Compiles the fixed acquisitions invoice status report with validated filters. */
final class AcquisitionsInvoiceStatusReportService
{
    public const REPORT_SLUG = 'acquisitions-invoice-status-report';
    private const SQL_SHA256 = 'eabff1602b272a0793005dde7a6c6edfc4ade0e5998c439548035ab99c3f8995';
    private const PARAMETERS_SHA256 = 'bbb9853bb042ad94a0cd5f442ce3c48cc055da780a8e04586f317c990ca2e8dc';
    private const ACQUISITION_UNITS = ['AC', 'HC', 'MH', 'SC', 'UM'];
    private const STATUSES = ['Open', 'Reviewed', 'Approved', 'Paid', 'Cancelled'];
    private const MAX_VENDOR_SELECTIONS = 1000;

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        foreach ([
            'slug' => self::REPORT_SLUG,
            'name' => 'Acquisitions Invoice Status Report',
            'category' => 'acquisitions',
            'data_source' => 'folio',
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
        $config = self::normalizedJson($definition['execution_config'] ?? null);
        return is_string($sql)
            && hash_equals(self::SQL_SHA256, hash('sha256', $sql))
            && is_array($parameters)
            && hash_equals(
                self::PARAMETERS_SHA256,
                hash('sha256', json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            )
            && $config === self::normalizeJson([
                'public_row_cap' => 100000,
                'fetch_row_limit' => 100001,
                'preserve_export_order' => true,
                'identifier_export' => ['source_column' => 'Invoice UUID', 'header' => 'UUID'],
            ]);
    }

    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        $definition = [];
        foreach ([
            'slug', 'name', 'category', 'data_source', 'created_by', 'default_limit', 'is_active',
            'sql_template', 'parameters', 'execution_config',
        ] as $key) {
            $definition[$key] = $report->$key;
        }
        if (!self::isCanonicalSeedDefinition($definition)) {
            throw new \InvalidArgumentException('Acquisitions invoice report definition does not match the reviewed seed contract.');
        }
        ReportExecutionContractService::assertStaticExportSql($report->sql_template, ['fetchRowLimit' => 100001]);

        $acqUnit = $inputs['acqUnit'] ?? 'SC';
        if ($acqUnit === '') {
            $acqUnit = 'SC';
        }
        $acqUnit = self::choice($acqUnit, self::ACQUISITION_UNITS, 'acqUnit');

        $startValue = $inputs['startDate'] ?? '';
        if ($startValue === '') {
            $startValue = ReportTemplate::resolveDefaultMacro('$fiscal_year_start');
        }
        $endValue = $inputs['endDate'] ?? '';
        if ($endValue === '') {
            $endValue = ReportTemplate::resolveDefaultMacro('$today');
        }
        $startDate = self::date($startValue, 'startDate');
        $endDate = self::date($endValue, 'endDate');
        if ($endDate < $startDate) {
            throw new ReportParameterValidationException('endDate', 'End Date must be on or after Start Date.');
        }

        $statusValue = $inputs['statuses'] ?? 'Open,Reviewed,Approved,Paid';
        $statuses = self::selections($statusValue, 'statuses', count(self::STATUSES));
        if (empty($statuses)) {
            throw new ReportParameterValidationException('statuses', 'Select at least one invoice status.');
        }
        foreach ($statuses as $status) {
            if (!in_array($status, self::STATUSES, true)) {
                throw new ReportParameterValidationException('statuses', 'Choose only listed invoice statuses.');
            }
        }

        $vendorValue = $inputs['vendorIds'] ?? '';
        $vendorIds = self::selections($vendorValue, 'vendorIds', self::MAX_VENDOR_SELECTIONS);
        foreach ($vendorIds as &$vendorId) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $vendorId) !== 1) {
                throw new ReportParameterValidationException('vendorIds', 'Every selected vendor must be a valid UUID.');
            }
            $vendorId = strtolower($vendorId);
        }
        unset($vendorId);
        if (count(array_unique($vendorIds)) !== count($vendorIds)) {
            throw new ReportParameterValidationException('vendorIds', 'Selected vendors must be unique.');
        }

        self::assertRequiredTables($folioDb);
        $params = [
            ':acqUnit' => $acqUnit,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
        ];
        $statusPlaceholders = [];
        foreach ($statuses as $index => $status) {
            $marker = ':status_' . $index;
            $statusPlaceholders[] = $marker;
            $params[$marker] = $status;
        }
        $vendorPlaceholders = [];
        foreach ($vendorIds as $index => $vendorId) {
            $marker = ':vendor_' . $index;
            $vendorPlaceholders[] = $marker;
            $params[$marker] = $vendorId;
        }

        $vendorFilter = empty($vendorPlaceholders)
            ? 'TRUE'
            : 'i.vendor_id IN (' . implode(', ', $vendorPlaceholders) . ')';
        $sql = str_replace([':statuses', ':vendorFilter'], [implode(', ', $statusPlaceholders), $vendorFilter], $report->sql_template);
        return [
            'sql' => $sql,
            'params' => $params,
            'marcTag' => 'invoice',
            'location' => ['id' => $acqUnit, 'name' => 'Acquisitions Unit ' . $acqUnit, 'code' => $acqUnit],
            'locations' => [],
        ];
    }

    private static function assertRequiredTables($folioDb): void
    {
        $tables = [
            'invoices' => 'invoice.invoices__t',
            'unit_links' => 'invoice.invoices__t__acq_unit_ids',
            'acquisition_units' => 'orders.acquisitions_unit__t',
            'vendors' => 'organizations.organizations__t',
            'fiscal_years' => 'finance.fiscal_year__t',
        ];
        $selects = [];
        $params = [];
        foreach ($tables as $alias => $table) {
            $marker = ':required_table_' . $alias;
            $selects[] = 'to_regclass(' . $marker . ') AS ' . $alias;
            $params[$marker] = $table;
        }
        $row = $folioDb->createCommand('SELECT ' . implode(', ', $selects), $params)->queryOne();
        foreach ($tables as $alias => $table) {
            if (!is_array($row) || empty($row[$alias])) {
                throw new \InvalidArgumentException('Reporting schema is missing the expected table ' . $table . '.');
            }
        }
    }

    private static function choice($value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ReportParameterValidationException($field, 'Choose one of the listed options.');
        }
        return $value;
    }

    private static function date($value, string $field): string
    {
        if (!is_string($value)) {
            throw new ReportParameterValidationException($field, 'Enter a valid date.');
        }
        $value = trim($value);
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        $errors = \DateTime::getLastErrors();
        if ($date === false
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new ReportParameterValidationException($field, 'Enter a valid date in YYYY-MM-DD format.');
        }
        return $value;
    }

    private static function selections($value, string $field, int $maximum): array
    {
        if (!is_string($value)) {
            throw new ReportParameterValidationException($field, 'Choose values from the listed options.');
        }
        if (trim($value) === '') {
            return [];
        }
        $values = array_map('trim', explode(',', $value));
        if (count($values) > $maximum || in_array('', $values, true)) {
            throw new ReportParameterValidationException($field, 'Selected values must be nonempty and within the selection limit.');
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new ReportParameterValidationException($field, 'Selected values must be unique.');
        }
        return $values;
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
