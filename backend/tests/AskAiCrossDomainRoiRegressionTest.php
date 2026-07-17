<?php

namespace yii\httpclient {
    class Client
    {
        public $transport;

        public function createRequest() { return new Request(); }
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
            RoiTestTransport::$requests[] = json_decode($this->content, true);
            $text = array_shift(RoiTestTransport::$responses);
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

    class RoiTestTransport
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
                'invoice_lines_fund_distributions',
                'po_lines',
                'items',
                'loans',
                'holdings_records',
                'instances',
            ];
        }

        public static function discoverTableMapping(): array
        {
            return [
                'invoice_lines_fund_distributions' => 'invoice.invoice_lines__t__fund_distributions',
                'po_lines' => 'orders.po_line__t',
                'items' => 'inventory.item__t',
                'loans' => 'circulation.loan__t',
                'holdings_records' => 'inventory.holdings_record__t',
                'instances' => 'inventory.instance__t',
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
        public static function validateSafety($sql): void
        {
            if (preg_match('/^\s*SELECT\b|^\s*WITH\b/i', (string)$sql) !== 1) {
                throw new \InvalidArgumentException('Only SELECT queries are allowed.');
            }
        }

        public static function validateTablePolicy($sql): void {}
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
    public static function info($message, $category = null) { self::$logs[] = ['level' => 'info', 'message' => $message]; }
    public static function warning($message, $category = null) { self::$logs[] = ['level' => 'warning', 'message' => $message]; }
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
use yii\httpclient\RoiTestTransport;

function roiRegressionAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function roiRegressionAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: {$needle}\nActual: {$haystack}\n");
        exit(1);
    }
}

function roiRegressionGeminiText(string $sql): string
{
    return "```sql\n{$sql}\n```\nCandidate query.\nDATA SOURCE: folio";
}

$question = 'Show me which call numbers we have purchased the most from the last 5 years. Compare circulation data to those call numbers and show the return on investment.';
$expectedAssumptionValues = [
    'call_number_grouping' => 'primary_call_number_class',
    'circulation_window' => 'same_as_purchase_window',
    'investment_cost_basis' => 'actual_paid_fund_distribution',
    'purchase_date_basis' => 'payment_date',
    'roi_formula' => 'checkouts_per_dollar_with_cost_per_use',
];
$validRoiSql = <<<'SQL'
WITH spend_by_instance AS (
    SELECT pol.instance_id,
           COUNT(DISTINCT pol.id) AS purchase_count,
           SUM(fd.total * fd.fund_distributions__value * 0.01) AS spend
    FROM invoice.invoice_lines__t__fund_distributions fd
    JOIN orders.po_line__t pol ON pol.id = fd.po_line_id
    GROUP BY pol.instance_id
), circulation_by_item AS (
    SELECT item.id AS item_id,
           item.holdings_record_id,
           COUNT(loan.id) AS checkouts
    FROM inventory.item__t item
    LEFT JOIN circulation.loan__t loan ON loan.item_id = item.id
    GROUP BY item.id, item.holdings_record_id
), circulation_by_instance AS (
    SELECT holdings.instance_id,
           SUM(circulation_by_item.checkouts) AS circulation
    FROM circulation_by_item
    JOIN inventory.holdings_record__t holdings ON holdings.id = circulation_by_item.holdings_record_id
    GROUP BY holdings.instance_id
), class_by_instance AS (
    SELECT instance.id AS instance_id,
           MIN(SUBSTRING(holdings.effective_call_number_components__call_number FROM '^[A-Za-z]+')) AS call_number_class
    FROM inventory.instance__t instance
    JOIN inventory.holdings_record__t holdings ON holdings.instance_id = instance.id
    GROUP BY instance.id
)
SELECT class_by_instance.call_number_class,
       SUM(spend_by_instance.purchase_count) AS purchase_count,
       SUM(spend_by_instance.spend) AS spend,
       SUM(circulation_by_instance.circulation) AS circulation,
       SUM(circulation_by_instance.circulation) / NULLIF(SUM(spend_by_instance.spend), 0) AS checkouts_per_dollar,
       SUM(spend_by_instance.spend) / NULLIF(SUM(circulation_by_instance.circulation), 0) AS cost_per_checkout
FROM spend_by_instance
JOIN class_by_instance ON class_by_instance.instance_id = spend_by_instance.instance_id
LEFT JOIN circulation_by_instance ON circulation_by_instance.instance_id = spend_by_instance.instance_id
GROUP BY class_by_instance.call_number_class
SQL;

