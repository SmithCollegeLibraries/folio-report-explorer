<?php

namespace yii\db {
    // Keep the test runnable without a Yii application when the live gate is skipped.
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
    use app\services\CatalogingMarcFieldFinderService;
    use app\services\CatalogingMarcLocationScopeService;
    use app\services\SettingsService;

    function marcFinderPostgresFail($message)
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function marcFinderPostgresSkip($message)
    {
        fwrite(STDOUT, "SKIP: {$message}\n");
        exit(0);
    }

    if (getenv('RUN_FOLIO_DB_TESTS') !== '1') {
        marcFinderPostgresSkip('Set RUN_FOLIO_DB_TESTS=1 to run live FOLIO PostgreSQL contract checks.');
    }

    if (!extension_loaded('pdo_pgsql')) {
        marcFinderPostgresFail('RUN_FOLIO_DB_TESTS=1 requires the PDO PostgreSQL driver.');
    }

    require_once __DIR__ . '/../services/SettingsService.php';
    require_once __DIR__ . '/../models/ReportTemplate.php';
    require_once __DIR__ . '/../services/SqlSelectStructureService.php';
    require_once __DIR__ . '/../services/CatalogingMarcLocationScopeService.php';
    require_once __DIR__ . '/../exceptions/ReportParameterValidationException.php';
    require_once __DIR__ . '/../services/CatalogingMarcFieldFinderService.php';

    final class MarcFinderPostgresCommand
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

