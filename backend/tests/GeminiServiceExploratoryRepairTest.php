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
        public static function getTableNames(): array { return ['item__t']; }
        public static function discoverTableMapping(): array { return ['item__t' => 'inventory.item__t']; }

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

    public static function getAlias($alias) { return __DIR__ . '/../data/settings.json'; }
    public static function info($message, $category = null) { self::$logs[] = ['level' => 'info', 'message' => $message, 'category' => $category]; }
    public static function warning($message, $category = null) { self::$logs[] = ['level' => 'warning', 'message' => $message, 'category' => $category]; }
}

Yii::$app = (object)['params' => [
    'aiProvider' => 'gemini',
    'geminiApiKey' => 'test-key',
    'geminiMaxRetries' => 1,
    'nl2sqlForceLegacy' => false,
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

function geminiText(string $sql, string $explanation = 'Candidate query.'): string
{
    return "```sql\n{$sql}\n```\n{$explanation}\nDATA SOURCE: folio";
}

function roiPrompt(): string
{
    return 'Show ROI for purchases and circulation by call number, including checkouts and investment.';
}

TestTransport::$responses = [
    geminiText('SELECT mt.id FROM inventory.missing_table__t mt'),
    geminiText('SELECT ii.id FROM inventory.item__t ii'),
];
TestTransport::$requests = [];
Yii::$logs = [];

$repaired = GeminiService::generateSqlWithShadow(roiPrompt(), 'Smith College', null, true);
repairAssertSame('SELECT ii.id FROM inventory.item__t ii', $repaired['sql'] ?? null, 'A bad initial table should be replaced by the valid repair candidate.');
repairAssertSame(1, $repaired['repairAttempts'] ?? null, 'One automatic repair should be reported.');
repairAssertSame('validated', $repaired['validationSummary']['status'] ?? null, 'Successful exploratory SQL should be marked validated.');
repairAssertSame(5, count($repaired['assumptions'] ?? []), 'The documented ROI defaults should be returned.');
repairAssertSame(2, count(TestTransport::$requests), 'Bad-then-valid generation should make one initial request and one repair request.');

$repairPayload = json_encode(TestTransport::$requests[1]);
repairAssertContains('SELECT mt.id FROM inventory.missing_table__t mt', $repairPayload, 'The repair request should contain the previous SQL candidate.');
repairAssertContains('unknown_table', $repairPayload, 'The repair request should contain the safe validation category.');
repairAssertContains('DOCUMENTED INTERPRETATIONS', $repairPayload, 'The repair request should contain documented assumptions.');
repairAssertContains('SCOPED SCHEMA', $repairPayload, 'The repair request should contain fresh scoped schema context.');
repairAssertContains('Smith College', $repairPayload, 'The repair request should explicitly preserve the separately supplied campus.');

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
    foreach (['promptFingerprint', 'phase', 'repairNumber', 'maximumRepairs', 'stage', 'category', 'candidateLength', 'provider', 'elapsedMs', 'assumptionKeys', 'outcome'] as $field) {
        repairAssertSame(true, array_key_exists($field, $record), "Exploratory repair telemetry should include {$field}.");
    }
    repairAssertSame(false, array_key_exists('sql', $record), 'Exploratory repair telemetry must not expose a SQL field.');
    repairAssertSame(false, array_key_exists('prompt', $record), 'Exploratory repair telemetry must not expose a prompt field.');
}
repairAssertSame(4, $repairTelemetryCount, 'Bad-then-valid generation should emit attempt and outcome telemetry for both candidates.');

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
$exhausted = GeminiService::generateSqlWithShadow(roiPrompt(), 'Smith College', null, true);
repairAssertSame(false, isset($exhausted['sql']), 'Exhausted recovery must not return unvalidated SQL.');
repairAssertSame('exploratory_recovery', $exhausted['route'] ?? null, 'Exhausted recovery should use the safe recovery route.');
repairAssertSame(2, $exhausted['repairAttempts'] ?? null, 'Exhaustion should consume exactly two repair attempts.');
repairAssertSame(roiPrompt(), $exhausted['recoveryContext']['originalQuestion'] ?? null, 'Recovery should retain the original question for an explicit retry.');
repairAssertSame('unknown_table', $exhausted['validationSummary']['failureCategory'] ?? null, 'Recovery should expose only the safe failure category.');

SqlBuilderService::$blockPolicy = true;
TestTransport::$responses = [geminiText('SELECT ii.id FROM inventory.item__t ii')];
try {
    GeminiService::generateSqlWithShadow('Show restricted patron details.', null, null, true);
    fwrite(STDERR, "Policy violations must not be repaired.\n");
    exit(1);
} catch (PolicyViolationException $exception) {
    repairAssertSame(0, count(TestTransport::$responses), 'A policy hard stop should make no retry request.');
}
SqlBuilderService::$blockPolicy = false;

foreach ([
    'SQLSTATE[57014]: Query canceled: canceling statement due to user request',
    'SQLSTATE[57014]: Query canceled: canceling statement due to statement timeout',
] as $cancellationError) {
    TestTransport::$responses = [];
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
    } catch (\RuntimeException $exception) {
        repairAssertContains('57014', $exception->getMessage(), 'PostgreSQL cancellation should propagate to request handling.');
        repairAssertSame($requestCount, count(TestTransport::$requests), 'PostgreSQL cancellation should make no repair request.');
    }
}

TestTransport::$responses = [];
$requestCount = count(TestTransport::$requests);
try {
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
$preflight = GeminiService::repairExploratorySqlAfterPreflight(
    roiPrompt(),
    'Smith College',
    [
        'sql' => 'SELECT ii.missing_column FROM inventory.item__t ii',
        'repairAttempts' => 1,
        'assumptions' => $repaired['assumptions'],
        'explanation' => 'Join purchase and circulation facts.',
    ],
    'ERROR: column ii.missing_column does not exist at character 15'
);
repairAssertSame(2, $preflight['repairAttempts'] ?? null, 'Preflight repair should share the two-repair total budget.');
repairAssertSame('validated', $preflight['validationSummary']['status'] ?? null, 'A repaired preflight candidate should be validated.');
$preflightPayload = json_encode(TestTransport::$requests[count(TestTransport::$requests) - 1]);
repairAssertContains('unknown_column', $preflightPayload, 'Preflight errors should be reduced to a safe category.');
repairAssertSame(false, strpos($preflightPayload, 'at character 15') !== false, 'Raw PostgreSQL error detail must not enter repair prompts.');

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
}

fwrite(STDOUT, "GeminiService exploratory repair test passed\n");
}
