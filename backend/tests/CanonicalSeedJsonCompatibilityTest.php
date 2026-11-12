<?php

namespace yii\db {
    if (!class_exists(ActiveRecord::class, false)) {
        class ActiveRecord
        {
            private $attributes = [];
            public function __get($name) { return $this->attributes[$name] ?? null; }
            public function __set($name, $value) { $this->attributes[$name] = $value; }
            public function hasAttribute($name) { return true; }
        }
    }
}

namespace {

require_once __DIR__ . '/support/AcquisitionsInvoiceStatusReportFixture.php';
require_once __DIR__ . '/support/AcquisitionsOpenOrdersReportFixture.php';
require_once __DIR__ . '/support/AcquisitionsMissingUnitReportFixture.php';
require_once __DIR__ . '/support/BudgetYearExpenseClassReportFixture.php';
require_once __DIR__ . '/support/HoldingslessReportFixture.php';
require_once __DIR__ . '/support/CatalogingSharedRecordReportFixture.php';
require_once __DIR__ . '/support/CatalogingMarcCombinationFinderFixture.php';

require_once __DIR__ . '/../services/AcquisitionsInvoiceStatusReportService.php';
require_once __DIR__ . '/../services/AcquisitionsOpenOrdersReportService.php';
require_once __DIR__ . '/../services/AcquisitionsMissingUnitReportService.php';
require_once __DIR__ . '/../services/AcquisitionsMissingUnitReportMigrationService.php';
require_once __DIR__ . '/../services/BudgetYearExpenseClassReportService.php';
require_once __DIR__ . '/../services/CatalogingHoldingslessReportService.php';
require_once __DIR__ . '/../services/CatalogingSharedRecordReportService.php';
require_once __DIR__ . '/../services/CatalogingMarcCombinationFinderService.php';
require_once __DIR__ . '/../services/CatalogingMarcFieldFinderService.php';
require_once __DIR__ . '/../services/CatalogingMarcMissingTagReportService.php';

function canonicalSeedJsonDefinition($source): array
{
    if (is_array($source)) {
        return $source;
    }

    $definition = [];
    foreach ([
        'slug', 'name', 'description', 'help_text', 'category', 'sql_template', 'parameters',
        'data_source', 'execution_config', 'composite_config', 'default_limit', 'is_active', 'created_by',
    ] as $key) {
        $definition[$key] = $source->$key;
    }
    return $definition;
}

function canonicalSeedJsonDecode($value): array
{
    if (!is_string($value)) {
        if (!is_array($value)) {
            throw new RuntimeException('A canonical seed fixture JSON field was not an array or string.');
        }
        return $value;
    }

    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
        $decoded = json_decode($decoded, true);
    }
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new RuntimeException('Could not decode canonical fixture JSON: ' . json_last_error_msg());
    }
    return $decoded;
}

function canonicalSeedJsonEncode(array $value, string $form): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $encoded = json_encode($value, $flags);
    if (!is_string($encoded)) {
        throw new RuntimeException('Could not encode a canonical seed fixture JSON field.');
    }
    if ($form === 'double-encoded') {
        $encoded = json_encode($encoded, $flags);
    }
    if (!is_string($encoded)) {
        throw new RuntimeException('Could not double-encode a canonical seed fixture JSON field.');
    }
    return $encoded;
}

function canonicalSeedJsonShape(array $definition, array $fields, string $form): array
{
    foreach ($fields as $field) {
        $decoded = canonicalSeedJsonDecode($definition[$field] ?? null);
        $definition[$field] = $form === 'decoded-array'
            ? $decoded
            : canonicalSeedJsonEncode($decoded, $form);
    }
    return $definition;
}

function assertCanonicalSeedJsonRepresentations(
    array $serviceClasses,
    $source,
    array $jsonFields,
    array $nullFields,
    string $reportName
): void {
    $definition = canonicalSeedJsonDefinition($source);
    foreach (['string', 'decoded-array', 'double-encoded'] as $form) {
        $shaped = canonicalSeedJsonShape($definition, $jsonFields, $form);
        foreach ($serviceClasses as $serviceClass) {
            if (!$serviceClass::isCanonicalSeedDefinition($shaped)) {
                throw new RuntimeException($reportName . ' rejected canonical JSON in ' . $form . ' form (' . $serviceClass . ').');
            }

            foreach ($jsonFields as $field) {
                $changed = $shaped;
                $value = canonicalSeedJsonDecode($changed[$field]);
                $value['__rpt027_changed'] = true;
                $changed[$field] = $form === 'decoded-array'
                    ? $value
                    : canonicalSeedJsonEncode($value, $form);
                if ($serviceClass::isCanonicalSeedDefinition($changed)) {
                    throw new RuntimeException($reportName . ' accepted changed ' . $field . ' in ' . $form . ' form (' . $serviceClass . ').');
                }
            }

            foreach ($jsonFields as $field) {
                $invalid = $shaped;
                $invalid[$field] = '{invalid json';
                if ($serviceClass::isCanonicalSeedDefinition($invalid)) {
                    throw new RuntimeException($reportName . ' accepted invalid JSON in ' . $field . ' (' . $serviceClass . ').');
                }
                $wrongType = $shaped;
                $wrongType[$field] = json_encode('not an object or array');
                if ($serviceClass::isCanonicalSeedDefinition($wrongType)) {
                    throw new RuntimeException($reportName . ' accepted a JSON scalar in ' . $field . ' (' . $serviceClass . ').');
                }
                $tooManyEncodings = $shaped;
                $value = canonicalSeedJsonDecode($tooManyEncodings[$field]);
                $tooManyEncodings[$field] = json_encode(canonicalSeedJsonEncode($value, 'double-encoded'));
                if ($serviceClass::isCanonicalSeedDefinition($tooManyEncodings)) {
                    throw new RuntimeException($reportName . ' accepted more than one extra JSON decode in ' . $field . ' (' . $serviceClass . ').');
                }
            }
        }
    }

    foreach ($serviceClasses as $serviceClass) {
        $wrongSqlType = $definition;
        $wrongSqlType['sql_template'] = ['SELECT 1'];
        if ($serviceClass::isCanonicalSeedDefinition($wrongSqlType)) {
            throw new RuntimeException($reportName . ' accepted a non-string SQL template (' . $serviceClass . ').');
        }

        foreach ($nullFields as $field) {
            $notNull = $definition;
            $notNull[$field] = '{}';
            if ($serviceClass::isCanonicalSeedDefinition($notNull)) {
                throw new RuntimeException($reportName . ' accepted non-NULL ' . $field . ' (' . $serviceClass . ').');
            }
            $missing = $definition;
            unset($missing[$field]);
            if ($serviceClass::isCanonicalSeedDefinition($missing)) {
                throw new RuntimeException($reportName . ' accepted missing NULL field ' . $field . ' (' . $serviceClass . ').');
            }
        }
    }
}

