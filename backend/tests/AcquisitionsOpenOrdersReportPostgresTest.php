<?php
require_once __DIR__ . '/support/AcquisitionsOpenOrdersReportFixture.php';
require_once __DIR__ . '/../services/CatalogingReportCompilerService.php';

use app\services\CatalogingReportCompilerService;

function acquisitionsPostgresFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function acquisitionsPostgresSkip(string $message): void
{
    fwrite(STDOUT, "SKIP: {$message}\n");
    exit(0);
}

if (getenv('RUN_FOLIO_DB_TESTS') !== '1') {
    acquisitionsPostgresSkip('Set RUN_FOLIO_DB_TESTS=1 to run the live acquisitions report contract check.');
}
if (!extension_loaded('pdo_pgsql')) {
    acquisitionsPostgresFail('The opt-in acquisitions report test requires PDO PostgreSQL support.');
}

$connection = [];
foreach (['FOLIO_PG_HOST', 'FOLIO_PG_PORT', 'FOLIO_PG_DB', 'FOLIO_PG_USER', 'FOLIO_PG_PASS'] as $name) {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        acquisitionsPostgresFail("The opt-in acquisitions report test requires {$name}.");
    }
    $connection[$name] = $value;
}

try {
    $dsn = 'pgsql:host=' . $connection['FOLIO_PG_HOST'] . ';port=' . $connection['FOLIO_PG_PORT'] . ';dbname=' . $connection['FOLIO_PG_DB'] . ';sslmode=require;connect_timeout=20';
    $pdo = new \PDO($dsn, $connection['FOLIO_PG_USER'], $connection['FOLIO_PG_PASS'], [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    $pdo->exec('SET default_transaction_read_only = on');
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION READ ONLY');
} catch (\Throwable $exception) {
    acquisitionsPostgresFail('Could not establish a read-only LDP connection; connection details were suppressed.');
}

final class AcquisitionsLiveLookupCommand
{
    private $pdo;
    private $sql;
    private $params;

    public function __construct(\PDO $pdo, string $sql, array $params)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryOne()
    {
        $statement = $this->pdo->prepare($this->sql);
        $statement->execute($this->params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}

final class AcquisitionsLiveLookupDb
{
    private $pdo;
    public $queryCount = 0;

    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }

    public function createCommand(string $sql, array $params = []): AcquisitionsLiveLookupCommand
    {
        $this->queryCount++;
        return new AcquisitionsLiveLookupCommand($this->pdo, $sql, $params);
    }
}

function acquisitionsLiveRows(\PDO $pdo, string $sql, array $params): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
}

function acquisitionsLiveDateValue($value): string
{
    return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
}

function acquisitionsLiveFilter(array $rows, callable $predicate): array
{
    return array_values(array_filter($rows, $predicate));
}

$report = acquisitionsOpenOrdersReportFixture();
$db = new AcquisitionsLiveLookupDb($pdo);
try {
    $compiled = CatalogingReportCompilerService::build($report, [], $db);
    $defaultRows = acquisitionsLiveRows($pdo, $compiled['sql'], $compiled['params']);

    $fy2026Params = $compiled['params'];
    $fy2026Params[':startDate'] = '2025-07-01';
    $fy2026Params[':endDate'] = '2026-06-30';
    $fy2026Rows = acquisitionsLiveRows($pdo, $compiled['sql'], $fy2026Params);

    $allTimeParams = $compiled['params'];
    $allTimeParams[':startDate'] = '1900-01-01';
    $allTimeParams[':endDate'] = '2200-12-31';
    $allTimeRows = acquisitionsLiveRows($pdo, $compiled['sql'], $allTimeParams);

    $awaitingRows = acquisitionsLiveFilter($allTimeRows, static function (array $row): bool {
        return $row['Receipt status'] === 'Awaiting Receipt';
    });
    $fullyReceivedRows = acquisitionsLiveFilter($allTimeRows, static function (array $row): bool {
        return $row['Receipt status'] === 'Fully Received'
            && in_array($row['Payment status'], ['Awaiting Payment', 'Partially Paid'], true);
    });
    $pendingRows = acquisitionsLiveFilter($allTimeRows, static function (array $row): bool {
        return $row['Date used'] === 'Last updated (pending)';
    });
    $samples = [];
    foreach ([
        'awaiting_receipt' => $awaitingRows,
        'fully_received_unpaid' => $fullyReceivedRows,
        'pending_po' => $pendingRows,
    ] as $category => $categoryRows) {
        $samples[$category] = array_slice($categoryRows, 0, 3);
    }
    if (count($samples['awaiting_receipt']) < 3 || count($samples['fully_received_unpaid']) < 3 || count($samples['pending_po']) < 1) {
        acquisitionsPostgresFail('The live population did not provide the expected spot-check candidates.');
    }

    $sampleIds = [];
    foreach ($samples as $categoryRows) {
        foreach ($categoryRows as $row) {
            $sampleIds[(string) $row['PO Line UUID']] = (string) $row['PO UUID'];
        }
    }
    $placeholders = [];
    $sampleParams = [];
    $index = 0;
    foreach (array_keys($sampleIds) as $lineId) {
        $placeholder = ':line_' . $index++;
        $placeholders[] = $placeholder;
        $sampleParams[$placeholder] = $lineId;
    }
    $sourceSql = 'SELECT po.id::text AS po_uuid, pol.id::text AS po_line_uuid, po.po_number,'
        . ' pol.po_line_number, pol.receipt_status, pol.payment_status, po.workflow_status, po.order_type,'
        . ' CASE WHEN po.workflow_status = \'Pending\' THEN po.metadata__updated_date::date::text'
        . ' ELSE po.date_ordered::date::text END AS report_date,'
        . ' CASE WHEN po.workflow_status = \'Pending\' THEN \'Last updated (pending)\' ELSE \'Opened\' END AS date_used'
        . ' FROM orders.po_line__t AS pol JOIN orders.purchase_order__t AS po ON po.id = pol.purchase_order_id'
        . ' WHERE pol.id IN (' . implode(', ', $placeholders) . ')';
    $sourceRows = acquisitionsLiveRows($pdo, $sourceSql, $sampleParams);
    $sourceByLine = [];
    foreach ($sourceRows as $row) { $sourceByLine[$row['po_line_uuid']] = $row; }

    $spots = [];
    foreach ($samples as $category => $categoryRows) {
        foreach ($categoryRows as $row) {
            $source = $sourceByLine[(string) $row['PO Line UUID']] ?? null;
            if (!is_array($source)
                || (string) $row['PO UUID'] !== (string) $source['po_uuid']
                || (string) $row['PO number'] !== (string) $source['po_number']
                || (string) $row['PO line number'] !== (string) $source['po_line_number']
                || (string) $row['Receipt status'] !== (string) $source['receipt_status']
                || (string) $row['Payment status'] !== (string) $source['payment_status']
                || acquisitionsLiveDateValue($row['Report date']) !== acquisitionsLiveDateValue($source['report_date'])
                || (string) $row['Date used'] !== (string) $source['date_used']) {
                acquisitionsPostgresFail('A report spot check did not match its source FOLIO order and line fields.');
            }
            $spots[] = [
                'category' => $category,
                'po_uuid' => (string) $row['PO UUID'],
                'po_line_uuid' => (string) $row['PO Line UUID'],
                'po_number' => (string) $row['PO number'],
                'po_line_number' => (string) $row['PO line number'],
                'workflow_status' => (string) $source['workflow_status'],
                'order_type' => (string) $source['order_type'],
                'receipt_status' => (string) $row['Receipt status'],
                'payment_status' => (string) $row['Payment status'],
                'report_date' => acquisitionsLiveDateValue($row['Report date']),
                'date_used' => (string) $row['Date used'],
            ];
        }
    }

    $evidence = [
        'default_range' => [$compiled['params'][':startDate'], $compiled['params'][':endDate']],
        'default_count' => count($defaultRows),
        'fy2026_count' => count($fy2026Rows),
        'all_time_count' => count($allTimeRows),
        'all_time_by_status' => [
            'awaiting_receipt' => count($awaitingRows),
            'fully_received_unpaid' => count($fullyReceivedRows),
            'pending_po' => count($pendingRows),
        ],
        'verified_spots' => $spots,
        'ldp_statements' => $db->queryCount + 4,
    ];
    fwrite(STDOUT, 'ACQ_OPEN_ORDERS_LIVE ' . json_encode($evidence, JSON_UNESCAPED_SLASHES) . "\n");
    if ($db->queryCount + 4 > 15) {
        acquisitionsPostgresFail('The live check exceeded the 15-statement LDP query budget.');
    }
    $pdo->rollBack();
    echo "Acquisitions open-orders live PostgreSQL contract checks passed\n";
} catch (\Throwable $exception) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    acquisitionsPostgresFail('Live acquisitions report verification failed; LDP error details were suppressed.');
}
