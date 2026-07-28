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
                'invoice_lines',
                'invoices',
                'po_lines',
                'purchase_orders',
                'items',
                'audit_loans',
                'holdings_records',
                'instances',
                'classifications',
            ];
        }

        public static function discoverTableMapping(): array
        {
            return [
                'invoice_lines_fund_distributions' => 'invoice.invoice_lines__t__fund_distributions',
                'invoice_lines' => 'invoice.invoice_lines__t',
                'invoices' => 'invoice.invoices__t',
                'po_lines' => 'orders.po_line__t',
                'purchase_orders' => 'orders.purchase_order__t',
                'items' => 'inventory.item__t',
                'audit_loans' => 'circulation.audit_loan__t',
                'holdings_records' => 'inventory.holdings_record__t',
                'instances' => 'inventory.instance__t',
                'classifications' => 'classification.classification__t',
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
    // This fixture verifies the original legacy repair contract through the rollback path.
    'nl2sqlHardenedPhysicalRoi' => false,
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

function roiRegressionAssertNoSqlLeak($value, array $rejectedCandidates, string $path = 'response'): void
{
    if (is_array($value)) {
        foreach ($value as $key => $nestedValue) {
            $nestedPath = $path . '.' . (string)$key;
            if (strtolower((string)$key) === 'sql') {
                fwrite(STDERR, "Exhausted recovery must not contain an SQL field at {$nestedPath}.\n");
                exit(1);
            }
            roiRegressionAssertNoSqlLeak($nestedValue, $rejectedCandidates, $nestedPath);
        }
        return;
    }

    if (!is_string($value)) {
        return;
    }

    foreach ($rejectedCandidates as $candidate) {
        if (strpos($value, $candidate) !== false) {
            fwrite(STDERR, "Exhausted recovery leaked rejected candidate SQL at {$path}.\n");
            exit(1);
        }
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
    JOIN invoice.invoice_lines__t invoice_line ON invoice_line.id = fd.id
    JOIN invoice.invoices__t invoice ON invoice.id = invoice_line.invoice_id
    JOIN orders.po_line__t pol ON pol.id = fd.po_line_id
    WHERE invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'
    GROUP BY pol.instance_id
), circulation_by_item AS (
    SELECT item.id AS item_id,
           item.holdings_record_id,
           COUNT(audit_loan.created_date) AS checkouts
    FROM inventory.item__t item
    LEFT JOIN circulation.audit_loan__t audit_loan
      ON audit_loan.loan__item_id = item.id
     AND audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')
     AND audit_loan.created_date >= CURRENT_DATE - INTERVAL '5 years'
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
ORDER BY purchase_count DESC
SQL;

$capturedProductionSql = <<<'SQL'
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

RoiTestTransport::$responses = [
    roiRegressionGeminiText($capturedProductionSql),
    roiRegressionGeminiText($validRoiSql),
];
RoiTestTransport::$requests = [];

roiRegressionAssertSame(
    false,
    Yii::$app->params['nl2sqlHardenedPhysicalRoi'],
    'This regression must explicitly exercise rollback and legacy repair semantics.'
);
$repaired = GeminiService::generateSqlWithShadow($question);
roiRegressionAssertSame($validRoiSql, $repaired['sql'] ?? null, 'The motivating request should return the validated repair candidate.');
roiRegressionAssertSame(1, $repaired['repairAttempts'] ?? null, 'Five semantic defects should trigger one automatic repair.');
roiRegressionAssertSame('validated', $repaired['validationSummary']['status'] ?? null, 'The repaired candidate should be marked validated.');
roiRegressionAssertSame('validated', $repaired['semanticValidation']['status'] ?? null, 'Returned exploratory SQL must pass semantic conformance.');
roiRegressionAssertSame(2, $repaired['semanticValidation']['contractVersion'] ?? null, 'The response must identify the checked contract version.');
roiRegressionAssertSame(12, count($repaired['semanticValidation']['checkedRequirements'] ?? []), 'Every ROI requirement must be checked.');
roiRegressionAssertSame('unsupported_query_family', $repaired['routeReason'] ?? null, 'The motivating request should enter exploratory generation through normal unsupported-family routing.');
roiRegressionAssertSame(false, isset($repaired['needsExploratoryApproval']), 'Unsupported routing should omit the obsolete exploratory approval gate.');
roiRegressionAssertSame(
    $expectedAssumptionValues,
    array_column($repaired['assumptions'] ?? [], 'value', 'key'),
    'The motivating request should receive the exact five documented defaults.'
);
roiRegressionAssertSame(5, count($repaired['assumptions'] ?? []), 'The motivating request should receive exactly five assumptions without duplicates.');
foreach (['spend_by_instance', 'circulation_by_item', 'purchase_count', 'spend', 'circulation', 'checkouts_per_dollar', 'cost_per_checkout'] as $requiredSqlShape) {
    roiRegressionAssertContains($requiredSqlShape, $repaired['sql'] ?? '', "The repaired SQL should retain {$requiredSqlShape}.");
}
roiRegressionAssertContains('invoice.invoices__t invoice', $repaired['sql'] ?? '', 'The repaired SQL should anchor purchase dates to invoices.');
roiRegressionAssertContains("invoice.payment_date >= CURRENT_DATE - INTERVAL '5 years'", $repaired['sql'] ?? '', 'The repaired SQL should use invoice payment date for the last-five-years purchase window.');
roiRegressionAssertContains('circulation.audit_loan__t audit_loan', $repaired['sql'] ?? '', 'The repaired SQL should use checkout audit events for circulation.');
roiRegressionAssertContains('audit_loan.loan__item_id = item.id', $repaired['sql'] ?? '', 'The repaired SQL should join checkout audit events at item grain.');
roiRegressionAssertContains("audit_loan.loan__action IN ('checkedout', 'checkedOutThroughOverride')", $repaired['sql'] ?? '', 'The repaired SQL should count only checkout actions.');
roiRegressionAssertContains("audit_loan.created_date >= CURRENT_DATE - INTERVAL '5 years'", $repaired['sql'] ?? '', 'The repaired SQL should apply the matching last-five-years window to checkout events.');
roiRegressionAssertContains('ORDER BY purchase_count DESC', $repaired['sql'] ?? '', 'The repaired SQL should rank call-number classes by purchases made most.');
roiRegressionAssertSame(2, count(RoiTestTransport::$requests), 'Success after one repair should make exactly two model calls.');

$repairPrompt = json_encode(RoiTestTransport::$requests[1]);
roiRegressionAssertContains('purchase_date_basis', $repairPrompt, 'Repair feedback must identify the unmet date requirement.');
roiRegressionAssertContains('spend_grain', $repairPrompt, 'Repair feedback must identify the unsafe grain.');
roiRegressionAssertContains('purchase_ranking', $repairPrompt, 'Repair feedback must identify missing ranking.');
roiRegressionAssertContains('governed_filters', $repairPrompt, 'Repair feedback must identify unrequested filters.');
roiRegressionAssertContains('numeric_output_types', $repairPrompt, 'Repair feedback must identify formatted numeric outputs.');
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
$rejectedCandidates = [
    'SELECT first.id FROM inventory.missing_first__t first',
    'SELECT second.id FROM inventory.missing_second__t second',
    'SELECT third.id FROM inventory.missing_third__t third',
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
roiRegressionAssertSame(5, count($exhausted['assumptions'] ?? []), 'Exhausted recovery should preserve exactly five assumptions without duplicates.');
roiRegressionAssertContains('ROI PLAN GUIDANCE', $exhausted['attemptedPlan'] ?? '', 'Exhausted recovery should preserve the attempted plan.');
roiRegressionAssertSame('unknown_table', $exhausted['validationSummary']['failureCategory'] ?? null, 'Exhausted recovery should expose a safe failure category.');
roiRegressionAssertSame(
    ['Retry the request.', 'Correct an assumption.', 'Narrow the period or output.'],
    $exhausted['suggestions'] ?? null,
    'Exhausted recovery should provide actionable suggestions.'
);
$ordinaryExhausted = $exhausted;
unset($ordinaryExhausted['_askEvidence']);
roiRegressionAssertNoSqlLeak($ordinaryExhausted, $rejectedCandidates);
roiRegressionAssertSame($rejectedCandidates[0], $exhausted['_askEvidence']['initialSql'] ?? null, 'Trusted exhausted evidence must retain the initial rejected candidate for structural persistence.');
roiRegressionAssertSame($rejectedCandidates[2], $exhausted['_askEvidence']['finalSql'] ?? null, 'Trusted exhausted evidence must retain the last rejected candidate for structural persistence.');

fwrite(STDOUT, "Ask AI cross-domain ROI regression test passed\n");
}
