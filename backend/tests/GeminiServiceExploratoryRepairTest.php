<?php

namespace yii\httpclient {
    class Client
    {
        public $transport;

        public function createRequest()
        {
            return new Request();
        }
    }

    class Request
    {
        private $content = '';

        public function setMethod($method) { return $this; }
        public function setUrl($url) { return $this; }
        public function setHeaders($headers) { return $this; }
        public function addOptions($options) { return $this; }

        public function setContent($content)
        {
            $this->content = (string)$content;
            return $this;
        }

        public function send()
        {
            TestTransport::$requests[] = json_decode($this->content, true);
            $text = array_shift(TestTransport::$responses);
            if ($text === null) {
                throw new \RuntimeException('No queued Gemini response.');
            }
            return new Response($text);
        }
    }

    class Response
    {
        public $isOk = true;
        public $statusCode = 200;
        public $content;

        public function __construct(string $text)
        {
            $this->content = json_encode([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => $text]]],
                ]],
            ]);
        }
    }

    class TestTransport
    {
        public static $responses = [];
        public static $requests = [];
    }
}

namespace app\services {
    class ReferenceResolverService
    {
        public static function resolvePrompt(string $prompt, $userId = null): array
        {
            return ['needsClarification' => false, 'guidanceLines' => []];
        }

        public static function appendGuidanceToPrompt(string $prompt, array $resolution): string
        {
            return $prompt;
        }
    }

    class FolioSchemaService
    {
        public static function buildSchemaContext($prompt = null): string
        {
            return "SCOPED SCHEMA\ninventory.item__t(id, holdings_record_id)";
        }

        public static function getMetadata(): array { return ['scraped_at' => 'test']; }
        public static function getTableNames(): array
        {
            return [
                'item__t', 'po_line__t', 'purchase_order__t', 'invoice_lines__t',
                'audit_loan__t', 'holdings_record__t', 'instance__t', 'classification__t',
            ];
        }
        public static function discoverTableMapping(): array
        {
            return [
                'item__t' => 'inventory.item__t',
                'po_line__t' => 'orders.po_line__t',
                'purchase_order__t' => 'orders.purchase_order__t',
                'purchase_order__t__acq_unit_ids' => 'orders.purchase_order__t__acq_unit_ids',
                'acquisitions_unit__t' => 'orders.acquisitions_unit__t',
                'pieces__t' => 'orders.pieces__t',
                'invoice_lines__t' => 'invoice.invoice_lines__t',
                'invoice_lines__t__fund_distributions' => 'invoice.invoice_lines__t__fund_distributions',
                'invoices__t' => 'invoice.invoices__t',
                'audit_loan__t' => 'circulation.audit_loan__t',
                'holdings_record__t' => 'inventory.holdings_record__t',
                'instance__t' => 'inventory.instance__t',
                'location__t' => 'inventory.location__t',
                'loclibrary__t' => 'inventory.loclibrary__t',
                'loccampus__t' => 'inventory.loccampus__t',
                'classification__t' => 'classification.classification__t',
            ];
        }

        public static function fuzzyMatch($table)
        {
            $name = strtolower((string)$table);
            if (strpos($name, '.') !== false) {
                $parts = explode('.', $name);
                $name = end($parts);
            }
            return in_array($name, ['item__t', 'items'], true) ? 'item__t' : null;
        }
    }

    class SqlBuilderService
    {
        public static $blockPolicy = false;

        public static function validateSafety($sql): void
        {
            if (strpos((string)$sql, 'invalid_repair_shape') !== false) {
                throw new \InvalidArgumentException('Only a single SELECT statement is allowed.');
            }
            if (preg_match('/^\s*SELECT\b|^\s*WITH\b/i', (string)$sql) !== 1) {
                throw new \InvalidArgumentException('Only SELECT queries are allowed.');
            }
        }

        public static function validateTablePolicy($sql): void
        {
            if (self::$blockPolicy) {
                throw new \app\exceptions\PolicyViolationException('Blocked by reporting policy.');
            }
            if (preg_match('/\bFROM\s+users\.users__t\b/i', (string)$sql) === 1) {
                throw new \app\exceptions\PolicyViolationException('Blocked users table.');
            }
        }
    }
}

namespace {
if (!defined('CURLOPT_TIMEOUT')) {
    define('CURLOPT_TIMEOUT', 13);
}

class Yii
{
    public static $app;
    public static $logs = [];
    public static $aliases = [];

    public static function getAlias($alias)
    {
        if ($alias === '@app/data/reference_cache.json') {
            return __DIR__ . '/../data/reference_cache.json';
        }
        return self::$aliases[$alias] ?? (__DIR__ . '/../data/settings.json');
    }
    public static function info($message, $category = null) { self::$logs[] = ['level' => 'info', 'message' => $message, 'category' => $category]; }
    public static function warning($message, $category = null) { self::$logs[] = ['level' => 'warning', 'message' => $message, 'category' => $category]; }
}

Yii::$app = (object)['params' => [
    'aiProvider' => 'gemini',
    'geminiApiKey' => 'test-key',
    'geminiModel' => 'test-model',
    'geminiMaxRetries' => 1,
    'nl2sqlForceLegacy' => false,
    'nl2sqlHardenedPhysicalRoi' => true,
]];

require_once __DIR__ . '/../exceptions/PolicyViolationException.php';
require_once __DIR__ . '/../services/QueryFamilyContractService.php';
require_once __DIR__ . '/../services/GeminiService.php';

use app\exceptions\PolicyViolationException;
use app\exceptions\ExploratorySqlValidationException;
use app\services\GeminiService;
use app\services\SqlBuilderService;
use yii\httpclient\TestTransport;

function repairAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function repairAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: {$needle}\nActual: {$haystack}\n");
        exit(1);
    }
}

