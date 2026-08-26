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
            $request = json_decode($this->content, true);
            TestTransport::$requests[] = $request;
            $response = array_shift(TestTransport::$responses);
            $text = is_callable($response) ? $response($request) : $response;
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
            if (stripos($prompt, 'Hillyer scoped video report') === false) {
                return ['needsClarification' => false, 'guidanceLines' => [], 'resolvedFilters' => []];
            }

            return [
                'needsClarification' => false,
                'guidanceLines' => [],
                'resolvedFilters' => [
                    [
                        'dimension' => 'library',
                        'source_table' => 'inventory.loclibrary__t',
                        'column' => 'name',
                        'values' => ['SC Hillyer Art Library'],
                    ],
                    [
                        'dimension' => 'material_type',
                        'source_table' => 'inventory.material_type__t',
                        'column' => 'name',
                        'values' => ['Videocassette', 'DVD/Blu-ray'],
                    ],
                ],
            ];
        }

        public static function appendGuidanceToPrompt(string $prompt, array $resolution): string
        {
            if (empty($resolution['resolvedFilters'])) {
                return $prompt;
            }

            return $prompt
                . "\n\nReference resolver guidance:\n"
                . "- Resolved local reference filter: use exactly inventory.loclibrary__t.name = 'SC Hillyer Art Library'.\n"
                . "- Resolved local reference filter: use exactly inventory.material_type__t.name IN ('Videocassette', 'DVD/Blu-ray').";
        }

        public static function appendGenerationContextToPrompt(
            string $prompt,
            array $resolution,
            ?array $ambiguity = null
        ): string {
            return self::appendGuidanceToPrompt($prompt, $resolution);
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
                'location__t', 'loclibrary__t', 'material_type__t', 'organizations__t',
                'organizations__t__interfaces', 'interfaces__t',
                'organizations__t__acq_unit_ids', 'acquisitions_unit__t',
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
                'material_type__t' => 'inventory.material_type__t',
                'classification__t' => 'classification.classification__t',
                'organizations__t' => 'organizations.organizations__t',
                'organizations__t__interfaces' => 'organizations.organizations__t__interfaces',
                'interfaces__t' => 'organizations.interfaces__t',
                'organizations__t__acq_unit_ids' => 'organizations.organizations__t__acq_unit_ids',
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
        if ($alias === '@app/data/query_family_contracts.json') {
            return __DIR__ . '/../data/query_family_contracts.json';
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
require_once __DIR__ . '/../services/QueryFamilySlotService.php';
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

function telemetryEvents(string $event): array
{
    $events = [];
    foreach (Yii::$logs as $logRecord) {
        $message = (string)($logRecord['message'] ?? '');
        if (strpos($message, 'NL2SQL telemetry: ') !== 0) {
            continue;
        }
        $record = json_decode(substr($message, strlen('NL2SQL telemetry: ')), true);
        if (($record['event'] ?? null) === $event) {
            $events[] = $record;
        }
    }
    return $events;
}

function geminiText(string $sql, string $explanation = 'Candidate query.'): string
{
    return "```sql\n{$sql}\n```\n{$explanation}\nDATA SOURCE: folio";
}

function familyIntentText(string $familyKey, array $slots): string
{
    return json_encode([
        'familyKey' => $familyKey,
        'slots' => $slots,
    ]);
}

function unchangedCandidateResponse(array $request): string
{
    $encoded = json_encode($request);
    if (preg_match('/PREVIOUS CANDIDATE\\\\n(.*?)\\\\n\\\\nVALIDATOR STAGE/s', $encoded, $matches) !== 1) {
        throw new \RuntimeException('Repair payload did not contain a seeded candidate.');
    }
    return geminiText(str_replace('\\n', "\n", (string)$matches[1]), 'Reviewed candidate unchanged.');
}

function generateTwoLaneCase(string $question, array $responses, ?string $campus = 'Smith College'): array
{
    TestTransport::$responses = $responses;
    TestTransport::$requests = [];
    return GeminiService::generateSqlWithShadow($question, $campus);
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

function semanticallyUnverifiedCteSql(): string
{
    return "WITH report AS (\n" . semanticallyFlawedRoiSql() . "\n)\nSELECT * FROM report";
}

function scopedVideoSql(string $library, array $materialTypes): string
{
    $quotedMaterialTypes = array_map(function (string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    }, $materialTypes);

    return "SELECT item.id\n"
        . "FROM inventory.item__t item\n"
        . "JOIN inventory.location__t location ON location.id = item.effective_location_id\n"
        . "JOIN inventory.loclibrary__t library ON library.id = location.library_id\n"
        . "JOIN inventory.material_type__t material_type ON material_type.id = item.material_type_id\n"
        . "WHERE library.name = '" . str_replace("'", "''", $library) . "'\n"
        . 'AND material_type.name IN (' . implode(', ', $quotedMaterialTypes) . ')';
}

function validOrganizationInterfaceSql(): string
{
    return <<<'SQL'
SELECT intf.statistics_notes
FROM organizations.organizations__t AS org
JOIN organizations.organizations__t__interfaces AS oi ON oi.id = org.id
JOIN organizations.interfaces__t AS intf ON intf.id = oi.interfaces
JOIN organizations.organizations__t__acq_unit_ids AS ou ON ou.id = org.id
JOIN orders.acquisitions_unit__t AS au ON au.id = ou.acq_unit_ids
WHERE au.name = 'AC'
  AND intf.statistics_notes IS NOT NULL
LIMIT 100
SQL;
}

function invalidOrganizationInterfaceSql(): string
{
    return <<<'SQL'
SELECT intf.statistics_notes
FROM organizations.interfaces__t AS intf
JOIN organizations.organizations__t AS org ON intf.id = org.id
JOIN orders.purchase_order__t__acq_unit_ids AS po_units ON po_units.id = org.id
JOIN orders.acquisitions_unit__t AS au ON au.id = po_units.acq_unit_ids
WHERE au.name = 'AC'
LIMIT 100
SQL;
}

Yii::$app->params['nl2sqlTwoLaneEnabled'] = true;
$aiBuiltSql = 'SELECT ii.id AS title, ii.barcode FROM inventory.item__t ii LIMIT 100';

$missingSlotLane = generateTwoLaneCase(
    'Show me Smith College theses by this contributor with barcodes.',
    [
        familyIntentText('inventory_contributor_campus_item_barcode', [
            'campus' => 'Smith College',
            'material_type' => 'Theses',
            'requested_outputs' => ['barcode'],
        ]),
        geminiText($aiBuiltSql),
    ]
);
repairAssertSame($aiBuiltSql, $missingSlotLane['sql'] ?? null, 'Missing canonical slots must automatically consume the AI-built response.');
repairAssertSame('ai_built', $missingSlotLane['generationProvenance'] ?? null, 'Missing canonical slots must return AI-built provenance.');
repairAssertSame(false, isset($missingSlotLane['needsClarification']), 'Missing canonical slots must not return clarification when two-lane routing is enabled.');
repairAssertSame(2, count(TestTransport::$requests), 'Missing canonical slots must make one intent call and one AI-built generation call.');

Yii::$logs = [];
$wrongFamilyQuestion = 'Show barcodes for Smith College theses with the contributor named Smith College Biology.';
$wrongFamilyLane = generateTwoLaneCase(
    $wrongFamilyQuestion,
    [
        familyIntentText('circulation_top_items', [
            'campus' => 'Smith College',
            'library' => 'Neilson Library',
            'material_type' => 'Book',
            'requested_outputs' => ['ranked_circulation_items'],
        ]),
        geminiText($aiBuiltSql),
    ]
);
repairAssertSame($aiBuiltSql, $wrongFamilyLane['sql'] ?? null, 'A wrong model family must automatically consume the AI-built response.');
repairAssertSame('ai_built', $wrongFamilyLane['generationProvenance'] ?? null, 'A wrong model family must return AI-built provenance.');
repairAssertSame(2, count(TestTransport::$requests), 'A wrong model family must make one intent call and one AI-built generation call.');
$wrongFamilyTransitions = telemetryEvents('nl2sql.lane_transition');
repairAssertSame(1, count($wrongFamilyTransitions), 'A canonical failure must emit exactly one lane-transition event.');
repairAssertSame('canonical_family_contract_mismatch', $wrongFamilyTransitions[0]['reason'] ?? null, 'Lane telemetry must retain the safe transition reason.');
repairAssertSame('inventory_contributor_campus_item_barcode', $wrongFamilyTransitions[0]['familyKey'] ?? null, 'Lane telemetry must retain only the sanitized family key.');
repairAssertSame(false, $wrongFamilyTransitions[0]['seededCandidate'] ?? null, 'Fresh AI generation must not claim a seeded candidate.');
$wrongFamilyTelemetryJson = json_encode($wrongFamilyTransitions);
repairAssertSame(false, strpos($wrongFamilyTelemetryJson, $wrongFamilyQuestion) !== false, 'Lane telemetry must not contain prompt text.');
repairAssertSame(false, strpos($wrongFamilyTelemetryJson, $aiBuiltSql) !== false, 'Lane telemetry must not contain generated SQL.');

$unsupportedContractLane = generateTwoLaneCase(
    'Show barcodes for Smith College theses with the contributor named Smith College Biology.',
    [
        familyIntentText('inventory_contributor_campus_item_barcode', [
            'campus' => 'Smith College',
            'contributor_name' => 'Smith College Biology',
            'material_type' => 'Theses',
            'requested_outputs' => ['unsupported_projection'],
        ]),
        geminiText($aiBuiltSql),
    ]
);
repairAssertSame($aiBuiltSql, $unsupportedContractLane['sql'] ?? null, 'Unsupported canonical output shapes must automatically consume the AI-built response.');
repairAssertSame('ai_built', $unsupportedContractLane['generationProvenance'] ?? null, 'Unsupported canonical output shapes must return AI-built provenance.');
repairAssertSame(2, count(TestTransport::$requests), 'Unsupported canonical output shapes must make one intent call and one AI-built generation call.');

$invalidIntentLane = generateTwoLaneCase(
    'Show barcodes for Smith College theses with the contributor named Smith College Biology.',
    [
        '{malformed structured intent',
        geminiText($aiBuiltSql),
    ]
);
repairAssertSame($aiBuiltSql, $invalidIntentLane['sql'] ?? null, 'Invalid structured intent must automatically consume the AI-built response.');
repairAssertSame('ai_built', $invalidIntentLane['generationProvenance'] ?? null, 'Invalid structured intent must return AI-built provenance.');
repairAssertSame(2, count(TestTransport::$requests), 'Invalid structured intent must make one intent call and one AI-built generation call.');

$inventoryClarificationLane = generateTwoLaneCase(
    'List materials in location code SJTR. Include title and barcode.',
    [
        familyIntentText('inventory_library_location_listing', [
            'campus' => 'Smith College',
            'location_code' => 'SJTR',
            'requested_outputs' => ['title', 'barcode'],
        ]),
        geminiText($aiBuiltSql),
    ]
);
repairAssertSame($aiBuiltSql, $inventoryClarificationLane['sql'] ?? null, 'Inventory compiler clarification outcomes must automatically consume the AI-built response.');
repairAssertSame('ai_built', $inventoryClarificationLane['generationProvenance'] ?? null, 'Inventory compiler clarification outcomes must return AI-built provenance.');
repairAssertSame(false, isset($inventoryClarificationLane['needsClarification']), 'Inventory compiler clarification outcomes must not reach the user in enabled mode.');
repairAssertSame(2, count(TestTransport::$requests), 'Inventory compiler clarification outcomes must make one intent call and one AI-built generation call.');

Yii::$app->params['nl2sqlForceLegacy'] = true;
$forcedLegacyLane = generateTwoLaneCase(
    $wrongFamilyQuestion,
    [geminiText($aiBuiltSql)]
);
repairAssertSame($aiBuiltSql, $forcedLegacyLane['sql'] ?? null, 'Forced legacy generation must return the AI-built SQL candidate.');
repairAssertSame('ai_built', $forcedLegacyLane['generationProvenance'] ?? null, 'Forced legacy freeform SQL must never be labeled verified.');
repairAssertSame(false, ($forcedLegacyLane['generationProvenance'] ?? null) === 'verified_pattern', 'Forced legacy freeform SQL must not claim verified-pattern provenance.');
repairAssertSame(1, count(TestTransport::$requests), 'Forced legacy generation should make exactly one AI request.');
Yii::$app->params['nl2sqlForceLegacy'] = false;

Yii::$logs = [];
TestTransport::$responses = [];
TestTransport::$requests = [];
try {
    GeminiService::generateSqlWithShadow($wrongFamilyQuestion, 'Smith College');
    fwrite(STDERR, "Canonical provider failures must propagate.\n");
    exit(1);
} catch (\RuntimeException $exception) {
    repairAssertContains('AI request failed', $exception->getMessage(), 'Canonical provider failures should preserve the transport failure.');
}
repairAssertSame(1, count(TestTransport::$requests), 'A canonical provider failure must not trigger a second Lane 2 request.');
repairAssertSame(0, count(telemetryEvents('nl2sql.lane_transition')), 'A canonical provider failure must not emit a lane transition.');

$seededPrompt = 'For instance numbers in0001 and in0002, show title and publication date.';
$seededCandidateSql = 'SELECT inst.title FROM inventory.instance__t inst';
$seededRepairedSql = "SELECT inst.title, inst.publication_date FROM inventory.instance__t inst "
    . "WHERE inst.hrid IN ('in0001','in0002')";
$aiBuiltLane = new ReflectionMethod(GeminiService::class, 'generateAiBuiltLane');
TestTransport::$responses = [geminiText($seededRepairedSql)];
TestTransport::$requests = [];
Yii::$logs = [];
$seededSemanticLane = $aiBuiltLane->invoke(
    null,
    $seededPrompt,
    $seededPrompt,
    'Smith College',
    'canonical_semantic_validation_failed',
    [],
    [
        'sql' => $seededCandidateSql,
        'route' => 'builder_intent',
        'routeReason' => 'family_contract_supported:inventory_library_location_listing',
        'repairAttempts' => 0,
    ],
    'Canonical semantic validation requires AI review.',
    'inventory_library_location_listing'
);
repairAssertSame($seededRepairedSql, $seededSemanticLane['sql'] ?? null, 'Canonical semantic failure must return the repaired AI candidate.');
repairAssertContains($seededCandidateSql, json_encode(TestTransport::$requests[0] ?? []), 'Lane 2 must receive the compiled SQL as its seeded candidate.');
repairAssertSame('ai_built', $seededSemanticLane['generationProvenance'] ?? null, 'AI-owned repaired SQL must not remain verified.');
repairAssertSame(false, ($seededSemanticLane['generationProvenance'] ?? null) === 'verified_pattern', 'Semantic fallback must downgrade provenance.');
$seededTransitions = telemetryEvents('nl2sql.lane_transition');
repairAssertSame(1, count($seededTransitions), 'Seeded AI review must emit exactly one lane-transition event.');
repairAssertSame(true, $seededTransitions[0]['seededCandidate'] ?? null, 'Seeded AI review telemetry must record candidate presence without SQL.');
$seededTelemetryJson = json_encode($seededTransitions);
repairAssertSame(false, strpos($seededTelemetryJson, $seededPrompt) !== false, 'Seeded lane telemetry must not contain prompt text.');
repairAssertSame(false, strpos($seededTelemetryJson, $seededCandidateSql) !== false, 'Seeded lane telemetry must not contain candidate SQL.');

$unchangedCandidateSql = "SELECT inst.title, inst.publication_date FROM inventory.instance__t inst "
    . "WHERE inst.hrid = 'in0001'";
TestTransport::$responses = [
    'unchangedCandidateResponse',
    'unchangedCandidateResponse',
];
TestTransport::$requests = [];
$unchangedSeededLane = $aiBuiltLane->invoke(
    null,
    $seededPrompt,
    $seededPrompt,
    'Smith College',
    'canonical_semantic_validation_failed',
    [],
    [
        'sql' => $unchangedCandidateSql,
        'route' => 'builder_intent',
        'routeReason' => 'family_contract_supported:inventory_library_location_listing',
        'repairAttempts' => 0,
    ],
    'Canonical semantic validation requires AI review.',
    'inventory_library_location_listing'
);
repairAssertSame(true, isset($unchangedSeededLane['sql']), 'A safe canonical candidate returned unchanged after final AI review remains eligible for preflight.');
repairAssertSame('ai_built', $unchangedSeededLane['generationProvenance'] ?? null, 'An unchanged AI-reviewed candidate must remain AI-built.');
repairAssertSame('advisory', $unchangedSeededLane['semanticValidation']['status'] ?? null, 'An unchanged candidate with unverified explicit values must carry advisory validation.');
repairAssertSame(true, $unchangedSeededLane['reviewRequired'] ?? null, 'An unchanged advisory candidate must require review.');
repairAssertSame(2, count(TestTransport::$requests), 'An unchanged seeded candidate must use both bounded AI reviews.');
$seededAdvisoryAssumptionsJson = json_encode($unchangedSeededLane['assumptions'] ?? []);
repairAssertContains('Explicit report identifier', $seededAdvisoryAssumptionsJson, 'Seeded advisory results must retain a safe assumption for each unverified requirement.');
repairAssertContains('not_fully_verified', $seededAdvisoryAssumptionsJson, 'Seeded advisory assumptions must disclose their unverified status.');
repairAssertSame(false, strpos($seededAdvisoryAssumptionsJson, 'inst.hrid') !== false, 'Seeded advisory assumptions must not expose SQL predicates.');

TestTransport::$responses = [
    geminiText('SELECT mt.id FROM inventory.missing_table__t mt'),
    geminiText('SELECT ii.id FROM inventory.item__t ii'),
];
TestTransport::$requests = [];
Yii::$logs = [];

$repaired = GeminiService::generateSqlWithShadow('Show item identifiers for inventory.', 'Smith College', null, true);
repairAssertSame('SELECT ii.id FROM inventory.item__t ii', $repaired['sql'] ?? null, 'A bad initial table should be replaced by the valid repair candidate.');
repairAssertSame('ai_built', $repaired['generationProvenance'] ?? null, 'Direct exploratory generation must return AI-built provenance.');
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

$initialPayload = json_encode(TestTransport::$requests[0]);
$repairPayload = json_encode(TestTransport::$requests[1]);
repairAssertContains('SELECT mt.id FROM inventory.missing_table__t mt', $repairPayload, 'The repair request should contain the previous SQL candidate.');
foreach ([
    'organizations.organizations__t__interfaces',
    'organizations.organizations__t__acq_unit_ids',
    'orders.acquisitions_unit__t',
    'purchase_order__t__acq_unit_ids.id is the purchase order ID',
    'acquisition-unit codes use exact equality',
] as $organizationGuidanceAnchor) {
    repairAssertContains(
        $organizationGuidanceAnchor,
        $initialPayload,
        'Initial exploratory generation must include the shared organization relationship guidance.'
    );
    repairAssertContains(
        $organizationGuidanceAnchor,
        $repairPayload,
        'Exploratory repair must include the same organization relationship guidance.'
    );
}
$baselineRepairLogs = Yii::$logs;

$organizationQuestion = 'List all statistics notes in organization interfaces limited to the AC acquisition unit';
TestTransport::$responses = [
    geminiText(invalidOrganizationInterfaceSql()),
    geminiText(validOrganizationInterfaceSql()),
];
TestTransport::$requests = [];
$organizationRepair = GeminiService::generateSqlWithShadow(
    $organizationQuestion,
    null,
    null,
    true
);
repairAssertSame(1, $organizationRepair['repairAttempts'] ?? null, 'Invalid organization joins must enter one bounded repair.');
repairAssertSame(validOrganizationInterfaceSql(), $organizationRepair['sql'] ?? null, 'Repair must restore both authoritative organization bridges.');
repairAssertSame('validated', $organizationRepair['semanticValidation']['status'] ?? null, 'The repaired organization candidate must satisfy its semantic contract.');
repairAssertSame(true, $organizationRepair['semanticContractApplicable'] ?? null, 'Organization acquisition-unit requests must retain an applicable semantic contract.');
repairAssertContains(
    'organization_interface_relationship',
    json_encode(TestTransport::$requests[1]),
    'The repair request must identify the failed interface relationship safely.'
);

TestTransport::$responses = [
    geminiText(invalidOrganizationInterfaceSql()),
    geminiText(invalidOrganizationInterfaceSql()),
    geminiText(invalidOrganizationInterfaceSql()),
];
TestTransport::$requests = [];
$organizationExhaustion = GeminiService::generateSqlWithShadow(
    $organizationQuestion,
    null,
    null,
    true
);
repairAssertSame(2, $organizationExhaustion['repairAttempts'] ?? null, 'Organization semantic repair must use the shared two-attempt budget.');
repairAssertSame(true, isset($organizationExhaustion['sql']), 'A safe final organization candidate must remain eligible for preflight.');
repairAssertSame('advisory', $organizationExhaustion['semanticValidation']['status'] ?? null, 'Organization semantic exhaustion must be advisory after final AI review.');
repairAssertSame(true, $organizationExhaustion['reviewRequired'] ?? null, 'Organization advisory results must require review.');

TestTransport::$responses = [
    geminiText(str_replace(
        "library.name = 'SC Hillyer Art Library'",
        "location.name = 'HC DVD'",
        scopedVideoSql('SC Hillyer Art Library', ['Videocassette', 'DVD/Blu-ray'])
    )),
    geminiText(scopedVideoSql('SC Hillyer Art Library', ['Videocassette', 'DVD/Blu-ray'])),
];
TestTransport::$requests = [];
$resolvedFilterRepair = GeminiService::generateSqlWithShadow(
    'Hillyer scoped video report',
    'Smith College',
    null,
    true
);
repairAssertSame(1, $resolvedFilterRepair['repairAttempts'] ?? null, 'Resolved-filter mismatch should use one bounded repair.');
repairAssertContains("library.name = 'SC Hillyer Art Library'", $resolvedFilterRepair['sql'] ?? '', 'Repair must restore library scope.');
repairAssertSame(false, strpos($resolvedFilterRepair['sql'] ?? '', 'HC DVD') !== false, 'Repair must remove the wrong location.');
repairAssertSame(
    [
        [
            'dimension' => 'library',
            'source_table' => 'inventory.loclibrary__t',
            'column' => 'name',
            'values' => ['SC Hillyer Art Library'],
        ],
        [
            'dimension' => 'material_type',
            'source_table' => 'inventory.material_type__t',
            'column' => 'name',
            'values' => ['Videocassette', 'DVD/Blu-ray'],
        ],
    ],
    $resolvedFilterRepair['_askEvidence']['resolvedReferenceFilters'] ?? null,
    'Trusted evidence must contain only the structured resolved filters.'
);
$resolvedEvidenceJson = json_encode($resolvedFilterRepair['_askEvidence']['resolvedReferenceFilters'] ?? []);
repairAssertSame(false, strpos($resolvedEvidenceJson, 'Hillyer scoped video report') !== false, 'Resolved-filter evidence must not contain raw prompts.');
repairAssertSame(false, strpos($resolvedEvidenceJson, 'Reference resolver guidance') !== false, 'Resolved-filter evidence must not contain rendered guidance.');

TestTransport::$responses = [
    geminiText(scopedVideoSql('SC Hillyer Art Library', ['DVD/Blu-ray'])),
    geminiText(scopedVideoSql('SC Hillyer Art Library', ['DVD/Blu-ray'])),
    geminiText(scopedVideoSql('SC Hillyer Art Library', ['DVD/Blu-ray'])),
];
TestTransport::$requests = [];
$resolvedFilterExhausted = GeminiService::generateSqlWithShadow(
    'Hillyer scoped video report exhaustion',
    'Smith College',
    null,
    true
);
repairAssertSame(true, isset($resolvedFilterExhausted['sql']), 'A safe final resolved-filter candidate must remain eligible for database preflight.');
repairAssertSame('advisory', $resolvedFilterExhausted['semanticValidation']['status'] ?? null, 'Resolved-filter proof exhaustion must be marked advisory.');
repairAssertSame(true, $resolvedFilterExhausted['reviewRequired'] ?? null, 'Resolved-filter proof exhaustion must require review.');
repairAssertSame('ai_built', $resolvedFilterExhausted['generationProvenance'] ?? null, 'Resolved-filter proof exhaustion must remain AI-built.');
repairAssertSame(2, $resolvedFilterExhausted['repairAttempts'] ?? null, 'Resolved-filter exhaustion must preserve the shared two-repair maximum.');
repairAssertSame(3, count(TestTransport::$requests), 'Resolved-filter exhaustion must use one initial generation and exactly two repairs.');
repairAssertSame(false, ($resolvedFilterExhausted['route'] ?? null) === 'exploratory_recovery', 'Resolved-filter advisory success must not become recovery.');
repairAssertSame(false, isset($resolvedFilterExhausted['needsClarification']), 'Resolved-filter advisory success must not become clarification.');
repairAssertSame(false, strpos(json_encode($resolvedFilterExhausted), 'top-level WHERE clause') !== false, 'Resolved-filter advisory output must not expose validator guidance.');
$referenceAdvisoryAssumptionsJson = json_encode($resolvedFilterExhausted['assumptions'] ?? []);
repairAssertContains('Library and material filters', $referenceAdvisoryAssumptionsJson, 'Direct advisory results must retain a safe assumption for each unverified requirement.');
repairAssertContains('not_fully_verified', $referenceAdvisoryAssumptionsJson, 'Direct advisory assumptions must disclose their unverified status.');
repairAssertSame(false, strpos($referenceAdvisoryAssumptionsJson, 'top-level WHERE clause') !== false, 'Direct advisory assumptions must not expose validator guidance.');
repairAssertContains('unknown_table', $repairPayload, 'The repair request should contain the safe validation category.');
repairAssertContains('None documented.', $repairPayload, 'The repair request should safely represent absent assumptions.');
repairAssertContains('SCOPED SCHEMA', $repairPayload, 'The repair request should contain fresh scoped schema context.');
repairAssertContains('Smith College', $repairPayload, 'The repair request should explicitly preserve the separately supplied campus.');
repairAssertContains('Never include a second SQL statement', $repairPayload, 'The repair response contract should explicitly forbid alternate statements.');
repairAssertContains('one ```sql code block', $repairPayload, 'The repair response contract should require the parser-supported fenced SQL shape.');

Yii::$logs = $baselineRepairLogs;
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
if (PHP_VERSION_ID < 80100) {
    $logValidationFailure->setAccessible(true);
}
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
repairAssertSame(false, isset($exhausted['sql']), 'Exhaustion must not return unvalidated SQL.');
repairAssertSame('sql_generation_failed', $exhausted['errorType'] ?? null, 'Exhaustion must expose a concise SQL-generation failure type.');
repairAssertSame('generation_failed', $exhausted['route'] ?? null, 'Exhaustion must not use the exploratory recovery route.');
repairAssertSame('sql_repair_exhausted', $exhausted['routeReason'] ?? null, 'Exhaustion should expose a stable repair-budget reason.');
repairAssertSame(2, $exhausted['validationSummary']['repairAttempts'] ?? null, 'Exhaustion should consume exactly two repair attempts.');
repairAssertSame('Report Explorer could not safely run this report. Please retry.', $exhausted['message'] ?? null, 'Exhaustion should use concise Retry-oriented copy.');
foreach (['recoveryContext', 'recoveryItems', 'attemptedPlan', 'suggestions', 'unmetRequirements', 'generationProvenance', 'provenanceLabel'] as $forbiddenField) {
    repairAssertSame(false, array_key_exists($forbiddenField, $exhausted), 'Exhaustion must omit recovery, correction, and provenance fields.');
}
repairAssertSame(false, strpos(strtolower(json_encode($exhausted)), 'request is preserved') !== false, 'Exhaustion must not promise request preservation.');
repairAssertSame('exhausted', terminalTelemetryOutcomes()[0]['outcome'] ?? null, 'Repair exhaustion should emit a terminal exhausted outcome.');

$explicitRecoveryPrompt = 'For instance numbers in0001, in0002, show title, barcode, and publication date. Limit 20.';
TestTransport::$responses = [
    geminiText("SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20"),
    geminiText("SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20"),
    geminiText("SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20"),
];
Yii::$logs = [];
$explicitRecovery = GeminiService::generateSqlWithShadow($explicitRecoveryPrompt, null, null, true);
repairAssertSame(true, isset($explicitRecovery['sql']), 'A safe final explicit-value candidate must remain eligible for preflight.');
repairAssertSame('advisory', $explicitRecovery['semanticValidation']['status'] ?? null, 'Explicit-value exhaustion must become advisory after final AI review.');
repairAssertSame(true, $explicitRecovery['reviewRequired'] ?? null, 'Explicit-value advisory results must require review.');
repairAssertSame(false, strpos(json_encode($explicitRecovery), 'EXPLICIT REPORT VALUES') !== false, 'Advisory responses must not expose server-authored explicit-value guidance.');
repairAssertSame(false, strpos(json_encode($explicitRecovery), 'SQL filter') !== false, 'Advisory responses must not expose SQL-oriented repair guidance.');

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
    geminiText("SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20"),
    geminiText("SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20"),
];
TestTransport::$requests = [];
Yii::$logs = [];
$routedRepair = new ReflectionMethod(GeminiService::class, 'repairRoutedCandidateMissingExplicitValues');
if (PHP_VERSION_ID < 80100) {
    $routedRepair->setAccessible(true);
}
$routedExhausted = $routedRepair->invoke(
    null,
    [
        'sql' => "SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20",
        'route' => 'builder_intent',
        'routeReason' => 'family_contract_supported:inventory_library_location_listing',
    ],
    $routedEffectivePrompt,
    null,
    $routedExplicitPrompt
);
repairAssertSame(2, $routedExhausted['repairAttempts'] ?? null, 'Routed-family explicit repair exhaustion must preserve the shared two-attempt maximum.');
repairAssertSame(true, isset($routedExhausted['sql']), 'Routed-family explicit repair exhaustion must retain the safe final candidate.');
repairAssertSame('advisory', $routedExhausted['semanticValidation']['status'] ?? null, 'Routed-family explicit repair exhaustion must become advisory.');
repairAssertSame(true, $routedExhausted['reviewRequired'] ?? null, 'Routed-family advisory results must require review.');
repairAssertSame(false, strpos(json_encode($routedExhausted), 'EXPLICIT REPORT VALUES') !== false, 'Routed-family advisory results must not expose server guidance.');
repairAssertSame(false, strpos(json_encode($routedExhausted), 'Reference resolver guidance') !== false, 'Routed-family advisory results must not expose resolver schema guidance.');
repairAssertSame(false, strpos(json_encode($routedExhausted), 'explicitReportRequest') !== false, 'Routed-family advisory results must not expose internal explicit-value keys.');
$routedRepairPayload = json_encode(TestTransport::$requests[0] ?? []);
repairAssertContains('Reference resolver guidance', $routedRepairPayload, 'Routed-family repair must retain resolver guidance as model-only generation context.');
repairAssertContains('EXPLICIT REPORT VALUES', $routedRepairPayload, 'Routed-family repair must retain explicit-value guidance as model-only generation context.');

TestTransport::$responses = [
    geminiText(semanticallyUnverifiedCteSql()),
    geminiText(semanticallyUnverifiedCteSql()),
    geminiText(semanticallyUnverifiedCteSql()),
];
TestTransport::$requests = [];
$compiledFallback = GeminiService::generateSqlWithShadow(roiPrompt(), 'Smith College', null, true);
repairAssertSame('advisory', $compiledFallback['semanticValidation']['status'] ?? null, 'A safe CTE the semantic analyzer cannot fully verify must become advisory.');
repairAssertSame(true, $compiledFallback['reviewRequired'] ?? null, 'A semantically unverified CTE must require review.');
repairAssertSame('ai_built', $compiledFallback['generationProvenance'] ?? null, 'A semantically unverified CTE must remain AI-built.');
repairAssertSame(false, ($compiledFallback['route'] ?? null) === 'exploratory_recovery', 'A semantically unverified safe CTE must not become recovery.');
repairAssertSame(false, isset($compiledFallback['needsClarification']), 'A semantically unverified safe CTE must not become clarification.');
repairAssertSame(2, $compiledFallback['repairAttempts'] ?? null, 'The advisory CTE should preserve the exhausted model repair count.');
repairAssertSame(3, count(TestTransport::$requests), 'The advisory CTE must use the initial candidate and two bounded repairs.');

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
    'SQLSTATE[53200]: Out of memory',
    'SQLSTATE[53400]: Configuration limit exceeded',
    'SQLSTATE[54001]: Statement too complex',
    'Query is too complex for the configured preflight limit',
    'Estimated query cost exceeds configured limit',
] as $resourceError) {
    TestTransport::$responses = [geminiText('SELECT should_not_run FROM inventory.item__t')];
    TestTransport::$requests = [];
    Yii::$logs = [];
    try {
        GeminiService::repairExploratorySqlAfterPreflight(
            'Show items',
            null,
            [
                'sql' => 'SELECT id FROM inventory.item__t',
                'repairAttempts' => 0,
                'generationProvenance' => 'verified_pattern',
            ],
            $resourceError
        );
        fwrite(STDERR, "Database resource limits must be hard stops.\n");
        exit(1);
    } catch (\RuntimeException $exception) {
        repairAssertSame(0, count(TestTransport::$requests), 'Database resource limits must not make an AI repair request.');
        repairAssertSame('resource_limited', terminalTelemetryOutcomes()[0]['outcome'] ?? null, 'Database resource limits should emit a distinct terminal outcome.');
        repairAssertSame('resource_limit', terminalTelemetryOutcomes()[0]['category'] ?? null, 'Database resource limits should expose only a safe resource category.');
    }
}

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

