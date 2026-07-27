<?php

// Regression test: family routing must be decided from the user's raw prompt,
// not from the resolver-augmented effective prompt. ReferenceResolverService
// appends boilerplate ("Do not apply this value to library or campus name
// columns") to every resolved reference. That text contains the word "library",
// which trips promptMentionsLibraryLocationListingScope and used to misroute a
// generic item listing onto the inventory_library_location_listing compiler,
// producing campus/library name-ILIKE junk SQL.

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

        public function setMethod($method)
        {
            return $this;
        }

        public function setUrl($url)
        {
            return $this;
        }

        public function setHeaders($headers)
        {
            return $this;
        }

        public function addOptions($options)
        {
            return $this;
        }

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
                $text = "```sql\nSELECT ii.title FROM inventory.item__t AS ii LIMIT 100\n```\nFreeform item listing.\nDATA SOURCE: folio";
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
            return [
                'needsClarification' => false,
                'guidanceLines' => self::guidanceLines(),
            ];
        }

        // Mirror the real boilerplate that every resolved reference appends. The
        // word "library" here is what used to contaminate family routing.
        public static function appendGuidanceToPrompt(string $prompt, array $referenceResolution): string
        {
            return rtrim($prompt) . "\n\nReference resolver guidance:\n" . implode("\n", self::guidanceLines());
        }

        private static function guidanceLines(): array
        {
            return [
                "- Resolved local reference: use exactly inventory.material_type__t.name = 'E-Book'. Do not apply this value to library or campus name columns. Do not add code filters unless the user explicitly asks to filter by code.",
            ];
        }
    }

    class FolioSchemaService
    {
        public static function buildSchemaContext($prompt = null): string
        {
            return 'inventory.item__t(id, title)';
        }

        public static function getMetadata(): array
        {
            return ['scraped_at' => 'test'];
        }

        public static function getTableNames(): array
        {
            return ['item__t', 'instance__t'];
        }

        public static function discoverTableMapping(): array
        {
            return [
                'item__t' => 'inventory.item__t',
                'instance__t' => 'inventory.instance__t',
            ];
        }

        public static function fuzzyMatch($table)
        {
            return in_array($table, ['item__t', 'instance__t'], true) ? $table : null;
        }
    }

    class SqlBuilderService
    {
        public static function validateSafety($sql): void
        {
        }

        public static function validateTablePolicy($sql): void
        {
        }
    }
}

namespace {
if (!defined('CURLOPT_TIMEOUT')) {
    define('CURLOPT_TIMEOUT', 13);
}

$contractServicePath = __DIR__ . '/../services/QueryFamilyContractService.php';
$geminiServicePath = __DIR__ . '/../services/GeminiService.php';

foreach ([
    'QueryFamilyContractService' => $contractServicePath,
    'GeminiService' => $geminiServicePath,
] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "{$label} is missing at {$path}\n");
        exit(1);
    }
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
        public static $logs = [];

        public static function getAlias($alias)
        {
            return __DIR__ . '/../data/settings.json';
        }

        public static function info($message, $category = null)
        {
            self::$logs[] = ['message' => $message, 'category' => $category];
        }

        public static function warning($message, $category = null)
        {
            self::$logs[] = ['message' => $message, 'category' => $category];
        }
    }
}

Yii::$app = (object) [
    'params' => [
        'aiProvider' => 'gemini',
        'geminiApiKey' => 'test-key',
        'nl2sqlForceLegacy' => false,
        'geminiMaxRetries' => 1,
    ],
];

require_once $contractServicePath;
require_once $geminiServicePath;

use app\services\GeminiService;
use yii\httpclient\TestTransport;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: {$needle}\nActual: {$haystack}\n");
        exit(1);
    }
}

function telemetryEvents(string $event): array
{
    $events = [];
    foreach (Yii::$logs as $record) {
        $message = (string)($record['message'] ?? '');
        if (strpos($message, 'NL2SQL telemetry: ') !== 0) {
            continue;
        }
        $payload = json_decode(substr($message, strlen('NL2SQL telemetry: ')), true);
        if (($payload['event'] ?? null) === $event) {
            $events[] = $payload;
        }
    }
    return $events;
}

function geminiSql(string $sql, string $explanation = 'Candidate query.'): string
{
    return "```sql\n{$sql}\n```\n{$explanation}\nDATA SOURCE: folio";
}

// This prompt names no campus, library, location, or holdings scope. On its own
// it resolves to no query family (freeform). With the resolver guidance appended
// it must still resolve to no family — routing must ignore guidance boilerplate.
$prompt = 'List item titles with material type "e-book" and item status of "in process".';

