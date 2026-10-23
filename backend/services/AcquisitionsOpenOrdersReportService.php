<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;
use app\models\ReportTemplate;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/ReportExecutionContractService.php';
require_once __DIR__ . '/ReportFilterInputService.php';

/** Compiles the fixed, one-time acquisitions open-orders worklist. */
final class AcquisitionsOpenOrdersReportService
{
    public const REPORT_SLUG = 'quarterly-open-orders';
    private const SQL_SHA256 = '0c06cfba037331c1ad748b0a852f85567d7d01e2e69e6e83d1782828cffe0981';
    private const PARAMETERS_SHA256 = '7eedbfe6e580fc6649f15e64812bff80a688ca0e5355dac01b1ac3241e65968d';
    private const DESCRIPTION_SHA256 = '7276ecf6bb276e7d8fd7418985b155330aa75abc3a60c0bb10af6697af0a7d84';
    private const HELP_TEXT_SHA256 = 'dfba685312c9062039a6b943ff68d3baa29e865df8cec830b0a266ad78dd2f8a';
    private const ORDER_FORMATS = ['All', 'Electronic Resource', 'Physical Resource', 'P/E Mix', 'Other'];
    private const RE_ENCUMBER = ['Any', 'On', 'Off'];
    private const RECEIPT_STATUSES = ['Pending', 'Awaiting Receipt', 'Partially Received', 'Fully Received'];
    private const PAYMENT_STATUSES = ['Pending', 'Awaiting Payment', 'Partially Paid', 'Fully Paid', 'Ongoing', 'Payment Not Required', 'Cancelled'];
    private const REQUIRED_TABLES = [
        'orders.acquisitions_unit__t',
        'orders.purchase_order__t',
        'orders.purchase_order__t__acq_unit_ids',
        'orders.po_line__t',
        'orders.po_line__t__fund_distribution',
        'orders.po_line__t__vendor_detail__reference_numbers',
        'finance.fund__t',
        'finance.expense_class__t',
        'inventory.material_type__t',
        'organizations.organizations__t',
        'organizations.organizations__t__contacts',
        'organizations.contacts__t__emails',
    ];

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        foreach ([
            'slug' => self::REPORT_SLUG,
            'name' => 'Open Order Report',
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
        $description = $definition['description'] ?? null;
        $helpText = $definition['help_text'] ?? null;
        $parameters = self::normalizedJson($definition['parameters'] ?? null);
        $config = self::normalizedJson($definition['execution_config'] ?? null);
        return is_string($sql)
            && hash_equals(self::SQL_SHA256, hash('sha256', $sql))
            && is_string($description)
            && hash_equals(self::DESCRIPTION_SHA256, hash('sha256', $description))
            && is_string($helpText)
            && hash_equals(self::HELP_TEXT_SHA256, hash('sha256', $helpText))
            && is_array($parameters)
            && hash_equals(self::PARAMETERS_SHA256, hash('sha256', json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            && $config === self::normalizeJson([
                'public_row_cap' => 100000,
                'fetch_row_limit' => 100001,
                'preserve_export_order' => true,
                'identifier_export' => ['source_column' => 'PO Line UUID', 'header' => 'PO Line UUID'],
            ]);
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

    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        $definition = [];
        foreach (['slug', 'name', 'description', 'help_text', 'category', 'data_source', 'created_by', 'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config'] as $key) {
            $definition[$key] = $report->$key;
        }
        if (!self::isCanonicalSeedDefinition($definition)) {
            throw new \InvalidArgumentException('Open Order Report definition does not match the reviewed seed contract.');
        }

        $acqUnit = self::stringInput($inputs, 'acqUnit', 'SC');
        if ($acqUnit === '' || strlen($acqUnit) > 100) {
            throw new ReportParameterValidationException('acqUnit', 'Choose an acquisitions unit from the list.');
        }
        $startDate = self::dateInput($inputs, 'startDate', ReportTemplate::resolveDefaultMacro('$fiscal_year_start'));
        $endDate = self::dateInput($inputs, 'endDate', ReportTemplate::resolveDefaultMacro('$today'));
        if ($endDate < $startDate) {
            throw new ReportParameterValidationException('endDate', 'End date must be on or after start date.');
        }
        $orderFormat = self::choiceInput($inputs, 'orderFormat', 'All', self::ORDER_FORMATS);
        $reEncumber = self::choiceInput($inputs, 'reEncumber', 'Any', self::RE_ENCUMBER);
        $receiptStatuses = ReportFilterInputService::selections(
            $inputs['receiptStatuses'] ?? null,
            'receiptStatuses',
            self::RECEIPT_STATUSES,
            self::RECEIPT_STATUSES
        );
        $paymentStatuses = ReportFilterInputService::selections(
            $inputs['paymentStatuses'] ?? null,
            'paymentStatuses',
            self::PAYMENT_STATUSES,
            self::PAYMENT_STATUSES
        );

        ReportExecutionContractService::assertStaticExportSql($report->sql_template, ['fetchRowLimit' => 100001]);
        $params = [
            ':acqUnit' => $acqUnit,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
            ':orderFormat' => $orderFormat,
            ':reEncumber' => $reEncumber,
        ];
        $receiptFilter = ReportFilterInputService::selectionPredicate(
            $receiptStatuses,
            self::RECEIPT_STATUSES,
            'pol.receipt_status',
            'receiptStatus',
            $params
        );
        $paymentFilter = ReportFilterInputService::selectionPredicate(
            $paymentStatuses,
            self::PAYMENT_STATUSES,
            'pol.payment_status',
            'paymentStatus',
            $params
        );
        $sql = str_replace(
            ['/*__RECEIPT_STATUS_FILTER__*/TRUE', '/*__PAYMENT_STATUS_FILTER__*/TRUE'],
            [$receiptFilter, $paymentFilter],
            $report->sql_template
        );
        self::assertRequiredTables($folioDb);
        self::assertAcquisitionsUnit($folioDb, $acqUnit);

        return [
            'sql' => $sql,
            'params' => $params,
            'marcTag' => 'open-orders',
            'location' => ['name' => $acqUnit, 'code' => $acqUnit],
            'locations' => [],
        ];
    }

    private static function stringInput(array $inputs, string $name, string $default): string
    {
        $value = $inputs[$name] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value)) {
            throw new ReportParameterValidationException($name, 'Choose a value from the report form.');
        }
        return $value;
    }

    private static function dateInput(array $inputs, string $name, string $default): string
    {
        return ReportFilterInputService::date($inputs[$name] ?? null, $name, $default);
    }

    private static function choiceInput(array $inputs, string $name, string $default, array $allowed): string
    {
        $value = self::stringInput($inputs, $name, $default);
        if (!in_array($value, $allowed, true)) {
            throw new ReportParameterValidationException($name, 'Choose one of the listed options.');
        }
        return $value;
    }

    private static function assertRequiredTables($folioDb): void
    {
        $selects = [];
        $params = [];
        foreach (self::REQUIRED_TABLES as $index => $table) {
            $parameter = ':requiredTable' . $index;
            $selects[] = 'to_regclass(' . $parameter . ') AS required_table_' . $index;
            $params[$parameter] = $table;
        }
        $row = $folioDb->createCommand('SELECT ' . implode(', ', $selects), $params)->queryOne();
        if (!is_array($row)) {
            throw new \InvalidArgumentException('The reporting schema could not be verified.');
        }
        foreach (self::REQUIRED_TABLES as $index => $table) {
            if (empty($row['required_table_' . $index])) {
                throw new \InvalidArgumentException("Reporting schema is missing the expected table {$table}.");
            }
        }
    }

    private static function assertAcquisitionsUnit($folioDb, string $acqUnit): void
    {
        $row = $folioDb->createCommand(
            'SELECT name FROM orders.acquisitions_unit__t WHERE name = :acqUnit LIMIT 1',
            [':acqUnit' => $acqUnit]
        )->queryOne();
        if (!is_array($row) || (string) ($row['name'] ?? '') !== $acqUnit) {
            throw new ReportParameterValidationException('acqUnit', 'Choose an acquisitions unit from the list.');
        }
    }
}