function terminalTelemetryOutcomes(): array
{
    $outcomes = [];
    foreach (Yii::$logs as $logRecord) {
        $message = (string)($logRecord['message'] ?? '');
        if (strpos($message, 'NL2SQL telemetry: ') !== 0) {
            continue;
        }
        $record = json_decode(substr($message, strlen('NL2SQL telemetry: ')), true);
        if (($record['event'] ?? null) === 'nl2sql.exploratory_terminal_outcome') {
            $outcomes[] = $record;
        }
    }
    return $outcomes;
}

function geminiText(string $sql, string $explanation = 'Candidate query.'): string
{
    return "```sql\n{$sql}\n```\n{$explanation}\nDATA SOURCE: folio";
}

function roiPrompt(): string
{
    return 'Show ROI for purchases and circulation by call number, including checkouts and investment.';
}

function semanticallyFlawedRoiSql(): string
{
    return <<<'SQL'
SELECT pc.call_number_class,
       TO_CHAR(SUM(ilt.total), 'FM$999,999,990.00') AS total_spent,
       COUNT(DISTINCT pol.id) AS purchase_count,
       TO_CHAR(SUM(ilt.total) / NULLIF(COUNT(al.id), 0), 'FM$999,999,990.00') AS cost_per_checkout
FROM orders.po_line__t pol
JOIN orders.purchase_order__t pot ON pot.id = pol.purchase_order_id
JOIN invoice.invoice_lines__t ilt ON ilt.po_line_id = pol.id
JOIN inventory.item__t item ON item.material_type_id = 'book'
LEFT JOIN circulation.audit_loan__t al ON al.loan__item_id = item.id
JOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id
JOIN inventory.instance__t instance ON instance.id = holdings.instance_id
JOIN classification.classification__t pc ON pc.instance_id = instance.id
WHERE pot.date_ordered >= CURRENT_DATE - INTERVAL '5 years'
  AND item.material_type_id = 'book'
GROUP BY pc.call_number_class
SQL;
}

TestTransport::$responses = [
    geminiText('SELECT mt.id FROM inventory.missing_table__t mt'),
    geminiText('SELECT ii.id FROM inventory.item__t ii'),
];
TestTransport::$requests = [];
Yii::$logs = [];

$repaired = GeminiService::generateSqlWithShadow('Show item identifiers for inventory.', 'Smith College', null, true);
repairAssertSame('SELECT ii.id FROM inventory.item__t ii', $repaired['sql'] ?? null, 'A bad initial table should be replaced by the valid repair candidate.');
repairAssertSame(1, $repaired['repairAttempts'] ?? null, 'One automatic repair should be reported.');
repairAssertSame('SELECT mt.id FROM inventory.missing_table__t mt', $repaired['_askEvidence']['initialSql'] ?? null, 'Gemini exploratory repair must retain the genuine pre-repair candidate.');
repairAssertSame('SELECT ii.id FROM inventory.item__t ii', $repaired['_askEvidence']['finalSql'] ?? null, 'Gemini exploratory repair must retain the validated final candidate.');
repairAssertSame(1, $repaired['_askEvidence']['repairAttempts'] ?? null, 'Gemini exploratory repair evidence must retain the actual repair count.');
repairAssertSame('test-model', $repaired['_askEvidence']['modelName'] ?? null, 'Gemini generation must propagate the trusted configured model name.');
repairAssertSame(GeminiService::LEGACY_PROMPT_VERSION, $repaired['_askEvidence']['promptVersion'] ?? null, 'Gemini generation must propagate the trusted prompt version.');
repairAssertSame('test', $repaired['_askEvidence']['schemaMetadata']['version'] ?? null, 'Gemini generation must propagate the trusted schema version.');
repairAssertSame('2026-06-11T15:41:49+00:00', $repaired['_askEvidence']['referenceBundleMetadata']['version'] ?? null, 'Gemini generation must propagate the server-side reference bundle version.');
repairAssertSame(64, strlen((string)($repaired['_askEvidence']['referenceBundleMetadata']['hash'] ?? '')), 'Gemini generation must propagate a SHA-256 reference bundle hash.');
repairAssertSame('validated', $repaired['validationSummary']['status'] ?? null, 'Successful exploratory SQL should be marked validated.');
repairAssertSame(0, count($repaired['assumptions'] ?? []), 'Unrelated exploratory requests should not receive ROI assumptions.');
repairAssertSame(false, isset($repaired['semanticValidation']), 'Non-applicable exploratory requests should not display a false semantic checklist.');
repairAssertSame(2, count(TestTransport::$requests), 'Bad-then-valid generation should make one initial request and one repair request.');

