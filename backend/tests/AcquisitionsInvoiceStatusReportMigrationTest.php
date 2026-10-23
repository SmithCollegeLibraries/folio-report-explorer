<?php

namespace yii\db {
    if (!class_exists(ActiveRecord::class, false)) {
        class ActiveRecord
        {
            private $attributes = [];
            public function __get($name) { return $this->attributes[$name] ?? null; }
            public function __set($name, $value) { $this->attributes[$name] = $value; }
            public function hasAttribute($name) { return true; }
        }
    }
}

namespace {
    require_once __DIR__ . '/support/AcquisitionsInvoiceStatusReportFixture.php';
    require_once __DIR__ . '/../services/MigrationService.php';

    function acquisitionsInvoiceMigrationCheck($condition, $message)
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    $report = acquisitionsInvoiceStatusReportFixture();
    $definition = [];
    foreach (['slug', 'name', 'category', 'sql_template', 'parameters', 'data_source', 'execution_config', 'default_limit', 'is_active', 'created_by'] as $key) {
        $definition[$key] = $report->$key;
    }
    $db = new class($definition) {
        public $definition;
        public function __construct($definition) { $this->definition = $definition; }
        public function createCommand($sql, $params = []) {
            return new class($this->definition) {
                private $definition;
                public function __construct($definition) { $this->definition = $definition; }
                public function queryOne() { return $this->definition; }
            };
        }
    };

    $recognize = new \ReflectionMethod(\app\services\MigrationService::class, 'migrationAppearsApplied');
    if (PHP_VERSION_ID < 80500) {
        $recognize->setAccessible(true);
    }
    acquisitionsInvoiceMigrationCheck(
        $recognize->invoke(null, $db, '049_acquisitions_invoice_status_report.sql'),
        'Migration 049 must recognize its canonical report seed.'
    );

    $db->definition['sql_template'] = 'SELECT 1';
    acquisitionsInvoiceMigrationCheck(
        !$recognize->invoke(null, $db, '049_acquisitions_invoice_status_report.sql'),
        'Migration 049 must reject report SQL drift.'
    );

    fwrite(STDOUT, "Acquisitions invoice status migration recognition tests passed\n");
}
