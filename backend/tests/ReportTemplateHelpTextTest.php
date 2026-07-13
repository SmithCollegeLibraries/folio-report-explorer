<?php

namespace yii\db {
    class ActiveRecord
    {
        private $attributes = [];
        private $availableAttributes = [
            'id',
            'slug',
            'name',
            'description',
            'category',
            'help_text',
            'data_source',
            'sql_template',
            'parameters',
            'default_limit',
            'is_active',
            'created_by',
            'created_at',
            'updated_at',
        ];

        public function __get($name)
        {
            if (!$this->hasAttribute($name)) {
                throw new \RuntimeException("Unknown attribute: {$name}");
            }

            return $this->attributes[$name] ?? null;
        }

        public function __set($name, $value)
        {
            if (!$this->hasAttribute($name)) {
                throw new \RuntimeException("Unknown attribute: {$name}");
            }

            $this->attributes[$name] = $value;
        }

        public function hasAttribute($name)
        {
            return in_array($name, $this->availableAttributes, true);
        }

        public function removeAvailableAttribute($name)
        {
            $this->availableAttributes = array_values(array_diff($this->availableAttributes, [$name]));
        }
    }
}

namespace {
    require_once __DIR__ . '/../models/ReportTemplate.php';

    use app\models\ReportTemplate;

    function assertSameValue($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\n");
            fwrite(STDERR, 'Expected: ' . var_export($expected, true) . "\n");
            fwrite(STDERR, 'Actual: ' . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    $report = new ReportTemplate();
    $report->parameters = '[]';
    $report->help_text = 'Calculated values explain reconciliation differences.';
    $detail = $report->toDetailArray();

    assertSameValue(
        'Calculated values explain reconciliation differences.',
        $detail['helpText'] ?? null,
        'Report detail should expose help_text as helpText.'
    );

    $legacyReport = new ReportTemplate();
    $legacyReport->parameters = '[]';
    $legacyReport->removeAvailableAttribute('help_text');
    $legacyDetail = $legacyReport->toDetailArray();

    assertSameValue(
        null,
        $legacyDetail['helpText'] ?? null,
        'Report detail should remain compatible with schemas without help_text.'
    );

    $legacyRuleAttributes = [];
    foreach ($legacyReport->rules() as $rule) {
        foreach ((array) ($rule[0] ?? []) as $attribute) {
            $legacyRuleAttributes[] = $attribute;
        }
    }

    assertSameValue(
        false,
        in_array('help_text', $legacyRuleAttributes, true),
        'Schemas without help_text should not validate the unavailable attribute.'
    );

    echo "PASS: ReportTemplate help text serialization tests\n";
}