$followUpGenerationPrompt = implode("\n\n", [
    'This is a follow-up request to a previously generated library report.',
    'Previous request: Show active E-Books from MRBC.',
    'Previous SQL: SELECT title FROM inventory.instance__t',
    'Follow-up request: ' . $prompt,
]);
$generationTransport = null;
TestTransport::$responses = [
    geminiSql('SELECT ii.title FROM inventory.item__t AS ii LIMIT 100'),
];
TestTransport::$requests = [];
Yii::$logs = [];
$result = GeminiService::generateSqlWithShadow(
    $prompt,
    'Smith College',
    null,
    false,
    $followUpGenerationPrompt,
    $generationTransport
);

assertSameValue(
    'exploratory_legacy_freeform',
    $result['route'] ?? null,
    'A generic item listing with no location scope must route to freeform generation even when resolver guidance mentioning "library"/"campus" is appended; family routing must use the raw prompt.'
);
assertSameValue(
    'unsupported_query_family',
    $result['routeReason'] ?? null,
    'The raw prompt resolves to no query family, so the route reason must reflect the freeform/exploratory fallback rather than a contaminated family match.'
);
assertSameValue($prompt, $generationTransport['rawQuestion'] ?? null, 'Generation transport must retain the immutable raw question.');
assertContainsText('Previous SQL:', $generationTransport['generationPrompt'] ?? '', 'Generation transport must retain the expanded follow-up model context.');
assertContainsText('Reference resolver guidance:', $generationTransport['generationPrompt'] ?? '', 'Generation transport must retain resolver guidance for later model repair.');
assertSameValue(false, isset($result['_generationTransport']), 'Internal generation transport must not be serialized into the service response.');

$rawFingerprint = substr(hash('sha256', trim($prompt)), 0, 16);
$augmentedFingerprint = substr(hash('sha256', trim($generationTransport['generationPrompt'] ?? '')), 0, 16);
assertSameValue(false, $rawFingerprint === $augmentedFingerprint, 'Telemetry regression requires distinguishable raw and generated prompts.');
foreach (['nl2sql.generated', 'nl2sql.exploratory_notice_attached'] as $eventName) {
    $events = telemetryEvents($eventName);
    assertSameValue(1, count($events), "{$eventName} should be emitted once for the exploratory response.");
    assertSameValue($rawFingerprint, $events[0]['promptFingerprint'] ?? null, "{$eventName} must fingerprint only the raw question.");
}

$routedRawQuestion = 'For instance numbers in0001, in0002, show title, barcode, and publication date for material type E-Book. Limit 20.';
$routedFollowUpPrompt = implode("\n\n", [
    'This is a follow-up request to a previously generated library report.',
    'Previous request: Show E-Book titles.',
    'Previous SQL: SELECT title FROM inventory.instance__t',
    'Follow-up request: ' . $routedRawQuestion,
]);
$untrustedRoutedExplanation = "Plan: filter with inventory.material_type__t.name = 'E-Book'.";
$routedGenerationPrompt = \app\services\ReferenceResolverService::appendGuidanceToPrompt(
    $routedFollowUpPrompt,
    \app\services\ReferenceResolverService::resolvePrompt($routedRawQuestion)
);
$routedGenerationPrompt = \app\services\ExplicitReportRequestService::appendGuidance(
    $routedGenerationPrompt,
    \app\services\ExplicitReportRequestService::extract($routedRawQuestion)
);
TestTransport::$responses = [
    geminiSql("SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20"),
    geminiSql("SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20"),
];
TestTransport::$requests = [];
$modelEchoRecovery = GeminiService::repairExploratorySqlAfterPreflight(
    $routedRawQuestion,
    'Smith College',
    [
        'sql' => "SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20",
        'explanation' => $untrustedRoutedExplanation,
        'route' => 'legacy_fallback',
        'routeReason' => 'intent_contract_failed',
        'repairAttempts' => 0,
    ],
    'Explicit report values were not preserved.',
    $routedGenerationPrompt
);
assertSameValue(2, count(TestTransport::$requests), 'A routed candidate should consume exactly the shared two repair attempts before exhaustion.');
assertSameValue(2, $modelEchoRecovery['repairAttempts'] ?? null, 'Model-echo recovery must preserve the shared two-attempt cap.');
assertSameValue(false, isset($modelEchoRecovery['sql']), 'Model-echo exhaustion must not return invalid SQL.');
assertSameValue(
    false,
    strpos((string)($modelEchoRecovery['attemptedPlan'] ?? ''), "inventory.material_type__t.name = 'E-Book'") !== false,
    'Routed recovery attemptedPlan must not expose the resolver predicate echoed by the initial model explanation.'
);
assertSameValue(
    false,
    strpos(json_encode($modelEchoRecovery), $untrustedRoutedExplanation) !== false,
    'Routed service recovery must not expose the untrusted initial model explanation anywhere.'
);
if (isset($modelEchoRecovery['attemptedPlan'])) {
    assertSameValue(
        'server_defaults',
        $modelEchoRecovery['_attemptedPlanProvenance'] ?? null,
        'Any routed recovery attempted plan must carry explicit server-authored provenance.'
    );
}
$modelEchoRepairPayload = json_encode(TestTransport::$requests);
assertContainsText(
    $untrustedRoutedExplanation,
    $modelEchoRepairPayload,
    'The initial model explanation should remain available only inside model repair context.'
);
assertContainsText(
    'explicit_values_missing',
    $modelEchoRepairPayload,
    'Both routed repair attempts must fail through explicit-value validation.'
);

