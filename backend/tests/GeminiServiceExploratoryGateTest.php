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
            return $this;
        }

        public function send()
        {
            return new Response();
        }
    }

    class Response
    {
        public $isOk = true;
        public $statusCode = 200;
        public $content = '{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"```sql\nSELECT inv.id FROM invoice.invoice__t AS inv LIMIT 10\n```\nExploratory invoice spend query.\nDATA SOURCE: folio"}]}}]}';
    }
}

namespace app\services {
    class ReferenceResolverService
    {
        public static function resolvePrompt(string $prompt, $userId = null): array
        {
            return [
                'needsClarification' => false,
                'guidanceLines' => [],
            ];
        }

        public static function appendGuidanceToPrompt(string $prompt, array $referenceResolution): string
        {
            return $prompt;
        }
    }

    class FolioSchemaService
    {
        public static function buildSchemaContext($prompt = null): string
        {
            return 'invoice.invoice__t(id)';
        }

        public static function getMetadata(): array
        {
            return ['scraped_at' => 'test'];
        }

        public static function getTableNames(): array
        {
            return ['invoice__t'];
        }

        public static function discoverTableMapping(): array
        {
            return ['invoice__t' => 'invoice.invoice__t'];
        }

        public static function fuzzyMatch($table)
        {
            return $table === 'invoice__t' ? 'invoice__t' : null;
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

        public static function getAlias($alias)
        {
            return __DIR__ . '/../data/settings.json';
        }

        public static function info($message, $category = null)
        {
        }

        public static function warning($message, $category = null)
        {
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
        fwrite(STDERR, $message . "\nMissing text: {$needle}\nActual: {$haystack}\n");
        exit(1);
    }
}

$unsupported = GeminiService::generateSqlWithShadow(
    'Show vendors with the highest invoice spend last fiscal year.',
    'Smith College',
    null,
    false
);

assertSameValue(false, $unsupported['needsClarification'] ?? false, 'Unsupported prompts should not require clarification when no ambiguous term was found.');
assertSameValue(false, $unsupported['needsExploratoryApproval'] ?? false, 'Unsupported prompts should not require explicit exploratory approval before SQL generation.');
assertSameValue('exploratory_legacy_freeform', $unsupported['route'] ?? null, 'Unsupported prompts should continue through exploratory legacy SQL generation.');
assertSameValue('unsupported_query_family', $unsupported['routeReason'] ?? null, 'Unsupported prompts should preserve the route reason that forced exploratory generation.');
assertSameValue('exploratory', $unsupported['mode'] ?? null, 'Unsupported prompts should be labeled as exploratory.');
assertContainsText('SELECT', strtoupper($unsupported['sql'] ?? ''), 'Unsupported prompts should return generated exploratory SQL.');
assertSameValue(0, $unsupported['repairAttempts'] ?? null, 'A valid initial exploratory candidate should report zero repairs.');
assertSameValue('validated', $unsupported['validationSummary']['status'] ?? null, 'Exploratory SQL should expose its validation status.');
assertSameValue([], $unsupported['assumptions'] ?? null, 'Prompts without documented defaults should expose an empty assumption list.');
assertSameValue(
    'AI-assisted query',
    $unsupported['exploratoryNotice']['title'] ?? null,
    'Exploratory results should include staff-facing notice metadata.'
);
assertContainsText(
    'verified report pattern',
    $unsupported['exploratoryNotice']['message'] ?? '',
    'Exploratory notice should explain the limitation without internal compiler terms.'
);
assertContainsText(
    'Review the results and SQL',
    $unsupported['exploratoryNotice']['message'] ?? '',
    'Exploratory notice should tell staff what action to take.'
);
assertSameValue(
    'unsupported_query_family',
    $unsupported['exploratoryNotice']['reason'] ?? null,
    'Exploratory notice should expose a stable reason for telemetry and review queues.'
);

$source = file_get_contents($geminiServicePath);
assertContainsText(
    'buildExploratoryNotice',
    $source,
    'GeminiService should centralize exploratory notice copy and metadata.'
);
assertContainsText(
    'unsupported_query_family',
    $source,
    'Unsupported family prompts should preserve a stable exploratory reason.'
);
assertContainsText(
    'exploratory_legacy_freeform',
    $source,
    'Unsupported family prompts should be labeled as exploratory SQL generation.'
);

fwrite(STDOUT, "GeminiService exploratory gate test passed\n");
}
