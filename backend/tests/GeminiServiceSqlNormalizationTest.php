<?php

$geminiServicePath = __DIR__ . '/../services/GeminiService.php';
if (!file_exists($geminiServicePath)) {
    fwrite(STDERR, "GeminiService is missing at {$geminiServicePath}\n");
    exit(1);
}

if (!class_exists('Yii')) {
    class Yii
    {
        public static $app;
        public static function getAlias($alias) { return $alias; }
        public static function info($message, $category = null) {}
        public static function warning($message, $category = null) {}
        public static function error($message, $category = null) {}
    }
}

Yii::$app = (object) ['params' => []];

require_once $geminiServicePath;

use app\services\GeminiService;

function assertContainsSql(string $needle, string $sql, string $message): void
{
    if (stripos($sql, $needle) === false) {
        fwrite(STDERR, $message . "\nSQL:\n" . $sql . "\n");
        exit(1);
    }
}

$baseSql = <<<SQL
SELECT po.po_number, pol.order_format, pol.payment_status
FROM orders.po_line__t AS pol
JOIN orders.purchase_order__t AS po
  ON pol.purchase_order_id = po.id
WHERE %s
SQL;

$cases = [
    "LOWER(pol.order_format) = LOWER('Ongoing')",
    "LOWER(pol.payment_status) = LOWER('Ongoing')",
    "pol.order_format = 'Ongoing'",
    "pol.payment_status ILIKE 'Ongoing'",
];

foreach ($cases as $predicate) {
    $normalized = GeminiService::normalizeGeneratedSql(sprintf($baseSql, $predicate));
    assertContainsSql(
        "LOWER(po.order_type) = LOWER('Ongoing')",
        $normalized,
        'Standing-order predicate should be normalized to purchase_order.order_type'
    );
}

echo "GeminiServiceSqlNormalizationTest passed\n";