RoiTestTransport::$responses = [
    roiRegressionGeminiText('SELECT missing.id FROM inventory.missing_table__t missing'),
    roiRegressionGeminiText($validRoiSql),
];
RoiTestTransport::$requests = [];

$repaired = GeminiService::generateSqlWithShadow($question, 'Smith College');
roiRegressionAssertSame($validRoiSql, $repaired['sql'] ?? null, 'The motivating request should return the validated repair candidate.');
roiRegressionAssertSame(1, $repaired['repairAttempts'] ?? null, 'The motivating request should succeed after one repair.');
roiRegressionAssertSame('validated', $repaired['validationSummary']['status'] ?? null, 'The repaired candidate should be marked validated.');
roiRegressionAssertSame('unsupported_query_family', $repaired['routeReason'] ?? null, 'The motivating request should enter exploratory generation through normal unsupported-family routing.');
roiRegressionAssertSame(false, $repaired['needsExploratoryApproval'] ?? null, 'Unsupported routing should not require an exploratory approval gate.');
roiRegressionAssertSame(
    $expectedAssumptionValues,
    array_column($repaired['assumptions'] ?? [], 'value', 'key'),
    'The motivating request should receive the exact five documented defaults.'
);
foreach (['spend_by_instance', 'circulation_by_item', 'purchase_count', 'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout'] as $requiredSqlShape) {
    roiRegressionAssertContains($requiredSqlShape, $repaired['sql'] ?? '', "The repaired SQL should retain {$requiredSqlShape}.");
}
roiRegressionAssertSame(2, count(RoiTestTransport::$requests), 'Success after one repair should make exactly two model calls.');

$repairPrompt = json_encode(RoiTestTransport::$requests[1]);
roiRegressionAssertContains('po_line_id', $repairPrompt, 'The repair prompt should preserve the invoice-to-PO-line join guidance.');
roiRegressionAssertContains('orders.po_line__t.instance_id', $repairPrompt, 'The repair prompt should preserve the PO-line-to-instance join guidance.');
roiRegressionAssertContains('Aggregate spend before joining item-level circulation', $repairPrompt, 'The repair prompt should preserve spend pre-aggregation guidance.');
roiRegressionAssertContains('Aggregate circulation at item grain', $repairPrompt, 'The repair prompt should preserve item-grain circulation guidance.');
roiRegressionAssertContains('cost per checkout', $repairPrompt, 'The repair prompt should preserve the companion cost-per-checkout output.');

RoiTestTransport::$responses = [
    roiRegressionGeminiText('SELECT first.id FROM inventory.missing_first__t first'),
    roiRegressionGeminiText('SELECT second.id FROM inventory.missing_second__t second'),
    roiRegressionGeminiText('SELECT third.id FROM inventory.missing_third__t third'),
];
RoiTestTransport::$requests = [];

$exhausted = GeminiService::generateSqlWithShadow($question, 'Smith College');
roiRegressionAssertSame(false, array_key_exists('sql', $exhausted), 'Exhausted recovery must not expose unvalidated SQL.');
roiRegressionAssertSame(2, $exhausted['repairAttempts'] ?? null, 'Exhaustion must stop after two repair calls.');
roiRegressionAssertSame(3, count(RoiTestTransport::$requests), 'Exhaustion should make one initial call and no more than two repairs.');
roiRegressionAssertSame($question, $exhausted['recoveryContext']['originalQuestion'] ?? null, 'Exhausted recovery should preserve the original question.');
roiRegressionAssertSame(
    $expectedAssumptionValues,
    array_column($exhausted['assumptions'] ?? [], 'value', 'key'),
    'Exhausted recovery should preserve the exact five assumptions.'
);
roiRegressionAssertContains('ROI PLAN GUIDANCE', $exhausted['attemptedPlan'] ?? '', 'Exhausted recovery should preserve the attempted plan.');
roiRegressionAssertSame('unknown_table', $exhausted['validationSummary']['failureCategory'] ?? null, 'Exhausted recovery should expose a safe failure category.');
roiRegressionAssertSame(
    ['Retry the request.', 'Correct an assumption.', 'Narrow the period or output.'],
    $exhausted['suggestions'] ?? null,
    'Exhausted recovery should provide actionable suggestions.'
);

fwrite(STDOUT, "Ask AI cross-domain ROI regression test passed\n");
}
