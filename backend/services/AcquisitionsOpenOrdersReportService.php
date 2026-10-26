<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;
use app\models\ReportTemplate;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/ReportExecutionContractService.php';
require_once __DIR__ . '/ReportFilterInputService.php';

/** Compiles the governed acquisitions open-order worklist. */
final class AcquisitionsOpenOrdersReportService
{
    public const REPORT_SLUG = 'quarterly-open-orders';
    private const SQL_SHA256 = 'efc6d56e68a65a0cf3d89da9aadfeecf2ae5a66ff562a157edab18bc9afe9fea';
    private const PARAMETERS_SHA256 = '68aba9046d2b1ab7b19147066d595ec99dbdf7c7a9b6a83fea1861160f507f06';
    private const DESCRIPTION_SHA256 = '945c7b36e9fe4d33e39b5c925e3e2a6ee495234042365f07bfd9174a094a00ba';
    private const HELP_TEXT_SHA256 = '7f4dacbe5ac30edf971a3f916ca419f7fdc079ddf63e3dbf1313e27e83fe411a';
    private const WORKFLOW_STATUSES = ['Pending', 'Open', 'Closed'];
    private const ORDER_TYPES = ['One-Time', 'Ongoing'];
    private const LINE_VIEWS = ['Not yet received or not yet paid', 'All lines'];
    private const ORDER_FORMATS = ['All', 'Electronic Resource', 'Physical Resource', 'P/E Mix', 'Other'];
    private const RE_ENCUMBER = ['Any', 'On', 'Off'];
    private const RECEIPT_STATUSES = ['Pending', 'Awaiting Receipt', 'Partially Received', 'Fully Received', 'Receipt Not Required', 'Ongoing', 'Cancelled'];
    private const PAYMENT_STATUSES = ['Pending', 'Awaiting Payment', 'Partially Paid', 'Fully Paid', 'Ongoing', 'Payment Not Required', 'Cancelled'];
    private const NO_EXPENSE_CLASS = '__none__';
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
        'invoice.invoice_lines__t',
        'invoice.invoices__t',
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

