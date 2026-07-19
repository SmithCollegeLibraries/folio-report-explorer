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
            return 'SCOPED SCHEMA';
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
            return null;
        }
    }

    class SqlBuilderService
    {
        public static function validateSafety($sql): void
        {
            if (preg_match('/^\s*SELECT\b|^\s*WITH\b/i', (string)$sql) !== 1) {
                throw new \InvalidArgumentException('Only SELECT queries are allowed.');
            }
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

class Yii
{
    public static $app;
    public static $logs = [];
    public static $aliases = [];

    public static function getAlias($alias) { return self::$aliases[$alias] ?? (__DIR__ . '/../data/settings.json'); }
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

use app\services\GeminiService;
use yii\httpclient\TestTransport;

function routingAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function routingAssertContains(string $needle, string $haystack, string $message): void
{
    routingAssertSame(true, strpos($haystack, $needle) !== false, $message);
}

function routingCandidate(): string
{
    $sql = <<<'SQL'
SELECT pc.call_number_class,
       SUM(ilt.total) AS total_spent,
       COUNT(DISTINCT pol.id) AS purchase_count,
       SUM(ilt.total) / NULLIF(COUNT(al.id), 0) AS cost_per_checkout
FROM orders.po_line__t pol
JOIN orders.purchase_order__t pot ON pot.id = pol.purchase_order_id
JOIN invoice.invoice_lines__t ilt ON ilt.po_line_id = pol.id
JOIN inventory.item__t item ON item.material_type_id = 'book'
LEFT JOIN circulation.audit_loan__t al ON al.loan__item_id = item.id
JOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id
JOIN inventory.instance__t instance ON instance.id = holdings.instance_id
JOIN classification.classification__t pc ON pc.instance_id = instance.id
WHERE pot.date_ordered >= CURRENT_DATE - INTERVAL '5 years'
GROUP BY pc.call_number_class
SQL;
    return "```sql\n{$sql}\n```\nCandidate query.\nDATA SOURCE: folio";
}

function runRoutingScenario(?bool $enabled): array
{
    if ($enabled === null) {
        unset(Yii::$app->params['nl2sqlHardenedPhysicalRoi']);
    } else {
        Yii::$app->params['nl2sqlHardenedPhysicalRoi'] = $enabled;
    }
    TestTransport::$responses = [routingCandidate(), routingCandidate(), routingCandidate()];
    TestTransport::$requests = [];

    $prompt = 'Show ROI for purchases and circulation by call number, including checkouts and investment.';
    $result = GeminiService::generateSqlWithShadow($prompt, 'Smith College', null, true);
    $result['requestCount'] = count(TestTransport::$requests);
    return $result;
}

function checklistLabels(array $result): string
{
    return implode("\n", array_map(
        static function (array $requirement): string {
            return (string)($requirement['label'] ?? '');
        },
        $result['semanticValidation']['checkedRequirements'] ?? []
    ));
}

$defaultRoute = runRoutingScenario(null);
routingAssertSame('physical_roi_v2', $defaultRoute['compilerVersion'] ?? null, 'A missing flag must default to v2.');
routingAssertContains('orders.pieces__t', $defaultRoute['sql'] ?? '', 'The default route must include exact linkage.');
routingAssertSame(3, $defaultRoute['requestCount'], 'The default route must consume the initial candidate and two repairs.');

$v2 = runRoutingScenario(true);
routingAssertSame('physical_roi_v2', $v2['compilerVersion'] ?? null, 'The enabled route must use v2.');
routingAssertContains('orders.pieces__t', $v2['sql'] ?? '', 'The enabled route must include exact linkage.');
routingAssertSame(3, $v2['requestCount'], 'The enabled route must consume the initial candidate and two repairs.');
routingAssertSame(4, count($v2['reportDisclosures'] ?? []), 'The v2 compiler disclosures must survive response decoration.');
routingAssertContains('physical copies purchased', strtolower(checklistLabels($v2)), 'The v2 checklist must describe physical-copy measures.');

$legacy = runRoutingScenario(false);
routingAssertSame(false, isset($legacy['compilerVersion']), 'The rollback route must retain the legacy result.');
routingAssertContains('WITH spend_by_instance AS', $legacy['sql'] ?? '', 'Rollback must use the existing compiler.');
routingAssertSame(3, $legacy['requestCount'], 'The rollback route must consume the initial candidate and two repairs.');
routingAssertContains('purchase count', strtolower(checklistLabels($legacy)), 'Rollback must retain the legacy measure checklist.');
routingAssertSame(false, strpos(strtolower(checklistLabels($legacy)), 'physical copies purchased') !== false, 'Rollback must not use v2 physical-copy requirements.');
}
