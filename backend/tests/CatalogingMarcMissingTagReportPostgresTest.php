<?php

namespace yii\db {
    // This test intentionally stays runnable without bootstrapping Yii. The
    // report model only needs ActiveRecord's attribute accessors here.
    class ActiveRecord
    {
        private $attributes = [];

        public function __get($name) { return $this->attributes[$name] ?? null; }
        public function __set($name, $value) { $this->attributes[$name] = $value; }
        public function hasAttribute($name) { return true; }
    }
}

namespace {
    use app\models\ReportTemplate;
    use app\services\CatalogingMarcMissingTagReportService;

    function marcPostgresFail($message)
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function marcPostgresSkip($message)
    {
        fwrite(STDOUT, "SKIP: {$message}\n");
        exit(0);
    }

    if (getenv('RUN_FOLIO_DB_TESTS') !== '1') {
        marcPostgresSkip('Set RUN_FOLIO_DB_TESTS=1 to run live FOLIO PostgreSQL contract checks.');
    }

    if (!extension_loaded('pdo_pgsql')) {
        marcPostgresFail('RUN_FOLIO_DB_TESTS=1 requires the PDO PostgreSQL driver.');
    }

    require_once __DIR__ . '/../services/SettingsService.php';
    require_once __DIR__ . '/../models/ReportTemplate.php';
    require_once __DIR__ . '/../services/CatalogingMarcMissingTagReportService.php';

    final class MarcPostgresCommand
    {
        private $pdo;
        private $sql;
        private $params;

        public function __construct(\PDO $pdo, $sql, array $params = [])
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

    final class MarcPostgresDb
    {
        private $pdo;

        public function __construct(\PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        public function createCommand($sql, array $params = [])
        {
            return new MarcPostgresCommand($this->pdo, $sql, $params);
        }
    }

    function marcPostgresSetting($key, $environmentKey, $default = '')
    {
        return \app\services\SettingsService::get($key, $environmentKey, $default);
    }

    function marcPostgresReport()
    {
        $migration = file_get_contents(__DIR__ . '/../../mysql/migrations/040_cataloging_marc_missing_tag_report.sql');
        if ($migration === false || preg_match("/\\n  '(WITH target_instances AS MATERIALIZED \\(.*?LIMIT 100001)',\\n  '(\\[.*?\\])',\\n  'folio'/s", $migration, $matches) !== 1) {
            marcPostgresFail('Could not load the reviewed MARC report template from migration 040.');
        }

        $report = new ReportTemplate();
        $report->slug = CatalogingMarcMissingTagReportService::REPORT_SLUG;
        $report->sql_template = str_replace("''", "'", $matches[1]);
        $report->parameters = str_replace("''", "'", $matches[2]);
        $report->default_limit = CatalogingMarcMissingTagReportService::PUBLIC_ROW_CAP;
        return $report;
    }

    function marcPostgresRows(\PDO $pdo, $sql, array $params)
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $row;
        }
        return $rows;
    }

    function marcPostgresPlanNodes(array $node, array &$nodes)
    {
        $nodes[] = $node;
        foreach (['Plans', 'InitPlan', 'Subplans'] as $childKey) {
            if (!isset($node[$childKey]) || !is_array($node[$childKey])) {
                continue;
            }
            foreach ($node[$childKey] as $child) {
                if (is_array($child)) {
                    marcPostgresPlanNodes($child, $nodes);
                }
            }
        }
    }

    function marcPostgresTableNames(array $plan)
    {
        $nodes = [];
        marcPostgresPlanNodes($plan, $nodes);
        $tables = [];
        foreach ($nodes as $node) {
            if (($node['Schema'] ?? null) === 'marctab' && isset($node['Relation Name'])) {
                $tables[] = 'marctab.' . $node['Relation Name'];
            }
        }
        return array_values(array_unique($tables));
    }