$repairPayload = json_encode(TestTransport::$requests[1]);
repairAssertContains('SELECT mt.id FROM inventory.missing_table__t mt', $repairPayload, 'The repair request should contain the previous SQL candidate.');
repairAssertContains('unknown_table', $repairPayload, 'The repair request should contain the safe validation category.');
repairAssertContains('None documented.', $repairPayload, 'The repair request should safely represent absent assumptions.');
repairAssertContains('SCOPED SCHEMA', $repairPayload, 'The repair request should contain fresh scoped schema context.');
repairAssertContains('Smith College', $repairPayload, 'The repair request should explicitly preserve the separately supplied campus.');
repairAssertContains('Never include a second SQL statement', $repairPayload, 'The repair response contract should explicitly forbid alternate statements.');
repairAssertContains('one ```sql code block', $repairPayload, 'The repair response contract should require the parser-supported fenced SQL shape.');

$telemetry = implode("\n", array_map(function ($record) { return (string)$record['message']; }, Yii::$logs));
repairAssertSame(false, strpos($telemetry, 'SELECT mt.id FROM inventory.missing_table__t mt') !== false, 'Telemetry must not contain raw candidate SQL.');
repairAssertSame(false, strpos($telemetry, roiPrompt()) !== false, 'Telemetry must not contain the raw user prompt.');
$repairTelemetryCount = 0;
foreach (Yii::$logs as $logRecord) {
    if (strpos((string)$logRecord['message'], 'NL2SQL telemetry: ') !== 0) {
        continue;
    }
    $record = json_decode(substr((string)$logRecord['message'], strlen('NL2SQL telemetry: ')), true);
    if (strpos((string)($record['event'] ?? ''), 'nl2sql.exploratory_repair_') !== 0) {
        continue;
    }
    $repairTelemetryCount++;
    foreach (['promptFingerprint', 'route', 'routeReason', 'phase', 'repairNumber', 'maximumRepairs', 'stage', 'category', 'candidateLength', 'provider', 'elapsedMs', 'assumptionKeys', 'outcome'] as $field) {
        repairAssertSame(true, array_key_exists($field, $record), "Exploratory repair telemetry should include {$field}.");
    }
    repairAssertSame(false, array_key_exists('sql', $record), 'Exploratory repair telemetry must not expose a SQL field.');
    repairAssertSame(false, array_key_exists('prompt', $record), 'Exploratory repair telemetry must not expose a prompt field.');
}
repairAssertSame(4, $repairTelemetryCount, 'Bad-then-valid generation should emit attempt and outcome telemetry for both candidates.');
$terminalOutcomes = terminalTelemetryOutcomes();
repairAssertSame(0, count($terminalOutcomes), 'Static/model validation must not emit terminal validated before database preflight.');

TestTransport::$responses = [
    geminiText("SELECT inst.hrid AS instance_hrid, inst.title, item.barcode FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid = 'in0001' LIMIT 20"),
    geminiText("SELECT inst.hrid AS instance_hrid, inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001', 'in0002') LIMIT 20"),
];
TestTransport::$requests = [];
Yii::$logs = [];
$explicitValuesResult = GeminiService::generateSqlWithShadow(
    'For instance numbers in0001, in0002, show title, barcode, and publication date. Limit 20.',
    null,
    null,
    true
);
repairAssertSame(1, $explicitValuesResult['repairAttempts'] ?? null, 'An explicit-value omission must enter the existing bounded repair path.');
repairAssertContains('EXPLICIT REPORT VALUES', json_encode(TestTransport::$requests[0]), 'The generation prompt must include server-authored explicit-value guidance.');
repairAssertSame(['in0001', 'in0002'], $explicitValuesResult['_askEvidence']['explicitReportRequest']['identifiers']['instance_hrid'] ?? null, 'Server-extracted explicit identifiers must remain trusted Ask evidence.');
repairAssertSame(['title', 'barcode', 'publication_date'], $explicitValuesResult['_askEvidence']['explicitReportRequest']['requestedFields'] ?? null, 'Server-extracted requested fields must remain trusted Ask evidence.');

Yii::$logs = [];
$logValidationFailure = new ReflectionMethod(GeminiService::class, 'logValidationFailure');
$logValidationFailure->invoke(null, 'legacy_sql_parse', [
    'route' => 'legacy_freeform',
    'routeReason' => 'forced_legacy_mode',
    'error' => 'SQLSTATE[42P01] relation inventory.secret_table__t does not exist; PDO driver exception',
]);
$parserTelemetry = implode("\n", array_map(function ($record) { return (string)$record['message']; }, Yii::$logs));
foreach (['SQLSTATE', '42P01', 'inventory.secret_table__t', 'PDO driver exception'] as $unsafeFragment) {
    repairAssertSame(false, stripos($parserTelemetry, $unsafeFragment) !== false, 'Parser telemetry must contain only a safe failure category.');
}
repairAssertContains('parser_failure', $parserTelemetry, 'Parser telemetry should retain a stable safe category.');

TestTransport::$responses = [
    geminiText('WITH recent AS (SELECT ii.id FROM inventory.item__t ii) SELECT recent.id FROM recent'),
];
$cteResult = GeminiService::generateSqlWithShadow('Show recent item identifiers from a derived set.', null, null, true);
repairAssertSame(0, $cteResult['repairAttempts'] ?? null, 'A CTE alias should not be treated as an unknown physical table.');

