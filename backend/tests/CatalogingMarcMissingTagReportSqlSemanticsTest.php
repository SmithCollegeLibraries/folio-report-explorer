<?php

namespace yii\db {
    class ActiveRecord
    {
        private $attributes = [];
        public function __get($name) { return $this->attributes[$name] ?? null; }
        public function __set($name, $value): void { $this->attributes[$name] = $value; }
        public function hasAttribute($name): bool { return true; }
    }
}

namespace {
    require_once __DIR__ . '/../models/ReportTemplate.php';
    require_once __DIR__ . '/../services/CatalogingMarcMissingTagReportService.php';

    use app\models\ReportTemplate;
    use app\services\CatalogingMarcMissingTagReportService;

    function marcSemanticsAssertSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    final class MarcSemanticsLookupCommand
    {
        private $sql;
        private $params;

        public function __construct($sql, array $params)
        {
            $this->sql = $sql;
            $this->params = $params;
        }
        public function queryAll(): array
        {
            if (strpos($this->sql, 'FROM inventory.location__t') !== false) {
                return [
                    ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Selected Location', 'code' => 'SELECTED'],
                    ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Other Location', 'code' => 'OTHER'],
                ];
            }
            throw new \RuntimeException('Unexpected queryAll lookup.');
        }

        public function queryOne()
        {
            if (strpos($this->sql, 'to_regclass') !== false) {
                return ['to_regclass' => $this->params[':table_name']];
            }
            throw new \RuntimeException('Unexpected lookup.');
        }
    }

    final class MarcSemanticsLookupDb
    {
        public function createCommand(string $sql, array $params = []): MarcSemanticsLookupCommand
        {
            return new MarcSemanticsLookupCommand($sql, $params);
        }
    }

    function marcSemanticsReport(): ReportTemplate
    {
        $migration = (string) file_get_contents(__DIR__ . '/../../mysql/migrations/042_cataloging_marc_multi_location.sql');
        if (preg_match("/SET\\n.*?  `sql_template` = '(WITH target_instances AS MATERIALIZED \\(.*?LIMIT 100001)',\\n  `parameters` = '(\\[.*?\\])',\\n/s", $migration, $matches) !== 1) {
            throw new \RuntimeException('Could not load the multi-location report template fixture.');
        }
        $report = new ReportTemplate();
        $report->slug = CatalogingMarcMissingTagReportService::REPORT_SLUG;
        $report->sql_template = str_replace("''", "'", $matches[1]);
        $report->parameters = str_replace("''", "'", $matches[2]);
        $report->default_limit = 100000;
        return $report;
    }

    function marcSemanticsUuids(array $rows, string $uuid): array
    {
        return array_values(array_filter($rows, function (array $row) use ($uuid) {
            return $row['Instance UUID'] === $uuid;
        }));
    }

    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("ATTACH DATABASE ':memory:' AS inventory");
    $pdo->exec("ATTACH DATABASE ':memory:' AS marctab");
    $pdo->exec('CREATE TABLE inventory.location__t (id TEXT PRIMARY KEY, name TEXT, code TEXT)');
    $pdo->exec('CREATE TABLE inventory.instance__t (id TEXT PRIMARY KEY, hrid TEXT, title TEXT, source TEXT)');
    $pdo->exec('CREATE TABLE inventory.holdings_record__t (id TEXT PRIMARY KEY, instance_id TEXT, permanent_location_id TEXT)');
    $pdo->exec('CREATE TABLE inventory.item__t (id TEXT PRIMARY KEY, holdings_record_id TEXT, effective_location_id TEXT, permanent_location_id TEXT)');
    $pdo->exec('CREATE TABLE marctab.mt856 (instance_id TEXT)');

    $pdo->exec("INSERT INTO inventory.location__t VALUES ('11111111-1111-4111-8111-111111111111', 'Selected Location', 'SELECTED'), ('22222222-2222-4222-8222-222222222222', 'Other Location', 'OTHER')");
    $instances = [
        ['marc-with-856', 'h1', 'MARC with 856', 'MARC'],
        ['marc-missing-856', 'h2', 'MARC missing 856', 'MARC'],
        ['folio-missing-856', 'h3', 'FOLIO missing 856', 'FOLIO'],
        ['null-hrid-with-856', null, 'Null HRID with 856', 'MARC'],
        ['duplicate-item-instance', 'h5', 'Two selected items', 'MARC'],
        ['shared-selected-instance', 'h6', 'Shared selected instance', 'MARC'],
        ['itemless-holding-instance', 'h7', 'Itemless holding', 'MARC'],
    ];
    $insertInstance = $pdo->prepare('INSERT INTO inventory.instance__t (id, hrid, title, source) VALUES (?, ?, ?, ?)');
    foreach ($instances as $instance) {
        $insertInstance->execute($instance);
    }

