<?php

if (!class_exists('Yii')) {
    class Yii
    {
        public static function info($message, $category = null): void {}
    }
}

require_once __DIR__ . '/../exceptions/ExploratorySqlValidationException.php';
require_once __DIR__ . '/../services/AskRequestPolicyService.php';
require_once __DIR__ . '/../services/AskGenerationCoordinatorService.php';
require_once __DIR__ . '/../services/FolioSchemaService.php';
require_once __DIR__ . '/../services/SqlBuilderService.php';

use app\services\AskGenerationCoordinatorService;
use app\services\SqlBuilderService;

function vendorRegressionAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function vendorRegressionAssertContains(string $needle, string $haystack, string $message): void
{
    if (stripos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: {$needle}\n");
        exit(1);
    }
}

$question = 'For the last three completed fiscal years, summarize the time from purchase-order creation to receipt by vendor. Include vendor, fiscal year, received line count, average days to receipt, median days to receipt, and percentage received within 90 days. Include only vendors with at least 20 received lines.';
$unsafeCandidate = 'DELETE FROM orders.pieces__t';
$safeCandidate = <<<'SQL'
WITH received_lines AS (
    SELECT
        v.name AS vendor,
        fy.name AS fiscal_year,
        EXTRACT(EPOCH FROM (p.receipt_date - po.date_ordered)) / 86400.0 AS days_to_receipt
    FROM orders.pieces__t p
    JOIN orders.po_line__t pol ON pol.id = p.po_line_id
    JOIN orders.purchase_order__t po ON po.id = pol.purchase_order_id
    JOIN organizations.organizations__t v ON v.id = po.vendor
    JOIN finance.fiscal_year__t fy ON po.date_ordered >= fy.period_start AND po.date_ordered < fy.period_end
    WHERE p.receipt_date IS NOT NULL
      AND po.date_ordered IS NOT NULL
)
SELECT
    vendor,
    fiscal_year,
    COUNT(*) AS received_line_count,
    AVG(days_to_receipt) AS average_days_to_receipt,
    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY days_to_receipt) AS median_days_to_receipt,
    100.0 * AVG(CASE WHEN days_to_receipt <= 90 THEN 1.0 ELSE 0.0 END) AS percentage_received_within_90_days
FROM received_lines
GROUP BY vendor, fiscal_year
HAVING COUNT(*) >= 20
ORDER BY fiscal_year, vendor
SQL;

$transportRequestCount = 0;
$preflightCount = 0;
$result = AskGenerationCoordinatorService::run(
    $question,
    function () use (&$transportRequestCount, $unsafeCandidate): array {
        $transportRequestCount++;
        try {
            SqlBuilderService::validateSafety($unsafeCandidate);
            fwrite(STDERR, "The unsafe first candidate unexpectedly passed the SQL safety gate.\n");
            exit(1);
        } catch (InvalidArgumentException $expected) {
            return [
                'state' => 'candidate_rejected',
                'reason' => 'non_select',
                'candidateSqlHash' => hash('sha256', $unsafeCandidate),
            ];
        }
    },
    function () use (&$transportRequestCount, &$preflightCount, $safeCandidate): array {
        $transportRequestCount++;
        SqlBuilderService::validateSafety($safeCandidate);
        SqlBuilderService::validateTablePolicy($safeCandidate);
        $preflightCount++;
        return [
            'state' => 'handled',
            'result' => [
                'sql' => $safeCandidate,
                'generationProvenance' => 'ai_built',
                'route' => 'exploratory',
                'validationSummary' => ['status' => 'validated', 'repairAttempts' => 1],
            ],
        ];
    }
);

vendorRegressionAssertSame('ai_built', $result['generationProvenance'] ?? null, 'Vendor receipt report must be AI-built.');
vendorRegressionAssertSame(false, isset($result['errorType']), 'A rejected first candidate must not become a terminal response.');
vendorRegressionAssertSame(2, $transportRequestCount, 'One unsafe candidate must cost one fresh generation.');
vendorRegressionAssertSame(1, $preflightCount, 'The safe replacement must pass the preflight boundary.');
vendorRegressionAssertContains('SELECT', $result['sql'] ?? '', 'The final report must contain executable read-only SQL.');
vendorRegressionAssertContains('PERCENTILE_CONT(0.5)', $result['sql'] ?? '', 'The report must retain the requested median calculation.');
vendorRegressionAssertContains('HAVING COUNT(*) >= 20', $result['sql'] ?? '', 'The report must retain the vendor line-count threshold.');

fwrite(STDOUT, "Ask AI-always vendor receipt regression test passed\n");
