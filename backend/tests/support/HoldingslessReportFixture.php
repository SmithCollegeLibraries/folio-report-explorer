<?php
namespace yii\db {
    if (!class_exists(ActiveRecord::class, false)) {
        class ActiveRecord {
            private $attributes = [];
            public function __get($name) { return $this->attributes[$name] ?? null; }
            public function __set($name, $value) { $this->attributes[$name] = $value; }
            public function hasAttribute($name) { return true; }
        }
    }
}
namespace {
    require_once __DIR__ . '/../../models/ReportTemplate.php';
    function holdingslessReportFixture(): \app\models\ReportTemplate {
        $seed = file_get_contents(__DIR__ . '/../../../mysql/migrations/047_cataloging_holdingsless_exclusion_match.sql');
        if (!preg_match("/\n  '(WITH candidates.*?LIMIT 100001)',\n  '(\\[.*?\\])',\n  'folio',\n  '(\\{.*?\\})'/s", $seed, $matches)) {
            throw new \RuntimeException('Cannot load holdings-less report seed.');
        }
        $report = new \app\models\ReportTemplate();
        foreach ([
            'id' => 45, 'slug' => 'instances-without-holdings',
            'name' => 'Instances Without Holdings — Cataloging Review',
            'category' => 'cataloging', 'data_source' => 'folio', 'created_by' => 'manual',
            'is_active' => 1, 'default_limit' => 100000,
            'sql_template' => str_replace("''", "'", $matches[1]),
            'parameters' => str_replace("''", "'", $matches[2]),
            'execution_config' => str_replace("''", "'", $matches[3]),
        ] as $key => $value) { $report->$key = $value; }
        return $report;
    }
    class HoldingslessLookupDb {
        public $missingTable = false;
        public function createCommand($sql, $params = []) {
            if (strpos($sql, 'to_regclass') === false) { throw new \RuntimeException('Unexpected lookup'); }
            return new class($this->missingTable) {
                private $missing;
                public function __construct($missing) { $this->missing = $missing; }
                public function queryOne() { return ['table' => $this->missing ? null : 'marctab.mt035']; }
            };
        }
    }
}
