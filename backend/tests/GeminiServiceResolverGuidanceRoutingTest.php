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
        public static $resolution = [];

        public static function resolvePrompt(string $prompt, $userId = null): array
        {
            return self::$resolution;
        }

        // Mirror the real boilerplate that every resolved reference appends. The
        // word "library" here is what used to contaminate family routing.
        public static function appendGuidanceToPrompt(string $prompt, array $referenceResolution): string
        {
            return rtrim($prompt) . "\n\nReference resolver guidance:\n" . implode("\n", self::guidanceLines());
        }

        public static function appendGenerationContextToPrompt(
            string $prompt,
            array $resolution,
            ?array $ambiguity = null
        ): string {
            $prompt = self::appendGuidanceToPrompt($prompt, $resolution);
            $lines = [];

            foreach (array_slice($resolution['unresolvedNamedIntents'] ?? [], 0, 8) as $intent) {
                $span = trim((string)($intent['span'] ?? ''));
                $dimension = trim((string)($intent['dimension'] ?? 'unknown'));
                if ($span !== '') {
                    $lines[] = 'Unresolved local term: ' . $span . ' (' . $dimension . ')';
                }
            }

            foreach (array_slice($resolution['clarificationItems'] ?? [], 0, 8) as $item) {
                $term = trim((string)($item['term'] ?? ''));
                $labels = [];
                foreach (array_slice($item['options'] ?? [], 0, 5) as $option) {
                    $label = trim((string)($option['label'] ?? ''));
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
                if ($term !== '' && $labels !== []) {
                    $lines[] = $term . ' candidate values: ' . implode('; ', array_values(array_unique($labels)));
                }
            }

            if ($ambiguity !== null && trim((string)($ambiguity['question'] ?? '')) !== '') {
                $lines[] = 'Advisory interpretation note: ' . trim((string)$ambiguity['question']);
            }

            return $lines === []
                ? $prompt
                : $prompt . "\n\nLocal reference generation context:\n- " . implode("\n- ", $lines);
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

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . "\nUnexpected: {$needle}\nActual: {$haystack}\n");
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

$realResolverPath = realpath(__DIR__ . '/../services/ReferenceResolverService.php');
$neilsonResolution = [
    'needsClarification' => true,
    'resolvedFilters' => [],
    'unresolvedNamedIntents' => [[
        'dimension' => 'library',
        'span' => 'Neilson Library',
    ]],
    'clarificationItems' => [[
        'term' => 'Neilson Library',
        'confidence' => 'ambiguous',
        'options' => [
            ['label' => 'SC Neilson Library'],
            ['label' => 'Neilson Library Annex'],
        ],
    ]],
];
$generationContextProbe = 'require ' . var_export($realResolverPath, true) . ';'
    . '$resolution=' . var_export($neilsonResolution, true) . ';'
    . 'echo app\\services\\ReferenceResolverService::appendGenerationContextToPrompt('
    . var_export('Show the 20 most-circulated books at Neilson Library during the last five years.', true)
    . ', $resolution);';
$generationContextLines = [];
$generationContextStatus = 0;
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($generationContextProbe), $generationContextLines, $generationContextStatus);
assertSameValue(0, $generationContextStatus, 'The real resolver generation-context probe must execute successfully.');
$generationContext = implode("\n", $generationContextLines);
assertContainsText(
    'Unresolved local term: Neilson Library (library)',
    $generationContext,
    'Raw named terms must reach generation.'
);
assertContainsText(
    'Neilson Library candidate values: SC Neilson Library; Neilson Library Annex',
    $generationContext,
    'Ranked candidates must reach generation.'
);
assertNotContainsText('ask the user', strtolower($generationContext), 'Context must not direct a blocker.');