TestTransport::$responses = [
    geminiText('WITH c AS (SELECT id FROM inventory.item__t) SELECT c.id, c.name FROM c'),
];
$cteWithOuterProjection = GeminiService::generateSqlWithShadow('Show identifiers and names from a derived item set.', null, null, true);
repairAssertSame(0, $cteWithOuterProjection['repairAttempts'] ?? null, 'An outer SELECT projection comma must not be treated as an inner CTE relation separator.');

foreach (['MATERIALIZED', 'NOT MATERIALIZED'] as $materialization) {
    TestTransport::$responses = [
        geminiText("WITH recent(id) AS {$materialization} (SELECT ii.id FROM inventory.item__t ii) SELECT recent.id FROM recent"),
    ];
    $cteWithColumns = GeminiService::generateSqlWithShadow('Show item identifiers from a named derived set.', null, null, true);
    repairAssertSame(0, $cteWithColumns['repairAttempts'] ?? null, "A CTE column list with {$materialization} should be recognized.");
}

TestTransport::$responses = [
    geminiText('SELECT ii.id FROM "inventory"."item__t" ii'),
];
$quotedPhysicalTable = GeminiService::generateSqlWithShadow('Show quoted item identifiers.', null, null, true);
repairAssertSame(0, $quotedPhysicalTable['repairAttempts'] ?? null, 'An exact quoted physical table should validate.');

$subtableCachePath = tempnam(sys_get_temp_dir(), 'exploratory-subtables-');
file_put_contents($subtableCachePath, json_encode([
    'subtables' => [
        'invoice.invoice_lines__t__fund_distributions' => [
            'parent' => 'invoice.invoice_lines__t',
            'columns' => [['name' => 'id'], ['name' => 'total']],
        ],
    ],
]));
Yii::$aliases['@app/data/subtable_cache.json'] = $subtableCachePath;
TestTransport::$responses = [
    geminiText('SELECT fd.id FROM invoice.invoice_lines__t__fund_distributions fd'),
];
$cachedSubtable = GeminiService::generateSqlWithShadow('Show paid invoice fund distributions.', null, null, true);
repairAssertSame(0, $cachedSubtable['repairAttempts'] ?? null, 'An exact physical subtable from the discovered subtable cache should validate without a repair.');
unset(Yii::$aliases['@app/data/subtable_cache.json']);
unlink($subtableCachePath);

TestTransport::$responses = [
    geminiText('WITH "Recent Items"("id") AS (SELECT ii.id FROM "inventory"."item__t" ii) SELECT r.id FROM "Recent Items" r'),
];
$quotedCte = GeminiService::generateSqlWithShadow('Show item identifiers from a quoted CTE.', null, null, true);
repairAssertSame(0, $quotedCte['repairAttempts'] ?? null, 'A quoted CTE alias should not be treated as a physical table.');

TestTransport::$responses = [
    geminiText('SELECT u.id FROM "users"."users__t" u'),
];
$requestCount = count(TestTransport::$requests);
try {
    GeminiService::generateSqlWithShadow('Show restricted user identifiers.', null, null, true);
    fwrite(STDERR, "A quoted blocked table must be a policy hard stop.\n");
    exit(1);
} catch (PolicyViolationException $exception) {
    repairAssertSame($requestCount + 1, count(TestTransport::$requests), 'A quoted blocked table should make no repair request.');
}

TestTransport::$responses = [
    geminiText('SELECT mt.id FROM "inventory"."missing_table__t" mt'),
    geminiText('SELECT ii.id FROM "inventory"."item__t" ii'),
];
$unknownQuotedTable = GeminiService::generateSqlWithShadow('Show identifiers from quoted inventory tables.', null, null, true);
repairAssertSame(1, $unknownQuotedTable['repairAttempts'] ?? null, 'An unknown quoted physical table must be rejected and repaired.');
repairAssertSame('SELECT ii.id FROM "inventory"."item__t" ii', $unknownQuotedTable['sql'] ?? null, 'Quoted-table repair should use an exact physical table.');

TestTransport::$responses = [
    geminiText("SELECT kv.key FROM jsonb_each('{}'::jsonb) kv"),
];
$tableFunction = GeminiService::generateSqlWithShadow('Expand a JSON object into key/value rows.', null, null, true);
repairAssertSame(0, $tableFunction['repairAttempts'] ?? null, 'A set-returning table function should not be validated as a physical table.');

TestTransport::$responses = [
    geminiText("SELECT kv.key FROM jsonb_each_text(jsonb_build_object('a', 1, 'b', 2)) kv"),
];
$multiArgumentTableFunction = GeminiService::generateSqlWithShadow('Expand a constructed JSON object.', null, null, true);
repairAssertSame(0, $multiArgumentTableFunction['repairAttempts'] ?? null, 'Commas inside a table function must not be treated as relation separators.');

foreach ([
    'SELECT EXTRACT(YEAR FROM ii.created_date) AS created_year FROM inventory.item__t ii',
    "SELECT TRIM(BOTH ' ' FROM ii.title) AS clean_title FROM inventory.item__t ii",
    "SELECT 'FROM inventory.missing_table__t JOIN inventory.other_missing__t' AS note FROM inventory.item__t ii",
    'SELECT ii.id /* FROM inventory.missing_table__t */ FROM inventory.item__t ii -- JOIN inventory.other_missing__t',
] as $sqlWithInertRelationText) {
    TestTransport::$responses = [geminiText($sqlWithInertRelationText)];
    $contextAwareResult = GeminiService::generateSqlWithShadow('Show item data using PostgreSQL expressions.', null, null, true);
    repairAssertSame(0, $contextAwareResult['repairAttempts'] ?? null, 'Expression, string, and comment FROM/JOIN text must not be treated as physical relations.');
}