function canonicalSeedFieldFinderDefinition(): array
{
    $migration = (string) file_get_contents(__DIR__ . '/../../mysql/migrations/043_cataloging_marc_field_finder.sql');
    if (preg_match(
        "/VALUES \\(\n  'marc-field-indicator-content-finder',\n  '([^']+)',.*?\n  '(WITH target_instances AS MATERIALIZED \\(.*?LIMIT 100001)',\n  '(\\[.*?\\])',\n  'folio',\n  '(\\{.*?\\})',\n  100000,\n  1,\n  'manual'\n\\)/s",
        $migration,
        $matches
    ) !== 1) {
        throw new RuntimeException('Could not load the canonical MARC field finder seed.');
    }
    return [
        'slug' => 'marc-field-indicator-content-finder',
        'name' => $matches[1],
        'category' => 'cataloging',
        'sql_template' => str_replace("''", "'", $matches[2]),
        'parameters' => str_replace("''", "'", $matches[3]),
        'data_source' => 'folio',
        'execution_config' => str_replace("''", "'", $matches[4]),
        'default_limit' => 100000,
        'is_active' => 1,
        'created_by' => 'manual',
    ];
}

function canonicalSeedMissingTagDefinition(): array
{
    $report = new app\models\ReportTemplate();
    $migration = (string) file_get_contents(__DIR__ . '/../../mysql/migrations/042_cataloging_marc_multi_location.sql');
    if (preg_match(
        "/SET\n.*?  `sql_template` = '(WITH target_instances AS MATERIALIZED \\(.*?LIMIT 100001)',\n  `parameters` = '(\\[.*?\\])',\n/s",
        $migration,
        $matches
    ) !== 1) {
        throw new RuntimeException('Could not load the canonical MARC missing-tag seed.');
    }
    $report->sql_template = str_replace("''", "'", $matches[1]);
    $report->parameters = str_replace("''", "'", $matches[2]);
    return [
        'slug' => app\services\CatalogingMarcMissingTagReportService::REPORT_SLUG,
        'name' => 'MARC Bibliographic Records Missing a Tag',
        'category' => 'cataloging',
        'sql_template' => $report->sql_template,
        'parameters' => $report->parameters,
        'data_source' => 'folio',
        'execution_config' => '{"public_row_cap":100000,"fetch_row_limit":100001,"preserve_export_order":true,"identifier_export":{"source_column":"Instance UUID","header":"UUID"}}',
        'default_limit' => 100000,
        'is_active' => 1,
        'created_by' => 'manual',
    ];
}

$combination = marcCombinationFixtureSeed();
$budgetYear = budgetYearExpenseClassReportFixture();

$cases = [
    ['Acquisitions invoice status', [app\services\AcquisitionsInvoiceStatusReportService::class], acquisitionsInvoiceStatusReportFixture(), ['parameters', 'execution_config'], []],
    ['Acquisitions open orders', [app\services\AcquisitionsOpenOrdersReportService::class], acquisitionsOpenOrdersReportFixture(), ['parameters', 'execution_config'], []],
    ['Acquisitions missing unit', [
        app\services\AcquisitionsMissingUnitReportService::class,
        app\services\AcquisitionsMissingUnitReportMigrationService::class,
    ], acquisitionsMissingUnitReportSeed(), ['parameters', 'execution_config'], []],
    ['Budget year expense class', [app\services\BudgetYearExpenseClassReportService::class], $budgetYear, ['parameters', 'composite_config'], ['execution_config']],
    ['Cataloging holdings-less', [app\services\CatalogingHoldingslessReportService::class], holdingslessReportFixture(), ['parameters', 'execution_config'], []],
    ['Cataloging shared record', [app\services\CatalogingSharedRecordReportService::class], sharedRecordDefinition(sharedRecordReportFixture()), ['parameters', 'execution_config'], ['composite_config']],
    ['MARC combination finder', [app\services\CatalogingMarcCombinationFinderService::class], $combination, ['parameters', 'execution_config'], ['composite_config']],
    ['MARC field finder', [app\services\CatalogingMarcFieldFinderService::class], canonicalSeedFieldFinderDefinition(), ['parameters', 'execution_config'], []],
    ['MARC missing-tag finder', [app\services\CatalogingMarcMissingTagReportService::class], canonicalSeedMissingTagDefinition(), ['parameters', 'execution_config'], []],
];

foreach ($cases as $case) {
    assertCanonicalSeedJsonRepresentations($case[1], $case[2], $case[3], $case[4], $case[0]);
}

echo "PASS: canonical seed JSON accepts only the reviewed string, array, and double-encoded forms.\n";

}