TestTransport::$responses = [
    geminiSql(
        "SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20",
        $untrustedRoutedExplanation
    ),
    geminiSql(
        "SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20",
        $untrustedRoutedExplanation
    ),
    geminiSql(
        "SELECT inst.title FROM inventory.instance__t inst WHERE inst.hrid = 'in0001' LIMIT 20",
        $untrustedRoutedExplanation
    ),
];
TestTransport::$requests = [];
$previousForceLegacy = Yii::$app->params['nl2sqlForceLegacy'];
Yii::$app->params['nl2sqlForceLegacy'] = true;
$routedTransport = null;
$routedRecovery = GeminiService::generateSqlWithShadow(
    $routedRawQuestion,
    'Smith College',
    null,
    false,
    $routedFollowUpPrompt,
    $routedTransport
);
Yii::$app->params['nl2sqlForceLegacy'] = $previousForceLegacy;
assertSameValue(3, count(TestTransport::$requests), 'Routed exhaustion should make one generation call plus exactly two repair calls.');
assertSameValue(2, $routedRecovery['repairAttempts'] ?? null, 'Routed exhaustion must preserve the shared two-attempt repair cap.');
assertSameValue(false, isset($routedRecovery['sql']), 'Routed exhaustion must not return invalid SQL.');
assertSameValue($routedRawQuestion, $routedRecovery['recoveryContext']['originalQuestion'] ?? null, 'Routed exhaustion must recover the exact latest raw question.');
assertSameValue(
    false,
    strpos((string)($routedRecovery['attemptedPlan'] ?? ''), "inventory.material_type__t.name = 'E-Book'") !== false,
    'Routed recovery attemptedPlan must not expose the resolver predicate echoed by the initial model explanation.'
);
assertSameValue(
    false,
    strpos((string)($routedRecovery['attemptedPlan'] ?? ''), $untrustedRoutedExplanation) !== false,
    'Routed recovery must never promote a model-authored explanation into the user-visible attempted plan.'
);
if (isset($routedRecovery['attemptedPlan'])) {
    assertSameValue(
        'server_defaults',
        $routedRecovery['_attemptedPlanProvenance'] ?? null,
        'Any routed recovery attempted plan must carry explicit server-authored provenance.'
    );
}
$routedRecoveryJson = json_encode($routedRecovery);
assertSameValue(false, strpos($routedRecoveryJson, 'Previous SQL:') !== false, 'Recovery must not expose follow-up generation context.');
assertSameValue(false, strpos($routedRecoveryJson, 'Reference resolver guidance:') !== false, 'Recovery must not expose resolver guidance.');
assertSameValue(
    false,
    strpos($routedRecoveryJson, "inventory.material_type__t.name = 'E-Book'") !== false,
    'Recovery must not expose the resolver guidance predicate even when its wrapper heading is absent.'
);
assertSameValue(false, strpos($routedRecoveryJson, 'EXPLICIT REPORT VALUES') !== false, 'Recovery must not expose explicit-value guidance.');
assertSameValue($routedRawQuestion, $routedTransport['rawQuestion'] ?? null, 'Routed transport must retain the exact raw question.');
assertContainsText('Previous SQL:', $routedTransport['generationPrompt'] ?? '', 'Routed transport must retain follow-up generation context.');
assertContainsText('Reference resolver guidance:', $routedTransport['generationPrompt'] ?? '', 'Routed transport must retain resolver guidance.');
assertContainsText('EXPLICIT REPORT VALUES', $routedTransport['generationPrompt'] ?? '', 'Routed transport must retain explicit guidance.');
$lastRepairPayload = json_encode(TestTransport::$requests[2] ?? []);
assertContainsText('MODEL GENERATION CONTEXT', $lastRepairPayload, 'Repair payload must label augmented model context accurately.');
assertSameValue(false, strpos($lastRepairPayload, 'ORIGINAL QUESTION') !== false, 'Repair payload must not label augmented model context as the original question.');

$effectivePrompt = \app\services\ReferenceResolverService::appendGuidanceToPrompt(
    $prompt,
    \app\services\ReferenceResolverService::resolvePrompt($prompt)
);
$intentRequestContext = new ReflectionMethod(GeminiService::class, 'buildIntentRequestContext');
$requestContext = $intentRequestContext->invoke(
    null,
    $effectivePrompt,
    'Smith College',
    'inventory.item__t(id, title)',
    $prompt
);
assertSameValue(
    null,
    $requestContext['queryFamily'] ?? null,
    'Intent-family selection must use the raw question while retaining resolver guidance in the model generation prompt.'
);

fwrite(STDOUT, "GeminiService resolver guidance routing test passed\n");
}
