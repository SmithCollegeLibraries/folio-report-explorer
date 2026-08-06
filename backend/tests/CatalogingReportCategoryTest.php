<?php

namespace yii\db {
    class ActiveRecord
    {
        public function hasAttribute($name): bool
        {
            return in_array($name, ['help_text'], true);
        }
    }
}

namespace {
    require_once __DIR__ . '/../models/ReportTemplate.php';

    use app\models\ReportTemplate;

    function assertCatalogingCategorySame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }

    $categoryRule = null;
    foreach ((new ReportTemplate())->rules() as $rule) {
        if (($rule[1] ?? null) === 'in' && in_array('category', (array) ($rule[0] ?? []), true)) {
            $categoryRule = $rule;
            break;
        }
    }

    $categories = $categoryRule['range'] ?? [];
    assertCatalogingCategorySame(true, in_array('cataloging', $categories, true), 'ReportTemplate must accept Cataloging.');
    assertCatalogingCategorySame(false, in_array('not-a-category', $categories, true), 'ReportTemplate must reject unknown categories.');

    $gemini = (string) file_get_contents(__DIR__ . '/../services/GeminiService.php');
    $schema = 'acquisitions|circulation|inventory|finance|users|cataloging|other';
    assertCatalogingCategorySame(2, substr_count($gemini, $schema), 'Both report-generation prompt schemas must advertise Cataloging exactly once.');

    fwrite(STDOUT, "Cataloging report category test passed\n");
}