TestTransport::$responses = [
    geminiText('SELECT ii.id FROM inventory.item__t ii, inventory.missing_table__t mt'),
    geminiText('SELECT ii.id FROM inventory.item__t ii'),
];
$commaSeparatedTable = GeminiService::generateSqlWithShadow('Show identifiers across comma-separated inventory relations.', null, null, true);
repairAssertSame(1, $commaSeparatedTable['repairAttempts'] ?? null, 'An unknown later comma-separated physical table must be rejected.');
repairAssertSame('SELECT ii.id FROM inventory.item__t ii', $commaSeparatedTable['sql'] ?? null, 'Comma-separated relation repair should return validated SQL.');

TestTransport::$responses = [
    geminiText('SELECT items.id FROM items'),
    geminiText('SELECT ii.id FROM inventory.item__t ii'),
];
$inexactTable = GeminiService::generateSqlWithShadow('Show item identifiers.', null, null, true);
repairAssertSame(1, $inexactTable['repairAttempts'] ?? null, 'A fuzzy table suffix must not be accepted as a physical table name.');
repairAssertSame('SELECT ii.id FROM inventory.item__t ii', $inexactTable['sql'] ?? null, 'An inexact physical table should be replaced by an exact schema name.');

TestTransport::$responses = [
    geminiText('SELECT mt.id FROM inventory.missing_table__t mt'),
    geminiText("SELECT 'invalid_repair_shape' AS marker FROM inventory.item__t ii"),
    geminiText('SELECT ii.id FROM inventory.item__t ii'),
];
TestTransport::$requests = [];
$invalidRepairShape = GeminiService::generateSqlWithShadow('Show item identifiers.', null, null, true);
repairAssertSame('SELECT ii.id FROM inventory.item__t ii', $invalidRepairShape['sql'] ?? null, 'An invalid repair response should not prevent the remaining repair attempt from returning validated SQL.');
repairAssertSame(2, $invalidRepairShape['repairAttempts'] ?? null, 'An invalid repair response should consume one repair attempt and continue within the shared budget.');
repairAssertSame(3, count(TestTransport::$requests), 'The invalid repair scenario should consume its initial request and both repair requests.');
repairAssertContains('multiple_statements', json_encode(TestTransport::$requests[count(TestTransport::$requests) - 1]), 'The remaining repair should receive only a safe multiple-statement category.');

foreach ([
    'DELETE FROM inventory.item__t',
    "Here is the requested statement:\nDELETE FROM inventory.item__t",
    "-- generated statement\nDELETE FROM inventory.item__t",
    "/* generated statement */\nDELETE FROM inventory.item__t",
] as $destructiveResponse) {
    TestTransport::$responses = [$destructiveResponse];
    $requestCount = count(TestTransport::$requests);
    try {
        GeminiService::generateSqlWithShadow('Remove obsolete items.', null, null, true);
        fwrite(STDERR, "An unfenced destructive response must be a hard stop.\n");
        exit(1);
    } catch (ExploratorySqlValidationException $exception) {
        repairAssertSame('non_select', $exception->getSafeCategory(), 'An unfenced destructive response should retain a non-SELECT safety category.');
        repairAssertSame(false, $exception->isRepairable(), 'An unfenced destructive response must not be repairable.');
        repairAssertSame($requestCount + 1, count(TestTransport::$requests), 'An unfenced destructive response should make no repair request.');
    }
}

TestTransport::$responses = [
    geminiText('SELECT a.id FROM inventory.missing_a__t a'),
    geminiText('SELECT b.id FROM inventory.missing_b__t b'),
    geminiText('SELECT c.id FROM inventory.missing_c__t c'),
];
Yii::$logs = [];
$exhaustedPrompt = 'Compare identifiers across unsupported inventory relations.';
$exhausted = GeminiService::generateSqlWithShadow($exhaustedPrompt, 'Smith College', null, true);
repairAssertSame(false, isset($exhausted['sql']), 'Exhausted recovery must not return unvalidated SQL.');
repairAssertSame('exploratory_recovery', $exhausted['route'] ?? null, 'Exhausted recovery should use the safe recovery route.');
repairAssertSame(2, $exhausted['repairAttempts'] ?? null, 'Exhaustion should consume exactly two repair attempts.');
repairAssertSame($exhaustedPrompt, $exhausted['recoveryContext']['originalQuestion'] ?? null, 'Recovery should retain the original question for an explicit retry.');
repairAssertSame('unknown_table', $exhausted['validationSummary']['failureCategory'] ?? null, 'Recovery should expose only the safe failure category.');
repairAssertSame('exhausted', terminalTelemetryOutcomes()[0]['outcome'] ?? null, 'Repair exhaustion should emit a terminal exhausted outcome.');

