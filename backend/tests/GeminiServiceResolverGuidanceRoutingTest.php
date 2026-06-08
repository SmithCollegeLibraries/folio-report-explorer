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
        public $content = '{"candidates":[{"finishReason":"STOP","content":{"parts":[{"text":"```sql\nSELECT ii.title FROM inventory.item__t AS ii LIMIT 100\n```\nFreeform item listing.\nDATA SOURCE: folio"}]}}]}';
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
            return ['item__t'];
        }

        public static function discoverTableMapping(): array
        {
            return ['item__t' => 'inventory.item__t'];
        }

        public static function fuzzyMatch($table)
        {
            return $table === 'item__t' ? 'item__t' : null;
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

// This prompt names no campus, library, location, or holdings scope. On its own
// it resolves to no query family (freeform). With the resolver guidance appended
// it must still resolve to no family — routing must ignore guidance boilerplate.
$prompt = 'List of items with material type "e-book" and item status of "in process". Include title, barcode and instance number.';

$result = GeminiService::generateSqlWithShadow($prompt, 'Smith College', null, false);

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

fwrite(STDOUT, "GeminiService resolver guidance routing test passed\n");
}
