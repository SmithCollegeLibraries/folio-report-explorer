<?php
require_once __DIR__ . '/support/AcquisitionsOpenOrdersReportFixture.php';
require_once __DIR__ . '/support/GiftReportsMigrationFixture.php';
require_once __DIR__ . '/../services/SqlBuilderService.php';
require_once __DIR__ . '/../services/FolioSchemaService.php';

use app\models\ReportTemplate;
use app\services\SqlBuilderService;

function giftReportsAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

function giftReportsContains(string $needle, string $haystack, string $message): void
{
    giftReportsAssert(strpos($haystack, $needle) !== false, $message);
}

$cases = [
    ['2026-06-30 00:00:00', '2025-07-01', '2026-06-30', '2024-07-01', '2025-06-30'],
    ['2026-06-30 12:00:00', '2025-07-01', '2026-06-30', '2024-07-01', '2025-06-30'],
    ['2026-07-01 00:00:00', '2026-07-01', '2027-06-30', '2025-07-01', '2026-06-30'],
    ['2027-01-15 00:00:00', '2026-07-01', '2027-06-30', '2025-07-01', '2026-06-30'],
];
foreach ($cases as $case) {
    $clock = new DateTime($case[0]);
    giftReportsAssert(ReportTemplate::resolveDefaultMacro('$fiscal_year_start', $clock) === $case[1], 'Keep the current fiscal-year start correct for ' . $case[0] . '.');
    giftReportsAssert(ReportTemplate::resolveDefaultMacro('$fiscal_year_end', $clock) === $case[2], 'Keep the current fiscal-year end correct for ' . $case[0] . '.');
    giftReportsAssert(ReportTemplate::resolveDefaultMacro('$previous_fiscal_year_start', $clock) === $case[3], 'Resolve the previous fiscal-year start for ' . $case[0] . '.');
    giftReportsAssert(ReportTemplate::resolveDefaultMacro('$previous_fiscal_year_end', $clock) === $case[4], 'Resolve the previous fiscal-year end for ' . $case[0] . '.');
}

$definitions = giftReportsMigrationDefinitions();
giftReportsAssert(count($definitions) === 2, 'Seed exactly the one-time and ongoing gift reports.');
$migrationSource = file_get_contents(__DIR__ . '/../../mysql/migrations/070_gift_reports.sql');
giftReportsAssert(strpos($migrationSource, "START TRANSACTION;\n") === 0 && strpos($migrationSource, 'ON DUPLICATE KEY UPDATE') !== false, 'Install both report rows transactionally with upsert repair.');
$oneTime = $definitions['gift-items-one-time'] ?? [];
$ongoing = $definitions['gift-ongoing'] ?? [];
foreach ([
    'gift-items-one-time' => ['Gift items (one-time)', ['Title', 'Location', 'Call number', 'Barcode', 'Description', 'Format', 'Gift note', 'Accession date']],
    'gift-ongoing' => ['Ongoing gifts', ['Title', 'Location', 'Call number', 'Number of issues', 'Format']],
] as $slug => $expected) {
    $definition = $definitions[$slug] ?? [];
    giftReportsAssert(($definition['name'] ?? null) === $expected[0], 'Keep the canonical display name for ' . $slug . '.');
    giftReportsAssert(($definition['category'] ?? null) === 'acquisitions' && ($definition['data_source'] ?? null) === 'folio', 'Use acquisitions FOLIO execution for ' . $slug . '.');
    giftReportsAssert(($definition['default_limit'] ?? null) === 100000 && ($definition['is_active'] ?? null) === 1 && ($definition['created_by'] ?? null) === 'manual', 'Keep the canonical active/manual row settings for ' . $slug . '.');
    giftReportsAssert(($definition['execution_config'] ?? null) === null, 'Use ordinary report binding and export for ' . $slug . '.');
    $parameters = json_decode((string) ($definition['parameters'] ?? ''), true);
    giftReportsAssert(is_array($parameters) && count($parameters) === 2, 'Define two date parameters for ' . $slug . '.');
    giftReportsAssert(($parameters[0]['name'] ?? null) === 'startDate' && ($parameters[0]['type'] ?? null) === 'date' && !empty($parameters[0]['required']) && ($parameters[0]['default'] ?? null) === '$previous_fiscal_year_start', 'Bind the required previous-fiscal-year start for ' . $slug . '.');
    giftReportsAssert(($parameters[1]['name'] ?? null) === 'endDate' && ($parameters[1]['type'] ?? null) === 'date' && !empty($parameters[1]['required']) && ($parameters[1]['default'] ?? null) === '$previous_fiscal_year_end', 'Bind the required previous-fiscal-year end for ' . $slug . '.');
    preg_match_all('/\bAS\s+"([^"]+)"/i', (string) ($definition['sql_template'] ?? ''), $aliases);
    giftReportsAssert(($aliases[1] ?? []) === $expected[1], 'Return the required output aliases in order for ' . $slug . '.');
}