$explicitRecoveryPrompt = 'For instance numbers in0001, in0002, show title, barcode, and publication date. Limit 20.';
TestTransport::$responses = [
    geminiText("SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20"),
    geminiText("SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20"),
    geminiText("SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20"),
];
Yii::$logs = [];
$explicitRecovery = GeminiService::generateSqlWithShadow($explicitRecoveryPrompt, null, null, true);
repairAssertSame(false, isset($explicitRecovery['sql']), 'Exhausted explicit-value generation must not return an invalid SQL candidate.');
repairAssertSame($explicitRecoveryPrompt, $explicitRecovery['recoveryContext']['originalQuestion'] ?? null, 'Recovery context must retain the raw user question, not server guidance.');
repairAssertSame(false, strpos(json_encode($explicitRecovery), 'EXPLICIT REPORT VALUES') !== false, 'Exhausted ordinary responses must not expose server-authored explicit-value guidance.');
repairAssertSame(false, strpos(json_encode($explicitRecovery), 'SQL filter') !== false, 'Exhausted ordinary responses must not expose SQL-oriented repair guidance.');

$routedExplicitPrompt = 'For instance numbers in0001, in0002, show title, barcode, and publication date. Limit 20.';
$routedReferencePrompt = $routedExplicitPrompt
    . "\n\nReference resolver guidance:\n"
    . "- Resolved local reference: use exactly inventory.material_type__t.name = 'E-Book'. "
    . 'Do not apply this value to library or campus name columns.';
$routedEffectivePrompt = \app\services\ExplicitReportRequestService::appendGuidance(
    $routedReferencePrompt,
    \app\services\ExplicitReportRequestService::extract($routedExplicitPrompt)
);
TestTransport::$responses = [
    geminiText("SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20"),
    geminiText("SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20"),
];
TestTransport::$requests = [];
Yii::$logs = [];
$routedRepair = new ReflectionMethod(GeminiService::class, 'repairRoutedCandidateMissingExplicitValues');
$routedExhausted = $routedRepair->invoke(
    null,
    [
        'sql' => "SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20",
        'route' => 'builder_intent',
        'routeReason' => 'family_contract_supported:inventory_library_location_listing',
    ],
    $routedEffectivePrompt,
    null,
    $routedExplicitPrompt
);
repairAssertSame(2, $routedExhausted['repairAttempts'] ?? null, 'Routed-family explicit repair exhaustion must preserve the shared two-attempt maximum.');
repairAssertSame(false, isset($routedExhausted['sql']), 'Routed-family explicit repair exhaustion must not return invalid SQL.');
repairAssertSame($routedExplicitPrompt, $routedExhausted['recoveryContext']['originalQuestion'] ?? null, 'Routed-family exhaustion must retain only the raw user question.');
repairAssertSame(false, strpos(json_encode($routedExhausted), 'EXPLICIT REPORT VALUES') !== false, 'Routed-family exhaustion must not expose server guidance.');
repairAssertSame(false, strpos(json_encode($routedExhausted), 'Reference resolver guidance') !== false, 'Routed-family exhaustion must not expose resolver schema guidance.');
repairAssertSame(false, strpos(json_encode($routedExhausted), 'explicitReportRequest') !== false, 'Routed-family exhaustion must not expose internal explicit-value keys.');
$routedRepairPayload = json_encode(TestTransport::$requests[0] ?? []);
repairAssertContains('Reference resolver guidance', $routedRepairPayload, 'Routed-family repair must retain resolver guidance as model-only generation context.');
repairAssertContains('EXPLICIT REPORT VALUES', $routedRepairPayload, 'Routed-family repair must retain explicit-value guidance as model-only generation context.');

TestTransport::$responses = [
    geminiText(semanticallyFlawedRoiSql()),
    geminiText(semanticallyFlawedRoiSql()),
    geminiText(semanticallyFlawedRoiSql()),
];
TestTransport::$requests = [];
$compiledFallback = GeminiService::generateSqlWithShadow(roiPrompt(), 'Smith College', null, true);
repairAssertSame('validated', $compiledFallback['validationSummary']['status'] ?? null, 'Semantic exhaustion for the documented ROI contract should use validated deterministic SQL.');
repairAssertSame(2, $compiledFallback['repairAttempts'] ?? null, 'The deterministic fallback should preserve the exhausted model repair count.');
repairAssertSame('physical_roi_v2', $compiledFallback['compilerVersion'] ?? null, 'Semantic exhaustion should use the hardened compiler.');
repairAssertContains('physical_copies_purchased', $compiledFallback['sql'] ?? '', 'The hardened fallback should return physical-copy measures.');
repairAssertSame(3, count(TestTransport::$requests), 'The fallback must run only after the initial candidate and two repairs.');

SqlBuilderService::$blockPolicy = true;
TestTransport::$responses = [geminiText('SELECT ii.id FROM inventory.item__t ii')];
Yii::$logs = [];
try {
    GeminiService::generateSqlWithShadow('Show restricted patron details.', null, null, true);
    fwrite(STDERR, "Policy violations must not be repaired.\n");
    exit(1);
} catch (PolicyViolationException $exception) {
    repairAssertSame(0, count(TestTransport::$responses), 'A policy hard stop should make no retry request.');
    repairAssertSame('policy_blocked', terminalTelemetryOutcomes()[0]['outcome'] ?? null, 'Policy hard stops should emit a terminal policy-blocked outcome.');
}
SqlBuilderService::$blockPolicy = false;