    function marcPostgresActiveLocations(\PDO $pdo)
    {
        $requested = trim((string)getenv('FOLIO_DB_TEST_LOCATION_IDS'));
        if ($requested !== '') {
            $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $requested)))));
            if (count($ids) === 0) {
                marcPostgresFail('FOLIO_DB_TEST_LOCATION_IDS did not contain a UUID.');
            }
            return $ids;
        }

        $selectionSql = <<<'SQL'
SELECT loc.id::text
FROM inventory.location__t loc
LEFT JOIN inventory.item__t item ON item.effective_location_id = loc.id
WHERE COALESCE(loc.is_active, true)
GROUP BY loc.id, loc.name, loc.code
ORDER BY COUNT(item.id) %s, loc.name NULLS LAST, loc.code NULLS LAST, loc.id
LIMIT 1
SQL;
        $small = $pdo->query(sprintf($selectionSql, 'ASC'))->fetchColumn();
        $large = $pdo->query(sprintf($selectionSql, 'DESC'))->fetchColumn();
        $locations = array_values(array_unique(array_filter([$small, $large])));
        if (count($locations) === 0) {
            marcPostgresFail('No active FOLIO location is available for live MARC report verification.');
        }
        return $locations;
    }

    function marcPostgresFetchPlan(\PDO $pdo, $sql, array $params)
    {
        $statement = $pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . $sql);
        $statement->execute($params);
        $document = json_decode((string)$statement->fetchColumn(), true);
        if (!is_array($document) || !isset($document[0]['Plan']) || !is_array($document[0]['Plan'])) {
            marcPostgresFail('PostgreSQL did not return a JSON execution plan.');
        }
        return $document[0];
    }

    function marcPostgresInstanceIndexes(\PDO $pdo, $tableName)
    {
        $statement = $pdo->prepare(
            "SELECT indexname\n"
            . "FROM pg_indexes\n"
            . "WHERE schemaname = 'marctab'\n"
            . "  AND tablename = :table_name\n"
            . "  AND indexdef ILIKE '%instance_id%'\n"
            . 'ORDER BY indexname'
        );
        $statement->execute([':table_name' => $tableName]);
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    $host = marcPostgresSetting('pg_host', 'FOLIO_PG_HOST', '');
    $port = marcPostgresSetting('pg_port', 'FOLIO_PG_PORT', '5432');
    $database = marcPostgresSetting('pg_db', 'FOLIO_PG_DB', '');
    $username = marcPostgresSetting('pg_user', 'FOLIO_PG_USER', '');
    $password = marcPostgresSetting('pg_pass', 'FOLIO_PG_PASS', '');
    $sslMode = marcPostgresSetting('pg_sslmode', 'FOLIO_PG_SSLMODE', 'require');
    $connectTimeout = max(1, (int)(getenv('FOLIO_DB_TEST_CONNECT_TIMEOUT') ?: 10));
    $statementTimeout = max(1, (int)(getenv('FOLIO_DB_TEST_STATEMENT_TIMEOUT_MS') ?: 1800000));
    if ($host === '' || $database === '' || $username === '') {
        marcPostgresFail('RUN_FOLIO_DB_TESTS=1 requires configured FOLIO PostgreSQL host, database, and user.');
    }

    try {
        $pdo = new \PDO(
            "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslMode};connect_timeout={$connectTimeout}",
            $username,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => $connectTimeout]
        );
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->exec('SET LOCAL statement_timeout = ' . $statementTimeout);

        $requiredTables = marcPostgresRows(
            $pdo,
            "SELECT to_regclass('marctab.mt245') AS mt245, to_regclass('marctab.mt856') AS mt856",
            []
        );
        if (count($requiredTables) !== 1 || empty($requiredTables[0]['mt245']) || empty($requiredTables[0]['mt856'])) {
            marcPostgresFail('Required MARC tables marctab.mt245 and marctab.mt856 are not both available.');
        }

        $report = marcPostgresReport();
        $folioDb = new MarcPostgresDb($pdo);
        $locations = marcPostgresActiveLocations($pdo);
        $checked = 0;
        foreach ($locations as $locationId) {
            foreach (['effective_item', 'permanent_item', 'permanent_holdings'] as $basis) {
                foreach (['245', '856'] as $tag) {
                    $compiled = CatalogingMarcMissingTagReportService::build($report, [
                        'locationId' => $locationId,
                        'locationBasis' => $basis,
                        'marcTag' => $tag,
                    ], $folioDb);
                    $planDocument = marcPostgresFetchPlan($pdo, $compiled['sql'], $compiled['params']);
                    $plan = $planDocument['Plan'];
                    $actualRows = (int)($plan['Actual Rows'] ?? -1);
                    $touchedTables = marcPostgresTableNames($plan);
                    $expectedTable = 'marctab.mt' . $tag;
                    if ($touchedTables !== [$expectedTable]) {
                        marcPostgresFail("{$basis}/{$tag} touched unexpected MARC tables: " . json_encode($touchedTables));
                    }
                    if (strpos(json_encode($planDocument), 'folio_source_record.marctab') !== false) {
                        marcPostgresFail("{$basis}/{$tag} plan touched the forbidden combined MARC view.");
                    }
                    if ($actualRows < 0 || $actualRows > CatalogingMarcMissingTagReportService::FETCH_ROW_LIMIT) {
                        marcPostgresFail("{$basis}/{$tag} returned {$actualRows} rows in EXPLAIN, outside the 100001-row fetch cap.");
                    }

                    $reportedRows = marcPostgresRows($pdo, $compiled['sql'], $compiled['params']);
                    if (count($reportedRows) > CatalogingMarcMissingTagReportService::FETCH_ROW_LIMIT) {
                        marcPostgresFail("{$basis}/{$tag} query returned more than 100001 rows.");
                    }
                    $presenceSql = "WITH reported AS (\n" . $compiled['sql'] . "\n)\n"
                        . "SELECT COUNT(*) FROM reported JOIN {$expectedTable} present "
                        . 'ON present.instance_id = reported."Instance UUID"';
                    $presenceStatement = $pdo->prepare($presenceSql);
                    $presenceStatement->execute($compiled['params']);
                    if ((int)$presenceStatement->fetchColumn() !== 0) {
                        marcPostgresFail("{$basis}/{$tag} reported an Instance UUID that is present in {$expectedTable}.");
                    }

                    $positiveSql = str_replace('WHERE NOT EXISTS (', 'WHERE EXISTS (', $compiled['sql']);
                    $positiveStatement = $pdo->prepare($positiveSql);
                    $positiveStatement->execute($compiled['params']);
                    $positiveAvailable = $positiveStatement->fetch(\PDO::FETCH_ASSOC) !== false;

                    $result = [
                        'location_id' => $compiled['location']['id'],
                        'location_name' => $compiled['location']['name'],
                        'location_code' => $compiled['location']['code'],
                        'basis' => $basis,
                        'tag' => $tag,
                        'planning_ms' => $planDocument['Planning Time'] ?? null,
                        'execution_ms' => $planDocument['Execution Time'] ?? null,
                        'returned_rows' => count($reportedRows),
                        'explain_actual_rows' => $actualRows,
                        'shared_hit_blocks' => $plan['Shared Hit Blocks'] ?? null,
                        'shared_read_blocks' => $plan['Shared Read Blocks'] ?? null,
                        'sentinel_row_present' => count($reportedRows) === CatalogingMarcMissingTagReportService::FETCH_ROW_LIMIT,
                        'statement_timeout_ms' => $statementTimeout,
                        'marctab_table' => $expectedTable,
                        'instance_id_indexes' => marcPostgresInstanceIndexes($pdo, 'mt' . $tag),
                        'tag_presence_fixture_available' => $positiveAvailable,
                    ];
                    fwrite(STDOUT, 'MARC_PG_PLAN ' . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
                    $checked++;
                }
            }
        }
        $pdo->rollBack();
        fwrite(STDOUT, "Cataloging MARC missing-tag PostgreSQL contract test passed ({$checked} plans).\n");
    } catch (\Throwable $exception) {
        if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        marcPostgresFail('Live FOLIO PostgreSQL contract check failed: ' . $exception->getMessage());
    }
}