TestTransport::$responses = [
    geminiText('SELECT missing_a.id FROM inventory.missing_a__t missing_a'),
    geminiText('SELECT missing_b.id FROM inventory.missing_b__t missing_b'),
];
TestTransport::$requests = [];
Yii::$logs = [];
$preflightExhaustion = GeminiService::repairExploratorySqlAfterPreflight(
    'Show item identifiers.',
    'Smith College',
    [
        'sql' => 'SELECT ii.missing_column FROM inventory.item__t ii',
        'repairAttempts' => 0,
        'generationProvenance' => 'verified_pattern',
        'provenanceLabel' => 'Verified pattern',
    ],
    'ERROR: column ii.missing_column does not exist'
);
repairAssertSame(2, count(TestTransport::$requests), 'Database-preflight exhaustion must consume the shared two-repair budget.');
repairAssertSame(false, isset($preflightExhaustion['sql']), 'Database-preflight exhaustion must not return rejected SQL.');
repairAssertSame('sql_generation_failed', $preflightExhaustion['errorType'] ?? null, 'Database-preflight exhaustion must use concise SQL-generation failure.');
repairAssertSame('generation_failed', $preflightExhaustion['route'] ?? null, 'Database-preflight exhaustion must not use exploratory recovery.');
repairAssertSame(2, $preflightExhaustion['validationSummary']['repairAttempts'] ?? null, 'Database-preflight exhaustion must retain the actual shared repair count.');
repairAssertSame(null, $preflightExhaustion['_askEvidence']['finalSql'] ?? null, 'Database-preflight exhaustion must not retain rejected SQL as executable final evidence.');
foreach (['recoveryContext', 'attemptedPlan', 'suggestions', 'unmetRequirements', 'generationProvenance', 'provenanceLabel'] as $forbiddenField) {
    repairAssertSame(false, array_key_exists($forbiddenField, $preflightExhaustion), 'Database-preflight exhaustion must omit recovery, correction, and provenance fields.');
}

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
repairAssertSame(true, isset($semanticPreflightExhaustion['sql']), 'Safe semantic exhaustion after preflight must retain the final candidate.');
repairAssertSame('advisory', $semanticPreflightExhaustion['semanticValidation']['status'] ?? null, 'Semantic exhaustion after preflight must become advisory.');
repairAssertSame(true, $semanticPreflightExhaustion['reviewRequired'] ?? null, 'Semantic exhaustion after preflight must require review.');
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
repairAssertSame(true, isset($followUpSemanticExhaustion['sql']), 'A safe follow-up candidate must remain eligible for preflight after bounded review.');
repairAssertSame('advisory', $followUpSemanticExhaustion['semanticValidation']['status'] ?? null, 'A semantically unverified follow-up must become advisory.');
repairAssertSame(true, $followUpSemanticExhaustion['reviewRequired'] ?? null, 'A semantically unverified follow-up must require review.');
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