foreach ([
    'SQLSTATE[57014]: Query canceled: canceling statement due to user request',
    'SQLSTATE[57014]: Query canceled: canceling statement due to statement timeout',
] as $cancellationError) {
    TestTransport::$responses = [];
    Yii::$logs = [];
    $requestCount = count(TestTransport::$requests);
    try {
        GeminiService::repairExploratorySqlAfterPreflight(
            'Show items',
            null,
            ['sql' => 'SELECT id FROM inventory.item__t', 'repairAttempts' => 0],
            $cancellationError
        );
        fwrite(STDERR, "PostgreSQL cancellation must not be repaired.\n");
        exit(1);
    } catch (\app\exceptions\DatabaseQueryCancelledException $exception) {
        repairAssertSame($requestCount, count(TestTransport::$requests), 'PostgreSQL cancellation should make no repair request.');
        repairAssertSame('cancelled', terminalTelemetryOutcomes()[0]['outcome'] ?? null, 'Database cancellation should emit a terminal cancelled outcome.');
    }
}

TestTransport::$responses = [];
$requestCount = count(TestTransport::$requests);
try {
    Yii::$logs = [];
    GeminiService::repairExploratorySqlAfterPreflight(
        'Show items',
        null,
        ['sql' => 'SELECT id FROM inventory.item__t', 'repairAttempts' => 0],
        'SQLSTATE[42501]: Insufficient privilege: permission denied for table item__t'
    );
    fwrite(STDERR, "PostgreSQL permission failures must not be repaired.\n");
    exit(1);
} catch (PolicyViolationException $exception) {
    repairAssertSame($requestCount, count(TestTransport::$requests), 'PostgreSQL permission failures should make no repair request.');
}

TestTransport::$responses = [geminiText('SELECT ii.id FROM inventory.item__t ii')];
Yii::$logs = [];
$preflight = GeminiService::repairExploratorySqlAfterPreflight(
    'Show item identifiers.',
    'Smith College',
    [
        'sql' => 'SELECT ii.missing_column FROM inventory.item__t ii',
        'repairAttempts' => 1,
        'routeReason' => 'unsupported_query_family',
        'explanation' => 'Join purchase and circulation facts.',
    ],
    'ERROR: column ii.missing_column does not exist at character 15'
);
repairAssertSame(2, $preflight['repairAttempts'] ?? null, 'Preflight repair should share the two-repair total budget.');
repairAssertSame('validated', $preflight['validationSummary']['status'] ?? null, 'A repaired preflight candidate should be validated.');
$preflightPayload = json_encode(TestTransport::$requests[count(TestTransport::$requests) - 1]);
repairAssertContains('unknown_column', $preflightPayload, 'Preflight errors should be reduced to a safe category.');
repairAssertSame(false, strpos($preflightPayload, 'at character 15') !== false, 'Raw PostgreSQL error detail must not enter repair prompts.');
repairAssertSame(0, count(terminalTelemetryOutcomes()), 'A repaired candidate must not emit terminal validated until controller re-preflight succeeds.');

$legacyContract = \app\services\ExploratorySemanticContractService::build(
    roiPrompt(),
    'Smith College',
    \app\services\ExploratoryQueryDefaultsService::resolve(roiPrompt()),
    'unsupported_query_family',
    ['physicalRoiPolicyVersion' => 'legacy']
);
$legacyCompiled = \app\services\ExploratoryRoiSqlCompilerService::compile($legacyContract);
repairAssertSame(true, is_array($legacyCompiled), 'The rollback regression requires a valid legacy repair candidate.');
Yii::$app->params['nl2sqlHardenedPhysicalRoi'] = false;
TestTransport::$responses = [geminiText($legacyCompiled['sql'])];
TestTransport::$requests = [];
Yii::$logs = [];
$legacyPreflightRepair = GeminiService::repairExploratorySqlAfterPreflight(
    roiPrompt(),
    'Smith College',
    [
        'sql' => 'SELECT ii.missing_column FROM inventory.item__t ii',
        'repairAttempts' => 1,
        'routeReason' => 'unsupported_query_family',
        'explanation' => 'Join purchase and circulation facts.',
    ],
    'ERROR: column ii.missing_column does not exist at character 15'
);
repairAssertSame('validated', $legacyPreflightRepair['validationSummary']['status'] ?? null, 'Explicit rollback must preserve the legacy policy during post-preflight repair.');
repairAssertSame(2, $legacyPreflightRepair['repairAttempts'] ?? null, 'Legacy post-preflight repair must preserve the shared repair budget.');
repairAssertSame(1, count(TestTransport::$requests), 'Legacy post-preflight repair should succeed with one remaining model call.');
Yii::$app->params['nl2sqlHardenedPhysicalRoi'] = true;

