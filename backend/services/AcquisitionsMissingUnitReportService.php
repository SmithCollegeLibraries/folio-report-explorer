<?php

namespace app\services;

use app\exceptions\ReportParameterValidationException;
use app\models\ReportTemplate;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/ReportExecutionContractService.php';
require_once __DIR__ . '/ReportFilterInputService.php';

/** Compiles the fixed missing-acquisitions-unit report with validated filters. */
final class AcquisitionsMissingUnitReportService
{
    public const REPORT_SLUG = 'orders-missing-acquisitions-unit';
    private const REPORT_NAME = 'Orders Missing an Acquisitions Unit';
    private const REPORT_DESCRIPTION = 'Finds purchase orders with no non-null acquisitions unit whose order numbers begin with SC or contain digits only, and shows vendor and fund-code evidence that can help staff identify likely Smith orders.';
    private const SQL_SHA256 = '770f05332a02d3fe855766f96117ac2bf77298580bf62897edf9a827b93ae2da';
    private const PARAMETERS_SHA256 = '31e8a8bf9bf3db09c6c5b49adb04f673573c3a5d74e6bc742da04f900025d8c6';
    private const HELP_TEXT_SHA256 = '2c33761f405d9c47979adf2ab28639ad73a6e3ad09991513d08f2342c4a3b3d7';
    private const WORKFLOW_STATUSES = ['Pending', 'Open', 'Closed'];
    private const ORDER_TYPES = ['One-Time', 'Ongoing'];
    private const REQUIRED_TABLES = [
        'orders.purchase_order__t',
        'orders.purchase_order__t__acq_unit_ids',
        'orders.po_line__t',
        'orders.po_line__t__fund_distribution',
        'organizations.organizations__t',
    ];

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        foreach ([
            'slug' => self::REPORT_SLUG,
            'name' => self::REPORT_NAME,
            'description' => self::REPORT_DESCRIPTION,
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
        $helpText = $definition['help_text'] ?? null;
        $parameters = self::normalizedJson($definition['parameters'] ?? null);
        $config = self::normalizedJson($definition['execution_config'] ?? null);
        return is_string($sql)
            && hash_equals(self::SQL_SHA256, hash('sha256', $sql))
            && is_string($helpText)
            && hash_equals(self::HELP_TEXT_SHA256, hash('sha256', $helpText))
            && is_array($parameters)
            && hash_equals(
                self::PARAMETERS_SHA256,
                hash('sha256', json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            )
            && $config === self::normalizeJson([
                'public_row_cap' => 100000,
                'fetch_row_limit' => 100001,
                'preserve_export_order' => true,
                'identifier_export' => ['source_column' => 'PO UUID', 'header' => 'UUID'],
            ]);
    }

    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        $definition = [];
        foreach ([
            'slug', 'name', 'description', 'help_text', 'category', 'data_source', 'created_by',
            'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config',
        ] as $key) {
            $definition[$key] = $report->$key;
        }
        if (!self::isCanonicalSeedDefinition($definition)) {
            throw new \InvalidArgumentException('Missing-acquisitions-unit report definition does not match the reviewed seed contract.');
        }
        ReportExecutionContractService::assertStaticExportSql($report->sql_template, ['fetchRowLimit' => 100001]);

        $createdStartDate = ReportFilterInputService::date(
            $inputs['createdStartDate'] ?? null,
            'createdStartDate',
            null,
            true
        );
        $createdEndDate = ReportFilterInputService::date(
            $inputs['createdEndDate'] ?? null,
            'createdEndDate',
            null,
            true
        );
        if ($createdStartDate !== null && $createdEndDate !== null && $createdEndDate < $createdStartDate) {
            throw new ReportParameterValidationException('createdEndDate', 'Date created end must be on or after Date created start.');
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

        $params = [
            ':createdStartDate' => $createdStartDate,
            ':createdEndDate' => $createdEndDate,
        ];
        $workflowFilter = ReportFilterInputService::selectionPredicate(
            $workflowStatuses,
            self::WORKFLOW_STATUSES,
            'po.workflow_status',
            'workflowStatus',
            $params
        );
        $orderTypeFilter = ReportFilterInputService::selectionPredicate(
            $orderTypes,
            self::ORDER_TYPES,
            'po.order_type',
            'orderType',
            $params
        );
        $sql = str_replace(
            ['/*__WORKFLOW_STATUS_FILTER__*/TRUE', '/*__ORDER_TYPE_FILTER__*/TRUE'],
            [$workflowFilter, $orderTypeFilter],
            $report->sql_template
        );
        self::assertRequiredTables($folioDb);

        return [
            'sql' => $sql,
            'params' => $params,
            'marcTag' => 'missing-acquisitions-unit',
            'location' => ['name' => 'All orders', 'code' => 'ALL'],
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
                throw new \InvalidArgumentException("Reporting schema is missing the expected table {$table}.");
            }
        }
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
