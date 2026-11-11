<?php

require_once __DIR__ . '/HoldingslessReportFixture.php';

function sharedRecordReadSqlString(string $source, int &$offset): string
{
    if (($source[$offset] ?? '') !== "'") {
        throw new RuntimeException('The shared record seed has an unexpected SQL value.');
    }
    $offset++;
    $value = '';
    $length = strlen($source);
    while ($offset < $length) {
        if ($source[$offset] === "'") {
            if (($source[$offset + 1] ?? '') === "'") {
                $value .= "'";
                $offset += 2;
                continue;
            }
            $offset++;
            return $value;
        }
        $value .= $source[$offset++];
    }
    throw new RuntimeException('The shared record seed contains an unterminated SQL value.');
}

function sharedRecordReportFixture(): \app\models\ReportTemplate
{
    $seed = file_get_contents(__DIR__ . '/../../../mysql/migrations/072_shared_record_check.sql');
    if (!preg_match('/VALUES\s*\(/', $seed, $match, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('Cannot load the shared record report seed.');
    }
    $offset = $match[0][1] + strlen($match[0][0]);
    $values = [];
    for ($index = 0; $index < 9; $index++) {
        while (isset($seed[$offset]) && (ctype_space($seed[$offset]) || $seed[$offset] === ',')) {
            $offset++;
        }
        $values[] = sharedRecordReadSqlString($seed, $offset);
    }

    $report = new \app\models\ReportTemplate();
    foreach ([
        'slug' => $values[0], 'name' => $values[1], 'description' => $values[2], 'help_text' => $values[3],
        'category' => $values[4], 'sql_template' => $values[5], 'parameters' => $values[6],
        'data_source' => $values[7], 'execution_config' => $values[8],
        'default_limit' => 100000, 'is_active' => 1, 'created_by' => 'manual', 'composite_config' => null,
    ] as $key => $value) {
        $report->$key = $value;
    }
    return $report;
}

function sharedRecordDefinition(\app\models\ReportTemplate $report): array
{
    $definition = [];
    foreach ([
        'slug', 'name', 'description', 'help_text', 'category', 'data_source', 'created_by',
        'default_limit', 'is_active', 'sql_template', 'parameters', 'execution_config', 'composite_config',
    ] as $key) {
        $definition[$key] = $report->$key;
    }
    return $definition;
}

class SharedRecordLookupDb
{
    public $missingTable = false;
    public $locations = [];
    public $locationLookups = 0;
    public $definition;

    public function createCommand($sql, $params = [])
    {
        return new SharedRecordLookupCommand($this, $sql, $params);
    }
}

class SharedRecordLookupCommand
{
    private $db;
    private $sql;
    private $params;

    public function __construct(SharedRecordLookupDb $db, string $sql, array $params)
    {
        $this->db = $db;
        $this->sql = $sql;
        $this->params = $params;
    }

    public function queryOne()
    {
        if (strpos($this->sql, 'to_regclass') !== false) {
            return ['to_regclass' => $this->db->missingTable ? null : 'marctab.mt998'];
        }
        return $this->db->definition;
    }

    public function queryAll(): array
    {
        $this->db->locationLookups++;
        $selected = array_values($this->params);
        return array_values(array_filter($this->db->locations, function ($location) use ($selected) {
            return in_array($location['id'], $selected, true);
        }));
    }
}

function sharedRecordSqliteSql(string $sql): string
{
    $sql = str_replace('AS MATERIALIZED', 'AS', $sql);
    $sql = str_replace('UPPER(BTRIM(', 'UPPER(TRIM(', $sql);
    $sql = preg_replace('/\bOFFSET 0\b/', 'LIMIT -1 OFFSET 0', $sql);
    $sql = str_replace(
        "selected_holding.permanent_location_id = ANY(string_to_array(params.location_ids, ',')::uuid[])",
        "INSTR(',' || params.location_ids || ',', ',' || selected_holding.permanent_location_id || ',') > 0",
        $sql
    );
    $sql = str_replace(
        [
            "STRING_AGG(DISTINCT UPPER(TRIM(ownership.content)), '; ' ORDER BY UPPER(TRIM(ownership.content)))",
            "STRING_AGG(DISTINCT campus.code, '; ' ORDER BY campus.code)",
        ],
        [
            "(SELECT GROUP_CONCAT(ordered_codes.code, '; ') FROM (SELECT DISTINCT UPPER(TRIM(ordered_ownership.content)) AS code FROM marctab.mt998 AS ordered_ownership WHERE ordered_ownership.instance_id = ownership.instance_id AND ordered_ownership.sf = 'a' ORDER BY code) AS ordered_codes)",
            "(SELECT GROUP_CONCAT(ordered_campuses.code, '; ') FROM (SELECT DISTINCT sorted_campus.code AS code FROM inventory.holdings_record__t AS sorted_holding JOIN inventory.location__t AS sorted_location ON sorted_location.id = sorted_holding.permanent_location_id JOIN inventory.loclibrary__t AS sorted_library ON sorted_library.id = sorted_location.library_id JOIN inventory.loccampus__t AS sorted_campus ON sorted_campus.id = sorted_library.campus_id WHERE sorted_holding.instance_id = holding.instance_id ORDER BY code) AS ordered_campuses)",
        ],
        $sql
    );
    $sql = str_replace(
        ['"Title" NULLS LAST, "HRID" NULLS LAST, "Instance UUID" NULLS LAST'],
        ['("Title" IS NULL), "Title", ("HRID" IS NULL), "HRID", ("Instance UUID" IS NULL), "Instance UUID"'],
        $sql
    );
    if (strpos($sql, 'MATERIALIZED') !== false || strpos($sql, 'STRING_AGG(') !== false || strpos($sql, '::uuid[]') !== false) {
        throw new RuntimeException('SQLite translation left syntax unsupported by the test database.');
    }
    return $sql;
}