TestTransport::$responses = [geminiText(semanticallyFlawedRoiSql())];
TestTransport::$requests = [];
Yii::$logs = [];
$semanticPreflightExhaustion = GeminiService::repairExploratorySqlAfterPreflight(
    roiPrompt(),
    'Smith College',
    [
        'sql' => 'SELECT ii.missing_column FROM inventory.item__t ii',
        'repairAttempts' => 1,
        'routeReason' => 'unsupported_query_family',
        'explanation' => 'Join purchase and circulation facts.',
    ],
    'ERROR: column ii.missing_column does not exist at character 15'
);
repairAssertSame(1, count(TestTransport::$requests), 'A semantic rejection after preflight should use only the one remaining repair call.');
repairAssertSame(2, $semanticPreflightExhaustion['repairAttempts'] ?? null, 'Semantic rejection after preflight must consume the remaining shared repair budget.');
repairAssertSame(false, isset($semanticPreflightExhaustion['sql']), 'Semantic exhaustion after preflight must not expose rejected SQL.');
repairAssertSame('semantic_conformance', $semanticPreflightExhaustion['validationSummary']['validatorStage'] ?? null, 'Recovery should identify semantic conformance as the exhausted stage.');
repairAssertSame(true, count($semanticPreflightExhaustion['unmetRequirements'] ?? []) > 0, 'Recovery should return safe unmet semantic requirements.');
repairAssertContains('I could not build a report I could safely run', $semanticPreflightExhaustion['validationSummary']['message'] ?? '', 'Semantic exhaustion should use novice-facing recovery copy.');
repairAssertSame(false, strpos(json_encode($semanticPreflightExhaustion), semanticallyFlawedRoiSql()) !== false, 'Semantic recovery must not leak the rejected SQL candidate.');
$semanticTelemetry = implode("\n", array_map(function ($record) { return (string)$record['message']; }, Yii::$logs));
repairAssertSame(false, strpos($semanticTelemetry, semanticallyFlawedRoiSql()) !== false, 'Semantic telemetry must not expose rejected SQL.');
repairAssertSame(false, strpos($semanticTelemetry, roiPrompt()) !== false, 'Semantic telemetry must not expose the original prompt.');

$terseFollowUp = 'Use invoice date instead.';
$followUpGenerationPrompt = implode("\n\n", [
    'This is a follow-up request to a previously generated library report.',
    'Previous request: ' . roiPrompt(),
    'Follow-up request: ' . $terseFollowUp,
]);
TestTransport::$responses = [geminiText(semanticallyFlawedRoiSql())];
TestTransport::$requests = [];
Yii::$logs = [];
$followUpSemanticExhaustion = GeminiService::repairExploratorySqlAfterPreflight(
    $terseFollowUp,
    'Smith College',
    [
        'sql' => 'SELECT ii.missing_column FROM inventory.item__t ii',
        'repairAttempts' => 1,
        'routeReason' => 'unsupported_query_family',
        'explanation' => 'Preserve the prior ROI report while changing its purchase date basis.',
    ],
    'ERROR: column ii.missing_column does not exist at character 15',
    $followUpGenerationPrompt
);
repairAssertSame(1, count(TestTransport::$requests), 'A terse follow-up semantic rejection should use only the one remaining repair call.');
repairAssertSame(2, $followUpSemanticExhaustion['repairAttempts'] ?? null, 'Terse follow-up semantic rejection must consume the remaining shared repair budget.');
repairAssertSame(false, isset($followUpSemanticExhaustion['sql']), 'A repair that drops the augmented ROI semantics must be rejected.');
repairAssertSame(
    'semantic_conformance',
    $followUpSemanticExhaustion['validationSummary']['validatorStage'] ?? null,
    'Terse follow-up recovery should identify semantic conformance as the exhausted stage.'
);
$followUpAssumptions = [];
foreach (($followUpSemanticExhaustion['assumptions'] ?? []) as $assumption) {
    if (is_array($assumption) && isset($assumption['key'])) {
        $followUpAssumptions[$assumption['key']] = $assumption['value'] ?? null;
    }
}
repairAssertSame(
    'invoice_date',
    $followUpAssumptions['purchase_date_basis'] ?? null,
    'Post-preflight assumptions must preserve the invoice-date correction from augmented generation context.'
);
repairAssertSame(
    $terseFollowUp,
    $followUpSemanticExhaustion['recoveryContext']['originalQuestion'] ?? null,
    'Terse follow-up recovery must still expose only the raw latest question.'
);

Yii::$logs = [];
try {
    GeminiService::repairExploratorySqlAfterPreflight(
        'Show items',
        null,
        ['sql' => 'SELECT id FROM inventory.item__t', 'repairAttempts' => 0],
        'SQLSTATE[08006] server closed the connection unexpectedly'
    );
    fwrite(STDERR, "Connectivity failures must be rethrown.\n");
    exit(1);
} catch (\RuntimeException $exception) {
    repairAssertContains('08006', $exception->getMessage(), 'Connectivity failures should propagate to infrastructure handling.');
    repairAssertSame('connectivity_failure', terminalTelemetryOutcomes()[0]['outcome'] ?? null, 'Database connectivity should emit its own terminal outcome.');
}

TestTransport::$responses = [];
Yii::$logs = [];
try {
    GeminiService::generateSqlWithShadow('Show unsupported provider failure telemetry.', null, null, true);
    fwrite(STDERR, "Provider failures must propagate.\n");
    exit(1);
} catch (\RuntimeException $exception) {
    repairAssertSame('provider_failure', terminalTelemetryOutcomes()[0]['outcome'] ?? null, 'Provider failures should emit their own terminal outcome.');
}

fwrite(STDOUT, "GeminiService exploratory repair test passed\n");
}
