<?php

namespace yii\db {
    if (!class_exists(ActiveRecord::class, false)) {
        class ActiveRecord
        {
            private $attributes = [];

            public function __get($name) { return $this->attributes[$name] ?? null; }
            public function __set($name, $value): void { $this->attributes[$name] = $value; }
            public function hasAttribute($name): bool { return true; }
        }
    }
}

namespace {
    require_once __DIR__ . '/../../models/ReportTemplate.php';

    function acquisitionsOpenOrdersReportFixture(): \app\models\ReportTemplate
    {
        $path = __DIR__ . '/../../../mysql/migrations/056_open_order_report_expansion.sql';
        $migration = file_get_contents($path);
        if (!is_string($migration)) {
            throw new \RuntimeException('Cannot load the open-order report seed from migration 056.');
        }
        $values = acquisitionsOpenOrdersMigrationValues($migration);
        if (count($values) !== 12 || $values[0] !== 'quarterly-open-orders') {
            throw new \RuntimeException('Cannot load the open-order report seed from migration 056.');
        }

        $report = new \app\models\ReportTemplate();
        foreach ([
            'id' => 48,
            'slug' => 'quarterly-open-orders',
            'name' => $values[1],
            'description' => $values[2],
            'help_text' => $values[3],
            'category' => 'acquisitions',
            'sql_template' => $values[5],
            'parameters' => $values[6],
            'data_source' => 'folio',
            'execution_config' => $values[8],
            'default_limit' => $values[9],
            'is_active' => $values[10],
            'created_by' => $values[11],
        ] as $key => $value) {
            $report->$key = $value;
        }
        return $report;
    }

    function acquisitionsOpenOrdersMigrationValues(string $migration): array
    {
        $valuesOffset = stripos($migration, 'VALUES (');
        if ($valuesOffset === false) {
            return [];
        }
        $length = strlen($migration);
        $index = $valuesOffset + strlen('VALUES (');
        $values = [];
        while ($index < $length) {
            while ($index < $length && ctype_space($migration[$index])) {
                $index++;
            }
            if ($index >= $length || $migration[$index] === ')') {
                break;
            }
            if ($migration[$index] === "'") {
                $index++;
                $value = '';
                while ($index < $length) {
                    if ($migration[$index] === "'" && $index + 1 < $length && $migration[$index + 1] === "'") {
                        $value .= "'";
                        $index += 2;
                    } elseif ($migration[$index] === "'") {
                        $index++;
                        break;
                    } else {
                        $value .= $migration[$index];
                        $index++;
                    }
                }
                $values[] = $value;
            } else {
                $start = $index;
                while ($index < $length && $migration[$index] !== ',' && $migration[$index] !== ')') {
                    $index++;
                }
                $values[] = trim(substr($migration, $start, $index - $start));
            }
            while ($index < $length && ctype_space($migration[$index])) {
                $index++;
            }
            if ($index < $length && $migration[$index] === ',') {
                $index++;
                continue;
            }
            if ($index >= $length || $migration[$index] === ')') {
                break;
            }
            return [];
        }
        return $values;
    }

    final class AcquisitionsOpenOrdersLookupCommand
    {
        private $db;
        private $sql;
        private $params;

        public function __construct($db, string $sql, array $params)
        {
            $this->db = $db;
            $this->sql = $sql;
            $this->params = $params;
        }

        public function queryOne()
        {
            $this->db->queries[] = ['sql' => $this->sql, 'params' => $this->params];
            if (strpos($this->sql, 'to_regclass') !== false) {
                $row = [];
                foreach ($this->params as $key => $table) {
                    preg_match('/\A:requiredTable(\d+)\z/', $key, $matches);
                    $column = 'required_table_' . ($matches[1] ?? '');
                    $row[$column] = $table === $this->db->missingTable ? null : $table;
                }
                return $row;
            }
            if (strpos($this->sql, 'FROM orders.acquisitions_unit__t') !== false) {
                $unit = $this->params[':acqUnit'] ?? null;
                return in_array($unit, $this->db->unitNames, true) ? ['name' => $unit] : false;
            }
            throw new RuntimeException('Unexpected acquisitions report lookup query.');
        }

        public function queryColumn()
        {
            $this->db->queries[] = ['sql' => $this->sql, 'params' => $this->params];
            if (strpos($this->sql, 'inventory.material_type__t') !== false) {
                return $this->db->materialTypes;
            }
            if (strpos($this->sql, 'finance.expense_class__t') !== false) {
                return $this->db->expenseClasses;
            }
            throw new RuntimeException('Unexpected acquisitions report options query.');
        }
    }

    final class AcquisitionsOpenOrdersLookupDb
    {
        public $missingTable = null;
        public $unitNames = ['AC', 'HC', 'MH', 'SC', 'UM'];
        public $materialTypes = ['Book', 'E-Book'];
        public $expenseClasses = ['expense-1', 'expense-2', '__none__'];
        public $queries = [];

        public function createCommand(string $sql, array $params = []): AcquisitionsOpenOrdersLookupCommand
        {
            return new AcquisitionsOpenOrdersLookupCommand($this, $sql, $params);
        }
    }
}