        public function queryAll()
        {
            $statement = $this->pdo->prepare($this->sql);
            $statement->execute($this->params);
            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        }
    }

    final class MarcFinderPostgresDb
    {
        private $pdo;

        public function __construct(\PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        public function createCommand($sql, array $params = [])
        {
            return new MarcFinderPostgresCommand($this->pdo, $sql, $params);
        }
    }

    function marcFinderPostgresSetting($key, $environmentKey, $default = '')
    {
        return SettingsService::get($key, $environmentKey, $default);
    }

    function marcFinderPostgresReport()
    {
        $migration = file_get_contents(__DIR__ . '/../../mysql/migrations/043_cataloging_marc_field_finder.sql');
        if ($migration === false || preg_match(
            "/VALUES \(\n  'marc-field-indicator-content-finder',\n  '([^']+)',.*?\n  '(WITH target_instances AS MATERIALIZED \(.*?LIMIT 100001)',\n  '(\\[.*?\\])',\n  'folio',\n  '(\\{.*?\\})',\n  100000,\n  1,\n  'manual'\n\)/s",
            $migration,
            $matches
        ) !== 1) {
            marcFinderPostgresFail('Could not load the canonical MARC finder seed from migration 043.');
        }

        $report = new ReportTemplate();
        $report->slug = CatalogingMarcFieldFinderService::REPORT_SLUG;
        $report->name = $matches[1];
        $report->category = 'cataloging';
        $report->sql_template = str_replace("''", "'", $matches[2]);
        $report->parameters = str_replace("''", "'", $matches[3]);
        $report->data_source = 'folio';
        $report->execution_config = str_replace("''", "'", $matches[4]);
        $report->default_limit = CatalogingMarcFieldFinderService::PUBLIC_ROW_CAP;
        $report->is_active = 1;
        $report->created_by = 'manual';
        return $report;
    }

    function marcFinderPostgresPlanNodes(array $node, array &$nodes)
    {
        $nodes[] = $node;
        foreach (['Plans', 'InitPlan', 'Subplans'] as $childKey) {
            if (!isset($node[$childKey]) || !is_array($node[$childKey])) {
                continue;
            }
            foreach ($node[$childKey] as $child) {
                if (is_array($child)) {
                    marcFinderPostgresPlanNodes($child, $nodes);
                }
            }
        }
    }

    function marcFinderPostgresNodes(array $plan)
    {
        $nodes = [];
        marcFinderPostgresPlanNodes($plan, $nodes);
        return $nodes;
    }

    function marcFinderPostgresTableNames(array $plan)
    {
        $tables = [];
        foreach (marcFinderPostgresNodes($plan) as $node) {
            if (($node['Schema'] ?? null) === 'marctab' && isset($node['Relation Name'])) {
                $tables[] = 'marctab.' . strtolower($node['Relation Name']);
            }
        }
        return array_values(array_unique($tables));
    }

    function marcFinderPostgresHasForbiddenSource(array $plan)
    {
        foreach (marcFinderPostgresNodes($plan) as $node) {
            if (($node['Schema'] ?? null) === 'folio_source_record'
                && ($node['Relation Name'] ?? null) === 'marctab') {
                return true;
            }
        }
        return false;
    }

    function marcFinderPostgresHasMt245SeqScan(array $plan)
    {
        foreach (marcFinderPostgresNodes($plan) as $node) {
            if (($node['Node Type'] ?? null) === 'Seq Scan'
                && strtolower((string) ($node['Schema'] ?? '')) === 'marctab'
                && strtolower((string) ($node['Relation Name'] ?? '')) === 'mt245') {
                return true;
            }
        }
        return false;
    }

    function marcFinderPostgresHasMaterializedTargetScope(array $plan)
    {
        foreach (marcFinderPostgresNodes($plan) as $node) {
            if (($node['Node Type'] ?? null) === 'CTE Scan'
                && ($node['CTE Name'] ?? null) === 'target_instances') {
                return true;
            }
        }
        return false;
    }

    function marcFinderPostgresNormalizeIds(array $ids)
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
                marcFinderPostgresFail("FOLIO_DB_TEST_LOCATION_IDS contains an invalid UUID: {$id}");
            }
            $normalized[] = strtolower($id);
        }
        return array_values(array_unique($normalized));
    }

    function marcFinderPostgresLocations(\PDO $pdo)
    {
        $requested = trim((string) getenv('FOLIO_DB_TEST_LOCATION_IDS'));
        if ($requested !== '') {
            $ids = marcFinderPostgresNormalizeIds(array_values(array_filter(array_map('trim', explode(',', $requested)))));
            if ($ids === []) {
                marcFinderPostgresFail('FOLIO_DB_TEST_LOCATION_IDS did not contain a UUID.');
            }
            $params = [];
            $markers = [];
            foreach ($ids as $index => $id) {
                $marker = ':override_location_' . $index;
                $markers[] = $marker;
                $params[$marker] = $id;
            }
            $statement = $pdo->prepare(
                'SELECT id::text FROM inventory.location__t '
                . 'WHERE COALESCE(is_active, true) AND id IN (' . implode(', ', $markers) . ')'
            );
            $statement->execute($params);
            $active = array_map('strtolower', $statement->fetchAll(\PDO::FETCH_COLUMN));
            $missing = array_values(array_diff($ids, $active));
            if ($missing !== []) {
                marcFinderPostgresFail('FOLIO_DB_TEST_LOCATION_IDS contains a missing or inactive location: ' . implode(', ', $missing));
            }
            return [$ids];
        }

        $sql = <<<'SQL'
SELECT loc.id::text
FROM inventory.location__t loc
LEFT JOIN inventory.item__t item ON item.effective_location_id = loc.id
WHERE COALESCE(loc.is_active, true)
GROUP BY loc.id, loc.name, loc.code
ORDER BY COUNT(item.id) %s, loc.name NULLS LAST, loc.code NULLS LAST, loc.id
LIMIT 1
SQL;
        $small = $pdo->query(sprintf($sql, 'ASC'))->fetchColumn();
        $large = $pdo->query(sprintf($sql, 'DESC'))->fetchColumn();
        $sets = [];
        foreach (array_values(array_unique(array_filter([$small, $large]))) as $id) {
            $sets[] = [strtolower($id)];
        }
        if ($sets === []) {
            marcFinderPostgresFail('No active FOLIO location is available for live MARC finder verification.');
        }
        return $sets;
    }

    function marcFinderPostgresFetchPlan(\PDO $pdo, $sql, array $params)
    {
        $statement = $pdo->prepare('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' . $sql);
        $statement->execute($params);
        $document = json_decode((string) $statement->fetchColumn(), true);
        if (!is_array($document) || !isset($document[0]['Plan']) || !is_array($document[0]['Plan'])) {
            marcFinderPostgresFail('PostgreSQL did not return a JSON execution plan.');
        }
        return $document[0];
    }

    function marcFinderPostgresRows(\PDO $pdo, $sql, array $params)
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $count = 0;
        while ($statement->fetch(\PDO::FETCH_ASSOC) !== false) {
            $count++;
        }
        return $count;
    }

    function marcFinderPostgresBlankCounts(\PDO $pdo, array $locationIds, $basis)
    {
        $locationColumn = $basis === 'permanent_item' ? 'item.permanent_location_id' : 'item.effective_location_id';
        $sql = "WITH target_instances AS MATERIALIZED (\n"
            . "SELECT instance.id AS instance_uuid\n"
            . "FROM inventory.item__t item\n"
            . "JOIN inventory.holdings_record__t holdings ON holdings.id = item.holdings_record_id\n"
            . "JOIN inventory.instance__t instance ON instance.id = holdings.instance_id\n"
            . "JOIN inventory.location__t location ON location.id = {$locationColumn}\n"
            . "WHERE location.id = ANY(CAST(:location_ids AS uuid[])) AND instance.source = 'MARC'\n"
            . "GROUP BY instance.id)\n"
            . "SELECT marc_row.ind1, COUNT(*) AS count\n"
            . "FROM target_instances\n"
            . "JOIN marctab.mt035 marc_row ON marc_row.instance_id = target_instances.instance_uuid\n"
            . "WHERE marc_row.ind2 = '9' AND marc_row.sf = 'a'\n"
            . "AND (marc_row.ind1 = ' ' OR marc_row.ind1 = CHR(92))\n"
            . "GROUP BY marc_row.ind1";
        $statement = $pdo->prepare($sql);
        $statement->execute([':location_ids' => implode(',', $locationIds)]);
        $counts = ['space' => 0, 'backslash' => 0];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if ($row['ind1'] === ' ') {
                $counts['space'] = (int) $row['count'];
            } elseif ($row['ind1'] === '\\') {
                $counts['backslash'] = (int) $row['count'];
            }
        }
        return $counts;
    }

    $host = marcFinderPostgresSetting('pg_host', 'FOLIO_PG_HOST', '');
    $port = marcFinderPostgresSetting('pg_port', 'FOLIO_PG_PORT', '5432');
    $database = marcFinderPostgresSetting('pg_db', 'FOLIO_PG_DB', '');
    $username = marcFinderPostgresSetting('pg_user', 'FOLIO_PG_USER', '');
    $password = marcFinderPostgresSetting('pg_pass', 'FOLIO_PG_PASS', '');
    $sslMode = marcFinderPostgresSetting('pg_sslmode', 'FOLIO_PG_SSLMODE', 'require');
    $connectTimeout = max(1, (int) (getenv('FOLIO_DB_TEST_CONNECT_TIMEOUT') ?: 10));
    $statementTimeout = max(1, (int) (getenv('FOLIO_DB_TEST_STATEMENT_TIMEOUT_MS') ?: 1800000));
    if ($host === '' || $database === '' || $username === '') {
        marcFinderPostgresFail('RUN_FOLIO_DB_TESTS=1 requires configured FOLIO PostgreSQL host, database, and user.');
    }

    $pdo = null;
    try {
        $pdo = new \PDO(
            "pgsql:host={$host};port={$port};dbname={$database};sslmode={$sslMode};connect_timeout={$connectTimeout}",
            $username,
            $password,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => $connectTimeout,
                \PDO::ATTR_EMULATE_PREPARES => true,
            ]
        );
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->exec('SET LOCAL statement_timeout = ' . $statementTimeout);

        foreach (['245', '035', '100'] as $tag) {
            $table = $pdo->query("SELECT to_regclass('marctab.mt{$tag}')")->fetchColumn();
            if (!$table) {
                marcFinderPostgresFail("Required MARC table marctab.mt{$tag} is not available.");
            }
        }

        $report = marcFinderPostgresReport();
        $db = new MarcFinderPostgresDb($pdo);
        $locationSets = marcFinderPostgresLocations($pdo);
        $cases = [
            ['name' => 'mt245_contains', 'marcTag' => '245', 'occurrenceCondition' => 'has', 'firstIndicator' => 'any', 'secondIndicator' => 'any', 'subfieldCode' => 'a', 'contentRule' => 'contains', 'searchValue' => 'the', 'caseExact' => 'false'],
            ['name' => 'mt245_missing', 'marcTag' => '245', 'occurrenceCondition' => 'missing', 'firstIndicator' => 'any', 'secondIndicator' => 'any', 'subfieldCode' => 'a', 'contentRule' => 'any', 'searchValue' => '', 'caseExact' => 'false'],
            ['name' => 'mt035_blank_indicator', 'marcTag' => '035', 'occurrenceCondition' => 'has', 'firstIndicator' => 'blank', 'secondIndicator' => 'char:9', 'subfieldCode' => 'a', 'contentRule' => 'any', 'searchValue' => '', 'caseExact' => 'false'],
            ['name' => 'mt100_lowercase', 'marcTag' => '100', 'occurrenceCondition' => 'has', 'firstIndicator' => 'any', 'secondIndicator' => 'any', 'subfieldCode' => 'a', 'contentRule' => 'has_lowercase', 'searchValue' => '', 'caseExact' => 'false'],
            ['name' => 'mt245_literal_punctuation', 'marcTag' => '245', 'occurrenceCondition' => 'has', 'firstIndicator' => 'any', 'secondIndicator' => 'any', 'subfieldCode' => '', 'contentRule' => 'contains', 'searchValue' => "%_'\\quoted", 'caseExact' => 'true'],
        ];

        $checked = 0;
        foreach ($locationSets as $locationIds) {
            foreach (['effective_item', 'permanent_item'] as $basis) {
                foreach ($cases as $case) {
                    $inputs = array_merge(['locationIds' => implode(',', $locationIds), 'locationBasis' => $basis], $case);
                    $compiled = CatalogingMarcFieldFinderService::build($report, $inputs, $db);
                    $planDocument = marcFinderPostgresFetchPlan($pdo, $compiled['sql'], $compiled['params']);
                    $plan = $planDocument['Plan'];
                    $expectedTable = 'marctab.mt' . $case['marcTag'];
                    $tables = marcFinderPostgresTableNames($plan);
                    if ($tables !== [$expectedTable]) {
                        marcFinderPostgresFail($case['name'] . '/' . $basis . ' touched unexpected tables: ' . json_encode($tables));
                    }
                    if (stripos($compiled['sql'], 'folio_source_record.marctab') !== false
                        || stripos($compiled['sql'], 'parsed_record__content') !== false
                        || marcFinderPostgresHasForbiddenSource($plan)) {
                        marcFinderPostgresFail($case['name'] . '/' . $basis . ' referenced a forbidden MARC source.');
                    }
                    if (!marcFinderPostgresHasMaterializedTargetScope($plan)) {
                        marcFinderPostgresFail($case['name'] . '/' . $basis . ' plan does not expose a materialized target_instances scope.');
                    }
                    if ($case['marcTag'] === '245' && marcFinderPostgresHasMt245SeqScan($plan)) {
                        marcFinderPostgresFail($case['name'] . '/' . $basis . ' used a sequential scan on marctab.mt245.');
                    }
                    $actualRows = (int) ($plan['Actual Rows'] ?? -1);
                    if ($actualRows < 0 || $actualRows > CatalogingMarcFieldFinderService::FETCH_ROW_LIMIT) {
                        marcFinderPostgresFail($case['name'] . '/' . $basis . " returned {$actualRows} rows, outside the 100001-row cap.");
                    }
                    $returnedRows = marcFinderPostgresRows($pdo, $compiled['sql'], $compiled['params']);
                    if ($returnedRows > CatalogingMarcFieldFinderService::FETCH_ROW_LIMIT) {
                        marcFinderPostgresFail($case['name'] . '/' . $basis . ' returned more than 100001 rows.');
                    }
                    $blankCounts = null;
                    if ($case['name'] === 'mt035_blank_indicator') {
                        $blankCounts = marcFinderPostgresBlankCounts($pdo, $locationIds, $basis);
                        if ($blankCounts['space'] > 0 && $blankCounts['backslash'] > 0
                            && $returnedRows < $blankCounts['space'] + $blankCounts['backslash']) {
                            marcFinderPostgresFail($case['name'] . '/' . $basis . ' omitted whitespace or backslash blank-indicator rows.');
                        }
                    }
                    $evidence = [
                        'case' => $case['name'],
                        'location_ids' => $locationIds,
                        'location_basis' => $basis,
                        'marctab_table' => $expectedTable,
                        'planning_ms' => $planDocument['Planning Time'] ?? null,
                        'execution_ms' => $planDocument['Execution Time'] ?? null,
                        'returned_rows' => $returnedRows,
                        'explain_actual_rows' => $actualRows,
                        'statement_timeout_ms' => $statementTimeout,
                        'touched_tables' => $tables,
                        'target_instances_materialized' => true,
                        'mt245_seq_scan' => false,
                        'blank_indicator_counts' => $blankCounts,
                    ];
                    fwrite(STDOUT, 'MARC_FINDER_PG_PLAN ' . json_encode($evidence, JSON_UNESCAPED_SLASHES) . "\n");
                    $checked++;
                }
            }
        }
        $pdo->rollBack();
        fwrite(STDOUT, "Cataloging MARC finder PostgreSQL contract test passed ({$checked} plans).\n");
    } catch (\Throwable $exception) {
        if ($pdo instanceof \PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        marcFinderPostgresFail('Live FOLIO PostgreSQL contract check failed: ' . $exception->getMessage());
    }
}
