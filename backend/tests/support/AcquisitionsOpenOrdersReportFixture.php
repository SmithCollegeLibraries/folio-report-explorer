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
        $path = __DIR__ . '/../../../mysql/migrations/053_acquisitions_feedback_report_filters.sql';
        $migration = file_get_contents($path);
        if (!is_string($migration) || preg_match(
            "/VALUES \\(\\s*'quarterly-open-orders',\\s*'(.*?)',\\s*'(.*?)',\\s*'(.*?)',\\s*'acquisitions',\\s*'(WITH params AS .*?LIMIT 100001)',\\s*'(\\[.*?\\])',\\s*'folio',\\s*'(\\{.*?\\})',\\s*100000,\\s*1,\\s*'manual'\\s*\\)/s",
            $migration,
            $matches
        ) !== 1) {
            throw new \RuntimeException('Cannot load the open-order report seed from migration 053.');
        }

        $report = new \app\models\ReportTemplate();
        foreach ([
            'id' => 48,
            'slug' => 'quarterly-open-orders',
            'name' => str_replace("''", "'", $matches[1]),
            'description' => str_replace("''", "'", $matches[2]),
            'help_text' => str_replace("''", "'", $matches[3]),
            'category' => 'acquisitions',
            'sql_template' => str_replace("''", "'", $matches[4]),
            'parameters' => str_replace("''", "'", $matches[5]),
            'data_source' => 'folio',
            'execution_config' => str_replace("''", "'", $matches[6]),
            'default_limit' => 100000,
            'is_active' => 1,
            'created_by' => 'manual',
        ] as $key => $value) {
            $report->$key = $value;
        }
        return $report;
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
            throw new \RuntimeException('Unexpected acquisitions report lookup query.');
        }
    }

    final class AcquisitionsOpenOrdersLookupDb
    {
        public $missingTable = null;
        public $unitNames = ['AC', 'HC', 'MH', 'SC', 'UM'];
        public $queries = [];

        public function createCommand(string $sql, array $params = []): AcquisitionsOpenOrdersLookupCommand
        {
            return new AcquisitionsOpenOrdersLookupCommand($this, $sql, $params);
        }
    }
}
