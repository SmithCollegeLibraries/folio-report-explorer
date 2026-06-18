<?php

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

if (!class_exists('Yii')) {
    class FolioSchemaAcquisitionsJoinContextFakeCommand
    {
        public function queryAll()
        {
            return [];
        }
    }

    class FolioSchemaAcquisitionsJoinContextFakeDb
    {
        public function quoteValue($value)
        {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        public function createCommand($sql)
        {
            return new FolioSchemaAcquisitionsJoinContextFakeCommand();
        }
    }

    class Yii
    {
        public static $app;

        public static function getAlias($alias)
        {
            if ($alias === '@app') {
                return dirname(__DIR__);
            }

            if (strpos($alias, '@app/') === 0) {
                return dirname(__DIR__) . substr($alias, 4);
            }

            return $alias;
        }

        public static function warning($message)
        {
        }
    }
}

Yii::$app = (object) [
    'cache' => null,
    'db' => new FolioSchemaAcquisitionsJoinContextFakeDb(),
    'folioDb' => new FolioSchemaAcquisitionsJoinContextFakeDb(),
    'params' => [
        'schemaPath' => __DIR__ . '/../data/folio_schema.json',
        'derivedPath' => __DIR__ . '/../data/folio_derived_tables.json',
    ],
];

require_once __DIR__ . '/../services/PromptBudgetService.php';
require_once __DIR__ . '/../services/SemanticContextRetrievalService.php';
require_once __DIR__ . '/../services/FolioSchemaService.php';

use app\services\FolioSchemaService;

function assertContextContains(string $needle, string $context, string $message): void
{
    if (strpos($context, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: {$needle}\n");
        exit(1);
    }
}

$package = FolioSchemaService::buildSchemaContextPackage(
    'I would like to see all of the open standing orders with the fund code SCDPG or SCXPG we have at Smith College.'
);
$context = $package['text'] ?? '';

assertContextContains(
    'invoice.invoice_lines__t__fund_distributions AS iltfd ON iltfd.po_line_id = plt.id',
    $context,
    'Schema context should explicitly teach the invoice fund-distribution to PO-line join.'
);

assertContextContains(
    'Do NOT join invoice.invoice_lines__t__fund_distributions.id to orders.po_line__t.id',
    $context,
    'Schema context should explicitly forbid the observed incorrect id-to-id join.'
);

assertContextContains(
    "Standing orders are purchase orders where orders.purchase_order__t.order_type = 'Ongoing'",
    $context,
    'Schema context should explicitly map standing orders to purchase_order__t.order_type.'
);

assertContextContains(
    'Do NOT filter orders.po_line__t.order_format for standing orders',
    $context,
    'Schema context should forbid using POL order_format as standing-order status.'
);

fwrite(STDOUT, "FolioSchema acquisitions join context test passed\n");
