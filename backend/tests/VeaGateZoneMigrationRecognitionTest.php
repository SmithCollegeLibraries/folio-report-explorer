<?php

require_once __DIR__ . '/../services/MigrationService.php';

function veaMigrationAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

class VeaMigrationTestDb
{
    public $version;
    public $tableExists = true;
    public $columns;
    public $hasPrimary = true;

    public function __construct(string $version)
    {
        $this->version = $version;
        $this->columns = [
            $this->column('zone_id', 'varchar', 'varchar(64)', 'utf8mb4', 'utf8mb4_unicode_ci', 64),
            $this->column('sort_order', 'int', 'int', null, null, null),
        ];
    }

    private function column($name, $type, $columnType, $characterSet, $collation, $length)
    {
        return [
            'COLUMN_NAME' => $name,
            'DATA_TYPE' => $type,
            'COLUMN_TYPE' => $columnType,
            'IS_NULLABLE' => 'NO',
            'CHARACTER_SET_NAME' => $characterSet,
            'COLLATION_NAME' => $collation,
            'COLUMN_DEFAULT' => null,
            'CHARACTER_MAXIMUM_LENGTH' => $length,
            'EXTRA' => '',
        ];
    }

    public function createCommand($sql, $params = [])
    {
        return new class($this, $sql) {
            private $db;
            private $sql;
            public function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
            public function queryScalar()
            {
                if ($this->sql === 'SELECT VERSION()') {
                    return $this->db->version;
                }
                throw new RuntimeException('Unexpected scalar query.');
            }
            public function queryAll()
            {
                if (strpos($this->sql, 'information_schema.TABLES') !== false) {
                    return $this->db->tableExists
                        ? [['ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci']]
                        : [];
                }
                if (strpos($this->sql, 'information_schema.COLUMNS') !== false) {
                    return $this->db->columns;
                }
                if (strpos($this->sql, 'information_schema.STATISTICS') !== false) {
                    return $this->db->hasPrimary
                        ? [['NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'zone_id', 'SUB_PART' => null]]
                        : [];
                }
                throw new RuntimeException('Unexpected metadata query.');
            }
        };
    }
}

$migrationPath = __DIR__ . '/../../mysql/migrations/071_vea_gate_zones.sql';
veaMigrationAssert(is_file($migrationPath), 'Migration 071 must exist.');
if (is_file($migrationPath)) {
    $sql = strtolower(preg_replace('/\s+/', ' ', str_replace('`', '', (string)file_get_contents($migrationPath))));
    foreach ([
        'create table if not exists vea_gate_zones',
        'zone_id varchar(64) not null',
        'sort_order int not null',
        'primary key (zone_id)',
        'engine=innodb',
        'charset=utf8mb4',
    ] as $fragment) {
        veaMigrationAssert(strpos($sql, $fragment) !== false, 'Migration 071 must contain ' . $fragment . '.');
    }
    veaMigrationAssert(strpos($sql, 'timestamp') === false, 'Migration 071 must not add a timestamp.');
    veaMigrationAssert(strpos($sql, 'drop table') === false && strpos($sql, 'truncate') === false, 'Migration 071 must not drop or truncate data.');
}

$recognize = new ReflectionMethod(app\services\MigrationService::class, 'migrationAppearsApplied');
if (PHP_VERSION_ID < 80500) {
    $recognize->setAccessible(true);
}
$absent = new VeaMigrationTestDb('8.0.36');
$absent->tableExists = false;
veaMigrationAssert(!$recognize->invoke(null, $absent, '071_vea_gate_zones.sql'), 'An absent table is not recognized.');

foreach (['8.0.36', '10.3.39-MariaDB'] as $version) {
    $db = new VeaMigrationTestDb($version);
    veaMigrationAssert($recognize->invoke(null, $db, '071_vea_gate_zones.sql'), $version . ' recognizes the exact two-column shape and primary key.');

    $extraColumn = new VeaMigrationTestDb($version);
    $extraColumn->columns[] = [
        'COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'datetime', 'COLUMN_TYPE' => 'datetime',
        'IS_NULLABLE' => 'NO', 'CHARACTER_SET_NAME' => null, 'COLLATION_NAME' => null,
        'COLUMN_DEFAULT' => null, 'CHARACTER_MAXIMUM_LENGTH' => null, 'EXTRA' => '',
    ];
    veaMigrationAssert(!$recognize->invoke(null, $extraColumn, '071_vea_gate_zones.sql'), $version . ' rejects an extra column.');

    $wrongPrimary = new VeaMigrationTestDb($version);
    $wrongPrimary->hasPrimary = false;
    veaMigrationAssert(!$recognize->invoke(null, $wrongPrimary, '071_vea_gate_zones.sql'), $version . ' rejects a missing zone_id primary key.');

    $autoIncrement = new VeaMigrationTestDb($version);
    $autoIncrement->columns[1]['EXTRA'] = 'auto_increment';
    veaMigrationAssert(!$recognize->invoke(null, $autoIncrement, '071_vea_gate_zones.sql'), $version . ' rejects an unexpected auto-increment column.');
}

echo "PASS: Vea gate zone migration shape and recognition\n";