        ReportExecutionContractService::assertStaticExportSql($report->sql_template, ['fetchRowLimit' => 100001]);
        $acqUnit = self::stringInput($inputs, 'acqUnit', 'SC');
        if ($acqUnit === '' || strlen($acqUnit) > 100) {
            throw new ReportParameterValidationException('acqUnit', 'Choose an acquisitions unit from the list.');
        }
        $workflowStatuses = ReportFilterInputService::selections(
            $inputs['workflowStatuses'] ?? null,
            'workflowStatuses',
            self::WORKFLOW_STATUSES,
            self::WORKFLOW_STATUSES
        );
        $orderTypes = ReportFilterInputService::selections(
            $inputs['orderTypes'] ?? null,
            'orderTypes',
            self::ORDER_TYPES,
            self::ORDER_TYPES
        );
        $lineView = self::choiceInput($inputs, 'lineView', self::LINE_VIEWS[0], self::LINE_VIEWS);
        $titleSearch = self::searchPattern($inputs, 'titleSearch', 200);
        $poLineNumberSearch = self::searchPattern($inputs, 'poLineNumberSearch', 100);
        $startDate = ReportFilterInputService::date($inputs['startDate'] ?? null, 'startDate', null, true);
        $endDate = ReportFilterInputService::date($inputs['endDate'] ?? null, 'endDate', null, true);
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            throw new ReportParameterValidationException('endDate', 'End date must be on or after start date.');
        }
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
        $orderFormat = self::choiceInput($inputs, 'orderFormat', 'All', self::ORDER_FORMATS);
        $reEncumber = self::choiceInput($inputs, 'reEncumber', 'Any', self::RE_ENCUMBER);

        self::assertRequiredTables($folioDb);
        self::assertAcquisitionsUnit($folioDb, $acqUnit);
        $materialTypeOptions = self::loadOptionValues($report, 'materialTypes', $folioDb);
        $expenseClassOptions = self::loadOptionValues($report, 'expenseClasses', $folioDb);
        $materialTypes = self::dynamicSelections($inputs['materialTypes'] ?? null, 'materialTypes', $materialTypeOptions);
        $expenseClasses = self::dynamicSelections($inputs['expenseClasses'] ?? null, 'expenseClasses', $expenseClassOptions);

        $params = [
            ':acqUnit' => $acqUnit,
            ':lineView' => $lineView,
            ':titleSearch' => $titleSearch,
            ':poLineNumberSearch' => $poLineNumberSearch,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
            ':orderFormat' => $orderFormat,
            ':reEncumber' => $reEncumber,
        ];
        $workflowFilter = ReportFilterInputService::selectionPredicate(
            $workflowStatuses, self::WORKFLOW_STATUSES, 'po.workflow_status', 'workflowStatus', $params
        );
        $orderTypeFilter = ReportFilterInputService::selectionPredicate(
            $orderTypes, self::ORDER_TYPES, 'po.order_type', 'orderType', $params
        );
        $receiptFilter = ReportFilterInputService::selectionPredicate(
            $receiptStatuses, self::RECEIPT_STATUSES, 'pol.receipt_status', 'receiptStatus', $params
        );
        $paymentFilter = ReportFilterInputService::selectionPredicate(
            $paymentStatuses, self::PAYMENT_STATUSES, 'pol.payment_status', 'paymentStatus', $params
        );
        $materialFilter = ReportFilterInputService::selectionPredicate(
            $materialTypes,
            $materialTypeOptions,
            'COALESCE(physical_mt.name, eresource_mt.name)',
            'materialType',
            $params
        );
        $expenseFilter = self::expenseClassPredicate($expenseClasses, $expenseClassOptions, $params);
        $sql = str_replace(
            [
                '/*__WORKFLOW_STATUS_FILTER__*/TRUE',
                '/*__ORDER_TYPE_FILTER__*/TRUE',
                '/*__RECEIPT_STATUS_FILTER__*/TRUE',
                '/*__PAYMENT_STATUS_FILTER__*/TRUE',
                '/*__MATERIAL_TYPE_FILTER__*/TRUE',
                '/*__EXPENSE_CLASS_FILTER__*/TRUE',
            ],
            [$workflowFilter, $orderTypeFilter, $receiptFilter, $paymentFilter, $materialFilter, $expenseFilter],
            $report->sql_template
        );

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

    private static function choiceInput(array $inputs, string $name, string $default, array $allowed): string
    {
        $value = self::stringInput($inputs, $name, $default);
        if (!in_array($value, $allowed, true)) {
            throw new ReportParameterValidationException($name, 'Choose one of the listed options.');
        }
        return $value;
    }

    private static function searchPattern(array $inputs, string $name, int $maximumLength): ?string
    {
        $value = $inputs[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new ReportParameterValidationException($name, 'Enter text from the report form.');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maximumLength) {
            throw new ReportParameterValidationException($name, 'Search text is too long.');
        }
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        return '%' . $escaped . '%';
    }

    private static function dynamicSelections($value, string $field, array $allowed): array
    {
        if ($value === null || $value === '') {
            return $allowed;
        }
        return ReportFilterInputService::selections($value, $field, $allowed, $allowed);
    }

    private static function loadOptionValues(ReportTemplate $report, string $name, $folioDb): array
    {
        foreach ($report->getDecodedParameters() as $definition) {
            if (($definition['name'] ?? null) !== $name) {
                continue;
            }
            $sql = $definition['options_sql'] ?? null;
            if (!is_string($sql) || trim($sql) === '' || ($definition['options_db'] ?? 'folio') !== 'folio') {
                break;
            }
            $values = $folioDb->createCommand($sql)->queryColumn();
            if (!is_array($values)) {
                break;
            }
            return array_values(array_unique(array_map('strval', $values)));
        }
        throw new \InvalidArgumentException('Open Order Report is missing its ' . $name . ' option query.');
    }

    private static function expenseClassPredicate(array $selected, array $all, array &$params): string
    {
        if ($selected === $all) {
            return 'TRUE';
        }
        $classIds = [];
        $includeNoClass = false;
        foreach ($selected as $value) {
            if ($value === self::NO_EXPENSE_CLASS) {
                $includeNoClass = true;
            } else {
                $classIds[] = $value;
            }
        }

        $predicates = [];
        if (!empty($classIds)) {
            $markers = [];
            foreach ($classIds as $index => $classId) {
                $marker = ':expenseClass' . $index;
                $markers[] = $marker;
                $params[$marker] = $classId;
            }
            $predicates[] = 'EXISTS (SELECT 1 FROM orders.po_line__t__fund_distribution AS selected_fd'
                . ' WHERE selected_fd.id = il.po_line_id'
                . ' AND selected_fd.fund_distribution__expense_class_id IN (' . implode(', ', $markers) . '))';
        }
        if ($includeNoClass) {
            $predicates[] = 'NOT EXISTS (SELECT 1 FROM orders.po_line__t__fund_distribution AS no_class_fd'
                . ' WHERE no_class_fd.id = il.po_line_id'
                . ' AND no_class_fd.fund_distribution__expense_class_id IS NOT NULL)';
        }
        return empty($predicates) ? 'FALSE' : '(' . implode(' OR ', $predicates) . ')';
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