\app\services\ReferenceResolverService::$resolution = $neilsonResolution;
TestTransport::$responses = [
    geminiSql('SELECT i.title FROM inventory.instance__t AS i LIMIT 20'),
];
TestTransport::$requests = [];
$neilsonTransport = null;
$previousNeilsonForceLegacy = Yii::$app->params['nl2sqlForceLegacy'];
Yii::$app->params['nl2sqlForceLegacy'] = true;
$neilsonResult = GeminiService::generateSqlWithShadow(
    'Show the 20 most-circulated books at Neilson Library during the last five years.',
    'Smith College',
    null,
    false,
    null,
    $neilsonTransport
);
Yii::$app->params['nl2sqlForceLegacy'] = $previousNeilsonForceLegacy;
assertSameValue(1, count(TestTransport::$requests), 'Enabled two-lane reference ambiguity must still make an AI request.');
assertContainsText('Unresolved local term: Neilson Library (library)', json_encode(TestTransport::$requests[0] ?? []), 'Unresolved reference context must reach the model request.');
assertContainsText('Neilson Library candidate values: SC Neilson Library; Neilson Library Annex', json_encode(TestTransport::$requests[0] ?? []), 'Reference candidates must reach the model request.');
assertContainsText('SELECT i.title', $neilsonResult['sql'] ?? '', 'Enabled two-lane reference ambiguity must return model SQL.');
assertSameValue(false, isset($neilsonResult['needsClarification']), 'Enabled two-lane response must not serialize resolver clarification state.');

$eBookResolution = [
    'needsClarification' => false,
    'guidanceLines' => [
        "- Resolved local reference: use exactly inventory.material_type__t.name = 'E-Book'. Do not apply this value to library or campus name columns. Do not add code filters unless the user explicitly asks to filter by code.",
    ],
];
\app\services\ReferenceResolverService::$resolution = $eBookResolution;
TestTransport::$responses = [
    geminiSql('SELECT i.title FROM inventory.instance__t AS i LIMIT 20'),
];
TestTransport::$requests = [];
$previousMrbcForceLegacy = Yii::$app->params['nl2sqlForceLegacy'];
Yii::$app->params['nl2sqlForceLegacy'] = true;
$mrbcResult = GeminiService::generateSqlWithShadow('List holdings in MRBC.', 'Smith College');
Yii::$app->params['nl2sqlForceLegacy'] = $previousMrbcForceLegacy;
assertSameValue(1, count(TestTransport::$requests), 'Enabled two-lane prompt ambiguity must still make an AI request.');
assertContainsText('Advisory interpretation note: Which rare book location do you mean?', json_encode(TestTransport::$requests[0] ?? []), 'Prompt ambiguity must reach the model only as advisory context.');
assertContainsText('SELECT i.title', $mrbcResult['sql'] ?? '', 'Enabled two-lane prompt ambiguity must return model SQL.');
assertSameValue(false, isset($mrbcResult['needsClarification']), 'Enabled two-lane response must not serialize prompt clarification state.');

\app\services\ReferenceResolverService::$resolution = $neilsonResolution;
TestTransport::$responses = [];
TestTransport::$requests = [];
$previousTwoLaneEnabled = Yii::$app->params['nl2sqlTwoLaneEnabled'] ?? null;
Yii::$app->params['nl2sqlTwoLaneEnabled'] = false;
$rollbackResult = GeminiService::generateSqlWithShadow(
    'Show the 20 most-circulated books at Neilson Library during the last five years.',
    'Smith College'
);
if ($previousTwoLaneEnabled === null) {
    unset(Yii::$app->params['nl2sqlTwoLaneEnabled']);
} else {
    Yii::$app->params['nl2sqlTwoLaneEnabled'] = $previousTwoLaneEnabled;
}
assertSameValue(0, count(TestTransport::$requests), 'Disabled two-lane mode must retain strict resolver clarification without an AI request.');
assertSameValue(true, $rollbackResult['needsClarification'] ?? false, 'Disabled two-lane mode must retain resolver clarification responses.');