$oneTimeSql = (string) ($oneTime['sql_template'] ?? '');
$ongoingSql = (string) ($ongoing['sql_template'] ?? '');
giftReportsContains("(it2.metadata__created_date AT TIME ZONE 'America/New_York')::date BETWEEN CAST(:startDate AS date) AND CAST(:endDate AS date)", $oneTimeSql, 'Filter one-time gifts by inclusive Eastern item-created dates.');
giftReportsContains("(it.metadata__created_date AT TIME ZONE 'America/New_York')::date BETWEEN CAST(:startDate AS date) AND CAST(:endDate AS date)", $ongoingSql, 'Filter ongoing gifts by inclusive Eastern item-created dates.');
giftReportsContains('itn.notes__note, it2.metadata__created_date', $oneTimeSql, 'Keep the one-time grouping on the raw item timestamp.');
giftReportsContains('COUNT(DISTINCT it.hrid) AS "Number of issues"', $ongoingSql, 'Count distinct item HRIDs for ongoing gifts.');
foreach ([
    [$oneTimeSql, "itn.notes__item_note_type_id = 'a28539e9-21ce-40f1-9b64-b1faf4e189c0'", 'Filter on the Gift note type.'],
    [$oneTimeSql, "lt.campus_id = '7d02e46d-3e60-4eaf-a986-5aa3550e8cb5'", 'Filter to the Smith College campus.'],
    [$oneTimeSql, 'itn.effective_location_id = lt.id', 'Use the effective item location.'],
    [$ongoingSql, 'orders.pieces__t pt ON it.id = pt.item_id', 'Link ongoing items through order pieces.'],
    [$ongoingSql, "plt.acquisition_method = '0a4163a5-d225-4007-ad90-2fb41b73efab'", 'Filter on the Gift acquisition method.'],
    [$ongoingSql, "potaui.acq_unit_ids = 'b17b9e6b-82bb-4f97-b3e7-757e4e5aeb61'", 'Filter purchase orders to acquisitions unit SC.'],
    [$oneTimeSql, 'ORDER BY it.title', 'Sort one-time gifts by Title.'],
    [$ongoingSql, 'ORDER BY it2.title', 'Sort ongoing gifts by Title.'],
] as $marker) {
    giftReportsContains($marker[1], $marker[0], $marker[2]);
}
giftReportsAssert(stripos($oneTimeSql . $ongoingSql, 'substring(') === false, 'Use timezone-aware timestamp casts instead of substring date handling.');
giftReportsAssert(stripos($ongoingSql, 'receipt_status') === false && stripos($ongoingSql, 'order_type') === false, 'Do not filter ongoing gifts by receipt status or order type.');
foreach ([
    [$oneTime, 'Gift note', 'Smith College campus', 'item record created date', 'Eastern time'],
    [$ongoing, 'items created in the selected date range', 'order pieces', 'Gift acquisition method', 'acquisitions unit SC', 'distinct item HRIDs', 'no receipt status or order type filter'],
] as $helpCase) {
    $copy = (string) ($helpCase[0]['description'] ?? '') . ' ' . (string) ($helpCase[0]['help_text'] ?? '');
    foreach (array_slice($helpCase, 1) as $phrase) {
        giftReportsContains($phrase, $copy, 'Describe the real report rule: ' . $phrase . '.');
    }
    giftReportsContains('inclusive', $copy, 'State that report dates are inclusive.');
}

foreach ([$oneTime, $ongoing] as $definition) {
    SqlBuilderService::validateSafety((string) $definition['sql_template']);
    SqlBuilderService::validateTablePolicy((string) $definition['sql_template']);
}

$report = new ReportTemplate();
$report->parameters = $oneTime['parameters'];
$report->sql_template = $oneTime['sql_template'];
$report->default_limit = $oneTime['default_limit'];
$apiParameters = $report->toDetailArray()['parameters'];
giftReportsAssert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $apiParameters[0]['resolvedDefault'] ?? '') === 1, 'Expose resolved dates to the API instead of macro tokens.');
$bound = $report->bindParams([]);
giftReportsAssert($bound['params'][':startDate'] === $apiParameters[0]['resolvedDefault'] && $bound['params'][':endDate'] === $apiParameters[1]['resolvedDefault'], 'Bind omitted dates using the same resolved API defaults.');
$explicit = $report->bindParams(['startDate' => '2024-07-01', 'endDate' => '2025-06-30']);
giftReportsAssert($explicit['params'][':startDate'] === '2024-07-01' && $explicit['params'][':endDate'] === '2025-06-30', 'Pass explicit report dates through unchanged.');
$malformed = $report->bindParams(['startDate' => 'not-a-date', 'endDate' => '2024-07-01']);
giftReportsAssert($malformed['params'][':startDate'] === 'not-a-date', 'Leave malformed dates to the existing PostgreSQL cast error.');
$reversed = $report->bindParams(['startDate' => '2025-06-30', 'endDate' => '2024-07-01']);
giftReportsAssert($reversed['params'][':startDate'] === '2025-06-30' && $reversed['params'][':endDate'] === '2024-07-01', 'Keep reversed dates as bound values for the inclusive BETWEEN to return no rows.');

echo "PASS: Gift report SQL, safety, dates, and defaults\n";
