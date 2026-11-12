<?php

namespace app\services;

use app\models\ReportTemplate;
use app\exceptions\ReportParameterValidationException;

require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
require_once __DIR__ . '/CatalogingMarcLocationScopeService.php';
require_once __DIR__ . '/ReportExecutionContractService.php';
require_once __DIR__ . '/CanonicalSeedJsonService.php';

final class CatalogingSharedRecordReportService
{
    public const REPORT_SLUG = 'shared-record-check';
    private const SQL_SHA256 = '2972fa870a493d50778bf6566f8acb8569b0f9704011632ec3c76880a5f4f211';
    private const PARAMETERS_SHA256 = '6f98570cb2ee42de1f5e6f67b61d7911d706911955ecd6252a2f8fd269d483da';
    private const SCOPE_OPTIONS = [
        'owner:SM', 'owner:AM', 'owner:MH', 'owner:UM', 'owner:HA', 'owner:YB', 'owner:DP',
        'campus:SC', 'campus:AC', 'campus:MH', 'campus:UM', 'campus:HC', 'campus:RP', 'campus:YB',
    ];
    private const SHARE_STATUSES = ['all', 'shared', 'single', 'no_owner_multi', 'no_owner'];
    private const SUPPRESSION_MODES = ['all', 'exclude_discovery', 'exclude_staff', 'exclude_both'];

    public static function supports(ReportTemplate $report): bool
    {
        return $report->slug === self::REPORT_SLUG;
    }

    public static function isCanonicalSeedDefinition(array $definition): bool
    {
        foreach ([
            'slug' => self::REPORT_SLUG,
            'name' => 'Shared Record Check — Cataloging',
            'description' => 'Shows FOLIO instances by 998 owner or permanent holdings campus, with owner codes, holding colleges, and suppression status for review.',
            'help_text' => 'Ownership is read from 998$a. RC counts as UMass, and DP is the Five College Depository. Holding colleges come from every holdings permanent location; RP appears as its own campus code. Shared means two or more owners. Single owner means one owner. No owner code, held at 2+ colleges means no owner code and holdings at two or more campuses. No owner code means no owner code and no holdings or holdings at one campus.'
                . "\n\nThe report includes every instance source. A selected location filter uses permanent locations to decide which instances appear; Holding colleges still lists all permanent holding campuses for each result. Results stop at 100,000 rows. A run that reaches the cap is marked incomplete, so narrow large scopes by share status or selected locations. Export FOLIO UUID list gives the instance UUIDs for FOLIO batch work.",
            'category' => 'cataloging',
            'data_source' => 'folio',
            'created_by' => 'manual',
            'default_limit' => '100000',
            'is_active' => '1',
        ] as $key => $expected) {
            if (!is_scalar($definition[$key] ?? null) || (string) $definition[$key] !== $expected) {
                return false;
            }
        }

        $parameters = CanonicalSeedJsonService::normalize($definition['parameters'] ?? null);
        $config = CanonicalSeedJsonService::normalize($definition['execution_config'] ?? null);
        $sql = $definition['sql_template'] ?? null;
        return is_string($sql)
            && hash_equals(self::SQL_SHA256, hash('sha256', $sql))
            && is_array($parameters)
            && hash_equals(self::PARAMETERS_SHA256, hash('sha256', json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
            && $config === CanonicalSeedJsonService::normalize([
                'public_row_cap' => 100000,
                'fetch_row_limit' => 100001,
                'preserve_export_order' => true,
                'identifier_export' => ['source_column' => 'Instance UUID', 'header' => 'UUID'],
            ])
            && array_key_exists('composite_config', $definition)
            && $definition['composite_config'] === null;
    }

    public static function build(ReportTemplate $report, array $inputs, $folioDb): array
    {
        $definition = [];
        foreach ([
            'slug', 'name', 'description', 'help_text', 'category', 'data_source', 'created_by',
            'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config', 'composite_config',
        ] as $key) {
            $definition[$key] = $report->$key;
        }
        if (!self::isCanonicalSeedDefinition($definition)) {
            throw new \InvalidArgumentException('Shared record report definition does not match the reviewed seed contract.');
        }

        $scopeValue = self::defaulted($inputs, 'scope', 'owner:SM');
        $scopeValue = self::choice($scopeValue, self::SCOPE_OPTIONS, 'scope');
        list($scopeKind, $scopeCode) = explode(':', $scopeValue, 2);
        $shareStatus = self::choice(self::defaulted($inputs, 'shareStatus', 'all'), self::SHARE_STATUSES, 'shareStatus');
        $suppressionMode = self::choice(self::defaulted($inputs, 'suppressionMode', 'all'), self::SUPPRESSION_MODES, 'suppressionMode');
        $locationIds = self::defaulted($inputs, 'locationIds', '');
        $resolved = [
            'location' => ['name' => 'shared-records', 'code' => $scopeCode],
            'locations' => [],
        ];

        $row = $folioDb->createCommand('SELECT to_regclass(:table_name)', [':table_name' => 'marctab.mt998'])->queryOne();
        if (!is_array($row) || !reset($row)) {
            throw new \InvalidArgumentException('Reporting schema is missing the expected MARC tag table marctab.mt998.');
        }

        if ($locationIds !== '') {
            try {
                $locationScope = CatalogingMarcLocationScopeService::validate(
                    ['locationIds' => $locationIds, 'locationBasis' => 'permanent_holdings'],
                    ['permanent_holdings']
                );
            } catch (\InvalidArgumentException $exception) {
                throw new ReportParameterValidationException('locationIds', $exception->getMessage());
            }
            $resolved = CatalogingMarcLocationScopeService::resolveLocations($locationScope, $folioDb);
            $locationIds = implode(',', $locationScope['locationIds']);
        }

        $sql = $report->sql_template;
        ReportExecutionContractService::assertStaticExportSql($sql, ['fetchRowLimit' => 100001]);
        return [
            'sql' => $sql,
            'params' => [
                ':scopeKind' => $scopeKind,
                ':scopeCode' => $scopeCode,
                ':shareStatus' => $shareStatus,
                ':suppressionMode' => $suppressionMode,
                ':locationIds' => $locationIds,
            ],
            'location' => $resolved['location'],
            'locations' => $resolved['locations'],
            'marcTag' => '998',
        ];
    }

    private static function defaulted(array $inputs, string $field, string $default)
    {
        $value = $inputs[$field] ?? null;
        return $value === null || $value === '' ? $default : $value;
    }

    private static function choice($value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ReportParameterValidationException($field, 'Choose one of the listed options.');
        }
        return $value;
    }
}