\app\services\ReferenceResolverService::$resolution = $eBookResolution;
$structuredResolution = [
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
$guidanceProbe = 'require ' . var_export($realResolverPath, true) . ';'
    . '$resolution=' . var_export($structuredResolution, true) . ';'
    . 'echo app\\services\\ReferenceResolverService::appendGuidanceToPrompt("Prompt", $resolution);';
$guidanceLines = [];
$guidanceStatus = 0;
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($guidanceProbe), $guidanceLines, $guidanceStatus);
assertSameValue(0, $guidanceStatus, 'The real resolver guidance probe must execute successfully.');
$guidance = implode("\n", $guidanceLines);
assertContainsText(
    "inventory.loclibrary__t.name = 'SC Hillyer Art Library'",
    $guidance,
    'Guidance must preserve library scope.'
);
assertContainsText(
    "inventory.material_type__t.name IN ('Videocassette', 'DVD/Blu-ray')",
    $guidance,
    'Guidance must render explicit narrowed material values.'
);
assertNotContainsText('HC DVD', $guidance, 'Guidance must not contain unrelated location matches.');

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
    geminiSql("SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20"),
    geminiSql("SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20"),
];
TestTransport::$requests = [];
$modelEchoRecovery = GeminiService::repairExploratorySqlAfterPreflight(
    $routedRawQuestion,
    'Smith College',
    [
        'sql' => "SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20",
        'explanation' => $untrustedRoutedExplanation,
        'route' => 'legacy_fallback',
        'routeReason' => 'intent_contract_failed',
        'repairAttempts' => 0,
    ],
    'Explicit report values were not preserved.',
    $routedGenerationPrompt
);
assertSameValue(2, count(TestTransport::$requests), 'A routed candidate should consume exactly the shared two repair attempts before exhaustion.');
assertSameValue(2, $modelEchoRecovery['validationSummary']['repairAttempts'] ?? null, 'Model-echo terminal review must preserve the shared two-attempt cap.');
assertSameValue(false, isset($modelEchoRecovery['sql']), 'A final candidate with an unrequested explicit identifier must not remain executable.');
assertSameValue('sql_generation_failed', $modelEchoRecovery['errorType'] ?? null, 'Model-echo explicit-identifier exhaustion must remain terminal.');
assertSameValue(false, isset($modelEchoRecovery['assumptions']), 'Terminal explicit-identifier failures must not expose advisory assumptions.');
assertSameValue(
    false,
    strpos(json_encode($modelEchoRecovery), $untrustedRoutedExplanation) !== false,
    'Routed terminal result must not expose the untrusted initial model explanation anywhere.'
);
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
        "SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20",
        $untrustedRoutedExplanation
    ),
    geminiSql(
        "SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20",
        $untrustedRoutedExplanation
    ),
    geminiSql(
        "SELECT inst.title, item.barcode, inst.publication_date FROM inventory.instance__t inst JOIN inventory.item__t item ON item.id = inst.id WHERE inst.hrid IN ('in0001','in0002','in9999') LIMIT 20",
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
assertSameValue(2, $routedRecovery['validationSummary']['repairAttempts'] ?? null, 'Routed terminal review must preserve the shared two-attempt repair cap.');
assertSameValue(false, isset($routedRecovery['sql']), 'Routed explicit-identifier exhaustion must not retain executable SQL.');
assertSameValue('sql_generation_failed', $routedRecovery['errorType'] ?? null, 'Routed explicit-identifier exhaustion must remain terminal.');
assertSameValue(false, isset($routedRecovery['generationProvenance']), 'Terminal explicit-identifier failures must not claim success provenance.');
assertSameValue(false, isset($routedRecovery['assumptions']), 'Terminal explicit-identifier failures must not expose advisory assumptions.');
$routedUserResponseJson = json_encode(\app\services\AskResponseContractService::toUserResponse($routedRecovery));
assertSameValue(false, strpos($routedUserResponseJson, 'Previous SQL:') !== false, 'Terminal response must not expose follow-up generation context.');
assertSameValue(false, strpos($routedUserResponseJson, 'Reference resolver guidance:') !== false, 'Terminal response must not expose resolver guidance.');
assertSameValue(
    false,
    strpos($routedUserResponseJson, "inventory.material_type__t.name = 'E-Book'") !== false,
    'Terminal response must not expose the resolver guidance predicate even when its wrapper heading is absent.'
);
assertSameValue(false, strpos($routedUserResponseJson, 'EXPLICIT REPORT VALUES') !== false, 'Terminal response must not expose explicit-value guidance.');
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
$intentRequestContext->setAccessible(true);
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