    $holdings = [
        ['hold-1', 'marc-with-856', '11111111-1111-4111-8111-111111111111'],
        ['hold-2', 'marc-missing-856', '11111111-1111-4111-8111-111111111111'],
        ['hold-3', 'folio-missing-856', '11111111-1111-4111-8111-111111111111'],
        ['hold-4', 'null-hrid-with-856', '11111111-1111-4111-8111-111111111111'],
        ['hold-5', 'duplicate-item-instance', '11111111-1111-4111-8111-111111111111'],
        ['hold-6-selected', 'shared-selected-instance', '11111111-1111-4111-8111-111111111111'],
        ['hold-6-other', 'shared-selected-instance', '22222222-2222-4222-8222-222222222222'],
        ['hold-7', 'itemless-holding-instance', '11111111-1111-4111-8111-111111111111'],
    ];
    $insertHolding = $pdo->prepare('INSERT INTO inventory.holdings_record__t (id, instance_id, permanent_location_id) VALUES (?, ?, ?)');
    foreach ($holdings as $holding) {
        $insertHolding->execute($holding);
    }

    $items = [
        ['item-1', 'hold-1', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
        ['item-2', 'hold-2', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
        ['item-3', 'hold-3', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
        ['item-4', 'hold-4', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
        ['item-5a', 'hold-5', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
        ['item-5b', 'hold-5', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
        ['item-6a', 'hold-6-selected', '11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111'],
        ['item-6b', 'hold-6-other', '22222222-2222-4222-8222-222222222222', '22222222-2222-4222-8222-222222222222'],
    ];
    $insertItem = $pdo->prepare('INSERT INTO inventory.item__t (id, holdings_record_id, effective_location_id, permanent_location_id) VALUES (?, ?, ?, ?)');
    foreach ($items as $item) {
        $insertItem->execute($item);
    }
    $pdo->exec("INSERT INTO marctab.mt856 (instance_id) VALUES ('marc-with-856'), ('null-hrid-with-856')");

    $report = marcSemanticsReport();
    $lookupDb = new MarcSemanticsLookupDb();
    $run = function (string $basis) use ($pdo, $report, $lookupDb): array {
        $effective = CatalogingMarcMissingTagReportService::build($report, [
            'locationIds' => '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222',
            'locationBasis' => $basis,
            'marcTag' => '856',
        ], $lookupDb);
        $sqliteSql = str_replace(
            "location.id = ANY(string_to_array(:locationIds, ',')::uuid[])",
            "instr(',' || :locationIds || ',', ',' || location.id || ',') > 0",
            $effective['sql']
        );
        $sqliteSql = preg_replace(
            "/STRING_AGG\\(\\s*DISTINCT location\\.name \\|\\| COALESCE\\(' \\[' \\|\\| location\\.code \\|\\| '\\]', ''\\),\\s*'; ' ORDER BY location\\.name \\|\\| COALESCE\\(' \\[' \\|\\| location\\.code \\|\\| '\\]', ''\\)\\s*\\)/s",
            "GROUP_CONCAT(DISTINCT location.name || COALESCE(' [' || location.code || ']', ''))",
            $sqliteSql
        );
        $statement = $pdo->prepare($sqliteSql);
        $statement->execute($effective['params']);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    };

    $effectiveRows = $run('effective_item');
    $permanentItemRows = $run('permanent_item');
    $permanentHoldingsRows = $run('permanent_holdings');

    marcSemanticsAssertSame([], marcSemanticsUuids($effectiveRows, 'marc-with-856'), 'MARC instances containing 856 must not be reported.');
    marcSemanticsAssertSame(['marc-missing-856'], array_column(marcSemanticsUuids($effectiveRows, 'marc-missing-856'), 'Instance UUID'), 'MARC instances missing 856 must be reported.');
    marcSemanticsAssertSame([], marcSemanticsUuids($effectiveRows, 'folio-missing-856'), 'FOLIO-sourced instances must not be reported.');
    marcSemanticsAssertSame([], marcSemanticsUuids($effectiveRows, 'null-hrid-with-856'), 'Presence checks must use UUID rather than nullable HRID.');
    marcSemanticsAssertSame(1, count(marcSemanticsUuids($effectiveRows, 'duplicate-item-instance')), 'Distinct target instances must eliminate multiple selected items.');
    marcSemanticsAssertSame(1, count(marcSemanticsUuids($effectiveRows, 'shared-selected-instance')), 'A selected holding must include an instance even when it is shared elsewhere.');
    $sharedLocations = explode(',', marcSemanticsUuids($effectiveRows, 'shared-selected-instance')[0]['Selected Locations']);
    sort($sharedLocations);
    marcSemanticsAssertSame(['Other Location [OTHER]', 'Selected Location [SELECTED]'], $sharedLocations, 'Multiple matching locations must be combined on one instance row.');
    marcSemanticsAssertSame([], marcSemanticsUuids($effectiveRows, 'itemless-holding-instance'), 'Item scopes must exclude selected holdings without items.');
    marcSemanticsAssertSame(['itemless-holding-instance'], array_column(marcSemanticsUuids($permanentHoldingsRows, 'itemless-holding-instance'), 'Instance UUID'), 'Permanent-holdings scope must include itemless selected holdings.');
    marcSemanticsAssertSame(1, count(marcSemanticsUuids($permanentItemRows, 'duplicate-item-instance')), 'Permanent item scope must also preserve one row per instance.');

    fwrite(STDOUT, "Cataloging MARC missing-tag SQL semantics tests passed\n");
}
